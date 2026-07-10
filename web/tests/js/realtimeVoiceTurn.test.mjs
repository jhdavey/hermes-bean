import assert from 'node:assert/strict';
import test from 'node:test';

import {
    RealtimeCallDeduper,
    RealtimeResponseLifecycle,
    buildRealtimeResponseEvent,
    canQueueRealtimeFollowUp,
    realtimeFollowUpExpiry,
    shouldDeferAssistantMessage,
} from '../../resources/js/heybean/realtimeVoiceTurn.js';

test('explicit realtime speech responses cannot invoke app tools', () => {
    assert.deepEqual(buildRealtimeResponseEvent('Checking your calendar.'), {
        type: 'response.create',
        response: {
            instructions: 'Checking your calendar.',
            tool_choice: 'none',
        },
    });
});

test('repeated transcript items and function calls are claimed once', () => {
    const deduper = new RealtimeCallDeduper();

    assert.equal(deduper.claimTranscript('input-1'), true);
    assert.equal(deduper.claimTranscript('input-1'), false);
    assert.equal(deduper.claimToolCall('call-1'), true);
    assert.equal(deduper.claimToolCall('call-1'), false);

    deduper.reset();
    assert.equal(deduper.claimTranscript('input-1'), true);
    assert.equal(deduper.claimToolCall('call-1'), true);
});

test('deferred voice answers reject hidden bridge messages', () => {
    const hiddenBridge = { role: 'assistant', content: 'Working', metadata: { runtime: 'direct_queue_bridge' } };
    const finalAnswer = { role: 'assistant', content: 'You have two events today.', metadata: {} };
    const staysOut = (message) => message.metadata?.runtime === 'direct_queue_bridge';

    assert.equal(shouldDeferAssistantMessage(hiddenBridge, hiddenBridge.content, staysOut), false);
    assert.equal(shouldDeferAssistantMessage(finalAnswer, finalAnswer.content, staysOut), true);
});

test('follow-ups can remain queued while the active voice turn is working', () => {
    assert.equal(canQueueRealtimeFollowUp({ content: 'Actually, only work events', wakeActivated: true, followUpActive: false, turnActive: true }), true);
    assert.equal(canQueueRealtimeFollowUp({ content: 'Background noise', wakeActivated: false, followUpActive: false, turnActive: false }), false);
});

test('the follow-up window remains open through a long first answer', () => {
    const playbackSignalAt = 1_000;
    assert.ok(realtimeFollowUpExpiry(playbackSignalAt) > playbackSignalAt + 45_000);
});

test('a stale cancelled response cannot complete the next spoken response', async () => {
    const lifecycle = new RealtimeResponseLifecycle();
    lifecycle.begin('first');
    lifecycle.bindResponse('response-1');
    lifecycle.cancel();

    let resolved = false;
    const next = lifecycle.begin('second').then((result) => {
        resolved = true;
        return result;
    });

    assert.equal(lifecycle.finish('response-1'), null);
    await Promise.resolve();
    assert.equal(resolved, false);

    lifecycle.bindResponse('response-2');
    lifecycle.captureTranscript('Second answer');
    lifecycle.finish('response-2');
    assert.deepEqual(await next, { purpose: 'second', transcript: 'Second answer', cancelled: false });
});

test('a spoken response completes only after its audio buffer finishes playing', async () => {
    const lifecycle = new RealtimeResponseLifecycle();
    let completed = false;
    const completion = lifecycle.begin('final').then((result) => {
        completed = true;
        return result;
    });

    lifecycle.bindResponse('response-voice');
    lifecycle.markAudioStarted('response-voice');
    lifecycle.captureTranscript('You have two events today.');
    lifecycle.markResponseDone('response-voice');
    await Promise.resolve();
    assert.equal(completed, false);

    lifecycle.markAudioStopped('response-voice');
    assert.deepEqual(await completion, {
        purpose: 'final',
        transcript: 'You have two events today.',
        cancelled: false,
    });
});
