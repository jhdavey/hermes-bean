'use strict';

// Local, single-thread wake classification. Raw microphone audio never leaves
// this worker while Bean is dormant. The bundled KWS component proposes only a
// candidate type and timestamp. One first-party three-class model owns every
// final wake decision before deterministic code may use that timestamp.

const TARGET_SAMPLE_RATE = 16000;
const MAX_AUDIO_SAMPLES = 3200;
const MAX_DECODES_PER_MESSAGE = 32;
const NON_WAKE_SILENCE_RESET_SAMPLES = Math.round(TARGET_SAMPLE_RATE * 0.7);
const MAX_DORMANT_SILENCE_CHUNKS = 300;
const ACTIVITY_FRAME_SAMPLES = 320;
const DORMANT_AUDIO_RING_CHUNKS = 2;
// Match the worklet's visible activity floor. This threshold starts bounded
// local analysis only; the shared three-class model still owns every
// activation, so lowering it cannot release dormant audio.
const SPEECH_RMS_THRESHOLD = 0.008;
const STRICT_RELEASE_TAIL_SAMPLES = Math.round(TARGET_SAMPLE_RATE * 0.12);
const MAX_SOURCE_BOUNDARIES = 100;
const MAX_CLASSIFICATION_SAMPLES = TARGET_SAMPLE_RATE * 3;
const CLASSIFICATION_LEADING_PAD_SAMPLES = Math.round(TARGET_SAMPLE_RATE * 0.1);
const PROPOSAL_CONTEXT_SAMPLES = Math.round(TARGET_SAMPLE_RATE * 1.2);
const PROPOSAL_TAIL_SAMPLES = Math.round(TARGET_SAMPLE_RATE * 0.16);
const PROPOSAL_WINDOW_SAMPLES = PROPOSAL_CONTEXT_SAMPLES + PROPOSAL_TAIL_SAMPLES;
// Start every first strict timing stream with one exact model chunk of silence.
// This removes AudioWorklet/resampler phase from the proposal without adding
// any real dormant PCM or changing the proposal-aligned classifier evidence.
const STRICT_KWS_SYNTHETIC_PAD_SAMPLES = Math.round(TARGET_SAMPLE_RATE * 0.32);
const MIN_WAKE_ACCEPTANCE_PROBABILITY = 0.95;
const KEYWORD_ALIAS = 'HEY_BEAN';
const STRICT_WAKE_ALIAS = 'HEY_BEAN';
const ADDRESS_WAKE_ALIAS = 'BEAN';
const RUNTIME_VERSION = '17';

// These general graphs are high-recall proposal and timestamp sources only.
// Neither graph can accept a wake or release dormant audio.
const STRICT_KEYWORDS = [
    'HH EY1 B IY1 N :3.0 #0.01 @HEY_BEAN',
    'HH EY1 B IY1 M :3.0 #0.01 @HEY_BEAN',
].join('\n');
const ADDRESS_KEYWORDS = 'B IY1 N :3.0 #0.01 @BEAN';

const assetBaseUrl = new URL('./', self.location.href);

let currentGeneration = initialGeneration();
let moduleInstance = null;
let keywordSpotter = null;
let beanWakeModel = null;
let strictStream = null;
let addressStream = null;
let strictStreamStartSample = 0;
let addressStreamStartSample = 0;
let ready = false;
let armed = false;
let warmDecodeComplete = false;
let failed = false;
let closed = false;
let speechObserved = false;
let silentSamplesAfterSpeech = 0;
let dormantSilenceChunks = 0;
let acceptedSampleCount = 0;
let utteranceOnsetSample = null;
let dormantAudioRing = [];
let streamSourceChunks = [];
let classificationAudio = new Float32Array(0);
let provisionalAddressProposal = null;
let pendingProposal = null;
let wakeProposalSeen = false;
let classificationDecisionSeen = false;

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
        versionedAsset('kws-runtime.js'),
        versionedAsset('kws-api.js'),
    );
} catch (error) {
    fail('runtime_load_failed', error);
}

if (!failed) void initialize();

function versionedAsset(name) {
    const url = new URL(name, assetBaseUrl);
    url.searchParams.set('v', RUNTIME_VERSION);
    return url.href;
}

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
        if (typeof createSherpaKwsModule !== 'function' || typeof createKws !== 'function') {
            throw new Error('The local keyword runtime did not expose its expected API.');
        }

        [moduleInstance, beanWakeModel] = await Promise.all([
            createSherpaKwsModule({
                locateFile(path) {
                    if (path.endsWith('.wasm')) return versionedAsset('kws-runtime.wasm');
                    if (path.endsWith('.data')) return versionedAsset('kws-model.data');

                    return new URL(path, assetBaseUrl).href;
                },
                print() {},
                printErr() {},
            }),
            loadBeanWakeModel(),
        ]);

        if (closed) {
            teardown();
            return;
        }

        keywordSpotter = createKws(moduleInstance, {
            featConfig: {
                samplingRate: TARGET_SAMPLE_RATE,
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
                modelingUnit: 'phone+ppinyin',
                bpeVocab: '',
            },
            maxActivePaths: 4,
            numTrailingBlanks: 0,
            keywordsScore: 1,
            keywordsThreshold: 0.1,
            keywords: '',
            keywordsBuf: STRICT_KEYWORDS,
            keywordsBufSize: moduleInstance.lengthBytesUTF8(STRICT_KEYWORDS),
        });

        warmKeywordSpotter();
        warmDecodeComplete = true;
        createKeywordStreams();

        ready = true;
        armed = true;
        postReady();
    } catch (error) {
        fail('initialization_failed', error);
    }
}

