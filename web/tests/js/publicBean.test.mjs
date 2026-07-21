import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../resources/js/publicBean.js', import.meta.url), 'utf8');
const styles = await readFile(new URL('../../resources/css/public-bean.css', import.meta.url), 'utf8');
const navigation = await readFile(new URL('../../resources/views/partials/public-nav.blade.php', import.meta.url), 'utf8');
const agentConfig = await readFile(new URL('../../scripts/elevenlabs-landing-agent-configure.mjs', import.meta.url), 'utf8');

test('public pages expose a compact Bean control without the authenticated chat panel', () => {
    assert.match(navigation, /data-public-bean/);
    assert.match(navigation, /Tap to enable/);
    assert.match(navigation, /data-public-bean-status/);
    assert.doesNotMatch(navigation, /data-bean-panel|hb-bean-chat/);
    assert.match(styles, /\.public-bean-presence/);
    assert.match(styles, /background:\s*rgba\(255, 255, 255/);
    assert.match(styles, /\.public-bean-status/);
});

test('landing Bean enables the microphone, listens for the wake phrase, and starts ElevenLabs voice', () => {
    assert.match(source, /navigator\.mediaDevices\.getUserMedia\(\{ audio: true \}\)/);
    assert.match(source, /Just say “Hey Bean…”/);
    assert.match(source, /window\.SpeechRecognition/);
    assert.match(source, /processLocally:\s*true/);
    assert.match(source, /recognition\.processLocally = true/);
    assert.match(source, /Conversation\.startSession/);
    assert.match(source, /conversationToken:\s*session\.token/);
    assert.match(source, /sendUserMessage\?\.\(wakeTail \|\| WAKE_PHRASE\)/);
    assert.match(source, /askLandingBean/);
    assert.match(source, /root\.dataset\.conversationTokenUrl/);
    assert.match(source, /root\.dataset\.messageUrl/);
});

test('landing Bean can be disabled while wake or voice startup is still pending', () => {
    assert.match(source, /let lifecycleRevision = 0/);
    assert.match(source, /const isCurrentLifecycle = \(revision\) => enabled && lifecycleRevision === revision/);
    assert.match(source, /if \(!isCurrentLifecycle\(revision\)\) \{\s*detector\.stop\?\.\(\);\s*return;/);
    assert.match(source, /if \(!isCurrentLifecycle\(revision\)\) \{\s*await nextConversation\?\.endSession\?\.\(\)\.catch/);

    const disableBody = source.match(/const disable = async \(\) => \{([\s\S]*?)\n    \};/)?.[1] || '';
    assert.ok(disableBody.indexOf("setStatus('disabled', 'Tap to enable')") >= 0);
    assert.ok(disableBody.indexOf("setStatus('disabled', 'Tap to enable')") < disableBody.indexOf('await stopVoiceConversation()'));
});

test('landing voice uses a dedicated ElevenLabs agent configuration and public Hermes tool', () => {
    assert.match(agentConfig, /ELEVENLABS_LANDING_AGENT_ID/);
    assert.match(agentConfig, /askLandingBean/);
    assert.match(agentConfig, /isolated public Hermes Bean runtime/);
    assert.match(agentConfig, /firstMessage:\s*''/);
    assert.doesNotMatch(agentConfig, /dashboard_context|bean_dashboard/);
});
