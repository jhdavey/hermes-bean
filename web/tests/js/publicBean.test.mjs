import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../resources/js/publicBean.js', import.meta.url), 'utf8');
const styles = await readFile(new URL('../../resources/css/public-bean.css', import.meta.url), 'utf8');
const navigation = await readFile(new URL('../../resources/views/partials/public-nav.blade.php', import.meta.url), 'utf8');
const appView = await readFile(new URL('../../resources/views/app.blade.php', import.meta.url), 'utf8');
const landing = await readFile(new URL('../../resources/views/welcome.blade.php', import.meta.url), 'utf8');
const agentConfig = await readFile(new URL('../../scripts/elevenlabs-landing-agent-configure.mjs', import.meta.url), 'utf8');

test('public pages expose a compact Bean control without the authenticated chat panel', () => {
    assert.match(navigation, /data-public-bean/);
    assert.match(navigation, /Tap to talk/);
    assert.match(navigation, /data-public-bean-status/);
    assert.match(navigation, /Hey! I'm over here!/);
    assert.match(navigation, /data-public-bean-cue/);
    assert.match(navigation, /aria-label="Talk with Bean"/);
    assert.match(navigation, /href="\/register\?from=topbar_button"/);
    assert.match(navigation, /href="\/register\?from=mobile_menu"/);
    assert.doesNotMatch(navigation, /href="\/register\?from=bean"/);
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
    assert.doesNotMatch(styles, /\.public-bean-prompt|\.public-bean-intents/);
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
    assert.match(desktopCue, /left:\s*112px/);
    assert.match(desktopArrow, /top:\s*-60px/);
    assert.match(desktopArrow, /left:\s*-78px/);
    assert.match(desktopArrow, /width:\s*88px/);
    assert.match(navigation, /viewBox="0 0 88 88"/);
    assert.match(navigation, /M78 76 C56 66 48 51 40 36/);

    const mobileBlock = styles.match(/@media \(max-width: 620px\) \{([\s\S]*?)@media \(max-width: 390px\)/)?.[1] || '';
    assert.match(mobileBlock, /top:\s*calc\(100% \+ 28px\)/);
    assert.match(mobileBlock, /left:\s*72px/);
    assert.match(mobileBlock, /top:\s*-48px/);
    assert.match(mobileBlock, /left:\s*-62px/);
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
    assert.match(source, /SIGNUP_WAKE_GREETING/);
    assert.match(source, /You’re in the quick info step/);
    assert.match(agentConfig, /help you start signup whenever you’re ready/);
    assert.match(source, /firstMessage:\s*pendingFirstMessage \|\| \(signupOnboardingContext \? SIGNUP_WAKE_GREETING : WAKE_GREETING\)/);
    assert.doesNotMatch(source, /SpeechRecognition|Just say “Hey Bean|createWakeDetector|extractWakeTail|prefetchVoiceSession|restartWakeListening/);
    assert.match(source, /Demo cooldown — try again shortly/);
    assert.doesNotMatch(source, /Demo limit reached/);
    assert.match(source, /const WAKE_TO_GREETING_TARGET_MS = 1200/);
    assert.match(source, /const IDLE_CLOSE_MS = 15000/);
    assert.match(source, /voice_start_requested/);
    assert.match(source, /tap_to_start/);
    assert.doesNotMatch(source, /normalizeLandingIntent|bean_landing_intent|requested_intent|data-public-bean-intent/);
    assert.doesNotMatch(source, /Please give me the quick three-stop tour|Please help me sign up for HeyBean/);
    assert.match(source, /showLandingSection/);
    assert.match(source, /showSignupInput/);
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

test('register flow keeps one visual Bean while private signup fields stay text-only', () => {
    assert.match(appView, /request\(\)->is\('register'\)/);
    assert.match(appView, /data-public-bean-context="signup_onboarding"/);
    assert.match(appView, /public-bean-presence-signup/);
    assert.match(appView, /data-public-bean-toggle/);
    assert.match(appView, /Tap to talk/);
    assert.doesNotMatch(appView, /Hey! I'm over here!|data-public-bean-cue|public-bean-cue-arrow/);
    assert.match(appView, /resources\/js\/publicBean\.js/);
    assert.match(appView, /Type these quick details\. Bean will chime back in\./);
    assert.match(source, /publicBeanContext\(root\) === 'signup_onboarding'/);
    assert.match(source, /page_context:\s*publicBeanContext\(root\)/);
    assert.match(source, /bean_public_context:\s*publicBeanContext\(root\)/);
    assert.match(source, /bean_signup_step:\s*currentSignupOnboardingStep\(\)\.key/);
    assert.match(source, /bean_signup_step_label:\s*currentSignupOnboardingStep\(\)\.label/);
    assert.match(source, /function focusSignupOnboardingInput/);
    assert.match(source, /const setHelp = \(text\) =>/);
    assert.match(source, /function privateSignupStepIsActive/);
    assert.match(source, /signupOnboardingContext && privateSignupStepIsActive\(\)/);
    assert.match(source, /Type these quick details\. Bean will chime back in\./);
    assert.doesNotMatch(source, /SIGNUP_PROGRESS_UPDATE:|bean:signup-progress|conversation\.sendUserMessage\(prompt\)|signupProgressPrompt/);
    assert.match(source, /bean:post-signup-chime/);
    assert.match(source, /event\.detail\?\.autoVoice === true && !enabled/);
    assert.match(source, /root\.dataset\.postSignup = 'true'/);
    assert.match(source, /await stopVoiceConversation\('disabled'\)/);
    assert.match(styles, /public-bean-zero-float/);
    assert.match(styles, /public-bean-zero-glow/);
    assert.match(styles, /\.public-bean-presence-signup \.public-bean-status \{[\s\S]*?display:\s*none/);
    assert.match(agentConfig, /showSignupInput/);
    assert.match(agentConfig, /pre-account signup fields quiet and text-only/);
    assert.match(agentConfig, /Ok, i'll just get some quick info from you and show you around/);
    assert.match(agentConfig, /Bean re-enters after account creation/);
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
    assert.match(agentConfig, /showSignupInput/);
    assert.match(source, /showLandingSection:\s*async/);
    assert.match(source, /showSignupInput:\s*async/);
    assert.match(agentConfig, /answer directly with the facts below using the configured fast model/);
    assert.match(agentConfig, /Do not call a response\/reasoning tool for normal questions/);
    assert.match(agentConfig, /bean:landing-guide-facts/);
    assert.match(agentConfig, /firstMessage:\s*WAKE_GREETING/);
    assert.match(agentConfig, /Hey, I'm Bean, can you hear me\?/);
    assert.match(agentConfig, /If the visitor responds yes, yeah, yep, I can/);
    assert.match(agentConfig, /Primary goal:/);
    assert.match(agentConfig, /Help the visitor experience Bean as quickly as possible/);
    assert.match(agentConfig, /not like a nagging salesperson/);
    assert.doesNotMatch(agentConfig, /bean_landing_intent|quick-start intent/);
    assert.match(agentConfig, /expectsResponse:\s*false/);
    assert.match(agentConfig, /expectsResponse:\s*true/);
    assert.match(agentConfig, /firstMessage:\s*true/);
    assert.match(agentConfig, /llm:\s*landingLlm/);
    assert.match(agentConfig, /gpt-4\.1-nano/);
    assert.match(agentConfig, /const maxTokens = Number\(env\.ELEVENLABS_LANDING_MAX_TOKENS \|\| 260\)/);
    assert.match(agentConfig, /maxTokens,/);
    assert.match(agentConfig, /difference between two named plans/);
    assert.doesNotMatch(agentConfig, /reasoningEffort|thinkingBudget/);
    assert.match(agentConfig, /maxDurationSeconds/);
    assert.match(agentConfig, /env\.ELEVENLABS_MAX_DURATION_SECONDS \|\| 60/);
    assert.match(agentConfig, /env\.ELEVENLABS_SILENCE_TIMEOUT_SECONDS \|\| 30/);
    assert.doesNotMatch(agentConfig, /SIGNUP_PROGRESS_UPDATE|private UI state from the browser/);
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

test('landing Bean reveals the three-step quick tour plus signup and pricing destinations', () => {
    assert.match(source, /const destination = parameters\.destination \|\| parameters\.section \|\| parameters\.action/);
    assert.match(source, /focusSignupOnboardingInput\(parameters\)/);
    assert.match(source, /showLandingUiAction\(response\?\.ui_action \|\| parameters\.destination\)/);
    assert.match(agentConfig, /required: \['destination'\]/);
    assert.match(agentConfig, /enum: \['command_center', 'calendar_tasks', 'customization', 'features', 'pricing', 'signup', 'onboarding', 'how_it_works'\]/);
    assert.match(agentConfig, /keep it to exactly three short stops/);
    assert.match(agentConfig, /destination "onboarding"/);
    assert.match(agentConfig, /make it sound conversational instead of scripted/);
    assert.match(agentConfig, /Do not repeat the same question twice/);
    assert.match(agentConfig, /do not say “Want the next stop\?” more than once/);
    assert.match(agentConfig, /Ok, i'll just get some quick info from you and show you around/);
    assert.match(agentConfig, /Do not say handoff, transfer, another Bean, or explain implementation/);
    assert.match(agentConfig, /destination "command_center"/);
    assert.match(agentConfig, /destination "calendar_tasks"/);
    assert.match(agentConfig, /destination "customization"/);
    assert.doesNotMatch(agentConfig, /walk through features or pricing/);
    assert.doesNotMatch(agentConfig, /notes \("notes"\)|shared workspaces \("workspaces"\)|then Bean itself \("bean"\)/);

    const destinations = {
        how_it_works: '#how-it-works',
        bean: '#tour-command-center',
        command_center: '#tour-command-center',
        calendar_tasks: '#tour-calendar-tasks',
        calendar: '#tour-calendar-tasks',
        tasks: '#tour-calendar-tasks',
        customization: '#tour-customization',
        dashboard: '#tour-customization',
        themes: '#tour-customization',
        features: '#features',
        pricing: '#plans',
    };

    for (const [destination, selector] of Object.entries(destinations)) {
        assert.match(source, new RegExp(`${destination}:\\s*\\{ selector: '${selector.replace('#', '#')}'`));
        if (destination !== 'onboarding') {
            assert.match(source, new RegExp(`href: '/${selector}'`));
        }
    }

    for (const id of ['tour-command-center', 'tour-calendar-tasks', 'tour-customization']) {
        assert.match(landing, new RegExp(`id="${id}"`));
    }
    assert.match(landing, /heybean-tour-command-center-bean\.png/);
    assert.match(landing, /heybean-tour-calendar-tasks\.png/);
    assert.match(landing, /heybean-tour-customization-themes\.png/);
    assert.match(landing, /Quick interactive tour/);
    assert.match(landing, /Modular dashboard \+ themes/);
    assert.doesNotMatch(landing, /id="tour-notes"|id="tour-workspaces"|id="tour-context"/);
    assert.match(source, /const key = String\(action \|\| ''\)\.toLowerCase\(\)\.trim\(\)\.replace\(\/\[\\s-\]\+\/g, '_'\)/);
    assert.match(source, /document\.querySelector\(target\.scrollSelector\) \|\| section/);
    assert.match(source, /window\.scrollTo\(\{ top: Math\.max\(0, top\), behavior: reduceMotion \? 'auto' : 'smooth' \}\)/);
    assert.match(source, /scrollTarget\.classList\.add\('public-bean-guided-highlight'\)/);
    assert.match(source, /signup:\s*\{ href: '\/register\?from=bean'/);
    assert.match(source, /onboarding:\s*\{ href: '\/register\?from=bean'/);
    assert.match(source, /navigateDelay:\s*2200/);
    assert.match(source, /window\.location\.href = target\.href/);
    assert.match(source, /mountTourImageZoom\(\)/);
    assert.match(source, /closest\?\.\('\.tour-screenshot-card'\)/);
    assert.match(source, /className = 'tour-image-zoom'/);
    assert.match(source, /aria-modal', 'true'/);
    assert.match(landing, /\.tour-image-zoom/);
    assert.match(landing, /cursor:zoom-in/);
    assert.doesNotMatch(source, /overlay\.innerHTML/);
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
