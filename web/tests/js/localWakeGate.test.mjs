import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

import {
    LOCAL_WAKE_GATE_PROCESSOR_NAME,
    LOCAL_WAKE_GATE_PROCESSOR_URL,
    LOCAL_WAKE_CONSUMER_READY_TIMEOUT_MS,
    LOCAL_WAKE_PCM_ACK_TIMEOUT_MS,
    LOCAL_WAKE_PCM_SAMPLE_RATE,
    LOCAL_WAKE_WORKER_FAILURE_DETAIL_TIMEOUT_MS,
    LOCAL_WAKE_WORKER_URL,
    LocalWakeGate,
} from '../../resources/js/heybean/localWakeGate.js';

function validWakeModelFixture() {
    const layer = (shape) => ({
        shape,
        values: Array(shape.reduce((total, size) => total * size, 1)).fill(0),
    });

    return {
        schema_version: '2.0.0',
        model_id: 'bean-first-party-wake-v2',
        runtime_network_required: false,
        external_account_required: false,
        license_key_required: false,
        sample_rate: 16_000,
        classes: ['reject', 'strict_wake', 'missed_hey_confirmation'],
        feature: {
            fft_size: 512,
            window_samples: 400,
            hop_samples: 160,
            mel_bands: 32,
            normalized_frames: 48,
        },
        normalization: {
            mean: Array(1_536).fill(0),
            deviation: Array(1_536).fill(1),
        },
        proposal_window: {
            alignment: 'proposal_end',
            context_samples: 19_200,
            tail_samples: 2_560,
            total_samples: 21_760,
        },
        thresholds: {
            strict_wake: { probability: 0.95 },
            missed_hey_confirmation: { probability: 0.95 },
        },
        classifier: {
            architecture: 'temporal_conv1d_v1',
            layers: {
                'conv1.weight': layer([32, 32, 5]),
                'conv1.bias': layer([32]),
                'conv2.weight': layer([48, 32, 3]),
                'conv2.bias': layer([48]),
                'dense1.weight': layer([64, 576]),
                'dense1.bias': layer([64]),
                'dense2.weight': layer([3, 64]),
                'dense2.bias': layer([3]),
            },
        },
    };
}

test('packaged wake manifest and checksum inventory match every distributed byte', async () => {
    const assetRoot = new URL('../../public/voice/wake/', import.meta.url);
    const manifest = JSON.parse(await readFile(new URL('manifest.json', assetRoot), 'utf8'));
    const sha256 = (value) => createHash('sha256').update(value).digest('hex');
    assert.equal(manifest.worker, LOCAL_WAKE_WORKER_URL);
    assert.equal(manifest.audioWorklet, LOCAL_WAKE_GATE_PROCESSOR_URL);
    const assets = [
        { url: manifest.worker, bytes: manifest.workerBytes, hash: manifest.workerSha256 },
        { url: manifest.audioWorklet, bytes: manifest.audioWorkletBytes, hash: manifest.audioWorkletSha256 },
        {
            url: manifest.firstPartyWakeModel.path,
            bytes: manifest.firstPartyWakeModel.bytes,
            hash: manifest.firstPartyWakeModel.sha256,
        },
        ...manifest.vendorAssets.map((asset) => ({
            url: asset.path,
            bytes: asset.bytes,
            hash: asset.sha256,
        })),
    ];
    assert.ok(Number.isSafeInteger(manifest.firstPartyWakeModel.bytes));
    assert.match(manifest.firstPartyWakeModel.sha256, /^[a-f0-9]{64}$/);
    assert.deepEqual(manifest.firstPartyWakeModel, {
        id: 'bean-first-party-wake-v2',
        path: '/voice/wake/bean-wake-model-v2.json?v=17',
        bytes: manifest.firstPartyWakeModel.bytes,
        sha256: manifest.firstPartyWakeModel.sha256,
        available: true,
        schemaVersion: '2.0.0',
        architecture: 'temporal_conv1d_v1',
        classes: ['reject', 'strict_wake', 'missed_hey_confirmation'],
        inputSamples: 21_760,
        tailSamples: 2_560,
        tailMs: 160,
        purpose: 'wake_class_acceptance',
        runtimeNetworkRequired: false,
        externalAccountRequired: false,
        licenseKeyRequired: false,
    });
    assert.deepEqual(manifest.runtimeDecision, {
        proposalAuthority: 'bundled_local_kws',
        proposalAliases: ['HEY_BEAN', 'BEAN'],
        acceptanceAuthority: 'bean-first-party-wake-v2',
        proposalTailMs: 160,
        kwsMayActivate: false,
        safeBoundarySource: 'accepted_compatible_proposal_timestamp',
    });

    for (const asset of assets) {
        const name = new URL(asset.url, 'https://example.test').pathname.replace('/voice/wake/', '');
        const value = await readFile(new URL(name, assetRoot));
        assert.equal(value.byteLength, asset.bytes, `${name} byte count`);
        assert.equal(sha256(value), asset.hash, `${name} manifest hash`);
    }

    const inventory = await readFile(new URL('SHA256SUMS', assetRoot), 'utf8');
    for (const line of inventory.trim().split('\n')) {
        const match = line.match(/^([a-f0-9]{64})  (.+)$/);
        assert.ok(match, `Invalid checksum inventory line: ${line}`);
        const value = await readFile(new URL(match[2], assetRoot));
        assert.equal(sha256(value), match[1], `${match[2]} inventory hash`);
    }
});

