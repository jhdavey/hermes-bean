import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../resources/js/heybean/webApp.js', import.meta.url), 'utf8');
const noteEditorSource = await readFile(new URL('../../resources/js/heybean/noteMarkdownEditor.js', import.meta.url), 'utf8');
const agentConfigSource = await readFile(new URL('../../scripts/elevenlabs-agent-configure.mjs', import.meta.url), 'utf8');
const themeSource = await readFile(new URL('../../resources/css/heybean/theme.css', import.meta.url), 'utf8');
const dashboardSource = await readFile(new URL('../../resources/css/heybean/dashboard.css', import.meta.url), 'utf8');
const calendarSource = await readFile(new URL('../../resources/css/heybean/calendar.css', import.meta.url), 'utf8');
const notesSource = await readFile(new URL('../../resources/css/heybean/notes.css', import.meta.url), 'utf8');
const baseShellSource = await readFile(new URL('../../resources/css/heybean/base-shell.css', import.meta.url), 'utf8');

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

test('command center includes a date-scoped plain-text sticky note with autosave', () => {
    assert.match(source, /function dailyStickyNoteMarkup/);
    assert.match(source, /data-daily-sticky-note/);
    assert.match(source, /data-sticky-note-date="\$\{escapeAttr\(date\)\}"/);
    assert.match(source, /placeholder="Sticky Note"/);
    assert.match(source, /data-daily-sticky-note-status/);
    assert.match(source, /setDailyStickyNoteStatus\(key, 'Saving'\)/);
    assert.match(source, /setDailyStickyNoteStatus\(key, newerSaveQueued \? 'Saving' : 'Saved'\)/);
    assert.match(source, /}, 10000\)/);
    assert.match(source, /method: 'PUT',[\s\S]*?body,[\s\S]*?keepalive:/);
    assert.match(source, /flushAllDailyStickyNoteAutosaves\(\{ keepalive: true \}\)/);
    assert.match(dashboardSource, /\.hb-daily-sticky-note[\s\S]*?border-top:\s*1px solid var\(--hb-border\)/);
    assert.match(cssRuleContaining(dashboardSource, '.hb-daily-sticky-note'), /background:\s*var\(--hb-surface\)/);
    assert.match(cssRuleContaining(dashboardSource, '.hb-daily-sticky-note'), /min-height:\s*165px/);
    assert.match(cssRuleContaining(dashboardSource, '.hb-daily-sticky-note textarea'), /border:\s*0/);
    assert.doesNotMatch(cssRuleContaining(dashboardSource, '.hb-daily-sticky-note textarea::placeholder'), /text-align:\s*right/);
    assert.doesNotMatch(cssRuleContaining(dashboardSource, '.hb-daily-sticky-note'), /--hb-warning/);
    assert.doesNotMatch(source, /['"]Autosaves['"]/);
    assert.doesNotMatch(source, /data-daily-sticky-note[^>]*contenteditable/);
});

test('month event pills fit their text without exceeding the date cell', () => {
    assert.match(calendarSource, /\.hb-month-event\s*\{[^}]*width:\s*fit-content;[^}]*max-width:\s*100%;[^}]*justify-self:\s*start;/s);
    assert.match(calendarSource, /\.hb-month-all-day-event\s*\{[^}]*width:\s*fit-content;[^}]*max-width:\s*100%;[^}]*justify-self:\s*start;/s);
    assert.match(calendarSource, /\.hb-month-event-title\s*\{[^}]*text-overflow:\s*ellipsis;[^}]*white-space:\s*nowrap;/s);
});

