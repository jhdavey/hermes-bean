import assert from 'node:assert/strict';
import test from 'node:test';

import {
    acquireRealtimeMicrophone,
    RealtimeInputTranscriptBuffer,
    buildRealtimeConversationItemDeleteEvent,
    buildRealtimeResponseEvent,
    buildRealtimeTargetedResponseCancellationEvent,
    isLikelyNonEnglishRealtimeTranscript,
    isRealtimeWakeAddressOnly,
    isRealtimeDuplicateCallConflict,
    isStrictRealtimeWakePhrase,
    isVoiceFillerOnly,
    naturalizeRealtimeSpeechText,
    realtimeMicrophoneConstraints,
    realtimeUsageReportFromProviderEvent,
    sanitizedLocalWakeFailure,
    shouldDisplayRealtimeTranscriptDraft,
    stageOptimisticUserTurn,
    stripRealtimeLocalWakePrefix,
} from '../../resources/js/heybean/realtimeVoiceTurn.js';

globalThis.window ??= { matchMedia: () => null };
const {
    browserVoiceV2OwnsStopAction,
    captureHeyBeanChatControlFocus,
    restoreHeyBeanChatControlFocus,
} = await import('../../resources/js/heybean/webApp.js');

test('[BV2-STARTUP-04] a transient browser microphone release abort retries once before re-arm fails', async () => {
    const expectedStream = { id: 'second-acquisition' };
    const calls = [];
    const delays = [];
    const stream = await acquireRealtimeMicrophone(async (constraints) => {
        calls.push(constraints);
        if (calls.length === 1) {
            throw Object.assign(new Error('signal is aborted without reason'), { name: 'AbortError', code: 20 });
        }
        return expectedStream;
    }, { audio: true }, {
        retryDelaysMs: [250, 750, 1500],
        delay: async (milliseconds) => delays.push(milliseconds),
    });

    assert.equal(stream, expectedStream);
    assert.equal(calls.length, 2);
    assert.deepEqual(delays, [250]);

    const permissionFailure = Object.assign(new Error('Permission denied'), { name: 'NotAllowedError' });
    await assert.rejects(
        () => acquireRealtimeMicrophone(async () => { throw permissionFailure; }, { audio: true }, {
            delay: async () => assert.fail('non-transient microphone failures must not retry'),
        }),
        permissionFailure,
    );

    let sustainedAbortCalls = 0;
    const sustainedAbort = Object.assign(new Error('signal is aborted without reason'), { code: 20 });
    const sustainedDelays = [];
    await assert.rejects(
        () => acquireRealtimeMicrophone(async () => {
            sustainedAbortCalls += 1;
            throw sustainedAbort;
        }, { audio: true }, {
            retryDelaysMs: [250, 750, 1500],
            delay: async (milliseconds) => sustainedDelays.push(milliseconds),
        }),
        sustainedAbort,
    );
    assert.equal(sustainedAbortCalls, 4);
    assert.deepEqual(sustainedDelays, [250, 750, 1500]);
});

test('[BV2-STOP-05] browser voice Stop never routes active v2 work through generic task cancellation', () => {
    assert.equal(browserVoiceV2OwnsStopAction({ enabled: true, activeWork: true }), true);
    assert.equal(browserVoiceV2OwnsStopAction({ enabled: true, voiceWakeListening: true }), true);
    assert.equal(browserVoiceV2OwnsStopAction({ enabled: true, playbackActive: true }), true);
    assert.equal(browserVoiceV2OwnsStopAction({ enabled: true, realtimeVoiceActive: true }), true);

    // Generic typed chat keeps its existing cancel action when browser voice
    // is inactive and has no playback or server-owned v2 work.
    assert.equal(browserVoiceV2OwnsStopAction({ enabled: true }), false);
    assert.equal(browserVoiceV2OwnsStopAction({ enabled: false, activeWork: true }), false);
});

test('explicit v2 speech responses cannot invoke app tools and carry correlation metadata', () => {
    assert.deepEqual(buildRealtimeResponseEvent('Hello.', { clientResponseId: 'client-response-1' }), {
        type: 'response.create',
        response: {
            instructions: 'Hello.',
            tool_choice: 'none',
            metadata: { heybean_response_id: 'client-response-1' },
        },
    });
});

