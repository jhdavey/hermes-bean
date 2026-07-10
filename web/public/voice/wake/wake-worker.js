'use strict';

// Local, single-thread streaming ASR. Only an anchored Hey Bean acoustic
// prefix opens the provider-bound microphone gate; command audio stays local
// until that decision has been made.

const TARGET_SAMPLE_RATE = 16000;
const MAX_AUDIO_SAMPLES = 3200;
const MAX_DECODES_PER_MESSAGE = 32;
const NON_WAKE_SILENCE_RESET_CHUNKS = 7;
const SPEECH_RMS_THRESHOLD = 0.012;
const KEYWORD_ALIAS = 'HEY_BEAN';
const WAKE_PREFIX = /^(?:(?:HEY|THEY|HE)\s+(?:BEAN|BEING)|HABE(?:EN|ING))\b/;
const assetBaseUrl = new URL('./', self.location.href);

let currentGeneration = initialGeneration();
let moduleInstance = null;
let recognizer = null;
let recognitionStream = null;
let ready = false;
let armed = false;
let failed = false;
let closed = false;
let speechObserved = false;
let silentChunksAfterSpeech = 0;

self.addEventListener('message', handleMessage);
self.addEventListener('error', (event) => {
    event.preventDefault();
    fail('worker_error', event.error || event.message);
});
self.addEventListener('unhandledrejection', (event) => {
    event.preventDefault();
    fail('unhandled_rejection', event.reason);
});

try {
    importScripts(
        new URL('runtime.js', assetBaseUrl).href,
        new URL('asr-api.js', assetBaseUrl).href,
    );
} catch (error) {
    fail('runtime_load_failed', error);
}

if (!failed) void initialize();

function initialGeneration() {
    try {
        const value = Number(new URL(self.location.href).searchParams.get('generation'));

        return Number.isSafeInteger(value) && value >= 0 ? value : 0;
    } catch {
        return 0;
    }
}

async function initialize() {
    try {
        if (typeof createSherpaAsrModule !== 'function' || typeof createOnlineRecognizer !== 'function') {
            throw new Error('The local speech runtime did not expose its expected API.');
        }

        moduleInstance = await createSherpaAsrModule({
            locateFile(path) {
                if (path.endsWith('.wasm')) return new URL('runtime.wasm', assetBaseUrl).href;
                if (path.endsWith('.data')) return new URL('model.data', assetBaseUrl).href;

                return new URL(path, assetBaseUrl).href;
            },
            print() {},
            printErr() {},
        });

        if (closed) {
            teardown();
            return;
        }

        recognizer = createOnlineRecognizer(moduleInstance, {
            featConfig: {
                sampleRate: TARGET_SAMPLE_RATE,
                featureDim: 80,
            },
            modelConfig: {
                transducer: {
                    encoder: './encoder.onnx',
                    decoder: './decoder.onnx',
                    joiner: './joiner.onnx',
                },
                tokens: './tokens.txt',
                provider: 'cpu',
                modelType: '',
                numThreads: 1,
                debug: 0,
                modelingUnit: '',
                bpeVocab: '',
            },
            decodingMethod: 'greedy_search',
            maxActivePaths: 4,
            enableEndpoint: 0,
        });
        replaceRecognitionStream();

        ready = true;
        armed = true;
        postMessage({ type: 'ready', generation: currentGeneration });
    } catch (error) {
        fail('initialization_failed', error);
    }
}

function handleMessage(event) {
    if (closed) return;

    const message = event.data;
    if (!message || typeof message !== 'object') {
        fail('invalid_message', 'Wake worker messages must be objects.');
        return;
    }

    if (message.type === 'close') {
        handleClose(message);
        return;
    }
    if (message.type === 'reset') {
        handleReset(message);
        return;
    }
    if (message.type === 'audio') {
        handleAudio(message);
        return;
    }

    fail('invalid_message_type', 'The wake worker received an unsupported message type.');
}

function handleReset(message) {
    const generation = parseGeneration(message.generation);
    if (generation === null) {
        fail('invalid_generation', 'Reset messages require a non-negative integer generation.');
        return;
    }
    if (generation < currentGeneration) return;

    currentGeneration = generation;
    if (!ready || failed || !recognizer) return;

    try {
        replaceRecognitionStream();
        armed = true;
        postMessage({ type: 'ready', generation: currentGeneration });
    } catch (error) {
        fail('reset_failed', error);
    }
}

