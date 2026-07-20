import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../resources/js/heybean/webApp.js', import.meta.url), 'utf8');
const themeSource = await readFile(new URL('../../resources/css/heybean/theme.css', import.meta.url), 'utf8');
const dashboardSource = await readFile(new URL('../../resources/css/heybean/dashboard.css', import.meta.url), 'utf8');

function cssRuleContaining(sourceText, selector) {
    const selectorIndex = sourceText.indexOf(selector);
    const ruleStart = sourceText.lastIndexOf('}', selectorIndex) + 1;
    const ruleEnd = sourceText.indexOf('}', selectorIndex) + 1;

    return sourceText.slice(ruleStart, ruleEnd);
}

test('browser app retains direct productivity resources and command center', () => {
    for (const path of ['/tasks', '/reminders', '/calendar-events', '/notes', '/workspaces']) {
        assert.match(source, new RegExp(path.replace('/', '\\/')));
    }
    assert.match(source, /function commandCenterMarkup/);
    assert.match(source, /Today and upcoming list/);
    assert.match(source, /function adminExecutiveKpisMarkup/);
    assert.match(source, /function adminHealthGridMarkup/);
    assert.match(source, /\/admin\/dashboard\/summary/);
});

test('Bean assistant presence is web-first and uses the Laravel Bean runtime', () => {
    assert.match(source, /function beanPresenceMarkup/);
    assert.match(source, /data-bean-toggle/);
    assert.match(source, /Listening locally for “Hey Bean”/);
    assert.match(source, /\/bean\/messages/);
    assert.match(source, /\/bean\/events\?after=/);
    assert.match(dashboardSource, /\.hb-bean-button/);
    assert.match(dashboardSource, /hb-bean-pulse/);
});

test('expanded Bean panel only shows chat history and the composer', () => {
    assert.match(source, /function beanPanelMarkup/);
    assert.match(source, /hb-bean-chat-log/);
    assert.match(source, /data-bean-chat-form/);
    assert.match(source, /data-bean-input/);
    assert.doesNotMatch(source, /hb-bean-panel-head|data-bean-panel-close/);
    assert.doesNotMatch(source, /hb-bean-privacy-row|data-bean-privacy/);
    assert.doesNotMatch(source, /Activity log|Your HeyBean assistant/);
    assert.match(dashboardSource, /grid-template-rows: minmax\(160px, 1fr\) auto/);
});