test('input transcript deltas stream into one draft and finalize per audio item', () => {
    const transcripts = new RealtimeInputTranscriptBuffer();
    assert.equal(transcripts.append({ itemId: 'one', delta: 'What ' }), 'What ');
    assert.equal(transcripts.append({ itemId: 'one', delta: 'time?' }), 'What time?');
    assert.equal(transcripts.complete({ itemId: 'one' }), 'What time?');
    transcripts.append({ itemId: 'two', delta: 'discard me' });
    transcripts.discard({ itemId: 'two' });
    assert.equal(transcripts.complete({ itemId: 'two' }), '');
});

test('wake-only draft fragments stay hidden while accepted speech can display', () => {
    assert.equal(shouldDisplayRealtimeTranscriptDraft('Hey'), false);
    assert.equal(shouldDisplayRealtimeTranscriptDraft('Hey Bean.'), false);
    assert.equal(shouldDisplayRealtimeTranscriptDraft('Hey Bean, what is the weather?'), true);
});

test('voice output humanizes canonical dates, times, and timezone identifiers', () => {
    const now = new Date(2026, 6, 11, 10, 50);
    assert.equal(
        naturalizeRealtimeSpeechText('It starts at 2026-07-12T16:00:00-04:00.', { now }),
        'It starts tomorrow at 4 p.m.',
    );
    assert.equal(
        naturalizeRealtimeSpeechText('That is 18:30 (America/New_York).', { now }),
        'That is 6:30 p.m.',
    );
});

test('v2 transcript filters reject filler and non-Latin recognition artifacts', () => {
    assert.equal(isVoiceFillerOnly('Um, yeah.'), true);
    assert.equal(isVoiceFillerOnly('yes'), false);
    assert.equal(isLikelyNonEnglishRealtimeTranscript('Take care.'), false);
    assert.equal(isLikelyNonEnglishRealtimeTranscript('ありがとうございます。'), true);
});

test('strict wake and released transcript prefix handling remain conservative', () => {
    assert.equal(isStrictRealtimeWakePhrase('Hey Bean, are you there?'), true);
    assert.equal(isStrictRealtimeWakePhrase('Hey Ben'), false);
    assert.equal(isStrictRealtimeWakePhrase('To begin, say Hey Bean'), false);
    assert.equal(stripRealtimeLocalWakePrefix('Hey Bean, what is the weather?'), 'what is the weather?');
    assert.equal(stripRealtimeLocalWakePrefix('Bean, what time is it?'), 'what time is it?');
    assert.equal(stripRealtimeLocalWakePrefix('Bean.'), '');
    assert.equal(stripRealtimeLocalWakePrefix('Being honest is important'), 'Being honest is important');
    assert.equal(isRealtimeWakeAddressOnly('Hey Bean.'), true);
    assert.equal(isRealtimeWakeAddressOnly('Bean.'), true);
    assert.equal(isRealtimeWakeAddressOnly('Bean, what time is it?'), false);
    assert.equal(isRealtimeWakeAddressOnly('Being honest is important'), false);
});

test('provider event helpers target only the intended response or transcript item', () => {
    assert.deepEqual(buildRealtimeTargetedResponseCancellationEvent(' response-old '), {
        type: 'response.cancel',
        response_id: 'response-old',
    });
    assert.equal(buildRealtimeTargetedResponseCancellationEvent(''), null);
    assert.deepEqual(buildRealtimeConversationItemDeleteEvent('item-dormant'), {
        type: 'conversation.item.delete',
        item_id: 'item-dormant',
    });
});

