import assert from 'node:assert/strict';
import test from 'node:test';
import {
    browserVoiceV2BundleCheck,
    browserVoiceV2ShellCheck,
} from '../browser/voice-v2-production-preflight-core.mjs';

test('[BV2-DEPLOY-01] production preflight rejects a present but disabled Browser Voice v2 marker', () => {
    assert.deepEqual(browserVoiceV2ShellCheck('<main data-browser-voice-v2="false"></main>'), {
        actual: 'false',
        expected: 'data-browser-voice-v2="true"',
        pass: false,
    });
});

test('[BV2-DEPLOY-02] production preflight accepts only an explicitly enabled Browser Voice v2 marker', () => {
    assert.equal(browserVoiceV2ShellCheck('<main data-browser-voice-v2="true"></main>').pass, true);
    assert.equal(browserVoiceV2ShellCheck('<main></main>').pass, false);
});

test('[BV2-AUDIO-NATIVE-01] production preflight requires the new Realtime boundaries and rejects legacy speech routes', () => {
    const requiredBundle = [
        '/assistant/voice/realtime/session',
        '/assistant/voice/client-failures',
        '/assistant/voice/capabilities',
        '/assistant/voice/turns',
        '/assistant/voice/state',
        '/assistant/voice/stream',
        '/assistant/voice/cancellations',
    ].join(' ');

    assert.equal(browserVoiceV2BundleCheck(requiredBundle).pass, true);
    assert.equal(browserVoiceV2BundleCheck(`${requiredBundle} /assistant/voice/speech`).pass, false);
    assert.equal(browserVoiceV2BundleCheck(requiredBundle.replace('/assistant/voice/stream', '')).pass, false);
});
