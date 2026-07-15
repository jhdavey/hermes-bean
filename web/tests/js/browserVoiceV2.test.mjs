import assert from 'node:assert/strict';
import test from 'node:test';

import {
    BROWSER_VOICE_CONVERSATION_STATES,
    BROWSER_VOICE_EFFECTS,
    BrowserVoiceControllerV2,
} from '../../resources/js/heybean/browserVoiceControllerV2.js';
import {
    BROWSER_VOICE_PLAYBACK_STATES,
    BrowserVoicePlaybackAdapterV2,
    BrowserVoiceSpeechSchedulerV2,
} from '../../resources/js/heybean/browserVoiceSpeechV2.js';

globalThis.window = { matchMedia: () => null };
const { browserVoiceV2LocalWakeMatchesCompletedBarge } = await import('../../resources/js/heybean/webApp.js');

class FakeClock {
    constructor(now = 0) {
        this.now = now;
        this.nextId = 1;
        this.tasks = new Map();
    }

    clock = () => this.now;

    setTimeout = (callback, delay = 0) => {
        const id = this.nextId;
        this.nextId += 1;
        this.tasks.set(id, { id, callback, at: this.now + Math.max(0, Number(delay) || 0) });
        return id;
    };

    clearTimeout = (id) => {
        this.tasks.delete(id);
    };

    advance(milliseconds) {
        const target = this.now + milliseconds;
        while (true) {
            const task = [...this.tasks.values()]
                .filter((candidate) => candidate.at <= target)
                .sort((left, right) => left.at - right.at || left.id - right.id)[0];
            if (!task) break;
            this.tasks.delete(task.id);
            this.now = task.at;
            task.callback();
        }
        this.now = target;
    }
}

class FakePlayback {
    constructor() {
        this.plays = [];
        this.stops = [];
        this.volumes = [];
        this.active = new Set();
        this.maxActive = 0;
    }

    play = (item, listeners) => {
        const handle = { item, listeners, started: false, stopped: false };
        this.plays.push(handle);
        return handle;
    };

    setVolume = (handle, volume, item) => {
        this.volumes.push({ handle, volume, item });
    };

    stop = (handle, reason, item) => {
        if (handle) {
            handle.stopped = true;
            this.active.delete(handle);
        }
        this.stops.push({ handle, reason, item });
    };

    start(handle = this.plays.at(-1)) {
        handle.started = true;
        this.active.add(handle);
        this.maxActive = Math.max(this.maxActive, this.active.size);
        handle.listeners.onStart();
    }

    end(handle = this.plays.at(-1), reason = 'completed') {
        this.active.delete(handle);
        handle.listeners.onEnd(reason);
    }
}

function createReadyVoice({ now = 0 } = {}) {
    const time = new FakeClock(now);
    let turn = 0;
    const voice = new BrowserVoiceControllerV2({
        clock: time.clock,
        timers: { setTimeout: time.setTimeout, clearTimeout: time.clearTimeout },
        createTurnId: () => `turn-${++turn}`,
    });
    voice.start();
    voice.providerReady();
    voice.drainEffects();
    return { voice, time };
}

function beginWakeCapture(voice, turnId = 'turn-explicit') {
    voice.wakeConfirmed({ turnId });
    voice.activationReady();
    voice.speechStarted();
    voice.drainEffects();
    return turnId;
}

function finishUtterance(voice, time, text) {
    voice.transcriptPartial(text.slice(0, Math.max(1, Math.floor(text.length / 2))));
    voice.transcriptFinal(text);
    voice.speechEnded();
    time.advance(2_000);
}

function createSpeechHarness(options = {}) {
    const time = new FakeClock();
    const playback = new FakePlayback();
    const adapter = new BrowserVoicePlaybackAdapterV2({
        play: playback.play,
        setVolume: playback.setVolume,
        stop: playback.stop,
    });
    const speech = new BrowserVoiceSpeechSchedulerV2({
        playback: adapter,
        clock: time.clock,
        timers: { setTimeout: time.setTimeout, clearTimeout: time.clearTimeout },
        ...options,
    });
    return { time, playback, speech };
}

test('[BV2-WAKE-01] readiness admits the first wake exactly once and rejects stale startup events', () => {
    const time = new FakeClock();
    const voice = new BrowserVoiceControllerV2({
        clock: time.clock,
        timers: { setTimeout: time.setTimeout, clearTimeout: time.clearTimeout },
    });

    const started = voice.start();
    assert.equal(started.conversationState, BROWSER_VOICE_CONVERSATION_STATES.STARTING);
    voice.providerReady({ generation: started.generation - 1, sequence: 1, source: 'provider' });
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.STARTING);
    assert.equal(voice.snapshot().lastRejectedEvent.reason, 'stale_generation');

    voice.providerReady({ sequence: 2, source: 'provider' });
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY);
    const wake = voice.wakeConfirmed({ turnId: 'first-turn', sequence: 3, source: 'provider' });
    assert.equal(wake.state.conversationState, BROWSER_VOICE_CONVERSATION_STATES.ACTIVATING);
    assert.equal(wake.state.activeTurn.id, 'first-turn');
    assert.equal(wake.effects.filter((item) => item.type === BROWSER_VOICE_EFFECTS.ACTIVATE_CAPTURE).length, 1);

    voice.dispatch({
        type: 'wake_confirmed',
        turnId: 'duplicate-turn',
        generation: voice.snapshot().generation,
        connectionGeneration: voice.snapshot().connectionGeneration,
        source: 'provider',
        sequence: 3,
    });
    assert.equal(voice.snapshot().activeTurn.id, 'first-turn');
    assert.equal(voice.snapshot().lastRejectedEvent.reason, 'stale_sequence');
});

test('[BV2-WAKE-08] a failed first mic start can be stopped, re-armed, and accept the first wake once', () => {
    const voice = new BrowserVoiceControllerV2();
    const failedStart = voice.start();
    const failedConnection = failedStart.connectionGeneration;
    voice.dispatch({ type: 'connection_failed', reason: 'startup_timeout', source: 'provider' });
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.FAILED);

    voice.disable('startup_failed');
    const restarted = voice.start();
    assert.ok(restarted.connectionGeneration > failedConnection);
    voice.providerReady({ source: 'provider' });
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY);

    voice.dispatch({
        type: 'provider_ready',
        connectionGeneration: failedConnection,
        generation: failedStart.generation,
        sequence: 99,
        source: 'stale-provider',
    });
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY);
    assert.equal(voice.snapshot().lastRejectedEvent.reason, 'stale_generation');

    const wake = voice.wakeConfirmed({ turnId: 'rearmed-first-turn', source: 'local_wake' });
    assert.equal(wake.state.activeTurn.id, 'rearmed-first-turn');
    assert.equal(wake.effects.filter((item) => item.type === BROWSER_VOICE_EFFECTS.ACTIVATE_CAPTURE).length, 1);
});