test('notes use a WYSIWYG Markdown editor with the complete formatting toolbar', () => {
    assert.match(source, /import\('\.\/noteMarkdownEditor\.js'\)/);
    assert.match(noteEditorSource, /import Editor from '@toast-ui\/editor'/);
    assert.match(source, /initialEditType:\s*'wysiwyg'/);
    assert.match(source, /hideModeSwitch:\s*true/);
    for (const group of [
        "['heading', 'bold', 'italic', 'strike']",
        "['hr', 'quote']",
        "['ul', 'ol', 'task', 'indent', 'outdent']",
        "['table', 'image', 'link']",
        "['code', 'codeblock']",
    ]) {
        assert.ok(source.includes(group), `missing Markdown toolbar group: ${group}`);
    }
    assert.match(source, /body_markdown:\s*markdown/);
    assert.match(source, /getMarkdown\(\)/);
    assert.match(source, /addImageBlobHook/);
    assert.match(notesSource, /\.hb-note-markdown-editor \.toastui-editor-toolbar/);
    assert.match(notesSource, /\.hb-note-markdown-editor \.toastui-editor-contents\s*\{[^}]*font-weight:\s*400;/s);
    assert.match(notesSource, /\.hb-note-markdown-editor \.toastui-editor-contents strong,[\s\S]*?font-weight:\s*700;/);
    assert.match(notesSource, /\.hb-note-editor-toolbar\s*\{[^}]*z-index:\s*30;/s);
    assert.match(notesSource, /\.hb-note-markdown-editor\s*\{[^}]*position:\s*relative;[^}]*z-index:\s*1;/s);
    assert.doesNotMatch(source, /document\.execCommand/);
    assert.doesNotMatch(source, /body_html|body_delta|data-note-command/);
});

test('Bean assistant presence is web-first and uses the Laravel Bean runtime', () => {
    assert.match(source, /function beanPresenceMarkup/);
    assert.match(source, /data-bean-toggle/);
    assert.match(source, /Listening locally for “Hey Bean”/);
    assert.match(source, /\/bean\/messages/);
    assert.match(source, /\/bean\/events\?after=/);
    assert.match(dashboardSource, /\.hb-bean-button/);
    assert.match(dashboardSource, /\.hb-bean-presence-open/);
    assert.match(dashboardSource, /hb-bean-pulse/);
    assert.doesNotMatch(source, /hb-bean-status-pill/);
});

test('Bean status and expandable chat share one dynamic bordered control', () => {
    assert.match(source, /function beanPanelMarkup/);
    assert.match(source, /hb-bean-status/);
    assert.match(source, /hb-bean-panel-toggle/);
    assert.match(source, /aria-controls="hb-bean-chat"/);
    assert.match(source, /<svg class="hb-bean-panel-caret"/);
    assert.match(source, /<path d="M1\.5 1\.5 6 6l4\.5-4\.5"><\/path>/);
    assert.match(source, /panelOpen \? beanPanelMarkup\(\) : ''/);
    assert.match(source, /hb-bean-chat-log/);
    assert.match(source, /data-bean-chat-form/);
    assert.match(source, /data-bean-input/);
    assert.doesNotMatch(source, /hb-bean-panel-head|data-bean-panel-close/);
    assert.doesNotMatch(source, /hb-bean-privacy-row|data-bean-privacy/);
    assert.doesNotMatch(source, /Activity log|Your HeyBean assistant/);
    assert.match(dashboardSource, /grid-template-rows: minmax\(160px, 1fr\) auto/);
    assert.match(dashboardSource, /@keyframes hb-bean-orbit/);
    assert.doesNotMatch(dashboardSource, /\.hb-bean-status-pill/);
    assert.doesNotMatch(dashboardSource, /\.hb-bean-panel\s*\{[^}]*position:\s*fixed/s);
    const presenceRule = cssRuleContaining(dashboardSource, '.hb-bean-presence');
    assert.match(presenceRule, /min-width:\s*0/);
    assert.match(presenceRule, /width:\s*fit-content/);
    assert.doesNotMatch(presenceRule, /min-width:\s*210px/);
    const toggleRule = cssRuleContaining(dashboardSource, '.hb-bean-panel-toggle');
    assert.match(toggleRule, /border:\s*0/);
    assert.doesNotMatch(toggleRule, /border-left/);
    assert.match(dashboardSource, /\.hb-bean-panel-caret\s*\{[^}]*stroke-linecap:\s*round/s);
    assert.match(dashboardSource, /\.hb-bean-presence-open \.hb-bean-panel-caret\s*\{[^}]*rotate\(180deg\)/s);
    assert.match(dashboardSource, /\.hb-bean-summary\s*\{[^}]*display:\s*flex/s);
});

