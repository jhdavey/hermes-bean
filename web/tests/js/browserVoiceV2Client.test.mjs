import assert from 'node:assert/strict';
import test from 'node:test';
import {
    BrowserVoiceAdmissionRegistryV2,
    BrowserVoiceV2Client,
    deliverBrowserVoiceV2ReceiptOnce,
    normalizeVoiceV2Snapshot,
    recoverBrowserVoiceV2Admission,
} from '../../resources/js/heybean/browserVoiceV2Client.js';

test('[BV2-ADMISSION-07] newer turns do not supersede recovery for an older stable turn', () => {
    const registry = new BrowserVoiceAdmissionRegistryV2();
    const older = registry.begin('older-turn');
    const newer = registry.begin('newer-turn');

    assert.equal(registry.isCurrent(older), true);
    assert.equal(registry.isCurrent(newer), true);
    assert.equal(registry.finish(newer), true);
    assert.equal(registry.isCurrent(older), true);

    const replacementForOlder = registry.begin('older-turn');
    assert.equal(registry.isCurrent(older), false);
    assert.equal(registry.isCurrent(replacementForOlder), true);
});

test('[BV2-ADMISSION-08] an older ambiguous POST still reconciles after a newer turn begins', async () => {
    const registry = new BrowserVoiceAdmissionRegistryV2();
    const older = registry.begin('ambiguous-older');
    let resolveAdmission;
    const client = {
        admit: () => new Promise((resolve) => { resolveAdmission = resolve; }),
        snapshot: async () => { throw new Error('snapshot should not be needed'); },
    };
    const recovery = recoverBrowserVoiceV2Admission({
        client,
        turnId: older.turnId,
        sessionId: 91,
        admissionInput: { turnId: older.turnId, sessionId: 91, transcript: 'Create the older note.' },
        isCurrent: () => registry.isCurrent(older),
    });

    registry.begin('newer-turn');
    resolveAdmission({ data: { turn: { turn_id: older.turnId, state: 'accepted', version: 1 } } });
    const result = await recovery;
    assert.equal(result.status, 'recovered');
    assert.equal(result.turnId, 'ambiguous-older');
});

test('[BV2-DELIVERY-01] failed final delivery remains retryable and concurrent calls share one POST', async () => {
    const confirmed = new Set();
    const inFlight = new Map();
    let attempts = 0;
    let releaseFirst;
    const firstAttempt = () => new Promise((resolve, reject) => {
        releaseFirst = () => reject(new Error('offline'));
    });

    const first = deliverBrowserVoiceV2ReceiptOnce({
        key: 'delivery-turn',
        confirmed,
        inFlight,
        deliver: () => { attempts += 1; return firstAttempt(); },
    });
    const duplicate = deliverBrowserVoiceV2ReceiptOnce({
        key: 'delivery-turn',
        confirmed,
        inFlight,
        deliver: () => { attempts += 1; },
    });
    assert.equal(first, duplicate);
    await Promise.resolve();
    assert.equal(attempts, 1);
    releaseFirst();
    await assert.rejects(first, /offline/);
    assert.equal(confirmed.has('delivery-turn'), false);
    assert.equal(inFlight.has('delivery-turn'), false);

    assert.equal(await deliverBrowserVoiceV2ReceiptOnce({
        key: 'delivery-turn',
        confirmed,
        inFlight,
        deliver: async () => { attempts += 1; },
    }), true);
    assert.equal(attempts, 2);
    assert.equal(confirmed.has('delivery-turn'), true);
    assert.equal(await deliverBrowserVoiceV2ReceiptOnce({
        key: 'delivery-turn',
        confirmed,
        inFlight,
        deliver: async () => { attempts += 1; },
    }), false);
    assert.equal(attempts, 2);
});