test('[BV2-TRANSCRIPT-01] live final text replaces its partial and the utterance closes at 2,000 ms, not 1,999', () => {
    const { voice, time } = createReadyVoice();
    const turnId = beginWakeCapture(voice);

    voice.transcriptPartial('Create a meal');
    assert.equal(voice.snapshot().liveDraft, 'Create a meal');
    voice.transcriptFinal('Create a meal plan note');
    assert.equal(voice.snapshot().liveDraft, 'Create a meal plan note');
    assert.equal(voice.snapshot().liveDraft.includes('Create a meal Create a meal plan'), false);
    voice.speechEnded();
    voice.drainEffects();

    time.advance(1_999);
    assert.equal(voice.drainEffects().some((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY), false);
    time.advance(1);
    const effects = voice.drainEffects();
    assert.deepEqual(
        effects.find((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY),
        {
            type: BROWSER_VOICE_EFFECTS.TURN_READY,
            turnId,
            transcript: 'Create a meal plan note',
            conversationContext: { mode: 'new_conversation', epoch: 1 },
        },
    );
});

test('[BV2-TRANSCRIPT-02] speech resuming before two seconds cancels the old endpoint', () => {
    const { voice, time } = createReadyVoice();
    beginWakeCapture(voice, 'continuing-turn');
    voice.transcriptFinal('Create a note');
    voice.speechEnded();
    voice.drainEffects();

    time.advance(1_999);
    voice.speechStarted();
    voice.transcriptFinal('Create a note with my meal plan');
    time.advance(5_000);
    assert.equal(voice.drainEffects().some((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY), false);

    voice.speechEnded();
    time.advance(2_000);
    assert.equal(
        voice.drainEffects().find((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY).transcript,
        'Create a note with my meal plan',
    );
});

test('[BV2-TRANSCRIPT-04] provider-observed two-second silence does not add a second endpoint delay', () => {
    const { voice, time } = createReadyVoice();
    beginWakeCapture(voice, 'provider-vad-turn');
    voice.transcriptFinal('What time is it?');
    voice.speechEnded({ observedSilenceMs: 2_000 });

    time.advance(0);

    assert.equal(
        voice.drainEffects().filter((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY).length,
        1,
    );
});

test('[BV2-CLARIFY-01] clarification answer within five seconds remains one stable logical turn', () => {
    const { voice, time } = createReadyVoice();
    const turnId = beginWakeCapture(voice, 'clarification-turn');
    finishUtterance(voice, time, 'Create a reminder');
    voice.drainEffects();
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP);
    voice.drainEffects();
    voice.admissionClarificationRequired('What time should I remind you?', { turnId });
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.AWAITING_CLARIFICATION);
    assert.equal(voice.snapshot().activeTurn.id, turnId);
    assert.equal(
        voice.drainEffects().find((item) => item.type === BROWSER_VOICE_EFFECTS.SPEAK_CLARIFICATION).text,
        'What time should I remind you?',
    );
    voice.playbackStarted({ turnId });
    voice.playbackFinished({ turnId });

    time.advance(4_999);
    voice.speechStarted();
    assert.equal(voice.snapshot().activeTurn.id, turnId);
    voice.transcriptFinal('At 4 p.m. today');
    voice.speechEnded();
    time.advance(2_000);
    const ready = voice.drainEffects().filter((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY);
    assert.deepEqual(ready, [{
        type: BROWSER_VOICE_EFFECTS.TURN_READY,
        turnId,
        transcript: 'Create a reminder At 4 p.m. today',
        clarificationContinuation: true,
        clarificationAnswer: 'At 4 p.m. today',
        conversationContext: { mode: 'new_conversation', epoch: 1 },
    }]);
    assert.equal(voice.snapshot().activeTurn.id, turnId);
});

test('[BV2-CLARIFY-02] unanswered initial clarification expires at five seconds and returns to wake-only', () => {
    const { voice, time } = createReadyVoice();
    beginWakeCapture(voice, 'clarification-timeout');
    finishUtterance(voice, time, 'Create a reminder');
    voice.drainEffects();
    voice.drainEffects();
    voice.admissionClarificationRequired('What time?', { turnId: 'clarification-timeout' });
    voice.drainEffects();
    voice.playbackStarted({ turnId: 'clarification-timeout' });
    voice.playbackFinished({ turnId: 'clarification-timeout' });

    time.advance(4_999);
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.AWAITING_CLARIFICATION);
    time.advance(1);
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY);
    assert.equal(voice.snapshot().activeTurn, null);
    assert.deepEqual(
        voice.drainEffects().filter((item) => item.type === BROWSER_VOICE_EFFECTS.CLARIFICATION_EXPIRED),
        [{
            type: BROWSER_VOICE_EFFECTS.CLARIFICATION_EXPIRED,
            turnId: 'clarification-timeout',
            durable: true,
        }],
    );
});

test('[BV2-CLARIFY-04] answering over the clarification keeps the original stable turn', () => {
    const speechHarness = createSpeechHarness();
    let counter = 0;
    const voice = new BrowserVoiceControllerV2({
        clock: speechHarness.time.clock,
        timers: { setTimeout: speechHarness.time.setTimeout, clearTimeout: speechHarness.time.clearTimeout },
        createTurnId: () => `clarify-barge-${++counter}`,
        speechScheduler: speechHarness.speech,
    });
    voice.start();
    voice.providerReady();
    const turnId = beginWakeCapture(voice, 'clarify-original');
    finishUtterance(voice, speechHarness.time, 'Create a reminder');
    voice.drainEffects();
    voice.admissionClarificationRequired('What time?', { turnId });
    speechHarness.speech.enqueueSpeech({ turnId, text: 'What time?', purpose: 'clarification' });
    speechHarness.playback.start();
    voice.playbackStarted({ turnId });

    voice.potentialBargeIn('potential_speech');
    voice.confirmBargeIn({ source: 'provider_transcript' });

    assert.equal(voice.snapshot().activeTurn.id, turnId);
    assert.equal(voice.snapshot().activeTurn.clarificationContinuation, true);
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.CAPTURING);
});

test('[BV2-CLARIFY-05] server admission clarification keeps one stable turn and starts its five seconds after playback', () => {
    const { voice, time } = createReadyVoice();
    const turnId = beginWakeCapture(voice, 'server-clarification-turn');
    finishUtterance(voice, time, 'Set a reminder');
    const firstAdmission = voice.drainEffects().filter((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY);
    assert.deepEqual(firstAdmission, [{
        type: BROWSER_VOICE_EFFECTS.TURN_READY,
        turnId,
        transcript: 'Set a reminder',
        conversationContext: { mode: 'new_conversation', epoch: 1 },
    }]);

    voice.admissionClarificationRequired('What should I remind you about, and when?', {
        source: 'server_admission',
        turnId,
    });
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.AWAITING_CLARIFICATION);
    assert.equal(voice.snapshot().activeTurn.id, turnId);
    assert.equal(voice.snapshot().deadlines.clarificationAt, null);

    time.advance(10_000);
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.AWAITING_CLARIFICATION);
    voice.playbackStarted({ turnId });
    voice.playbackFinished({ turnId });
    time.advance(4_999);
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.AWAITING_CLARIFICATION);

    const continuation = voice.speechStarted({ source: 'provider_vad' });
    assert.equal(continuation.state.conversationState, BROWSER_VOICE_CONVERSATION_STATES.CAPTURING);
    assert.equal(continuation.state.activeTurn.id, turnId);
    assert.equal(continuation.effects.some((item) => item.type === BROWSER_VOICE_EFFECTS.ACTIVATE_CAPTURE), false);
    voice.transcriptFinal('for 4 p.m. today titled Universal');
    voice.speechEnded({ observedSilenceMs: 2_000 });
    time.advance(0);
    assert.deepEqual(
        voice.drainEffects().filter((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY),
        [{
            type: BROWSER_VOICE_EFFECTS.TURN_READY,
            turnId,
            transcript: 'Set a reminder for 4 p.m. today titled Universal',
            clarificationContinuation: true,
            clarificationAnswer: 'for 4 p.m. today titled Universal',
            conversationContext: { mode: 'new_conversation', epoch: 1 },
        }],
    );
});