test('orchestration protocol matches the packaged same-origin worker and worklet', async () => {
    const assetRoot = new URL('../../public/voice/wake/', import.meta.url);
    const [processor, worker] = await Promise.all([
        readFile(new URL('gate-processor.js', assetRoot), 'utf8'),
        readFile(new URL('wake-worker.js', assetRoot), 'utf8'),
    ]);

    assert.match(processor, new RegExp(`const PROCESSOR_NAME = '${LOCAL_WAKE_GATE_PROCESSOR_NAME}'`));
    assert.match(processor, /const AUDIO_BATCH_SAMPLES = 1280/);
    assert.match(processor, /registerProcessor\(PROCESSOR_NAME,/);
    assert.match(processor, /type:\s*'audio'/);
    assert.match(processor, /message\.type === 'activate'/);
    assert.match(processor, /message\.type === 'close'/);
    assert.match(worker, /message\.type === 'audio'/);
    assert.match(worker, /message\.type === 'reset'/);
    assert.doesNotMatch(worker, /cancel_candidate|handleCancelCandidate/);
    assert.match(worker, /message\.type === 'close'/);
    assert.match(worker, /type:\s*'wake_confirmed'/);
    assert.match(worker, /type:\s*'wake_proposal'/);
    assert.match(worker, /type:\s*'classification_decision'/);
    assert.match(worker, /createKws\(moduleInstance,/);
    assert.match(worker, /warmKeywordSpotter\(\)/);
    assert.match(worker, /classifyWakeProposal\(pendingProposal\)/);
    assert.match(worker, /SPEECH_RMS_THRESHOLD = 0\.008/);
    assert.match(processor, /\(rms - 0\.008\) \/ 0\.16/);
    assert.match(worker, /keywordSpotter\.createStream\(ADDRESS_KEYWORDS\)/);
    assert.match(worker, /numTrailingBlanks: 0/);
    assert.match(worker, /maxActivePaths: 4/);
    assert.match(worker, /HH EY1 B IY1 N :3\.0 #0\.01 @HEY_BEAN/);
    assert.match(worker, /HH EY1 B IY1 M :3\.0 #0\.01 @HEY_BEAN/);
    assert.doesNotMatch(worker, /@HEY_BEAM/);
    assert.match(worker, /B IY1 N :3\.0 #0\.01 @BEAN/);
    assert.doesNotMatch(worker, /createOnlineRecognizer/);
});

test('the KWS wrapper preserves an explicit zero trailing-blank proposal setting', async () => {
    const source = await readFile(
        new URL('../../public/voice/wake/kws-api.js', import.meta.url),
        'utf8',
    );
    const context = {};
    runInNewContext(`${source}\nglobalThis.__initKwsConfig = initKwsConfig;`, context);

    function configure(numTrailingBlanks) {
        let nextPointer = 1_024;
        const writes = [];
        const Module = {
            HEAPU8: new Uint8Array(1_000_000),
            _malloc(bytes) {
                const pointer = nextPointer;
                nextPointer += Number(bytes) + 16;
                return pointer;
            },
            _free() {},
            _CopyHeap() {},
            lengthBytesUTF8(value) { return String(value || '').length; },
            stringToUTF8() {},
            setValue(address, value, type) { writes.push({ address, value, type }); },
        };
        const config = {
            featConfig: { samplingRate: 16_000, featureDim: 80 },
            modelConfig: {
                transducer: { encoder: 'e', decoder: 'd', joiner: 'j' },
                tokens: 'tokens',
                provider: 'cpu',
                modelType: '',
                numThreads: 1,
                debug: 0,
                modelingUnit: 'phone+ppinyin',
                bpeVocab: '',
            },
            maxActivePaths: 4,
            keywordsScore: 1,
            keywordsThreshold: 0.01,
            keywords: '',
            keywordsBuf: 'B IY1 N :3.0 #0.01 @BEAN',
            keywordsBufSize: 28,
            ...(numTrailingBlanks === undefined ? {} : { numTrailingBlanks }),
        };
        const prepared = context.__initKwsConfig(config, Module);
        const address = prepared.ptr + prepared.featConfig.len + prepared.modelConfig.len + 4;

        return writes.find((write) => write.address === address);
    }

    const explicitZero = configure(0);
    assert.equal(explicitZero.value, 0);
    assert.equal(explicitZero.type, 'i32');
    assert.equal(configure(undefined).value, 1);
});

async function createWakeWorkerHarness({ generation = 7 } = {}) {
    const source = await readFile(
        new URL('../../public/voice/wake/wake-worker.js', import.meta.url),
        'utf8',
    );
    const messages = [];
    const context = {
        URL,
        importScripts() { throw new Error('Vendor runtime intentionally skipped.'); },
        postMessage(message) { messages.push(structuredClone(message)); },
        self: {
            location: { href: `https://example.test/voice/wake/wake-worker.js?generation=${generation}` },
            addEventListener() {},
            close() {},
        },
    };
    runInNewContext(`${source}
let __strictResults = [];
let __addressResults = [];
let __probabilities = [0.99, 0.005, 0.005];
let __classificationWindows = [];
let __nextHandle = 1;
const __emptyResult = () => ({ keyword: '', timestamps: [] });
const __stream = (kind) => ({
    kind,
    handle: __nextHandle++,
    acceptWaveform() {},
    free() {},
});
globalThis.__configure = (generation) => {
    currentGeneration = generation;
    ready = true;
    failed = false;
    closed = false;
    armed = true;
    warmDecodeComplete = true;
    moduleInstance = {};
    beanWakeModel = {
        classes: ['reject', 'strict_wake', 'missed_hey_confirmation'],
        thresholds: { strict_wake: 0.95, missed_hey_confirmation: 0.95 },
    };
    beanWakeProbabilities = (samples) => {
        __classificationWindows.push(Array.from(samples));
        return [...__probabilities];
    };
    keywordSpotter = {
        createStream(keywords = '') {
            return __stream(keywords === ADDRESS_KEYWORDS ? 'address' : 'strict');
        },
        getResult(stream) {
            const queue = stream.kind === 'strict' ? __strictResults : __addressResults;
            return queue.shift() || __emptyResult();
        },
        isReady() { return false; },
        decode() {},
        free() {},
    };
    resetUtteranceActivity();
    createKeywordStreams();
};
globalThis.__setProbabilities = (values) => { __probabilities = [...values]; };
globalThis.__setThresholds = (values) => {
    beanWakeModel = {
        ...beanWakeModel,
        thresholds: {...values},
    };
};
globalThis.__queueProposal = (proposalType, candidateEndSample = 1280) => {
    if (proposalType === 'strict') {
        const end = candidateEndSample / TARGET_SAMPLE_RATE + 0.32;
        __strictResults.push({ keyword: 'HEY_BEAN', timestamps: [0.32, end] });
        return;
    }
    const end = candidateEndSample / TARGET_SAMPLE_RATE;
    __addressResults.push({ keyword: 'BEAN', timestamps: [0.02, end] });
};
globalThis.__queueUnexpectedAlias = () => {
    __strictResults.push({ keyword: 'NOT_BEAN', timestamps: [0.32, 0.4] });
};
globalThis.__queueAddressAlias = (keyword, candidateEndSample = 1280) => {
    __addressResults.push({
        keyword,
        timestamps: [0.02, candidateEndSample / TARGET_SAMPLE_RATE],
    });
};
globalThis.__send = (generation, sequence, sourceSequence, value = 0.08, length = 1280) => {
    handleAudio({
        type: 'audio',
        generation,
        sequence,
        sourceSequence,
        samples: new Float32Array(length).fill(value),
    });
};
globalThis.__reset = (generation) => handleReset({ type: 'reset', generation });
globalThis.__classificationWindows = () => __classificationWindows;
globalThis.__normalizedKeywordResult = normalizedKeywordResult;
globalThis.__beanWakeFeatures = beanWakeFeatures;
globalThis.__wakeBoundary = {
    firstActiveSampleOffset,
    track: trackUtteranceActivity,
    shouldReset: shouldResetNonWakeUtterance,
    reset: resetUtteranceActivity,
    setArmed(value) { armed = value; },
};
`, context);
    messages.length = 0;
    context.__configure(generation);

    return { context, messages, source };
}

test('the packaged worker has one classifier acceptance owner and no dormant semantic shortcuts', async () => {
    const { context, source } = await createWakeWorkerHarness();

    assert.match(source, /HH EY1 B IY1 N :3\.0 #0\.01 @HEY_BEAN/);
    assert.match(source, /HH EY1 B IY1 M :3\.0 #0\.01 @HEY_BEAN/);
    assert.doesNotMatch(source, /@HEY_BEAM/);
    assert.match(source, /B IY1 N :3\.0 #0\.01 @BEAN/);
    assert.match(source, /PROPOSAL_TAIL_SAMPLES = Math\.round\(TARGET_SAMPLE_RATE \* 0\.16\)/);
    assert.match(source, /const classification = classifyWakeProposal\(pendingProposal\)/);
    assert.match(source, /compatiblePositive/);
    assert.match(source, /beanWakeModel\.thresholds\[activationClass\]/);
    assert.match(source, /type: 'classification_decision'/);
    assert.match(source, /type: 'wake_confirmed'/);
    assert.doesNotMatch(source, /decoded_text|result\.text|transcript/);
    assert.doesNotMatch(source, /fallback|guard|base model/i);

    assert.deepEqual(
        [...context.__normalizedKeywordResult(
            { keyword: 'HEY_BEAN', timestamps: [0.4, 0.6] },
            -5_120,
        ).timestamps].map((value) => Number(value.toFixed(6))),
        [0.08, 0.28],
    );
    assert.equal(context.__wakeBoundary.firstActiveSampleOffset(
        new Float32Array(1_280).fill(0.009),
    ), 0);
    assert.equal(context.__wakeBoundary.firstActiveSampleOffset(
        new Float32Array(1_280).fill(0.007),
    ), -1);

    const speech = new Float32Array(1_280).fill(0.08);
    const silence = new Float32Array(1_280);
    context.__wakeBoundary.setArmed(true);
    context.__wakeBoundary.track(speech);
    for (let chunk = 0; chunk < 8; chunk += 1) {
        context.__wakeBoundary.track(silence);
        assert.equal(context.__wakeBoundary.shouldReset(), false);
    }
    context.__wakeBoundary.track(silence);
    assert.equal(context.__wakeBoundary.shouldReset(), true);
});

test('[BV2-FIRST-WAKE-01:A] strict proposal waits for exactly 160 ms then the shared classifier confirms one safe wake', async () => {
    const { context, messages } = await createWakeWorkerHarness();
    context.__setProbabilities([0.001, 0.998, 0.001]);
    context.__queueProposal('strict');

    context.__send(7, 1, 10, 0.11);
    assert.deepEqual(messages.map(({ type }) => type), [
        'utterance_started',
        'wake_proposal',
        'ack',
    ]);
    assert.equal(messages.some(({ type }) => type === 'wake_confirmed'), false);
    assert.deepEqual(messages[1], {
        type: 'wake_proposal',
        generation: 7,
        proposalType: 'strict',
        timestampCount: 2,
        requiredTailSamples: 2_560,
    });

    context.__send(7, 2, 11, 0.22);
    assert.equal(messages.some(({ type }) => type === 'classification_decision'), false);
    assert.equal(messages.some(({ type }) => type === 'wake_confirmed'), false);

    context.__send(7, 3, 12, 0.33);
    assert.equal(messages.filter(({ type }) => type === 'classification_decision').length, 1);
    assert.equal(messages.filter(({ type }) => type === 'wake_confirmed').length, 1);
    assert.deepEqual(messages.slice(-3), [
        {
            type: 'classification_decision',
            generation: 7,
            proposalType: 'strict',
            accepted: true,
            winningClass: 'strict_wake',
            probability: 0.998,
            threshold: 0.95,
            sampleCount: 21_760,
            tailSamples: 2_560,
        },
        { type: 'ack', sequence: 3, generation: 7, accepted: true },
        {
            type: 'wake_confirmed',
            keyword: 'HEY_BEAN',
            variant: 'HEY BEAN',
            activation: 'strict_wake',
            generation: 7,
            sourceSequence: 12,
            releaseBoundary: {
                sourceSequence: 10,
                sampleOffset: 0,
                policy: 'post_address_tail',
            },
        },
    ]);

    const windows = context.__classificationWindows();
    assert.equal(windows.length, 1);
    assert.equal(windows[0].length, 21_760);
    assert.ok(windows[0].slice(0, 17_920).every((value) => value === 0));
    assert.ok(windows[0].slice(17_920, 19_200).every((value) => Math.abs(value - 0.11) < 1e-6));
    assert.ok(windows[0].slice(19_200, 20_480).every((value) => Math.abs(value - 0.22) < 1e-6));
    assert.ok(windows[0].slice(20_480).every((value) => Math.abs(value - 0.33) < 1e-6));
    for (const message of messages) {
        assert.equal(Object.hasOwn(message, 'samples'), false);
        assert.equal(Object.hasOwn(message, 'audio'), false);
        assert.equal(Object.hasOwn(message, 'transcript'), false);
    }
});

test('[BV2-MISSED-HEY-01] address-only proposal gets one grace step and one shared-classifier decision', async () => {
    const { context, messages } = await createWakeWorkerHarness();
    context.__setThresholds({
        strict_wake: 0.95,
        missed_hey_confirmation: 0.99,
    });
    context.__setProbabilities([0.001, 0.001, 0.998]);
    context.__queueProposal('address');

    context.__send(7, 1, 20);
    assert.equal(messages.some(({ type }) => type === 'wake_proposal'), false);
    assert.equal(messages.some(({ type }) => type === 'wake_confirmed'), false);

    context.__send(7, 2, 21);
    assert.equal(messages.some(({ type }) => type === 'wake_proposal'), false);
    assert.equal(messages.some(({ type }) => type === 'classification_decision'), false);

    context.__send(7, 3, 22);
    assert.deepEqual(messages.filter(({ type }) => type === 'wake_proposal'), [{
        type: 'wake_proposal',
        generation: 7,
        proposalType: 'address',
        timestampCount: 2,
        requiredTailSamples: 2_560,
    }]);
    assert.deepEqual(messages.filter(({ type }) => type === 'classification_decision'), [{
        type: 'classification_decision',
        generation: 7,
        proposalType: 'address',
        accepted: true,
        winningClass: 'missed_hey_confirmation',
        probability: 0.998,
        threshold: 0.99,
        sampleCount: 21_760,
        tailSamples: 2_560,
    }]);
    assert.deepEqual(messages.filter(({ type }) => type === 'wake_confirmed'), [{
        type: 'wake_confirmed',
        keyword: 'HEY_BEAN',
        variant: 'BEAN',
        activation: 'missed_hey_confirmation',
        generation: 7,
        sourceSequence: 22,
        releaseBoundary: {
            sourceSequence: 20,
            sampleOffset: 0,
            policy: 'utterance_onset',
        },
    }]);

    // A strict observation one source boundary after address finalization is a
    // new event, not a retroactive promotion of the durable address decision.
    context.__queueProposal('strict', 5_120);
    context.__send(7, 4, 23);
    assert.equal(messages.filter(({ type }) => type === 'wake_proposal').length, 1);
    assert.equal(messages.filter(({ type }) => type === 'classification_decision').length, 1);
    assert.equal(messages.filter(({ type }) => type === 'wake_confirmed').length, 1);
    assert.deepEqual(messages.at(-1), {
        type: 'ack',
        sequence: 4,
        generation: 7,
        accepted: false,
        reason: 'activation_pending',
    });
});

// This wake-decision regression pairs with the deterministic browser
// [BV2-FIRST-WAKE-01:C–E] journey for provider PCM, transcript, durable
// admission, final delivery, and reload coverage.
test('[BV2-FIRST-WAKE-01:A–B] strict proposal plus learned address-positive class completes one safe strict wake', async () => {
    const { context, messages } = await createWakeWorkerHarness();
    context.__setThresholds({
        strict_wake: 0.95,
        missed_hey_confirmation: 0.99,
    });
    context.__setProbabilities([0.001, 0.011, 0.988]);
    context.__queueProposal('strict');

    context.__send(7, 1, 30, 0.11);
    context.__send(7, 2, 31, 0.22);
    context.__send(7, 3, 32, 0.33);

    assert.deepEqual(messages.filter(({ type }) => type === 'classification_decision'), [{
        type: 'classification_decision',
        generation: 7,
        proposalType: 'strict',
        accepted: true,
        winningClass: 'missed_hey_confirmation',
        probability: 0.988,
        threshold: 0.95,
        sampleCount: 21_760,
        tailSamples: 2_560,
    }]);
    assert.deepEqual(messages.filter(({ type }) => type === 'wake_confirmed'), [{
        type: 'wake_confirmed',
        keyword: 'HEY_BEAN',
        variant: 'HEY BEAN',
        activation: 'strict_wake',
        generation: 7,
        sourceSequence: 32,
        releaseBoundary: {
            sourceSequence: 30,
            sampleOffset: 0,
            policy: 'post_address_tail',
        },
    }]);
});

test('[BV2-FIRST-WAKE-01:B] address at N and strict at N+2 coalesce before one strict decision', async () => {
    const { context, messages } = await createWakeWorkerHarness();
    context.__setProbabilities([0.001, 0.998, 0.001]);
    context.__queueProposal('address', 1_280);
    context.__send(7, 1, 30);
    context.__send(7, 2, 31);
    assert.equal(messages.some(({ type }) => type === 'wake_proposal'), false);

    context.__queueProposal('strict', 3_840);
    context.__send(7, 3, 32);
    assert.deepEqual(messages.filter(({ type }) => type === 'wake_proposal'), [{
        type: 'wake_proposal',
        generation: 7,
        proposalType: 'strict',
        timestampCount: 2,
        requiredTailSamples: 2_560,
    }]);
    assert.equal(messages.some(({ type }) => type === 'classification_decision'), false);

    context.__send(7, 4, 33);
    assert.equal(messages.some(({ type }) => type === 'classification_decision'), false);
    context.__send(7, 5, 34);

    const decisions = messages.filter(({ type }) => type === 'classification_decision');
    assert.equal(decisions.length, 1);
    assert.equal(decisions[0].proposalType, 'strict');
    assert.equal(decisions[0].winningClass, 'strict_wake');
    const confirmations = messages.filter(({ type }) => type === 'wake_confirmed');
    assert.equal(confirmations.length, 1);
    assert.equal(confirmations[0].activation, 'strict_wake');
    assert.equal(confirmations[0].releaseBoundary.policy, 'post_address_tail');
    assert.deepEqual(confirmations[0].releaseBoundary, {
        sourceSequence: 31,
        sampleOffset: 640,
        policy: 'post_address_tail',
    });
});

test('same-boundary strict wins when the custom address stream inherits HEY_BEAN', async () => {
    const { context, messages } = await createWakeWorkerHarness();
    context.__setProbabilities([0.001, 0.998, 0.001]);
    context.__queueProposal('strict');
    context.__queueAddressAlias('HEY_BEAN');
    context.__send(7, 1, 80);
    context.__send(7, 2, 81);
    context.__send(7, 3, 82);

    assert.deepEqual(messages.filter(({ type }) => type === 'wake_proposal').map(({ proposalType }) => proposalType), [
        'strict',
    ]);
    assert.deepEqual(messages.filter(({ type }) => type === 'classification_decision').map(({ proposalType }) => proposalType), [
        'strict',
    ]);
    assert.equal(messages.filter(({ type }) => type === 'wake_confirmed').length, 1);
    assert.equal(messages.some(({ type }) => type === 'error'), false);

    const inheritedOnly = await createWakeWorkerHarness();
    inheritedOnly.context.__queueAddressAlias('HEY_BEAN');
    inheritedOnly.context.__send(7, 1, 83);
    inheritedOnly.context.__send(7, 2, 84);
    inheritedOnly.context.__send(7, 3, 85);
    assert.equal(inheritedOnly.messages.some(({ type }) => type === 'wake_proposal'), false);
    assert.equal(inheritedOnly.messages.some(({ type }) => type === 'classification_decision'), false);
    assert.equal(inheritedOnly.messages.some(({ type }) => type === 'wake_confirmed'), false);
    assert.equal(inheritedOnly.messages.some(({ type }) => type === 'error'), false);
});

test('address deadline overshoot still queries strict first and waits for the promoted strict tail', async () => {
    const { context, messages } = await createWakeWorkerHarness();
    context.__setProbabilities([0.001, 0.998, 0.001]);
    context.__queueProposal('address', 1_280);
    context.__send(7, 1, 90);
    context.__queueProposal('strict', 4_480);
    context.__send(7, 2, 91, 0.08, 3_200);

    assert.deepEqual(messages.filter(({ type }) => type === 'wake_proposal').map(({ proposalType }) => proposalType), [
        'strict',
    ]);
    assert.equal(messages.some(({ type }) => type === 'classification_decision'), false);
    context.__send(7, 3, 92);
    assert.equal(messages.some(({ type }) => type === 'classification_decision'), false);
    context.__send(7, 4, 93);
    assert.equal(messages.filter(({ type }) => type === 'classification_decision').length, 1);
    assert.equal(messages.find(({ type }) => type === 'classification_decision').proposalType, 'strict');
});

test('a late-observed address with an existing tail gets exactly one following-boundary strict promotion chance', async () => {
    const { context, messages } = await createWakeWorkerHarness();
    context.__setProbabilities([0.001, 0.998, 0.001]);
    context.__send(7, 1, 100);
    context.__send(7, 2, 101);
    context.__queueProposal('address', 1_280);
    context.__send(7, 3, 102);
    assert.equal(messages.some(({ type }) => type === 'wake_proposal'), false);

    context.__queueProposal('strict', 5_120);
    context.__send(7, 4, 103);
    assert.deepEqual(messages.filter(({ type }) => type === 'wake_proposal').map(({ proposalType }) => proposalType), [
        'strict',
    ]);
    context.__send(7, 5, 104);
    context.__send(7, 6, 105);
    assert.equal(messages.filter(({ type }) => type === 'classification_decision').length, 1);
    assert.equal(messages.find(({ type }) => type === 'classification_decision').proposalType, 'strict');
});

test('[BV2-MISSED-HEY-02] address proposal with strict-only class rejects, rearms, ignores stale audio, then allows the next wake', async () => {
    const { context, messages } = await createWakeWorkerHarness();
    context.__setProbabilities([0.001, 0.998, 0.001]);
    context.__queueProposal('address');
    context.__send(7, 1, 40);
    context.__send(7, 2, 41);
    context.__send(7, 3, 42);

    assert.equal(messages.filter(({ type }) => type === 'wake_confirmed').length, 0);
    assert.equal(messages.filter(({ type }) => type === 'classification_decision').length, 1);
    assert.equal(messages.find(({ type }) => type === 'classification_decision').accepted, false);
    assert.deepEqual(messages.find(({ type }) => type === 'dormant_discard'), {
        type: 'dormant_discard',
        generation: 7,
        reason: 'classifier_rejected',
        proposalSeen: true,
        classificationDecisionSeen: true,
    });

    context.__reset(8);
    context.__setProbabilities([0.001, 0.998, 0.001]);
    context.__queueProposal('strict');
    context.__send(7, 4, 43);
    const staleAck = messages.at(-1);
    assert.deepEqual(staleAck, {
        type: 'ack',
        sequence: 4,
        generation: 7,
        accepted: false,
        reason: 'generation_mismatch',
    });
    context.__send(8, 5, 50);
    context.__send(8, 6, 51);
    context.__send(8, 7, 52);
    assert.equal(messages.filter(({ type }) => type === 'wake_confirmed').length, 1);
    assert.equal(messages.find(({ type }) => type === 'wake_confirmed').generation, 8);
});

test('[BV2-WAKE-ALIAS-01] Hey Beam uses the same proposal and classifier path as exact wake', async () => {
    const { context, messages, source } = await createWakeWorkerHarness();
    context.__setProbabilities([0.001, 0.998, 0.001]);
    context.__queueProposal('strict');
    context.__send(7, 1, 60);
    context.__send(7, 2, 61);
    context.__send(7, 3, 62);

    assert.equal(messages.filter(({ type }) => type === 'wake_proposal').length, 1);
    assert.equal(messages.filter(({ type }) => type === 'classification_decision').length, 1);
    assert.equal(messages.filter(({ type }) => type === 'wake_confirmed').length, 1);
    assert.doesNotMatch(source, /HEY BEAM|HEY_BEAM/);
});

test('[BV2-WAKE-MODEL-02] exact proposal-routed artifact thresholds accept and the values below reject', async () => {
    const thresholds = {
        strict_wake: 0.9876543,
        missed_hey_confirmation: 0.9654321,
    };
    const decisionFor = async (proposalType, probabilities, sequence) => {
        const harness = await createWakeWorkerHarness();
        harness.context.__setThresholds(thresholds);
        harness.context.__setProbabilities(probabilities);
        harness.context.__queueProposal(proposalType);
        harness.context.__send(7, 1, sequence);
        harness.context.__send(7, 2, sequence + 1);
        harness.context.__send(7, 3, sequence + 2);
        return {
            decision: harness.messages.find(({ type }) => type === 'classification_decision'),
            confirmations: harness.messages.filter(({ type }) => type === 'wake_confirmed'),
        };
    };

    const strictAtThreshold = await decisionFor('strict', [0.01, 0.0023457, 0.9876543], 80);
    assert.equal(strictAtThreshold.decision.accepted, true);
    assert.equal(strictAtThreshold.decision.winningClass, 'missed_hey_confirmation');
    assert.equal(strictAtThreshold.decision.threshold, thresholds.strict_wake);
    assert.equal(strictAtThreshold.confirmations.length, 1);
    assert.equal(strictAtThreshold.confirmations[0].activation, 'strict_wake');

    const strictBelowThreshold = await decisionFor('strict', [0.01, 0.0023458, 0.9876542], 90);
    assert.equal(strictBelowThreshold.decision.accepted, false);
    assert.equal(strictBelowThreshold.decision.threshold, thresholds.strict_wake);
    assert.equal(strictBelowThreshold.confirmations.length, 0);

    const addressAtThreshold = await decisionFor('address', [0.02, 0.0145679, 0.9654321], 100);
    assert.equal(addressAtThreshold.decision.accepted, true);
    assert.equal(addressAtThreshold.decision.threshold, thresholds.missed_hey_confirmation);
    assert.equal(addressAtThreshold.confirmations.length, 1);
    assert.equal(addressAtThreshold.confirmations[0].activation, 'missed_hey_confirmation');

    const addressBelowThreshold = await decisionFor('address', [0.02, 0.014568, 0.965432], 110);
    assert.equal(addressBelowThreshold.decision.accepted, false);
    assert.equal(addressBelowThreshold.decision.threshold, thresholds.missed_hey_confirmation);
    assert.equal(addressBelowThreshold.confirmations.length, 0);
});

test('[BV2-WORKER-FAIL-01] malformed KWS output fails closed without a confirmation', async () => {
    const { context, messages } = await createWakeWorkerHarness();
    context.__queueUnexpectedAlias();
    context.__send(7, 1, 70);

    assert.equal(messages.some(({ type }) => type === 'wake_confirmed'), false);
    assert.equal(messages.some(({ type }) => type === 'error'), true);
    assert.equal(messages.find(({ type }) => type === 'error').fatal, true);
});

test('[BV2-WAKE-MODEL-02] malformed or superseded shared wake-model values fail closed', async () => {
    const source = await readFile(
        new URL('../../public/voice/wake/wake-worker.js', import.meta.url),
        'utf8',
    );
    const context = {
        URL,
        importScripts() { throw new Error('Vendor candidate runtime intentionally skipped.'); },
        postMessage() {},
        self: {
            location: { href: 'https://example.test/voice/wake/wake-worker.js?generation=1' },
            addEventListener() {},
            close() {},
        },
    };
    runInNewContext(`${source}
globalThis.__prepareWakeModel = (manifest) => {
    validateBeanWakeModel(manifest);
    return prepareBeanWakeModel(manifest);
};
globalThis.__beanWakeFeatures = beanWakeFeatures;`, context);

    const valid = validWakeModelFixture();
    valid.thresholds.strict_wake.probability = 0.9876543;
    valid.thresholds.missed_hey_confirmation.probability = 0.9654321;
    const prepared = context.__prepareWakeModel(valid);
    assert.deepEqual([...prepared.classes], ['reject', 'strict_wake', 'missed_hey_confirmation']);
    assert.equal(prepared.layers['dense2.weight'].length, 192);
    assert.equal(prepared.layers['dense2.bias'].length, 3);
    assert.deepEqual(
        {...prepared.thresholds},
        { strict_wake: 0.9876543, missed_hey_confirmation: 0.9654321 },
    );
    assert.equal(context.__beanWakeFeatures(new Float32Array(21_760)).length, 1_536);
    assert.throws(() => context.__beanWakeFeatures(new Float32Array(21_759)));

    const corruptions = [
        (model) => { model.schema_version = '1.0.0'; },
        (model) => { model.model_id = 'unexpected-wake-model'; },
        (model) => { model.classes = ['reject', 'unexpected', 'missed_hey_confirmation']; },
        (model) => { model.proposal_window.tail_samples = 2_559; },
        (model) => { model.proposal_window.total_samples = 21_759; },
        (model) => { model.thresholds.strict_wake.probability = 0.9; },
        (model) => { model.thresholds.strict_wake.probability = 1.0000001; },
        (model) => { model.thresholds.strict_wake.probability = Number.NaN; },
        (model) => { model.thresholds.strict_wake.probability = Number.POSITIVE_INFINITY; },
        (model) => { model.thresholds.strict_wake.probability = '0.98'; },
        (model) => { delete model.thresholds.strict_wake; },
        (model) => { model.thresholds.reject = { probability: 0.95 }; },
        (model) => { model.thresholds.strict_wake.extra = true; },
        (model) => { model.classifier.layers.extra = { shape: [1], values: [0] }; },
        (model) => { delete model.classifier.layers['conv1.weight']; },
        (model) => { model.classifier.layers['conv1.weight'].shape = [32, 32, 4]; },
        (model) => { model.classifier.layers['conv1.weight'].values.pop(); },
        (model) => { model.classifier.layers['dense2.weight'].values[0] = 1e100; },
        (model) => { model.classifier.layers['dense2.bias'].shape = [2]; },
        (model) => { model.classifier.layers['dense2.bias'].values[0] = Number.POSITIVE_INFINITY; },
        (model) => { model.normalization.mean[0] = null; },
        (model) => { model.normalization.deviation[0] = 0; },
    ];
    for (const corrupt of corruptions) {
        const model = structuredClone(valid);
        corrupt(model);
        assert.throws(() => context.__prepareWakeModel(model));
    }
});

test('the packaged worklet is an exact-zero analysis sink before and after activation', async () => {
    const source = await readFile(
        new URL('../../public/voice/wake/gate-processor.js', import.meta.url),
        'utf8',
    );
    let Processor = null;
    let processorName = '';
    const processorMessages = [];
    class FakeAudioWorkletProcessor {
        constructor() {
            this.port = {
                close() {},
                onmessage: null,
                postMessage(message) { processorMessages.push(message); },
            };
        }
    }
    runInNewContext(source, {
        AudioWorkletProcessor: FakeAudioWorkletProcessor,
        sampleRate: 16_000,
        registerProcessor(name, implementation) {
            processorName = name;
            Processor = implementation;
        },
    });

    assert.equal(processorName, LOCAL_WAKE_GATE_PROCESSOR_NAME);
    const processor = new Processor();
    processor.handleControlMessage({ type: 'close', generation: 1 });

    function render(samples) {
        const rendered = [];
        for (let offset = 0; offset < samples.length; offset += 128) {
            const input = samples.slice(offset, Math.min(samples.length, offset + 128));
            const output = new Float32Array(input.length);
            assert.equal(processor.process([[input]], [[output]]), true);
            rendered.push(output);
        }
        const result = new Float32Array(rendered.reduce((total, chunk) => total + chunk.length, 0));
        let offset = 0;
        rendered.forEach((chunk) => {
            result.set(chunk, offset);
            offset += chunk.length;
        });
        return result;
    }

    // Raw PCM is posted only to the local main-thread boundary. The audio graph
    // itself remains exact zero before and after local confirmation.
    const beforeWake = new Float32Array(32_000);
    beforeWake.fill(0.25, 16_000, 17_600);
    const closedOutput = render(beforeWake);
    assert.equal(closedOutput.every((sample) => Object.is(sample, 0)), true);
    assert.ok(processorMessages.some((message) => message.type === 'activity' && message.level > 0));

    processor.handleControlMessage({ type: 'activate', generation: 1 });
    const openOutput = render(new Float32Array(20_800));
    assert.equal(openOutput.every((sample) => Object.is(sample, 0)), true);
    assert.ok(processorMessages.some((message) => message.type === 'audio'));
});

test('[BV2-FIRST-WAKE-01:A/B][BV2-STARTUP-01..03] visible 48 kHz speech activity survives the real resampler and crosses worker VAD', async () => {
    const assetRoot = new URL('../../public/voice/wake/', import.meta.url);
    const [processorSource, workerSource] = await Promise.all([
        readFile(new URL('gate-processor.js', assetRoot), 'utf8'),
        readFile(new URL('wake-worker.js', assetRoot), 'utf8'),
    ]);
    const processorMessages = [];
    let Processor = null;
    class FakeAudioWorkletProcessor {
        constructor() {
            this.port = {
                close() {},
                onmessage: null,
                postMessage(message) { processorMessages.push(message); },
            };
        }
    }
    runInNewContext(processorSource, {
        AudioWorkletProcessor: FakeAudioWorkletProcessor,
        sampleRate: 48_000,
        registerProcessor(_name, implementation) { Processor = implementation; },
    });

    const workerContext = {
        URL,
        importScripts() { throw new Error('Vendor candidate runtime intentionally skipped.'); },
        postMessage() {},
        self: {
            location: { href: 'https://example.test/voice/wake/wake-worker.js?generation=1' },
            addEventListener() {},
            close() {},
        },
    };
    runInNewContext(`${workerSource}
globalThis.__firstActiveSampleOffset = firstActiveSampleOffset;`, workerContext);

    const sourceRate = 48_000;
    const sourceSamples = new Float32Array(3_840);
    for (let index = 0; index < sourceSamples.length; index += 1) {
        const time = index / sourceRate;
        const envelope = Math.min(
            1,
            index / Math.round(sourceRate * 0.008),
            (sourceSamples.length - 1 - index) / Math.round(sourceRate * 0.008),
        );
        sourceSamples[index] = envelope * (
            0.018 * Math.sin(2 * Math.PI * 180 * time)
            + 0.012 * Math.sin(2 * Math.PI * 540 * time)
        );
    }

    const processor = new Processor();
    processor.handleControlMessage({ type: 'close', generation: 1 });
    for (let offset = 0; offset < sourceSamples.length; offset += 128) {
        const input = sourceSamples.slice(offset, offset + 128);
        const output = new Float32Array(input.length);
        assert.equal(processor.process([[input]], [[output]]), true);
        assert.equal(output.every((sample) => Object.is(sample, 0)), true);
    }

    const activity = processorMessages.find(
        (message) => message.type === 'activity' && message.level > 0,
    );
    assert.ok(activity, 'pre-resample microphone RMS must drive the visible border signal');
    assert.ok(activity.rms > 0.008);

    const resampled = processorMessages.find((message) => message.type === 'audio')?.samples;
    assert.equal(Object.prototype.toString.call(resampled), '[object Float32Array]');
    assert.equal(resampled.length, 1_280, '80 ms at 48 kHz must emit one 80 ms 16 kHz batch');
    const resampledRms = Math.sqrt(
        resampled.reduce((sum, sample) => sum + sample * sample, 0) / resampled.length,
    );
    assert.ok(resampledRms >= 0.008, `resampled RMS ${resampledRms} fell below worker VAD`);
    assert.ok(
        workerContext.__firstActiveSampleOffset(resampled) >= 0,
        'the real worker VAD must accept the worklet-resampled speech-like batch',
    );
});

test('a newer dormant generation erases rejected candidate audio before a later confirmed wake opens', async () => {
    const source = await readFile(
        new URL('../../public/voice/wake/gate-processor.js', import.meta.url),
        'utf8',
    );
    let Processor = null;
    class FakeAudioWorkletProcessor {
        constructor() {
            this.port = { close() {}, onmessage: null, postMessage() {} };
        }
    }
    runInNewContext(source, {
        AudioWorkletProcessor: FakeAudioWorkletProcessor,
        sampleRate: 16_000,
        registerProcessor(_name, implementation) { Processor = implementation; },
    });
    const processor = new Processor();
    const render = (samples) => {
        const rendered = [];
        for (let offset = 0; offset < samples.length; offset += 128) {
            const input = samples.slice(offset, offset + 128);
            const output = new Float32Array(input.length);
            processor.process([[input]], [[output]]);
            rendered.push(...output);
        }

        return rendered;
    };

    processor.handleControlMessage({ type: 'close', generation: 1 });
    const rejectedCandidate = new Float32Array(19_200).fill(0.25);
    assert.equal(render(rejectedCandidate).every((sample) => Object.is(sample, 0)), true);

    // Local rejection rotates the generation while closed, which synchronously
    // discards the candidate and every in-flight analysis/pre-roll sample.
    processor.handleControlMessage({ type: 'close', generation: 2 });
    const confirmedWake = new Float32Array(19_200);
    confirmedWake.fill(0.5, 0, 1600);
    assert.equal(render(confirmedWake).every((sample) => Object.is(sample, 0)), true);
    processor.handleControlMessage({ type: 'activate', generation: 2 });
    const released = render(new Float32Array(19_200));

    assert.equal(released.every((sample) => Object.is(sample, 0)), true);
});

function createHarness({
    addModuleError = null,
    beforeRelease = null,
    consumerReadyTimeoutMs = LOCAL_WAKE_CONSUMER_READY_TIMEOUT_MS,
    maxInFlightPcm = 2,
    maxBufferedPcm = 80,
    consumerReady = true,
    onDetected = null,
    onReleaseRejected = null,
    pcmAckTimeoutMs = LOCAL_WAKE_PCM_ACK_TIMEOUT_MS,
    precreateAudioContext = false,
} = {}) {
    const order = [];
    const contexts = [];
    const worklets = [];
    const workers = [];
    const timers = new Map();
    const sourceSequenceByGeneration = new Map();
    let clock = 0;
    let nextTimerId = 1;

    function setTimeout(callback, delay) {
        const timerId = nextTimerId;
        nextTimerId += 1;
        timers.set(timerId, { callback, at: clock + Math.max(0, Number(delay) || 0) });

        return timerId;
    }

    function clearTimeout(timerId) {
        timers.delete(timerId);
    }

    function advance(milliseconds) {
        const target = clock + Math.max(0, Number(milliseconds) || 0);
        while (true) {
            const next = [...timers.entries()]
                .filter(([, timer]) => timer.at <= target)
                .sort((left, right) => left[1].at - right[1].at || left[0] - right[0])[0];
            if (!next) break;
            const [timerId, timer] = next;
            timers.delete(timerId);
            clock = timer.at;
            timer.callback();
        }
        clock = target;
    }

    class FakeTrack {
        constructor(name) {
            this.name = name;
            this.kind = 'audio';
            this.stopped = false;
        }

        stop() {
            this.stopped = true;
            order.push(`track:${this.name}:stop`);
        }
    }

    class FakeMediaStream {
        constructor(tracks = []) {
            this.tracks = [...tracks];
        }

        getTracks() {
            return [...this.tracks];
        }

        getAudioTracks() {
            return this.tracks.filter((track) => track.kind === 'audio');
        }
    }

    class FakePort {
        constructor() {
            this.messages = [];
            this.onmessage = null;
            this.onmessageerror = null;
            this.closed = false;
        }

        postMessage(message, transfer = []) {
            this.messages.push({ message, transfer });
            order.push(`gate:${message.type}`);
        }

        emit(data) {
            this.onmessage?.({ data });
        }

        close() {
            this.closed = true;
            order.push('port:close');
        }
    }

    class FakeNode {
        constructor(name) {
            this.name = name;
            this.connections = [];
            this.disconnected = false;
        }

        connect(target) {
            this.connections.push(target);
            order.push(`${this.name}:connect:${target.name}`);
            return target;
        }

        disconnect() {
            this.disconnected = true;
            order.push(`${this.name}:disconnect`);
        }
    }

    class FakeAudioWorkletNode extends FakeNode {
        constructor(context, name, options) {
            super('worklet');
            this.context = context;
            this.processorName = name;
            this.options = options;
            this.port = new FakePort();
            worklets.push(this);
        }
    }

    class FakeAudioContext {
        constructor() {
            this.sampleRate = 48_000;
            this.source = null;
            this.destination = new FakeNode('destination');
            this.closed = false;
            this.audioWorklet = {
                addModule: async (url) => {
                    order.push(`module:${url}`);
                    if (addModuleError) throw addModuleError;
                },
            };
            contexts.push(this);
        }

        createMediaStreamSource(stream) {
            this.source = new FakeNode('source');
            this.source.stream = stream;
            return this.source;
        }

        async close() {
            this.closed = true;
            order.push('context:close');
        }
    }

    class FakeWorker {
        constructor(url, options) {
            this.url = url;
            this.options = options;
            this.messages = [];
            this.terminated = false;
            this.onmessage = null;
            this.onerror = null;
            this.onmessageerror = null;
            workers.push(this);
        }

        postMessage(message, transfer = []) {
            this.messages.push({ message, transfer });
            order.push(`worker:${message.type}`);
        }

        emit(data) {
            this.onmessage?.({ data });
        }

        fail(message = 'worker exploded') {
            this.onerror?.({ error: new Error(message), message });
        }

        terminate() {
            this.terminated = true;
            order.push('worker:terminate');
        }
    }

    const activities = [];
    const diagnostics = [];
    const errors = [];
    const detections = [];
    const releaseRejections = [];
    const readiness = [];
    const activatedPcm = [];
    const preparedAudioContext = precreateAudioContext ? new FakeAudioContext() : null;
    const gate = new LocalWakeGate({
        AudioContext: FakeAudioContext,
        AudioWorkletNode: FakeAudioWorkletNode,
        Worker: FakeWorker,
        MediaStream: FakeMediaStream,
        ...(preparedAudioContext ? { audioContext: preparedAudioContext } : {}),
        maxInFlightPcm,
        maxBufferedPcm,
        consumerReady,
        consumerReadyTimeoutMs,
        beforeRelease: beforeRelease || (() => true),
        pcmAckTimeoutMs,
        setTimeout,
        clearTimeout,
        now: () => clock,
        onReady: (event) => {
            order.push('ready');
            readiness.push(event);
        },
        onActivity: (activity) => activities.push(activity),
        onDiagnostic: (diagnostic) => diagnostics.push(diagnostic),
        onActivatedPcm: (event) => activatedPcm.push(event),
        onError: (error) => errors.push(error),
        onDetected: (detection) => {
            order.push('detected');
            detections.push(detection);
            onDetected?.(detection);
        },
        onReleaseRejected: (event) => {
            releaseRejections.push(event);
            onReleaseRejected?.(event);
        },
    });

    const rawTrack = new FakeTrack('raw');
    const rawStream = new FakeMediaStream([rawTrack]);

    function emitPcm({
        generation = gate.currentGeneration(),
        samples = new Float32Array(4),
        sourceSequence = null,
    } = {}) {
        const sequence = sourceSequence === null
            ? Number(sourceSequenceByGeneration.get(generation) || 0)
            : Number(sourceSequence);
        sourceSequenceByGeneration.set(generation, sequence + 1);
        worklets[0].port.emit({ type: 'audio', generation, sequence, samples });
        return sequence;
    }

    return {
        contexts,
        advance,
        activities,
        activatedPcm,
        detections,
        diagnostics,
        errors,
        gate,
        order,
        rawStream,
        rawTrack,
        emitPcm,
        readiness,
        releaseRejections,
        timers,
        workers,
        worklets,
        FakeAudioContext,
        FakeAudioWorkletNode,
        FakeMediaStream,
        preparedAudioContext,
    };
}

function workerReadyMessage(generation) {
    return {
        type: 'ready',
        generation,
        modelReady: true,
        warmDecodeReady: true,
        recognitionStreamReady: true,
    };
}

function completeReadinessBarrier(harness, generation = harness.gate.currentGeneration()) {
    const worker = harness.workers[0];
    const worklet = harness.worklets[0];
    worklet.port.emit({ type: 'processor_ready', generation });
    worker.emit(workerReadyMessage(generation));
    harness.emitPcm({ generation });
    const audioMessage = worker.messages.filter(({ message }) => message.type === 'audio').at(-1)?.message;
    assert.ok(audioMessage);
    worker.emit({ type: 'ack', generation, sequence: audioMessage.sequence, accepted: true });

    return audioMessage;
}

function wakeMessage(harness, generation = harness.gate.currentGeneration(), overrides = {}) {
    const latestAudio = harness.workers[0].messages
        .filter(({ message }) => message.type === 'audio' && message.generation === generation)
        .at(-1)?.message;
    const sourceSequence = Number(overrides.sourceSequence ?? latestAudio?.sourceSequence ?? 0);
    return {
        type: 'wake_confirmed',
        generation,
        keyword: 'HEY_BEAN',
        variant: 'HEY BEAN',
        activation: 'strict_wake',
        sourceSequence,
        releaseBoundary: {
            sourceSequence,
            sampleOffset: 0,
            policy: 'post_address_tail',
        },
        ...overrides,
    };
}

test('start exposes only a closed local PCM analysis sink through a same-origin graph', async () => {
    const harness = createHarness();
    const result = await harness.gate.start(harness.rawStream);
    const context = harness.contexts[0];
    const worklet = harness.worklets[0];
    const worker = harness.workers[0];

    assert.deepEqual(result, { sampleRate: LOCAL_WAKE_PCM_SAMPLE_RATE });
    assert.equal(context.source.stream, harness.rawStream);
    assert.deepEqual(context.source.connections, [worklet]);
    assert.deepEqual(worklet.connections, [context.destination]);
    assert.equal(worklet.options.processorOptions.captureActive, false);
    assert.deepEqual(worklet.port.messages[0].message, {
        type: 'close',
        generation: harness.gate.currentGeneration(),
    });
    assert.equal(worker.url, `${LOCAL_WAKE_WORKER_URL}&generation=${harness.gate.currentGeneration()}`);
    assert.deepEqual(worker.options, { name: 'heybean-local-wake' });
    assert.ok(harness.order.includes(`module:${LOCAL_WAKE_GATE_PROCESSOR_URL}`));
    assert.equal(harness.gate.isOpen(), false);
});

test('[BV2-FIRST-WAKE-01:A-E] a gesture-prepared AudioContext is the gate context and is closed on teardown', async () => {
    const harness = createHarness({ precreateAudioContext: true });
    const prepared = harness.preparedAudioContext;

    assert.equal(harness.contexts.length, 1);
    await harness.gate.start(harness.rawStream);
    assert.equal(harness.contexts.length, 1, 'startup must not replace the gesture-prepared context');
    assert.equal(harness.worklets[0].context, prepared);

    await harness.gate.stop();
    assert.equal(prepared.closed, true);
    assert.equal(harness.rawTrack.stopped, true);
});

test('only a fully ready current-generation wake confirmation opens and reset rejects stale events', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const worker = harness.workers[0];
    const worklet = harness.worklets[0];
    const firstGeneration = harness.gate.currentGeneration();

    worker.emit({ type: 'wake_confirmed', generation: firstGeneration, keyword: 'HEY_BEAN' });
    worker.emit(workerReadyMessage(firstGeneration - 1));
    worker.emit({ type: 'wake_confirmed', generation: firstGeneration - 1, keyword: 'HEY_BEAN' });
    assert.equal(harness.gate.isOpen(), false);

    completeReadinessBarrier(harness, firstGeneration);
    worker.emit(wakeMessage(harness, firstGeneration));
    worker.emit({ type: 'wake_confirmed', generation: firstGeneration, keyword: 'HEY_BEAN' });
    assert.equal(harness.gate.isOpen(), true);
    assert.equal(worklet.port.messages.at(-1).message.type, 'activate');
    assert.equal(harness.detections.length, 1);
    assert.equal(harness.detections[0].activation, 'strict_wake');
    assert.equal(harness.activatedPcm.length, 1);
    assert.equal(harness.activatedPcm[0].released, true);
    assert.ok(harness.order.indexOf('detected') < harness.order.indexOf('gate:activate'));

    const secondGeneration = harness.gate.resetAfterTurn();
    assert.ok(secondGeneration > firstGeneration);
    assert.equal(harness.gate.isOpen(), false);
    assert.deepEqual(worker.messages.at(-1).message, {
        type: 'reset',
        generation: secondGeneration,
        reason: 'turn_reset',
    });

    worker.emit(workerReadyMessage(firstGeneration));
    worker.emit({ type: 'wake_confirmed', generation: firstGeneration, keyword: 'HEY_BEAN' });
    worker.emit({ type: 'wake_confirmed', generation: secondGeneration, keyword: 'HEY_BEAN' });
    assert.equal(harness.gate.isOpen(), false);

    completeReadinessBarrier(harness, secondGeneration);
    worker.emit(wakeMessage(harness, secondGeneration));
    assert.equal(harness.gate.isOpen(), true);
    assert.equal(worklet.port.messages.at(-1).message.type, 'activate');
    assert.deepEqual(harness.detections.map(({ generation }) => generation), [
        firstGeneration,
        secondGeneration,
    ]);
});

test('startup readiness waits for worklet, model, local PCM sink, and live decode barriers', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const worker = harness.workers[0];
    const worklet = harness.worklets[0];
    const generation = harness.gate.currentGeneration();

    worklet.port.emit({ type: 'processor_ready', generation });
    assert.equal(harness.gate.isReady(), false);

    worker.emit(workerReadyMessage(generation));
    assert.equal(harness.gate.isReady(), false);

    harness.emitPcm({ generation });
    const audioMessage = worker.messages.find(({ message }) => message.type === 'audio')?.message;
    assert.ok(audioMessage);
    assert.equal(harness.gate.isReady(), false);

    worker.emit({ type: 'ack', generation, sequence: audioMessage.sequence, accepted: true });
    assert.equal(harness.gate.isReady(), true);
    assert.equal(harness.gate.state, 'armed');
    assert.equal(harness.readiness.length, 1);
    assert.deepEqual(harness.readiness[0], {
        type: 'ready',
        generation,
        barriers: {
            worklet: true,
            model: true,
            warmDecode: true,
            recognitionStream: true,
            localPcmCapture: true,
            liveAudioDecode: true,
        },
    });
});

test('[BV2-FIRST-WAKE-01:A-E][BV2-WAKE-01] consumer admission waits for one clean current-generation live decode', async () => {
    const harness = createHarness({ consumerReady: false });
    await harness.gate.start(harness.rawStream);
    const startupGeneration = harness.gate.currentGeneration();
    const consumerGeneration = harness.gate.primeConsumerAdmission();

    assert.equal(consumerGeneration, startupGeneration + 1);
    assert.deepEqual(harness.workers[0].messages.at(-1).message, {
        type: 'reset',
        generation: consumerGeneration,
        reason: 'consumer_admission',
    });
    let settled = false;
    const readiness = harness.gate.waitForConsumerAdmissionReady({
        generation: consumerGeneration,
    }).then((event) => {
        settled = true;
        return event;
    });

    harness.worklets[0].port.emit({ type: 'processor_ready', generation: startupGeneration });
    harness.workers[0].emit(workerReadyMessage(startupGeneration));
    await Promise.resolve();
    assert.equal(settled, false, 'stale startup readiness cannot publish consumer admission');

    completeReadinessBarrier(harness, consumerGeneration);
    assert.deepEqual(await readiness, {
        type: 'consumer_admission_ready',
        generation: consumerGeneration,
        barriers: {
            worklet: true,
            model: true,
            warmDecode: true,
            recognitionStream: true,
            localPcmCapture: true,
            liveAudioDecode: true,
            consumerGeneration: true,
        },
    });
    assert.equal(harness.gate.isConsumerAdmissionReady(), true);
    assert.equal(harness.timers.size, 0);
});

test('[BV2-WAKE-01][BV2-DIAGNOSTIC-03] superseded and failed readiness barriers reject and release their timers', async () => {
    const harness = createHarness({ consumerReady: false });
    await harness.gate.start(harness.rawStream);
    const firstGeneration = harness.gate.primeConsumerAdmission();
    const staleBarrier = assert.rejects(
        harness.gate.waitForConsumerAdmissionReady({ generation: firstGeneration }),
        (error) => error?.code === 'consumer_admission_generation_superseded',
    );

    const secondGeneration = harness.gate.resetAfterTurn();
    await staleBarrier;
    assert.equal(secondGeneration, firstGeneration + 1);
    assert.equal(harness.timers.size, 0);

    const failedBarrier = assert.rejects(
        harness.gate.waitForConsumerAdmissionReady({ generation: secondGeneration }),
        (error) => error?.code === 'initialization_failed',
    );
    harness.workers[0].emit({
        type: 'error',
        generation: secondGeneration,
        code: 'initialization_failed',
        message: 'wake model unavailable',
    });
    await failedBarrier;
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(harness.gate.state, 'failed');
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.rawTrack.stopped, true);
    assert.equal(harness.workers[0].terminated, true);
    assert.equal(harness.timers.size, 0);
});

test('[BV2-WAKE-01][BV2-DIAGNOSTIC-03] consumer admission timeout fails closed and tears down cold-start capture', async () => {
    const harness = createHarness({
        consumerReady: false,
        consumerReadyTimeoutMs: 1000,
    });
    await harness.gate.start(harness.rawStream);
    const generation = harness.gate.primeConsumerAdmission();
    const timedOut = assert.rejects(
        harness.gate.waitForConsumerAdmissionReady({ generation }),
        (error) => error?.code === 'consumer_admission_timeout',
    );

    harness.advance(999);
    assert.equal(harness.gate.state, 'listening');
    harness.advance(1);
    await timedOut;
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(harness.gate.state, 'failed');
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.rawTrack.stopped, true);
    assert.equal(harness.workers[0].terminated, true);
    assert.equal(harness.errors.at(-1)?.code, 'consumer_admission_timeout');
    assert.equal(harness.timers.size, 0);
});

test('[BV2-WAKE-04] wake audio spoken during local model warm-up is retained and decoded in order', async () => {
    const harness = createHarness({ maxInFlightPcm: 2, maxBufferedPcm: 4 });
    await harness.gate.start(harness.rawStream);
    const worker = harness.workers[0];
    const worklet = harness.worklets[0];
    const generation = harness.gate.currentGeneration();
    const startupAudio = [1, 2, 3].map(() => new Float32Array(4));

    worklet.port.emit({ type: 'processor_ready', generation });
    startupAudio.forEach((samples) => harness.emitPcm({ generation, samples }));

    assert.equal(worker.messages.some(({ message }) => message.type === 'audio'), false);
    assert.equal(harness.gate.pendingPcmChunks(), 0);
    assert.equal(harness.gate.bufferedPcmChunks(), 3);

    worker.emit(workerReadyMessage(generation));
    let audioMessages = worker.messages.filter(({ message }) => message.type === 'audio');
    assert.equal(audioMessages.length, 2);
    assert.deepEqual(audioMessages.map(({ message }) => message.sequence), [1, 2]);
    assert.equal(harness.gate.pendingPcmChunks(), 2);
    assert.equal(harness.gate.bufferedPcmChunks(), 1);

    worker.emit({ type: 'ack', generation, sequence: 1, accepted: true });
    audioMessages = worker.messages.filter(({ message }) => message.type === 'audio');
    assert.equal(audioMessages.length, 3);
    assert.deepEqual(audioMessages.map(({ message }) => message.sequence), [1, 2, 3]);
    assert.equal(harness.gate.bufferedPcmChunks(), 0);
    assert.equal(harness.gate.isReady(), true);
});

test('the first ordered wake confirmation is admitted immediately after its live-decode acknowledgement', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const worker = harness.workers[0];
    const worklet = harness.worklets[0];
    const generation = harness.gate.currentGeneration();

    worklet.port.emit({ type: 'processor_ready', generation });
    worker.emit(workerReadyMessage(generation));
    harness.emitPcm({ generation });
    const audioMessage = worker.messages.filter(({ message }) => message.type === 'audio').at(-1).message;
    assert.equal(harness.gate.isReady(), false);
    assert.equal(harness.gate.isOpen(), false);

    // The packaged worker deliberately posts this acknowledgement before the
    // wake decision for the same PCM sequence.
    worker.emit({ type: 'ack', generation, sequence: audioMessage.sequence, accepted: true });
    assert.equal(harness.gate.isReady(), true);
    assert.equal(harness.gate.isOpen(), false);
    worker.emit(wakeMessage(harness, generation));
    assert.equal(harness.gate.isOpen(), true);
    assert.equal(harness.readiness.length, 1);
    assert.equal(harness.detections.length, 1);
    assert.ok(harness.order.indexOf('ready') < harness.order.indexOf('detected'));
    assert.ok(harness.order.indexOf('detected') < harness.order.indexOf('gate:activate'));
});

test('[BV2-FIRST-WAKE-01:C] proposal and classifier diagnostics stay private; only the durable confirmation opens once', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const worker = harness.workers[0];
    const worklet = harness.worklets[0];
    const firstGeneration = harness.gate.currentGeneration();
    completeReadinessBarrier(harness, firstGeneration);

    assert.equal(harness.activatedPcm.length, 0);
    worker.emit({
        type: 'wake_proposal',
        generation: firstGeneration,
        proposalType: 'strict',
        timestampCount: 5,
        requiredTailSamples: 2_560,
        samples: new Float32Array([0.1]),
        transcript: 'must remain private',
    });
    worker.emit({
        type: 'classification_decision',
        generation: firstGeneration,
        proposalType: 'strict',
        accepted: true,
        winningClass: 'strict_wake',
        probability: 0.998,
        threshold: 0.95,
        sampleCount: 21_760,
        tailSamples: 2_560,
        audio: new Float32Array([0.1]),
    });
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.detections.length, 0);
    worker.emit(wakeMessage(harness, firstGeneration, {
        variant: 'HEY BEAN',
        activation: 'strict_wake',
        releaseBoundary: {
            sourceSequence: 0,
            sampleOffset: 0,
            policy: 'post_address_tail',
        },
    }));
    worker.emit(wakeMessage(harness, firstGeneration));

    assert.deepEqual(harness.diagnostics, [
        {
            type: 'wake_proposal',
            generation: firstGeneration,
            proposalType: 'strict',
            timestampCount: 5,
            requiredTailSamples: 2_560,
        },
        {
            type: 'classification_decision',
            generation: firstGeneration,
            accepted: true,
            proposalType: 'strict',
            winningClass: 'strict_wake',
            probability: 0.998,
            threshold: 0.95,
            sampleCount: 21_760,
            tailSamples: 2_560,
        },
    ]);
    for (const diagnostic of harness.diagnostics) {
        assert.equal(Object.hasOwn(diagnostic, 'samples'), false);
        assert.equal(Object.hasOwn(diagnostic, 'audio'), false);
        assert.equal(Object.hasOwn(diagnostic, 'transcript'), false);
    }
    assert.equal(harness.detections.length, 1);
    assert.equal(harness.detections[0].activation, 'strict_wake');
    assert.equal(harness.detections[0].releaseBoundary.policy, 'post_address_tail');
    assert.equal(harness.activatedPcm.length, 1);
    assert.equal(harness.activatedPcm[0].released, true);
    assert.equal(
        worklet.port.messages.filter(({ message }) => message.type === 'activate').length,
        1,
    );

    const secondGeneration = harness.gate.resetAfterTurn();
    completeReadinessBarrier(harness, secondGeneration);
    worker.emit(wakeMessage(harness, secondGeneration));

    assert.deepEqual(harness.detections.map(({ generation }) => generation), [
        firstGeneration,
        secondGeneration,
    ]);
    assert.equal(
        worklet.port.messages.filter(({ message }) => message.type === 'activate').length,
        2,
    );
});

test('[BV2-FIRST-WAKE-01:E] a no-decision discard is observable, erases its generation, and cannot hide the next wake', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const worker = harness.workers[0];
    const firstGeneration = harness.gate.currentGeneration();
    completeReadinessBarrier(harness, firstGeneration);

    assert.equal(harness.activatedPcm.length, 0);
    worker.emit({
        type: 'dormant_discard',
        generation: firstGeneration,
        reason: 'no_accepted_wake',
        proposalSeen: false,
        classificationDecisionSeen: false,
    });

    const secondGeneration = harness.gate.currentGeneration();
    assert.equal(secondGeneration, firstGeneration + 1);
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.activatedPcm.length, 0);
    assert.deepEqual(harness.diagnostics, [{
        type: 'wake_candidate_discarded',
        generation: firstGeneration,
        reason: 'no_accepted_wake',
        proposalSeen: false,
        classificationDecisionSeen: false,
    }]);
    assert.doesNotMatch(JSON.stringify(harness.diagnostics), /samples|audio|transcript/i);
    assert.deepEqual(worker.messages.at(-1).message, {
        type: 'reset',
        generation: secondGeneration,
        reason: 'dormant_discard',
    });

    completeReadinessBarrier(harness, secondGeneration);
    worker.emit(wakeMessage(harness, secondGeneration));
    assert.equal(harness.detections.length, 1);
    assert.equal(harness.detections[0].generation, secondGeneration);
    assert.equal(harness.activatedPcm.length, 1);
});

