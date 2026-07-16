import assert from 'node:assert/strict';
import test from 'node:test';

import {
    BeanVoiceProjectionStream,
    normalizeBeanVoiceProjection,
    parseBeanVoiceSseChunk,
} from '../../resources/js/heybean/beanVoiceProjectionStream.js';
import { BeanVoiceRealtimeTransport } from '../../resources/js/heybean/beanVoiceRealtimeTransport.js';

function projectionPayload() {
    return {
        cursor: 12,
        transcript: 'private transcript at the root',
        messages: [{ role: 'assistant', content: 'private final answer' }],
        turns: [{
            turn_id: 'turn-1',
            state: 'running',
            version: 3,
            close_after_response: true,
            transcript: 'private turn transcript',
        }],
        jobs: [{
            id: 8,
            turn_id: 'turn-1',
            status: 'running',
            version: 2,
            input: 'private job input',
        }],
        events: [{
            id: 12,
            type: 'speech_authorized',
            turn_id: 'turn-1',
            metadata: {
                authorization_id: 'authorization-1',
                speech_item_id: 'speech-1',
                purpose: 'final',
                realtime_session_id: '11111111-1111-4111-8111-111111111111',
                controller_generation: 4,
                provider_connection_generation: 8,
                approved_text_sha256: 'a'.repeat(64),
                playback_capability: 'capability-1',
                expires_at: '2099-01-01T00:00:00.000Z',
                text: 'private authorized response',
                transcript: 'private event transcript',
            },
        }],
        dashboard_invalidations: [{ id: 'task:8', resource: 'tasks' }],
    };
}

test('[BV-PROJECTION-01] browser projection discards transcripts, messages, and response text', () => {
    const projection = normalizeBeanVoiceProjection(projectionPayload());
    assert.equal(projection.cursor, 12);
    assert.equal(projection.turns[0].turnId, 'turn-1');
    assert.equal(projection.turns[0].closeAfterResponse, true);
    assert.equal(projection.jobs[0].label, 'Bean work');
    assert.equal(projection.events[0].payload.text, undefined);
    assert.equal(projection.events[0].payload.transcript, undefined);
    assert.equal(projection.speechAuthorizations[0].speechItemId, 'speech-1');
    const serialized = JSON.stringify(projection);
    for (const privateValue of [
        'private transcript at the root',
        'private final answer',
        'private turn transcript',
        'private job input',
        'private authorized response',
        'private event transcript',
    ]) {
        assert.doesNotMatch(serialized, new RegExp(privateValue));
    }
});