test('[BV2-FOLLOWUP-01] follow-up expires at fifteen seconds and background events cannot extend it', () => {
    const { voice, time } = createReadyVoice();
    beginWakeCapture(voice, 'calendar-turn');
    finishUtterance(voice, time, "What's on my calendar tomorrow?");
    voice.drainEffects();
    voice.drainEffects();
    voice.playbackStarted({ turnId: 'calendar-turn' });
    voice.playbackFinished({ turnId: 'calendar-turn' });
    voice.drainEffects();

    time.advance(7_500);
    voice.dispatch({ type: 'server_job_updated', source: 'server', sequence: 1 });
    assert.equal(voice.snapshot().lastRejectedEvent.reason, 'unknown_event');
    time.advance(7_499);
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP);
    time.advance(1);
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY);
});

test('[BV2-FOLLOWUP-02] meaningful follow-up speech at 14,999 ms opens a new turn without a wake phrase', () => {
    const { voice, time } = createReadyVoice();
    beginWakeCapture(voice, 'first-turn');
    finishUtterance(voice, time, 'What time is it?');
    voice.drainEffects();
    voice.playbackFinished({ turnId: 'first-turn' });
    voice.drainEffects();

    time.advance(14_999);
    voice.speechStarted({ turnId: 'follow-up-turn' });
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP);
    assert.equal(voice.snapshot().liveDraft, '');
    voice.transcriptPartial('What time is it?');
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.CAPTURING);
    assert.equal(voice.snapshot().activeTurn.id, 'follow-up-turn');
    assert.equal(voice.snapshot().liveDraft, 'What time is it?');
    time.advance(1);
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.CAPTURING);
});

test('[BV2-FOLLOWUP-03] a second request is accepted while the first submitted job is still running', () => {
    const { voice, time } = createReadyVoice();
    const firstTurnId = beginWakeCapture(voice, 'first-background-turn');
    finishUtterance(voice, time, 'Create a three-day meal plan and save it as a note.');

    assert.equal(voice.snapshot().activeTurn.id, firstTurnId);
    assert.equal(voice.snapshot().activeTurn.submitted, true);
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP);

    voice.speechStarted({ source: 'provider_vad' });
    const secondTurnId = voice.snapshot().followUpCandidate.id;
    assert.equal(voice.snapshot().activeTurn.id, firstTurnId);
    assert.equal(voice.snapshot().liveDraft, '');
    voice.transcriptPartial('Also create a note');

    assert.notEqual(secondTurnId, firstTurnId);
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.CAPTURING);
    assert.equal(voice.snapshot().activeTurn.id, secondTurnId);
    assert.ok(voice.snapshot().closedTurnIds.includes(firstTurnId));
});