async function loadBeanWakeModel() {
    const response = await fetch(versionedAsset('bean-wake-model-v2.json'), {
        cache: 'force-cache',
        credentials: 'same-origin',
    });
    if (!response.ok) throw new Error(`The first-party wake model failed to load (${response.status}).`);
    const model = await response.json();
    validateBeanWakeModel(model);

    return prepareBeanWakeModel(model);
}

function validateBeanWakeModel(model) {
    if (!model || model.schema_version !== '2.0.0'
        || model.model_id !== 'bean-first-party-wake-v2'
        || model.runtime_network_required !== false
        || model.external_account_required !== false
        || model.license_key_required !== false
        || model.sample_rate !== TARGET_SAMPLE_RATE
        || model.classifier?.architecture !== 'temporal_conv1d_v1') {
        throw new Error('The first-party wake model manifest is incompatible.');
    }
    const feature = model.feature || {};
    if (feature.fft_size !== 512
        || feature.window_samples !== 400
        || feature.hop_samples !== 160
        || feature.mel_bands !== 32
        || feature.normalized_frames !== 48) {
        throw new Error('The first-party wake feature contract is incompatible.');
    }
    const proposalWindow = model.proposal_window || {};
    if (proposalWindow.alignment !== 'proposal_end'
        || proposalWindow.context_samples !== PROPOSAL_CONTEXT_SAMPLES
        || proposalWindow.tail_samples !== PROPOSAL_TAIL_SAMPLES
        || proposalWindow.total_samples !== PROPOSAL_WINDOW_SAMPLES) {
        throw new Error('The first-party wake proposal window is incompatible.');
    }
    const thresholdClasses = ['strict_wake', 'missed_hey_confirmation'];
    if (!model.thresholds || JSON.stringify(Object.keys(model.thresholds).sort())
        !== JSON.stringify([...thresholdClasses].sort())) {
        throw new Error('The first-party wake thresholds are incompatible.');
    }
    for (const className of thresholdClasses) {
        const threshold = model.thresholds[className];
        if (!threshold || JSON.stringify(Object.keys(threshold)) !== JSON.stringify(['probability'])
            || typeof threshold.probability !== 'number'
            || !Number.isFinite(threshold.probability)
            || threshold.probability < MIN_WAKE_ACCEPTANCE_PROBABILITY
            || threshold.probability > 1) {
            throw new Error(`The first-party wake threshold ${className} is incompatible.`);
        }
    }
    const expected = {
        'conv1.weight': [32, 32, 5],
        'conv1.bias': [32],
        'conv2.weight': [48, 32, 3],
        'conv2.bias': [48],
        'dense1.weight': [64, 576],
        'dense1.bias': [64],
        'dense2.weight': [3, 64],
        'dense2.bias': [3],
    };
    if (JSON.stringify(Object.keys(model.classifier.layers || {}).sort())
        !== JSON.stringify(Object.keys(expected).sort())) {
        throw new Error('The first-party wake layer inventory is incompatible.');
    }
    if (JSON.stringify(model.classes) !== JSON.stringify([
        'reject',
        'strict_wake',
        'missed_hey_confirmation',
    ])) {
        throw new Error('The first-party wake classes are incompatible.');
    }
    if (!Array.isArray(model.normalization?.mean)
        || model.normalization.mean.length !== 1536
        || !Array.isArray(model.normalization?.deviation)
        || model.normalization.deviation.length !== 1536) {
        throw new Error('The first-party wake normalization is invalid.');
    }
    for (const [name, shape] of Object.entries(expected)) {
        const layer = model.classifier.layers?.[name];
        if (!layer || JSON.stringify(layer.shape) !== JSON.stringify(shape)) {
            throw new Error(`The first-party wake layer ${name} is invalid.`);
        }
    }
}