test('[BV2-COMPLETENESS-02] clarification continues the same durable turn through its dedicated boundary', async () => {
    const calls = [];
    const client = new BrowserVoiceV2Client({ request: async (...args) => calls.push(args) });
    await client.clarify({
        sessionId: 41,
        turnId: 'stable-turn',
        answer: 'Salt at 5 p.m.',
        clarificationId: 'stable-turn:clarification:1',
    });
    assert.equal(calls[0][0], '/assistant/voice/turns/stable-turn/clarifications');
    assert.deepEqual(calls[0][1].body, {
        session_id: 41,
        answer: 'Salt at 5 p.m.',
        clarification_id: 'stable-turn:clarification:1',
    });
});

test('admission sends one stable browser turn to the v2 boundary', async () => {
    const calls = [];
    const client = new BrowserVoiceV2Client({
        request: async (...args) => {
            calls.push(args);
            return { turn: { turn_id: 'voice-1', state: 'accepted' } };
        },
    });

    await client.admit({
        turnId: 'voice-1',
        sessionId: 41,
        transcript: 'Create a reminder for four.',
        timezone: 'America/New_York',
        controllerGeneration: 3,
        connectionGeneration: 7,
        transcriptTiming: { completed_at_ms: 1000 },
        conversationContext: { mode: 'contextual_follow_up', epoch: 8 },
    });

    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], '/assistant/voice/turns');
    assert.deepEqual(calls[0][1].body, {
        turn_id: 'voice-1',
        session_id: 41,
        transcript: 'Create a reminder for four.',
        timezone: 'America/New_York',
        location_context: null,
        controller_generation: 3,
        provider_connection_generation: 7,
        transcript_timing: { completed_at_ms: 1000 },
        conversation_context: { mode: 'contextual_follow_up', epoch: 8 },
        client_context: {},
    });
});

test('admission rejects incomplete logical requests before network dispatch', async () => {
    let called = false;
    const client = new BrowserVoiceV2Client({ request: async () => { called = true; } });
    await assert.rejects(() => client.admit({ turnId: 'voice-1', sessionId: 41 }), /requires/);
    assert.equal(called, false);
});

test('[BV2-ADMISSION-02] an accepted request whose POST timed out is recovered with the same stable turn ID', async () => {
    const retryTurnIds = [];
    let snapshotCalls = 0;
    const client = {
        admit: async (input) => {
            retryTurnIds.push(input.turnId);
            throw new Error('response timed out after the server accepted it');
        },
        snapshot: async () => {
            snapshotCalls += 1;
            return {
                data: {
                    turns: [{ turn_id: 'stable-timeout-turn', state: 'accepted', version: 1 }],
                    messages: [{
                        id: 91,
                        turn_id: 'stable-timeout-turn',
                        role: 'user',
                        content: 'Create a reminder for four.',
                    }],
                    cursor: 4,
                },
            };
        },
    };

    const recovery = await recoverBrowserVoiceV2Admission({
        client,
        turnId: 'stable-timeout-turn',
        sessionId: 41,
        admissionInput: {
            turnId: 'stable-timeout-turn',
            sessionId: 41,
            transcript: 'Create a reminder for four.',
        },
    });

    assert.equal(recovery.status, 'recovered');
    assert.equal(recovery.source, 'snapshot');
    assert.equal(recovery.projection.turns[0].turnId, 'stable-timeout-turn');
    assert.deepEqual(retryTurnIds, ['stable-timeout-turn']);
    assert.equal(snapshotCalls, 1);
});

test('[BV2-ADMISSION-03] an absent request terminates at the recovery deadline after reconnect failures', async () => {
    let now = 0;
    let snapshotCalls = 0;
    const client = {
        admit: async () => { throw new Error('offline'); },
        snapshot: async () => {
            snapshotCalls += 1;
            if (snapshotCalls === 1) throw new Error('reconnecting');
            return { data: { turns: [], messages: [], cursor: 0 } };
        },
    };

    const recovery = await recoverBrowserVoiceV2Admission({
        client,
        turnId: 'absent-turn',
        sessionId: 42,
        admissionInput: { turnId: 'absent-turn', sessionId: 42, transcript: 'Make a note.' },
        deadlineMs: 600,
        pollIntervalMs: 200,
        maxSnapshotAttempts: 10,
        clock: () => now,
        sleep: async (delayMs) => { now += delayMs; },
    });

    assert.equal(recovery.status, 'absent');
    assert.equal(recovery.source, 'deadline');
    assert.equal(now, 600);
    assert.equal(snapshotCalls, 3);
});

