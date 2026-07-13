import { expect, test } from 'playwright/test';

const HARNESS_URL = '/tests/browser/fixtures/voice-v2-harness.html?reset=1';
const READY_PARTS = [
    'track',
    'worklet',
    'model',
    'recognizer',
    'derived_track',
    'warm_decode',
    'provider',
];

async function boot(page) {
    await page.goto(HARNESS_URL);
    await page.evaluate(() => window.voiceHarnessReady);
}

async function markReady(page) {
    await page.evaluate((parts) => {
        for (const part of parts) window.voiceHarness.markReady(part);
    }, READY_PARTS);
    await expect(page.locator('#voice-state')).toHaveAttribute('data-state', 'wake_only');
}

test('[BV2-BROWSER-01] fresh-load readiness, wake privacy, live transcript, and exact admission form one journey', async ({ page }) => {
    await boot(page);

    const callsBeforeAmbient = await page.evaluate(() => window.voiceHarness.server.calls.length);
    await page.evaluate(() => {
        window.voiceHarness.ambientSpeech('This room conversation must stay private.');
        window.voiceHarness.wake('too-early');
    });
    await expect(page.locator('#voice-state')).toHaveAttribute('data-state', 'starting');
    await expect(page.locator('#voice-input')).toHaveText('');
    expect(await page.evaluate(() => window.voiceHarness.server.calls.length)).toBe(callsBeforeAmbient);

    await page.evaluate((parts) => {
        for (const part of parts.slice(0, -1)) window.voiceHarness.markReady(part);
    }, READY_PARTS);
    await expect(page.locator('#voice-state')).toHaveAttribute('data-state', 'starting');
    await page.evaluate(() => window.voiceHarness.markReady('provider'));
    await expect(page.locator('#voice-state')).toHaveAttribute('data-state', 'wake_only');

    await page.evaluate(() => {
        window.voiceHarness.wake('first-fresh-turn');
        window.voiceHarness.partial("What's on my calendar");
    });
    await expect(page.locator('#voice-state')).toHaveAttribute('data-state', 'capturing');
    await expect(page.locator('#voice-input')).toHaveText("What's on my calendar");

    await page.evaluate(async () => {
        const harness = window.voiceHarness;
        const scope = harness.controller.snapshot();
        harness.controller.dispatch({
            type: 'wake_confirmed',
            turnId: 'duplicate-wake',
            source: 'wake-gate',
            sequence: 2,
            generation: scope.generation,
            connectionGeneration: scope.connectionGeneration,
        });
        harness.final("What's on my calendar tomorrow?");
        harness.endUtterance('complete');
        await harness.waitForAdmissions();
    });

    await expect(page.locator('#voice-input')).toHaveText('');
    await expect(page.locator('#chat [data-role="user"]')).toHaveCount(1);
    await expect(page.locator('#chat [data-role="user"]')).toHaveText("What's on my calendar tomorrow?");
    const state = await page.evaluate(() => window.voiceHarness.snapshot());
    expect(state.server.turns).toHaveLength(1);
    expect(state.server.messages.filter((message) => message.role === 'user')).toHaveLength(1);
    expect(state.controller.lastRejectedEvent.reason).toBe('stale_sequence');
});

test('[BV2-BROWSER-07] a released wake fragment stays private until the complete instant command arrives', async ({ page }) => {
    await boot(page);
    await markReady(page);

    const callsBeforeWake = await page.evaluate(() => window.voiceHarness.server.calls.length);
    await page.evaluate(() => {
        const harness = window.voiceHarness;
        harness.wake('wake-fragment-time-turn');
        harness.providerTranscript('Bean.');
    });

    await expect(page.locator('#voice-state')).toHaveAttribute('data-state', 'capturing');
    await expect(page.locator('#voice-input')).toHaveText('');
    expect(await page.evaluate(() => window.voiceHarness.server.calls.length)).toBe(callsBeforeWake);

    await page.evaluate(async () => {
        const harness = window.voiceHarness;
        harness.providerTranscript('What time is it?');
        harness.endUtterance('complete');
        await harness.waitForAdmissions();
    });

    const state = await page.evaluate(() => window.voiceHarness.snapshot());
    expect(state.server.turns).toHaveLength(1);
    expect(state.server.turns[0].transcript).toBe('What time is it?');
    expect(state.server.messages.filter((message) => message.role === 'user')).toHaveLength(1);
    await expect(page.locator('#chat [data-role="user"]')).toHaveText('What time is it?');
});

