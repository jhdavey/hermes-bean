import assert from 'node:assert/strict';
import test from 'node:test';
import {
    BROWSER_VOICE_CONVERSATION_STATES,
    BROWSER_VOICE_EFFECTS,
    BrowserVoiceControllerV2,
} from '../../resources/js/heybean/browserVoiceControllerV2.js';
import {
    BROWSER_VOICE_PROVIDER_ITEM_BINDING_MODES,
    BROWSER_VOICE_REALTIME_INGRESS_RESULTS,
    BrowserVoiceProviderItemRegistryV2,
    bindBrowserVoiceProviderSpeechStartedV2,
    browserVoiceProviderItemBindingIsCurrentV2,
    browserVoiceProviderItemCapacityExhaustedV2,
    createBrowserVoiceProviderTurnIdentityV2,
    currentBrowserVoiceProviderItemBindingV2,
    routeBrowserVoiceRealtimeIngressV2,
} from '../../resources/js/heybean/browserVoiceRealtimeIngressV2.js';

function readyController() {
    let now = 0;
    const controller = new BrowserVoiceControllerV2({
        clock: () => now,
        timers: { setTimeout: () => 1, clearTimeout: () => {} },
    });
    controller.start();
    controller.providerReady();
    return { controller, setNow: (value) => { now = value; } };
}

test('[BV2-BROWSER-11] real provider event order admits the first contextual reminder follow-up exactly once', () => {
    const { controller, setNow } = readyController();
    controller.wakeConfirmed({ turnId: 'task-read' });
    controller.activationReady();
    controller.transcriptFinal("What's on my to-do list for today?");
    controller.speechEnded({ observedSilenceMs: 2000 });
    const beforeEndpoint = controller.snapshot();
    controller.dispatch({
        type: 'timer_fired',
        timerKey: 'endpoint',
        turnId: 'task-read',
        generation: beforeEndpoint.generation,
        connectionGeneration: beforeEndpoint.connectionGeneration,
        source: 'timer:endpoint',
    });
    controller.playbackStarted({ turnId: 'task-read' });
    controller.playbackFinished({ turnId: 'task-read' });
    controller.drainEffects();

    setNow(6000);
    routeBrowserVoiceRealtimeIngressV2(controller, {
        type: 'speech_started',
        providerItemId: 'provider-reminder',
    });
    routeBrowserVoiceRealtimeIngressV2(controller, {
        type: 'transcript_partial',
        text: 'Can you set a reminder for salt',
        providerItemId: 'provider-reminder',
    });
    routeBrowserVoiceRealtimeIngressV2(controller, {
        type: 'transcript_final',
        text: 'Can you set a reminder for salt for five p.m.?',
        providerItemId: 'provider-reminder',
    });
    const followUp = controller.snapshot();
    controller.dispatch({
        type: 'timer_fired',
        timerKey: 'endpoint',
        turnId: followUp.activeTurn.id,
        generation: followUp.generation,
        connectionGeneration: followUp.connectionGeneration,
        source: 'timer:endpoint',
    });
    const admissions = controller.drainEffects()
        .filter((effect) => effect.type === BROWSER_VOICE_EFFECTS.TURN_READY);
    assert.equal(admissions.length, 1);
    assert.equal(admissions[0].transcript, 'Can you set a reminder for salt for five p.m.?');
    assert.deepEqual(admissions[0].conversationContext, { mode: 'contextual_follow_up', epoch: 1 });
});

