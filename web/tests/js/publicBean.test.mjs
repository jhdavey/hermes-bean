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
    assert.match(source, /let enabled = false/);
    assert.doesNotMatch(source, /localStorage\.getItem|localStorage\.setItem/);
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

    const sessionAssignment = source.indexOf('conversation = nextConversation');
    const wakeSubmission = source.indexOf('conversation.sendUserMessage?.(wakeTail || WAKE_PHRASE)');
    assert.ok(sessionAssignment >= 0);
    assert.ok(wakeSubmission > sessionAssignment);
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
    assert.match(agentConfig, /llm:\s*landingLlm/);
    assert.match(agentConfig, /gpt-4\.1-nano/);
    assert.doesNotMatch(agentConfig, /reasoningEffort|thinkingBudget/);
    assert.match(agentConfig, /maxDurationSeconds/);
    assert.match(agentConfig, /dailyLimit:\s*dailyConversationLimit/);
    assert.match(agentConfig, /enableAuth:\s*true/);
    assert.match(agentConfig, /promptInjection:\s*\{ isEnabled: true \}/);
    assert.match(agentConfig, /recordVoice:\s*false/);
    assert.doesNotMatch(agentConfig, /dashboard_context|bean_dashboard/);
});

test('landing Bean supports optional bot verification without exposing a secret', () => {
    assert.match(navigation, /data-turnstile-site-key/);
    assert.match(source, /getTurnstileToken/);
    assert.match(source, /challenges\.cloudflare\.com\/turnstile/);
    assert.doesNotMatch(navigation, /TURNSTILE_SECRET|secret_key/);
});

test('landing Bean reveals allowlisted feature and pricing destinations', () => {
    assert.match(source, /showLandingUiAction\(response\?\.ui_action\)/);
    assert.match(source, /features:\s*\{ selector: '#features', href: '\/#features'/);
    assert.match(source, /pricing:\s*\{ selector: '#plans', href: '\/pricing#plans'/);
    assert.match(source, /scrollIntoView\(\{ behavior: reduceMotion \? 'auto' : 'smooth'/);
    assert.match(source, /previousMode === 'speaking' && pendingNavigation/);
    assert.match(source, /window\.location\.assign\(navigation\.href\)/);
    assert.match(styles, /\.public-bean-guided-highlight/);
    assert.match(styles, /@keyframes public-bean-guided-highlight/);
});

test('landing Bean uses the stationary app-style border tracing indicator', () => {
    assert.match(styles, /@property --public-bean-ring-angle/);
    assert.match(styles, /conic-gradient\(from var\(--public-bean-ring-angle\)/);
    assert.match(styles, /to \{ --public-bean-ring-angle: 360deg; \}/);
    assert.doesNotMatch(styles, /public-bean-orbit[\s\S]*?transform:\s*rotate/);
});
