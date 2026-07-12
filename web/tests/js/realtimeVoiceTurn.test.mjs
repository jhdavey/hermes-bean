import assert from 'node:assert/strict';
import test from 'node:test';

import {
    RealtimeInputTranscriptBuffer,
    buildRealtimeConversationItemDeleteEvent,
    buildRealtimeResponseEvent,
    buildRealtimeTargetedResponseCancellationEvent,
    isLikelyNonEnglishRealtimeTranscript,
    isRealtimeDuplicateCallConflict,
    isStrictRealtimeWakePhrase,
    isVoiceFillerOnly,
    naturalizeRealtimeSpeechText,
    realtimeMicrophoneConstraints,
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
    assert.equal(stripRealtimeLocalWakePrefix('Being honest is important'), 'Being honest is important');
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
