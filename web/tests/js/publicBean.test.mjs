import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../resources/js/publicBean.js', import.meta.url), 'utf8');
const styles = await readFile(new URL('../../resources/css/public-bean.css', import.meta.url), 'utf8');
const navigation = await readFile(new URL('../../resources/views/partials/public-nav.blade.php', import.meta.url), 'utf8');
const agentConfig = await readFile(new URL('../../scripts/elevenlabs-landing-agent-configure.mjs', import.meta.url), 'utf8');

test('public pages expose a compact Bean control without the authenticated chat panel', () => {
    assert.match(navigation, /data-public-bean/);
    assert.match(navigation, /Tap to talk/);
    assert.match(navigation, /data-public-bean-status/);
    assert.match(navigation, /Hey! I'm over here!/);
    assert.match(navigation, /data-public-bean-cue/);
    assert.match(navigation, /aria-label="Talk with Bean"/);
    assert.match(navigation, /Turn your volume on, then allow microphone access\./);
    assert.doesNotMatch(navigation, /data-bean-panel|hb-bean-chat/);
    assert.match(styles, /\.public-bean-presence/);
    assert.match(styles, /background:\s*rgba\(255, 255, 255/);
    assert.match(styles, /\.public-bean-status/);
    assert.match(styles, /\.public-bean-cue/);
    assert.match(styles, /font-family: "Bradley Hand", "Comic Sans MS", "Marker Felt", cursive/);
    assert.match(styles, /\.public-bean-cue-arrow/);
    assert.match(styles, /\.public-bean-help/);
    assert.match(styles, /\.public-bean-cue:focus-visible/);
});

test('landing Bean starts voice directly from an explicit tap with a hearing check', () => {
    assert.match(source, /let enabled = false/);
    assert.doesNotMatch(source, /localStorage\.getItem|localStorage\.setItem/);
    assert.match(source, /navigator\.mediaDevices\.getUserMedia\(\{ audio: true \}\)/);
    assert.match(source, /Turn volume on\. Allow mic\./);
    assert.match(source, /await startVoiceConversation\(revision\)/);
    assert.match(source, /cue\?\.addEventListener\('click'/);
    assert.match(source, /Conversation\.startSession/);
    assert.match(source, /conversationToken:\s*session\.token/);
    assert.match(source, /Hey, I'm Bean, can you hear me\?/);
    assert.match(agentConfig, /How can I help\?/);
    assert.match(source, /firstMessage:\s*WAKE_GREETING/);
    assert.doesNotMatch(source, /SpeechRecognition|Just say “Hey Bean|createWakeDetector|extractWakeTail|prefetchVoiceSession|restartWakeListening/);
    assert.match(source, /Demo cooldown — try again shortly/);
    assert.doesNotMatch(source, /Demo limit reached/);
    assert.match(source, /const WAKE_TO_GREETING_TARGET_MS = 1200/);
    assert.match(source, /const IDLE_CLOSE_MS = 15000/);
    assert.match(source, /voice_start_requested/);
    assert.match(source, /tap_to_start/);
    assert.match(source, /showLandingSection/);
    assert.match(source, /askLandingBean/);
    assert.match(source, /root\.dataset\.conversationTokenUrl/);
    assert.match(source, /root\.dataset\.messageUrl/);
    assert.match(source, /root\.dataset\.voiceEventUrl/);
    assert.match(navigation, /data-voice-event-url/);

    const permissionRequest = source.indexOf('navigator.mediaDevices.getUserMedia({ audio: true })');
    const voiceStart = source.indexOf('await startVoiceConversation(revision)');
    assert.ok(permissionRequest >= 0);
    assert.ok(voiceStart > permissionRequest);
});

test('landing Bean can be disabled while voice startup is still pending', () => {
    assert.match(source, /let lifecycleRevision = 0/);
    assert.match(source, /const isCurrentLifecycle = \(revision\) => enabled && lifecycleRevision === revision/);
    assert.match(source, /if \(!isCurrentLifecycle\(revision\)\) \{\s*await nextConversation\?\.endSession\?\.\(\)\.catch/);

    const disableBody = source.match(/const disable = async \(\) => \{([\s\S]*?)\n    \};/)?.[1] || '';
    assert.ok(disableBody.indexOf("setStatus('disabled', 'Tap to talk')") >= 0);
    assert.ok(disableBody.indexOf("setStatus('disabled', 'Tap to talk')") < disableBody.indexOf("await stopVoiceConversation('disabled')"));
});

test('landing voice uses a dedicated fast ElevenLabs guide with an action-only public section tool', () => {
    assert.match(agentConfig, /ELEVENLABS_LANDING_AGENT_ID/);
    assert.match(agentConfig, /showLandingSection/);
    assert.match(source, /showLandingSection:\s*async/);
    assert.match(agentConfig, /answer directly with the facts below using the configured fast model/);
    assert.match(agentConfig, /Do not call a response\/reasoning tool for normal questions/);
    assert.match(agentConfig, /bean:landing-guide-facts/);
    assert.match(agentConfig, /firstMessage:\s*WAKE_GREETING/);
    assert.match(agentConfig, /Hey, I'm Bean, can you hear me\?/);
    assert.match(agentConfig, /If the visitor responds yes, yeah, yep, I can/);
    assert.match(agentConfig, /Great — I'm Bean, the voice assistant inside HeyBean/);
    assert.match(agentConfig, /expectsResponse:\s*false/);
    assert.match(agentConfig, /firstMessage:\s*true/);
    assert.match(agentConfig, /llm:\s*landingLlm/);
    assert.match(agentConfig, /gpt-4\.1-nano/);
    assert.match(agentConfig, /const maxTokens = Number\(env\.ELEVENLABS_LANDING_MAX_TOKENS \|\| 260\)/);
    assert.match(agentConfig, /maxTokens,/);
    assert.match(agentConfig, /difference between two named plans/);
    assert.doesNotMatch(agentConfig, /reasoningEffort|thinkingBudget/);
    assert.match(agentConfig, /maxDurationSeconds/);
    assert.match(agentConfig, /env\.ELEVENLABS_MAX_DURATION_SECONDS \|\| 60/);
    assert.match(agentConfig, /silenceEndCallTimeout:\s*silenceEndCallSeconds/);
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
    assert.match(source, /showLandingUiAction\(parameters\.destination \|\| parameters\.section \|\| parameters\.action\)/);
    assert.match(source, /showLandingUiAction\(response\?\.ui_action \|\| parameters\.destination\)/);
    assert.match(agentConfig, /required: \['destination'\]/);
    assert.match(agentConfig, /enum: \['features', 'pricing'\]/);
    assert.match(source, /features:\s*\{ selector: '#features', href: '\/#features'/);
    assert.match(source, /pricing:\s*\{ selector: '#plans', scrollSelector: '#plans \.plans', href: '\/#plans', label: 'pricing', offset: 24 \}/);
    assert.match(source, /document\.querySelector\(target\.scrollSelector\) \|\| section/);
    assert.match(source, /window\.scrollTo\(\{ top: Math\.max\(0, top\), behavior: reduceMotion \? 'auto' : 'smooth' \}\)/);
    assert.doesNotMatch(source, /section\.scrollIntoView/);
    assert.doesNotMatch(source, /pendingNavigation|window\.location\.assign/);
    assert.match(styles, /\.public-bean-guided-highlight/);
    assert.match(styles, /@keyframes public-bean-guided-highlight/);
});

test('landing Bean uses the stationary app-style border tracing indicator', () => {
    assert.match(styles, /@property --public-bean-ring-angle/);
    assert.match(styles, /conic-gradient\(from var\(--public-bean-ring-angle\)/);
    assert.match(styles, /to \{ --public-bean-ring-angle: 360deg; \}/);
    assert.doesNotMatch(styles, /public-bean-orbit[\s\S]*?transform:\s*rotate/);
});
