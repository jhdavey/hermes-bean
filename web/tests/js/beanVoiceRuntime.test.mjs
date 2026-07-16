import assert from 'node:assert/strict';
import test from 'node:test';

import { BeanVoiceRuntime } from '../../resources/js/heybean/BeanVoiceRuntime.js';

function strictDetection(generation = 7, sourceSequence = 20) {
    return {
        generation,
        sourceSequence,
        activation: 'strict_wake',
        keyword: 'HEY_BEAN',
        variant: 'HEY BEAN',
        releaseBoundary: { sourceSequence, sampleOffset: 0, policy: 'post_address_tail' },
    };
}

function harness({
    gateAdmissionReady = true,
    gestureAudioContext = false,
    transportFailures = [],
    freshMicrophonePerStart = false,
} = {}) {
    const requests = [];
    const views = [];
    const work = [];
    const invalidations = [];
    const failures = [];
    const startupOrder = [];
    const audioContexts = [];
    const rawTracks = [];
    const rawStreams = [];
    const pendingTransportFailures = [...transportFailures];
    const timers = new Map();
    let nextTimer = 1;
    let microphoneAttempts = 0;

    function createRawStream() {
        const rawTrack = { stopped: 0, stop() { this.stopped += 1; } };
        const rawStream = {
            getTracks: () => [rawTrack],
            getAudioTracks: () => [rawTrack],
        };
        rawTracks.push(rawTrack);
        rawStreams.push(rawStream);
        return rawStream;
    }

    const rawStream = createRawStream();

    class FakeGate {
        constructor(options) {
            this.options = options;
            startupOrder.push('gate:create');
            this.generation = 7;
            this.consumerReady = false;
            this.ready = gateAdmissionReady;
            this.stopped = false;
            this.resetCount = 0;
            this.closeCount = 0;
            this.consumerAdmissionWaiter = null;
        }

        async start(stream) {
            startupOrder.push('gate:start');
            assert.ok(rawStreams.includes(stream));
            return { sampleRate: 16000 };
        }

        setConsumerReady(value) { this.consumerReady = value === true; }
        primeConsumerAdmission() {
            this.consumerReady = true;
            return this.generation;
        }
        waitForConsumerAdmissionReady({ generation }) {
            if (generation !== this.generation) {
                return Promise.reject(Object.assign(new Error('stale gate generation'), {
                    code: 'consumer_admission_generation_superseded',
                }));
            }
            if (this.ready && this.consumerReady) {
                return Promise.resolve({ type: 'consumer_admission_ready', generation });
            }
            return new Promise((resolve, reject) => {
                this.consumerAdmissionWaiter = { generation, resolve, reject };
            });
        }
        isConsumerAdmissionReady() { return this.consumerReady; }
        isReady() { return this.ready; }
        currentGeneration() { return this.generation; }
        async stop() {
            this.stopped = true;
            this.consumerAdmissionWaiter?.reject?.(Object.assign(new Error('gate stopped'), {
                code: 'consumer_admission_stopped',
            }));
            this.consumerAdmissionWaiter = null;
            await this.options.audioContext?.close?.();
        }
        close() { this.closeCount += 1; return true; }
        resetAfterTurn() {
            this.resetCount += 1;
            this.generation += 1;
            this.ready = false;
            return this.generation;
        }
        openContextualCapture({ generation }) {
            if (!this.ready || generation !== this.generation) return false;
            this.contextualOpenGeneration = generation;
            return true;
        }
        readyNow() {
            this.ready = true;
            if (this.consumerAdmissionWaiter?.generation === this.generation) {
                const waiter = this.consumerAdmissionWaiter;
                this.consumerAdmissionWaiter = null;
                waiter.resolve({ type: 'consumer_admission_ready', generation: this.generation });
            }
            this.options.onReady?.({ type: 'ready', generation: this.generation });
        }
    }

    class FakeTransport {
        constructor(options) {
            this.options = options;
            this.connected = false;
            this.activeResponse = null;
            this.authorizations = [];
            this.activated = [];
            this.appended = [];
            this.closeReasons = [];
            this.connectInputs = [];
        }

        prime() { return {}; }
        async connect(input) {
            this.connectInput = input;
            this.connectInputs.push(input);
            const failure = pendingTransportFailures.shift();
            if (failure) throw failure;
            this.connected = true;
            return {
                realtimeSessionId: '11111111-1111-4111-8111-111111111111',
                playbackCapability: 'capability-1',
            };
        }

        snapshot() {
            return {
                connected: this.connected,
                playbackActive: Boolean(this.activeResponse?.started),
                activeResponse: this.activeResponse ? { ...this.activeResponse } : null,
            };
        }

        sendInputEvent() { return true; }
        bufferedAmount() { return 0; }
        activateInput(generation) { this.activated.push(generation); return true; }
        deactivateInput() { this.deactivated = true; }
        appendActivatedPcm(event) { this.appended.push(event); return true; }
        authorizeSpeech(item) { this.authorizations.push(item); return true; }
        duck() { this.ducked = true; return Boolean(this.activeResponse); }
        restore() { this.ducked = false; return Boolean(this.activeResponse); }
        stopPlayback(reason) {
            if (!this.activeResponse) return false;
            const response = { ...this.activeResponse };
            this.activeResponse = null;
            this.options.onEvent({ type: 'playback_stopped', reason, ...response });
            return true;
        }

        close(reason) {
            this.connected = false;
            this.activeResponse = null;
            this.closeReasons.push(reason);
        }

        beginResponse(response) {
            this.activeResponse = { ...response, started: true };
            this.options.onEvent({ type: 'playback_started', ...this.activeResponse });
        }

        finishResponse(reason = 'finished') {
            const response = { ...this.activeResponse };
            this.activeResponse = null;
            this.options.onEvent({ type: 'playback_finished', reason, ...response });
        }

        emit(event) { this.options.onEvent(event); }
        fail(error, stage = 'connection') { this.options.onFailure(error, stage); }
    }

    class FakeProjection {
        constructor(options) { this.options = options; }
        start(sessionId) { this.sessionId = String(sessionId); }
        stop() { this.stopped = true; }
        emit(projection) { this.options.onProjection(projection, { transport: 'test' }); }
    }

    let gate;
    let transport;
    let projection;
    const runtime = new BeanVoiceRuntime({
        request: async (path, options = {}) => {
            requests.push({ path, options: structuredClone(options) });
            if (path === '/assistant/voice/turns') {
                return {
                    turn_id: options.body.turn_id,
                    state: 'awaiting_audio',
                    sideband_ready: true,
                };
            }
            return { accepted: true };
        },
        openProjectionStream: async () => { throw new Error('fake projection owns streaming'); },
        ensureConversationSession: async () => {
            startupOrder.push('session:start');
            return { id: 42 };
        },
        openRealtimeSession: async () => { throw new Error('fake transport owns session setup'); },
        currentWorkspaceId: () => 9,
        acquireMicrophone: async () => {
            startupOrder.push('microphone:start');
            const stream = freshMicrophonePerStart && microphoneAttempts > 0
                ? createRawStream()
                : rawStream;
            microphoneAttempts += 1;
            return stream;
        },
        ...(gestureAudioContext ? {
            localAudioContextFactory: () => {
                startupOrder.push('audio:create');
                const context = {
                    state: 'suspended',
                    resumeCount: 0,
                    closeCount: 0,
                    resume() {
                        startupOrder.push('audio:resume');
                        this.resumeCount += 1;
                        this.state = 'running';
                        return Promise.resolve();
                    },
                    close() {
                        startupOrder.push('audio:close');
                        this.closeCount += 1;
                        this.state = 'closed';
                        return Promise.resolve();
                    },
                };
                audioContexts.push(context);
                return context;
            },
        } : {}),
        localWakeGateFactory: (options) => { gate = new FakeGate(options); return gate; },
        inputTransportFactory: () => ({ append() {}, activate() {}, deactivate() {} }),
        transportFactory: (options) => { transport = new FakeTransport(options); return transport; },
        projectionFactory: (options) => { projection = new FakeProjection(options); return projection; },
        createTurnId: (() => {
            const ids = [
                'browser-voice-test-turn',
                'browser-voice-follow-up-one',
                'browser-voice-follow-up-two',
            ];
            return () => ids.shift() || 'browser-voice-follow-up-extra';
        })(),
        clock: () => 1000,
        timers: {
            setTimeout: (callback) => { const id = nextTimer++; timers.set(id, callback); return id; },
            clearTimeout: (id) => timers.delete(id),
        },
        followUpMs: 1000,
        onViewState: (view) => views.push(view),
        onWorkProjection: (value) => work.push(value),
        onDashboardInvalidated: (value) => invalidations.push(value),
        onFailure: (error, context) => failures.push({ error, context }),
    });

    return {
        runtime,
        requests,
        views,
        work,
        invalidations,
        failures,
        startupOrder,
        audioContexts,
        timers,
        rawTrack: rawTracks[0],
        rawTracks,
        rawStreams,
        get gate() { return gate; },
        get transport() { return transport; },
        get projection() { return projection; },
        runTimers() {
            const callbacks = [...timers.values()];
            timers.clear();
            callbacks.forEach((callback) => callback());
        },
        flush: () => new Promise((resolve) => setImmediate(resolve)),
    };
}

