import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../resources/js/publicBean.js', import.meta.url), 'utf8');
const styles = await readFile(new URL('../../resources/css/public-bean.css', import.meta.url), 'utf8');
const navigation = await readFile(new URL('../../resources/views/partials/public-nav.blade.php', import.meta.url), 'utf8');
const publicPostbridgeStyles = await readFile(new URL('../../resources/views/partials/public-postbridge-styles.blade.php', import.meta.url), 'utf8');
const beanPresence = await readFile(new URL('../../resources/views/partials/public-bean-presence.blade.php', import.meta.url), 'utf8');
const criticalStyles = await readFile(new URL('../../resources/views/partials/public-bean-critical-styles.blade.php', import.meta.url), 'utf8');
const handoffScript = await readFile(new URL('../../public/js/public-bean-handoff.js', import.meta.url), 'utf8');
const appShellStyles = await readFile(new URL('../../resources/css/heybean/base-shell.css', import.meta.url), 'utf8');
const appResponsiveStyles = await readFile(new URL('../../resources/css/heybean/responsive.css', import.meta.url), 'utf8');
const appView = await readFile(new URL('../../resources/views/app.blade.php', import.meta.url), 'utf8');
const landing = await readFile(new URL('../../resources/views/welcome.blade.php', import.meta.url), 'utf8');
const agentConfig = await readFile(new URL('../../scripts/elevenlabs-landing-agent-configure.mjs', import.meta.url), 'utf8');
const pricingScriptPartial = await readFile(new URL('../../resources/views/partials/public-pricing-script.blade.php', import.meta.url), 'utf8');
const publicPricingScript = await readFile(new URL('../../public/js/public-pricing.js', import.meta.url), 'utf8');
const landingSignup = await readFile(new URL('../../resources/js/landingSignup.js', import.meta.url), 'utf8');
const viteConfig = await readFile(new URL('../../vite.config.js', import.meta.url), 'utf8');