test('[BV2-USAGE-01] provider transcription and speech usage become sanitized idempotent reports', () => {
    assert.deepEqual(realtimeUsageReportFromProviderEvent({
        type: 'conversation.item.input_audio_transcription.completed',
        event_id: 'event-transcription-1',
        transcript: 'raw words must not be copied into the report',
        usage: {
            total_tokens: 26,
            input_tokens: 17,
            output_tokens: 9,
            input_token_details: { text_tokens: 0, audio_tokens: 17 },
        },
    }), {
        providerEventId: 'transcription:event-transcription-1',
        eventType: 'transcription',
        usage: {
            total_tokens: 26,
            input_tokens: 17,
            output_tokens: 9,
            input_token_details: { text_tokens: 0, audio_tokens: 17 },
        },
    });
    assert.deepEqual(realtimeUsageReportFromProviderEvent({
        type: 'response.done',
        response: {
            id: 'response-1',
            usage: { total_tokens: 12, input_tokens: 5, output_tokens: 7 },
        },
    }), {
        providerEventId: 'speech:response-1',
        eventType: 'speech',
        usage: { total_tokens: 12, input_tokens: 5, output_tokens: 7 },
    });
    assert.equal(realtimeUsageReportFromProviderEvent({ type: 'response.done', response: { id: 'empty', usage: {} } }), null);
    assert.equal(realtimeUsageReportFromProviderEvent({ type: 'conversation.item.created' }), null);
});

test('[BV2-DIAGNOSTIC-01] local wake failures retain only a bounded sanitized cause chain', () => {
    const deepest = Object.assign(new Error('Realtime transcription disconnected.\nBearer secret-must-not-be-copied'), {
        code: 'transport failed!',
    });
    const outer = Object.assign(new Error('The local wake gate could not open safely.'), {
        code: 'gate_open_failed',
        cause: deepest,
    });
    const diagnostic = sanitizedLocalWakeFailure(outer);

    assert.equal(diagnostic.stage, 'local_wake');
    assert.equal(diagnostic.code, 'gate_open_failed');
    assert.equal(diagnostic.cause_chain.length, 2);
    assert.equal(diagnostic.cause_chain[1].code, 'transport_failed_');
    assert.doesNotMatch(JSON.stringify(diagnostic), /secret-must-not-be-copied/);
    assert.match(diagnostic.cause_chain[1].message, /Bearer \[redacted\]/);
});

test('[BV2-STARTUP-02] startup failures are classified for diagnostics without becoming raw UI copy', () => {
    const diagnostic = sanitizedLocalWakeFailure(
        Object.assign(new Error('signal is aborted without reason'), { code: 'AbortError' }),
        'startup',
    );

    assert.equal(diagnostic.stage, 'startup');
    assert.equal(diagnostic.code, 'AbortError');
    assert.equal(diagnostic.cause_chain.length, 1);
});

test('microphone capture requests browser echo and noise controls', () => {
    assert.deepEqual(realtimeMicrophoneConstraints(), {
        audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
    });
});

test('only the known duplicate live-call conflict is eligible for clean renegotiation', () => {
    assert.equal(isRealtimeDuplicateCallConflict(409, 'A live session already exists for the provided call_id.'), true);
    assert.equal(isRealtimeDuplicateCallConflict(409, 'Another conflict.'), false);
    assert.equal(isRealtimeDuplicateCallConflict(401, 'A live session already exists for the provided call_id.'), false);
});

test('typed chat stages one optimistic user message without voice lifecycle metadata', () => {
    const staged = stageOptimisticUserTurn([], {
        content: 'Hello Bean',
        clientRequestId: 'web-chat-1',
        localId: 'local-1',
    });
    assert.deepEqual(staged.messages, [{
        id: 'local-1',
        role: 'user',
        content: 'Hello Bean',
        metadata: { client_request_id: 'web-chat-1' },
    }]);
});

test('chat rerenders restore textarea focus, selection, and scroll without moving the page', () => {
    const form = { dataset: { chatInstance: 'primary-chat' } };
    const original = {
        dataset: { chatFocusControl: 'message' },
        selectionStart: 2,
        selectionEnd: 6,
        selectionDirection: 'forward',
        scrollTop: 11,
        closest(selector) { return selector === '[data-chat-focus-control]' ? this : form; },
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
    assert.equal(restoreHeyBeanChatControlFocus(mount, snapshot), true);
    assert.deepEqual(replacement.focusOptions, { preventScroll: true });
    assert.deepEqual(replacement.selection, [2, 3, 'forward']);
    assert.equal(replacement.scrollTop, 11);
});

test('a removed stop control hands keyboard focus to the stable send control', () => {
    const send = { disabled: false, focused: false, focus() { this.focused = true; } };
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