test('[BV2-FIRST-WAKE-01:A-E][BV2-WAKE-01] slow local startup completes wake, admission, and one authorized final', async () => {
    const h = harness({ gateAdmissionReady: false, gestureAudioContext: true });
    let settled = false;
    const startup = h.runtime.start().then((result) => {
        settled = true;
        return result;
    });

    assert.deepEqual(h.startupOrder.slice(0, 5), [
        'audio:create',
        'gate:create',
        'audio:resume',
        'session:start',
        'microphone:start',
    ]);
    assert.equal(h.audioContexts[0].resumeCount, 1);
    assert.equal(h.gate.options.audioContext, h.audioContexts[0]);

    await h.flush();
    assert.equal(h.transport.connected, true, 'Realtime may finish before the local model');
    assert.equal(settled, false);
    assert.equal(h.runtime.snapshot().mode, 'starting');
    h.gate.options.onActivity({ level: 0.9 });
    assert.equal(h.runtime.snapshot().recording, false);
    assert.equal(h.runtime.snapshot().processing, true);
    assert.equal(h.projection.sessionId, undefined);

    h.gate.readyNow();
    assert.equal(await startup, true);
    assert.equal(h.runtime.snapshot().mode, 'wake_only');
    assert.equal(h.runtime.snapshot().activityLevel, 0);
    assert.equal(h.runtime.snapshot().recording, false);
    h.gate.options.onActivity({ level: 0.9 });
    assert.equal(h.runtime.snapshot().recording, true);
    assert.equal(h.gate.isConsumerAdmissionReady(), true);
    assert.equal(h.projection.sessionId, '42');

    const detection = strictDetection();
    assert.equal(await h.gate.options.beforeRelease(detection), true);
    h.gate.options.onDetected(detection);
    h.gate.options.onActivatedPcm({
        generation: detection.generation,
        sourceSequence: detection.sourceSequence,
        sampleRate: 16000,
        samples: new Float32Array(4),
        released: true,
    });
    h.transport.emit({ type: 'input_speech_stopped' });
    h.projection.emit({
        speechAuthorizations: [{
            authorizationId: 'cold-start-final-authorization',
            turnId: 'browser-voice-test-turn',
            speechItemId: 'cold-start-final-speech',
            purpose: 'final',
        }],
        turns: [{
            turnId: 'browser-voice-test-turn',
            state: 'completed',
            closeAfterResponse: true,
        }],
        jobs: [],
        activeJobs: [],
        activeTurns: [],
        events: [],
        dashboardInvalidations: [],
    });
    h.transport.beginResponse({
        responseId: 'cold-start-final-response',
        turnId: 'browser-voice-test-turn',
        speechItemId: 'cold-start-final-speech',
        purpose: 'final',
    });
    await h.flush();
    h.gate.readyNow();
    await h.flush();
    h.transport.finishResponse();

    assert.equal(h.runtime.snapshot().mode, 'wake_only');
    assert.equal(h.transport.appended.length, 1);
    assert.equal(h.transport.authorizations.length, 1);
    await h.runtime.stop('journey_complete');
    assert.equal(h.audioContexts[0].closeCount, 1);
    assert.ok(h.rawTracks[0].stopped >= 1);
});

