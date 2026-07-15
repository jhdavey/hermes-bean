import { expect, test } from 'playwright/test';

async function boot(page) {
    await page.goto('/tests/browser/fixtures/voice-v2-harness.html');
    await page.evaluate(() => window.voiceHarnessReady);
}

test('[BV-BROWSER-01] durable voice projection cannot disclose transcript or spoken final text', async ({ page }) => {
    await boot(page);
    const projection = await page.evaluate(() => window.beanVoiceHarness.project({
        cursor: 1,
        transcript: 'private user speech',
        messages: [{ role: 'assistant', content: 'private spoken final' }],
        turns: [{ turn_id: 'turn-1', state: 'running', version: 1 }],
        jobs: [{ id: 1, turn_id: 'turn-1', status: 'running', version: 1 }],
    }));
    expect(projection.turns).toHaveLength(1);
    expect(projection.jobs[0].label).toBe('Bean work');
    await expect(page.locator('#chat')).toHaveText('');
    await expect(page.locator('#result')).not.toContainText('private user speech');
    await expect(page.locator('#result')).not.toContainText('private spoken final');
});

test('[BV-BROWSER-02] playback binding fails closed on a stale controller generation', async ({ page }) => {
    await boot(page);
    const failure = await page.evaluate(() => window.beanVoiceHarness.authorize({
        binding: {
            responseId: 'response-1',
            authorizationId: 'authorization-1',
            turnId: 'turn-1',
            speechItemId: 'speech-1',
            purpose: 'final',
            realtimeSessionId: 'session-1',
            controllerGeneration: 3,
            providerConnectionGeneration: 8,
            approvedTextSha256: 'a'.repeat(64),
            playbackCapability: 'capability-1',
        },
        authorization: {
            authorizationId: 'authorization-1',
            turnId: 'turn-1',
            speechItemId: 'speech-1',
            purpose: 'final',
            realtimeSessionId: 'session-1',
            controllerGeneration: 4,
            providerConnectionGeneration: 8,
            approvedTextSha256: 'a'.repeat(64),
            playbackCapability: 'capability-1',
            expiresAt: '2099-01-01T00:00:00.000Z',
        },
        realtimeSessionId: 'session-1',
        playbackCapability: 'capability-1',
        controllerGeneration: 4,
        providerConnectionGeneration: 8,
    }));
    expect(failure).toBe('controller_generation_mismatch');
});