test('[BV2-SEMANTIC-01] activated follow-ups cross one admission boundary without a local intent grammar', () => {
    const transcripts = [
        'What about today?',
        "What's on my to-do list for today?",
        'Okay, great. Can you set a reminder at 5 p.m. for that task?',
        'A reminder for that task at five.',
        'Set it for 5 p.m.',
        'Did you finish that?',
        'Is it going to rain tonight?',
        'Tomorrow at 4 p.m.',
        'And the date?',
        'Move that',
        'Stop',
        "Don't create the note.",
        'Cancel everything you are working on.',
        'Thanks',
        'Um, yeah.',
        'ありがとうございます。',
    ];

    for (const [index, transcript] of transcripts.entries()) {
        const { voice, time } = createReadyVoice();
        beginWakeCapture(voice, `first-${index}`);
        finishUtterance(voice, time, 'Tell me something useful.');
        voice.drainEffects();
        voice.playbackFinished({ turnId: `first-${index}` });
        voice.drainEffects();

        const turnId = `semantic-${index}`;
        voice.speechStarted({ turnId, providerItemId: `provider-${index}` });
        voice.transcriptFinal(transcript);
        voice.speechEnded({ observedSilenceMs: 2_000 });
        time.advance(0);

        const ready = voice.drainEffects().filter((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY);
        assert.equal(ready.length, 1, transcript);
        assert.equal(ready[0].turnId, turnId, transcript);
        assert.equal(ready[0].transcript, transcript, transcript);
    }
});

test('[BV2-SEMANTIC-02] one-word follow-ups are admitted without client-side question inference', () => {
    const { voice, time } = createReadyVoice();
    beginWakeCapture(voice, 'question-turn');
    finishUtterance(voice, time, 'What is on my calendar tomorrow?');
    voice.drainEffects();
    voice.playbackFinished({ turnId: 'question-turn' });
    voice.drainEffects();

    voice.speechStarted({ turnId: 'answer-turn', providerItemId: 'provider-answer' });
    voice.transcriptFinal('Yes');
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.CAPTURING);
    assert.equal(voice.snapshot().activeTurn.id, 'answer-turn');
    voice.speechEnded({ observedSilenceMs: 2_000 });
    time.advance(0);
    assert.equal(
        voice.drainEffects().filter((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY).length,
        1,
    );

    voice.playbackFinished({ turnId: 'answer-turn' });
    voice.drainEffects();
    voice.speechStarted({ turnId: 'ambient-yes', providerItemId: 'provider-ambient-yes' });
    voice.transcriptFinal('Yes');
    voice.speechEnded({ observedSilenceMs: 2_000 });
    time.advance(0);
    assert.equal(
        voice.drainEffects().filter((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY).length,
        1,
    );
});

test('[BV2-SEMANTIC-03] conversational follow-up text reaches semantic admission exactly once', () => {
    const { voice, time } = createReadyVoice();
    beginWakeCapture(voice, 'initial-answer');
    finishUtterance(voice, time, 'What time is it?');
    voice.drainEffects();
    voice.playbackFinished({ turnId: 'initial-answer' });
    voice.drainEffects();

    time.advance(10_000);
    voice.speechStarted({ turnId: 'ambient-candidate', providerItemId: 'provider-ambient' });
    voice.drainEffects();
    voice.transcriptPartial('We should get dinner');

    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.CAPTURING);
    assert.equal(voice.snapshot().activeTurn.id, 'ambient-candidate');
    assert.equal(voice.snapshot().liveDraft, 'We should get dinner');
    voice.drainEffects();

    voice.transcriptFinal('We should get dinner tonight');
    voice.speechEnded({ observedSilenceMs: 2_000 });
    time.advance(0);
    const ready = voice.drainEffects().filter((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY);
    assert.equal(ready.length, 1);
    assert.equal(ready[0].transcript, 'We should get dinner tonight');
});

test('[BV2-FOLLOWUP-06] strict Hey Bean supersedes an active semantic candidate immediately', () => {
    const { voice, time } = createReadyVoice();
    beginWakeCapture(voice, 'initial-turn');
    finishUtterance(voice, time, 'What time is it?');
    voice.drainEffects();
    voice.playbackFinished({ turnId: 'initial-turn' });
    voice.drainEffects();

    voice.speechStarted({ turnId: 'hidden-candidate', providerItemId: 'provider-hidden' });
    voice.transcriptPartial('We were talking about');
    voice.drainEffects();
    const wake = voice.wakeConfirmed({ turnId: 'strict-wake-turn' });

    assert.equal(wake.state.conversationState, BROWSER_VOICE_CONVERSATION_STATES.ACTIVATING);
    assert.equal(wake.state.activeTurn.id, 'strict-wake-turn');
    assert.equal(wake.state.followUpCandidate, null);
    assert.equal(wake.state.liveDraft, '');
    assert.equal(wake.effects.some((item) => item.type === BROWSER_VOICE_EFFECTS.ACTIVATE_CAPTURE), true);
    assert.equal(wake.effects.some((item) => item.type === BROWSER_VOICE_EFFECTS.DISCARD_FOLLOW_UP_CANDIDATE), false);
});

test('[BV2-FOLLOWUP-07] locally confirmed strict wake reuses its transcript item instead of deleting it', () => {
    const { voice, time } = createReadyVoice();
    beginWakeCapture(voice, 'initial-turn');
    finishUtterance(voice, time, 'What time is it?');
    voice.drainEffects();
    voice.playbackFinished({ turnId: 'initial-turn' });
    voice.drainEffects();

    voice.speechStarted({ turnId: 'hidden-candidate', providerItemId: 'provider-strict-item' });
    const wake = voice.wakeConfirmed({
        turnId: 'local-wake-turn',
        providerItemId: 'provider-strict-item',
        source: 'local_strict_wake',
    });

    assert.equal(wake.state.activeTurn.id, 'local-wake-turn');
    assert.equal(wake.effects.some((item) => item.type === BROWSER_VOICE_EFFECTS.DISCARD_FOLLOW_UP_CANDIDATE), false);
});

test('[BV2-ADMISSION-05] exhausted admission recovery releases the turn and cannot strand follow-up state', () => {
    const { voice, time } = createReadyVoice();
    beginWakeCapture(voice, 'failed-admission-turn');
    finishUtterance(voice, time, 'Create a task called Send RSVP.');
    voice.drainEffects();
    voice.drainEffects();

    const failed = voice.admissionFailed('failed-admission-turn');
    assert.equal(failed.state.conversationState, BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP);
    assert.equal(failed.state.activeTurn, null);
    assert.ok(failed.state.closedTurnIds.includes('failed-admission-turn'));
    assert.equal(failed.effects.some((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY), false);

    time.advance(14_999);
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP);
    time.advance(1);
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY);
});

test('[BV2-SEQUENCE-01] stale connection, turn, and provider sequences cannot mutate the current draft', () => {
    const { voice } = createReadyVoice();
    beginWakeCapture(voice, 'current-turn');
    const scope = voice.snapshot();
    voice.transcriptPartial('Current', { source: 'provider', sequence: 10 });

    voice.dispatch({
        type: 'transcript_partial',
        text: 'Stale sequence',
        turnId: 'current-turn',
        generation: scope.generation,
        connectionGeneration: scope.connectionGeneration,
        source: 'provider',
        sequence: 9,
    });
    assert.equal(voice.snapshot().liveDraft, 'Current');
    assert.equal(voice.snapshot().lastRejectedEvent.reason, 'stale_sequence');

    voice.dispatch({
        type: 'transcript_partial',
        text: 'Wrong turn',
        turnId: 'other-turn',
        generation: scope.generation,
        connectionGeneration: scope.connectionGeneration,
        source: 'provider',
        sequence: 11,
    });
    assert.equal(voice.snapshot().liveDraft, 'Current');
    assert.equal(voice.snapshot().lastRejectedEvent.reason, 'turn_mismatch');

    voice.reconnect();
    voice.dispatch({
        type: 'transcript_partial',
        text: 'Old connection',
        turnId: 'current-turn',
        generation: scope.generation,
        connectionGeneration: scope.connectionGeneration,
        source: 'provider',
        sequence: 12,
    });
    assert.equal(voice.snapshot().liveDraft, 'Current');
    assert.equal(voice.snapshot().lastRejectedEvent.reason, 'stale_connection_generation');
});

test('[BV2-SEQUENCE-02] ten thousand stale and mis-correlated events preserve the active capture', () => {
    const { voice } = createReadyVoice();
    beginWakeCapture(voice, 'protected-turn');
    voice.transcriptPartial('Keep this draft', { source: 'stable-provider', sequence: 1 });
    const scope = voice.snapshot();

    for (let index = 0; index < 10_000; index += 1) {
        const variant = index % 4;
        voice.dispatch({
            type: 'transcript_partial',
            text: `Rejected ${index}`,
            turnId: variant === 2 ? 'wrong-turn' : 'protected-turn',
            generation: variant === 0 ? scope.generation - 1 : scope.generation,
            connectionGeneration: variant === 1 ? scope.connectionGeneration - 1 : scope.connectionGeneration,
            source: variant === 3 ? 'stable-provider' : `invalid-${variant}`,
            sequence: variant === 3 ? 1 : index + 10,
        });
    }

    assert.equal(voice.snapshot().liveDraft, 'Keep this draft');
    assert.equal(voice.snapshot().activeTurn.id, 'protected-turn');
    assert.equal(voice.snapshot().rejectedEventCount, 10_000);
});

test('[BV2-RECOVERY-01] reconnect uses a new provider generation and preserves the remaining follow-up deadline', () => {
    const { voice, time } = createReadyVoice();
    beginWakeCapture(voice, 'recovery-turn');
    finishUtterance(voice, time, 'What time is it?');
    voice.drainEffects();
    voice.playbackFinished({ turnId: 'recovery-turn' });
    voice.drainEffects();

    time.advance(5_000);
    const oldConnection = voice.snapshot().connectionGeneration;
    voice.dispatch({ type: 'connection_lost' });
    voice.reconnect();
    assert.equal(voice.snapshot().connectionGeneration, oldConnection + 1);
    time.advance(4_000);
    voice.providerReady();
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP);
    time.advance(5_999);
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP);
    time.advance(1);
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY);
});