function prepareBeanWakeModel(model) {
    const flatten = (value, output = []) => {
        if (Array.isArray(value)) {
            for (const item of value) flatten(item, output);
        } else {
            if (typeof value !== 'number' || !Number.isFinite(value)) {
                throw new Error('The first-party wake model contains an invalid numeric value.');
            }
            output.push(value);
        }

        return output;
    };
    const finiteFloat32Array = (values, label) => {
        const converted = new Float32Array(flatten(values));
        if (converted.some((value) => !Number.isFinite(value))) {
            throw new Error(`The first-party wake ${label} exceeds the supported numeric range.`);
        }

        return converted;
    };
    const layers = {};
    for (const [name, layer] of Object.entries(model.classifier.layers)) {
        layers[name] = finiteFloat32Array(layer.values, `layer ${name}`);
        const expectedLength = layer.shape.reduce((total, size) => total * size, 1);
        if (layers[name].length !== expectedLength) {
            throw new Error(`The first-party wake layer ${name} has the wrong size.`);
        }
    }
    const mean = finiteFloat32Array(model.normalization.mean, 'normalization mean');
    const deviation = finiteFloat32Array(model.normalization.deviation, 'normalization deviation');
    if (deviation.some((value) => value <= 0)) {
        throw new Error('The first-party wake normalization deviation must be positive.');
    }

    return Object.freeze({
        classes: Object.freeze([...model.classes]),
        thresholds: Object.freeze({
            strict_wake: model.thresholds.strict_wake.probability,
            missed_hey_confirmation: model.thresholds.missed_hey_confirmation.probability,
        }),
        mean,
        deviation,
        layers: Object.freeze(layers),
    });
}

function appendClassificationAudio(chunks) {
    const values = Array.isArray(chunks) ? chunks : [chunks];
    const valid = values.filter((value) => value instanceof Float32Array && value.length > 0);
    const incomingLength = valid.reduce((total, value) => total + value.length, 0);
    if (incomingLength === 0) return;
    const retainedLength = Math.min(classificationAudio.length, Math.max(0, MAX_CLASSIFICATION_SAMPLES - incomingLength));
    const incomingOffset = Math.max(0, incomingLength - MAX_CLASSIFICATION_SAMPLES);
    const next = new Float32Array(Math.min(MAX_CLASSIFICATION_SAMPLES, retainedLength + incomingLength - incomingOffset));
    if (retainedLength > 0) {
        next.set(classificationAudio.subarray(classificationAudio.length - retainedLength), 0);
    }
    let target = retainedLength;
    let skipped = incomingOffset;
    for (const value of valid) {
        const start = Math.min(value.length, skipped);
        skipped -= start;
        const available = Math.min(value.length - start, next.length - target);
        if (available > 0) next.set(value.subarray(start, start + available), target);
        target += available;
        if (target >= next.length) break;
    }
    classificationAudio = next;
}

function concatenateAudioChunks(chunks) {
    const valid = chunks.filter((value) => value instanceof Float32Array && value.length > 0);
    const merged = new Float32Array(valid.reduce((total, value) => total + value.length, 0));
    let offset = 0;
    for (const value of valid) {
        merged.set(value, offset);
        offset += value.length;
    }
    return merged;
}

function classifyWakeProposal(proposal) {
    if (!proposal || !beanWakeModel) return null;
    const requiredEndSample = proposal.candidateEndSample + PROPOSAL_TAIL_SAMPLES;
    if (acceptedSampleCount < requiredEndSample) return null;

    const samples = proposalClassificationWindow(proposal.candidateEndSample);
    const probabilities = beanWakeProbabilities(samples, beanWakeModel);
    const strictProposal = proposal.proposalType === 'strict';
    const activationClass = strictProposal
        ? 'strict_wake'
        : 'missed_hey_confirmation';
    const winningIndex = probabilities.indexOf(Math.max(...probabilities));
    const winningClass = beanWakeModel.classes[winningIndex] || 'reject';
    const compatiblePositive = strictProposal
        ? winningClass === 'strict_wake' || winningClass === 'missed_hey_confirmation'
        : winningClass === 'missed_hey_confirmation';
    const decisionClass = compatiblePositive ? winningClass : activationClass;
    const decisionIndex = beanWakeModel.classes.indexOf(decisionClass);
    const threshold = beanWakeModel.thresholds[activationClass];

    return Object.freeze({
        accepted: compatiblePositive && probabilities[decisionIndex] >= threshold,
        activation: activationClass,
        proposalType: proposal.proposalType,
        winningClass,
        probability: probabilities[decisionIndex],
        threshold,
        sampleCount: samples.length,
        tailSamples: PROPOSAL_TAIL_SAMPLES,
    });
}

function proposalClassificationWindow(candidateEndSample) {
    const windowStartSample = candidateEndSample - PROPOSAL_CONTEXT_SAMPLES;
    const windowEndSample = candidateEndSample + PROPOSAL_TAIL_SAMPLES;
    const retainedStartSample = acceptedSampleCount - classificationAudio.length;
    const overlapStartSample = Math.max(windowStartSample, retainedStartSample, 0);
    const overlapEndSample = Math.min(windowEndSample, acceptedSampleCount);
    const samples = new Float32Array(PROPOSAL_WINDOW_SAMPLES);

    if (overlapEndSample > overlapStartSample) {
        samples.set(
            classificationAudio.subarray(
                overlapStartSample - retainedStartSample,
                overlapEndSample - retainedStartSample,
            ),
            overlapStartSample - windowStartSample,
        );
    }

    return samples;
}

function beanWakeProbabilities(samples, model) {
    const dense1 = beanWakeEmbedding(samples, model);
    const logits = dense(dense1, model.layers['dense2.weight'], model.layers['dense2.bias'], 3);
    const maximum = Math.max(...logits);
    const exponentials = logits.map((value) => Math.exp(value - maximum));
    const total = exponentials.reduce((sum, value) => sum + value, 0);

    return exponentials.map((value) => value / total);
}