test('public nav uses Bean brand left, centered nav links, and login on the right', () => {
    assert.match(navigation, /class="brand"/);
    assert.match(navigation, /<span>Bean<\/span>/);
    assert.match(navigation, /images\/bean-logo\.png/);
    assert.match(navigation, /<nav class="navlinks"/);
    assert.match(navigation, /href="\/#how-it-works"/);
    assert.match(navigation, /href="\/#features"/);
    assert.match(navigation, /href="\/#plans"/);
    assert.match(navigation, /<a class="nav-login" href="\/login">Login<\/a>/);
    assert.doesNotMatch(navigation, /nav-cta|mobile-menu-cta|from=topbar_button|from=mobile_menu|Try it for free/);
    assert.match(navigation, /<a href="\/login">Login<\/a>/);
    assert.match(navigation, /@vite\('resources\/js\/publicBean\.js'\)/);

    assert.match(publicPostbridgeStyles, /\.nav\{height:80px;display:grid;grid-template-columns:minmax\(160px,1fr\) auto minmax\(160px,1fr\)/);
    assert.match(publicPostbridgeStyles, /\.brand\{justify-self:start/);
    assert.match(publicPostbridgeStyles, /\.navlinks\{justify-self:center/);
    assert.match(publicPostbridgeStyles, /\.nav-login\{justify-self:end/);
    assert.doesNotMatch(publicPostbridgeStyles, /\.nav-cta|\.mobile-menu-cta/);
});


test('public pricing toggle script is external so production CSP does not block it', () => {
    assert.match(pricingScriptPartial, /src="\{\{ asset\('js\/public-pricing\.js'\) \}\}"/);
    assert.doesNotMatch(pricingScriptPartial, /document\.querySelectorAll|URLSearchParams|addEventListener/);
    assert.match(publicPricingScript, /data-billing-option/);
    assert.match(publicPricingScript, /data-billing-label/);
    assert.match(publicPricingScript, /billing_interval/);
});

test('public early access banner stays at the top of the page instead of sticky viewport chrome', () => {
    assert.match(appShellStyles, /\.public-beta-banner \{[\s\S]*?position:\s*static;[\s\S]*?top:\s*auto;/);
    assert.match(publicPostbridgeStyles, /\.public-beta-banner\{position:static;top:auto;/);
    assert.doesNotMatch(publicPostbridgeStyles, /\.public-beta-banner\{position:sticky;top:0/);
});

test('public Bean presence remains icon-only where it is mounted', () => {
    assert.match(beanPresence, /data-public-bean/);
    assert.match(beanPresence, /Tap to wake up/);
    assert.match(beanPresence, /Volume on · allow mic/);
    assert.match(beanPresence, /data-public-bean-status/);
    assert.match(beanPresence, /data-public-bean-help/);
    assert.match(beanPresence, /aria-label="\{\{ \$publicBeanAria \}\}"/);
    assert.doesNotMatch(beanPresence, /data-bean-panel|hb-bean-chat|public-bean-ring/);
    assert.doesNotMatch(beanPresence, /Hey! I'm over here!|data-public-bean-cue|public-bean-cue-arrow|viewBox="0 0 88 88"/);
    assert.doesNotMatch(styles, /\.public-bean-cue|Bradley Hand|Comic Sans MS|Marker Felt|public-bean-cue-arrow|public-bean-ring|public-bean-orbit|public-bean-pulse|--public-bean-ring-angle|conic-gradient/);
    assert.match(styles, /\.public-bean-control \{[\s\S]*?width:\s*92px[\s\S]*?height:\s*92px/);
    assert.match(styles, /\.public-bean-icon img \{[\s\S]*?width:\s*68px[\s\S]*?height:\s*68px[\s\S]*?max-width:\s*68px[\s\S]*?max-height:\s*68px/);
    assert.match(beanPresence, /width=\"68\" height=\"68\"/);
    assert.match(styles, /\.public-bean-status,[\s\S]*?\.public-bean-help \{/);
    assert.doesNotMatch(styles, /\.public-bean-prompt|\.public-bean-intents/);
});

test('home landing Bean is centered in the hero above the feature icons', () => {
    assert.match(landing, /@include\('partials.public-nav'\)/);
    assert.match(landing, /public-bean-presence-hero/);
    assert.match(landing, /'status' => 'Tap to wake up'/);
    assert.match(landing, /'help' => 'Volume on · allow mic'/);
    assert.match(landing, /Hi! I'm Bean\. Your new assistant!/);
    assert.match(landing, /Bean is here to help you stay organized and on top of things like your calendar, tasks, reminders and more\./);
    assert.match(landing, /Across personal and shared workspaces, Bean is by your side, 24\/7\./);
    assert.match(landing, /Try it for free/);
    assert.doesNotMatch(landing, /Stop carrying every detail yourself\.|See Bean in action/);
    assert.ok(landing.indexOf('public-bean-presence-hero') >= 0);
    assert.ok(landing.indexOf('public-bean-presence-hero') < landing.indexOf('hero-icons'));
    assert.doesNotMatch(landing, /class="hero-icon bean"/);
    assert.doesNotMatch(landing, /aria-label="Bean"[\s\S]*?images\/bean-logo\.png/);
    assert.doesNotMatch(publicPostbridgeStyles, /hero-icon\.bean/);

    assert.match(landing, /data-public-landing-page/);
    assert.match(landing, /class="public-landing-content"/);
    assert.match(landing, /class="public-landing-signup-flow"/);
    assert.match(landing, /id="heybean-web-app"/);
    assert.match(landing, /data-auth-mode="register"/);
    assert.match(landing, /data-from-landing-bean="true"/);
    assert.match(landing, /resources\/js\/landingSignup\.js/);
    assert.match(landingSignup, /mountHeyBeanWebApp\(mount\)/);
    assert.match(viteConfig, /resources\/js\/landingSignup\.js/);
    assert.match(landingSignup, /a\[href\]/);
    assert.match(landingSignup, /signupPathPattern/);
    assert.match(landingSignup, /event\.preventDefault\(\)/);
    assert.match(landingSignup, /bean:inline-signup-started/);
    assert.match(landingSignup, /history\.pushState\(\{ inlineSignup: true \}/);
    assert.match(landingSignup, /public-signup-active/);
    assert.match(landing, /@include\('partials.public-bean-critical-styles'\)/);
    assert.match(appView, /@include\('partials.public-bean-critical-styles'\)/);
    assert.match(criticalStyles, /js\/public-bean-handoff\.js/);
    assert.match(handoffScript, /heybean\.publicBean\.handoff/);
    assert.match(handoffScript, /--public-bean-handoff-top/);
    assert.match(handoffScript, /--public-bean-handoff-left/);
    assert.match(handoffScript, /dataset\.publicBeanHandoff = 'true'/);
    assert.doesNotMatch(criticalStyles, /window\.sessionStorage|document\.documentElement\.dataset\.publicBeanHandoff/);
    assert.match(criticalStyles, /\.public-bean-presence-hero,[\s\S]*?\.public-bean-presence-signup \{/);
    assert.match(criticalStyles, /position:\s*fixed/);
    assert.match(criticalStyles, /width:\s*68px[\s\S]*?height:\s*68px[\s\S]*?max-width:\s*68px[\s\S]*?max-height:\s*68px/);
    assert.match(criticalStyles, /body:has\(\.public-bean-presence-hero\)::before \{[\s\S]*?opacity:\s*0;[\s\S]*?transition:\s*opacity \.24s ease/);
    assert.match(criticalStyles, /body\.public-bean-landing-compact::before \{[\s\S]*?opacity:\s*1/);
    const criticalCompactBlock = criticalStyles.match(/\.public-bean-presence-hero\[data-landing-scroll="compact"\] \{([\s\S]*?)\n    \}/)?.[1] || '';
    assert.match(criticalCompactBlock, /left:\s*50%/);
    assert.doesNotMatch(criticalCompactBlock, /top:\s*calc\(env\(safe-area-inset-top/);
    assert.match(criticalStyles, /\.public-bean-presence-hero\[data-landing-scroll="compact"\] \.public-bean-icon img \{[\s\S]*?width:\s*46px[\s\S]*?height:\s*46px/);
    const heroPresence = styles.match(/\.public-bean-presence-hero \{([\s\S]*?)\n\}/)?.[1] || '';
    assert.match(heroPresence, /position:\s*fixed/);
    assert.match(heroPresence, /--public-bean-handoff-top/);
    assert.match(heroPresence, /--public-bean-handoff-left/);
    assert.match(heroPresence, /transform:\s*translateX\(-50%\)/);
    assert.match(styles, /body:has\(\.public-bean-presence-hero\) \.hero \.hero-icons \{[\s\S]*?margin-top:\s*137px/);
    assert.match(styles, /body:has\(\.public-bean-presence-hero\)::before \{[\s\S]*?height:\s*calc\(env\(safe-area-inset-top, 0px\) \+ 154px\)[\s\S]*?opacity:\s*0;[\s\S]*?transition:\s*opacity \.24s ease/);
    assert.match(styles, /\.public-landing-signup-flow \{[\s\S]*?position:\s*fixed[\s\S]*?opacity:\s*0/);
    assert.match(styles, /body\.public-signup-active \.public-landing-content \{[\s\S]*?opacity:\s*0[\s\S]*?filter:\s*blur\(6px\)/);
    assert.match(styles, /body\.public-signup-active \.public-landing-signup-flow \{[\s\S]*?opacity:\s*1/);
    assert.match(styles, /body\.public-signup-active \.public-bean-presence-hero \.public-bean-help,[\s\S]*?body\.public-signup-active \.public-bean-presence-signup \.public-bean-help \{[\s\S]*?display:\s*none/);
    assert.match(styles, /body\.public-signup-active \.hb-guided-immersive-shell,[\s\S]*?padding-top:\s*clamp\(330px, 44vh, 372px\)/);
    assert.match(styles, /body\.public-bean-landing-compact::before \{[\s\S]*?opacity:\s*1/);
    const bundledCompactBlock = styles.match(/\.public-bean-presence-hero\[data-landing-scroll="compact"\] \{([\s\S]*?)\n\}/)?.[1] || '';
    assert.match(bundledCompactBlock, /left:\s*50%/);
    assert.doesNotMatch(bundledCompactBlock, /top:\s*calc\(env\(safe-area-inset-top/);
    assert.match(styles, /\.public-bean-presence-hero\[data-landing-scroll="compact"\] \.public-bean-control,[\s\S]*?width:\s*64px[\s\S]*?height:\s*64px/);
    assert.match(styles, /\.public-bean-presence-hero\[data-landing-scroll="compact"\] \.public-bean-icon img \{[\s\S]*?width:\s*46px[\s\S]*?height:\s*46px/);
    assert.match(source, /function mountLandingBeanScrollState\(root\)/);
    assert.match(source, /const heroTopPx = parseFloat\(window\.getComputedStyle\(root\)\.top/);
    assert.match(source, /const dockedTopPx = \(\) => \(window\.matchMedia\?\.\('\(max-width: 620px\)'\)\?\.matches \? 10 : 14\)/);
    assert.match(source, /const travelTop = Math\.max\(dockedTop, heroTopPx - window\.scrollY\)/);
    assert.match(source, /const docked = window\.scrollY > 0 && travelTop <= dockedTop \+ 0\.5/);
    assert.match(source, /const travelling = window\.scrollY > 0 && !docked/);
    assert.match(source, /--public-bean-scroll-top/);
    assert.match(source, /root\.dataset\.landingScroll = docked \? 'compact' : \(travelling \? 'travel' : 'hero'\)/);
    assert.match(source, /classList\.toggle\('public-bean-landing-compact', docked\)/);
    assert.match(source, /window\.addEventListener\('scroll', requestScrollState, \{ passive: true \}\)/);
    assert.match(source, /root\.classList\.contains\('public-bean-presence-hero'\)/);
    assert.doesNotMatch(source, /updateScrolledCueState|data-public-bean-cue/);
});

test('landing Bean starts voice directly from an explicit tap with a hearing check', () => {
    assert.match(source, /let enabled = false/);
    assert.doesNotMatch(source, /localStorage\.getItem|localStorage\.setItem/);
    assert.match(source, /navigator\.mediaDevices\.getUserMedia\(\{ audio: true \}\)/);
    assert.match(source, /Turn volume on\. Allow mic\./);
    assert.match(source, /await startVoiceConversation\(revision\)/);
    assert.doesNotMatch(source, /cue\?\.addEventListener|data-public-bean-cue/);
    assert.match(source, /Conversation\.startSession/);
    assert.match(source, /conversationToken:\s*session\.token/);
    assert.match(source, /Hey, I'm Bean, can you hear me\?/);
    assert.match(source, /SIGNUP_WAKE_GREETING/);
    assert.match(source, /You’re in the quick info step/);
    assert.match(agentConfig, /I can give you a quick tour or answer questions/);
    assert.doesNotMatch(agentConfig, /help you start signup whenever you’re ready/);
    assert.match(source, /firstMessage:\s*pendingFirstMessage \|\| \(isSignupOnboardingContext\(\) \? SIGNUP_WAKE_GREETING : WAKE_GREETING\)/);
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
    assert.match(beanPresence, /data-voice-event-url/);

    const permissionRequest = source.indexOf('navigator.mediaDevices.getUserMedia({ audio: true })');
    const voiceStart = source.indexOf('await startVoiceConversation(revision)');
    assert.ok(permissionRequest >= 0);
    assert.ok(voiceStart > permissionRequest);
});

test('register flow keeps one visual Bean while private signup fields stay text-only', () => {
    assert.match(appView, /request\(\)->is\('register'\)/);
    assert.match(appView, /@include\('partials.public-bean-presence'/);
    assert.match(appView, /'context' => 'signup_onboarding'/);
    assert.match(appView, /public-bean-presence-signup/);
    assert.match(appView, /'status' => 'Tap to wake up'/);
    assert.doesNotMatch(appView, /Hey! I'm over here!|data-public-bean-cue|public-bean-cue-arrow/);
    assert.match(appView, /resources\/js\/publicBean\.js/);
    assert.match(appView, /Type these quick details\. Bean will chime back in\./);
    assert.match(source, /const isSignupOnboardingContext = \(\) => publicBeanContext\(root\) === 'signup_onboarding'/);
    assert.match(source, /page_context:\s*publicBeanContext\(root\)/);
    assert.match(source, /bean_public_context:\s*publicBeanContext\(root\)/);
    assert.match(source, /bean_signup_step:\s*currentSignupOnboardingStep\(\)\.key/);
    assert.match(source, /bean_signup_step_label:\s*currentSignupOnboardingStep\(\)\.label/);
    assert.match(source, /function focusSignupOnboardingInput/);
    assert.match(source, /const setHelp = \(text\) =>/);
    assert.match(source, /function privateSignupStepIsActive/);
    assert.match(source, /isSignupOnboardingContext\(\) && privateSignupStepIsActive\(\)/);
    assert.match(source, /Type these quick details\. Bean will chime back in\./);
    assert.doesNotMatch(source, /SIGNUP_PROGRESS_UPDATE:|bean:signup-progress|conversation\.sendUserMessage\(prompt\)|signupProgressPrompt/);
    assert.match(source, /bean:post-signup-chime/);
    assert.match(source, /const BEAN_HANDOFF_KEY = 'heybean\.publicBean\.handoff'/);
    assert.match(source, /function captureBeanHandoffState/);
    assert.match(source, /function navigateWithBeanHandoff/);
    assert.match(source, /window\.sessionStorage\.setItem\(BEAN_HANDOFF_KEY/);
    assert.match(styles, /\.public-bean-presence-signup \{[\s\S]*?top:\s*var\(--public-bean-handoff-top, var\(--public-bean-shell-top\)\)/);
    assert.match(styles, /\.public-bean-presence-signup \.public-bean-icon img \{[\s\S]*?width:\s*68px[\s\S]*?height:\s*68px/);
    assert.match(appShellStyles, /:has\(\.public-bean-presence-signup\)[\s\S]*?padding-top:\s*clamp\(292px, 39vh, 326px\)/);
    assert.match(appResponsiveStyles, /:has\(\.public-bean-presence-signup\)[\s\S]*?padding:\s*clamp\(292px, 39vh, 326px\) 16px 88px/);
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
    assert.ok(disableBody.indexOf("setStatus('disabled', 'Tap to wake up')") >= 0);
    assert.ok(disableBody.indexOf("setStatus('disabled', 'Tap to wake up')") < disableBody.indexOf("await stopVoiceConversation('disabled')"));
});

test('landing voice uses a dedicated fast ElevenLabs guide with an action-only public section tool', () => {
    assert.match(agentConfig, /ELEVENLABS_LANDING_AGENT_ID/);
    assert.match(agentConfig, /showLandingSection/);
    assert.match(agentConfig, /showSignupInput/);
    assert.match(source, /showLandingSection:\s*async/);
    assert.match(source, /showSignupInput:\s*async/);
    assert.match(source, /keepVoiceAliveAfterUiAction/);
    assert.match(source, /conversation\?\.sendUserActivity\?\.\(\)/);
    assert.match(agentConfig, /answer directly with the facts below using the configured fast model/);
    assert.match(agentConfig, /Do not call a response\/reasoning tool for normal questions/);
    assert.match(agentConfig, /bean:landing-guide-facts/);
    assert.match(agentConfig, /firstMessage:\s*WAKE_GREETING/);
    assert.match(agentConfig, /Hey, I'm Bean, can you hear me\?/);
    assert.match(agentConfig, /If the visitor responds yes, yeah, yep, I can/);
    assert.match(agentConfig, /Do not call showLandingSection for this hearing-check confirmation/);
    assert.match(agentConfig, /do not move the page unless the visitor asks for a tour/);
    assert.match(agentConfig, /Do not call showLandingSection for greetings, hearing checks, acknowledgements, filler/);
    assert.match(agentConfig, /Primary goal:/);
    assert.match(agentConfig, /Help the visitor understand Bean quickly/);
    assert.match(agentConfig, /not a salesperson/);
    assert.match(agentConfig, /Do not repeatedly suggest signup or pressure them/);
    assert.doesNotMatch(agentConfig, /naturally steer interested visitors|nagging salesperson|If they sound interested/);
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
    assert.match(beanPresence, /data-turnstile-site-key/);
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
    assert.match(agentConfig, /Do not pivot to signup unless they explicitly ask how to try or start/);
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
    assert.match(source, /requestInlineSignupOrNavigate\(target\.href\)/);
    assert.match(source, /bean:request-inline-signup/);
    assert.match(source, /new CustomEvent\('bean:request-inline-signup'/);
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

test('landing Bean does not render circular listening indicators around the icon', () => {
    assert.doesNotMatch(beanPresence, /public-bean-ring/);
    assert.doesNotMatch(styles, /@property --public-bean-ring-angle|--public-bean-ring-angle|conic-gradient|public-bean-ring|public-bean-orbit|public-bean-pulse/);
    assert.match(styles, /\.public-bean-icon img \{[\s\S]*?filter:\s*drop-shadow/);
});
