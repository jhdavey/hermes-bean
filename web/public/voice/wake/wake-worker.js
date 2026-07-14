'use strict';

// Local, single-thread wake classification. Raw microphone audio never leaves
// this worker while Bean is dormant. The bundled KWS component supplies only
// strict-phrase timing candidates; Bean's first-party classifier owns every
// acceptance and missed-Hey address decision.

const TARGET_SAMPLE_RATE = 16000;
const MAX_AUDIO_SAMPLES = 3200;
const MAX_DECODES_PER_MESSAGE = 32;
const NON_WAKE_SILENCE_RESET_CHUNKS = 7;
const MAX_DORMANT_SILENCE_CHUNKS = 300;
const ACTIVITY_FRAME_SAMPLES = 320;
const DORMANT_AUDIO_RING_CHUNKS = 2;
const SPEECH_RMS_THRESHOLD = 0.012;
const STRICT_RELEASE_TAIL_SAMPLES = Math.round(TARGET_SAMPLE_RATE * 0.12);
const MAX_SOURCE_BOUNDARIES = 100;
const MAX_CLASSIFICATION_SAMPLES = TARGET_SAMPLE_RATE * 3;
const CLASSIFICATION_LEADING_PAD_SAMPLES = Math.round(TARGET_SAMPLE_RATE * 0.1);
const CLASSIFICATION_TRAILING_PAD_SAMPLES = Math.round(TARGET_SAMPLE_RATE * 0.25);
// The independently timed strict path retains its certified operating point.
// The prefix fallback is much stricter because it has no second timing vote.
// Cross-engine calibration keeps the weakest held-out strict wake (0.765 on
// WebKit) above the operating point while retaining a 0.261 observed margin
// over the strongest timing-triggered negative (0.489). This remains one
// phrase-agnostic verifier threshold; no recognized words or incident phrase enters
// the decision.
const STRICT_ACCEPTANCE_PROBABILITY = 0.75;
const STRICT_PREFIX_ACCEPTANCE_PROBABILITY = 0.99;
const STRICT_PREFIX_FALLBACK_SILENCE_CHUNKS = 4;
const ADDRESS_ACCEPTANCE_PROBABILITY = 0.95;
const FIRST_PARTY_ADDRESS_MIN_SAMPLES = Math.round(TARGET_SAMPLE_RATE * 0.5);
const FIRST_PARTY_ADDRESS_MAX_SAMPLES = Math.round(TARGET_SAMPLE_RATE * 2.8);
const FIRST_PARTY_ADDRESS_INTERVAL_SAMPLES = Math.round(TARGET_SAMPLE_RATE * 0.3);
const KEYWORD_ALIAS = 'HEY_BEAN';
const STRICT_WAKE_ALIAS = 'HEY_BEAN';
const RUNTIME_VERSION = '12';

// The timing detector proposes only the product wake phrase. It never embeds
// incident-specific negative phrases. The first-party classifier below owns
// the generic acoustic accept/reject decision for every proposed candidate.
const STRICT_KEYWORDS = 'HH EY1 B IY1 N :1.2 #0.1 @HEY_BEAN';

const assetBaseUrl = new URL('./', self.location.href);

let currentGeneration = initialGeneration();
let moduleInstance = null;
let keywordSpotter = null;
let beanWakeModel = null;
let strictStream = null;
let ready = false;
let armed = false;
let warmDecodeComplete = false;
let failed = false;
let closed = false;
let speechObserved = false;
let silentChunksAfterSpeech = 0;
let dormantSilenceChunks = 0;
let acceptedSampleCount = 0;
let utteranceOnsetSeconds = null;
let utteranceOnsetSample = null;
let dormantAudioRing = [];
let streamSourceChunks = [];
let classificationAudio = new Float32Array(0);
let nextFirstPartyAddressSample = FIRST_PARTY_ADDRESS_MIN_SAMPLES;

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
            numTrailingBlanks: 1,
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
    const response = await fetch(versionedAsset('bean-wake-model-v1.json'), {
        cache: 'force-cache',
        credentials: 'same-origin',
    });
    if (!response.ok) throw new Error(`The first-party wake model failed to load (${response.status}).`);
    const model = await response.json();
    validateBeanWakeModel(model);

    return prepareBeanWakeModel(model);
}