test('[BV2-BROWSER-02] follow-up, false barge, and meaningful barge preserve exact turns and visible answers', async ({ page }) => {
    await boot(page);
    await markReady(page);

    await page.evaluate(async () => {
        const harness = window.voiceHarness;
        harness.wake('calendar-turn');
        harness.partial("What's on my calendar");
        harness.final("What's on my calendar tomorrow?");
        harness.endUtterance();
        await harness.waitForAdmissions();
        await harness.updateTurn('calendar-turn', {
            state: 'completed',
            final_text: 'Your first event is at nine tomorrow.',
        });
        harness.startPlayback();
        harness.potentialBarge();
        harness.rejectBarge();
    });

    let state = await page.evaluate(() => window.voiceHarness.snapshot());
    expect(state.playback.plays).toHaveLength(1);
    expect(state.playback.volumes.map((event) => event.volume)).toEqual([0.2, 1]);
    expect(state.playback.stops).toHaveLength(0);
    await expect(page.locator('#chat [data-role="assistant"][data-turn-id="calendar-turn"]'))
        .toHaveText('Your first event is at nine tomorrow.');

    await page.evaluate(async () => {
        const harness = window.voiceHarness;
        harness.finishPlayback();
        harness.followUp('follow-up-turn');
        harness.partial('What time');
        harness.final('What time does it end?');
        harness.endUtterance();
        await harness.waitForAdmissions();
        await harness.updateTurn('follow-up-turn', {
            state: 'completed',
            final_text: 'It ends at ten o’clock.',
        });
        harness.startPlayback();
        harness.potentialBarge();
        harness.confirmBarge('barge-turn');
        harness.partial('Also create');
        harness.final('Also create a reminder for four p.m.');
        harness.endUtterance();
        await harness.waitForAdmissions();
    });

    await expect(page.locator('#chat [data-role="user"]')).toHaveCount(3);
    await expect(page.locator('#chat [data-role="assistant"][data-turn-id="calendar-turn"]'))
        .toHaveText('Your first event is at nine tomorrow.');
    state = await page.evaluate(() => window.voiceHarness.snapshot());
    expect(state.playback.stops.at(-1)).toMatchObject({
        turnId: 'follow-up-turn',
        reason: 'meaningful_barge_in',
    });
    expect(state.server.turns.map((turn) => turn.turn_id)).toEqual([
        'calendar-turn',
        'follow-up-turn',
        'barge-turn',
    ]);
});

test('[BV2-BROWSER-03] Stop is playback-only while three jobs run and a fourth stays visibly queued', async ({ page }) => {
    await boot(page);
    await markReady(page);

    await page.evaluate(async () => {
        await window.voiceHarness.seedTurns([
            voiceTurn('work-one', 'Create the launch note', 'running', 'job-1', 'running', true),
            voiceTurn('work-two', 'Create a reminder', 'running', 'job-2', 'running'),
            voiceTurn('work-three', 'Draft the meal plan', 'running', 'job-3', 'running'),
            voiceTurn('work-four', 'Create another note', 'accepted', 'job-4', 'queued'),
        ]);

        function voiceTurn(turnId, transcript, state, jobId, status, acknowledgement = false) {
            return {
                turn_id: turnId,
                transcript,
                state,
                acknowledgement_required: acknowledgement,
                acknowledgement_text: acknowledgement ? 'I’ll put that together.' : '',
                jobs: [{ id: jobId, label: transcript, status, version: 1 }],
            };
        }
    });

    await expect(page.locator('#dock [data-status="running"]')).toHaveCount(3);
    await expect(page.locator('#dock [data-status="queued"]')).toHaveCount(1);
    await expect.poll(() => page.evaluate(() => window.voiceHarness.snapshot().playback.plays.length)).toBe(1);

    const beforeStop = await page.evaluate(() => {
        const harness = window.voiceHarness;
        harness.startPlayback();
        const server = harness.server.snapshot();
        harness.stopPlayback();
        return { server, after: harness.snapshot() };
    });
    expect(beforeStop.after.playback.stops.at(-1).reason).toBe('user_stop');
    expect(beforeStop.after.controller.conversationState).toBe('wake_only');
    expect(beforeStop.after.server.turns).toEqual(beforeStop.server.turns);
    expect(beforeStop.after.server.jobs).toEqual(beforeStop.server.jobs);
    expect(beforeStop.after.server.calls).toBeUndefined();

    await page.evaluate(async () => {
        const harness = window.voiceHarness;
        await harness.updateTurn('work-three', {
            state: 'completed',
            final_text: 'The meal plan is ready.',
            jobs: [{ id: 'job-3', label: 'Draft the meal plan', status: 'completed', version: 2 }],
        });
        await harness.updateTurn('work-one', {
            state: 'completed',
            final_text: 'The launch note is ready.',
            jobs: [{ id: 'job-1', label: 'Create the launch note', status: 'completed', version: 2 }],
        });
        await harness.pollOnce();
    });

    await expect(page.locator('#chat [data-role="assistant"][data-turn-id="work-three"]')).toHaveCount(1);
    await expect(page.locator('#chat [data-role="assistant"][data-turn-id="work-one"]')).toHaveCount(1);
    await expect(page.locator('#dock [data-job-id="job-1"]')).toHaveAttribute('data-status', 'completed');
    await expect(page.locator('#dock [data-job-id="job-2"]')).toHaveAttribute('data-status', 'running');
    await expect(page.locator('#dock [data-job-id="job-3"]')).toHaveAttribute('data-status', 'completed');
    await expect(page.locator('#dock [data-job-id="job-4"]')).toHaveAttribute('data-status', 'queued');

    const after = await page.evaluate(() => window.voiceHarness.snapshot());
    expect(after.speech.state).toBe('stopped');
    expect(after.speech.pending).toHaveLength(2);
    expect(after.server.turns.find((turn) => turn.turn_id === 'work-two').state).toBe('running');
    expect(await page.evaluate(() => window.voiceHarness.server.calls.some((call) => call.path.includes('cancellations'))))
        .toBe(false);
});