test('[BV2-TRANSCRIPT-03][BV2-DIAGNOSTIC-03] provider item bindings are exact, scoped, consumed once, and bounded', () => {
    const { controller } = readyController();
    const registry = new BrowserVoiceProviderItemRegistryV2({ limit: 3 });
    controller.wakeConfirmed({ turnId: 'older-turn' });
    controller.activationReady();

    const older = bindBrowserVoiceProviderSpeechStartedV2(controller, registry, {
        providerItemId: 'older-item',
        connectionGeneration: 9,
    });
    assert.equal(older.result, BROWSER_VOICE_REALTIME_INGRESS_RESULTS.ROUTED);
    assert.equal(older.binding.turnId, 'older-turn');
    assert.equal(bindBrowserVoiceProviderSpeechStartedV2(controller, registry, {
        providerItemId: 'older-item',
        connectionGeneration: 9,
    }).result, BROWSER_VOICE_REALTIME_INGRESS_RESULTS.IGNORED);

    controller.wakeConfirmed({ turnId: 'newer-turn' });
    controller.activationReady();
    assert.equal(currentBrowserVoiceProviderItemBindingV2(registry, controller, {
        providerItemId: 'older-item',
        connectionGeneration: 9,
        consume: true,
    }), null, 'an older item may not attach to the newer capture');

    const newer = bindBrowserVoiceProviderSpeechStartedV2(controller, registry, {
        providerItemId: 'newer-item',
        connectionGeneration: 9,
    });
    assert.equal(newer.binding.turnId, 'newer-turn');
    assert.equal(currentBrowserVoiceProviderItemBindingV2(registry, controller, {
        providerItemId: 'newer-item',
        connectionGeneration: 9,
        consume: true,
    })?.turnId, 'newer-turn');
    assert.equal(currentBrowserVoiceProviderItemBindingV2(registry, controller, {
        providerItemId: 'newer-item',
        connectionGeneration: 9,
        consume: true,
    }), null, 'a duplicate terminal provider event must hit the tombstone');

    assert.equal(bindBrowserVoiceProviderSpeechStartedV2(controller, registry, {
        providerItemId: 'newer-item',
        connectionGeneration: 10,
    }).binding?.turnId, 'newer-turn', 'provider identities are scoped to their connection');
    registry.consume({ providerItemId: 'unowned-one', connectionGeneration: 9 });
    registry.consume({ providerItemId: 'unowned-two', connectionGeneration: 9 });
    assert.equal(registry.size, 3);
    const exhausted = bindBrowserVoiceProviderSpeechStartedV2(controller, registry, {
        providerItemId: 'capacity-item',
        connectionGeneration: 9,
    });
    assert.equal(exhausted.result, BROWSER_VOICE_REALTIME_INGRESS_RESULTS.CAPACITY_EXHAUSTED);
    assert.equal(exhausted.capacityExhausted, true);
    assert.equal(registry.size, 3, 'capacity must never evict an existing binding or tombstone');
    assert.equal(registry.has({ providerItemId: 'older-item', connectionGeneration: 9 }), true);
    assert.equal(bindBrowserVoiceProviderSpeechStartedV2(controller, registry, {
        providerItemId: 'older-item',
        connectionGeneration: 9,
    }).result, BROWSER_VOICE_REALTIME_INGRESS_RESULTS.IGNORED,
    'an oldest tombstone must never become rebindable within the connection');
});

test('[BV2-FIRST-WAKE-01:E][BV2-TRANSCRIPT-03] delayed provider start cannot bind an older PCM admission to a newer strict wake', () => {
    const { controller } = readyController();
    const registry = new BrowserVoiceProviderItemRegistryV2();
    controller.wakeConfirmed({ turnId: 'older-local-wake' });
    const olderIdentity = createBrowserVoiceProviderTurnIdentityV2(controller, {
        inputGeneration: 31,
        throughSourceSequence: 410,
        providerConnectionGeneration: 18,
    });
    controller.activationReady();

    controller.wakeConfirmed({ turnId: 'newer-local-wake' });
    const newerIdentity = createBrowserVoiceProviderTurnIdentityV2(controller, {
        inputGeneration: 32,
        throughSourceSequence: 520,
        providerConnectionGeneration: 18,
    });
    const beforeDelayedStart = controller.snapshot();

    const delayed = bindBrowserVoiceProviderSpeechStartedV2(controller, registry, {
        providerItemId: 'provider-item-from-older-pcm',
        connectionGeneration: 18,
        turnIdentity: olderIdentity,
        inputGeneration: 32,
        throughSourceSequence: 520,
    });
    assert.equal(delayed.result, BROWSER_VOICE_REALTIME_INGRESS_RESULTS.IGNORED);
    assert.equal(delayed.binding, null);
    assert.equal(delayed.staleTurnIdentity, true);
    assert.equal(registry.has({
        providerItemId: 'provider-item-from-older-pcm',
        connectionGeneration: 18,
    }), true, 'the delayed item is tombstoned before it can mutate the newer turn');
    assert.deepEqual(controller.snapshot(), beforeDelayedStart);

    const beforePcmAdmission = bindBrowserVoiceProviderSpeechStartedV2(controller, registry, {
        providerItemId: 'provider-item-before-newer-pcm-boundary',
        connectionGeneration: 18,
        turnIdentity: newerIdentity,
        inputGeneration: 32,
        throughSourceSequence: 519,
    });
    assert.equal(beforePcmAdmission.result, BROWSER_VOICE_REALTIME_INGRESS_RESULTS.IGNORED);
    assert.equal(beforePcmAdmission.staleTurnIdentity, true,
        'the correct turn still cannot claim provider VAD before its exact PCM boundary was admitted');
    assert.deepEqual(controller.snapshot(), beforeDelayedStart);

    const current = bindBrowserVoiceProviderSpeechStartedV2(controller, registry, {
        providerItemId: 'provider-item-from-newer-pcm',
        connectionGeneration: 18,
        turnIdentity: newerIdentity,
        inputGeneration: 32,
        throughSourceSequence: 520,
    });
    assert.equal(current.result, BROWSER_VOICE_REALTIME_INGRESS_RESULTS.ROUTED);
    assert.equal(current.binding.turnId, 'newer-local-wake');
    assert.equal(controller.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.CAPTURING);
});