function beanWakeEmbedding(samples, model) {
    const features = beanWakeFeatures(samples);
    for (let index = 0; index < features.length; index += 1) {
        features[index] = (features[index] - model.mean[index]) / Math.max(0.08, model.deviation[index]);
    }
    const convInput = new Float32Array(features.length);
    for (let frame = 0; frame < 48; frame += 1) {
        for (let band = 0; band < 32; band += 1) {
            convInput[band * 48 + frame] = features[frame * 32 + band];
        }
    }
    const conv1 = conv1dRelu(convInput, 32, 48, model.layers['conv1.weight'], model.layers['conv1.bias'], 32, 5, 2);
    const pool1 = maxPool1d(conv1, 32, 48);
    const conv2 = conv1dRelu(pool1, 32, 24, model.layers['conv2.weight'], model.layers['conv2.bias'], 48, 3, 1);
    const pool2 = maxPool1d(conv2, 48, 24);

    return denseRelu(pool2, model.layers['dense1.weight'], model.layers['dense1.bias'], 64);
}

function beanWakeFeatures(value) {
    const samples = value instanceof Float32Array ? value : new Float32Array(value || 0);
    if (samples.length !== PROPOSAL_WINDOW_SAMPLES) {
        throw new Error('The wake classifier requires one exact proposal window.');
    }
    const frameCount = 1 + Math.max(0, Math.floor((samples.length - 400) / 160));
    const mel = new Float32Array(frameCount * 32);
    const filters = beanMelFilters();
    for (let frame = 0; frame < frameCount; frame += 1) {
        const power = fftPower(samples, frame * 160, 400, 512);
        for (let band = 0; band < 32; band += 1) {
            let energy = 0;
            const filterOffset = band * 257;
            for (let bin = 0; bin < 257; bin += 1) energy += power[bin] * filters[filterOffset + bin];
            mel[frame * 32 + band] = Math.log1p(Math.max(0, energy));
        }
    }
    const normalized = new Float32Array(48 * 32);
    for (let frame = 0; frame < 48; frame += 1) {
        const position = frame * Math.max(0, frameCount - 1) / 47;
        const left = Math.floor(position);
        const right = Math.min(left + 1, frameCount - 1);
        const fraction = position - left;
        for (let band = 0; band < 32; band += 1) {
            normalized[frame * 32 + band] = mel[left * 32 + band] * (1 - fraction)
                + mel[right * 32 + band] * fraction;
        }
    }
    let mean = 0;
    for (const value of normalized) mean += value;
    mean /= normalized.length;
    let square = 0;
    for (const value of normalized) square += (value - mean) * (value - mean);
    const deviation = Math.max(0.12, Math.sqrt(square / normalized.length));
    for (let index = 0; index < normalized.length; index += 1) {
        normalized[index] = (normalized[index] - mean) / deviation;
    }

    return normalized;
}
let cachedMelFilters = null;
function beanMelFilters() {
    if (cachedMelFilters) return cachedMelFilters;
    const hzToMel = (hz) => 2595 * Math.log10(1 + hz / 700);
    const melToHz = (mel) => 700 * (10 ** (mel / 2595) - 1);
    const low = hzToMel(80);
    const high = hzToMel(7600);
    const bins = [];
    for (let index = 0; index < 34; index += 1) {
        bins.push(Math.max(0, Math.min(256, Math.floor(513 * melToHz(low + (high - low) * index / 33) / TARGET_SAMPLE_RATE))));
    }
    cachedMelFilters = new Float32Array(32 * 257);
    for (let band = 0; band < 32; band += 1) {
        const left = bins[band];
        const center = Math.max(left + 1, bins[band + 1]);
        const right = Math.max(center + 1, bins[band + 2]);
        for (let bin = left; bin < Math.min(center, 257); bin += 1) {
            cachedMelFilters[band * 257 + bin] = (bin - left) / Math.max(1, center - left);
        }
        for (let bin = center; bin < Math.min(right, 257); bin += 1) {
            cachedMelFilters[band * 257 + bin] = (right - bin) / Math.max(1, right - center);
        }
    }

    return cachedMelFilters;
}