test('[BV2-ADMISSION-04] a recovery that resolves after a newer turn is stale but retains safe durable evidence', async () => {
    let current = true;
    let resolveRetry;
    const retry = new Promise((resolve) => { resolveRetry = resolve; });
    const client = {
        admit: async () => retry,
        snapshot: async () => { throw new Error('snapshot must not run'); },
    };
    const pending = recoverBrowserVoiceV2Admission({
        client,
        turnId: 'older-turn',
        sessionId: 43,
        admissionInput: { turnId: 'older-turn', sessionId: 43, transcript: 'Create a task.' },
        isCurrent: () => current,
    });

    current = false;
    resolveRetry({ data: { turn: { turn_id: 'older-turn', state: 'accepted', version: 1 } } });
    const recovery = await pending;

    assert.equal(recovery.status, 'stale');
    assert.equal(recovery.projection.turns[0].turnId, 'older-turn');
    assert.equal(recovery.source, 'idempotent_retry');
});

test('snapshot projection deduplicates jobs by version and exposes active work', () => {
    const projection = normalizeVoiceV2Snapshot({
        cursor: 18,
        turns: [{
            turn_id: 'voice-1',
            state: 'running',
            version: 2,
            transcript: 'Make a note.',
            acknowledgement_required: true,
            acknowledgement_text: 'I’ll put that together.',
            jobs: [{ id: 9, turn_id: 'voice-1', status: 'queued', version: 1, label: 'Draft note' }],
        }],
        jobs: [{ id: 9, turn_id: 'voice-1', status: 'running', version: 2, label: 'Draft note' }],
    });

    assert.equal(projection.cursor, 18);
    assert.equal(projection.turns[0].acknowledgementRequired, true);
    assert.equal(projection.jobs.length, 1);
    assert.equal(projection.jobs[0].status, 'running');
    assert.equal(projection.activeTurns.length, 1);
    assert.equal(projection.activeJobs.length, 1);
});

test('[BV2-RELOAD-01] durable final-audio start evidence is distinct from final text delivery', () => {
    const projection = normalizeVoiceV2Snapshot({
        cursor: 22,
        turns: [{
            turn_id: 'heard-turn',
            state: 'completed',
            final_text: 'This was heard.',
            final_delivered_at: '2026-07-11T17:00:00Z',
        }, {
            turn_id: 'unheard-turn',
            state: 'completed',
            final_text: 'This was only rendered.',
            final_delivered_at: '2026-07-11T17:00:01Z',
        }],
        events: [{
            turn_id: 'heard-turn',
            type: 'final_audio_started',
            payload: { purpose: 'final', speech_item_id: 'heard-turn:final' },
        }],
    });

    assert.equal(projection.turns.find((turn) => turn.turnId === 'heard-turn').finalAudioStarted, true);
    assert.equal(projection.turns.find((turn) => turn.turnId === 'unheard-turn').finalAudioStarted, false);
    assert.ok(projection.turns.every((turn) => turn.finalDeliveredAt));
});

test('[BV2-RELOAD-02] a full event page is identified so reload waits for delivery catch-up', () => {
    const projection = normalizeVoiceV2Snapshot({
        turns: [{ turn_id: 'paged-turn', state: 'completed', final_text: 'Paged final.' }],
        events: Array.from({ length: 500 }, (_, index) => ({
            turn_id: 'unrelated-turn',
            type: 'progress',
            sequence: index + 1,
        })),
    });
    assert.equal(projection.eventPageFull, true);
    assert.equal(projection.turns[0].finalAudioStarted, false);
});

