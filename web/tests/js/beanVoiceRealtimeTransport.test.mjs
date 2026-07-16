import assert from 'node:assert/strict';
import test from 'node:test';

import {
    BeanVoiceRealtimeTransport,
    beanVoiceResponseAuthorizationFailure,
} from '../../resources/js/heybean/beanVoiceRealtimeTransport.js';

const sessionId = '11111111-1111-4111-8111-111111111111';
const capability = 'playback-capability-1';
const approvedHash = 'a'.repeat(64);

function createTransport() {
    const sent = [];
    const events = [];
    const failures = [];
    const audio = {
        autoplay: false,
        muted: true,
        volume: 0,
        playCount: 0,
        play() { this.playCount += 1; return Promise.resolve(); },
        pause() {},
    };
    const transport = new BeanVoiceRealtimeTransport({
        openSession: async () => { throw new Error('not used'); },
        inputTransport: { append() {}, deactivate() {} },
        audioFactory: () => audio,
        onEvent: (event) => events.push(event),
        onFailure: (error, stage) => failures.push({ error, stage }),
    });
    transport.prime();
    transport.dataChannel = {
        readyState: 'open',
        send: (payload) => sent.push(JSON.parse(payload)),
    };
    transport.connected = true;
    transport.realtimeSessionId = sessionId;
    transport.playbackCapability = capability;
    transport.controllerGeneration = 4;
    transport.providerConnectionGeneration = 8;
    return { transport, sent, events, failures, audio };
}

function authorization(overrides = {}) {
    return {
        authorization_id: 'authorization-1',
        turn_id: 'turn-1',
        speech_item_id: 'speech-1',
        purpose: 'final',
        realtime_session_id: sessionId,
        controller_generation: 4,
        provider_connection_generation: 8,
        approved_text_sha256: approvedHash,
        playback_capability: capability,
        expires_at: '2099-01-01T00:00:00.000Z',
        ...overrides,
    };
}

function responseCreated(overrides = {}) {
    return {
        type: 'response.created',
        response: {
            id: 'response-1',
            metadata: {
                authorization_id: 'authorization-1',
                turn_id: 'turn-1',
                speech_item_id: 'speech-1',
                purpose: 'final',
                realtime_session_id: sessionId,
                controller_generation: 4,
                provider_connection_generation: 8,
                approved_text_sha256: approvedHash,
                playback_capability: capability,
                ...overrides,
            },
        },
    };
}

test('[BV-PLAYBACK-01] browser cannot create responses or return provider tool output', () => {
    const { transport, sent, events } = createTransport();
    assert.equal(transport.sendInputEvent({ type: 'response.create' }), false);
    assert.equal(transport.sendInputEvent({
        type: 'conversation.item.create',
        item: { type: 'function_call_output' },
    }), false);
    assert.equal(transport.sendInputEvent({ type: 'input_audio_buffer.clear' }), true);
    assert.deepEqual(sent, [{ type: 'input_audio_buffer.clear' }]);

    transport.handleProviderEvent({ type: 'response.function_call_arguments.done' });
    transport.handleProviderEvent({
        type: 'conversation.item.input_audio_transcription.completed',
        transcript: 'private speech must not reach the browser runtime',
    });
    assert.deepEqual(events, [{
        type: 'provider_tool_ignored',
        providerEventType: 'response.function_call_arguments.done',
    }]);
});

test('[BV-PLAYBACK-02] unbound or mismatched remote audio is cancelled and stays muted', () => {
    const { transport, sent, failures, audio } = createTransport();
    transport.handleProviderEvent(responseCreated());
    assert.equal(audio.muted, true);
    assert.equal(audio.volume, 0);
    assert.deepEqual(sent, [
        { type: 'response.cancel', response_id: 'response-1' },
        { type: 'output_audio_buffer.clear' },
    ]);
    assert.equal(failures.length, 1);
    assert.equal(failures[0].stage, 'playback');
    assert.equal(failures[0].error.code, 'speech_item_not_authorized');
});

test('[BV-PLAYBACK-02A] sideband no-audio semantic responses are observed without cancellation', () => {
    const { transport, sent, events, failures, audio } = createTransport();
    transport.handleProviderEvent(responseCreated({
        purpose: 'semantic_plan',
        speech_item_id: '',
        authorization_id: '',
    }));
    assert.deepEqual(sent, []);
    assert.equal(failures.length, 0);
    assert.equal(audio.muted, true);
    assert.deepEqual(events.at(-1), {
        type: 'control_response_ignored',
        purpose: 'semantic_plan',
        responseId: 'response-1',
    });

    transport.handleProviderEvent(responseCreated({
        purpose: 'unknown_audio_response',
        speech_item_id: 'rogue-speech',
    }));
    assert.deepEqual(sent, [
        { type: 'response.cancel', response_id: 'response-1' },
        { type: 'output_audio_buffer.clear' },
    ]);
    assert.equal(failures.length, 1);
});