function fftPower(samples, offset, windowLength, fftSize) {
    const real = new Float64Array(fftSize);
    const imaginary = new Float64Array(fftSize);
    for (let index = 0; index < windowLength; index += 1) {
        const hann = 0.5 - 0.5 * Math.cos(2 * Math.PI * index / (windowLength - 1));
        real[index] = Number(samples[offset + index] || 0) * hann;
    }
    for (let index = 1, reversed = 0; index < fftSize; index += 1) {
        let bit = fftSize >> 1;
        while (reversed & bit) {
            reversed ^= bit;
            bit >>= 1;
        }
        reversed ^= bit;
        if (index >= reversed) continue;
        [real[index], real[reversed]] = [real[reversed], real[index]];
    }
    for (let size = 2; size <= fftSize; size <<= 1) {
        const angle = -2 * Math.PI / size;
        for (let start = 0; start < fftSize; start += size) {
            for (let step = 0; step < size / 2; step += 1) {
                const cosine = Math.cos(angle * step);
                const sine = Math.sin(angle * step);
                const even = start + step;
                const odd = even + size / 2;
                const oddReal = real[odd] * cosine - imaginary[odd] * sine;
                const oddImaginary = real[odd] * sine + imaginary[odd] * cosine;
                real[odd] = real[even] - oddReal;
                imaginary[odd] = imaginary[even] - oddImaginary;
                real[even] += oddReal;
                imaginary[even] += oddImaginary;
            }
        }
    }
    const power = new Float32Array(fftSize / 2 + 1);
    for (let index = 0; index < power.length; index += 1) {
        power[index] = real[index] * real[index] + imaginary[index] * imaginary[index];
    }

    return power;
}

function conv1dRelu(input, inputChannels, inputLength, weights, bias, outputChannels, kernel, padding) {
    const output = new Float32Array(outputChannels * inputLength);
    for (let channel = 0; channel < outputChannels; channel += 1) {
        for (let position = 0; position < inputLength; position += 1) {
            let sum = bias[channel];
            for (let source = 0; source < inputChannels; source += 1) {
                for (let step = 0; step < kernel; step += 1) {
                    const inputPosition = position + step - padding;
                    if (inputPosition < 0 || inputPosition >= inputLength) continue;
                    sum += input[source * inputLength + inputPosition]
                        * weights[(channel * inputChannels + source) * kernel + step];
                }
            }
            output[channel * inputLength + position] = Math.max(0, sum);
        }
    }

    return output;
}

function maxPool1d(input, channels, inputLength) {
    const outputLength = Math.floor(inputLength / 2);
    const output = new Float32Array(channels * outputLength);
    for (let channel = 0; channel < channels; channel += 1) {
        for (let position = 0; position < outputLength; position += 1) {
            output[channel * outputLength + position] = Math.max(
                input[channel * inputLength + position * 2],
                input[channel * inputLength + position * 2 + 1],
            );
        }
    }

    return output;
}

function denseRelu(input, weights, bias, outputSize) {
    const output = dense(input, weights, bias, outputSize);
    for (let index = 0; index < output.length; index += 1) output[index] = Math.max(0, output[index]);

    return new Float32Array(output);
}

function dense(input, weights, bias, outputSize) {
    const output = new Array(outputSize);
    for (let row = 0; row < outputSize; row += 1) {
        let sum = bias[row];
        for (let column = 0; column < input.length; column += 1) {
            sum += input[column] * weights[row * input.length + column];
        }
        output[row] = sum;
    }

    return output;
}