test('[BV2-PRIVACY-PCM-03] strict wake releases only its declared post-address tail then streams live PCM', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const generation = harness.gate.currentGeneration();
    const worker = harness.workers[0];
    completeReadinessBarrier(harness, generation);

    harness.emitPcm({ generation, samples: new Float32Array(1600).fill(0.1) });
    harness.emitPcm({ generation, samples: new Float32Array(1600).fill(0.2) });
    const pendingBridges = worker.messages
        .filter(({ message }) => message.type === 'audio' && message.generation === generation)
        .slice(-2)
        .map(({ message }) => message);
    pendingBridges.forEach(({ sequence }) => {
        worker.emit({ type: 'ack', generation, sequence, accepted: true });
    });
    worker.emit(wakeMessage(harness, generation, {
        sourceSequence: 2,
        releaseBoundary: {
            sourceSequence: 1,
            sampleOffset: 800,
            policy: 'post_address_tail',
        },
    }));

    assert.deepEqual(harness.activatedPcm.map((event) => ({
        sourceSequence: event.sourceSequence,
        samples: event.samples.length,
        released: event.released,
        first: event.samples[0],
    })), [
        { sourceSequence: 1, samples: 800, released: true, first: 0.10000000149011612 },
        { sourceSequence: 2, samples: 1600, released: true, first: 0.20000000298023224 },
    ]);

    harness.emitPcm({ generation, samples: new Float32Array(1600).fill(0.3) });
    assert.equal(harness.activatedPcm.at(-1).sourceSequence, 3);
    assert.equal(harness.activatedPcm.at(-1).released, false);
    assert.equal(harness.activatedPcm.at(-1).samples.length, 1600);
});