test('[BV2-FIRST-WAKE-01:A-E][BV2-DIAGNOSTIC-03] failed startup tears down its primed context and retry uses a fresh generation', async () => {
    const h = harness({
        gestureAudioContext: true,
        transportFailures: [Object.assign(new Error('remote description failed'), {
            code: 'realtime_remote_description_failed',
        })],
        freshMicrophonePerStart: true,
    });

    const firstStartup = h.runtime.start();
    const firstGate = h.gate;
    assert.equal(await firstStartup, false);
    assert.equal(h.runtime.snapshot().mode, 'failed');
    assert.equal(firstGate.stopped, true);
    assert.equal(h.audioContexts[0].resumeCount, 1);
    assert.equal(h.audioContexts[0].closeCount, 1);
    assert.ok(h.rawTracks[0].stopped >= 1);
    assert.equal(h.failures.length, 1, 'one startup cause produces one diagnostic');
    assert.equal(h.failures[0].context.stage, 'connection');
    assert.equal(h.failures[0].error.code, 'realtime_remote_description_failed');
    assert.deepEqual(h.requests, [], 'failed negotiation cannot admit a voice turn');
    assert.deepEqual(h.transport.activated, [], 'failed negotiation cannot activate provider input');
    assert.deepEqual(h.transport.appended, [], 'failed negotiation cannot release microphone PCM');

    const secondStartup = h.runtime.start();
    const secondGate = h.gate;
    assert.equal(await secondStartup, true);
    assert.notEqual(secondGate, firstGate);
    assert.notEqual(h.audioContexts[1], h.audioContexts[0]);
    assert.equal(h.audioContexts[1].resumeCount, 1);
    assert.equal(h.audioContexts[1].closeCount, 0);
    assert.equal(h.runtime.snapshot().mode, 'wake_only');
    assert.equal(h.rawTracks.length, 2);
    assert.deepEqual(
        h.transport.connectInputs.map(({ controllerGeneration, providerConnectionGeneration }) => ({
            controllerGeneration,
            providerConnectionGeneration,
        })),
        [
            { controllerGeneration: 1, providerConnectionGeneration: 1 },
            { controllerGeneration: 3, providerConnectionGeneration: 2 },
        ],
    );

    const detection = strictDetection();
    assert.equal(await secondGate.options.beforeRelease(detection), true);
    secondGate.options.onDetected(detection);
    secondGate.options.onActivatedPcm({
        generation: detection.generation,
        sourceSequence: detection.sourceSequence,
        sampleRate: 16000,
        samples: new Float32Array(4),
        released: true,
    });
    assert.equal(h.requests.filter(({ path }) => path === '/assistant/voice/turns').length, 1);
    assert.equal(h.transport.activated.length, 1);
    assert.equal(h.transport.appended.length, 1);

    const resumeIndices = h.startupOrder
        .map((event, index) => (event === 'audio:resume' ? index : -1))
        .filter((index) => index >= 0);
    const sessionIndices = h.startupOrder
        .map((event, index) => (event === 'session:start' ? index : -1))
        .filter((index) => index >= 0);
    assert.equal(resumeIndices.length, 2);
    assert.equal(sessionIndices.length, 2);
    assert.ok(resumeIndices.every((index, attempt) => index < sessionIndices[attempt]));

    await h.runtime.stop('retry_journey_complete');
    assert.equal(h.audioContexts[1].closeCount, 1);
    assert.ok(h.rawTracks[1].stopped >= 1);
});

