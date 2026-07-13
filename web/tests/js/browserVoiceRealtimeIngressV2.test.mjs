import assert from 'node:assert/strict';
import test from 'node:test';
import {
    BROWSER_VOICE_EFFECTS,
    BrowserVoiceControllerV2,
} from '../../resources/js/heybean/browserVoiceControllerV2.js';
import { routeBrowserVoiceRealtimeIngressV2 } from '../../resources/js/heybean/browserVoiceRealtimeIngressV2.js';

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
    controller.completenessDecided('complete');
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
        text: 'A reminder for that task',
        providerItemId: 'provider-reminder',
    });
    routeBrowserVoiceRealtimeIngressV2(controller, {
        type: 'transcript_final',
        text: 'A reminder for that task at five.',
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
    assert.equal(
        controller.drainEffects().filter((effect) => effect.type === BROWSER_VOICE_EFFECTS.ASSESS_COMPLETENESS).length,
        1,
    );
    controller.completenessDecided('complete');

    const admissions = controller.drainEffects()
        .filter((effect) => effect.type === BROWSER_VOICE_EFFECTS.TURN_READY);
    assert.equal(admissions.length, 1);
    assert.equal(admissions[0].transcript, 'A reminder for that task at five.');
    assert.deepEqual(admissions[0].conversationContext, { mode: 'contextual_follow_up', epoch: 1 });
});