test('[BV2-PCM-FAIL-03] activated transport failure closes and tears down microphone capture', async () => {
    const harness = createHarness();
    harness.gate.onActivatedPcm = () => {
        throw new Error('data channel backpressure');
    };
    await harness.gate.start(harness.rawStream);
    const generation = harness.gate.currentGeneration();
    completeReadinessBarrier(harness, generation);
    harness.workers[0].emit(wakeMessage(harness, generation));
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(harness.gate.state, 'failed');
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.rawTrack.stopped, true);
    assert.equal(harness.errors.length, 1);
    assert.equal(harness.errors[0].code, 'gate_open_failed');
    assert.match(String(harness.errors[0].cause?.message || ''), /data channel backpressure/);
});

test('[BV2-WAKE-03] startup speech is never admitted and the first post-readiness wake opens immediately', async () => {
    const detections = [];
    const harness = createHarness({
        consumerReady: false,
        onDetected: (event) => detections.push(event),
    });
    await harness.gate.start(harness.rawStream);
    completeReadinessBarrier(harness);
    const startupGeneration = harness.gate.currentGeneration();

    harness.workers[0].emit({
        type: 'wake_confirmed',
        generation: startupGeneration,
        keyword: 'HEY BEAN',
        variant: 'HEY BEAN',
        activation: 'strict_wake',
    });

    assert.equal(detections.length, 0);
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.gate.currentGeneration(), startupGeneration + 1);
    assert.equal(harness.gate.state, 'listening');
    assert.equal(harness.worklets[0].port.messages.at(-1).message.type, 'close');
    assert.deepEqual(harness.workers[0].messages.at(-1).message, {
        type: 'reset',
        generation: startupGeneration + 1,
        reason: 'consumer_not_ready',
    });

    completeReadinessBarrier(harness, startupGeneration + 1);
    harness.gate.setConsumerReady(true);

    // A delayed worker event from startup cannot cross the generation barrier.
    harness.workers[0].emit({
        type: 'wake_confirmed',
        generation: startupGeneration,
        keyword: 'HEY BEAN',
        variant: 'HEY BEAN',
        activation: 'strict_wake',
    });
    assert.equal(detections.length, 0);
    assert.equal(harness.gate.isOpen(), false);

    // A same-generation decision whose PCM was acknowledged before the
    // consumer boundary is also rejected if delivery was queued until later.
    harness.workers[0].emit({
        type: 'wake_confirmed',
        generation: startupGeneration + 1,
        keyword: 'HEY BEAN',
        variant: 'HEY BEAN',
        activation: 'strict_wake',
    });
    assert.equal(detections.length, 0);
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.gate.currentGeneration(), startupGeneration + 2);

    completeReadinessBarrier(harness, startupGeneration + 2);

    // The first wake spoken after both sides are ready is admitted once and
    // opens the provider track without waiting for another reset or retry.
    harness.workers[0].emit(wakeMessage(harness, startupGeneration + 2));
    assert.equal(detections.length, 1);
    assert.equal(harness.gate.isOpen(), true);
    assert.equal(harness.worklets[0].port.messages.at(-1).message.type, 'activate');
    assert.equal(
        harness.worklets[0].port.messages.filter(({ message }) => message.type === 'activate').length,
        1,
    );
});