function warmKeywordSpotter() {
    const streams = [keywordSpotter.createStream(), keywordSpotter.createStream(ADDRESS_KEYWORDS)];
    try {
        const silence = new Float32Array(6400);
        for (const stream of streams) stream.acceptWaveform(TARGET_SAMPLE_RATE, silence);
        decodeReadyStreams(streams, MAX_DECODES_PER_MESSAGE * streams.length * 2);
        for (const stream of streams) keywordSpotter.getResult(stream);
    } finally {
        for (const stream of streams) stream.free();
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
    if (!ready || failed || !keywordSpotter) return;

    try {
        resetKeywordStreams();
        armed = true;
        postReady();
    } catch (error) {
        fail('reset_failed', error);
    }
}

function postReady() {
    postMessage({
        type: 'ready',
        generation: currentGeneration,
        modelReady: moduleInstance !== null && keywordSpotter !== null && beanWakeModel !== null,
        warmDecodeReady: warmDecodeComplete,
        recognitionStreamReady: keywordStreams().every((stream) => Boolean(stream?.handle)),
        keywordStreamsReady: keywordStreams().every((stream) => Boolean(stream?.handle)),
    });
}

function dormantDecision(type, reason) {
    return {
        type,
        generation: currentGeneration,
        reason,
        proposalSeen: wakeProposalSeen,
        classificationDecisionSeen,
    };
}

function handleAudio(message) {
    const generation = parseGeneration(message.generation);
    const sequence = parseSequence(message.sequence);
    const sourceSequence = parseSequence(message.sourceSequence);

    if (sequence === null || sourceSequence === null) {
        fail('invalid_sequence', 'Audio messages require non-negative integer bridge and source sequences.');
        return;
    }
    if (generation === null || generation !== currentGeneration) {
        acknowledge(sequence, generation, false, 'generation_mismatch');
        return;
    }
    if (!ready || failed || !keywordSpotter || !beanWakeModel || keywordStreams().some((stream) => !stream?.handle)) {
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
        const activeOffset = firstActiveSampleOffset(samples);
        let decodeChunks = [samples];
        let strictKeywordDecodeChunks = decodeChunks;
        let addressKeywordDecodeChunks = decodeChunks;
        if (!speechObserved) {
            if (activeOffset < 0) {
                pushDormantAudio(samples, sourceSequence);
                dormantSilenceChunks += 1;
                acknowledge(sequence, generation, true);
                return;
            }

            // Give every utterance the same model/chunk alignment. Feeding an
            // always-running KWS stream made identical prerecorded wakes change
            // decisions based only on where speech landed in a worklet/model
            // chunk. Recreate at local VAD onset and start both timing streams
            // at exactly 100 ms before it; no audio leaves the worker.
            const prefix = dormantAudioRing;
            createKeywordStreams();
            const prefixSamples = prefix.reduce((total, chunk) => total + chunk.samples.length, 0);
            speechObserved = true;
            silentSamplesAfterSpeech = 0;
            dormantSilenceChunks = 0;
            acceptedSampleCount = prefixSamples + samples.length;
            utteranceOnsetSample = prefixSamples + activeOffset;
            dormantAudioRing = [];
            streamSourceChunks = [];
            let streamOffset = 0;
            for (const chunk of [...prefix, { samples, sourceSequence }]) {
                appendSourceChunk(chunk.sourceSequence, streamOffset, chunk.samples.length);
                streamOffset += chunk.samples.length;
            }
            const onsetBoundary = sourceBoundaryForStreamSample(utteranceOnsetSample);
            if (!onsetBoundary) throw new Error('The local utterance onset boundary was unavailable.');
            postMessage({
                type: 'utterance_started',
                generation: currentGeneration,
                sourceSequence,
                boundary: onsetBoundary,
            });
            decodeChunks = [...prefix.map((chunk) => chunk.samples), samples];
            const mergedDecodeAudio = concatenateAudioChunks(decodeChunks);
            const strictAlignedStartSample = utteranceOnsetSample;
            const addressAlignedStartSample = Math.max(
                0,
                utteranceOnsetSample - CLASSIFICATION_LEADING_PAD_SAMPLES,
            );
            strictStreamStartSample = strictAlignedStartSample - STRICT_KWS_SYNTHETIC_PAD_SAMPLES;
            addressStreamStartSample = addressAlignedStartSample;
            strictKeywordDecodeChunks = [
                new Float32Array(STRICT_KWS_SYNTHETIC_PAD_SAMPLES),
                mergedDecodeAudio.subarray(strictAlignedStartSample),
            ];
            addressKeywordDecodeChunks = [mergedDecodeAudio.subarray(addressAlignedStartSample)];
        } else {
            appendSourceChunk(sourceSequence, acceptedSampleCount, samples.length);
            trackUtteranceActivity(samples);
        }

        appendClassificationAudio(decodeChunks);
        for (const chunk of strictKeywordDecodeChunks) {
            strictStream.acceptWaveform(TARGET_SAMPLE_RATE, chunk);
        }
        for (const chunk of addressKeywordDecodeChunks) {
            addressStream.acceptWaveform(TARGET_SAMPLE_RATE, chunk);
        }
        decodeReadyStreams(keywordStreams(), MAX_DECODES_PER_MESSAGE * keywordStreams().length);

        const strictResult = normalizedKeywordResult(
            keywordSpotter.getResult(strictStream),
            strictStreamStartSample,
        );
        const addressResult = normalizedKeywordResult(
            keywordSpotter.getResult(addressStream),
            addressStreamStartSample,
        );
        const proposed = coalescedWakeProposal({
            strictResult,
            addressResult,
        });
        if (!pendingProposal) {
            if (provisionalAddressProposal) {
                // The full-phrase graph may promote the provisional address
                // until the address candidate's exact 160 ms tail is locally
                // available. Querying both streams above happens before this
                // boundary check, so a strict proposal on the boundary wins.
                // The finalized candidate is emitted once; a strict promotion
                // then waits for its own candidate end plus exactly 160 ms.
                if (proposed?.proposalType === 'strict') {
                    pendingProposal = proposed;
                    provisionalAddressProposal = null;
                    postWakeProposal(pendingProposal);
                } else if (acceptedSampleCount >= provisionalAddressProposal.candidateEndSample
                    + PROPOSAL_TAIL_SAMPLES) {
                    pendingProposal = provisionalAddressProposal;
                    provisionalAddressProposal = null;
                    postWakeProposal(pendingProposal);
                }
            } else if (proposed?.proposalType === 'address') {
                // Never finalize an address on its observation message. Even
                // when its tail is already retained, the next source-audio
                // boundary is the single strict-first coalescing deadline.
                provisionalAddressProposal = proposed;
            } else if (proposed?.proposalType === 'strict') {
                pendingProposal = proposed;
                postWakeProposal(pendingProposal);
            }
        }
        const classification = classifyWakeProposal(pendingProposal);
        if (classification) {
            const classifiedProposal = pendingProposal;
            pendingProposal = null;
            classificationDecisionSeen = true;
            postMessage({
                type: 'classification_decision',
                generation: currentGeneration,
                proposalType: classification.proposalType,
                accepted: classification.accepted,
                winningClass: classification.winningClass,
                probability: classification.probability,
                threshold: classification.threshold,
                sampleCount: classification.sampleCount,
                tailSamples: classification.tailSamples,
            });
            if (!classification.accepted) {
                const discard = dormantDecision('dormant_discard', 'classifier_rejected');
                armed = false;
                resetKeywordStreams();
                acknowledge(sequence, generation, true);
                postMessage(discard);
                return;
            }
            const releaseSample = classification.activation === 'strict_wake'
                ? strictReleaseSample(classifiedProposal.candidateEndSample)
                : utteranceOnsetSample;
            const releaseBoundary = sourceBoundaryForStreamSample(releaseSample);
            if (!releaseBoundary) {
                throw new Error('The accepted wake did not have a retained local release boundary.');
            }
            // Acknowledge the live decode before confirming wake so the main
            // thread can cross its complete readiness barrier on this chunk.
            armed = false;
            acknowledge(sequence, generation, true);
            const strictWake = classification.activation === 'strict_wake';
            postMessage({
                type: 'wake_confirmed',
                keyword: KEYWORD_ALIAS,
                variant: strictWake ? 'HEY BEAN' : 'BEAN',
                activation: classification.activation,
                generation: currentGeneration,
                sourceSequence,
                releaseBoundary: {
                    ...releaseBoundary,
                    policy: strictWake ? 'post_address_tail' : 'utterance_onset',
                },
            });
            return;
        }

        if (shouldResetNonWakeUtterance()) {
            const discard = dormantDecision('dormant_discard', 'no_accepted_wake');
            armed = false;
            resetKeywordStreams();
            acknowledge(sequence, generation, true);
            postMessage(discard);
            return;
        }
        acknowledge(sequence, generation, true);
    } catch (error) {
        acknowledge(sequence, generation, false, 'decode_failed');
        fail('decode_failed', error);
    }
}

function decodeReadyStreams(streams, limit) {
    let decodeCount = 0;
    while (true) {
        let decoded = false;
        for (const stream of streams) {
            if (!keywordSpotter.isReady(stream)) continue;
            if (decodeCount >= limit) throw new Error('The keyword decoder exceeded its work limit.');
            // Decode streams independently. Quantized batch inference changed
            // near-miss decisions in evaluation, so accuracy takes precedence
            // over the optional batched API here.
            keywordSpotter.decode(stream);
            decodeCount += 1;
            decoded = true;
        }
        if (!decoded) return decodeCount;
    }
}

function coalescedWakeProposal({ strictResult, addressResult }) {
    if (strictResult.keyword && strictResult.keyword !== STRICT_WAKE_ALIAS) {
        throw new Error('The strict proposal stream returned an unexpected keyword alias.');
    }
    // Query both streams on the same production decode boundary. A strict
    // proposal owns the one candidate before inspecting the custom stream.
    // Sherpa custom-keyword streams also inherit the base HEY_BEAN graph, so
    // that alias on the address stream is expected but never becomes an
    // address proposal of its own.
    if (strictResult.keyword === STRICT_WAKE_ALIAS) {
        return wakeProposal('strict', strictResult);
    }
    if (addressResult.keyword === STRICT_WAKE_ALIAS) return null;
    if (addressResult.keyword && addressResult.keyword !== ADDRESS_WAKE_ALIAS) {
        throw new Error('The address proposal stream returned an unexpected keyword alias.');
    }
    if (addressResult.keyword === ADDRESS_WAKE_ALIAS) {
        return wakeProposal('address', addressResult);
    }

    return null;
}

function wakeProposal(proposalType, result) {
    const timestamps = Array.isArray(result?.timestamps) ? result.timestamps : [];
    const candidateEndSeconds = Number(timestamps.at(-1));
    if (!Number.isFinite(candidateEndSeconds) || timestamps.length === 0) {
        throw new Error('The wake proposal did not include an end timestamp.');
    }

    return Object.freeze({
        proposalType,
        candidateEndSample: Math.max(0, Math.round(candidateEndSeconds * TARGET_SAMPLE_RATE)),
        timestampCount: timestamps.length,
    });
}

function postWakeProposal(proposal) {
    wakeProposalSeen = true;
    // The actual timestamp remains worker-private. This diagnostic can neither
    // open the gate nor expose dormant text or PCM.
    postMessage({
        type: 'wake_proposal',
        generation: currentGeneration,
        proposalType: proposal.proposalType,
        timestampCount: proposal.timestampCount,
        requiredTailSamples: PROPOSAL_TAIL_SAMPLES,
    });
}

function strictReleaseSample(candidateEndSample) {
    const onset = Number.isSafeInteger(utteranceOnsetSample) ? utteranceOnsetSample : 0;
    return Math.max(onset, candidateEndSample - STRICT_RELEASE_TAIL_SAMPLES);
}

function appendSourceChunk(sourceSequence, startSample, length) {
    streamSourceChunks.push({ sourceSequence, startSample, length });
    if (streamSourceChunks.length > MAX_SOURCE_BOUNDARIES) {
        streamSourceChunks.splice(0, streamSourceChunks.length - MAX_SOURCE_BOUNDARIES);
    }
}

function sourceBoundaryForStreamSample(value) {
    const requested = Math.max(0, Math.floor(Number(value) || 0));
    for (const chunk of streamSourceChunks) {
        const endSample = chunk.startSample + chunk.length;
        if (requested < chunk.startSample || requested > endSample) continue;
        if (requested === endSample) {
            const next = streamSourceChunks.find((candidate) => candidate.startSample === endSample);
            if (next) return { sourceSequence: next.sourceSequence, sampleOffset: 0 };
        }
        return {
            sourceSequence: chunk.sourceSequence,
            sampleOffset: Math.max(0, Math.min(chunk.length, requested - chunk.startSample)),
        };
    }

    const latest = streamSourceChunks.at(-1);
    if (latest && requested >= latest.startSample + latest.length) {
        return { sourceSequence: latest.sourceSequence, sampleOffset: latest.length };
    }

    return null;
}

function normalizedKeywordResult(result, startSample = 0) {
    // A controlled negative strict origin subtracts the synthetic KWS-only pad
    // so all returned timestamps remain on the real utterance timeline.
    const offsetSeconds = (Number(startSample) || 0) / TARGET_SAMPLE_RATE;
    const timestamps = Array.isArray(result?.timestamps)
        ? result.timestamps
            .map(Number)
            .filter(Number.isFinite)
            .map((value) => value + offsetSeconds)
        : [];
    return {
        keyword: String(result?.keyword || '').trim().toUpperCase(),
        timestamps,
    };
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

function createKeywordStreams() {
    freeKeywordStreams();
    strictStream = keywordSpotter.createStream();
    strictStreamStartSample = 0;
    addressStream = keywordSpotter.createStream(ADDRESS_KEYWORDS);
    addressStreamStartSample = 0;
    if (keywordStreams().some((stream) => !stream?.handle)) {
        throw new Error('The local keyword streams could not be created.');
    }
    resetUtteranceActivity();
}

function resetKeywordStreams() {
    // Recreate instead of calling SherpaOnnxResetKeywordStream. Repeated
    // generation resets must also rebuild each custom keyword graph; otherwise
    // browser replay degrades after the first turn even though the model remains
    // loaded. Stream create/free is bounded and heap-stable in the packaged runtime.
    createKeywordStreams();
}

function keywordStreams() {
    return [strictStream, addressStream].filter(Boolean);
}

function freeKeywordStreams() {
    for (const stream of keywordStreams()) {
        try {
            stream.free();
        } catch {
            // Worker termination releases any remaining Wasm memory.
        }
    }
    strictStream = null;
    addressStream = null;
    strictStreamStartSample = 0;
    addressStreamStartSample = 0;
}

function trackUtteranceActivity(samples) {
    const firstActiveOffset = firstActiveSampleOffset(samples);
    acceptedSampleCount += samples.length;

    if (firstActiveOffset >= 0) {
        speechObserved = true;
        silentSamplesAfterSpeech = 0;
        dormantSilenceChunks = 0;
        return;
    }
    if (speechObserved) {
        silentSamplesAfterSpeech += samples.length;
    } else {
        dormantSilenceChunks += 1;
    }
}

function firstActiveSampleOffset(samples) {
    for (let offset = 0; offset < samples.length; offset += ACTIVITY_FRAME_SAMPLES) {
        const end = Math.min(samples.length, offset + ACTIVITY_FRAME_SAMPLES);
        let squareSum = 0;
        for (let index = offset; index < end; index += 1) {
            squareSum += samples[index] * samples[index];
        }
        if (Math.sqrt(squareSum / Math.max(1, end - offset)) >= SPEECH_RMS_THRESHOLD) {
            return offset;
        }
    }

    return -1;
}

function pushDormantAudio(samples, sourceSequence) {
    dormantAudioRing.push({ samples: samples.slice(), sourceSequence });
    if (dormantAudioRing.length > DORMANT_AUDIO_RING_CHUNKS) {
        dormantAudioRing.splice(0, dormantAudioRing.length - DORMANT_AUDIO_RING_CHUNKS);
    }
    if (dormantSilenceChunks >= MAX_DORMANT_SILENCE_CHUNKS) {
        dormantSilenceChunks = 0;
    }
}

function shouldResetNonWakeUtterance() {
    return armed
        && speechObserved
        && silentSamplesAfterSpeech >= NON_WAKE_SILENCE_RESET_SAMPLES;
}

function resetUtteranceActivity() {
    speechObserved = false;
    silentSamplesAfterSpeech = 0;
    dormantSilenceChunks = 0;
    acceptedSampleCount = 0;
    utteranceOnsetSample = null;
    dormantAudioRing = [];
    streamSourceChunks = [];
    classificationAudio = new Float32Array(0);
    provisionalAddressProposal = null;
    pendingProposal = null;
    wakeProposalSeen = false;
    classificationDecisionSeen = false;
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
    warmDecodeComplete = false;
    freeKeywordStreams();
    if (keywordSpotter) {
        try {
            keywordSpotter.free();
        } catch {
            // Worker termination releases remaining Wasm memory.
        }
        keywordSpotter = null;
    }
    moduleInstance = null;
    beanWakeModel = null;
    resetUtteranceActivity();
}
