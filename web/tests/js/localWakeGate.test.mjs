import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

import {
    LOCAL_WAKE_ADDRESS_CONFIRMATION_MS,
    LOCAL_WAKE_GATE_PROCESSOR_NAME,
    LOCAL_WAKE_GATE_PROCESSOR_URL,
    LOCAL_WAKE_PCM_SAMPLE_RATE,
    LOCAL_WAKE_WORKER_URL,
    LocalWakeGate,
} from '../../resources/js/heybean/localWakeGate.js';

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
    assert.match(worker, /message\.type === 'cancel_candidate'/);
    assert.match(worker, /message\.type === 'close'/);
    assert.match(worker, /type:\s*'wake_confirmed'/);
    assert.match(worker, /type:\s*'address_candidate'/);
    assert.match(worker, /type:\s*'address_rejected'/);
    assert.match(worker, /createKws\(moduleInstance,/);
    assert.match(worker, /warmKeywordSpotter\(\)/);
    assert.match(worker, /classifyFirstPartyAddressPrefix\(\)/);
    assert.doesNotMatch(worker, /keywordSpotter\.createStream\(ADDRESS_KEYWORDS\)/);
    assert.doesNotMatch(worker, /createOnlineRecognizer/);
});

test('the packaged KWS worker exposes only strict timing candidates and never dormant text', async () => {
    const source = await readFile(
        new URL('../../public/voice/wake/wake-worker.js', import.meta.url),
        'utf8',
    );
    const context = {
        URL,
        importScripts() { throw new Error('vendor runtime intentionally skipped'); },
        postMessage() {},
        self: {
            location: { href: 'https://example.test/voice/wake/wake-worker.js?generation=1' },
            addEventListener() {},
            close() {},
        },
    };
    runInNewContext(`${source}
globalThis.__keywordDecision = keywordDecision;
globalThis.__wakeBoundary = {
    track: trackUtteranceActivity,
    shouldReset: shouldResetNonWakeUtterance,
    reset: resetUtteranceActivity,
    setArmed(value) { armed = value; },
};`, context);

    const none = { keyword: '', timestamps: [] };
    assert.equal(context.__keywordDecision({
        strictResult: { keyword: 'HEY_BEAN', timestamps: [0.08, 0.16, 0.36] },
    }).type, 'strict_wake');
    assert.equal(context.__keywordDecision({
        strictResult: { keyword: 'HEY_BEAN', timestamps: [1.1, 1.2, 1.4] },
    }).type, 'strict_wake');
    assert.equal(context.__keywordDecision({
        strictResult: none,
    }).type, 'none');

    assert.match(source, /HH EY1 B IY1 N :1\.2 #0\.1 @HEY_BEAN/);
    assert.match(source, /classification = classification \|\| classifyBeanCandidate/);
    assert.match(source, /releaseBoundary:/);
    assert.match(source, /sourceSequence/);
    assert.match(source, /classifyFirstPartyAddressPrefix/);
    assert.doesNotMatch(source, /ADDRESS_CAN_YOU|ADDRESS_KEYWORDS|addressStream/);
    assert.doesNotMatch(source, /decoded_text|result\.text|transcript/);

    const speech = new Float32Array(1600).fill(0.08);
    const silence = new Float32Array(1600);
    context.__wakeBoundary.setArmed(true);
    context.__wakeBoundary.track(speech);
    for (let chunk = 0; chunk < 6; chunk += 1) {
        context.__wakeBoundary.track(silence);
        assert.equal(context.__wakeBoundary.shouldReset(), false);
    }
    context.__wakeBoundary.track(silence);
    assert.equal(context.__wakeBoundary.shouldReset(), true);
    context.__wakeBoundary.reset();
    assert.equal(context.__wakeBoundary.shouldReset(), false);
});

test('[BV2-WAKE-MODEL-01] first-party classifier is self-contained and JavaScript inference matches training math', async () => {
    const assetRoot = new URL('../../public/voice/wake/', import.meta.url);
    const [source, model] = await Promise.all([
        readFile(new URL('wake-worker.js', assetRoot), 'utf8'),
        readFile(new URL('bean-wake-model-v1.json', assetRoot), 'utf8').then(JSON.parse),
    ]);
    const context = {
        URL,
        fetch() { throw new Error('No network is permitted in the inference parity test.'); },
        importScripts() { throw new Error('Vendor candidate runtime intentionally skipped.'); },
        postMessage() {},
        self: {
            location: { href: 'https://example.test/voice/wake/wake-worker.js?generation=1' },
            addEventListener() {},
            close() {},
        },
    };
    runInNewContext(`${source}
globalThis.__classifyDeterministic = (manifest) => {
    const prepared = prepareBeanWakeModel(manifest);
    const samples = new Float32Array(19200);
    for (let index = 0; index < samples.length; index += 1) {
        const time = index / 16000;
        const envelope = Math.min(1, index / 1600, (samples.length - 1 - index) / 1600);
        samples[index] = (0.07 * Math.sin(2 * Math.PI * 220 * time)
            + 0.025 * Math.sin(2 * Math.PI * 660 * time)) * envelope;
    }
    return beanWakeProbabilities(samples, prepared);
};`, context);

    assert.equal(model.runtime_network_required, false);
    assert.equal(model.external_account_required, false);
    assert.equal(model.license_key_required, false);
    assert.equal(model.training.evidence_classification, 'seen_synthetic_regression_only');
    const probabilities = context.__classifyDeterministic(model);
    // Independently produced by the repository training feature/inference
    // implementation for this deterministic waveform and packaged weights.
    const expected = [0.9999998808, 0, 0.000000096];
    assert.equal(probabilities.length, expected.length);
    probabilities.forEach((value, index) => {
        assert.ok(Math.abs(value - expected[index]) < 0.0005, `${index}: ${value}`);
    });
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
    maxInFlightPcm = 2,
    maxBufferedPcm = 80,
    consumerReady = true,
    onDetected = null,
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
    const errors = [];
    const detections = [];
    const readiness = [];
    const activatedPcm = [];
    const gate = new LocalWakeGate({
        AudioContext: FakeAudioContext,
        AudioWorkletNode: FakeAudioWorkletNode,
        Worker: FakeWorker,
        MediaStream: FakeMediaStream,
        maxInFlightPcm,
        maxBufferedPcm,
        consumerReady,
        setTimeout,
        clearTimeout,
        onReady: (event) => {
            order.push('ready');
            readiness.push(event);
        },
        onActivity: (activity) => activities.push(activity),
        onActivatedPcm: (event) => activatedPcm.push(event),
        onError: (error) => errors.push(error),
        onDetected: (detection) => {
            order.push('detected');
            detections.push(detection);
            onDetected?.(detection);
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
        errors,
        gate,
        order,
        rawStream,
        rawTrack,
        emitPcm,
        readiness,
        timers,
        workers,
        worklets,
        FakeAudioContext,
        FakeAudioWorkletNode,
        FakeMediaStream,
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
    worker.emit({
        type: 'ack',
        generation,
        sequence: audioMessage.sequence,
        accepted: false,
        reason: 'decode_pending',
    });
    assert.equal(harness.gate.isReady(), false);

    harness.emitPcm({ generation });
    const acceptedMessage = worker.messages.filter(({ message }) => message.type === 'audio').at(-1).message;
    worker.emit({ type: 'ack', generation, sequence: acceptedMessage.sequence, accepted: true });
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

test('[BV2-PRIVACY-PCM-03] strict wake releases only its declared post-address tail then streams live PCM', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const generation = harness.gate.currentGeneration();
    const worker = harness.workers[0];
    completeReadinessBarrier(harness, generation);

    harness.emitPcm({ generation, samples: new Float32Array(1600).fill(0.1) });
    harness.emitPcm({ generation, samples: new Float32Array(1600).fill(0.2) });
    const latestBridge = worker.messages.filter(({ message }) => message.type === 'audio').at(-1).message;
    worker.emit({ type: 'ack', generation, sequence: latestBridge.sequence, accepted: true });
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

test('[BV2-WAKE-11] one generic verifier owns wake acceptance and recovers strict Hey Bean missed by timing', async () => {
    const worker = await readFile(new URL('../../public/voice/wake/wake-worker.js', import.meta.url), 'utf8');

    assert.match(worker, /STRICT_ACCEPTANCE_PROBABILITY = 0\.75/);
    assert.match(worker, /STRICT_PREFIX_ACCEPTANCE_PROBABILITY = 0\.99/);
    assert.doesNotMatch(worker, /STRICT_NEAR_MISS_ALIASES/);
    assert.deepEqual([...new Set(worker.match(/@[A-Z_]+/g))], ['@HEY_BEAN']);
    assert.match(worker, /strictWakeAccepted = silentChunksAfterSpeech >= STRICT_PREFIX_FALLBACK_SILENCE_CHUNKS/);
    assert.match(worker, /activation: 'missed_hey_confirmation'/);
    assert.match(worker, /decision\.activation !== 'strict_wake'/);
});

test('missed-Hey candidate stays private and only obvious second-person confirmation opens it', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const generation = harness.gate.currentGeneration();
    const worker = harness.workers[0];
    completeReadinessBarrier(harness, generation);

    worker.emit({ type: 'address_candidate', generation });
    assert.equal(harness.gate.state, 'confirming');
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.detections.length, 0);
    assert.equal(harness.order.includes('gate:activate'), false);

    harness.advance(LOCAL_WAKE_ADDRESS_CONFIRMATION_MS - 501);
    assert.equal(harness.gate.isOpen(), false);
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
    harness.advance(501);
    assert.equal(worker.messages.some(({ message }) => message.reason === 'address_timeout'), false);
});

test('ambiguous missed-Hey candidate expires silently within three seconds and rejects stale confirmation', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const firstGeneration = harness.gate.currentGeneration();
    const worker = harness.workers[0];
    const worklet = harness.worklets[0];
    completeReadinessBarrier(harness, firstGeneration);

    worker.emit({ type: 'address_candidate', generation: firstGeneration });
    harness.advance(LOCAL_WAKE_ADDRESS_CONFIRMATION_MS);

    const secondGeneration = harness.gate.currentGeneration();
    assert.equal(secondGeneration, firstGeneration + 1);
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.gate.isReady(), false);
    assert.equal(harness.gate.state, 'listening');
    assert.equal(harness.detections.length, 0);
    assert.deepEqual(worker.messages.at(-1).message, {
        type: 'reset',
        generation: secondGeneration,
        reason: 'address_timeout',
    });
    assert.deepEqual(worklet.port.messages.at(-1).message, {
        type: 'close',
        generation: secondGeneration,
    });

    worker.emit({
        type: 'wake_confirmed',
        generation: firstGeneration,
        keyword: 'HEY_BEAN',
        variant: 'BEAN',
        activation: 'missed_hey_confirmation',
    });
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.detections.length, 0);

    completeReadinessBarrier(harness, secondGeneration);
    const forwardedAudioCount = worker.messages.filter(({ message }) => message.type === 'audio').length;
    worklet.port.emit({
        type: 'audio',
        generation: firstGeneration,
        samples: new ArrayBuffer(16),
    });
    assert.equal(
        worker.messages.filter(({ message }) => message.type === 'audio').length,
        forwardedAudioCount,
    );
});

test('obvious third-person Bean mention is rejected silently and erases candidate audio generation', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const firstGeneration = harness.gate.currentGeneration();
    const worker = harness.workers[0];
    completeReadinessBarrier(harness, firstGeneration);

    worker.emit({ type: 'address_candidate', generation: firstGeneration });
    worker.emit({ type: 'address_rejected', generation: firstGeneration });

    const secondGeneration = harness.gate.currentGeneration();
    assert.equal(secondGeneration, firstGeneration + 1);
    assert.equal(harness.gate.isOpen(), false);
    assert.equal(harness.detections.length, 0);
    assert.equal(harness.timers.size, 0);
    assert.deepEqual(worker.messages.at(-1).message, {
        type: 'reset',
        generation: secondGeneration,
        reason: 'address_rejected',
    });

    worker.emit({
        type: 'wake_confirmed',
        generation: firstGeneration,
        keyword: 'HEY_BEAN',
        variant: 'BEAN',
        activation: 'missed_hey_confirmation',
    });
    assert.equal(harness.gate.isOpen(), false);
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

    worker.emit({ type: 'ack', generation, sequence: firstSequence });
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

test('stop invalidates a pending local address timer without reopening or rearming', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const generation = harness.gate.currentGeneration();
    completeReadinessBarrier(harness, generation);
    harness.workers[0].emit({ type: 'address_candidate', generation });
    assert.equal(harness.gate.state, 'confirming');

    await harness.gate.stop();
    const workerMessagesAfterStop = harness.workers[0].messages.length;
    harness.advance(LOCAL_WAKE_ADDRESS_CONFIRMATION_MS * 2);

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