test('[BV-PLAYBACK-03][BV-PROJECTION-01] safe event metadata cannot replace the complete playback authorization', () => {
    const realtimeSessionId = '11111111-1111-4111-8111-111111111111';
    const playbackCapability = 'playback-capability-1';
    const approvedTextSha256 = 'b'.repeat(64);
    const projection = normalizeBeanVoiceProjection({
        cursor: 13,
        events: [{
            id: 13,
            type: 'speech_authorized',
            turn_id: 'browser-voice-live-regression',
            metadata: {
                authorization_id: 'speech:browser-voice-live-regression:final:1:delivery:1',
                speech_item_id: 'browser-voice-live-regression:final:1:delivery:1',
                purpose: 'final',
                realtime_session_id: realtimeSessionId,
                controller_generation: 1,
                provider_connection_generation: 1,
                transcript: 'private speech must never survive normalization',
            },
        }],
        speech_authorizations: [{
            authorization_id: 'speech:browser-voice-live-regression:final:1:delivery:1',
            turn_id: 'browser-voice-live-regression',
            speech_item_id: 'browser-voice-live-regression:final:1:delivery:1',
            purpose: 'final',
            realtime_session_id: realtimeSessionId,
            controller_generation: 1,
            provider_connection_generation: 1,
            approved_text_sha256: approvedTextSha256,
            playback_capability: playbackCapability,
            expires_at: '2099-01-01T00:00:00.000Z',
            authorized: true,
        }],
    });

    assert.equal(projection.speechAuthorizations.length, 1);
    assert.equal(projection.speechAuthorizations[0].approvedTextSha256, approvedTextSha256);
    assert.equal(projection.speechAuthorizations[0].playbackCapability, playbackCapability);
    assert.equal(projection.speechAuthorizations[0].expiresAt, '2099-01-01T00:00:00.000Z');
    assert.doesNotMatch(JSON.stringify(projection), /private speech/);

    const sent = [];
    const playbackEvents = [];
    const failures = [];
    const audio = {
        autoplay: false,
        muted: true,
        volume: 0,
        play() { return Promise.resolve(); },
        pause() {},
    };
    const transport = new BeanVoiceRealtimeTransport({
        openSession: async () => { throw new Error('not used'); },
        inputTransport: { append() {}, deactivate() {} },
        audioFactory: () => audio,
        onEvent: (event) => playbackEvents.push(event),
        onFailure: (error, stage) => failures.push({ error, stage }),
    });
    transport.prime();
    transport.dataChannel = {
        readyState: 'open',
        send: (payload) => sent.push(JSON.parse(payload)),
    };
    transport.connected = true;
    transport.realtimeSessionId = realtimeSessionId;
    transport.playbackCapability = playbackCapability;
    transport.controllerGeneration = 1;
    transport.providerConnectionGeneration = 1;

    transport.handleProviderEvent({
        type: 'response.created',
        response: {
            id: 'response-live-regression',
            metadata: {
                authorization_id: 'speech:browser-voice-live-regression:final:1:delivery:1',
                turn_id: 'browser-voice-live-regression',
                speech_item_id: 'browser-voice-live-regression:final:1:delivery:1',
                purpose: 'final',
                realtime_session_id: realtimeSessionId,
                controller_generation: 1,
                provider_connection_generation: 1,
                approved_text_sha256: approvedTextSha256,
                playback_capability: playbackCapability,
            },
        },
    });
    assert.equal(transport.snapshot().authorizationPending, true);
    assert.equal(audio.muted, true);
    assert.equal(audio.volume, 0);
    assert.deepEqual(playbackEvents, []);
    assert.deepEqual(sent, []);

    assert.equal(transport.authorizeSpeech(projection.speechAuthorizations[0]), true);
    assert.equal(transport.snapshot().authorizationPending, false);
    transport.handleProviderEvent({
        type: 'output_audio_buffer.started',
        response_id: 'response-live-regression',
    });
    transport.handleProviderEvent({
        type: 'output_audio_buffer.stopped',
        response_id: 'response-live-regression',
    });

    assert.deepEqual(failures, []);
    assert.deepEqual(sent, []);
    assert.deepEqual(
        playbackEvents.map((event) => event.type),
        ['response_authorized', 'playback_started', 'playback_finished'],
    );
    assert.equal(audio.muted, true);
    assert.equal(audio.volume, 0);
    assert.equal(transport.snapshot().activeResponse, null);
});

test('[BV-PROJECTION-02] SSE parser preserves event identity across split chunks', () => {
    const events = [];
    let remainder = parseBeanVoiceSseChunk('', [
        'id: 9',
        'event: voice.projection',
        'data: {"cursor":9,"turns":[{"turn_id":"turn-1",',
    ].join('\n'), (event) => events.push(event));
    assert.equal(events.length, 0);
    remainder = parseBeanVoiceSseChunk(remainder, '"state":"running"}]}\n\n', (event) => events.push(event));
    assert.equal(remainder, '');
    assert.deepEqual(events, [{
        id: '9',
        event: 'voice.projection',
        payload: { cursor: 9, turns: [{ turn_id: 'turn-1', state: 'running' }] },
    }]);
});