function validateBeanWakeModel(model) {
    if (!model || model.schema_version !== '1.0.0'
        || model.model_id !== 'bean-first-party-wake-v1'
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
            const parsed = Number(value);
            if (!Number.isFinite(parsed)) throw new Error('The first-party wake model contains a non-finite weight.');
            output.push(parsed);
        }

        return output;
    };
    const layers = {};
    for (const [name, layer] of Object.entries(model.classifier.layers)) {
        layers[name] = new Float32Array(flatten(layer.values));
        const expectedLength = layer.shape.reduce((total, size) => total * size, 1);
        if (layers[name].length !== expectedLength) {
            throw new Error(`The first-party wake layer ${name} has the wrong size.`);
        }
    }

    return Object.freeze({
        classes: Object.freeze([...model.classes]),
        mean: new Float32Array(model.normalization.mean),
        deviation: new Float32Array(model.normalization.deviation),
        thresholds: Object.freeze({
            strict_wake: Number(model.thresholds?.strict_wake?.probability),
            missed_hey_confirmation: Number(model.thresholds?.missed_hey_confirmation?.probability),
        }),
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

function classifyBeanCandidate(decisionType, keywordResult) {
    if (!beanWakeModel || classificationAudio.length < 400) {
        throw new Error('The first-party wake classifier is unavailable.');
    }
    const expectedClass = decisionType === 'strict_wake'
        ? 'strict_wake'
        : 'missed_hey_confirmation';
    const windows = decisionType === 'strict_wake'
        ? [
            ...isolatedStrictWakeWindows(),
            ...[1.8, 1.2].map((seconds) => ({
                samples: classificationAudio.subarray(Math.max(
                    0,
                    classificationAudio.length - Math.round(TARGET_SAMPLE_RATE * seconds),
                )),
            })),
        ]
        : [{ samples: classificationSamplesForKeyword(keywordResult) }];
    let probabilities = null;
    let samples = null;
    for (const window of windows) {
        const candidate = beanWakeProbabilities(window.samples, beanWakeModel);
        const expected = beanWakeModel.classes.indexOf(expectedClass);
        if (!probabilities || candidate[expected] > probabilities[expected]) {
            probabilities = candidate;
            samples = window.samples;
        }
        const winning = candidate.indexOf(Math.max(...candidate));
        const earlyThreshold = decisionType === 'strict_wake'
            ? STRICT_ACCEPTANCE_PROBABILITY
            : ADDRESS_ACCEPTANCE_PROBABILITY;
        if (winning === expected && candidate[expected] >= earlyThreshold) break;
    }
    const expectedIndex = beanWakeModel.classes.indexOf(expectedClass);
    const winningIndex = probabilities.indexOf(Math.max(...probabilities));
    const threshold = decisionType === 'strict_wake'
        ? Math.min(beanWakeModel.thresholds[expectedClass], STRICT_ACCEPTANCE_PROBABILITY)
        : Math.min(beanWakeModel.thresholds[expectedClass], ADDRESS_ACCEPTANCE_PROBABILITY);
    if (!Number.isFinite(threshold) || expectedIndex < 0) {
        throw new Error('The first-party wake threshold is unavailable.');
    }

    return Object.freeze({
        accepted: winningIndex === expectedIndex && probabilities[expectedIndex] >= threshold,
        expectedClass,
        winningClass: beanWakeModel.classes[winningIndex] || 'reject',
        probability: probabilities[expectedIndex],
        threshold,
        sampleCount: samples.length,
    });
}

function isolatedStrictWakeWindows() {
    const retainedStartSample = Math.max(0, acceptedSampleCount - classificationAudio.length);
    const onsetSample = Number.isSafeInteger(utteranceOnsetSample) ? utteranceOnsetSample : retainedStartSample;
    const start = Math.max(retainedStartSample, onsetSample - CLASSIFICATION_LEADING_PAD_SAMPLES);
    const localStart = start - retainedStartSample;
    return [0.5, 0.55, 0.6, 0.65, 0.7, 0.75, 0.8].map((seconds) => {
        const end = Math.min(
            acceptedSampleCount,
            Math.max(start + 400, onsetSample + Math.round(seconds * TARGET_SAMPLE_RATE)),
        );
        const speech = classificationAudio.subarray(localStart, end - retainedStartSample);
        const isolated = new Float32Array(speech.length + CLASSIFICATION_TRAILING_PAD_SAMPLES);
        isolated.set(speech);
        return { samples: isolated };
    });
}

function classifyFirstPartyAddressPrefix() {
    if (!beanWakeModel || classificationAudio.length < FIRST_PARTY_ADDRESS_MIN_SAMPLES) return null;
    const probabilities = beanWakeProbabilities(classificationAudio, beanWakeModel);
    const missedHeyClass = 'missed_hey_confirmation';
    const strictWakeClass = 'strict_wake';
    const missedHeyIndex = beanWakeModel.classes.indexOf(missedHeyClass);
    const strictWakeIndex = beanWakeModel.classes.indexOf(strictWakeClass);
    const winningIndex = probabilities.indexOf(Math.max(...probabilities));
    // Never let the fallback preempt the timing detector while the utterance
    // is still in progress. Four local silent chunks give the strict decoder
    // its trailing-blank window first; only a genuinely missed timing result
    // reaches this acoustic recovery path.
    const strictWakeAccepted = silentChunksAfterSpeech >= STRICT_PREFIX_FALLBACK_SILENCE_CHUNKS
        && winningIndex === strictWakeIndex
        && probabilities[strictWakeIndex] >= STRICT_PREFIX_ACCEPTANCE_PROBABILITY;
    const missedHeyAccepted = winningIndex === missedHeyIndex
        && probabilities[missedHeyIndex] >= ADDRESS_ACCEPTANCE_PROBABILITY;
    const expectedClass = strictWakeAccepted ? strictWakeClass : missedHeyClass;
    const expectedIndex = strictWakeAccepted ? strictWakeIndex : missedHeyIndex;
    return Object.freeze({
        accepted: strictWakeAccepted || missedHeyAccepted,
        // This is the timing-detector fallback, so it always uses the existing
        // missed-Hey privacy protocol even when the acoustic model's strict
        // class is what rescued the address. Only the timing-owned path may
        // publish a strict wake activation.
        activation: 'missed_hey_confirmation',
        expectedClass,
        winningClass: beanWakeModel.classes[winningIndex] || 'reject',
        probability: probabilities[expectedIndex],
        threshold: strictWakeAccepted
            ? STRICT_PREFIX_ACCEPTANCE_PROBABILITY
            : ADDRESS_ACCEPTANCE_PROBABILITY,
        sampleCount: classificationAudio.length,
    });
}

function classificationSamplesForKeyword(keywordResult) {
    const timestamps = Array.isArray(keywordResult?.timestamps) ? keywordResult.timestamps : [];
    const firstSeconds = Number(timestamps[0]);
    const lastSeconds = Number(timestamps.at(-1));
    if (!Number.isFinite(firstSeconds) || !Number.isFinite(lastSeconds) || lastSeconds < firstSeconds) {
        throw new Error('The first-party wake candidate did not include a valid acoustic boundary.');
    }
    const retainedStartSample = Math.max(0, acceptedSampleCount - classificationAudio.length);
    const keywordStartSample = Math.max(0, Math.floor(firstSeconds * TARGET_SAMPLE_RATE));
    const keywordEndSample = Math.max(keywordStartSample + 1, Math.ceil(lastSeconds * TARGET_SAMPLE_RATE));
    const start = Math.max(retainedStartSample, keywordStartSample - CLASSIFICATION_LEADING_PAD_SAMPLES);
    const end = Math.min(
        acceptedSampleCount,
        keywordEndSample + CLASSIFICATION_TRAILING_PAD_SAMPLES,
    );
    const localStart = start - retainedStartSample;
    const localEnd = end - retainedStartSample;
    if (localEnd - localStart < 400) {
        throw new Error('The first-party wake candidate boundary was not retained.');
    }

    return classificationAudio.subarray(localStart, localEnd);
}

function beanWakeProbabilities(samples, model) {
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
    const dense1 = denseRelu(pool2, model.layers['dense1.weight'], model.layers['dense1.bias'], 64);
    const logits = dense(dense1, model.layers['dense2.weight'], model.layers['dense2.bias'], 3);
    const maximum = Math.max(...logits);
    const exponentials = logits.map((value) => Math.exp(value - maximum));
    const total = exponentials.reduce((sum, value) => sum + value, 0);

    return exponentials.map((value) => value / total);
}

function beanWakeFeatures(value) {
    let samples = trimClassificationSpeech(value);
    if (samples.length < 400) {
        const padded = new Float32Array(400);
        padded.set(samples);
        samples = padded;
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

function trimClassificationSpeech(value) {
    const samples = value instanceof Float32Array ? value : new Float32Array(value || 0);
    let first = -1;
    let last = -1;
    for (let start = 0; start < samples.length; start += 320) {
        const end = Math.min(samples.length, start + 320);
        let square = 0;
        for (let index = start; index < end; index += 1) square += samples[index] * samples[index];
        if (Math.sqrt(square / Math.max(1, end - start)) < 0.009) continue;
        if (first < 0) first = start;
        last = end;
    }
    if (first < 0) return samples.subarray(Math.max(0, samples.length - TARGET_SAMPLE_RATE));

    return samples.subarray(Math.max(0, first - 1600), Math.min(samples.length, last + 1920));
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
    const streams = [keywordSpotter.createStream()];
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
    if (message.type === 'cancel_candidate') {
        handleCancelCandidate(message);
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
        if (!speechObserved) {
            if (activeOffset < 0) {
                pushDormantAudio(samples, sourceSequence);
                dormantSilenceChunks += 1;
                acknowledge(sequence, generation, true);
                return;
            }

            // Give every utterance the same model/chunk alignment. Feeding an
            // always-running KWS stream made identical prerecorded wakes change
            // decisions based only on where speech landed in a 320 ms model
            // chunk. Recreate at local VAD onset and replay a bounded 200 ms
            // local prefix; no audio leaves the worker.
            const prefix = dormantAudioRing;
            createKeywordStreams();
            const prefixSamples = prefix.reduce((total, chunk) => total + chunk.samples.length, 0);
            speechObserved = true;
            silentChunksAfterSpeech = 0;
            dormantSilenceChunks = 0;
            acceptedSampleCount = prefixSamples + samples.length;
            utteranceOnsetSample = prefixSamples + activeOffset;
            utteranceOnsetSeconds = (prefixSamples + activeOffset) / TARGET_SAMPLE_RATE;
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
        } else {
            appendSourceChunk(sourceSequence, acceptedSampleCount, samples.length);
            trackUtteranceActivity(samples);
        }

        appendClassificationAudio(decodeChunks);
        for (const chunk of decodeChunks) {
            for (const stream of keywordStreams()) stream.acceptWaveform(TARGET_SAMPLE_RATE, chunk);
        }
        decodeReadyStreams(keywordStreams(), MAX_DECODES_PER_MESSAGE * keywordStreams().length);

        const strictResult = normalizedKeywordResult(keywordSpotter.getResult(strictStream));
        let decision = keywordDecision({
            strictResult,
        });
        let classification = null;
        const utteranceSamples = Number.isSafeInteger(utteranceOnsetSample)
            ? acceptedSampleCount - utteranceOnsetSample
            : 0;
        if (decision.type === 'none'
            && utteranceSamples >= nextFirstPartyAddressSample
            && utteranceSamples <= FIRST_PARTY_ADDRESS_MAX_SAMPLES) {
            nextFirstPartyAddressSample = utteranceSamples + FIRST_PARTY_ADDRESS_INTERVAL_SAMPLES;
            classification = classifyFirstPartyAddressPrefix();
            if (classification?.accepted) {
                decision = {
                    type: 'address_confirmed',
                    addressRelated: true,
                    activation: classification.activation,
                };
            } else if (classification) {
                // Sanitized rejection telemetry makes the complete missed-Hey
                // journey diagnosable without exposing PCM or dormant text.
                postMessage({
                    type: 'classification_decision',
                    generation: currentGeneration,
                    decisionType: 'address_prefix_rejected',
                    accepted: false,
                    expectedClass: classification.expectedClass,
                    winningClass: classification.winningClass,
                    probability: classification.probability,
                    threshold: classification.threshold,
                    sampleCount: classification.sampleCount,
                });
            }
        }

        if (decision.type === 'strict_wake' || decision.type === 'address_confirmed') {
            classification = classification || classifyBeanCandidate(
                decision.type,
                strictResult,
            );
            // Sanitized model telemetry is available to deterministic test and
            // admin-diagnostic consumers. It contains no PCM or dormant text.
            postMessage({
                type: 'classification_decision',
                generation: currentGeneration,
                decisionType: decision.type,
                accepted: classification.accepted,
                expectedClass: classification.expectedClass,
                winningClass: classification.winningClass,
                probability: classification.probability,
                threshold: classification.threshold,
                sampleCount: classification.sampleCount,
            });
            if (!classification.accepted) {
                resetKeywordStreams();
                acknowledge(sequence, generation, true);
                postMessage({
                    type: decision.addressRelated ? 'address_rejected' : 'dormant_discard',
                    generation: currentGeneration,
                });
                return;
            }
            // Acknowledge the live decode before confirming wake so the main
            // thread can cross its complete readiness barrier on this chunk.
            armed = false;
            acknowledge(sequence, generation, true);
            if (decision.type === 'address_confirmed'
                && decision.activation !== 'strict_wake') {
                // This event contains no audio or text and remains invisible to
                // the application. It preserves the local confirmation protocol.
                postMessage({ type: 'address_candidate', generation: currentGeneration });
            }
            const releaseSample = decision.type === 'strict_wake'
                ? strictReleaseSample(strictResult)
                : utteranceOnsetSample;
            const releaseBoundary = sourceBoundaryForStreamSample(releaseSample);
            if (!releaseBoundary) {
                throw new Error('The confirmed wake did not have a retained local release boundary.');
            }
            postMessage({
                type: 'wake_confirmed',
                keyword: KEYWORD_ALIAS,
                variant: decision.type === 'strict_wake' ? 'HEY BEAN' : 'BEAN',
                activation: decision.type === 'strict_wake' || decision.activation === 'strict_wake'
                    ? 'strict_wake'
                    : 'missed_hey_confirmation',
                generation: currentGeneration,
                sourceSequence,
                releaseBoundary: {
                    ...releaseBoundary,
                    policy: decision.type === 'strict_wake'
                        ? 'post_address_tail'
                        : 'utterance_onset',
                },
            });
            return;
        }

        if (decision.type === 'reject') {
            resetKeywordStreams();
            acknowledge(sequence, generation, true);
            postMessage({
                type: decision.addressRelated ? 'address_rejected' : 'dormant_discard',
                generation: currentGeneration,
            });
            return;
        }

        if (shouldResetNonWakeUtterance()) {
            resetKeywordStreams();
            acknowledge(sequence, generation, true);
            postMessage({ type: 'dormant_discard', generation: currentGeneration });
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

function keywordDecision({ strictResult }) {
    if (strictResult.keyword === STRICT_WAKE_ALIAS) {
        // A deliberate Hey Bean must work even after other sound in the same
        // continuous local utterance. Only the confirmed keyword's short tail
        // is released, so accepting an in-stream match does not expose the
        // earlier room conversation to the provider.
        return { type: 'strict_wake', addressRelated: false };
    }
    if (strictResult.keyword) return { type: 'reject', addressRelated: false };

    return { type: 'none', addressRelated: false };
}

function strictReleaseSample(result) {
    const timestamps = Array.isArray(result?.timestamps) ? result.timestamps : [];
    const keywordEndSeconds = Number(timestamps.at(-1));
    if (!Number.isFinite(keywordEndSeconds)) {
        throw new Error('The strict wake result did not include an end timestamp.');
    }

    const keywordEndSample = Math.max(0, Math.round(keywordEndSeconds * TARGET_SAMPLE_RATE));
    const onset = Number.isSafeInteger(utteranceOnsetSample) ? utteranceOnsetSample : 0;
    return Math.max(onset, keywordEndSample - STRICT_RELEASE_TAIL_SAMPLES);
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

function normalizedKeywordResult(result) {
    const timestamps = Array.isArray(result?.timestamps)
        ? result.timestamps.map(Number).filter(Number.isFinite)
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

function handleCancelCandidate(message) {
    const generation = parseGeneration(message.generation);
    if (generation === null || generation !== currentGeneration || !ready || failed) return;

    try {
        resetKeywordStreams();
        armed = true;
        postMessage({ type: 'address_rejected', generation: currentGeneration });
    } catch (error) {
        fail('candidate_cancel_failed', error);
    }
}

function createKeywordStreams() {
    freeKeywordStreams();
    strictStream = keywordSpotter.createStream();
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
    return [strictStream].filter(Boolean);
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
}

function trackUtteranceActivity(samples) {
    const firstActiveOffset = firstActiveSampleOffset(samples);
    const chunkStartSample = acceptedSampleCount;
    acceptedSampleCount += samples.length;

    if (firstActiveOffset >= 0) {
        if (!speechObserved) {
            utteranceOnsetSeconds = (chunkStartSample + firstActiveOffset) / TARGET_SAMPLE_RATE;
        }
        speechObserved = true;
        silentChunksAfterSpeech = 0;
        dormantSilenceChunks = 0;
        return;
    }
    if (speechObserved) {
        silentChunksAfterSpeech += 1;
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
        && silentChunksAfterSpeech >= NON_WAKE_SILENCE_RESET_CHUNKS;
}

function resetUtteranceActivity() {
    speechObserved = false;
    silentChunksAfterSpeech = 0;
    dormantSilenceChunks = 0;
    acceptedSampleCount = 0;
    utteranceOnsetSeconds = null;
    utteranceOnsetSample = null;
    dormantAudioRing = [];
    streamSourceChunks = [];
    classificationAudio = new Float32Array(0);
    nextFirstPartyAddressSample = FIRST_PARTY_ADDRESS_MIN_SAMPLES;
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