test('[BV2-FIRST-WAKE-01:A-E][BV2-PRIVACY-PCM-03] stop during cold readiness prevents stale wake-only publication', async () => {
    const h = harness({ gateAdmissionReady: false, gestureAudioContext: true });
    const startup = h.runtime.start();
    const startupGate = h.gate;

    await h.flush();
    assert.equal(h.runtime.snapshot().mode, 'starting');
    assert.equal(h.projection.sessionId, undefined);
    await h.runtime.stop('pagehide');
    startupGate.readyNow();

    assert.equal(await startup, false);
    assert.equal(h.runtime.snapshot().mode, 'off');
    assert.equal(h.runtime.snapshot().recording, false);
    assert.equal(h.projection.sessionId, undefined);
    assert.equal(h.audioContexts[0].closeCount, 1);
    assert.ok(h.rawTracks[0].stopped >= 1);
    assert.equal(
        h.views.slice(h.views.findLastIndex((view) => view.mode === 'off'))
            .some((view) => view.mode === 'wake_only'),
        false,
    );
});

test('[BV-JOURNEY-01] wake to authorized acknowledgement and final stays transcript-free end to end', async () => {
    const h = harness();
    assert.equal(await h.runtime.start(), true);
    assert.equal(h.runtime.snapshot().mode, 'wake_only');
    assert.equal(h.projection.sessionId, '42');
    assert.equal(h.transport.connectInput.context.conversationSessionId, '42');
    assert.equal(h.transport.connectInput.context.workspaceId, 9);

    const detection = {
        generation: 7,
        sourceSequence: 20,
        activation: 'strict_wake',
        keyword: 'HEY_BEAN',
        variant: 'HEY BEAN',
        releaseBoundary: { sourceSequence: 20, sampleOffset: 0, policy: 'post_address_tail' },
    };
    assert.equal(await h.gate.options.beforeRelease(detection), true);
    const admission = h.requests.at(-1);
    assert.equal(admission.path, '/assistant/voice/turns');
    assert.deepEqual(admission.options.body, {
        session_id: 42,
        turn_id: 'browser-voice-test-turn',
        realtime_session_id: '11111111-1111-4111-8111-111111111111',
        controller_generation: 1,
        provider_connection_generation: 1,
        input_generation: 7,
        wake_detected_at_ms: 1000,
        client_milestones: {
            wake_detected_at_ms: 1000,
            pre_admission_started_at_ms: 1000,
        },
        conversation_context: {
            mode: 'new_conversation',
            epoch: 1,
        },
    });
    const admissionKeys = [];
    const collectKeys = (value) => {
        if (!value || typeof value !== 'object') return;
        Object.entries(value).forEach(([key, child]) => {
            admissionKeys.push(key.toLowerCase());
            collectKeys(child);
        });
    };
    collectKeys(admission.options.body);
    for (const forbidden of ['transcript', 'content', 'message', 'text', 'audio', 'raw_audio']) {
        assert.equal(admissionKeys.includes(forbidden), false);
    }
    assert.deepEqual(h.transport.activated, [7]);

    h.gate.options.onDetected(detection);
    h.gate.options.onActivatedPcm({
        generation: 7,
        sourceSequence: 20,
        sampleRate: 16000,
        samples: new Float32Array(4),
        released: true,
    });
    assert.equal(h.runtime.snapshot().mode, 'listening');
    assert.equal(h.transport.appended.length, 1);
    h.transport.emit({ type: 'input_speech_stopped' });
    assert.equal(h.runtime.snapshot().mode, 'thinking');

    const authorization = {
        authorizationId: 'authorization-1',
        turnId: 'browser-voice-test-turn',
        speechItemId: 'speech-ack',
        purpose: 'acknowledgement',
    };
    h.projection.emit({
        speechAuthorizations: [authorization],
        turns: [{ turnId: 'browser-voice-test-turn', state: 'running' }],
        jobs: [{ id: 'job-1', turnId: 'browser-voice-test-turn', label: 'Bean work', status: 'running' }],
        activeJobs: [{ id: 'job-1' }],
        activeTurns: [{ turnId: 'browser-voice-test-turn' }],
        events: [],
        dashboardInvalidations: [{ id: 'tasks:1', resource: 'tasks' }],
    });
    assert.deepEqual(h.transport.authorizations, [authorization]);
    assert.equal(h.runtime.snapshot().mode, 'working');
    assert.equal(h.work.length, 1);
    assert.equal(h.invalidations.length, 1);

    h.transport.beginResponse({
        responseId: 'response-ack',
        turnId: 'browser-voice-test-turn',
        speechItemId: 'speech-ack',
        purpose: 'acknowledgement',
    });
    assert.equal(h.runtime.snapshot().mode, 'speaking');
    h.transport.finishResponse();
    assert.equal(h.runtime.snapshot().mode, 'follow_up');
    await h.flush();
    h.gate.readyNow();
    await h.flush();
    assert.equal(h.gate.contextualOpenGeneration, 8);
    const firstFollowUpAdmission = h.requests.find(({ path, options }) => (
        path === '/assistant/voice/turns'
        && options.body.turn_id === 'browser-voice-follow-up-one'
    ));
    assert.deepEqual(firstFollowUpAdmission.options.body.conversation_context, {
        mode: 'contextual_follow_up',
        epoch: 1,
    });
    assert.equal(firstFollowUpAdmission.options.body.input_generation, 8);
    h.projection.emit({
        speechAuthorizations: [],
        turns: [{ turnId: 'browser-voice-follow-up-one', state: 'awaiting_audio' }],
        jobs: [{ id: 'background-job', turnId: 'browser-voice-test-turn', status: 'running' }],
        activeJobs: [{ id: 'background-job' }],
        activeTurns: [{ turnId: 'browser-voice-follow-up-one', state: 'awaiting_audio' }],
        events: [],
        dashboardInvalidations: [],
    });
    assert.equal(h.runtime.snapshot().mode, 'follow_up');

    const admissionsBeforeFinal = h.requests.filter(({ path }) => path === '/assistant/voice/turns').length;
    const resetCountBeforeFinal = h.gate.resetCount;

    h.transport.beginResponse({
        responseId: 'response-final',
        turnId: 'browser-voice-test-turn',
        speechItemId: 'speech-final',
        purpose: 'final',
    });
    h.transport.finishResponse();
    await h.flush();
    assert.equal(
        h.requests.filter(({ path }) => path === '/assistant/voice/turns').length,
        admissionsBeforeFinal,
        'acknowledgement and final must reuse one pending contextual admission',
    );
    assert.equal(h.gate.resetCount, resetCountBeforeFinal);
    assert.ok(h.requests.some(({ path, options }) => (
        path.endsWith('/delivery') && options.body.event === 'acknowledgement_started'
    )));
    assert.ok(h.requests.some(({ path, options }) => (
        path.endsWith('/delivery') && options.body.event === 'final_audio_started'
    )));
    assert.ok(h.views.every((view) => !('transcript' in view) && !('content' in view) && !('text' in view)));

    h.runTimers();
    assert.equal(h.runtime.snapshot().mode, 'wake_only');
    assert.equal(h.gate.resetCount, 2);
    const expiration = h.requests.find(({ path, options }) => (
        path === '/assistant/voice/cancellations'
        && options.body.turn_id === 'browser-voice-follow-up-one'
    ));
    assert.equal(expiration.options.body.reason, 'contextual_capture_expired');
    await h.runtime.stop('test_complete');
    assert.equal(h.runtime.snapshot().mode, 'off');
    assert.equal(h.gate.stopped, true);
    assert.ok(h.rawTrack.stopped >= 1);
});