test('[BV-PLAYBACK-03] one exact Laravel authorization makes one bound response audible', () => {
    const { transport, sent, failures, events, audio } = createTransport();
    assert.equal(transport.authorizeSpeech(authorization()), true);
    transport.handleProviderEvent(responseCreated());
    assert.equal(audio.muted, false);
    assert.equal(audio.volume, 1);
    assert.equal(transport.snapshot().playbackActive, false);
    assert.equal(events.at(-1).type, 'response_authorized');

    transport.handleProviderEvent({
        type: 'output_audio_buffer.started',
        response_id: 'response-1',
    });
    assert.equal(transport.snapshot().playbackActive, true);
    assert.equal(events.at(-1).type, 'playback_started');
    assert.equal(transport.stopPlayback('button_stop'), true);
    assert.equal(audio.muted, true);
    assert.equal(audio.volume, 0);
    assert.equal(transport.snapshot().activeResponse, null);
    assert.deepEqual(sent.slice(-2), [
        { type: 'response.cancel', response_id: 'response-1' },
        { type: 'output_audio_buffer.clear' },
    ]);
    assert.equal(transport.authorizeSpeech(authorization()), false, 'speech item authorization is one-use');
    transport.handleProviderEvent(responseCreated());
    assert.equal(failures.at(-1).error.code, 'speech_item_replayed');
});

test('[BV-PLAYBACK-04] generation, capability, text hash, and expiry are mandatory bindings', () => {
    const base = {
        responseId: 'response-1',
        authorizationId: 'authorization-1',
        turnId: 'turn-1',
        speechItemId: 'speech-1',
        purpose: 'final',
        realtimeSessionId: sessionId,
        controllerGeneration: 4,
        providerConnectionGeneration: 8,
        approvedTextSha256: approvedHash,
        playbackCapability: capability,
    };
    const normalizedAuthorization = {
        authorizationId: 'authorization-1',
        turnId: 'turn-1',
        speechItemId: 'speech-1',
        purpose: 'final',
        realtimeSessionId: sessionId,
        controllerGeneration: 4,
        providerConnectionGeneration: 8,
        approvedTextSha256: approvedHash,
        playbackCapability: capability,
        expiresAt: '2099-01-01T00:00:00.000Z',
    };
    const validate = (binding = base, auth = normalizedAuthorization) => (
        beanVoiceResponseAuthorizationFailure({
            binding,
            authorization: auth,
            realtimeSessionId: sessionId,
            playbackCapability: capability,
            controllerGeneration: 4,
            providerConnectionGeneration: 8,
            consumedSpeechItemIds: new Set(),
        })
    );
    assert.equal(validate(), null);
    assert.equal(validate({ ...base, controllerGeneration: 3 }), 'controller_generation_mismatch');
    assert.equal(validate({ ...base, playbackCapability: 'wrong' }), 'playback_capability_mismatch');
    assert.equal(validate({ ...base, approvedTextSha256: 'b'.repeat(64) }), 'approved_text_hash_mismatch');
    assert.equal(validate(base, { ...normalizedAuthorization, authorizationId: '' }), 'authorization_id_mismatch');
    assert.equal(validate(base, { ...normalizedAuthorization, expiresAt: '' }), 'speech_authorization_expiry_missing');
    assert.equal(validate(base, { ...normalizedAuthorization, expiresAt: '2000-01-01T00:00:00.000Z' }), 'speech_authorization_expired');
});

test('[BV-PLAYBACK-05] incomplete projected authorizations are rejected before provider binding', () => {
    for (const overrides of [
        { authorization_id: '' },
        { turn_id: '' },
        { approved_text_sha256: '' },
        { approved_text_sha256: 'not-a-sha256' },
        { expires_at: '' },
        { expires_at: '2000-01-01T00:00:00.000Z' },
    ]) {
        const { transport } = createTransport();
        assert.equal(transport.authorizeSpeech(authorization(overrides)), false);
        assert.equal(transport.snapshot().authorizationCount, 0);
    }
});