function handleAudio(message) {
    const generation = parseGeneration(message.generation);
    const sequence = parseSequence(message.sequence);

    if (sequence === null) {
        fail('invalid_sequence', 'Audio messages require a non-negative integer sequence.');
        return;
    }
    if (generation === null || generation !== currentGeneration) {
        acknowledge(sequence, generation, false, 'generation_mismatch');
        return;
    }
    if (!ready || failed || !recognizer || !recognitionStream) {
        acknowledge(sequence, generation, false, failed ? 'failed' : 'not_ready');
        return;
    }
    if (!armed) {
        acknowledge(sequence, generation, false, 'activation_pending');
        return;
    }

    const samples = normalizeSamples(message.samples);
    if (!samples || samples.length === 0 || samples.length > MAX_AUDIO_SAMPLES) {
        acknowledge(sequence, generation, false, 'invalid_audio');
        fail('invalid_audio', `Audio chunks must contain 1-${MAX_AUDIO_SAMPLES} Float32 samples.`);
        return;
    }
    for (let index = 0; index < samples.length; index += 1) {
        if (!Number.isFinite(samples[index])) {
            acknowledge(sequence, generation, false, 'invalid_audio');
            fail('invalid_audio', 'Audio chunks must contain only finite samples.');
            return;
        }
    }

    try {
        trackUtteranceActivity(samples);
        recognitionStream.acceptWaveform(TARGET_SAMPLE_RATE, samples);

        let decodeCount = 0;
        while (recognizer.isReady(recognitionStream)) {
            if (decodeCount >= MAX_DECODES_PER_MESSAGE) {
                throw new Error('The decoder exceeded its per-message work limit.');
            }
            recognizer.decode(recognitionStream);
            decodeCount += 1;
        }

        const result = recognizer.getResult(recognitionStream);
        const wakeVariant = matchedWakeVariant(result?.text);
        if (wakeVariant) {
            // Stop accepting local audio until the application closes and
            // rearms the gate with a new generation.
            armed = false;
            postMessage({
                type: 'detected',
                keyword: KEYWORD_ALIAS,
                variant: wakeVariant,
                generation: currentGeneration,
            });
        } else if (shouldResetNonWakeUtterance()) {
            // The matcher is intentionally anchored. Start a clean stream after
            // a completed non-wake utterance so ambient speech cannot poison
            // every later, valid Hey Bean attempt for this microphone session.
            replaceRecognitionStream();
            resetUtteranceActivity();
        }

        acknowledge(sequence, generation, true);
    } catch (error) {
        acknowledge(sequence, generation, false, 'decode_failed');
        fail('decode_failed', error);
    }
}

function handleClose(message) {
    const generation = parseGeneration(message.generation);
    if (generation === null || generation < currentGeneration) return;

    currentGeneration = generation;
    closed = true;
    ready = false;
    armed = false;
    teardown();
    self.close();
}

function replaceRecognitionStream() {
    if (recognitionStream) {
        recognitionStream.free();
        recognitionStream = null;
    }
    recognitionStream = recognizer.createStream();
    if (!recognitionStream?.handle) throw new Error('The local speech stream could not be created.');
    resetUtteranceActivity();
}

function trackUtteranceActivity(samples) {
    let squareSum = 0;
    for (let index = 0; index < samples.length; index += 1) {
        squareSum += samples[index] * samples[index];
    }
    const rms = Math.sqrt(squareSum / samples.length);
    if (rms >= SPEECH_RMS_THRESHOLD) {
        speechObserved = true;
        silentChunksAfterSpeech = 0;
        return;
    }
    if (speechObserved) silentChunksAfterSpeech += 1;
}

function shouldResetNonWakeUtterance() {
    return armed
        && speechObserved
        && silentChunksAfterSpeech >= NON_WAKE_SILENCE_RESET_CHUNKS;
}

function resetUtteranceActivity() {
    speechObserved = false;
    silentChunksAfterSpeech = 0;
}

function matchedWakeVariant(text) {
    const normalized = String(text || '')
        .toUpperCase()
        .replace(/[^A-Z\s]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    const match = normalized.match(WAKE_PREFIX);

    return match ? match[0] : '';
}

function normalizeSamples(value) {
    if (value instanceof Float32Array) return value;
    if (value instanceof ArrayBuffer && value.byteLength % Float32Array.BYTES_PER_ELEMENT === 0) {
        return new Float32Array(value);
    }

    return null;
}

function parseGeneration(value) {
    return Number.isSafeInteger(value) && value >= 0 ? value : null;
}

function parseSequence(value) {
    return Number.isSafeInteger(value) && value >= 0 ? value : null;
}

function acknowledge(sequence, generation, accepted, reason = null) {
    postMessage({
        type: 'ack',
        sequence,
        generation: generation === null ? currentGeneration : generation,
        accepted,
        ...(reason ? { reason } : {}),
    });
}

function fail(code, error) {
    if (closed || failed) return;

    failed = true;
    ready = false;
    armed = false;
    teardown();

    try {
        postMessage({
            type: 'error',
            code,
            message: safeMessage(error),
            fatal: true,
            generation: currentGeneration,
        });
    } catch {
        // Worker termination is the final fail-closed boundary.
    }
}

function safeMessage(error) {
    if (error instanceof Error) return error.message.slice(0, 240);

    return String(error || 'Unknown local wake worker error.').slice(0, 240);
}

function teardown() {
    if (recognitionStream) {
        try {
            recognitionStream.free();
        } catch {
            // Worker termination releases remaining Wasm memory.
        }
        recognitionStream = null;
    }
    if (recognizer) {
        try {
            recognizer.free();
        } catch {
            // Worker termination releases remaining Wasm memory.
        }
        recognizer = null;
    }
    moduleInstance = null;
}