test('[BV-JOURNEY-02] sideband-not-ready admission releases no PCM, cleans up, and a later wake retries', async () => {
    const h = harness();
    await h.runtime.start();
    const originalRequest = h.runtime.request;
    let rejectAdmission = true;
    h.runtime.request = async (path, options) => {
        if (path === '/assistant/voice/turns' && rejectAdmission) {
            rejectAdmission = false;
            return { turn_id: options.body.turn_id, sideband_ready: false };
        }
        return originalRequest(path, options);
    };
    const detection = {
        generation: 7,
        sourceSequence: 2,
        activation: 'strict_wake',
        keyword: 'HEY_BEAN',
        variant: 'HEY BEAN',
        releaseBoundary: { sourceSequence: 2, sampleOffset: 0, policy: 'post_address_tail' },
    };
    assert.equal(await h.gate.options.beforeRelease(detection), false);
    h.gate.options.onReleaseRejected(detection);
    assert.equal(h.runtime.snapshot().mode, 'wake_only');
    assert.deepEqual(h.transport.activated, []);
    assert.equal(h.transport.appended.length, 0);
    assert.ok(h.requests.some(({ path, options }) => (
        path === '/assistant/voice/cancellations'
        && options.body.turn_id === 'browser-voice-test-turn'
        && options.body.reason === 'admission_not_ready'
    )));
    assert.equal(h.failures.at(-1).context.stage, 'admission');

    h.gate.generation = 8;
    const retry = { ...detection, generation: 8, sourceSequence: 3 };
    assert.equal(await h.gate.options.beforeRelease(retry), true);
    h.gate.options.onDetected(retry);
    assert.deepEqual(h.transport.activated, [8]);
    const retryAdmission = h.requests.filter(({ path }) => path === '/assistant/voice/turns').at(-1);
    assert.equal(retryAdmission.options.body.conversation_context.epoch, 1);
    assert.equal(h.runtime.snapshot().error, '');
});