test('[BV-AUDIO-NATIVE-01] WebRTC negotiates receive-only audio and never receives the raw microphone track', async () => {
    const offerSdp = 'v=0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n';
    const answerSdp = `v=0\r\nm=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\na=ice-pwd:${'a'.repeat(32)}\r\n`;
    const channelListeners = new Map();
    const peerListeners = new Map();
    const channel = {
        readyState: 'open',
        bufferedAmount: 0,
        addEventListener: (type, listener) => channelListeners.set(type, listener),
        send() {},
        close() { channelListeners.get('close')?.(); },
    };
    const peer = {
        connectionState: 'connected',
        localDescription: null,
        transceivers: [],
        addTrackCalls: 0,
        addTrack() { this.addTrackCalls += 1; throw new Error('raw microphone track must never be attached'); },
        addTransceiver(type, options) { this.transceivers.push({ type, options }); },
        createDataChannel(label) { this.channelLabel = label; return channel; },
        addEventListener: (type, listener) => peerListeners.set(type, listener),
        async createOffer() { return { type: 'offer', sdp: offerSdp }; },
        async setLocalDescription(offer) { this.localDescription = offer; },
        async setRemoteDescription(answer) {
            assert.equal(answer.sdp, answerSdp, 'the provider SDP framing must remain byte-for-byte intact');
            assert.ok(answer.sdp.endsWith('\r\n'), 'Chrome requires the final SDP line terminator');
            this.remoteDescription = answer;
        },
        close() {},
    };
    const rawTrack = { stopCalls: 0, stop() { this.stopCalls += 1; } };
    const rawMicrophoneStream = { getTracks: () => [rawTrack] };
    let opened;
    const failures = [];
    const transport = new BeanVoiceRealtimeTransport({
        openSession: async (sdp, context) => {
            opened = { sdp, context };
            return {
                sdp: answerSdp,
                realtime_session_id: sessionId,
                playback_capability: capability,
            };
        },
        inputTransport: { append() {}, activate() { return true; }, deactivate() {} },
        peerConnectionFactory: () => peer,
        audioFactory: () => ({ muted: true, volume: 0, play: () => Promise.resolve(), pause() {} }),
        onFailure: (error, stage) => failures.push({ error, stage }),
    });

    const result = await transport.connect({
        rawMicrophoneStream,
        controllerGeneration: 4,
        providerConnectionGeneration: 8,
        context: { conversationSessionId: '42' },
    });
    assert.equal(result.realtimeSessionId, sessionId);
    assert.deepEqual(opened, {
        sdp: offerSdp,
        context: { conversationSessionId: '42' },
    });
    assert.deepEqual(peer.transceivers, [{ type: 'audio', options: { direction: 'recvonly' } }]);
    assert.equal(peer.addTrackCalls, 0);
    assert.equal(peer.channelLabel, 'oai-events');
    transport.close('test_complete');
    assert.equal(rawTrack.stopCalls, 0, 'the Realtime transport never owns the raw microphone stream');
    assert.deepEqual(failures, [], 'intentional close events are stale before the peer is closed');
});

