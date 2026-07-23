import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../resources/js/publicBean.js', import.meta.url), 'utf8');
const styles = await readFile(new URL('../../resources/css/public-bean.css', import.meta.url), 'utf8');
const navigation = await readFile(new URL('../../resources/views/partials/public-nav.blade.php', import.meta.url), 'utf8');
const landing = await readFile(new URL('../../resources/views/welcome.blade.php', import.meta.url), 'utf8');
const agentConfig = await readFile(new URL('../../scripts/elevenlabs-landing-agent-configure.mjs', import.meta.url), 'utf8');

test('public pages expose a compact Bean control without the authenticated chat panel', () => {
    assert.match(navigation, /data-public-bean/);
    assert.match(navigation, /Tap to talk/);
    assert.match(navigation, /data-public-bean-status/);
    assert.match(navigation, /Hey! I'm over here!/);
    assert.match(navigation, /data-public-bean-cue/);
    assert.match(navigation, /aria-label="Talk with Bean"/);
    assert.match(navigation, /Turn your volume on, then allow microphone access\./);
    assert.match(navigation, /public-bean-nav-spacer/);
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

test('landing Bean stays fixed in the top-left viewport while page content scrolls', () => {
    const presence = styles.match(/\.public-bean-presence \{([\s\S]*?)\n\}/)?.[1] || '';
    const spacer = styles.match(/\.public-bean-nav-spacer \{([\s\S]*?)\n\}/)?.[1] || '';
    assert.match(presence, /position:\s*fixed/);
    assert.match(presence, /top:\s*calc\(env\(safe-area-inset-top, 0px\) \+ 54px\)/);
    assert.match(presence, /left:\s*max\(24px, calc\(\(100vw - var\(--pb-max, 1152px\)\) \/ 2 \+ 24px\)\)/);
    assert.match(presence, /z-index:\s*70/);
    assert.match(spacer, /flex:\s*0 0 124px/);
    assert.match(spacer, /height:\s*42px/);
    assert.match(source, /const updateScrolledCueState = \(\) =>/);
    assert.match(source, /root\.dataset\.scrolled = window\.scrollY > 80 \? 'true' : 'false'/);
    assert.match(source, /window\.addEventListener\('scroll', updateScrolledCueState, \{ passive: true \}\)/);
    assert.match(styles, /\.public-bean-presence\[data-scrolled="true"\] \.public-bean-cue \{[\s\S]*?pointer-events:\s*none/);

    const mobileBlock = styles.match(/@media \(max-width: 620px\) \{([\s\S]*?)@media \(max-width: 390px\)/)?.[1] || '';
    assert.match(mobileBlock, /\.public-bean-presence \{[\s\S]*?left:\s*17px/);
});

test('landing Bean handwritten cue keeps the arrow separate and more upward than leftward', () => {
    const desktopCue = styles.match(/\.public-bean-cue \{([\s\S]*?)\n\}/)?.[1] || '';
    const desktopArrow = styles.match(/\.public-bean-cue svg \{([\s\S]*?)\n\}/)?.[1] || '';
    assert.match(desktopCue, /top:\s*calc\(100% \+ 34px\)/);
    assert.match(desktopCue, /left:\s*76px/);
    assert.match(desktopArrow, /top:\s*-60px/);
    assert.match(desktopArrow, /left:\s*-96px/);
    assert.match(desktopArrow, /width:\s*88px/);
    assert.match(navigation, /viewBox="0 0 88 88"/);
    assert.match(navigation, /M78 76 C56 66 48 51 40 36/);

    const mobileBlock = styles.match(/@media \(max-width: 620px\) \{([\s\S]*?)@media \(max-width: 390px\)/)?.[1] || '';
    assert.match(mobileBlock, /top:\s*calc\(100% \+ 28px\)/);
    assert.match(mobileBlock, /left:\s*54px/);
    assert.match(mobileBlock, /top:\s*-48px/);
    assert.match(mobileBlock, /left:\s*-80px/);
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

test('landing Bean reveals allowlisted feature, tour, signup, and pricing destinations', () => {
    assert.match(source, /showLandingUiAction\(parameters\.destination \|\| parameters\.section \|\| parameters\.action\)/);
    assert.match(source, /showLandingUiAction\(response\?\.ui_action \|\| parameters\.destination\)/);
    assert.match(agentConfig, /required: \['destination'\]/);
    assert.match(agentConfig, /enum: \['how_it_works', 'bean', 'daily', 'calendar', 'tasks', 'reminders', 'notes', 'workspaces', 'features', 'pricing', 'signup'\]/);
    assert.match(agentConfig, /Start with the daily command center and call showLandingSection with destination "daily"/);
    assert.match(agentConfig, /move through calendar views \("calendar"\), tasks and reminders \("tasks"\), notes \("notes"\), shared workspaces \("workspaces"\), then Bean itself \("bean"\)/);

    const destinations = {
        how_it_works: '#how-it-works',
        bean: '#bean-demo',
        daily: '#tour-daily',
        calendar: '#tour-calendar',
        tasks: '#tour-tasks',
        reminders: '#tour-tasks',
        notes: '#tour-notes',
        workspaces: '#tour-workspaces',
        features: '#features',
        pricing: '#plans',
        signup: '#early-access',
    };

    for (const [destination, selector] of Object.entries(destinations)) {
        assert.match(source, new RegExp(`${destination}:\\s*\\{ selector: '${selector.replace('#', '#')}'`));
        assert.match(source, new RegExp(`href: '/${selector}'`));
    }

    for (const id of ['tour-tasks', 'tour-calendar', 'tour-daily', 'tour-context', 'tour-notes', 'tour-workspaces']) {
        assert.match(landing, new RegExp(`id="${id}"`));
    }
    assert.match(landing, /Notes keep context beside the plan\./);
    assert.match(landing, /Shared workspaces separate work, home, and recurring plans\./);
    assert.match(source, /const key = String\(action \|\| ''\)\.toLowerCase\(\)\.trim\(\)\.replace\(\/\[\\s-\]\+\/g, '_'\)/);
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