test('[BV-PROJECTION-03] SSE and long-poll overlap cannot duplicate lifecycle or playback authority', async () => {
    const projections = [];
    const payload = projectionPayload();
    const sse = 'id: 12\nevent: voice.projection\ndata: '
        + JSON.stringify(payload)
        + '\n\n';
    let stream;
    let streamAttempts = 0;
    stream = new BeanVoiceProjectionStream({
        request: async (path) => {
            assert.match(path, /\/assistant\/voice\/state\?session_id=42&cursor=12&wait=1/);
            return payload;
        },
        openStream: async (path) => {
            streamAttempts += 1;
            if (streamAttempts > 1) throw new Error('projection stream temporarily unavailable');
            assert.match(path, /\/assistant\/voice\/stream\?session_id=42&cursor=0/);
            return new Response(sse, {
                status: 200,
                headers: { 'Content-Type': 'text/event-stream' },
            });
        },
        onProjection: (projection, context) => {
            projections.push({ projection, context });
            if (context.transport === 'poll') stream.stop();
        },
        retryDelayMs: 50,
    });
    stream.start(42);
    for (let index = 0; index < 10 && projections.length < 2; index += 1) {
        await new Promise((resolve) => setImmediate(resolve));
    }

    assert.equal(projections.length, 2);
    assert.equal(projections[0].context.transport, 'sse');
    assert.equal(projections[0].projection.turns.length, 1);
    assert.equal(projections[0].projection.speechAuthorizations.length, 1);
    assert.equal(projections[1].context.transport, 'poll');
    assert.equal(projections[1].projection.turns.length, 0, 'same turn version is centrally deduplicated');
    assert.equal(projections[1].projection.speechAuthorizations.length, 0, 'one-use playback authority is centrally deduplicated');
    assert.equal(projections[1].projection.dashboardInvalidations.length, 0);
});

test('[BV-PROJECTION-04] bounded SSE completion renews normally without false failure or polling', async () => {
    const projections = [];
    const errors = [];
    let streamAttempts = 0;
    let stream;
    stream = new BeanVoiceProjectionStream({
        request: async () => assert.fail('clean bounded SSE renewal must not invoke fallback polling'),
        openStream: async () => {
            streamAttempts += 1;
            return new Response(
                `id: ${streamAttempts}\nevent: voice.projection\ndata: ${JSON.stringify({
                    cursor: streamAttempts,
                    turns: [{ turn_id: 'turn-1', state: 'running', version: streamAttempts }],
                })}\n\n`,
                { status: 200, headers: { 'Content-Type': 'text/event-stream' } },
            );
        },
        onProjection: (projection, context) => {
            projections.push({ projection, context });
            if (projections.length === 2) stream.stop();
        },
        onError: (error) => errors.push(error),
    });
    stream.start(42);
    for (let index = 0; index < 20 && projections.length < 2; index += 1) {
        await new Promise((resolve) => setImmediate(resolve));
    }
    assert.equal(streamAttempts, 2);
    assert.equal(projections.length, 2);
    assert.ok(projections.every(({ context }) => context.transport === 'sse'));
    assert.deepEqual(errors, []);
});

test('[BV-PROJECTION-05] Laravel metadata and nested timing normalize into safe interruption evidence', () => {
    const projection = normalizeBeanVoiceProjection({
        cursor: 19,
        events: [{
            id: 19,
            type: 'interruption_rejected',
            turn_id: 'turn-2',
            metadata: {
                timing: {
                    reason: 'input_rejected',
                    directive_id: 'turn-2:playback-stop:3',
                    speech_item_id: 'speech-2',
                },
                transcript: 'must never survive normalization',
            },
        }],
    });
    assert.deepEqual(projection.events[0].payload, {
        authorization_id: '',
        turn_id: '',
        speech_item_id: 'speech-2',
        purpose: '',
        realtime_session_id: '',
        controller_generation: -1,
        provider_connection_generation: -1,
        approved_text_sha256: '',
        playback_capability: '',
        expires_at: '',
        reason: 'input_rejected',
        directive_id: 'turn-2:playback-stop:3',
    });
    assert.doesNotMatch(JSON.stringify(projection), /must never survive normalization/);
});