test('[BV2-BROWSER-04] stale events and snapshots cannot regress state, and reload does not duplicate or replay a final', async ({ page }) => {
    await boot(page);
    await markReady(page);

    await page.evaluate(() => {
        const harness = window.voiceHarness;
        harness.wake('protected-capture');
        harness.partial('Keep the current words', { source: 'provider', sequence: 20 });
        const scope = harness.controller.snapshot();
        harness.controller.dispatch({
            type: 'transcript_partial',
            text: 'Discard this stale text',
            turnId: 'protected-capture',
            source: 'provider',
            sequence: 19,
            generation: scope.generation,
            connectionGeneration: scope.connectionGeneration,
        });
    });
    await expect(page.locator('#voice-input')).toHaveText('Keep the current words');

    await page.evaluate(async () => {
        const harness = window.voiceHarness;
        await harness.seedTurns([{
            turn_id: 'reload-turn',
            transcript: 'Create a durable note',
            state: 'completed',
            final_text: 'I created the durable note.',
            jobs: [{ id: 'reload-job', label: 'Durable note', status: 'completed', version: 3 }],
        }]);
        await harness.server.request('/assistant/voice/turns/reload-turn/delivery', {
            method: 'POST',
            body: {
                session_id: 41,
                event: 'final_audio_started',
                timing: { purpose: 'final', speech_item_id: 'reload-turn:final' },
            },
        });
        await harness.pollOnce();
        const cursor = harness.snapshot().cursor;
        harness.applyProjection({
            cursor: cursor - 1,
            turns: [{
                turn_id: 'reload-turn',
                transcript: 'Create a durable note',
                state: 'running',
                version: 1,
                jobs: [{ id: 'reload-job', label: 'Durable note', status: 'queued', version: 1 }],
            }],
        });
    });

    await expect(page.locator('#chat [data-role="user"][data-turn-id="reload-turn"]')).toHaveCount(1);
    await expect(page.locator('#chat [data-role="assistant"][data-turn-id="reload-turn"]')).toHaveCount(1);
    await expect(page.locator('#dock [data-job-id="reload-job"]')).toHaveAttribute('data-status', 'completed');
    const cursorBeforeReload = await page.evaluate(() => window.voiceHarness.snapshot().cursor);

    await page.reload();
    await page.evaluate(() => window.voiceHarnessReady);

    await expect(page.locator('#chat [data-role="user"][data-turn-id="reload-turn"]')).toHaveCount(1);
    await expect(page.locator('#chat [data-role="assistant"][data-turn-id="reload-turn"]')).toHaveCount(1);
    await expect(page.locator('#dock [data-job-id="reload-job"]')).toHaveAttribute('data-status', 'completed');
    const afterReload = await page.evaluate(() => window.voiceHarness.snapshot());
    expect(afterReload.cursor).toBe(cursorBeforeReload);
    expect(afterReload.playback.plays).toHaveLength(0);
    expect(afterReload.server.messages.filter((message) => message.id === 'final:reload-turn')).toHaveLength(1);
});