test('[BV2-WAKE-09] published consumer readiness requires a post-enable live-decode acknowledgement', async () => {
    const harness = createHarness({ consumerReady: false });
    await harness.gate.start(harness.rawStream);
    const generation = harness.gate.currentGeneration();
    completeReadinessBarrier(harness, generation);

    assert.equal(harness.gate.isReady(), true);
    assert.equal(harness.gate.isConsumerAdmissionReady(), false);
    harness.gate.setConsumerReady(true);
    assert.equal(harness.gate.isConsumerAdmissionReady(), false);

    harness.emitPcm({ generation });
    const postEnableAudio = harness.workers[0].messages
        .filter(({ message }) => message.type === 'audio' && message.generation === generation)
        .at(-1).message;
    harness.workers[0].emit({
        type: 'ack',
        generation,
        sequence: postEnableAudio.sequence,
        accepted: true,
    });

    assert.equal(harness.gate.isConsumerAdmissionReady(), true);
    harness.workers[0].emit(wakeMessage(harness, generation));
    assert.equal(harness.detections.length, 1);
    assert.equal(harness.gate.isOpen(), true);
});

test('[BV2-WAKE-10] startup primes a clean consumer-enabled generation before the first user wake', async () => {
    const harness = createHarness({ consumerReady: false });
    await harness.gate.start(harness.rawStream);
    completeReadinessBarrier(harness);
    const disabledGeneration = harness.gate.currentGeneration();

    harness.gate.setConsumerReady(true);
    const primedGeneration = harness.gate.resetAfterTurn();
    assert.ok(primedGeneration > disabledGeneration);
    assert.equal(harness.gate.isConsumerAdmissionReady(), false);

    completeReadinessBarrier(harness, primedGeneration);
    assert.equal(harness.gate.isConsumerAdmissionReady(), true);
    harness.workers[0].emit(wakeMessage(harness, primedGeneration));

    assert.equal(harness.detections.length, 1);
    assert.equal(harness.detections[0].generation, primedGeneration);
    assert.equal(harness.gate.isOpen(), true);
    assert.equal(
        harness.workers[0].messages.filter(({ message }) => message.reason === 'consumer_not_ready').length,
        0,
    );
});