test('[BV2-BARGE-04][BV2-TRANSCRIPT-03] a barge item survives playback completion only inside its original epoch', () => {
    const { controller } = readyController();
    const registry = new BrowserVoiceProviderItemRegistryV2();
    controller.wakeConfirmed({ turnId: 'spoken-turn' });
    controller.activationReady();
    controller.transcriptFinal('Read my calendar.');
    controller.speechEnded({ observedSilenceMs: 2_000 });
    let snapshot = controller.snapshot();
    controller.dispatch({
        type: 'timer_fired',
        timerKey: 'endpoint',
        turnId: 'spoken-turn',
        generation: snapshot.generation,
        connectionGeneration: snapshot.connectionGeneration,
        source: 'timer:endpoint',
        atMs: snapshot.deadlines.endpointAt,
    });
    controller.playbackStarted({ turnId: 'spoken-turn' });

    const started = bindBrowserVoiceProviderSpeechStartedV2(controller, registry, {
        providerItemId: 'barge-item',
        connectionGeneration: 4,
    });
    assert.equal(started.result, BROWSER_VOICE_REALTIME_INGRESS_RESULTS.POTENTIAL_BARGE_IN);
    assert.equal(started.binding.mode, BROWSER_VOICE_PROVIDER_ITEM_BINDING_MODES.BARGE);
    assert.equal(started.binding.turnId, 'spoken-turn');
    controller.potentialBargeIn('potential_speech', {
        source: 'provider_vad',
        ownerTurnId: started.binding.turnId,
        providerItemId: started.binding.providerItemId,
    });
    controller.playbackFinished({ turnId: 'spoken-turn' });
    snapshot = controller.snapshot();
    assert.equal(snapshot.activeTurn, null);
    assert.equal(snapshot.conversationState, 'follow_up');
    assert.equal(browserVoiceProviderItemBindingIsCurrentV2(controller, started.binding), true,
        'playback completion may clear only the submitted owner without orphaning its barge item');
    assert.equal(currentBrowserVoiceProviderItemBindingV2(registry, controller, {
        providerItemId: 'barge-item',
        connectionGeneration: 4,
    }), started.binding);

    for (const mutation of [
        { generation: snapshot.generation + 1 },
        { connectionGeneration: snapshot.connectionGeneration + 1 },
        { conversationEpoch: snapshot.conversationEpoch + 1 },
        { activeTurn: { id: 'different-turn' } },
    ]) {
        const changedController = { snapshot: () => ({ ...snapshot, ...mutation }) };
        assert.equal(browserVoiceProviderItemBindingIsCurrentV2(changedController, started.binding), false);
    }

    controller.wakeConfirmed({ turnId: 'different-turn' });
    assert.equal(currentBrowserVoiceProviderItemBindingV2(registry, controller, {
        providerItemId: 'barge-item',
        connectionGeneration: 4,
        consume: true,
    }), null, 'a new strict turn must invalidate the older barge item');
    assert.equal(browserVoiceProviderItemCapacityExhaustedV2(
        registry.consume({ providerItemId: 'never-owned', connectionGeneration: 4 }),
    ), false);
});

