import assert from 'node:assert/strict';
import test from 'node:test';

import {
    REALTIME_CONVERSATION_STATES,
    RealtimeCallDeduper,
    RealtimeConversationController,
    RealtimeInputTranscriptBuffer,
    RealtimeResponseLifecycle,
    RealtimeTurnPersistenceQueue,
    restoreSupersededUserTurn,
    stageOptimisticUserTurn,
    buildRealtimeConversationItemDeleteEvent,
    buildRealtimePlaybackCancellationEvents,
    buildRealtimeResponseEvent,
    buildRealtimeTargetedResponseCancellationEvent,
    cancelRealtimeTurnWithoutBlockingReplacement,
    extractRealtimeResponseTranscript,
    isCompletedRealtimeResponse,
    isBareRealtimeWakePhrase,
    isExplicitRealtimeWorkInterruption,
    isLikelyNonEnglishRealtimeTranscript,
    isRealtimeVoiceStopCommand,
    isRealtimeDuplicateCallConflict,
    isStrictRealtimeWakePhrase,
    isVoiceFillerOnly,
    realtimeFollowUpExpiry,
    realtimeMicrophoneConstraints,
    realtimeNeedsAppRuntime,
    realtimePauseAcknowledgement,
    realtimeWorkStatusAnswer,
    shouldDeferAssistantMessage,
    shouldDisplayRealtimeTranscriptDraft,
    stripRealtimeLocalWakePrefix,
} from '../../resources/js/heybean/realtimeVoiceTurn.js';

globalThis.window ??= { matchMedia: () => null };
const {
    captureHeyBeanChatControlFocus,
    restoreHeyBeanChatControlFocus,
} = await import('../../resources/js/heybean/webApp.js');

function bindCurrentResponse(lifecycle, responseId) {
    return lifecycle.bindResponse(responseId, lifecycle.currentClientResponseId());
}

test('explicit realtime speech responses cannot invoke app tools', () => {
    assert.deepEqual(buildRealtimeResponseEvent('Checking your calendar.'), {
        type: 'response.create',
        response: {
            instructions: 'Checking your calendar.',
            tool_choice: 'none',
        },
    });
});