test('[BV2-MISSED-HEY-03] address proposal and shared-classifier decision stay private until one compatible confirmation', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const generation = harness.gate.currentGeneration();
    const worker = harness.workers[0];
    completeReadinessBarrier(harness, generation);

    worker.emit({
        type: 'wake_proposal',
        generation,
        proposalType: 'address',
        timestampCount: 2,
        requiredTailSamples: 2_560,
    });
    worker.emit({
        type: 'classification_decision',
        generation,
        proposalType: 'address',
        accepted: true,
        winningClass: 'missed_hey_confirmation',
        probability: 0.998,
        threshold: 0.95,
        sampleCount: 21_760,
        tailSamples: 2_560,
    });
    assert.equal(harness.gate.state, 'armed');
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.detections.length, 0);
    assert.equal(harness.order.includes('gate:activate'), false);
    worker.emit(wakeMessage(harness, generation, {
        variant: 'BEAN',
        activation: 'missed_hey_confirmation',
        releaseBoundary: {
            sourceSequence: 0,
            sampleOffset: 0,
            policy: 'utterance_onset',
        },
    }));

    assert.equal(harness.gate.isOpen(), true);
    assert.equal(harness.worklets[0].port.messages.at(-1).message.type, 'activate');
    assert.equal(harness.detections.length, 1);
    assert.equal(harness.detections[0].activation, 'missed_hey_confirmation');
    assert.equal(harness.detections[0].releaseBoundary.policy, 'utterance_onset');
});

test('[BV2-MISSED-HEY-04] classifier rejection discards the generation and a stale confirmation cannot reopen it', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const firstGeneration = harness.gate.currentGeneration();
    const worker = harness.workers[0];
    completeReadinessBarrier(harness, firstGeneration);

    worker.emit({
        type: 'wake_proposal',
        generation: firstGeneration,
        proposalType: 'address',
        timestampCount: 2,
        requiredTailSamples: 2_560,
    });
    worker.emit({
        type: 'classification_decision',
        generation: firstGeneration,
        proposalType: 'address',
        accepted: false,
        winningClass: 'reject',
        probability: 0.001,
        threshold: 0.95,
        sampleCount: 21_760,
        tailSamples: 2_560,
    });
    assert.equal(harness.gate.currentGeneration(), firstGeneration);
    assert.equal(harness.gate.isOpen(), false);
    worker.emit({
        type: 'dormant_discard',
        generation: firstGeneration,
        reason: 'classifier_rejected',
        proposalSeen: true,
        classificationDecisionSeen: true,
    });

    const secondGeneration = harness.gate.currentGeneration();
    assert.equal(secondGeneration, firstGeneration + 1);
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.gate.isReady(), false);
    assert.equal(harness.gate.state, 'listening');
    assert.equal(harness.detections.length, 0);
    assert.deepEqual(worker.messages.at(-1).message, {
        type: 'reset',
        generation: secondGeneration,
        reason: 'dormant_discard',
    });

    worker.emit(wakeMessage(harness, firstGeneration, {
        variant: 'BEAN',
        activation: 'missed_hey_confirmation',
        releaseBoundary: {
            sourceSequence: 0,
            sampleOffset: 0,
            policy: 'utterance_onset',
        },
    }));
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.detections.length, 0);
});

test('[BV2-PRIVACY-BOUNDARY-04] malformed or class-incompatible confirmations fail closed', async () => {
    const incompatible = [
        { variant: 'BEAN' },
        {
            activation: 'missed_hey_confirmation',
            variant: 'HEY BEAN',
            releaseBoundary: { sourceSequence: 0, sampleOffset: 0, policy: 'utterance_onset' },
        },
        { releaseBoundary: { sourceSequence: 0, sampleOffset: 0, policy: 'utterance_onset' } },
        { releaseBoundary: null },
    ];
    for (const overrides of incompatible) {
        const harness = createHarness();
        await harness.gate.start(harness.rawStream);
        const generation = harness.gate.currentGeneration();
        completeReadinessBarrier(harness, generation);
        harness.workers[0].emit(wakeMessage(harness, generation, overrides));

        assert.equal(harness.gate.state, 'failed');
        assert.equal(harness.gate.isOpen(), false);
        assert.equal(harness.detections.length, 0);
        assert.equal(harness.errors.length, 1);
        assert.equal(harness.errors[0].code, 'gate_open_failed');
    }
});

test('incomplete worker readiness is terminal and fail-closed', async () => {
    const harness = createHarness();
    const result = await harness.gate.start(harness.rawStream);
    const generation = harness.gate.currentGeneration();
    harness.worklets[0].port.emit({ type: 'processor_ready', generation });
    harness.workers[0].emit({
        type: 'ready',
        generation,
        modelReady: true,
        warmDecodeReady: false,
        recognitionStreamReady: true,
    });
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(harness.gate.state, 'failed');
    assert.equal(harness.gate.isReady(), false);
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.rawTrack.stopped, true);
    assert.equal(result.sampleRate, LOCAL_WAKE_PCM_SAMPLE_RATE);
    assert.equal(harness.errors[0]?.code, 'incomplete_readiness_barrier');
});

test('[BV2-DIAGNOSTIC-03] worker failure codes survive the fail-closed local gate boundary', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const generation = harness.gate.currentGeneration();
    completeReadinessBarrier(harness, generation);
    harness.workers[0].emit({
        type: 'error',
        generation,
        code: 'decode_failed',
        message: 'The keyword decoder exceeded its work limit.',
    });
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(harness.gate.state, 'failed');
    assert.equal(harness.rawTrack.stopped, true);
    assert.equal(harness.errors[0].code, 'decode_failed');
    assert.match(harness.errors[0].message, /decoder exceeded/);
});

test('[BV2-WAKE-01][BV2-DIAGNOSTIC-03][BV2-PRIVACY-PCM-03] ordered fatal acknowledgement retains the detailed worker failure exactly once', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const generation = harness.gate.currentGeneration();
    completeReadinessBarrier(harness, generation);
    harness.emitPcm({ generation, samples: new Float32Array(1_280).fill(0.08) });
    const worker = harness.workers[0];
    const sequence = worker.messages
        .filter(({ message }) => message.type === 'audio' && message.generation === generation)
        .at(-1).message.sequence;

    worker.emit({
        type: 'ack',
        generation,
        sequence,
        accepted: false,
        reason: 'decode_failed',
    });

    assert.equal(harness.gate.state, 'failure_pending');
    assert.equal(harness.gate.isReady(), false);
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.gate.pendingPcmChunks(), 0);
    assert.equal(harness.activatedPcm.length, 0);
    assert.equal(harness.errors.length, 0, 'the ordered detail must remain eligible');
    assert.equal(harness.rawTrack.stopped, true, 'fatal acknowledgement stops capture immediately');
    assert.equal(harness.contexts[0].source.disconnected, true);
    assert.equal(harness.worklets[0].disconnected, true);
    assert.equal(worker.terminated, false, 'the worker stays alive only for its posted fatal detail');

    worker.emit({
        type: 'error',
        generation,
        code: 'decode_failed',
        message: 'The keyword decoder exceeded its work limit.\nBearer secret-token',
        fatal: true,
    });
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(harness.gate.state, 'failed');
    assert.equal(harness.errors.length, 1);
    assert.equal(harness.errors[0].code, 'decode_failed');
    assert.equal(
        harness.errors[0].message,
        'The keyword decoder exceeded its work limit. Bearer [redacted]',
    );
    assert.equal(harness.errors[0].cause?.code, 'pcm_decode_rejected');
    assert.equal(worker.terminated, true);
    assert.equal(harness.timers.size, 0);
    worker.emit({
        type: 'error',
        generation,
        code: 'decode_failed',
        message: 'duplicate',
        fatal: true,
    });
    harness.advance(LOCAL_WAKE_WORKER_FAILURE_DETAIL_TIMEOUT_MS * 2);
    assert.equal(harness.errors.length, 1, 'late details and stale timers cannot duplicate failure');
    for (const forbidden of ['pcm', 'samples', 'transcript', 'text']) {
        assert.equal(forbidden in harness.errors[0], false, `${forbidden} must not enter diagnostics`);
    }
});