test('[BV-JOURNEY-03] clarification reopens the same stable turn with a fresh input generation', async () => {
    const h = harness();
    await h.runtime.start();
    const detection = {
        generation: 7,
        sourceSequence: 3,
        activation: 'strict_wake',
        keyword: 'HEY_BEAN',
        variant: 'HEY BEAN',
        releaseBoundary: { sourceSequence: 3, sampleOffset: 0, policy: 'post_address_tail' },
    };
    assert.equal(await h.gate.options.beforeRelease(detection), true);
    h.gate.options.onDetected(detection);

    h.transport.beginResponse({
        responseId: 'response-clarification',
        turnId: 'browser-voice-test-turn',
        speechItemId: 'speech-clarification',
        purpose: 'clarification',
    });
    h.transport.finishResponse();
    await h.flush();
    h.gate.readyNow();
    await h.flush();

    const admissions = h.requests.filter(({ path }) => path === '/assistant/voice/turns');
    assert.equal(admissions.length, 2);
    assert.equal(admissions[0].options.body.turn_id, 'browser-voice-test-turn');
    assert.equal(admissions[0].options.body.input_generation, 7);
    assert.deepEqual(admissions[0].options.body.conversation_context, {
        mode: 'new_conversation',
        epoch: 1,
    });
    assert.equal(admissions[1].options.body.turn_id, 'browser-voice-test-turn');
    assert.equal(admissions[1].options.body.input_generation, 8);
    assert.deepEqual(admissions[1].options.body.conversation_context, {
        mode: 'contextual_follow_up',
        epoch: 1,
    });
    assert.equal(h.requests.some(({ path }) => path.includes('/clarifications')), false);
    assert.deepEqual(h.transport.activated, [7, 8]);
    assert.equal(h.gate.contextualOpenGeneration, 8);
    assert.equal(h.runtime.snapshot().mode, 'follow_up');

    h.transport.emit({ type: 'input_speech_started', providerItemId: 'clarification-answer' });
    assert.equal(h.runtime.snapshot().mode, 'listening');
    h.transport.emit({ type: 'input_speech_stopped', providerItemId: 'clarification-answer' });
    assert.equal(h.runtime.snapshot().mode, 'thinking');
    h.transport.emit({ type: 'input_committed', providerItemId: 'clarification-answer' });
    assert.equal(h.gate.closeCount, 1);
    assert.equal(h.transport.deactivated, true);
    h.runTimers();
    assert.equal(
        h.requests.some(({ path, options }) => (
            path === '/assistant/voice/cancellations'
            && options.body.turn_id === 'browser-voice-test-turn'
        )),
        false,
        'clarification deadline belongs to the existing server-owned turn',
    );
});

test('[BV-STOP-01] physical Stop ends only current playback and returns to wake-only', async () => {
    const h = harness();
    await h.runtime.start();
    const detection = {
        generation: 7,
        sourceSequence: 4,
        activation: 'strict_wake',
        keyword: 'HEY_BEAN',
        variant: 'HEY BEAN',
        releaseBoundary: { sourceSequence: 4, sampleOffset: 0, policy: 'post_address_tail' },
    };
    await h.gate.options.beforeRelease(detection);
    h.gate.options.onDetected(detection);
    h.transport.beginResponse({
        responseId: 'response-final',
        turnId: 'browser-voice-test-turn',
        speechItemId: 'speech-final',
        purpose: 'final',
    });
    assert.equal(h.runtime.stopPlayback('button_stop'), true);
    assert.equal(h.runtime.snapshot().mode, 'wake_only');
    assert.equal(h.requests.some(({ path, options }) => (
        path === '/assistant/voice/cancellations'
        && options.body.turn_id === 'browser-voice-test-turn'
    )), false, 'physical Stop must not cancel the accepted request or its work');
});

test('[BV-STOP-01] physical Stop preserves an explicit clarification window', async () => {
    const h = harness();
    await h.runtime.start();
    const detection = strictDetection();
    await h.gate.options.beforeRelease(detection);
    h.gate.options.onDetected(detection);
    h.transport.beginResponse({
        responseId: 'response-clarification',
        turnId: 'browser-voice-test-turn',
        speechItemId: 'speech-clarification',
        purpose: 'clarification',
    });
    assert.equal(h.runtime.stopPlayback('button_stop'), true);
    await h.flush();
    h.gate.readyNow();
    await h.flush();
    assert.equal(h.runtime.snapshot().mode, 'follow_up');
    assert.equal(h.gate.contextualOpenGeneration, 8);
    assert.equal(h.requests.filter(({ path, options }) => (
        path === '/assistant/voice/turns'
        && options.body.turn_id === 'browser-voice-test-turn'
    )).length, 2);
    assert.equal(h.requests.some(({ path }) => path === '/assistant/voice/cancellations'), false);
});

test('[BV-BARGE-04] completed direct follow-up confirms barge before its final can overlap old audio', async () => {
    const h = harness();
    await h.runtime.start();
    const detection = strictDetection();
    await h.gate.options.beforeRelease(detection);
    h.gate.options.onDetected(detection);
    h.transport.beginResponse({
        responseId: 'response-old',
        turnId: 'browser-voice-test-turn',
        speechItemId: 'speech-old',
        purpose: 'acknowledgement',
    });
    await h.flush();
    h.gate.readyNow();
    await h.flush();

    h.transport.emit({ type: 'input_speech_started', providerItemId: 'follow-up-input' });
    assert.equal(h.transport.ducked, true);
    h.projection.emit({
        speechAuthorizations: [],
        turns: [{ turnId: 'browser-voice-follow-up-one', state: 'completed' }],
        jobs: [],
        activeJobs: [],
        activeTurns: [],
        events: [],
        dashboardInvalidations: [],
    });
    assert.equal(h.transport.snapshot().activeResponse, null);
    assert.equal(h.runtime.snapshot().mode, 'thinking');
    assert.ok(h.requests.some(({ path, options }) => (
        path.endsWith('/delivery')
        && options.body.event === 'interruption_confirmed'
    )));

    h.transport.beginResponse({
        responseId: 'response-follow-up-final',
        turnId: 'browser-voice-follow-up-one',
        speechItemId: 'speech-follow-up-final',
        purpose: 'final',
    });
    assert.equal(h.runtime.snapshot().mode, 'speaking');
});