test('response requests carry lifecycle correlation metadata', () => {
    assert.deepEqual(buildRealtimeResponseEvent('Hello.', { clientResponseId: 'client-response-1' }), {
        type: 'response.create',
        response: {
            instructions: 'Hello.',
            tool_choice: 'none',
            metadata: { heybean_response_id: 'client-response-1' },
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

test('input transcript deltas stream into one draft and finalize per audio item', () => {
    const transcripts = new RealtimeInputTranscriptBuffer();

    assert.equal(transcripts.append({ itemId: 'voice-1', contentIndex: 0, delta: 'Hey' }), 'Hey');
    assert.equal(transcripts.append({ itemId: 'voice-1', contentIndex: 0, delta: ' Bean' }), 'Hey Bean');
    assert.equal(transcripts.append({ itemId: 'voice-2', contentIndex: 0, delta: 'Next' }), 'Next');
    assert.equal(transcripts.complete({ itemId: 'voice-1', contentIndex: 0 }), 'Hey Bean');
    assert.equal(transcripts.complete({ itemId: 'voice-2', contentIndex: 0, transcript: 'Next request.' }), 'Next request.');

    transcripts.append({ itemId: 'voice-3', delta: 'Discard me' });
    transcripts.discard({ itemId: 'voice-3' });
    assert.equal(transcripts.complete({ itemId: 'voice-3' }), '');
});

test('bare wake hallucinations stay out of the live input draft', () => {
    assert.equal(shouldDisplayRealtimeTranscriptDraft('Hey'), false);
    assert.equal(shouldDisplayRealtimeTranscriptDraft('Hey Bean.'), false);
    assert.equal(shouldDisplayRealtimeTranscriptDraft('Hey Bean, what is the weather?'), true);
    assert.equal(isBareRealtimeWakePhrase('Hey Bean.'), true);
    assert.equal(isBareRealtimeWakePhrase('Hey Bean, what is the weather?'), false);
});

test('accepted and terminal persistence for one client turn is serialized', async () => {
    const queue = new RealtimeTurnPersistenceQueue();
    const order = [];
    let releaseAccepted;
    const accepted = queue.enqueue('turn-1', async () => {
        order.push('accepted-start');
        await new Promise((resolve) => { releaseAccepted = resolve; });
        order.push('accepted-finish');
        return 'accepted';
    });
    const terminal = queue.enqueue('turn-1', async () => {
        order.push('terminal-start');
        return 'completed';
    });

    await new Promise((resolve) => setImmediate(resolve));
    assert.deepEqual(order, ['accepted-start']);
    releaseAccepted();
    assert.equal(await accepted, 'accepted');
    assert.equal(await terminal, 'completed');
    assert.deepEqual(order, ['accepted-start', 'accepted-finish', 'terminal-start']);
});

test('a correction replaces its superseded optimistic user row and restores it on failure', () => {
    const original = {
        id: 'server-user-1',
        role: 'user',
        content: 'Set the reminder for five.',
        metadata: { client_request_id: 'voice-five' },
    };
    const staged = stageOptimisticUserTurn([original], {
        content: 'Actually, set it for six.',
        clientRequestId: 'voice-six',
        supersedesClientRequestId: 'voice-five',
        localId: 'local-correction',
    });

    assert.equal(staged.messages.length, 1);
    assert.equal(staged.messages[0].content, 'Actually, set it for six.');
    assert.equal(staged.messages[0].metadata.client_request_id, 'voice-six');
    assert.equal(staged.superseded.message.id, 'server-user-1');

    const restored = restoreSupersededUserTurn(staged.messages, 'voice-six', staged.superseded);
    assert.deepEqual(restored.map((message) => message.id), ['server-user-1', 'local-correction']);
});

test('an unrelated optimistic user row is not removed by correction staging', () => {
    const unrelated = {
        id: 'local-unrelated',
        role: 'user',
        content: 'Keep this turn.',
        metadata: { client_request_id: 'other-turn' },
    };
    const staged = stageOptimisticUserTurn([unrelated], {
        content: 'Corrected request.',
        clientRequestId: 'voice-new',
        supersedesClientRequestId: 'voice-missing',
        localId: 'local-new',
    });

    assert.deepEqual(staged.messages.map((message) => message.id), ['local-unrelated', 'local-new']);
    assert.equal(staged.superseded, null);
});

test('calendar schedule questions and their corrections stay on the app runtime', () => {
    assert.equal(realtimeNeedsAppRuntime('When do I have long runs scheduled?'), true);
    assert.equal(realtimeNeedsAppRuntime('Remember that my gate code changed.'), true);
    assert.equal(realtimeNeedsAppRuntime('Forget the old gate code.'), true);
    assert.equal(realtimeNeedsAppRuntime('Move my product meeting to Tuesday.'), true);
    assert.equal(realtimeNeedsAppRuntime('I thought I had them for Saturday.', { appConversationActive: true }), true);
    assert.equal(realtimeNeedsAppRuntime('Yes.', { appConversationActive: true }), true);
    assert.equal(realtimeNeedsAppRuntime('Tell me a short joke.'), false);
    assert.equal(realtimeNeedsAppRuntime('What did I just say?', { backendSyncRequired: true }), true);
});

test('current external voice questions and paraphrases cannot enter the tool-less lane', () => {
    assert.equal(realtimeNeedsAppRuntime("What's the stock price for Apple?"), true);
    assert.equal(realtimeNeedsAppRuntime('Who won the Yankees game?'), true);
    assert.equal(realtimeNeedsAppRuntime('Did the Yankees win last night?'), true);
    assert.equal(realtimeNeedsAppRuntime('Who is the president?'), true);
    assert.equal(realtimeNeedsAppRuntime('Is the nearest Target still open?'), true);
    assert.equal(realtimeNeedsAppRuntime('How is traffic to the airport?'), true);
    assert.equal(realtimeNeedsAppRuntime('Will I need an umbrella in Orlando at five?'), true);
    assert.equal(realtimeNeedsAppRuntime('What should I wear outside later?'), true);
    assert.equal(realtimeNeedsAppRuntime("How's Orlando looking at 5?"), true);
    assert.equal(realtimeNeedsAppRuntime('Should I bring a jacket tonight?'), true);
    assert.equal(realtimeNeedsAppRuntime('Is it safe to drive to work?'), true);
});

test('only narrow timeless conversation intents enter the tool-less lane', () => {
    assert.equal(realtimeNeedsAppRuntime('Tell me a short joke.'), false);
    assert.equal(realtimeNeedsAppRuntime('Please give me another joke.'), false);
    assert.equal(realtimeNeedsAppRuntime('Make me laugh.'), false);
    assert.equal(realtimeNeedsAppRuntime('Hello Bean.'), false);
    assert.equal(realtimeNeedsAppRuntime('How are you?'), false);

    assert.equal(realtimeNeedsAppRuntime(''), true);
    assert.equal(realtimeNeedsAppRuntime('Tell me a short story.'), true);
    assert.equal(realtimeNeedsAppRuntime('What time is it in Orlando?'), true);
    assert.equal(realtimeNeedsAppRuntime('Hello, then check the weather.'), true);
    assert.equal(realtimeNeedsAppRuntime('Tell me a joke about today\'s headlines.'), true);
});

test('filler-only recognition is not submitted as a user turn', () => {
    assert.equal(isVoiceFillerOnly('um...'), true);
    assert.equal(isVoiceFillerOnly('uh, I thought they were Saturday'), false);
});

test('work status questions report actual run state without inventing progress', () => {
    assert.equal(
        realtimeWorkStatusAnswer('Are you still working on it?', { isWorking: true }),
        'Yes — I’m still working on it. I’ll tell you as soon as it finishes.',
    );
    assert.equal(
        realtimeWorkStatusAnswer('Did you finish yet?', { isWorking: false }),
        'No — I’m not currently working on a request.',
    );
    assert.equal(
        realtimeWorkStatusAnswer('Are you still working on the weather request?', { isWorking: true }),
        'Yes — I’m still working on it. I’ll tell you as soon as it finishes.',
    );
    assert.equal(realtimeWorkStatusAnswer('What is the weather?', { isWorking: true }), '');
});

test('background work is superseded only by an explicit correction or fresh wake', () => {
    assert.equal(isExplicitRealtimeWorkInterruption('Dinner is ready.'), false);
    assert.equal(isExplicitRealtimeWorkInterruption('Only the Friday forecast.'), false);
    assert.equal(isExplicitRealtimeWorkInterruption('Actually, use Tampa instead.'), true);
    assert.equal(isExplicitRealtimeWorkInterruption('Change that to tomorrow.'), true);
    assert.equal(isExplicitRealtimeWorkInterruption('New request.', { heardWakeWord: true }), true);
});

test('non-Latin recognition artifacts are rejected from the US English voice session', () => {
    assert.equal(isLikelyNonEnglishRealtimeTranscript('Take care.'), false);
    assert.equal(isLikelyNonEnglishRealtimeTranscript("Schedule José's appointment."), false);
    assert.equal(isLikelyNonEnglishRealtimeTranscript('ありがとうございます。'), true);
    assert.equal(isLikelyNonEnglishRealtimeTranscript('알겠습니다'), true);
    assert.equal(isLikelyNonEnglishRealtimeTranscript('个'), true);
});

test('completed realtime output supplies assistant text when transcript events are missing', () => {
    assert.equal(extractRealtimeResponseTranscript({
        output: [{
            type: 'message',
            content: [{ type: 'audio', transcript: 'Your long runs start on Saturdays.' }],
        }],
    }), 'Your long runs start on Saturdays.');
});

test('deferred voice answers reject hidden bridge messages', () => {
    const hiddenBridge = { role: 'assistant', content: 'Working', metadata: { runtime: 'direct_queue_bridge' } };
    const finalAnswer = { role: 'assistant', content: 'You have two events today.', metadata: {} };
    const staysOut = (message) => message.metadata?.runtime === 'direct_queue_bridge';

    assert.equal(shouldDeferAssistantMessage(hiddenBridge, hiddenBridge.content, staysOut), false);
    assert.equal(shouldDeferAssistantMessage(finalAnswer, finalAnswer.content, staysOut), true);
});

test('active correction supersedes the prior turn and resumes only on the new epoch', () => {
    const conversation = new RealtimeConversationController();
    const wake = conversation.admitTranscript({ id: 'wake', content: 'Hey Bean, check my calendar', heardWakeWord: true });
    const correction = conversation.admitTranscript({ id: 'correction', content: 'Actually, only work events' });
    const superseded = conversation.supersedeTranscript({ content: 'Actually, only work events' });

    assert.equal(wake.accepted, true);
    assert.equal(correction.accepted, true);
    assert.equal(conversation.canContinue(wake.epoch), false);
    assert.equal(conversation.resumeTranscript({ content: 'Actually, only work events', epoch: correction.epoch }).accepted, false);
    assert.equal(conversation.resumeTranscript({ content: 'Actually, only work events', epoch: superseded.epoch }).accepted, true);
});

test('a local wake activates one current epoch before any provider transcript exists', () => {
    const conversation = new RealtimeConversationController();

    const wake = conversation.activateFromLocalWake();
    const providerTranscript = conversation.admitTranscript({
        id: 'provider-command',
        content: 'What is the weather in Orlando?',
        heardWakeWord: false,
    });

    assert.equal(wake.accepted, true);
    assert.equal(wake.activated, true);
    assert.equal(wake.reason, 'local_wake');
    assert.equal(providerTranscript.accepted, true);
    assert.equal(providerTranscript.epoch, wake.epoch);

    const duplicateWake = conversation.activateFromLocalWake();
    assert.equal(duplicateWake.activated, false);
    assert.equal(duplicateWake.epoch, wake.epoch);
});

test('the first provider transcript after local wake drops only validated wake-prefix variants', () => {
    assert.equal(stripRealtimeLocalWakePrefix('Hey Bean, what is the weather?'), 'what is the weather?');
    assert.equal(stripRealtimeLocalWakePrefix('They being add milk'), 'add milk');
    assert.equal(stripRealtimeLocalWakePrefix('He being what time is it?'), 'what time is it?');
    assert.equal(stripRealtimeLocalWakePrefix('Habeen remind me tomorrow'), 'remind me tomorrow');
    assert.equal(stripRealtimeLocalWakePrefix('What is the weather?'), 'What is the weather?');
    assert.equal(stripRealtimeLocalWakePrefix('Being honest is important'), 'Being honest is important');
});

test('stop replay rejects stale work and 1,000 dormant transcripts until one strict wake', async () => {
    const conversation = new RealtimeConversationController();
    const lifecycle = new RealtimeResponseLifecycle();
    const wake = conversation.admitTranscript({ id: 'initial-wake', content: 'Hey Bean, check the weather', heardWakeWord: true });
    const completion = lifecycle.begin('tool-final');
    bindCurrentResponse(lifecycle, 'old-response');
    const stoppedEpoch = conversation.stop();
    lifecycle.cancel('interrupted');

    assert.equal(conversation.snapshot().state, REALTIME_CONVERSATION_STATES.WAKE_ONLY);
    assert.equal(conversation.canContinue(wake.epoch), false);
    assert.equal(lifecycle.finish('old-response'), null);
    assert.equal((await completion).cancelled, true);

    const dormantNoise = [
        'binsta',
        'can you still hear me',
        'Hey Ben, can you hear me?',
        'Hey Bing, check the weather',
        'Hey being, delete my task',
        'Hey beam, add a reminder',
        'To delete a task, say Hey Bean, delete the grocery task',
        'background conversation',
    ];
    for (let replay = 0; replay < 1_000; replay += 1) {
        const content = dormantNoise[replay % dormantNoise.length];
        const admission = conversation.admitTranscript({
            id: `dormant-${replay}`,
            content,
            heardWakeWord: isStrictRealtimeWakePhrase(content),
        });
        assert.equal(admission.accepted, false);
        assert.equal(admission.reason, 'wake_required');
        assert.equal(conversation.capture(), stoppedEpoch);
    }

    const wakeText = 'Hey Bean, can you still hear me?';
    const accepted = conversation.admitTranscript({ id: 'explicit-wake', content: wakeText, heardWakeWord: isStrictRealtimeWakePhrase(wakeText) });
    const duplicate = conversation.admitTranscript({ id: 'explicit-wake', content: wakeText, heardWakeWord: isStrictRealtimeWakePhrase(wakeText) });
    assert.equal(accepted.accepted, true);
    assert.equal(accepted.activated, true);
    assert.equal(duplicate.accepted, false);
    assert.equal(duplicate.reason, 'duplicate');
    assert.equal(conversation.isActive(), true);
});

test('repeated stop is idempotent in state and monotonically invalidates epochs', () => {
    const conversation = new RealtimeConversationController();
    const activeEpoch = conversation.activate();
    const firstStopEpoch = conversation.stop();
    const secondStopEpoch = conversation.stop();

    assert.equal(conversation.snapshot().state, REALTIME_CONVERSATION_STATES.WAKE_ONLY);
    assert.ok(firstStopEpoch > activeEpoch);
    assert.ok(secondStopEpoch > firstStopEpoch);
    assert.equal(conversation.canContinue(activeEpoch), false);
});

test('strict wake recognition rejects homophones and embedded mentions', () => {
    assert.equal(isStrictRealtimeWakePhrase('Hey Bean, are you there?'), true);
    assert.equal(isStrictRealtimeWakePhrase('hey, bean'), true);
    assert.equal(isStrictRealtimeWakePhrase('Hey Ben'), false);
    assert.equal(isStrictRealtimeWakePhrase('Hey Bing'), false);
    assert.equal(isStrictRealtimeWakePhrase('Hey being'), false);
    assert.equal(isStrictRealtimeWakePhrase('Hey beam'), false);
    assert.equal(isStrictRealtimeWakePhrase('To begin, say Hey Bean, delete the task'), false);
});

test('a dormant-origin item completing after a newer wake remains wake-gated', () => {
    const conversation = new RealtimeConversationController();
    conversation.noteTranscriptOrigin('late-dormant');
    conversation.noteTranscriptOrigin('late-homophone');
    conversation.noteTranscriptOrigin('wake-item');

    const wakeText = 'Hey Bean, start listening';
    const wake = conversation.admitTranscript({ id: 'wake-item', content: wakeText, heardWakeWord: isStrictRealtimeWakePhrase(wakeText) });
    const delayed = conversation.admitTranscript({ id: 'late-dormant', content: 'delete every task', heardWakeWord: false });
    const delayedHomophone = conversation.admitTranscript({ id: 'late-homophone', content: 'Hey Ben, delete every task', heardWakeWord: isStrictRealtimeWakePhrase('Hey Ben, delete every task') });

    assert.equal(wake.accepted, true);
    assert.equal(conversation.isActive(), true);
    assert.equal(delayed.accepted, false);
    assert.equal(delayed.reason, 'wake_required');
    assert.equal(delayedHomophone.accepted, false);
    assert.equal(delayedHomophone.reason, 'wake_required');
});

test('pause acknowledgement cannot wake Bean through speaker echo', () => {
    const acknowledgement = realtimePauseAcknowledgement();

    assert.match(acknowledgement, /pause/i);
    assert.doesNotMatch(acknowledgement, /hey\s+bean/i);
});

test('natural stop variants pause while similar non-stop requests remain active', () => {
    [
        'Please stop, Bean',
        'Bean, stop please',
        'Okay Bean, stop',
        'stop now',
        'Hey Bean, please stop',
        'STOP!',
    ].forEach((phrase) => assert.equal(isRealtimeVoiceStopCommand(phrase), true, phrase));
    [
        "don't stop",
        'do not stop the timer',
        'stop by the store',
        'where is the bus stop',
    ].forEach((phrase) => assert.equal(isRealtimeVoiceStopCommand(phrase), false, phrase));
});

test('playback cancellation clears both generation and buffered WebRTC audio', () => {
    assert.deepEqual(buildRealtimePlaybackCancellationEvents(), [
        { type: 'response.cancel' },
        { type: 'output_audio_buffer.clear' },
    ]);
});

test('stale response cancellation targets only the mismatched response', () => {
    assert.deepEqual(buildRealtimeTargetedResponseCancellationEvent(' response-old '), {
        type: 'response.cancel',
        response_id: 'response-old',
    });
    assert.equal(buildRealtimeTargetedResponseCancellationEvent(''), null);
});

test('dormant transcript items can be removed from server conversation context', () => {
    assert.deepEqual(buildRealtimeConversationItemDeleteEvent('item-dormant'), {
        type: 'conversation.item.delete',
        item_id: 'item-dormant',
    });
});

test('microphone capture requests browser echo and noise controls', () => {
    assert.deepEqual(realtimeMicrophoneConstraints(), {
        audio: {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true,
        },
    });
});

test('only completed response.done payloads are successful', () => {
    assert.equal(isCompletedRealtimeResponse({ status: 'completed' }), true);
    assert.equal(isCompletedRealtimeResponse({ status: 'cancelled' }), false);
    assert.equal(isCompletedRealtimeResponse({ status: 'failed' }), false);
    assert.equal(isCompletedRealtimeResponse({ status: 'incomplete' }), false);
    assert.equal(isCompletedRealtimeResponse({}), false);
});

test('only the known duplicate live-call conflict is eligible for clean renegotiation', () => {
    assert.equal(isRealtimeDuplicateCallConflict(409, 'A live session already exists for the provided call_id.'), true);
    assert.equal(isRealtimeDuplicateCallConflict(409, 'Another conflict.'), false);
    assert.equal(isRealtimeDuplicateCallConflict(401, 'A live session already exists for the provided call_id.'), false);
});

test('barge-in marks the interrupted speech response as cancelled', async () => {
    let now = 1_000;
    const conversation = new RealtimeConversationController();
    const conversationEpoch = conversation.activate();
    const lifecycle = new RealtimeResponseLifecycle(() => now);
    const completion = lifecycle.begin('direct');
    bindCurrentResponse(lifecycle, 'response-interrupted');
    lifecycle.captureTranscript('A partial answer');

    now = 1_125;
    lifecycle.cancel('interrupted');

    assert.equal(conversation.canContinue(conversationEpoch), true);
    assert.equal(conversation.admitTranscript({ id: 'barge-in', content: 'Actually, make that tomorrow' }).accepted, true);
    assert.deepEqual(await completion, {
        purpose: 'direct',
        transcript: 'A partial answer',
        cancelled: true,
        reason: 'interrupted',
        startedAtMs: 1_000,
        audioStartedAtMs: null,
        audioStartLatencyMs: null,
        responseDurationMs: 125,
    });
});

test('barge-in cancellation cannot block the replacement on a hung old request', async () => {
    let cancelCalled = 0;
    let settleOldRequest;
    const oldRequest = new Promise((resolve) => { settleOldRequest = resolve; });

    assert.equal(cancelRealtimeTurnWithoutBlockingReplacement(() => {
        cancelCalled += 1;
        return oldRequest;
    }), true);
    assert.equal(cancelCalled, 1);

    let replacementRan = false;
    await Promise.resolve().then(() => { replacementRan = true; });
    assert.equal(replacementRan, true);

    settleOldRequest();
    await oldRequest;
});

test('an activated voice conversation stays open until explicit reset', () => {
    assert.equal(realtimeFollowUpExpiry(1_000), Number.POSITIVE_INFINITY);
});

test('natural follow-up sleep requires a new wake word without invalidating pending work', () => {
    const conversation = new RealtimeConversationController();
    const active = conversation.activateFromLocalWake();

    assert.equal(conversation.sleep(), active.epoch);
    assert.equal(conversation.isActive(), false);
    assert.equal(conversation.isCurrent(active.epoch), true);
    assert.equal(conversation.admitTranscript({ id: 'ambient', content: 'Dinner is ready.' }).reason, 'wake_required');
    assert.equal(conversation.admitTranscript({ id: 'wake-again', content: 'Hey Bean, status', heardWakeWord: true }).accepted, true);
});

test('an unbound cancelled generation rejects its late response.created event', async () => {
    const lifecycle = new RealtimeResponseLifecycle();
    const oldCompletion = lifecycle.begin('old');
    const oldClientResponseId = lifecycle.currentClientResponseId();
    lifecycle.cancel();
    assert.equal((await oldCompletion).cancelled, true);

    let nextResolved = false;
    const nextCompletion = lifecycle.begin('new').then((result) => {
        nextResolved = true;
        return result;
    });
    const newClientResponseId = lifecycle.currentClientResponseId();

    assert.notEqual(newClientResponseId, oldClientResponseId);
    assert.equal(lifecycle.bindResponse('late-old-response', oldClientResponseId), false);
    await Promise.resolve();
    assert.equal(nextResolved, false);
    assert.equal(lifecycle.bindResponse('new-response', newClientResponseId), true);
    lifecycle.markResponseDone('new-response');
    assert.equal((await nextCompletion).cancelled, false);
});

test('duplicate and out-of-order lifecycle events finish exactly once', async () => {
    const lifecycle = new RealtimeResponseLifecycle();
    const completion = lifecycle.begin('ordered');
    const clientResponseId = lifecycle.currentClientResponseId();

    assert.equal(lifecycle.markAudioStarted('response-ordered'), false);
    assert.equal(lifecycle.bindResponse('response-ordered', 'wrong-generation'), false);
    assert.equal(lifecycle.bindResponse('response-ordered', clientResponseId), true);
    assert.equal(lifecycle.bindResponse('response-ordered', clientResponseId), true);
    assert.equal(lifecycle.markAudioStarted('response-ordered'), true);
    assert.equal(lifecycle.markAudioStopped('response-ordered'), null);
    const finished = lifecycle.markResponseDone('response-ordered');
    assert.equal(finished?.cancelled, false);
    assert.equal(lifecycle.markResponseDone('response-ordered'), null);
    assert.equal(lifecycle.markAudioStopped('response-ordered'), null);
    assert.equal((await completion).cancelled, false);
});

test('a stale cancelled response cannot complete the next spoken response', async () => {
    let now = 2_000;
    const lifecycle = new RealtimeResponseLifecycle(() => now);
    lifecycle.begin('first');
    bindCurrentResponse(lifecycle, 'response-1');
    now = 2_010;
    lifecycle.cancel();

    let resolved = false;
    const next = lifecycle.begin('second').then((result) => {
        resolved = true;
        return result;
    });

    assert.equal(lifecycle.bindResponse('response-1', lifecycle.currentClientResponseId()), false);
    assert.equal(lifecycle.finish('response-1'), null);
    await Promise.resolve();
    assert.equal(resolved, false);

    bindCurrentResponse(lifecycle, 'response-2');
    lifecycle.captureTranscript('Second answer');
    now = 2_050;
    lifecycle.finish('response-2');
    assert.deepEqual(await next, {
        purpose: 'second',
        transcript: 'Second answer',
        cancelled: false,
        reason: 'completed',
        startedAtMs: 2_010,
        audioStartedAtMs: null,
        audioStartLatencyMs: null,
        responseDurationMs: 40,
    });
});

test('a spoken response completes only after its audio buffer finishes playing', async () => {
    let now = 3_000;
    const lifecycle = new RealtimeResponseLifecycle(() => now);
    let completed = false;
    const completion = lifecycle.begin('final').then((result) => {
        completed = true;
        return result;
    });

    bindCurrentResponse(lifecycle, 'response-voice');
    now = 3_120;
    lifecycle.markAudioStarted('response-voice');
    lifecycle.captureTranscript('You have two events today.');
    now = 3_400;
    lifecycle.markResponseDone('response-voice');
    await Promise.resolve();
    assert.equal(completed, false);

    now = 3_600;
    lifecycle.markAudioStopped('response-voice');
    assert.deepEqual(await completion, {
        purpose: 'final',
        transcript: 'You have two events today.',
        cancelled: false,
        reason: 'completed',
        startedAtMs: 3_000,
        audioStartedAtMs: 3_120,
        audioStartLatencyMs: 120,
        responseDurationMs: 600,
    });
});

test('a response watchdog terminates a missing provider completion exactly once', async () => {
    let now = 4_000;
    let timeoutCallback = null;
    let clearedTimeoutId = null;
    let onTimeoutCalls = 0;
    const lifecycle = new RealtimeResponseLifecycle(() => now, {
        setTimeout: (callback) => {
            timeoutCallback = callback;
            return 73;
        },
        clearTimeout: (id) => {
            clearedTimeoutId = id;
        },
    });
    const completion = lifecycle.begin('direct', {
        timeoutMs: 20_000,
        onTimeout: () => { onTimeoutCalls += 1; },
    });

    bindCurrentResponse(lifecycle, 'response-hung');
    lifecycle.captureTranscript('An unfinished answer');
    now = 24_000;
    timeoutCallback();

    assert.deepEqual(await completion, {
        purpose: 'direct',
        transcript: 'An unfinished answer',
        cancelled: true,
        reason: 'timed_out',
        startedAtMs: 4_000,
        audioStartedAtMs: null,
        audioStartLatencyMs: null,
        responseDurationMs: 20_000,
    });
    assert.equal(onTimeoutCalls, 1);
    assert.equal(clearedTimeoutId, 73);
    assert.equal(lifecycle.cancel('timed_out'), null);
});

test('chat rerenders restore textarea focus, selection, and scroll without moving the page', () => {
    const form = { dataset: { chatInstance: 'primary-chat' } };
    const original = {
        dataset: { chatFocusControl: 'message' },
        selectionStart: 2,
        selectionEnd: 6,
        selectionDirection: 'forward',
        scrollTop: 11,
        closest(selector) {
            return selector === '[data-chat-focus-control]' ? this : form;
        },
    };
    const replacement = {
        disabled: false,
        value: 'new',
        focusOptions: null,
        selection: null,
        scrollTop: 0,
        focus(options) { this.focusOptions = options; },
        setSelectionRange(...selection) { this.selection = selection; },
    };
    form.querySelector = (selector) => selector.includes('message') ? replacement : null;
    const mount = {
        ownerDocument: { activeElement: original },
        contains: (element) => element === original,
        querySelectorAll: () => [form],
    };

    const snapshot = captureHeyBeanChatControlFocus(mount);
    assert.deepEqual(snapshot, {
        instance: 'primary-chat',
        control: 'message',
        selectionStart: 2,
        selectionEnd: 6,
        selectionDirection: 'forward',
        scrollTop: 11,
    });
    assert.equal(restoreHeyBeanChatControlFocus(mount, snapshot), true);
    assert.deepEqual(replacement.focusOptions, { preventScroll: true });
    assert.deepEqual(replacement.selection, [2, 3, 'forward']);
    assert.equal(replacement.scrollTop, 11);
});

test('a removed stop control hands keyboard focus to the stable send control', () => {
    const send = {
        disabled: false,
        focused: false,
        focus() { this.focused = true; },
    };
    const form = {
        dataset: { chatInstance: 'primary-chat' },
        querySelector: (selector) => selector.includes('send') ? send : null,
    };
    const mount = { querySelectorAll: () => [form] };

    assert.equal(restoreHeyBeanChatControlFocus(mount, {
        instance: 'primary-chat',
        control: 'stop',
        selectionStart: null,
    }), true);
    assert.equal(send.focused, true);
});