test('[BV2-WAKE-01][BV2-DIAGNOSTIC-03][BV2-PRIVACY-PCM-03] missing fatal detail times out to one sanitized fail-closed teardown', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const generation = harness.gate.currentGeneration();
    completeReadinessBarrier(harness, generation);
    harness.emitPcm({ generation, samples: new Float32Array(1_280).fill(0.08) });
    const worker = harness.workers[0];
    const sequence = worker.messages
        .filter(({ message }) => message.type === 'audio' && message.generation === generation)
        .at(-1).message.sequence;
    worker.emit({
        type: 'ack',
        generation,
        sequence,
        accepted: false,
        reason: 'decode_failed',
    });

    harness.advance(LOCAL_WAKE_WORKER_FAILURE_DETAIL_TIMEOUT_MS - 1);
    assert.equal(harness.errors.length, 0);
    assert.equal(harness.gate.state, 'failure_pending');
    assert.equal(harness.activatedPcm.length, 0);

    harness.advance(1);
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(harness.errors.length, 1);
    assert.equal(harness.errors[0].code, 'decode_failed');
    assert.equal(
        harness.errors[0].message,
        'The local wake detector failed while decoding microphone audio.',
    );
    assert.equal(harness.errors[0].cause?.code, 'pcm_decode_rejected');
    assert.equal(harness.gate.state, 'failed');
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.gate.isReady(), false);
    assert.equal(harness.rawTrack.stopped, true);
    assert.equal(worker.terminated, true);
    assert.equal(harness.timers.size, 0);
    assert.equal(harness.activatedPcm.length, 0);

    worker.emit({
        type: 'error',
        generation,
        code: 'decode_failed',
        message: 'late detail',
        fatal: true,
    });
    assert.equal(harness.errors.length, 1);
});

test('[BV2-WAKE-01][BV2-PRIVACY-PCM-03][BV2-DIAGNOSTIC-03] live post-readiness PCM without a worker acknowledgement fails closed within the bounded deadline', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const generation = harness.gate.currentGeneration();
    completeReadinessBarrier(harness, generation);

    harness.emitPcm({ generation, samples: new Float32Array(1_280).fill(0.08) });
    assert.equal(harness.gate.pendingPcmChunks(), 1);
    harness.advance(LOCAL_WAKE_PCM_ACK_TIMEOUT_MS - 1);
    harness.emitPcm({ generation, samples: new Float32Array(1_280).fill(0.09) });
    assert.equal(harness.errors.length, 0);
    assert.equal(harness.gate.isOpen(), false);

    harness.advance(1);
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(harness.errors.length, 1);
    assert.equal(harness.errors[0].code, 'pcm_ack_timeout');
    assert.equal(harness.gate.state, 'failed');
    assert.equal(harness.gate.isReady(), false);
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.activatedPcm.length, 0, 'unconfirmed PCM must never reach transcription');
    assert.equal(harness.rawTrack.stopped, true);
    assert.equal(harness.workers[0].terminated, true);
    assert.equal(harness.timers.size, 0);
    for (const forbidden of ['pcm', 'samples', 'transcript', 'text']) {
        assert.equal(forbidden in harness.errors[0], false, `${forbidden} must not enter diagnostics`);
    }
});

test('[BV2-WAKE-01][BV2-DIAGNOSTIC-03] the watchdog keeps the oldest send deadline when a FIFO acknowledgement advances the queue', async () => {
    const harness = createHarness({ maxInFlightPcm: 2 });
    await harness.gate.start(harness.rawStream);
    const generation = harness.gate.currentGeneration();
    completeReadinessBarrier(harness, generation);
    harness.emitPcm({ generation });
    harness.emitPcm({ generation });
    const pending = harness.workers[0].messages
        .filter(({ message }) => message.type === 'audio' && message.generation === generation)
        .slice(-2)
        .map(({ message }) => message);

    harness.advance(1_000);
    harness.workers[0].emit({
        type: 'ack',
        generation,
        sequence: pending[0].sequence,
        accepted: true,
    });
    harness.advance(999);
    assert.equal(harness.errors.length, 0);

    harness.advance(1);
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(harness.errors.length, 1);
    assert.equal(harness.errors[0].code, 'pcm_ack_timeout');
    assert.equal(harness.gate.state, 'failed');
});

test('[BV2-WAKE-01][BV2-DIAGNOSTIC-03] out-of-order or unexplained current-generation acknowledgements fail closed', async () => {
    const outOfOrder = createHarness({ maxInFlightPcm: 2 });
    await outOfOrder.gate.start(outOfOrder.rawStream);
    const outOfOrderGeneration = outOfOrder.gate.currentGeneration();
    completeReadinessBarrier(outOfOrder, outOfOrderGeneration);
    outOfOrder.emitPcm({ generation: outOfOrderGeneration });
    outOfOrder.emitPcm({ generation: outOfOrderGeneration });
    const pending = outOfOrder.workers[0].messages
        .filter(({ message }) => message.type === 'audio'
            && message.generation === outOfOrderGeneration)
        .slice(-2)
        .map(({ message }) => message);
    outOfOrder.workers[0].emit({
        type: 'ack',
        generation: outOfOrderGeneration,
        sequence: pending[1].sequence,
        accepted: true,
    });
    assert.equal(outOfOrder.errors[0]?.code, 'invalid_pcm_ack_sequence');
    assert.equal(outOfOrder.gate.state, 'failed');
    assert.equal(outOfOrder.activatedPcm.length, 0);

    const rejected = createHarness();
    await rejected.gate.start(rejected.rawStream);
    const rejectedGeneration = rejected.gate.currentGeneration();
    completeReadinessBarrier(rejected, rejectedGeneration);
    rejected.emitPcm({ generation: rejectedGeneration });
    const rejectedSequence = rejected.workers[0].messages
        .filter(({ message }) => message.type === 'audio'
            && message.generation === rejectedGeneration)
        .at(-1).message.sequence;
    rejected.workers[0].emit({
        type: 'ack',
        generation: rejectedGeneration,
        sequence: rejectedSequence,
        accepted: false,
        reason: 'not_ready',
    });
    assert.equal(rejected.errors[0]?.code, 'pcm_decode_rejected');
    assert.equal(rejected.gate.state, 'failed');
    assert.equal(rejected.activatedPcm.length, 0);

    const unexplainedActivation = createHarness();
    await unexplainedActivation.gate.start(unexplainedActivation.rawStream);
    const unexplainedGeneration = unexplainedActivation.gate.currentGeneration();
    completeReadinessBarrier(unexplainedActivation, unexplainedGeneration);
    unexplainedActivation.emitPcm({ generation: unexplainedGeneration });
    const unexplainedSequence = unexplainedActivation.workers[0].messages
        .filter(({ message }) => message.type === 'audio'
            && message.generation === unexplainedGeneration)
        .at(-1).message.sequence;
    unexplainedActivation.workers[0].emit({
        type: 'ack',
        generation: unexplainedGeneration,
        sequence: unexplainedSequence,
        accepted: false,
        reason: 'activation_pending',
    });
    assert.equal(unexplainedActivation.errors[0]?.code, 'pcm_decode_rejected');
    assert.equal(unexplainedActivation.errors.length, 1);
    assert.equal(unexplainedActivation.gate.state, 'failed');
    assert.equal(unexplainedActivation.activatedPcm.length, 0);
    assert.equal(unexplainedActivation.rawTrack.stopped, true);
    assert.equal(unexplainedActivation.workers[0].terminated, true);
});

test('[BV2-WAKE-01] post-confirmation activation-pending acknowledgements drain safely without weakening readiness', async () => {
    const harness = createHarness({ maxInFlightPcm: 2 });
    await harness.gate.start(harness.rawStream);
    const generation = harness.gate.currentGeneration();
    completeReadinessBarrier(harness, generation);
    harness.emitPcm({ generation });
    harness.emitPcm({ generation });
    const pending = harness.workers[0].messages
        .filter(({ message }) => message.type === 'audio' && message.generation === generation)
        .slice(-2)
        .map(({ message }) => message);
    harness.workers[0].emit({
        type: 'ack',
        generation,
        sequence: pending[0].sequence,
        accepted: true,
    });
    harness.workers[0].emit(wakeMessage(harness, generation));
    assert.equal(harness.gate.isOpen(), true);

    harness.workers[0].emit({
        type: 'ack',
        generation,
        sequence: pending[1].sequence,
        accepted: false,
        reason: 'activation_pending',
    });
    harness.advance(LOCAL_WAKE_PCM_ACK_TIMEOUT_MS * 2);

    assert.equal(harness.errors.length, 0);
    assert.equal(harness.gate.pendingPcmChunks(), 0);
    assert.equal(harness.gate.isReady(), true);
    assert.equal(harness.gate.isOpen(), true);
    await harness.gate.stop();
});

test('[BV2-WAKE-01][BV2-DIAGNOSTIC-03] reset, close, and stop invalidate stale acknowledgement deadlines across generations', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const firstGeneration = harness.gate.currentGeneration();
    completeReadinessBarrier(harness, firstGeneration);
    harness.emitPcm({ generation: firstGeneration });
    const staleResetTimer = [...harness.timers.values()][0];
    assert.ok(staleResetTimer);

    const secondGeneration = harness.gate.resetAfterTurn();
    staleResetTimer.callback();
    assert.equal(harness.errors.length, 0, 'a reset generation cannot be failed by its predecessor');
    completeReadinessBarrier(harness, secondGeneration);
    harness.emitPcm({ generation: secondGeneration });
    const secondSequence = harness.workers[0].messages
        .filter(({ message }) => message.type === 'audio'
            && message.generation === secondGeneration)
        .at(-1).message.sequence;
    harness.workers[0].emit({
        type: 'ack',
        generation: secondGeneration,
        sequence: secondSequence,
        accepted: true,
    });
    harness.advance(LOCAL_WAKE_PCM_ACK_TIMEOUT_MS * 2);
    assert.equal(harness.errors.length, 0);

    harness.emitPcm({ generation: secondGeneration });
    const staleCloseTimer = [...harness.timers.values()][0];
    assert.ok(staleCloseTimer);
    assert.equal(harness.gate.close(), true);
    staleCloseTimer.callback();
    assert.equal(harness.errors.length, 0, 'a closed gate cannot be failed by its cleared timer');

    await harness.gate.stop();
    staleCloseTimer.callback();
    harness.advance(LOCAL_WAKE_PCM_ACK_TIMEOUT_MS * 2);
    assert.equal(harness.errors.length, 0, 'teardown cannot be failed by a stale timer callback');
    assert.equal(harness.gate.state, 'stopped');
    assert.equal(harness.gate.isOpen(), false);
});

test('PCM transfer is bounded until matching worker acknowledgements release capacity', async () => {
    const harness = createHarness({ maxInFlightPcm: 2 });
    await harness.gate.start(harness.rawStream);
    const worker = harness.workers[0];
    const worklet = harness.worklets[0];
    const generation = harness.gate.currentGeneration();
    worklet.port.emit({ type: 'processor_ready', generation });
    worker.emit(workerReadyMessage(generation));

    const buffers = [new Float32Array(4), new Float32Array(4), new Float32Array(4)];
    buffers.forEach((samples) => harness.emitPcm({ generation, samples }));

    const pcmMessages = () => worker.messages.filter(({ message }) => message.type === 'audio');
    assert.equal(pcmMessages().length, 2);
    assert.equal(harness.gate.pendingPcmChunks(), 2);
    assert.equal(pcmMessages()[0].transfer[0] instanceof ArrayBuffer, true);
    assert.equal(pcmMessages()[1].transfer[0] instanceof ArrayBuffer, true);
    assert.deepEqual(pcmMessages().map(({ message }) => message.sourceSequence), [0, 1]);

    const firstSequence = pcmMessages()[0].message.sequence;
    worker.emit({ type: 'ack', generation: generation - 1, sequence: firstSequence });
    assert.equal(harness.gate.pendingPcmChunks(), 2);

    worker.emit({ type: 'ack', generation, sequence: firstSequence, accepted: true });
    assert.equal(harness.gate.pendingPcmChunks(), 2);
    assert.equal(pcmMessages().length, 3);
    assert.equal(pcmMessages()[2].message.sourceSequence, 2);
});

test('the production wake queue preserves over one second of decode backpressure', async () => {
    const harness = createHarness({ maxInFlightPcm: null });
    await harness.gate.start(harness.rawStream);
    const worker = harness.workers[0];
    const worklet = harness.worklets[0];
    const generation = harness.gate.currentGeneration();
    worklet.port.emit({ type: 'processor_ready', generation });
    worker.emit(workerReadyMessage(generation));

    for (let index = 0; index < 13; index += 1) {
        harness.emitPcm({ generation });
    }

    const pcmMessages = worker.messages.filter(({ message }) => message.type === 'audio');
    assert.equal(pcmMessages.length, 12);
    assert.equal(harness.gate.pendingPcmChunks(), 12);
});

test('current-generation microphone activity is normalized for presentation only', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const worklet = harness.worklets[0];
    const generation = harness.gate.currentGeneration();

    worklet.port.emit({ type: 'activity', generation: generation - 1, level: 0.8, rms: 0.2 });
    worklet.port.emit({ type: 'activity', generation, level: 1.7, rms: 0.18 });
    assert.deepEqual(harness.activities, [{ generation, level: 1, rms: 0.18 }]);

    harness.gate.close();
    assert.deepEqual(harness.activities.at(-1), { generation, level: 0, rms: 0 });
});