test('[BV2-BARGE-04][BV2-FOLLOWUP-01] rejecting an ordinary post-playback barge preserves the real follow-up deadline', () => {
    const { controller } = readyController();
    const registry = new BrowserVoiceProviderItemRegistryV2();
    controller.wakeConfirmed({ turnId: 'ordinary-owner' });
    controller.activationReady();
    controller.transcriptFinal('Read my calendar.');
    controller.speechEnded({ observedSilenceMs: 2_000 });
    let snapshot = controller.snapshot();
    controller.dispatch({
        type: 'timer_fired',
        timerKey: 'endpoint',
        turnId: 'ordinary-owner',
        source: 'timer:endpoint:ordinary',
        atMs: snapshot.deadlines.endpointAt,
    });
    controller.playbackStarted({ turnId: 'ordinary-owner' });
    const started = bindBrowserVoiceProviderSpeechStartedV2(controller, registry, {
        providerItemId: 'ordinary-barge-item',
        connectionGeneration: 14,
    });
    controller.potentialBargeIn('potential_speech', {
        source: 'provider_vad',
        ownerTurnId: started.binding.turnId,
        providerItemId: started.binding.providerItemId,
    });
    controller.playbackFinished({ turnId: 'ordinary-owner' });
    snapshot = controller.snapshot();
    const originalFollowUpAt = snapshot.deadlines.followUpAt;
    assert.equal(snapshot.conversationState, BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP);
    assert.equal(snapshot.potentialBargeIn.returnToWakeOnly, false);
    assert.equal(browserVoiceProviderItemBindingIsCurrentV2(controller, started.binding), true);

    const rejected = controller.rejectBargeIn('background_or_noise', {
        source: 'provider_transcript',
        providerItemId: 'ordinary-barge-item',
    });
    assert.equal(rejected.state.conversationState, BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP);
    assert.equal(rejected.state.potentialBargeIn, null);
    assert.equal(rejected.state.deadlines.followUpAt, originalFollowUpAt);
    assert.equal(rejected.effects.some((item) => item.type === BROWSER_VOICE_EFFECTS.CANCEL_TIMER), false);
    assert.equal(currentBrowserVoiceProviderItemBindingV2(registry, controller, {
        providerItemId: 'ordinary-barge-item',
        connectionGeneration: 14,
        consume: true,
    }), null);

    controller.dispatch({
        type: 'timer_fired',
        timerKey: 'follow_up',
        source: 'timer:follow_up:ordinary',
        atMs: originalFollowUpAt,
    });
    assert.equal(controller.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY);
    assert.equal(controller.snapshot().deadlines.followUpAt, null);
});

