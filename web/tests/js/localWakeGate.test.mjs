import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

import {
    LOCAL_WAKE_GATE_PROCESSOR_NAME,
    LOCAL_WAKE_GATE_PROCESSOR_URL,
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
    assert.match(processor, /registerProcessor\(PROCESSOR_NAME,/);
    assert.match(processor, /type:\s*'audio'/);
    assert.match(processor, /message\.type === 'open'/);
    assert.match(processor, /message\.type === 'close'/);
    assert.match(worker, /message\.type === 'audio'/);
    assert.match(worker, /message\.type === 'reset'/);
    assert.match(worker, /message\.type === 'close'/);
    assert.match(worker, /type:\s*'detected'/);
    assert.match(worker, /warmRecognizer\(\)/);
});

test('the packaged worker accepts observed Hey Bean acoustics without broad homophone wakes', async () => {
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
globalThis.__matchedWakeVariant = matchedWakeVariant;
globalThis.__wakeBoundary = {
    track: trackUtteranceActivity,
    shouldReset: shouldResetNonWakeUtterance,
    reset: resetUtteranceActivity,
    setArmed(value) { armed = value; },
};`, context);

    assert.equal(context.__matchedWakeVariant('HEY BEAN WHAT TIME IS IT'), 'HEY BEAN');
    assert.equal(context.__matchedWakeVariant('HE BEING WHAT TIME IS IT'), 'HE BEING');
    assert.equal(context.__matchedWakeVariant('HEY BEN WHAT TIME IS IT'), '');
    assert.equal(context.__matchedWakeVariant('HEY BEAM WHAT TIME IS IT'), '');
    assert.equal(context.__matchedWakeVariant('TO BEGIN SAY HEY BEAN'), '');

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

test('the packaged worklet is exact-zero while closed and releases buffered command onset after wake', async () => {
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

    // Simulate a short wake-and-command onset that started almost a second
    // before the local recognizer confirmed the keyword.
    const beforeWake = new Float32Array(32_000);
    beforeWake.fill(0.25, 16_000, 17_600);
    const closedOutput = render(beforeWake);
    assert.equal(closedOutput.every((sample) => Object.is(sample, 0)), true);
    assert.ok(processorMessages.some((message) => message.type === 'activity' && message.level > 0));

    processor.handleControlMessage({ type: 'open', generation: 1 });
    const openOutput = render(new Float32Array(20_800));
    const firstRecoveredSample = openOutput.findIndex((sample) => Math.abs(sample) > 0.24);
    assert.ok(firstRecoveredSample >= 3_100 && firstRecoveredSample <= 3_300);
    assert.equal(Math.max(...openOutput), 0.25);
});

function createHarness({ addModuleError = null, maxInFlightPcm = 2 } = {}) {
    const order = [];
    const contexts = [];
    const worklets = [];
    const workers = [];

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
            this.destination = null;
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

        createMediaStreamDestination() {
            this.destination = new FakeNode('destination');
            this.destination.stream = new FakeMediaStream([new FakeTrack('derived')]);
            return this.destination;
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
    const gate = new LocalWakeGate({
        AudioContext: FakeAudioContext,
        AudioWorkletNode: FakeAudioWorkletNode,
        Worker: FakeWorker,
        MediaStream: FakeMediaStream,
        maxInFlightPcm,
        onActivity: (activity) => activities.push(activity),
        onError: (error) => errors.push(error),
        onDetected: (detection) => {
            order.push('detected');
            detections.push(detection);
        },
    });

    const rawTrack = new FakeTrack('raw');
    const rawStream = new FakeMediaStream([rawTrack]);

    return {
        contexts,
        activities,
        detections,
        errors,
        gate,
        order,
        rawStream,
        rawTrack,
        workers,
        worklets,
        FakeAudioContext,
        FakeAudioWorkletNode,
        FakeMediaStream,
    };
}

test('start exposes only a closed derived track through a same-origin static graph', async () => {
    const harness = createHarness();
    const result = await harness.gate.start(harness.rawStream);
    const context = harness.contexts[0];
    const worklet = harness.worklets[0];
    const worker = harness.workers[0];

    assert.notEqual(result.stream, harness.rawStream);
    assert.notEqual(result.track, harness.rawTrack);
    assert.deepEqual(result.stream.getTracks(), [result.track]);
    assert.deepEqual(Object.keys(result).sort(), ['stream', 'track']);
    assert.equal(context.source.stream, harness.rawStream);
    assert.deepEqual(context.source.connections, [worklet]);
    assert.deepEqual(worklet.connections, [context.destination]);
    assert.equal(worklet.options.processorOptions.gateOpen, false);
    assert.deepEqual(worklet.port.messages[0].message, {
        type: 'close',
        generation: harness.gate.currentGeneration(),
    });
    assert.equal(worker.url, `${LOCAL_WAKE_WORKER_URL}&generation=${harness.gate.currentGeneration()}`);
    assert.deepEqual(worker.options, { name: 'heybean-local-wake' });
    assert.ok(harness.order.includes(`module:${LOCAL_WAKE_GATE_PROCESSOR_URL}`));
    assert.equal(harness.gate.isOpen(), false);
});

test('only a ready current-generation detection opens the gate and reset rejects stale events', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const worker = harness.workers[0];
    const worklet = harness.worklets[0];
    const firstGeneration = harness.gate.currentGeneration();

    worker.emit({ type: 'detected', generation: firstGeneration });
    worker.emit({ type: 'ready', generation: firstGeneration - 1 });
    worker.emit({ type: 'detected', generation: firstGeneration - 1 });
    assert.equal(harness.gate.isOpen(), false);

    worker.emit({ type: 'ready', generation: firstGeneration });
    worker.emit({ type: 'detected', generation: firstGeneration });
    worker.emit({ type: 'detected', generation: firstGeneration });
    assert.equal(harness.gate.isOpen(), true);
    assert.deepEqual(harness.detections, [{ generation: firstGeneration, keyword: '', variant: '', result: null }]);
    assert.ok(harness.order.indexOf('detected') < harness.order.indexOf('gate:open'));

    const secondGeneration = harness.gate.resetAfterTurn();
    assert.ok(secondGeneration > firstGeneration);
    assert.equal(harness.gate.isOpen(), false);
    assert.deepEqual(worker.messages.at(-1).message, { type: 'reset', generation: secondGeneration });

    worker.emit({ type: 'ready', generation: firstGeneration });
    worker.emit({ type: 'detected', generation: firstGeneration });
    worker.emit({ type: 'detected', generation: secondGeneration });
    assert.equal(harness.gate.isOpen(), false);

    worker.emit({ type: 'ready', generation: secondGeneration });
    worker.emit({ type: 'detected', generation: secondGeneration });
    assert.equal(harness.gate.isOpen(), true);
    assert.equal(worklet.port.messages.at(-1).message.type, 'open');
    assert.deepEqual(harness.detections, [
        { generation: firstGeneration, keyword: '', variant: '', result: null },
        { generation: secondGeneration, keyword: '', variant: '', result: null },
    ]);
});

test('startup readiness requires one successfully decoded live microphone chunk', async () => {
    const harness = createHarness();
    await harness.gate.start(harness.rawStream);
    const worker = harness.workers[0];
    const worklet = harness.worklets[0];
    const generation = harness.gate.currentGeneration();

    worker.emit({ type: 'ready', generation });
    assert.equal(harness.gate.isReady(), false);

    worklet.port.emit({ type: 'audio', samples: new ArrayBuffer(16) });
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

    worklet.port.emit({ type: 'audio', samples: new ArrayBuffer(16) });
    const acceptedMessage = worker.messages.filter(({ message }) => message.type === 'audio').at(-1).message;
    worker.emit({ type: 'ack', generation, sequence: acceptedMessage.sequence, accepted: true });
    assert.equal(harness.gate.isReady(), true);
    assert.equal(harness.gate.state, 'armed');
});

test('PCM transfer is bounded until matching worker acknowledgements release capacity', async () => {
    const harness = createHarness({ maxInFlightPcm: 2 });
    await harness.gate.start(harness.rawStream);
    const worker = harness.workers[0];
    const worklet = harness.worklets[0];
    const generation = harness.gate.currentGeneration();
    worker.emit({ type: 'ready', generation });

    const buffers = [new ArrayBuffer(16), new ArrayBuffer(16), new ArrayBuffer(16)];
    buffers.forEach((samples) => worklet.port.emit({ type: 'audio', samples }));

    const pcmMessages = () => worker.messages.filter(({ message }) => message.type === 'audio');
    assert.equal(pcmMessages().length, 2);
    assert.equal(harness.gate.pendingPcmChunks(), 2);
    assert.deepEqual(pcmMessages()[0].transfer, [buffers[0]]);
    assert.deepEqual(pcmMessages()[1].transfer, [buffers[1]]);

    const firstSequence = pcmMessages()[0].message.sequence;
    worker.emit({ type: 'ack', generation: generation - 1, sequence: firstSequence });
    assert.equal(harness.gate.pendingPcmChunks(), 2);

    worker.emit({ type: 'ack', generation, sequence: firstSequence });
    assert.equal(harness.gate.pendingPcmChunks(), 1);
    worklet.port.emit({ type: 'audio', samples: buffers[2] });
    assert.equal(pcmMessages().length, 3);
    assert.equal(harness.gate.pendingPcmChunks(), 2);
    assert.deepEqual(pcmMessages()[2].transfer, [buffers[2]]);
});

test('the production wake queue preserves over one second of decode backpressure', async () => {
    const harness = createHarness({ maxInFlightPcm: null });
    await harness.gate.start(harness.rawStream);
    const worker = harness.workers[0];
    const worklet = harness.worklets[0];
    const generation = harness.gate.currentGeneration();
    worker.emit({ type: 'ready', generation });

    for (let index = 0; index < 13; index += 1) {
        worklet.port.emit({ type: 'audio', samples: new ArrayBuffer(16) });
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

    worker.emit({ type: 'ready', generation });
    worker.emit({ type: 'detected', generation });
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
    assert.equal(result.track.stopped, true);
    assert.equal(harness.errors.length, 1);
    assert.match(harness.errors[0].message, /decoder failed/);

    const closeIndex = harness.order.lastIndexOf('gate:close');
    assert.ok(closeIndex < harness.order.indexOf('worker:terminate'));
    assert.ok(closeIndex < harness.order.indexOf('track:raw:stop'));
});

test('stop synchronously closes before terminating graph, context, raw, and derived tracks', async () => {
    const harness = createHarness();
    const result = await harness.gate.start(harness.rawStream);
    const generation = harness.gate.currentGeneration();
    harness.workers[0].emit({ type: 'ready', generation });
    harness.workers[0].emit({ type: 'detected', generation });

    const stopping = harness.gate.stop();
    assert.equal(harness.gate.isOpen(), false);
    await stopping;

    const closeIndex = harness.order.lastIndexOf('gate:close');
    const terminateIndex = harness.order.indexOf('worker:terminate');
    const contextIndex = harness.order.indexOf('context:close');
    const rawIndex = harness.order.indexOf('track:raw:stop');
    const derivedIndex = harness.order.indexOf('track:derived:stop');
    assert.ok(closeIndex < terminateIndex);
    assert.ok(terminateIndex < contextIndex);
    assert.ok(contextIndex < rawIndex);
    assert.ok(contextIndex < derivedIndex);
    assert.equal(harness.rawTrack.stopped, true);
    assert.equal(result.track.stopped, true);
    assert.equal(harness.contexts[0].closed, true);
    assert.equal(harness.gate.state, 'stopped');
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