test('[BV2-FIRST-WAKE-01:A-E][BV2-DIAGNOSTIC-03][BV2-PRIVACY-PCM-03] malformed SDP framing tears down once and a fresh connection succeeds', async () => {
    const validAnswerSdp = `v=0\r\nm=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\na=ice-pwd:${'b'.repeat(32)}\r\n`;
    const invalidAnswerSdp = validAnswerSdp.slice(0, -2);
    const sessionAnswers = [invalidAnswerSdp, validAnswerSdp];
    const peers = [];
    const appended = [];
    const activated = [];
    const failures = [];

    function peerForAttempt(attempt) {
        const channelListeners = new Map();
        const peerListeners = new Map();
        const immediatelyReady = attempt === 1;
        const channel = {
            readyState: immediatelyReady ? 'open' : 'connecting',
            bufferedAmount: 0,
            addEventListener: (type, listener) => channelListeners.set(type, listener),
            send() {},
            closeCount: 0,
            close() {
                this.closeCount += 1;
                this.readyState = 'closed';
                channelListeners.get('close')?.();
            },
        };
        const peer = {
            channel,
            connectionState: immediatelyReady ? 'connected' : 'new',
            localDescription: null,
            closeCount: 0,
            addTransceiver() {},
            createDataChannel() { return channel; },
            addEventListener: (type, listener) => peerListeners.set(type, listener),
            async createOffer() { return { type: 'offer', sdp: 'v=0\r\n' }; },
            async setLocalDescription(offer) { this.localDescription = offer; },
            async setRemoteDescription(answer) {
                this.remoteDescription = answer;
                if (!answer.sdp.endsWith('\r\n')) {
                    throw Object.assign(new Error('native parser detail must not escape'), {
                        name: 'OperationError',
                    });
                }
            },
            close() {
                this.closeCount += 1;
                this.connectionState = 'closed';
                peerListeners.get('connectionstatechange')?.();
            },
        };
        peers.push(peer);
        return peer;
    }

    let attempt = 0;
    const transport = new BeanVoiceRealtimeTransport({
        openSession: async () => ({
            sdp: sessionAnswers[attempt++],
            realtime_session_id: sessionId,
            playback_capability: capability,
        }),
        inputTransport: {
            append: (event) => { appended.push(event); return true; },
            activate: (generation) => { activated.push(generation); return true; },
            deactivate() {},
        },
        peerConnectionFactory: () => peerForAttempt(peers.length),
        audioFactory: () => ({ muted: true, volume: 0, play: () => Promise.resolve(), pause() {} }),
        onFailure: (error, stage) => failures.push({ error, stage }),
    });

    await assert.rejects(
        transport.connect({ controllerGeneration: 1, providerConnectionGeneration: 1 }),
        (error) => error.code === 'realtime_remote_description_failed'
            && error.message === 'Realtime remote description could not be applied.'
            && !error.message.includes('native parser detail'),
    );
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(transport.snapshot().connected, false);
    assert.equal(peers[0].channel.closeCount, 1);
    assert.equal(peers[0].closeCount, 1);
    assert.deepEqual(failures, [], 'initial negotiation reports once through connect rejection');
    assert.deepEqual(appended, [], 'failed startup never releases microphone PCM');
    assert.deepEqual(activated, [], 'failed startup never activates provider input');

    const result = await transport.connect({
        controllerGeneration: 3,
        providerConnectionGeneration: 2,
    });
    assert.equal(result.realtimeSessionId, sessionId);
    assert.equal(transport.snapshot().connected, true);
    assert.equal(peers[1].remoteDescription.sdp, validAnswerSdp);
    assert.deepEqual(appended, []);
    assert.deepEqual(activated, []);
    transport.close('retry_journey_complete');
});

test('[BV2-DIAGNOSTIC-03] a channel close immediately after readiness cannot return a false successful connection', async () => {
    const channelListeners = new Map();
    const peerListeners = new Map();
    const failures = [];
    const channel = {
        readyState: 'connecting',
        bufferedAmount: 0,
        closeCount: 0,
        addEventListener: (type, listener) => channelListeners.set(type, listener),
        send() {},
        close() {
            this.closeCount += 1;
            this.readyState = 'closed';
            channelListeners.get('close')?.();
        },
    };
    const peer = {
        connectionState: 'connected',
        localDescription: null,
        closeCount: 0,
        addTransceiver() {},
        createDataChannel: () => channel,
        addEventListener: (type, listener) => peerListeners.set(type, listener),
        async createOffer() { return { type: 'offer', sdp: 'v=0\r\n' }; },
        async setLocalDescription(offer) { this.localDescription = offer; },
        async setRemoteDescription() {
            channel.readyState = 'open';
            channelListeners.get('open')?.();
            channel.readyState = 'closed';
            channelListeners.get('close')?.();
        },
        close() {
            this.closeCount += 1;
            this.connectionState = 'closed';
            peerListeners.get('connectionstatechange')?.();
        },
    };
    const transport = new BeanVoiceRealtimeTransport({
        openSession: async () => ({
            sdp: 'v=0\r\n',
            realtime_session_id: sessionId,
            playback_capability: capability,
        }),
        inputTransport: { append() {}, deactivate() {} },
        peerConnectionFactory: () => peer,
        audioFactory: () => ({ muted: true, volume: 0, play: () => Promise.resolve(), pause() {} }),
        onFailure: (error, stage) => failures.push({ error, stage }),
    });

    await assert.rejects(
        transport.connect({ controllerGeneration: 1, providerConnectionGeneration: 1 }),
        (error) => error.code === 'realtime_data_channel_closed',
    );
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(transport.snapshot().connected, false);
    assert.equal(channel.closeCount, 1);
    assert.equal(peer.closeCount, 1);
    assert.deepEqual(failures, [], 'negotiation failure has one owner: the connect rejection');
});