test('[BV2-BARGE-04][BV2-TRANSCRIPT-03] overlapping provider items cannot replace the exact potential-barge owner', () => {
    const { controller } = readyController();
    const registry = new BrowserVoiceProviderItemRegistryV2();
    controller.wakeConfirmed({ turnId: 'overlap-owner' });
    controller.activationReady();
    controller.transcriptFinal('Read my tasks.');
    controller.speechEnded({ observedSilenceMs: 2_000 });
    let snapshot = controller.snapshot();
    controller.dispatch({
        type: 'timer_fired',
        timerKey: 'endpoint',
        turnId: 'overlap-owner',
        source: 'timer:endpoint:overlap-owner',
        atMs: snapshot.deadlines.endpointAt,
    });
    controller.playbackStarted({ turnId: 'overlap-owner' });
    controller.drainEffects();

    const first = bindBrowserVoiceProviderSpeechStartedV2(controller, registry, {
        providerItemId: 'proof-owning-item',
        connectionGeneration: 15,
    });
    assert.equal(first.result, BROWSER_VOICE_REALTIME_INGRESS_RESULTS.POTENTIAL_BARGE_IN);
    controller.potentialBargeIn('potential_speech', {
        source: 'provider_vad',
        ownerTurnId: first.binding.turnId,
        providerItemId: first.binding.providerItemId,
    });

    const overlapping = bindBrowserVoiceProviderSpeechStartedV2(controller, registry, {
        providerItemId: 'overlapping-item',
        connectionGeneration: 15,
    });
    assert.equal(overlapping.result, BROWSER_VOICE_REALTIME_INGRESS_RESULTS.IGNORED);
    assert.equal(overlapping.binding, null);
    assert.equal(registry.has({
        providerItemId: 'overlapping-item',
        connectionGeneration: 15,
    }), true, 'the rejected overlap stays tombstoned for the connection');
    assert.equal(currentBrowserVoiceProviderItemBindingV2(registry, controller, {
        providerItemId: 'overlapping-item',
        connectionGeneration: 15,
        consume: true,
    }), null);

    const wrongConfirmation = controller.confirmBargeIn({
        turnId: 'overlap-must-not-admit',
        source: 'provider_transcript:overlap',
        providerItemId: 'overlapping-item',
    });
    assert.equal(wrongConfirmation.state.activeTurn.id, 'overlap-owner');
    assert.equal(wrongConfirmation.state.lastRejectedEvent.reason, 'potential_barge_mismatch');

    assert.equal(currentBrowserVoiceProviderItemBindingV2(registry, controller, {
        providerItemId: 'proof-owning-item',
        connectionGeneration: 15,
        consume: true,
    }), first.binding);
    const confirmed = controller.confirmBargeIn({
        turnId: 'exact-overlap-interruption',
        source: 'provider_transcript:owner',
        providerItemId: 'proof-owning-item',
    });
    assert.equal(confirmed.state.activeTurn.id, 'exact-overlap-interruption');
    controller.activationReady({ source: 'provider_transcript:owner' });
    routeBrowserVoiceRealtimeIngressV2(controller, {
        type: 'transcript_final',
        text: 'Actually, add one task.',
        providerItemId: 'proof-owning-item',
    });
    snapshot = controller.snapshot();
    controller.dispatch({
        type: 'timer_fired',
        timerKey: 'endpoint',
        turnId: 'exact-overlap-interruption',
        source: 'timer:endpoint:overlap-interruption',
        atMs: snapshot.deadlines.endpointAt,
    });
    assert.deepEqual(
        controller.drainEffects().filter((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY),
        [{
            type: BROWSER_VOICE_EFFECTS.TURN_READY,
            turnId: 'exact-overlap-interruption',
            transcript: 'Actually, add one task.',
            conversationContext: { mode: 'contextual_follow_up', epoch: 1 },
        }],
    );
});

test('[BV2-BARGE-04][BV2-PRIVACY-PCM-03] a natural-closing playback preserves only its exact bounded potential interruption', () => {
    const { controller } = readyController();
    const registry = new BrowserVoiceProviderItemRegistryV2();
    controller.wakeConfirmed({ turnId: 'natural-closing-owner' });
    controller.activationReady();
    controller.transcriptFinal('Thanks, that is all.');
    controller.speechEnded({ observedSilenceMs: 2_000 });
    let snapshot = controller.snapshot();
    controller.dispatch({
        type: 'timer_fired',
        timerKey: 'endpoint',
        turnId: 'natural-closing-owner',
        generation: snapshot.generation,
        connectionGeneration: snapshot.connectionGeneration,
        source: 'timer:endpoint',
        atMs: snapshot.deadlines.endpointAt,
    });
    controller.playbackStarted({ turnId: 'natural-closing-owner', naturalClosing: true });
    controller.drainEffects();

    const started = bindBrowserVoiceProviderSpeechStartedV2(controller, registry, {
        providerItemId: 'natural-close-barge-item',
        connectionGeneration: 8,
    });
    assert.equal(started.result, BROWSER_VOICE_REALTIME_INGRESS_RESULTS.POTENTIAL_BARGE_IN);
    controller.potentialBargeIn('potential_speech', {
        source: 'provider_vad',
        ownerTurnId: started.binding.turnId,
        providerItemId: started.binding.providerItemId,
    });
    controller.playbackFinished({ turnId: 'natural-closing-owner', naturalClosing: true });

    snapshot = controller.snapshot();
    assert.equal(snapshot.conversationState, BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP);
    assert.equal(snapshot.activeTurn, null);
    assert.deepEqual(snapshot.potentialBargeIn, {
        ownerTurnId: 'natural-closing-owner',
        providerItemId: 'natural-close-barge-item',
        contextualFollowUp: true,
        returnToWakeOnly: true,
    });
    assert.equal(snapshot.deadlines.followUpAt, 15_000);
    assert.equal(browserVoiceProviderItemBindingIsCurrentV2(controller, started.binding), true);

    const mismatch = controller.confirmBargeIn({
        turnId: 'must-not-start',
        source: 'provider_transcript',
        providerItemId: 'different-provider-item',
    });
    assert.equal(mismatch.state.conversationState, BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP);
    assert.equal(mismatch.state.activeTurn, null);
    assert.equal(mismatch.state.lastRejectedEvent.reason, 'potential_barge_mismatch');

    const confirmed = controller.confirmBargeIn({
        turnId: 'natural-close-interruption',
        source: 'provider_transcript',
        providerItemId: 'natural-close-barge-item',
    });
    assert.equal(confirmed.state.conversationState, BROWSER_VOICE_CONVERSATION_STATES.ACTIVATING);
    assert.equal(confirmed.state.activeTurn.id, 'natural-close-interruption');
    assert.deepEqual(confirmed.state.activeTurn.conversationContext, {
        mode: 'contextual_follow_up',
        epoch: 1,
    });
    controller.activationReady({ source: 'provider_transcript' });
    routeBrowserVoiceRealtimeIngressV2(controller, {
        type: 'transcript_final',
        text: 'Actually, add one reminder.',
        providerItemId: 'natural-close-barge-item',
    });
    snapshot = controller.snapshot();
    controller.dispatch({
        type: 'timer_fired',
        timerKey: 'endpoint',
        turnId: 'natural-close-interruption',
        generation: snapshot.generation,
        connectionGeneration: snapshot.connectionGeneration,
        source: 'timer:endpoint:natural-close',
        atMs: snapshot.deadlines.endpointAt,
    });
    assert.deepEqual(
        controller.drainEffects().filter((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY),
        [{
            type: BROWSER_VOICE_EFFECTS.TURN_READY,
            turnId: 'natural-close-interruption',
            transcript: 'Actually, add one reminder.',
            conversationContext: { mode: 'contextual_follow_up', epoch: 1 },
        }],
    );
});

test('[BV2-BARGE-04][BV2-PRIVACY-PCM-03] an unresolved natural-close interruption expires and cannot admit late provider text', () => {
    const { controller } = readyController();
    const registry = new BrowserVoiceProviderItemRegistryV2();
    controller.wakeConfirmed({ turnId: 'expiring-owner' });
    controller.activationReady();
    controller.transcriptFinal('Goodbye.');
    controller.speechEnded({ observedSilenceMs: 2_000 });
    let snapshot = controller.snapshot();
    controller.dispatch({
        type: 'timer_fired',
        timerKey: 'endpoint',
        turnId: 'expiring-owner',
        source: 'timer:endpoint',
        atMs: snapshot.deadlines.endpointAt,
    });
    controller.playbackStarted({ turnId: 'expiring-owner', naturalClosing: true });
    controller.drainEffects();
    const started = bindBrowserVoiceProviderSpeechStartedV2(controller, registry, {
        providerItemId: 'expiring-barge-item',
        connectionGeneration: 12,
    });
    controller.potentialBargeIn('potential_speech', {
        source: 'provider_vad',
        ownerTurnId: started.binding.turnId,
        providerItemId: started.binding.providerItemId,
    });
    controller.playbackFinished({ turnId: 'expiring-owner', naturalClosing: true });
    snapshot = controller.snapshot();

    controller.dispatch({
        type: 'timer_fired',
        timerKey: 'follow_up',
        source: 'timer:follow_up:early',
        atMs: snapshot.deadlines.followUpAt - 1,
    });
    assert.ok(controller.snapshot().potentialBargeIn);
    controller.dispatch({
        type: 'timer_fired',
        timerKey: 'follow_up',
        source: 'timer:follow_up:expiry',
        atMs: snapshot.deadlines.followUpAt,
    });
    assert.equal(controller.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY);
    assert.equal(controller.snapshot().potentialBargeIn, null);
    assert.equal(controller.snapshot().deadlines.followUpAt, null);
    assert.deepEqual(
        controller.drainEffects().filter((item) => item.type === BROWSER_VOICE_EFFECTS.DISCARD_POTENTIAL_BARGE_IN),
        [{
            type: BROWSER_VOICE_EFFECTS.DISCARD_POTENTIAL_BARGE_IN,
            ownerTurnId: 'expiring-owner',
            providerItemId: 'expiring-barge-item',
            reason: 'proof_expired',
        }],
    );
    assert.equal(currentBrowserVoiceProviderItemBindingV2(registry, controller, {
        providerItemId: 'expiring-barge-item',
        connectionGeneration: 12,
        consume: true,
    }), null);
    assert.equal(controller.drainEffects().some((item) => item.type === BROWSER_VOICE_EFFECTS.TURN_READY), false);

    const dormantRegistry = new BrowserVoiceProviderItemRegistryV2();
    const dormant = bindBrowserVoiceProviderSpeechStartedV2(controller, dormantRegistry, {
        providerItemId: 'ordinary-dormant-speech',
        connectionGeneration: 12,
    });
    assert.equal(dormant.result, BROWSER_VOICE_REALTIME_INGRESS_RESULTS.IGNORED);
    assert.equal(dormant.binding, null, 'ordinary wake-only speech never inherits expired barge authority');
});