test('[BV2-CLARIFY-03] the normal endpoint admits an open phrase without a browser grammar decision', () => {
    const { voice, time } = createReadyVoice();
    const turnId = beginWakeCapture(voice, 'uncertain-turn');
    finishUtterance(voice, time, 'Create a note about');
    const endpointEffects = voice.drainEffects();
    assert.equal(endpointEffects.some((item) => item.type === BROWSER_VOICE_EFFECTS.SPEAK_CLARIFICATION), false);
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP);
    assert.deepEqual(
        endpointEffects.filter((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY),
        [{
            type: BROWSER_VOICE_EFFECTS.TURN_READY,
            turnId,
            transcript: 'Create a note about',
            conversationContext: { mode: 'new_conversation', epoch: 1 },
        }],
    );
});

test('[BV2-CLARIFY-06] an incomplete fragment is admitted once at two seconds so Hermes owns clarification', () => {
    const { voice, time } = createReadyVoice();
    const turnId = beginWakeCapture(voice, 'abandoned-uncertain-turn');
    finishUtterance(voice, time, 'Create a');
    const effects = voice.drainEffects();
    assert.equal(effects.filter((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY).length, 1);
    assert.equal(effects.some((item) => item.type === BROWSER_VOICE_EFFECTS.SPEAK_CLARIFICATION), false);
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP);
    assert.equal(voice.snapshot().activeTurn.id, turnId);
    time.advance(2_000);
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP);
    assert.equal(voice.snapshot().activeTurn.id, turnId);
    assert.equal(
        voice.drainEffects().filter((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY).length,
        0,
    );
});