test('[BV2-ADMISSION-01] a singular admission projection immediately replaces the optimistic turn', () => {
    const projection = normalizeVoiceV2Snapshot({
        data: {
            turn: {
                turn_id: 'browser-voice-v2-admitted-1',
                state: 'accepted',
                lane: 'app_read',
                handler: 'app.calendar.read',
                version: 1,
                transcript: 'What is on my calendar tomorrow?',
            },
            jobs: [{
                id: 41,
                turn_id: 'browser-voice-v2-admitted-1',
                label: 'Check calendar',
                status: 'queued',
            }],
            messages: [{
                id: 51,
                turn_id: 'browser-voice-v2-admitted-1',
                role: 'user',
                content: 'What is on my calendar tomorrow?',
            }],
            cursor: 9,
        },
    });

    assert.equal(projection.turns.length, 1);
    assert.equal(projection.turns[0].turnId, 'browser-voice-v2-admitted-1');
    assert.equal(projection.activeTurns.length, 1);
    assert.equal(projection.activeJobs.length, 1);
    assert.equal(projection.messages.length, 1);
});

test('polling ignores an old response after stop and a new session starts', async () => {
    const requests = [];
    const timers = [];
    const snapshots = [];
    const client = new BrowserVoiceV2Client({
        request: (path) => new Promise((resolve) => requests.push({ path, resolve })),
        onSnapshot: (snapshot) => snapshots.push(snapshot),
        setTimer: (callback, delay) => {
            timers.push({ callback, delay });
            return timers.length;
        },
        clearTimer: () => {},
    });

    client.start(10);
    assert.equal(requests.length, 1);
    client.start(11);
    assert.equal(requests.length, 2);
    requests[0].resolve({ cursor: 99, turns: [{ turn_id: 'stale', state: 'running' }] });
    await flushPromises();
    assert.equal(snapshots.length, 0);

    requests[1].resolve({ cursor: 3, turns: [{ turn_id: 'current', state: 'accepted' }] });
    await flushPromises();
    assert.equal(snapshots.length, 1);
    assert.equal(snapshots[0].turns[0].turnId, 'current');
    assert.equal(timers.length, 1);
});

test('state polling backs off after failure without applying a stale error', async () => {
    const timers = [];
    const errors = [];
    const client = new BrowserVoiceV2Client({
        request: async () => { throw new Error('offline'); },
        onError: (error, context) => errors.push({ error, context }),
        setTimer: (callback, delay) => {
            timers.push({ callback, delay });
            return timers.length;
        },
        clearTimer: () => {},
        retryDelayMs: 400,
    });

    client.start(22);
    await flushPromises();
    assert.equal(errors.length, 1);
    assert.equal(errors[0].context.failureCount, 1);
    assert.equal(timers[0].delay, 400);

    client.stop();
    timers[0].callback();
    assert.equal(errors.length, 1);
});

test('explicit cancellation requires a target and never acts as playback Stop', async () => {
    const calls = [];
    const client = new BrowserVoiceV2Client({ request: async (...args) => calls.push(args) });
    await assert.rejects(() => client.cancel({ sessionId: 1 }), /requires/);
    await client.cancel({ sessionId: 1, turnId: 'voice-4' });
    assert.equal(calls[0][0], '/assistant/voice/cancellations');
    assert.deepEqual(calls[0][1].body, { session_id: 1, turn_id: 'voice-4' });
});

test('delivery markers are explicit and idempotency-safe turn events', async () => {
    const calls = [];
    const client = new BrowserVoiceV2Client({ request: async (...args) => calls.push(args) });
    await client.markDelivery({
        sessionId: 41,
        turnId: 'voice-4',
        event: 'acknowledgement_started',
        timing: { latency_ms: 420 },
    });
    assert.equal(calls[0][0], '/assistant/voice/turns/voice-4/delivery');
    assert.deepEqual(calls[0][1].body, {
        session_id: 41,
        event: 'acknowledgement_started',
        timing: { latency_ms: 420 },
    });
    await assert.rejects(() => client.markDelivery({ sessionId: 41, turnId: 'voice-4', event: 'audio_blob' }), /supported event/);
});

function flushPromises() {
    return new Promise((resolve) => setImmediate(resolve));
}