test('Bean requests include the browser timezone for local-day task queries', () => {
    assert.match(source, /function clientTimezonePayload/);
    assert.match(source, /Intl\.DateTimeFormat\(\)\.resolvedOptions\(\)\.timeZone/);
    assert.match(source, /client_timezone: timezone/);
    assert.match(source, /\/bean\/sessions', \{ method: 'POST', body: \{ workspace_id: currentWorkspaceId\(\), \.\.\.clientTimezonePayload\(\) \}/);
    assert.match(source, /\/bean\/messages', \{ method: 'POST', body: \{ session_id: sessionId, content, \.\.\.clientTimezonePayload\(\) \}/);
});

test('Bean voice starts from wake detection instead of tap-to-talk', () => {
    assert.doesNotMatch(source, /data-bean-voice/);
    assert.doesNotMatch(source, /Tap to talk/);
    assert.match(source, /\/bean\/realtime\/session/);
    assert.match(source, /navigator\.mediaDevices\.getUserMedia/);
    assert.match(source, /new RTCPeerConnection/);
    assert.match(source, /https:\/\/api\.openai\.com\/v1\/realtime\/calls/);
    assert.match(source, /prewarmBeanRealtimeSession/);
    assert.match(source, /beanRealtimeEventQueue/);
    assert.match(source, /flushBeanRealtimeEventQueue/);
    assert.match(source, /handleBeanWakeDetected/);
    assert.match(source, /extractBeanWakeTail/);
    assert.match(source, /Hey Bean heard — keep talking/);
});

test('Bean wake handoff does not submit the first partial wake tail as the command', () => {
    assert.match(source, /beanPendingWakeTailTimer/);
    assert.match(source, /scheduleBeanWakeTailStabilization/);
    assert.match(source, /isLikelyCompleteBeanWakeTail/);
    assert.match(source, /statusText = 'Hey Bean heard — keep talking…'/);
    assert.match(source, /state\.bean\.voiceTranscript = wakeTail/);
    assert.doesNotMatch(source, /sendBeanVoiceTranscript\(wakeTail\)/);
    assert.match(source, /startBeanVoiceSession\(\{ wakeEvent: event, wakeTail \}\)/);
});

test('Bean voice handles stale page-load status and stop commands locally', () => {
    assert.match(source, /beanEventStatusStartedAt/);
    assert.match(source, /isLiveBeanStatusEvent/);
    assert.match(source, /handleBeanVoiceControl/);
    assert.match(source, /isBeanStopCommand/);
    assert.match(source, /response\.cancel/);
    assert.match(source, /Dismissed — listening for “Hey Bean”/);
});

test('Bean voice mutes realtime mic while answering and only opens a short follow-up window', () => {
    assert.match(source, /const beanFollowUpWindowMs = 30000/);
    assert.match(source, /const beanPostSpeechInputCooldownMs = 700/);
    assert.match(source, /function setBeanVoiceInputEnabled/);
    assert.match(source, /track\.enabled = Boolean\(enabled\)/);
    assert.match(source, /setBeanVoiceInputEnabled\(false\)/);
    assert.match(source, /scheduleBeanFollowUpListening/);
    assert.match(source, /const beanAssistantSpeechMaxMuteMs = 3500/);
    assert.match(source, /function openBeanFollowUpAfterAssistantSpeech/);
    assert.match(source, /event\.track\.onmute = openBeanFollowUpAfterAssistantSpeech/);
    assert.match(source, /beanRemoteAudio\.onpause = openBeanFollowUpAfterAssistantSpeech/);
    assert.match(source, /scheduleBeanAssistantSpeechFallback/);
    assert.match(source, /const estimatedSpeechMs = Math\.min\(beanAssistantSpeechMaxMuteMs, Math\.max\(1200, String\(answer \|\| ''\)\.length \* 28\)\)/);
    assert.match(source, /isLikelyBeanAssistantEcho/);
    assert.match(source, /if \(state\.bean\.mode === 'speaking'\) openBeanFollowUpAfterAssistantSpeech\(\)/);
    assert.match(source, /clearBeanAssistantSpeechFallbackTimer/);
    assert.match(source, /Listening for a follow-up…/);
    assert.match(source, /input_audio_buffer\.clear/);
    assert.match(source, /Date\.now\(\) < beanVoiceInputIgnoreUntil/);
    assert.match(source, /beanEndVoiceAfterAnswer/);
    assert.match(source, /data\.run\?\.status === 'failed'/);
    assert.match(source, /endBeanVoiceConversationForWake\('Listening locally for “Hey Bean”'\)/);
    assert.match(source, /endBeanVoiceConversationForWake\(\)/);
});

test('Bean voice dismisses to wake-word-only mode before accepting more speech', () => {
    assert.match(source, /function endBeanVoiceConversationForWake/);
    assert.match(source, /Dismissed — listening for “Hey Bean”/);
    assert.match(source, /dismiss bean/);
    assert.match(source, /thanks bean/);
    assert.match(source, /goodbye/);
    assert.match(source, /stopBeanVoiceSession\(\{ statusText \}\)/);
    assert.match(source, /state\.bean\.mode = localStorage\.getItem\('heybean\.bean\.privacy'\) === 'listening' \? 'wake_listening' : 'privacy'/);
});

test('Bean local wake mode only uses local wake detectors', () => {
    assert.match(source, /HeyBeanLocalWakeDetector/);
    assert.match(source, /processLocally/);
    assert.match(source, /Listening locally for “Hey Bean”/);
    assert.doesNotMatch(source, /webkitSpeechRecognition/);
});

test('dark mode retains the original menu, modal, card, and command center surfaces', () => {
    const darkThemeSelector = '.heybean-app-body[data-hb-theme-resolved="dark"]';
    const menuRule = cssRuleContaining(themeSource, `${darkThemeSelector} .hb-profile-popover`);
    for (const selector of ['.hb-card', '.hb-surface-soft', '.hb-profile-popover', '.hb-create-popover', '.hb-critical-popover', '.hb-modal']) {
        assert.match(menuRule, new RegExp(selector.replace('.', '\\.')));
    }
    assert.match(menuRule, /linear-gradient\(180deg, rgba\(22, 29, 37, \.96\), rgba\(13, 18, 24, \.96\)\)/);

    const commandCenterRule = cssRuleContaining(dashboardSource, `${darkThemeSelector} .hb-command-center-card`);
    for (const selector of ['.hb-card', '.hb-surface-soft', '.hb-day-board-column', '.hb-command-center-card']) {
        assert.match(commandCenterRule, new RegExp(selector.replace('.', '\\.')));
    }
    assert.match(commandCenterRule, /background: var\(--hb-surface\)/);
});