test('[BV-BARGE-04] rejected fast follow-up restores old audio without replay or cancellation', async () => {
    const h = harness();
    await h.runtime.start();
    const detection = strictDetection();
    await h.gate.options.beforeRelease(detection);
    h.gate.options.onDetected(detection);
    h.transport.beginResponse({
        responseId: 'response-old',
        turnId: 'browser-voice-test-turn',
        speechItemId: 'speech-old',
        purpose: 'final',
    });
    await h.flush();
    h.gate.readyNow();
    await h.flush();
    h.transport.emit({ type: 'input_speech_started', providerItemId: 'noise-input' });
    h.projection.emit({
        speechAuthorizations: [],
        turns: [{ turnId: 'browser-voice-follow-up-one', state: 'failed' }],
        jobs: [],
        activeJobs: [],
        activeTurns: [],
        events: [],
        dashboardInvalidations: [],
    });
    assert.equal(h.transport.snapshot().activeResponse.responseId, 'response-old');
    assert.equal(h.transport.ducked, false);
    assert.equal(h.runtime.snapshot().mode, 'speaking');
    assert.ok(h.requests.some(({ path, options }) => (
        path.endsWith('/delivery')
        && options.body.event === 'interruption_rejected'
    )));
    assert.equal(h.requests.some(({ path }) => path === '/assistant/voice/cancellations'), false);
});

test('[BV-STOP-02] spoken Stop acknowledges its directive and does not suppress the Stop turn final', async () => {
    const h = harness();
    await h.runtime.start();
    const detection = strictDetection();
    await h.gate.options.beforeRelease(detection);
    h.gate.options.onDetected(detection);
    h.transport.beginResponse({
        responseId: 'response-old',
        turnId: 'browser-voice-test-turn',
        speechItemId: 'speech-old',
        purpose: 'final',
    });
    await h.flush();
    h.projection.emit({
        speechAuthorizations: [],
        turns: [{
            turnId: 'browser-voice-spoken-stop',
            state: 'running',
            stopPlayback: true,
            stopPlaybackDirectiveId: 'browser-voice-spoken-stop:playback-stop:9',
        }],
        jobs: [{ id: 9, turnId: 'browser-voice-spoken-stop', status: 'running' }],
        activeJobs: [{ id: 9 }],
        activeTurns: [{ turnId: 'browser-voice-spoken-stop' }],
        events: [],
        dashboardInvalidations: [],
    });
    await h.flush();
    const directiveReceipt = h.requests.find(({ path, options }) => (
        path === '/assistant/voice/turns/browser-voice-spoken-stop/delivery'
        && options.body.event === 'playback_stopped'
    ));
    assert.equal(
        directiveReceipt.options.body.timing.directive_id,
        'browser-voice-spoken-stop:playback-stop:9',
    );
    assert.equal(h.requests.some(({ path, options }) => (
        path === '/assistant/voice/cancellations'
        && options.body.turn_id === 'browser-voice-spoken-stop'
    )), false);

    const finalAuthorization = {
        authorizationId: 'stop-final-authorization',
        turnId: 'browser-voice-spoken-stop',
        speechItemId: 'stop-final-speech',
        purpose: 'final',
    };
    h.projection.emit({
        speechAuthorizations: [finalAuthorization],
        turns: [{ turnId: 'browser-voice-spoken-stop', state: 'completed' }],
        jobs: [],
        activeJobs: [],
        activeTurns: [],
        events: [],
        dashboardInvalidations: [],
    });
    assert.deepEqual(h.transport.authorizations.at(-1), finalAuthorization);
    h.transport.beginResponse({
        responseId: 'response-stop-final',
        turnId: 'browser-voice-spoken-stop',
        speechItemId: 'stop-final-speech',
        purpose: 'final',
    });
    assert.equal(h.runtime.snapshot().mode, 'speaking');
});

test('[BV-STOP-02] spoken Stop acknowledges a no-op directive when no audio is playing', async () => {
    const h = harness();
    await h.runtime.start();
    h.projection.emit({
        speechAuthorizations: [],
        turns: [{
            turnId: 'browser-voice-spoken-stop',
            state: 'running',
            stopPlayback: true,
            stopPlaybackDirectiveId: 'browser-voice-spoken-stop:playback-stop:10',
        }],
        jobs: [],
        activeJobs: [],
        activeTurns: [{ turnId: 'browser-voice-spoken-stop', state: 'running' }],
        events: [],
        dashboardInvalidations: [],
    });
    await h.flush();
    const directiveReceipt = h.requests.find(({ path, options }) => (
        path === '/assistant/voice/turns/browser-voice-spoken-stop/delivery'
        && options.body.event === 'playback_stopped'
    ));
    assert.equal(directiveReceipt.options.body.timing.speech_item_id, undefined);
    assert.equal(
        directiveReceipt.options.body.timing.directive_id,
        'browser-voice-spoken-stop:playback-stop:10',
    );
});