test('[BV2-BROWSER-06] capture owns buffered TTS, strict provider wake preserves queued finals, and reload speaks only unheard finals', async ({ page }) => {
    await boot(page);
    await markReady(page);

    const beforeReload = await page.evaluate(async () => {
        const harness = window.voiceHarness;
        await harness.seedTurns([{
            turn_id: 'buffered-current',
            transcript: 'First background result',
            state: 'completed',
            final_text: 'First result.',
        }, {
            turn_id: 'queued-unrelated',
            transcript: 'Second background result',
            state: 'completed',
            final_text: 'Second result.',
        }]);
        const buffered = harness.playback.handles[0];
        harness.controller.playbackFinished({ turnId: 'prior-conversation' });
        harness.followUp('capture-over-buffer');
        harness.partial('What time');
        const afterCapture = harness.snapshot();
        const staleStartAccepted = harness.playback.start(buffered);

        harness.providerTranscript('Hey Bean, what is the date?', 'strict-provider-turn');
        harness.endUtterance();
        await harness.waitForAdmissions();
        return { afterCapture, staleStartAccepted, final: harness.snapshot() };
    });

    expect(beforeReload.afterCapture.speech.captureBlocked).toBe(true);
    expect(beforeReload.afterCapture.playback.stops.at(-1).reason).toBe('voice_follow_up');
    expect(beforeReload.staleStartAccepted).toBe(false);
    expect(beforeReload.final.server.turns.some((turn) => turn.turn_id === 'strict-provider-turn')).toBe(true);
    expect([
        beforeReload.final.speech.current?.turnId,
        ...beforeReload.final.speech.pending.map((item) => item.turnId),
    ]).toContain('queued-unrelated');
    expect([
        beforeReload.final.speech.current?.turnId,
        ...beforeReload.final.speech.pending.map((item) => item.turnId),
    ]).not.toContain('buffered-current');

    await page.evaluate(async () => {
        const harness = window.voiceHarness;
        harness.server.reset();
        await harness.seedTurns([{
            turn_id: 'unheard-reload-turn',
            transcript: 'Finish an unheard result',
            state: 'completed',
            final_text: 'This final has not started audio yet.',
        }]);
    });
    await page.reload();
    await page.evaluate(() => window.voiceHarnessReady);

    await expect.poll(() => page.evaluate(() => (
        window.voiceHarness.snapshot().playback.plays
            .filter((item) => item.turnId === 'unheard-reload-turn').length
    ))).toBe(1);

    await page.evaluate(() => {
        const harness = window.voiceHarness;
        const handle = harness.playback.handles.find((item) => item.item.turnId === 'unheard-reload-turn');
        harness.playback.start(handle);
    });
    await page.reload();
    await page.evaluate(() => window.voiceHarnessReady);
    expect(await page.evaluate(() => (
        window.voiceHarness.snapshot().playback.plays
            .filter((item) => item.turnId === 'unheard-reload-turn').length
    ))).toBe(0);
});

test('[BV2-BROWSER-05] transport recovery and provider failure clear indefinite UI without canceling background work', async ({ page }) => {
    await boot(page);
    await markReady(page);

    await page.evaluate(async () => {
        const harness = window.voiceHarness;
        await harness.seedTurns([
            {
                turn_id: 'faulted-turn',
                transcript: 'Check the remote weather',
                state: 'running',
                jobs: [{ id: 'faulted-job', label: 'Check remote weather', status: 'running', version: 1 }],
            },
            {
                turn_id: 'survivor-turn',
                transcript: 'Draft a note in the background',
                state: 'running',
                jobs: [{ id: 'survivor-job', label: 'Draft note', status: 'running', version: 1 }],
            },
        ]);
        harness.failNextStateRequests(1);
        harness.startPolling();
    });

    await expect.poll(() => page.evaluate(() => window.voiceHarness.snapshot().networkErrors.length)).toBe(1);
    await page.evaluate(() => {
        window.voiceHarness.server.updateTurn('faulted-turn', {
            state: 'failed',
            final_text: 'I couldn’t reach the weather service. Would you like me to try again?',
            jobs: [{ id: 'faulted-job', label: 'Check remote weather', status: 'failed', version: 2 }],
        });
    });

    await expect(page.locator('#chat [data-role="assistant"][data-turn-id="faulted-turn"]'))
        .toHaveText('I couldn’t reach the weather service. Would you like me to try again?');
    await expect(page.locator('#dock [data-job-id="faulted-job"]')).toHaveAttribute('data-status', 'failed');
    await expect(page.locator('#dock [data-job-id="survivor-job"]')).toHaveAttribute('data-status', 'running');

    const providerState = await page.evaluate(() => {
        const harness = window.voiceHarness;
        harness.stopPolling();
        harness.speech.reset('provider-fault-setup');
        harness.speech.enqueueSpeech({ turnId: 'spoken-turn', text: 'This audio is playing.', purpose: 'final' });
        harness.startPlayback();
        harness.providerFailure('provider_unavailable');
        return harness.snapshot();
    });
    expect(providerState.controller.conversationState).toBe('failed');
    expect(providerState.playback.stops.at(-1).reason).toBe('provider_unavailable');
    expect(providerState.server.turns.find((turn) => turn.turn_id === 'survivor-turn').state).toBe('running');
    expect(await page.evaluate(() => window.voiceHarness.server.calls.some((call) => call.path.includes('cancellations'))))
        .toBe(false);
});