test('[BV2-CONTEXT-01] follow-ups retain one context epoch while strict wake starts a new one', () => {
    const { voice, time } = createReadyVoice();
    beginWakeCapture(voice, 'context-first');
    finishUtterance(voice, time, 'What reminders do I have?');
    const first = voice.drainEffects().find((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY);
    assert.deepEqual(first.conversationContext, { mode: 'new_conversation', epoch: 1 });

    voice.playbackFinished({ turnId: 'context-first' });
    voice.speechStarted({ turnId: 'context-follow-up' });
    voice.transcriptFinal('Delete that reminder');
    voice.speechEnded({ observedSilenceMs: 2_000 });
    time.advance(0);
    const followUp = voice.drainEffects().find((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY);
    assert.deepEqual(followUp.conversationContext, { mode: 'contextual_follow_up', epoch: 1 });

    voice.wakeConfirmed({ turnId: 'context-strict-wake' });
    voice.activationReady();
    voice.speechStarted();
    voice.transcriptFinal('Delete that reminder');
    voice.speechEnded({ observedSilenceMs: 2_000 });
    time.advance(0);
    const strictWake = voice.drainEffects().find((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY);
    assert.deepEqual(strictWake.conversationContext, { mode: 'new_conversation', epoch: 2 });
});

test('[BV2-STOP-01] Stop is playback-only, preserves finalized text, and has no cancellation effect', () => {
    const stopped = [];
    const { voice, time } = createReadyVoice();
    voice.speechScheduler = { stopCurrent: (reason) => stopped.push(reason) };
    beginWakeCapture(voice, 'background-turn');
    finishUtterance(voice, time, 'Create a three-day meal plan');
    voice.drainEffects();
    const beforeStop = voice.snapshot();
    assert.equal(beforeStop.finalizedTranscript, 'Create a three-day meal plan');
    voice.drainEffects();

    const result = voice.stopPlayback();
    assert.equal(result.state.conversationState, BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY);
    assert.equal(result.state.finalizedTranscript, 'Create a three-day meal plan');
    assert.deepEqual(stopped, ['user_stop']);
    assert.equal(result.effects.some((item) => /cancel/i.test(item.type) && !item.type.includes('timer')), false);
});

test('[BV2-STOP-02] Stop during an explicit clarification keeps the same clarification active', () => {
    const { voice, time } = createReadyVoice();
    beginWakeCapture(voice, 'clarifying-turn');
    finishUtterance(voice, time, 'Create a reminder');
    voice.drainEffects();
    const turnId = voice.snapshot().activeTurn.id;
    voice.drainEffects();
    voice.admissionClarificationRequired('What time?', { turnId });

    voice.stopPlayback();
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.AWAITING_CLARIFICATION);
    assert.equal(voice.snapshot().activeTurn.id, turnId);
    time.advance(5_000);
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY);
});

test('[BV2-STOP-06] spoken Stop after barge-in is admitted once and never becomes a local cancel', () => {
    const { voice, time } = createReadyVoice();
    beginWakeCapture(voice, 'speaking-turn');
    finishUtterance(voice, time, 'Read my schedule.');
    voice.drainEffects();
    voice.playbackStarted({ turnId: 'speaking-turn' });
    voice.drainEffects();

    voice.potentialBargeIn('potential_speech');
    const barge = voice.confirmBargeIn({ turnId: 'spoken-stop-turn', source: 'provider_transcript' });
    assert.equal(barge.effects.some((item) => item.type === BROWSER_VOICE_EFFECTS.CONFIRM_INTERRUPTION), true);
    assert.equal(barge.effects.some((item) => /cancel/i.test(item.type) && !item.type.includes('timer')), false);

    // The production effect handler completes this transition on a microtask
    // after capture activation. Keep the reducer test synchronous and explicit.
    voice.activationReady({ source: 'local_wake_gate' });
    voice.transcriptFinal('Stop');
    voice.speechEnded({ observedSilenceMs: 2_000 });
    time.advance(0);
    const ready = voice.drainEffects().filter((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY);
    assert.deepEqual(ready, [{
        type: BROWSER_VOICE_EFFECTS.TURN_READY,
        turnId: 'spoken-stop-turn',
        transcript: 'Stop',
        conversationContext: { mode: 'contextual_follow_up', epoch: 1 },
    }]);
});

test('[BV2-ACK-01] a final inside the acknowledgement grace window suppresses acknowledgement audio', () => {
    const { time, playback, speech } = createSpeechHarness({ acknowledgementGraceMs: 350 });
    speech.scheduleAcknowledgement({ turnId: 'fast-turn', text: 'Let me check.' });
    time.advance(349);
    assert.equal(playback.plays.length, 0);
    assert.equal(speech.finalReady({ turnId: 'fast-turn', text: 'You have one event.' }), true);
    assert.equal(playback.plays.length, 1);
    assert.equal(playback.plays[0].item.purpose, 'final');
    time.advance(1_000);
    assert.equal(playback.plays.filter((entry) => entry.item.purpose === 'acknowledgement').length, 0);
});

test('[BV2-ACK-02] a started acknowledgement finishes before final speech and playback never overlaps', () => {
    const { time, playback, speech } = createSpeechHarness({ acknowledgementGraceMs: 350 });
    speech.scheduleAcknowledgement({ turnId: 'slow-turn', text: "I'll put that together." });
    time.advance(350);
    const acknowledgement = playback.plays[0];
    playback.start(acknowledgement);
    assert.equal(speech.snapshot().state, BROWSER_VOICE_PLAYBACK_STATES.PLAYING_ACK);

    speech.finalReady({ turnId: 'slow-turn', text: 'Your meal plan is ready.' });
    assert.equal(playback.plays.length, 1);
    assert.equal(speech.snapshot().pending.length, 1);
    playback.end(acknowledgement);
    assert.equal(playback.plays.length, 2);
    const final = playback.plays[1];
    playback.start(final);
    playback.end(final);

    assert.deepEqual(playback.plays.map((entry) => entry.item.purpose), ['acknowledgement', 'final']);
    assert.equal(playback.maxActive, 1);
    assert.equal(speech.snapshot().state, BROWSER_VOICE_PLAYBACK_STATES.IDLE);
});

test('[BV2-ACK-03] a final cancels an acknowledgement that is buffering but has not started', () => {
    const { time, playback, speech } = createSpeechHarness({ acknowledgementGraceMs: 300 });
    speech.scheduleAcknowledgement({ turnId: 'buffering-turn', text: 'Let me check.' });
    time.advance(300);
    assert.equal(speech.snapshot().state, BROWSER_VOICE_PLAYBACK_STATES.BUFFERING_ACK);

    speech.finalReady({ turnId: 'buffering-turn', text: 'It is sunny.' });
    assert.equal(playback.stops.length, 1);
    assert.equal(playback.stops[0].reason, 'final_before_ack_start');
    assert.equal(playback.plays.length, 2);
    assert.equal(playback.plays[1].item.purpose, 'final');
});

test('[BV2-BARGE-01] false barge-in ducks and restores the same audio without replaying it', () => {
    const { playback, speech } = createSpeechHarness();
    speech.finalReady({ turnId: 'speaking-turn', text: 'Here is your full answer.' });
    const final = playback.plays[0];
    playback.start(final);

    assert.equal(speech.potentialInterruption(), true);
    assert.equal(speech.snapshot().state, BROWSER_VOICE_PLAYBACK_STATES.POTENTIALLY_INTERRUPTED);
    assert.equal(playback.volumes[0].volume, 0.2);
    assert.equal(speech.rejectInterruption(), true);
    assert.equal(playback.volumes[1].volume, 1);
    assert.equal(speech.snapshot().state, BROWSER_VOICE_PLAYBACK_STATES.PLAYING_FINAL);
    assert.equal(playback.plays.length, 1);
    assert.equal(playback.stops.length, 0);
});

test('[BV2-BARGE-02] meaningful barge-in permanently stops only the current audio item', () => {
    const { playback, speech } = createSpeechHarness();
    speech.finalReady({ turnId: 'interrupted-turn', text: 'The visible answer remains complete.' });
    const final = playback.plays[0];
    playback.start(final);
    speech.potentialInterruption();

    assert.equal(speech.confirmInterruption(), true);
    assert.equal(speech.snapshot().state, BROWSER_VOICE_PLAYBACK_STATES.STOPPED);
    assert.equal(playback.stops[0].reason, 'meaningful_barge_in');
    playback.end(final);
    assert.equal(speech.snapshot().state, BROWSER_VOICE_PLAYBACK_STATES.STOPPED);
    assert.equal(playback.plays.length, 1);
});

test('[BV2-BARGE-03] the controller coordinates duck, rejection, and one replacement capture through the scheduler', () => {
    const calls = [];
    const { voice, time } = createReadyVoice();
    voice.speechScheduler = {
        potentialInterruption: (reason) => calls.push(['duck', reason]),
        rejectInterruption: (reason) => calls.push(['restore', reason]),
        confirmInterruption: (reason) => calls.push(['confirm', reason]),
        stopCurrent: (reason) => calls.push(['stop', reason]),
    };
    beginWakeCapture(voice, 'spoken-turn');
    finishUtterance(voice, time, 'Read my calendar');
    voice.drainEffects();
    voice.playbackStarted({ turnId: 'spoken-turn' });

    voice.potentialBargeIn();
    voice.rejectBargeIn();
    assert.deepEqual(calls, [['duck', 'potential_speech'], ['restore', 'not_meaningful']]);
    assert.equal(voice.snapshot().speechActive, true);

    voice.potentialBargeIn();
    voice.confirmBargeIn({ turnId: 'replacement-turn' });
    assert.deepEqual(calls.at(-1), ['confirm', 'meaningful_barge_in']);
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.ACTIVATING);
    assert.equal(voice.snapshot().activeTurn.id, 'replacement-turn');
    assert.equal(voice.snapshot().closedTurnIds.includes('spoken-turn'), true);
});

test('[BV2-BARGE-04] provisional delta followed by transcription failure restores the same playback', () => {
    const { time, playback, speech } = createSpeechHarness();
    const voice = new BrowserVoiceControllerV2({
        clock: time.clock,
        timers: { setTimeout: time.setTimeout, clearTimeout: time.clearTimeout },
        speechScheduler: speech,
    });
    voice.start();
    voice.providerReady();
    beginWakeCapture(voice, 'answer-turn');
    finishUtterance(voice, time, 'Read my calendar');
    voice.drainEffects();

    speech.finalReady({ turnId: 'answer-turn', text: 'Here is the calendar answer.' });
    const answer = playback.plays[0];
    playback.start(answer);
    voice.playbackStarted({ turnId: 'answer-turn' });
    voice.drainEffects();

    voice.potentialBargeIn('potential_speech');
    // A provider delta updates only the live draft in webApp. It deliberately
    // does not dispatch barge_in_confirmed into the lifecycle controller.
    assert.equal(voice.snapshot().speechActive, true);
    assert.equal(speech.snapshot().state, BROWSER_VOICE_PLAYBACK_STATES.POTENTIALLY_INTERRUPTED);
    assert.equal(playback.volumes.at(-1).volume, 0.2);

    voice.rejectBargeIn('transcription_failed', { source: 'provider_transcript' });

    assert.equal(speech.snapshot().state, BROWSER_VOICE_PLAYBACK_STATES.PLAYING_FINAL);
    assert.equal(playback.volumes.at(-1).volume, 1);
    assert.equal(playback.stops.length, 0);
    assert.equal(playback.plays.length, 1);
    assert.equal(voice.snapshot().speechActive, true);
    assert.equal(
        voice.drainEffects().filter((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY).length,
        0,
    );
});

test('[BV2-BARGE-05] repeated playback wording reaches one replacement Hermes turn', () => {
    const { voice, time } = createReadyVoice();
    beginWakeCapture(voice, 'original-turn');
    finishUtterance(voice, time, 'Delete the first one.');
    voice.drainEffects();
    voice.playbackStarted({ turnId: 'original-turn' });
    voice.drainEffects();

    voice.potentialBargeIn('potential_speech');
    voice.confirmBargeIn({ turnId: 'repeated-wording-turn', source: 'provider_transcript' });
    voice.activationReady({ source: 'local_wake_gate' });
    voice.transcriptFinal('Delete the first one.');
    voice.speechEnded({ observedSilenceMs: 2_000 });
    time.advance(0);

    assert.deepEqual(
        voice.drainEffects().filter((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY),
        [{
            type: BROWSER_VOICE_EFFECTS.TURN_READY,
            turnId: 'repeated-wording-turn',
            transcript: 'Delete the first one.',
            conversationContext: { mode: 'contextual_follow_up', epoch: 1 },
        }],
    );
});

test('[BV2-BARGE-06] active strict-wake wording completes once before a delayed or missing local callback', () => {
    const calls = [];
    const { voice, time } = createReadyVoice();
    voice.speechScheduler = {
        potentialInterruption: (reason) => calls.push(['duck', reason]),
        confirmInterruption: (reason) => calls.push(['confirm', reason]),
    };
    beginWakeCapture(voice, 'old-answer-turn');
    finishUtterance(voice, time, 'Tell me the weather');
    voice.drainEffects();
    voice.playbackStarted({ turnId: 'old-answer-turn' });
    voice.drainEffects();

    voice.potentialBargeIn('potential_speech');
    // "Hey Bean" in a delta is still provisional transcript content. The
    // active conversation, not those words, is what permits this barge path.
    assert.equal(voice.snapshot().activeTurn.id, 'old-answer-turn');
    assert.equal(voice.snapshot().speechActive, true);

    const confirmed = voice.confirmBargeIn({ turnId: 'stable-barge-turn', source: 'provider_transcript' });
    assert.equal(confirmed.state.conversationState, BROWSER_VOICE_CONVERSATION_STATES.ACTIVATING);
    voice.activationReady({ source: 'provider_transcript' });
    voice.transcriptFinal('Hey Bean, delete the first one.', { source: 'provider_transcript' });
    voice.speechEnded({ source: 'provider_vad', observedSilenceMs: 2_000 });
    time.advance(0);

    const ready = voice.drainEffects().filter((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY);
    assert.deepEqual(ready, [{
        type: BROWSER_VOICE_EFFECTS.TURN_READY,
        turnId: 'stable-barge-turn',
        transcript: 'Hey Bean, delete the first one.',
        conversationContext: { mode: 'contextual_follow_up', epoch: 1 },
    }]);
    assert.deepEqual(calls, [
        ['duck', 'potential_speech'],
        ['confirm', 'meaningful_barge_in'],
    ]);

    const snapshot = voice.snapshot();
    const completedBarge = {
        turnId: 'stable-barge-turn',
        controllerGeneration: snapshot.generation,
        connectionGeneration: snapshot.connectionGeneration,
        gateGeneration: 7,
        throughSourceSequence: 90,
    };
    const delayedSamePcm = browserVoiceV2LocalWakeMatchesCompletedBarge({
        snapshot,
        completedBarge,
        connectionGeneration: snapshot.connectionGeneration,
        gateGeneration: 7,
        sourceSequence: 90,
    });
    if (!delayedSamePcm) voice.wakeConfirmed({ turnId: 'duplicate-local-turn', source: 'local_wake_gate' });

    assert.equal(delayedSamePcm, true);
    assert.equal(voice.snapshot().activeTurn.id, 'stable-barge-turn');
    assert.equal(
        voice.drainEffects().filter((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY).length,
        0,
    );
    assert.equal(browserVoiceV2LocalWakeMatchesCompletedBarge({
        snapshot: voice.snapshot(),
        completedBarge,
        connectionGeneration: snapshot.connectionGeneration,
        gateGeneration: 7,
        sourceSequence: 91,
    }), false, 'later local PCM must remain eligible to begin a genuinely new wake turn');
});

test('[BV2-WAKE-02] a strict wake while Bean is speaking stops playback and begins a fresh capture only once', () => {
    const calls = [];
    const { voice, time } = createReadyVoice();
    voice.speechScheduler = { stopCurrent: (reason) => calls.push(reason) };
    beginWakeCapture(voice, 'old-spoken-turn');
    finishUtterance(voice, time, 'Tell me the weather');
    voice.drainEffects();
    voice.playbackStarted({ turnId: 'old-spoken-turn' });

    voice.wakeConfirmed({ turnId: 'new-wake-turn' });
    assert.deepEqual(calls, ['wake']);
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.ACTIVATING);
    assert.equal(voice.snapshot().activeTurn.id, 'new-wake-turn');
});

test('[BV2-WAKE-05] strict wake discards only current playback and preserves unrelated queued finals', () => {
    const { playback, speech } = createSpeechHarness();
    speech.finalReady({ turnId: 'current-turn', text: 'Current answer.' });
    speech.finalReady({ turnId: 'queued-one', text: 'First queued answer.' });
    speech.finalReady({ turnId: 'queued-two', text: 'Second queued answer.' });
    const current = playback.plays[0];
    playback.start(current);

    const voice = new BrowserVoiceControllerV2({ speechScheduler: speech, createTurnId: () => 'fresh-wake-turn' });
    voice.start();
    voice.providerReady();
    voice.playbackStarted({ turnId: 'current-turn' });
    voice.wakeConfirmed({ source: 'local_strict_wake' });

    assert.equal(playback.stops.at(-1).reason, 'wake');
    assert.deepEqual(speech.snapshot().pending.map((item) => item.turnId), ['queued-one', 'queued-two']);
    assert.equal(voice.snapshot().activeTurn.id, 'fresh-wake-turn');
});

test('[BV2-WAKE-06] strict local wake replaces a capture or clarification with one fresh stable turn', () => {
    const { voice, time } = createReadyVoice();
    beginWakeCapture(voice, 'partial-old-turn');
    voice.transcriptPartial('Create a note');
    voice.wakeConfirmed({ turnId: 'local-wake-during-capture', source: 'local_strict_wake' });
    assert.equal(voice.snapshot().activeTurn.id, 'local-wake-during-capture');
    assert.ok(voice.snapshot().closedTurnIds.includes('partial-old-turn'));

    voice.activationReady();
    voice.transcriptFinal('Create a reminder');
    voice.speechEnded();
    time.advance(2_000);
    voice.drainEffects();
    voice.admissionClarificationRequired('When?', { turnId: 'local-wake-during-capture' });
    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.AWAITING_CLARIFICATION);
    voice.wakeConfirmed({ turnId: 'local-wake-during-clarification', source: 'local_strict_wake' });
    assert.equal(voice.snapshot().activeTurn.id, 'local-wake-during-clarification');
    assert.ok(voice.snapshot().closedTurnIds.includes('local-wake-during-capture'));
});

test('[BV2-SPEECH-04] buffered TTS is canceled and deferred while capture owns audio', () => {
    const { playback, speech } = createSpeechHarness();
    speech.finalReady({ turnId: 'buffered-final', text: 'Do not talk over the user.' });
    const abandoned = playback.plays[0];
    assert.equal(abandoned.started, false);

    assert.equal(speech.captureStarted(), true);
    assert.equal(abandoned.stopped, true);
    assert.equal(speech.snapshot().captureBlocked, true);
    assert.equal(speech.snapshot().pending.length, 1);
    assert.equal(playback.start(abandoned), undefined);
    assert.equal(speech.drainEvents().some((event) => event.type === 'playback.started'), false);

    assert.equal(speech.captureEnded(), true);
    assert.equal(playback.plays.length, 2);
    playback.start(playback.plays[1]);
    assert.equal(speech.snapshot().state, BROWSER_VOICE_PLAYBACK_STATES.PLAYING_FINAL);
});

test('[BV2-SPEECH-05] strict wake discards the capture-deferred current item but not unrelated finals', () => {
    const { playback, speech } = createSpeechHarness();
    speech.finalReady({ turnId: 'buffered-current', text: 'Current buffered final.' });
    speech.finalReady({ turnId: 'unrelated-final', text: 'Independent queued final.' });
    speech.captureStarted('follow_up_capture');

    const voice = new BrowserVoiceControllerV2({
        speechScheduler: speech,
        createTurnId: () => 'strict-wake-turn',
    });
    voice.start();
    voice.providerReady();
    voice.wakeConfirmed({ source: 'local_strict_wake' });

    assert.equal(speech.snapshot().captureDeferredItemId, null);
    assert.deepEqual(speech.snapshot().pending.map((item) => item.turnId), ['unrelated-final']);
    assert.equal(playback.stops.filter((entry) => entry.item?.turnId === 'buffered-current').length, 1);
});

test('[BV2-STOP-04] Stop during capture does not immediately restart deferred speech', () => {
    const { playback, speech } = createSpeechHarness();
    speech.finalReady({ turnId: 'deferred-final', text: 'Deferred final.' });
    speech.captureStarted('follow_up_capture');
    speech.stopCurrent('user_stop');
    speech.captureEnded('voice_wake_only', { resume: false });

    assert.equal(speech.snapshot().state, BROWSER_VOICE_PLAYBACK_STATES.STOPPED);
    assert.equal(speech.snapshot().pending.length, 1);
    assert.equal(playback.plays.length, 1);
});

test('[BV2-STOP-03] scheduler Stop retains pending speech and does not expose a task-cancel API', () => {
    const { time, playback, speech } = createSpeechHarness({ acknowledgementGraceMs: 100 });
    speech.scheduleAcknowledgement({ turnId: 'work-turn', text: "I'll work on that." });
    time.advance(100);
    const acknowledgement = playback.plays[0];
    playback.start(acknowledgement);
    speech.finalReady({ turnId: 'work-turn', text: 'The work completed.' });
    assert.equal(speech.snapshot().pending.length, 1);

    speech.stopCurrent('user_stop');
    assert.equal(speech.snapshot().state, BROWSER_VOICE_PLAYBACK_STATES.STOPPED);
    assert.equal(speech.snapshot().pending.length, 1);
    assert.equal(typeof speech.cancelTask, 'undefined');
    assert.equal(playback.plays.length, 1);

    speech.resume();
    assert.equal(playback.plays.length, 2);
    assert.equal(playback.plays[1].item.purpose, 'final');
});

test('[BV2-SPEECH-01] duplicate final events produce exactly one speech item', () => {
    const { playback, speech } = createSpeechHarness();
    assert.equal(speech.finalReady({ turnId: 'exactly-once', text: 'Done.' }), true);
    assert.equal(speech.finalReady({ turnId: 'exactly-once', text: 'Done again.' }), false);
    assert.equal(playback.plays.length, 1);
    assert.equal(speech.snapshot().pending.length, 0);
});

test('[BV2-SPEECH-02] one hundred out-of-order finals remain strictly non-overlapping', () => {
    const { playback, speech } = createSpeechHarness();
    for (let index = 99; index >= 0; index -= 1) {
        speech.finalReady({ turnId: `turn-${index}`, text: `Finished work ${index}.` });
    }
    assert.equal(playback.plays.length, 1);
    assert.equal(speech.snapshot().pending.length, 99);

    for (let index = 0; index < 100; index += 1) {
        const handle = playback.plays[index];
        playback.start(handle);
        playback.end(handle);
    }
    assert.equal(playback.plays.length, 100);
    assert.equal(playback.maxActive, 1);
    assert.equal(speech.snapshot().state, BROWSER_VOICE_PLAYBACK_STATES.IDLE);
});

test('[BV2-SPEECH-03] a background final cannot strand a different live capture', () => {
    const { voice } = createReadyVoice();
    beginWakeCapture(voice, 'live-turn');
    voice.playbackStarted({ turnId: 'background-turn', source: 'speech_scheduler' });
    voice.playbackFinished({ turnId: 'background-turn', source: 'speech_scheduler' });

    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.CAPTURING);
    assert.equal(voice.snapshot().activeTurn.id, 'live-turn');
    assert.equal(voice.snapshot().speechActive, false);
});

test('[BV2-TRANSCRIPT-03] a provider transcription failure releases capture without admitting work', () => {
    const { voice } = createReadyVoice();
    beginWakeCapture(voice, 'failed-capture-turn');
    voice.transcriptPartial('Create a');
    voice.captureFailed('transcription_failed');

    assert.equal(voice.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP);
    assert.equal(voice.snapshot().activeTurn, null);
    assert.equal(voice.snapshot().liveDraft, '');
    assert.ok(voice.snapshot().closedTurnIds.includes('failed-capture-turn'));
    assert.equal(voice.drainEffects().some((effect) => effect.type === BROWSER_VOICE_EFFECTS.TURN_READY), false);
});