test('[BV-RELOAD-01] teardown closes every media path and only cleans an unused follow-up admission', async () => {
    const h = harness();
    await h.runtime.start();
    const detection = strictDetection();
    await h.gate.options.beforeRelease(detection);
    h.gate.options.onDetected(detection);
    h.transport.beginResponse({
        responseId: 'response-final',
        turnId: 'browser-voice-test-turn',
        speechItemId: 'speech-final',
        purpose: 'final',
    });
    await h.flush();
    await h.runtime.stop('pagehide');
    assert.equal(h.runtime.snapshot().mode, 'off');
    assert.equal(h.gate.stopped, true);
    assert.ok(h.rawTrack.stopped >= 1);
    assert.equal(h.transport.closeReasons.at(-1), 'pagehide');
    assert.ok(h.requests.some(({ path, options }) => (
        path === '/assistant/voice/cancellations'
        && options.body.turn_id === 'browser-voice-follow-up-one'
    )));
    assert.equal(h.requests.some(({ path, options }) => (
        path === '/assistant/voice/cancellations'
        && options.body.turn_id === 'browser-voice-test-turn'
    )), false);
});

test('[BV-FOLLOWUP-01] a Hermes natural-closing directive ends after final playback', async () => {
    const h = harness();
    await h.runtime.start();
    const detection = strictDetection();
    await h.gate.options.beforeRelease(detection);
    h.gate.options.onDetected(detection);
    h.projection.emit({
        speechAuthorizations: [],
        turns: [{
            turnId: 'browser-voice-test-turn',
            state: 'completed',
            closeAfterResponse: true,
        }],
        jobs: [],
        activeJobs: [],
        activeTurns: [],
        events: [],
        dashboardInvalidations: [],
    });
    h.transport.beginResponse({
        responseId: 'response-closing',
        turnId: 'browser-voice-test-turn',
        speechItemId: 'speech-closing',
        purpose: 'final',
    });
    await h.flush();
    h.gate.readyNow();
    await h.flush();
    h.transport.finishResponse();
    assert.equal(h.runtime.snapshot().mode, 'wake_only');
    assert.ok(h.requests.some(({ path, options }) => (
        path === '/assistant/voice/cancellations'
        && options.body.turn_id === 'browser-voice-follow-up-one'
        && options.body.reason === 'conversation_closed'
    )));
});

test('[BV-FAILURE-01] terminal Realtime loss cannot leave listening or thinking stuck', async () => {
    const h = harness();
    await h.runtime.start();
    h.transport.fail(Object.assign(new Error('data channel closed'), {
        code: 'realtime_data_channel_closed',
    }), 'connection');
    assert.equal(h.runtime.snapshot().mode, 'failed');
    assert.equal(h.runtime.snapshot().enabled, false);
    assert.match(h.runtime.snapshot().error, /Tap Bean to try again/);
    assert.equal(h.transport.closeReasons.at(-1), 'connection_failed');
    assert.equal(h.failures.at(-1).context.stage, 'connection');

    assert.equal(await h.runtime.start(), true);
    assert.equal(h.runtime.snapshot().mode, 'wake_only');
    assert.equal(h.runtime.snapshot().error, '');
});

test('[BV-DIAGNOSTIC-03] playback failure is visible while the same durable response retries', async () => {
    const h = harness();
    await h.runtime.start();

    h.transport.fail(Object.assign(new Error('authorization arrived too late'), {
        code: 'speech_item_not_authorized',
    }), 'playback');
    assert.equal(h.runtime.snapshot().enabled, true);
    assert.match(h.runtime.snapshot().error, /playback problem.*retrying/i);
    assert.equal(h.failures.at(-1).context.stage, 'playback');

    h.transport.beginResponse({
        responseId: 'response-retry',
        turnId: 'browser-voice-test-turn',
        speechItemId: 'speech-final-retry-1',
        purpose: 'final',
    });
    assert.equal(h.runtime.snapshot().mode, 'speaking');
    assert.equal(h.runtime.snapshot().error, '');
});

test('[BV-DIAGNOSTIC-03] a server-terminalized capture cannot leave the microphone journey stuck', async () => {
    const h = harness();
    await h.runtime.start();
    const detection = strictDetection();
    await h.gate.options.beforeRelease(detection);
    h.gate.options.onDetected(detection);
    assert.equal(h.runtime.snapshot().mode, 'listening');

    h.projection.emit({
        speechAuthorizations: [],
        turns: [{ turnId: 'browser-voice-test-turn', state: 'failed' }],
        jobs: [],
        activeJobs: [],
        activeTurns: [],
        events: [],
        dashboardInvalidations: [],
    });

    assert.equal(h.runtime.snapshot().mode, 'wake_only');
    assert.match(h.runtime.snapshot().error, /couldn.t finish/i);
    assert.equal(h.gate.closeCount, 1);
    assert.equal(h.transport.deactivated, true);
});

test('[BV-FOLLOWUP-01] terminal speculative follow-up cannot reopen after current playback', async () => {
    const h = harness();
    await h.runtime.start();
    const detection = strictDetection();
    await h.gate.options.beforeRelease(detection);
    h.gate.options.onDetected(detection);
    h.transport.beginResponse({
        responseId: 'response-current',
        turnId: 'browser-voice-test-turn',
        speechItemId: 'speech-current',
        purpose: 'final',
    });
    await h.flush();
    h.gate.readyNow();
    await h.flush();

    h.projection.emit({
        speechAuthorizations: [],
        turns: [{ turnId: 'browser-voice-follow-up-one', state: 'canceled' }],
        jobs: [],
        activeJobs: [],
        activeTurns: [],
        events: [],
        dashboardInvalidations: [],
    });
    h.transport.finishResponse();

    assert.equal(h.runtime.snapshot().mode, 'wake_only');
    assert.equal(h.transport.deactivated, true);
    assert.ok(h.gate.closeCount >= 1);
});