test('top navigation, productivity pages, and notes use the simplified surfaces', () => {
    const topbarRule = cssRuleContaining(baseShellSource, '.hb-topbar');
    assert.match(topbarRule, /--hb-topbar-date-font-size:\s*17px/);
    assert.match(topbarRule, /border-bottom:\s*1px solid var\(--hb-border\)/);
    assert.match(source, /<section class="hb-card-pad hb-board-card" data-tour-target="tasks-view">/);
    assert.match(source, /<section class="hb-card-pad hb-board-card" data-tour-target="reminders-view">/);
    assert.match(source, /<header class="hb-board-heading"><h2>Tasks<\/h2><\/header>/);
    assert.match(source, /<header class="hb-board-heading"><h2>Reminders<\/h2><\/header>/);
    assert.match(source, /data-reminder-filter="scheduled"[^>]*>Active<\/button>/);
    assert.match(source, /data-reminder-filter="completed"[^>]*>Done<\/button>/);
    assert.doesNotMatch(source, /sectionTitle\(icons\.tasks, 'Tasks'/);
    assert.doesNotMatch(source, /sectionTitle\(icons\.reminders, 'Reminders'/);
    assert.match(dashboardSource, /\.hb-board-card > \.hb-tabs[\s\S]*?border:\s*0/);
    assert.match(cssRuleContaining(dashboardSource, '.hb-board-card > .hb-tabs'), /border-bottom:\s*1px solid var\(--hb-border\)/);
    assert.match(dashboardSource, /\.hb-board-card > \.hb-tabs \.hb-chip\[aria-pressed="true"\][\s\S]*?color:\s*var\(--hb-accent-strong\)/);
    assert.match(dashboardSource, /\.hb-main-board \.hb-day-board-column[\s\S]*?background:\s*transparent/);
    assert.match(dashboardSource, /\.hb-main-board \.hb-day-board::before[\s\S]*?background:\s*var\(--hb-border\)/);
    assert.match(dashboardSource, /\.hb-main-board \.hb-day-board::after[\s\S]*?left:\s*calc\(200% \/ 3\)/);
    assert.match(dashboardSource, /\.hb-main-board \.hb-day-board-shell > \.hb-day-board-column-all::before[\s\S]*?left:\s*-7px/);
    assert.doesNotMatch(cssRuleContaining(dashboardSource, '.hb-task-future-list'), /border-left/);
    assert.doesNotMatch(source, /noteFolderButtonMarkup\('unfiled'/);
    assert.doesNotMatch(source, /noteListOptionButton\('unfiled'/);
    assert.doesNotMatch(source, />Unfiled</);
});

test('Bean requests include the canonical user timezone for local-day task queries', () => {
    assert.match(source, /function clientTimezonePayload/);
    assert.match(source, /function userTimezone/);
    assert.match(source, /state\.user\?\.timezone/);
    assert.match(source, /Intl\.DateTimeFormat\(\)\.resolvedOptions\(\)\.timeZone/);
    assert.match(source, /client_timezone: timezone/);
    assert.match(source, /\/bean\/sessions', \{ method: 'POST', body: \{ workspace_id: currentWorkspaceId\(\), \.\.\.clientTimezonePayload\(\) \}/);
    assert.match(source, /\/bean\/messages', \{ method: 'POST', body: \{ session_id: sessionId, content, \.\.\.clientTimezonePayload\(\) \}/);
});

test('Bean voice starts from wake detection through ElevenLabs Agent client tools', () => {
    assert.doesNotMatch(source, /data-bean-voice/);
    assert.doesNotMatch(source, /Tap to talk/);
    assert.match(source, /\/bean\/elevenlabs\/conversation-token/);
    assert.match(source, /Conversation\.startSession/);
    assert.match(source, /conversationToken: realtime\.token/);
    assert.match(source, /clientTools: \{/);
    assert.match(source, /askBean: askBeanFromElevenLabsAgent/);
    assert.doesNotMatch(source, /\/bean\/elevenlabs\/bridge-sessions/);
    assert.match(source, /transport: 'elevenlabs_agent'/);
    assert.match(source, /prewarmBeanRealtimeSession/);
    assert.match(source, /handleBeanWakeDetected/);
    assert.match(source, /extractBeanWakeTail/);
    assert.match(source, /Hey Bean heard — keep talking/);
});

test('Bean wake handoff does not submit the first partial wake tail as the command', () => {
    assert.match(source, /beanPendingWakeTailTimer/);
    assert.match(source, /beanPendingWakeTail = wakeTail/);
    assert.match(source, /isLikelyCompleteBeanWakeTail/);
    assert.match(source, /statusText = 'Hey Bean heard — keep talking…'/);
    assert.match(source, /state\.bean\.voiceTranscript = wakeTail/);
    assert.doesNotMatch(source, /sendBeanVoiceTranscript\(wakeTail\)/);
    assert.match(source, /startBeanVoiceSession\(\{ wakeEvent: event, wakeTail \}\)/);
    assert.match(source, /sendUserActivity/);
});

test('Bean voice handles stale page-load status and stop commands locally', () => {
    assert.match(source, /beanEventStatusStartedAt/);
    assert.match(source, /isLiveBeanStatusEvent/);
    assert.match(source, /handleBeanVoiceControl/);
    assert.match(source, /isBeanStopCommand/);
    assert.doesNotMatch(source, /response\.cancel/);
    assert.match(source, /Dismissed — listening for “Hey Bean”/);
});

test('Bean voice lets ElevenLabs Agent own turn-taking while the client tool calls Bean', () => {
    assert.match(source, /function setBeanVoiceInputEnabled/);
    assert.match(source, /track\.enabled = Boolean\(enabled\)/);
    assert.match(source, /setBeanVoiceInputEnabled\(false\)/);
    assert.match(source, /function handleBeanElevenLabsMode/);
    assert.match(source, /onModeChange: \(\{ mode \} = \{\}\) => handleBeanElevenLabsMode\(mode\)/);
    assert.match(source, /function askBeanFromElevenLabsAgent/);
    assert.match(source, /\/bean\/messages/);
    assert.match(source, /transport: 'elevenlabs_agent'/);
    assert.match(source, /connectionType: 'webrtc'/);
    assert.match(source, /textOnly: false/);
    assert.doesNotMatch(source, /overrides: \{/);
    assert.match(source, /onAudio: \(base64Audio\) => handleBeanElevenLabsAudio\(base64Audio\)/);
    assert.match(source, /source: 'elevenlabs_agent'/);
    assert.match(source, /voice_idle_timeout_closed/);
    assert.match(source, /non_speech_transcript_ignored/);
    assert.match(source, /isBeanNonSpeechTranscript/);
    assert.match(source, /scheduleBeanVoiceIdleClose/);
    assert.match(source, /audio_playback_blocked/);
    assert.match(source, /audio_output_detected/);
    assert.match(source, /audio_chunk_received/);
    assert.match(source, /agent_audio_track_subscribed/);
    assert.match(source, /voice_livekit_participants/);
    assert.match(source, /audioElements/);
    assert.match(source, /audioCaptureContext/);
    assert.match(source, /element\.play/);
    assert.match(source, /voice_conversation_created/);
    assert.match(agentConfigSource, /agentOutputAudioFormat: 'pcm_48000'/);
    assert.match(agentConfigSource, /provider: 'scribe_realtime'/);
    assert.match(agentConfigSource, /keywords: \['Hey Bean'/);
    assert.match(agentConfigSource, /turnTimeout: 30/);
    assert.match(agentConfigSource, /silenceEndCallTimeout: 18/);
    assert.match(agentConfigSource, /speculativeTurn: true/);
    assert.match(agentConfigSource, /timeoutSeconds: -1/);
    assert.match(agentConfigSource, /message: 'Waiting\.'/);
    assert.match(agentConfigSource, /Do not ask "Are you still there\?"/);
    assert.match(agentConfigSource, /clientEvents: \['audio', 'user_transcript', 'agent_response', 'interruption'\]/);
    assert.match(agentConfigSource, /textOnly: false/);
    assert.match(source, /bean_dashboard_context: JSON\.stringify\(realtime\.dashboard_context \|\| \{\}\)/);
    assert.match(agentConfigSource, /dashboard_context/);
    assert.match(agentConfigSource, /upcoming horizon/);
    assert.match(agentConfigSource, /answer directly from dashboard_context/);
    assert.match(agentConfigSource, /\*_local timestamp fields/);
    assert.match(agentConfigSource, /call askBean/);
    assert.match(source, /isLikelyBeanAssistantEcho/);
    assert.match(source, /reason: 'assistant_speaking'/);
    assert.match(source, /beanPendingVoiceResponse/);
    assert.match(source, /watchPendingBeanVoiceResponse/);
    assert.match(source, /resolvePendingBeanVoiceResponseFromActivity/);
    assert.match(source, /voice_request_error/);
    assert.match(source, /voice_request_timed_out/);
    assert.match(source, /const beanVoiceIdleCloseMs = 18000/);
    assert.match(source, /function markBeanVoiceActivity/);
    assert.match(source, /elapsedSinceActivity < beanVoiceIdleCloseMs/);
    assert.match(source, /scheduleBeanVoiceIdleClose\(`\$\{reason\}_after_activity`\)/);
    assert.match(source, /markBeanVoiceActivity\(\);\n\s*logBeanVoiceLifecycleEvent\('bean_response_received'/);
    assert.match(source, /markBeanVoiceActivity\(\);\n\s*beanLastSpokenAnswer = content/);
    assert.match(source, /const voiceOwnsStatus = state\.bean\.voiceActive/);
    assert.match(source, /payloadMode === 'wake_listening' \|\| event\.label === 'Done'/);
    assert.doesNotMatch(source, /input_audio_buffer\.clear/);
    assert.doesNotMatch(source, /response\.create/);
    assert.doesNotMatch(source, /function sendBeanRealtimeEvent/);
    assert.match(source, /logBeanVoiceLifecycleEvent/);
    assert.match(source, /newBeanVoiceEventId/);
    assert.match(source, /voice_client_session_id/);
    assert.match(source, /voice_client_turn_id/);
    assert.match(source, /\/bean\/voice-events/);
    assert.match(source, /wake_detected/);
    assert.match(source, /voice_session_started/);
    assert.match(source, /user_transcript_received/);
    assert.match(source, /bean_request_sent/);
    assert.match(source, /bean_response_received/);
    assert.match(source, /assistant_speech_started/);
    assert.match(source, /background_audio_ignored/);
    assert.match(source, /assistant_echo_ignored/);
    assert.match(source, /function endBeanVoiceConversationForWake\(statusText = 'Listening locally for “Hey Bean”'\)/);
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
    assert.match(source, /beanWakeListeningStarting/);
    assert.match(source, /if \(beanWakeDetector \|\| beanWakeListeningStarting \|\| state\.bean\.voiceActive \|\| state\.bean\.voiceConnecting\) return/);
    assert.match(source, /function browserLocalSpeechWakeFactory/);
    assert.match(source, /const scheduleRecognitionRestart =/);
    assert.match(source, /restartDelay = Math\.min\(2000/);
    assert.match(source, /if \(Date\.now\(\) - lastRecognitionStartedAt > 5000\) restartDelay = 250/);
    assert.doesNotMatch(source, /window\.setTimeout\(startRecognition, 250\)/);
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