test('worker errors close first, report failure, and tear down every microphone path', async () => {
    const harness = createHarness();
    const result = await harness.gate.start(harness.rawStream);
    const generation = harness.gate.currentGeneration();
    const worker = harness.workers[0];
    const worklet = harness.worklets[0];

    completeReadinessBarrier(harness, generation);
    worker.emit(wakeMessage(harness, generation));
    worker.fail('decoder failed');
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.gate.state, 'failed');
    assert.deepEqual(
        worklet.port.messages.slice(-2).map(({ message }) => message.type),
        ['close', 'destroy'],
    );
    assert.equal(worker.terminated, true);
    assert.equal(harness.rawTrack.stopped, true);
    assert.equal(result.sampleRate, LOCAL_WAKE_PCM_SAMPLE_RATE);
    assert.equal(harness.errors.length, 1);
    assert.match(harness.errors[0].message, /decoder failed/);

    const closeIndex = harness.order.lastIndexOf('gate:close');
    assert.ok(closeIndex < harness.order.indexOf('worker:terminate'));
    assert.ok(closeIndex < harness.order.indexOf('track:raw:stop'));
});

test('stop synchronously closes before terminating graph, context, and raw microphone track', async () => {
    const harness = createHarness();
    const result = await harness.gate.start(harness.rawStream);
    const generation = harness.gate.currentGeneration();
    completeReadinessBarrier(harness, generation);
    harness.workers[0].emit(wakeMessage(harness, generation));

    const stopping = harness.gate.stop();
    assert.equal(harness.gate.isOpen(), false);
    await stopping;

    const closeIndex = harness.order.lastIndexOf('gate:close');
    const terminateIndex = harness.order.indexOf('worker:terminate');
    const contextIndex = harness.order.indexOf('context:close');
    const rawIndex = harness.order.indexOf('track:raw:stop');
    assert.ok(closeIndex < terminateIndex);
    assert.ok(terminateIndex < contextIndex);
    assert.ok(contextIndex < rawIndex);
    assert.equal(harness.rawTrack.stopped, true);
    assert.equal(result.sampleRate, LOCAL_WAKE_PCM_SAMPLE_RATE);
    assert.equal(harness.contexts[0].closed, true);
    assert.equal(harness.gate.state, 'stopped');
});

test('stop after a diagnostic-only proposal cannot reopen or rearm', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const generation = harness.gate.currentGeneration();
    completeReadinessBarrier(harness, generation);
    harness.workers[0].emit({
        type: 'wake_proposal',
        generation,
        proposalType: 'address',
        timestampCount: 2,
        requiredTailSamples: 2_560,
    });
    assert.equal(harness.gate.state, 'armed');

    await harness.gate.stop();
    const workerMessagesAfterStop = harness.workers[0].messages.length;
    harness.advance(10_000);

    assert.equal(harness.gate.state, 'stopped');
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.gate.isReady(), false);
    assert.equal(harness.workers[0].messages.length, workerMessagesAfterStop);
    assert.equal(harness.detections.length, 0);
});

test('unsupported or failed startup rejects and stops raw capture instead of passing it through', async () => {
    const unsupported = createHarness();
    unsupported.gate.Worker = null;
    await assert.rejects(
        unsupported.gate.start(unsupported.rawStream),
        (error) => error.code === 'unsupported',
    );
    assert.equal(unsupported.rawTrack.stopped, true);
    assert.equal(unsupported.errors.length, 1);

    const failed = createHarness({ addModuleError: new Error('module missing') });
    await assert.rejects(
        failed.gate.start(failed.rawStream),
        (error) => error.code === 'start_failed',
    );
    assert.equal(failed.rawTrack.stopped, true);
    assert.equal(failed.contexts[0].closed, true);
    assert.equal(failed.errors.length, 1);
});

test('[BV2-FIRST-WAKE-01:C-E][BV2-WAKE-01][BV2-WAKE-11][BV2-SIDEBAND-01][BV2-PRIVACY-PCM-03] in-flight PCM drains privately while durable pre-admission is pending', async () => {
    let approve;
    const admission = new Promise((resolve) => { approve = resolve; });
    const harness = createHarness({ beforeRelease: () => admission, maxInFlightPcm: 2 });
    await harness.gate.start(harness.rawStream);
    const generation = harness.gate.currentGeneration();
    completeReadinessBarrier(harness, generation);
    harness.emitPcm({ generation, samples: new Float32Array(1_280).fill(0.25) });
    harness.emitPcm({ generation, samples: new Float32Array(1_280).fill(0.5) });
    const candidates = harness.workers[0].messages
        .filter(({ message }) => message.type === 'audio' && message.generation === generation)
        .slice(-2)
        .map(({ message }) => message);
    harness.workers[0].emit({
        type: 'ack',
        generation,
        sequence: candidates[0].sequence,
        accepted: true,
    });
    harness.workers[0].emit(wakeMessage(harness, generation, {
        sourceSequence: candidates[0].sourceSequence,
    }));

    assert.equal(harness.gate.state, 'admission_pending');
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.detections.length, 0);
    assert.equal(harness.activatedPcm.length, 0);
    assert.equal(
        harness.worklets[0].port.messages.filter(({ message }) => message.type === 'activate').length,
        0,
    );

    // The real worker disarms as soon as it confirms the wake. Any PCM already
    // queued behind the confirming batch is acknowledged as activation-pending
    // while the durable server admission is still unresolved.
    harness.workers[0].emit({
        type: 'ack',
        generation,
        sequence: candidates[1].sequence,
        accepted: false,
        reason: 'activation_pending',
    });
    assert.equal(harness.errors.length, 0);
    assert.equal(harness.gate.state, 'admission_pending');
    assert.equal(harness.gate.pendingPcmChunks(), 0);
    assert.equal(harness.timers.size, 0);
    assert.equal(harness.rawTrack.stopped, false);
    assert.equal(harness.workers[0].terminated, false);

    const workerAudioCount = harness.workers[0].messages
        .filter(({ message }) => message.type === 'audio' && message.generation === generation)
        .length;
    harness.emitPcm({ generation, samples: new Float32Array(1_280).fill(0.75) });
    assert.equal(harness.activatedPcm.length, 0, 'live PCM must remain private while admission is pending');
    assert.equal(harness.gate.bufferedPcmChunks(), 1, 'pending PCM remains in the bounded local bridge queue');
    assert.equal(
        harness.workers[0].messages
            .filter(({ message }) => message.type === 'audio' && message.generation === generation)
            .length,
        workerAudioCount,
        'a disarmed worker must not receive more PCM while admission is pending',
    );

    approve(true);
    await admission;
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(harness.gate.isOpen(), true);
    assert.equal(harness.detections.length, 1);
    assert.deepEqual(
        harness.activatedPcm.map(({ sourceSequence }) => sourceSequence),
        [candidates[0].sourceSequence, candidates[1].sourceSequence, candidates[1].sourceSequence + 1],
    );
    assert.ok(harness.activatedPcm.every((event) => event.released === true));
    assert.equal(
        harness.worklets[0].port.messages.filter(({ message }) => message.type === 'activate').length,
        1,
    );

    harness.emitPcm({ generation, samples: new Float32Array(1_280).fill(0.9) });
    assert.equal(harness.activatedPcm.at(-1).released, false);
    assert.equal(harness.activatedPcm.at(-1).sourceSequence, candidates[1].sourceSequence + 2);
    harness.advance(LOCAL_WAKE_PCM_ACK_TIMEOUT_MS * 2);
    assert.equal(harness.errors.length, 0);
});

test('[BV2-WAKE-01][BV2-WAKE-11][BV2-DIAGNOSTIC-03][BV2-PRIVACY-PCM-03] rejected pre-admission after activation-pending PCM rearms a clean generation', async () => {
    let rejectAdmission;
    let attempts = 0;
    const firstAdmission = new Promise((resolve) => { rejectAdmission = resolve; });
    const harness = createHarness({
        beforeRelease: () => {
            attempts += 1;
            return attempts === 1 ? firstAdmission : true;
        },
    });
    await harness.gate.start(harness.rawStream);
    const firstGeneration = harness.gate.currentGeneration();
    completeReadinessBarrier(harness, firstGeneration);
    harness.emitPcm({ generation: firstGeneration, samples: new Float32Array(1_280).fill(0.25) });
    harness.emitPcm({ generation: firstGeneration, samples: new Float32Array(1_280).fill(0.5) });
    const candidates = harness.workers[0].messages
        .filter(({ message }) => message.type === 'audio' && message.generation === firstGeneration)
        .slice(-2)
        .map(({ message }) => message);
    harness.workers[0].emit({
        type: 'ack',
        generation: firstGeneration,
        sequence: candidates[0].sequence,
        accepted: true,
    });
    harness.workers[0].emit(wakeMessage(harness, firstGeneration, {
        sourceSequence: candidates[0].sourceSequence,
    }));
    harness.workers[0].emit({
        type: 'ack',
        generation: firstGeneration,
        sequence: candidates[1].sequence,
        accepted: false,
        reason: 'activation_pending',
    });
    harness.emitPcm({ generation: firstGeneration, samples: new Float32Array(1_280).fill(0.75) });

    assert.equal(harness.gate.state, 'admission_pending');
    assert.equal(harness.errors.length, 0);
    assert.equal(harness.activatedPcm.length, 0);
    rejectAdmission(false);
    await firstAdmission;
    await new Promise((resolve) => setImmediate(resolve));

    const secondGeneration = harness.gate.currentGeneration();
    assert.equal(secondGeneration, firstGeneration + 1);
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.detections.length, 0);
    assert.equal(harness.activatedPcm.length, 0);
    assert.equal(harness.releaseRejections.length, 1);
    assert.equal(harness.errors.length, 0);
    assert.equal(harness.gate.pendingPcmChunks(), 0);
    assert.equal(harness.gate.bufferedPcmChunks(), 0);
    assert.deepEqual(harness.workers[0].messages.at(-1).message, {
        type: 'reset',
        generation: secondGeneration,
        reason: 'pre_admission_rejected',
    });

    harness.workers[0].emit({
        type: 'ack',
        generation: firstGeneration,
        sequence: candidates[1].sequence,
        accepted: false,
        reason: 'activation_pending',
    });
    assert.equal(harness.errors.length, 0, 'a stale acknowledgement cannot fail the replacement generation');

    completeReadinessBarrier(harness, secondGeneration);
    harness.workers[0].emit(wakeMessage(harness, secondGeneration));
    assert.equal(harness.gate.isOpen(), true);
    assert.deepEqual(harness.detections.map(({ generation }) => generation), [secondGeneration]);
    assert.ok(harness.activatedPcm.every(({ generation }) => generation === secondGeneration));
});

test('[BV2-WAKE-11][BV2-DIAGNOSTIC-03][BV2-PRIVACY-PCM-03] stop invalidates a pending wake admission and its late approval', async () => {
    let approve;
    const admission = new Promise((resolve) => { approve = resolve; });
    const harness = createHarness({ beforeRelease: () => admission, maxInFlightPcm: 2 });
    await harness.gate.start(harness.rawStream);
    const generation = harness.gate.currentGeneration();
    completeReadinessBarrier(harness, generation);
    harness.emitPcm({ generation, samples: new Float32Array(1_280).fill(0.25) });
    harness.emitPcm({ generation, samples: new Float32Array(1_280).fill(0.5) });
    const candidates = harness.workers[0].messages
        .filter(({ message }) => message.type === 'audio' && message.generation === generation)
        .slice(-2)
        .map(({ message }) => message);
    harness.workers[0].emit({
        type: 'ack',
        generation,
        sequence: candidates[0].sequence,
        accepted: true,
    });
    harness.workers[0].emit(wakeMessage(harness, generation, {
        sourceSequence: candidates[0].sourceSequence,
    }));
    harness.workers[0].emit({
        type: 'ack',
        generation,
        sequence: candidates[1].sequence,
        accepted: false,
        reason: 'activation_pending',
    });
    harness.emitPcm({ generation, samples: new Float32Array(1_280).fill(0.75) });

    assert.equal(harness.gate.state, 'admission_pending');
    const stopping = harness.gate.stop();
    approve(true);
    await admission;
    await stopping;
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(harness.gate.state, 'stopped');
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.detections.length, 0);
    assert.equal(harness.activatedPcm.length, 0);
    assert.equal(harness.errors.length, 0);
    assert.equal(harness.rawTrack.stopped, true);
    assert.equal(harness.workers[0].terminated, true);
    assert.equal(harness.timers.size, 0);
    assert.equal(
        harness.worklets[0].port.messages.filter(({ message }) => message.type === 'activate').length,
        0,
    );
});

test('[BV-FOLLOW-UP-01] contextual capture starts at a fresh live boundary without releasing pre-admission PCM', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const generation = harness.gate.currentGeneration();
    completeReadinessBarrier(harness, generation);
    harness.emitPcm({ generation, samples: new Float32Array(1600).fill(0.2) });
    const pending = harness.workers[0].messages
        .filter(({ message }) => message.type === 'audio' && message.generation === generation)
        .at(-1).message;
    harness.workers[0].emit({
        type: 'ack',
        generation,
        sequence: pending.sequence,
        accepted: true,
    });

    assert.equal(harness.gate.openContextualCapture({ generation }), true);
    assert.equal(harness.gate.isOpen(), true);
    assert.equal(harness.activatedPcm.length, 0, 'dormant PCM is discarded at contextual admission');
    harness.emitPcm({ generation, samples: new Float32Array(1600).fill(0.4) });
    assert.equal(harness.activatedPcm.length, 1);
    assert.equal(harness.activatedPcm[0].released, false);
    assert.equal(harness.activatedPcm[0].sourceSequence, 2);
});
