import assert from 'node:assert/strict';
import test from 'node:test';

import {
    VOICE_SESSION_STATES,
    VOICE_TURN_PHASES,
    VoiceOrchestrator,
} from '../../resources/js/heybean/voiceOrchestrator.js';

test('wake, transcript, backend, playback, and follow-up form one terminal lifecycle', async () => {
    let now = 1000;
    const voice = new VoiceOrchestrator({ clock: () => now });
    const generation = voice.start();
    assert.equal(voice.connected(generation), true);
    const wake = voice.activateFromLocalWake();
    assert.equal(wake.accepted, true);
    voice.noteTranscriptOrigin('input-1');
    voice.beginTranscribing('input-1');
    const admission = voice.admitTranscript({ id: 'input-1', content: 'Check my calendar' });
    assert.equal(admission.accepted, true);
    voice.beginWork();
    now = 1250;
    voice.endWork();
    voice.terminal('completed');
    assert.equal(voice.snapshot().sessionState, VOICE_SESSION_STATES.ACTIVE);
    assert.equal(voice.snapshot().phase, VOICE_TURN_PHASES.TERMINAL);
    assert.equal(voice.sleep(), voice.capture());
    assert.equal(voice.snapshot().sessionState, VOICE_SESSION_STATES.WAKE_ONLY);
});

test('a wake admitted during connection startup survives the connected boundary', () => {
    const voice = new VoiceOrchestrator();
    const generation = voice.start();
    const wake = voice.activateFromLocalWake();

    assert.equal(wake.accepted, true);
    assert.equal(voice.snapshot().sessionState, VOICE_SESSION_STATES.ACTIVE);
    assert.equal(voice.connected(generation), true);
    assert.equal(voice.snapshot().sessionState, VOICE_SESSION_STATES.ACTIVE);
    assert.equal(voice.localWakePending, true);
});

test('additive work is FIFO and prevents conversation sleep until drained', () => {
    const voice = new VoiceOrchestrator();
    voice.connected(voice.start());
    voice.activateFromLocalWake();
    voice.admitTranscript({ id: 'one', content: 'Check my calendar' });
    voice.beginWork();
    voice.enqueue({ id: 'two', transcript: 'Also create a reminder', admission: { epoch: voice.capture() } });
    voice.enqueue({ id: 'three', transcript: 'Then add a task', admission: { epoch: voice.capture() } });
    assert.equal(voice.sleep(), false);
    assert.equal(voice.dequeue().id, 'two');
    assert.equal(voice.dequeue().id, 'three');
    voice.endWork();
    assert.equal(voice.hasPendingWork(), false);
});

test('stale and duplicate transcript events cannot affect the current generation', () => {
    const voice = new VoiceOrchestrator();
    const firstGeneration = voice.start();
    voice.connected(firstGeneration);
    voice.activateFromLocalWake();
    voice.noteTranscriptOrigin('old-input');
    voice.disconnect();
    const nextGeneration = voice.start();
    voice.connected(nextGeneration);
    assert.equal(voice.admitTranscript({ id: 'old-input', content: 'Create a reminder' }).reason, 'wake_required');
    voice.activateFromLocalWake();
    assert.equal(voice.admitTranscript({ id: 'new-input', content: 'Create a reminder' }).accepted, true);
    assert.equal(voice.admitTranscript({ id: 'new-input', content: 'Create a reminder' }).reason, 'duplicate');
});

test('a response resolves once under duplicate and out-of-order provider events', async () => {
    let now = 10;
    const voice = new VoiceOrchestrator({ clock: () => now });
    voice.connected(voice.start());
    voice.activateFromLocalWake();
    voice.admitTranscript({ content: 'What time is it?' });
    const completion = voice.responses.begin('final');
    const clientId = voice.responses.currentClientResponseId();
    assert.equal(voice.responses.bindResponse('response-1', clientId), true);
    voice.responses.markAudioStarted('response-1');
    voice.responses.markResponseDone('response-1');
    now = 20;
    voice.responses.markAudioStopped('response-1');
    assert.equal((await completion).reason, 'completed');
    assert.equal(voice.responses.markAudioStopped('response-1'), null);
});

test('random stale terminal events never create duplicate terminal outcomes', () => {
    const voice = new VoiceOrchestrator();
    voice.connected(voice.start());
    voice.activateFromLocalWake();
    voice.admitTranscript({ content: 'Set a reminder' });
    assert.equal(voice.terminal('completed'), true);
    for (let index = 0; index < 10_000; index += 1) {
        assert.equal(voice.terminal(index % 2 ? 'failed' : 'timed_out'), false);
    }
    assert.equal(voice.terminals.size, 1);
});

test('a correction supersedes exactly one active turn and starts a new epoch', () => {
    const voice = new VoiceOrchestrator();
    voice.connected(voice.start());
    voice.activateFromLocalWake();
    voice.admitTranscript({ content: 'Check Friday weather' });
    const originalTurnId = voice.activeTurn.id;
    const originalEpoch = voice.capture();
    const correction = voice.supersedeTranscript({ content: 'Actually use Saturday' });
    assert.equal(correction.accepted, true);
    assert.ok(correction.epoch > originalEpoch);
    assert.equal(voice.terminals.get(originalTurnId).outcome, 'superseded');
    assert.notEqual(voice.activeTurn.id, originalTurnId);
});

test('stop terminalizes active work, clears FIFO, and invalidates stale events', () => {
    const voice = new VoiceOrchestrator();
    voice.connected(voice.start());
    voice.activateFromLocalWake();
    voice.admitTranscript({ content: 'Check my calendar' });
    const activeTurnId = voice.activeTurn.id;
    voice.beginWork();
    voice.enqueue({ transcript: 'Also add a reminder' });
    const oldEpoch = voice.capture();
    voice.stop('user_stop');
    assert.equal(voice.backendActive, false);
    assert.equal(voice.hasQueue(), false);
    assert.equal(voice.terminals.get(activeTurnId).outcome, 'cancelled');
    assert.equal(voice.canContinue(oldEpoch), false);
});

test('ten thousand deterministic lifecycle events preserve invariants and terminal uniqueness', () => {
    let seed = 0x5eed1234;
    const random = () => {
        seed = (seed * 1664525 + 1013904223) >>> 0;
        return seed / 0x100000000;
    };
    const voice = new VoiceOrchestrator();
    voice.connected(voice.start());
    for (let index = 0; index < 10_000; index += 1) {
        if (!voice.isActive()) voice.activateFromLocalWake();
        if (!voice.activeTurn || voice.terminals.has(voice.activeTurn.id)) {
            voice.admitTranscript({ id: `input-${index}`, content: `request ${index}` });
        }
        const choice = Math.floor(random() * 5);
        if (choice === 0 && !voice.backendActive) voice.beginWork();
        if (choice === 1 && voice.backendActive) voice.endWork();
        if (choice === 2) voice.enqueue({ id: `queued-${index}`, transcript: `also request ${index}` });
        if (choice === 3 && voice.hasQueue()) voice.dequeue();
        if (choice === 4 && !voice.backendActive && !voice.responseActive) voice.terminal('completed');
        voice.assertInvariants();
    }
    assert.equal(
        [...voice.terminals.keys()].length,
        new Set(voice.terminals.keys()).size,
    );
});
