import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { centeredMonthCellCount, centeredMonthGridDays } from '../../resources/js/heybean/calendarGrid.js';

const source = await readFile(new URL('../../resources/js/heybean/webApp.js', import.meta.url), 'utf8');
const noteEditorSource = await readFile(new URL('../../resources/js/heybean/noteMarkdownEditor.js', import.meta.url), 'utf8');
const agentConfigSource = await readFile(new URL('../../scripts/elevenlabs-agent-configure.mjs', import.meta.url), 'utf8');
const themeSource = await readFile(new URL('../../resources/css/heybean/theme.css', import.meta.url), 'utf8');
const dashboardSource = await readFile(new URL('../../resources/css/heybean/dashboard.css', import.meta.url), 'utf8');
const calendarSource = await readFile(new URL('../../resources/css/heybean/calendar.css', import.meta.url), 'utf8');
const notesSource = await readFile(new URL('../../resources/css/heybean/notes.css', import.meta.url), 'utf8');
const baseShellSource = await readFile(new URL('../../resources/css/heybean/base-shell.css', import.meta.url), 'utf8');
const modalsSource = await readFile(new URL('../../resources/css/heybean/modals.css', import.meta.url), 'utf8');
const onboardingPolishSource = await readFile(new URL('../../resources/css/heybean/onboarding-polish.css', import.meta.url), 'utf8');
const publicBetaBannerSource = await readFile(new URL('../../resources/views/partials/public-beta-banner.blade.php', import.meta.url), 'utf8');
const typographyOverridesSource = await readFile(new URL('../../resources/css/heybean/typography-overrides.css', import.meta.url), 'utf8');

function cssRuleContaining(sourceText, selector) {
    const selectorIndex = sourceText.indexOf(selector);
    const ruleStart = sourceText.lastIndexOf('}', selectorIndex) + 1;
    const ruleEnd = sourceText.indexOf('}', selectorIndex) + 1;

    return sourceText.slice(ruleStart, ruleEnd);
}

test('web signup uses the Zero Chrome Bean onboarding contract', () => {
    assert.match(source, /Start with Bean/);
    assert.match(source, /Use plain signup form/);
    assert.doesNotMatch(source, /Use form instead/);
    assert.doesNotMatch(source, /messages\.push\(\['user', state\.guidedSignupEmail\]\)/);
    assert.doesNotMatch(source, /Nice to meet you, \$\{state\.guidedSignupName\}/);
    assert.match(source, /plainSignupMarkup/);
    assert.match(source, /hb-guided-zero-chrome-app/);
    assert.match(source, /hb-guided-zero-chrome-shell/);
    assert.match(source, /hb-guided-zero-chrome-message/);
    assert.match(source, /function guidedCurrentBeanMessage/);
    assert.match(source, /What is your first and last name\?/);
    assert.match(source, /Choose Light, Dark, or Auto\./);
    assert.match(source, /What email should I use for your account\?/);
    assert.match(source, /Choose a password\. Type it — don’t say it\./);
    assert.doesNotMatch(source, /I’ll help get your HeyBean account set up\. What is your first and last name\?/);
    assert.match(source, /data-action="guided-onboarding"/);
    assert.match(source, /data-guided-onboarding-step="\$\{escapeAttr\(step\)\}"/);
    assert.match(source, /source: signupSource/);
    assert.match(source, /normalizedSignupSource/);
    assert.match(source, /data-action="plain-signup"/);
    assert.match(source, /function looksLikeInternalError/);
    assert.match(source, /SQLSTATE\|PDOException\|QueryException/);
    assert.match(source, /Could not \$\{action\} right now\. Please try again in a moment\./);
    assert.match(source, /Number\(error\?\.status\) >= 500/);
    assert.match(source, /function removePublicSignupBeanPresence/);
    assert.match(source, /\.public-bean-presence-signup\[data-public-bean\]/);
    assert.match(source, /root\.querySelector\('\[data-public-bean-toggle\]'\)\?\.click\(\)/);
    assert.match(source, /function shouldUseConnectedSignupBeanPresence/);
    assert.match(source, /\['post-signup-bean-choice', 'post-tour-first-action'\]\.includes\(state\.modal\?\.type\)/);
    assert.match(source, /if \(shouldUseConnectedSignupBeanPresence\(\)\) return ''/);
    assert.match(source, /removePublicSignupBeanPresence\(\);\n\s*state\.signupPaywallDeferred = false/);
    assert.doesNotMatch(source, /removePublicSignupBeanPresence\(\{ delayMs: 3600 \}\);\n\s*state\.signupPaywallDeferred = true/);
    assert.match(source, /removePublicSignupBeanPresence\(\{ delayMs: 3600 \}\);\n\s*state\.phase = 'waitlist'/);
    assert.doesNotMatch(source, /completed_step: 'name'|next_step: 'themeMode'|completed_step: 'email'|completed_step: 'password'|next_step: 'creatingAccount'/);
    assert.match(source, /function dispatchPostSignupBeanChime/);
    assert.match(source, /new CustomEvent\('bean:post-signup-chime'/);
    assert.match(source, /autoVoice:\s*fromLandingBean/);
    assert.match(source, /post-signup-bean-choice/);
    assert.match(source, /Alright, your account is created\./);
    assert.match(source, /give you a quick tour of the dashboard, help you get started, or you can skip all of that stuff and just dive in/);
    assert.match(source, /data-post-signup-tour/);
    assert.match(source, /data-post-signup-first-action/);
    assert.match(source, /data-post-signup-skip/);
    assert.match(source, /hb-zero-choice-panel/);
    assert.match(source, /Want a quick tour, a guided first action/);
    assert.match(onboardingPolishSource, /\.heybean-app-body \.hb-zero-choice-backdrop \{[\s\S]*?background:\s*transparent;[\s\S]*?backdrop-filter:\s*none/);
    assert.match(onboardingPolishSource, /\.heybean-app-body \.hb-zero-choice-panel \{[\s\S]*?text-align:\s*center/);
    assert.doesNotMatch(source, /hb-card hb-modal hb-post-tour-first-action-modal hb-post-signup-bean-choice-modal/);
    assert.match(source, /postTourFirstActionModalMarkup/);
    assert.match(source, /What do you want to do first\?/);
    assert.match(source, /Customize dashboard/);
    assert.match(source, /Import a calendar/);
    assert.match(source, /Create a shared workspace/);
    assert.match(source, /Walk me through it/);
    assert.match(source, /Have Bean do it/);
    assert.match(source, /data-post-tour-first-action-skip>Skip</);
    assert.match(source, /data-post-tour-first-action/);
    assert.match(source, /data-post-tour-bean-do-it/);
    assert.match(source, /data-post-tour-walkthrough/);
    assert.match(source, /signupPaywallDeferred/);
    assert.match(source, /startSignupDashboardPreview/);
    assert.match(source, /activateOnboardingTourStep\(0\)/);
    assert.ok(source.indexOf("state.modal = { type: 'post-signup-bean-choice' }") < source.indexOf('dispatchPostSignupBeanChime()'));
    assert.match(source, /openDeferredSignupPaywall\('Choose a plan to continue into your dashboard\.'/);
    assert.ok(source.indexOf('startSignupDashboardPreview(result)') < source.indexOf('openGuidedPlanSelection()'));
    assert.ok(source.indexOf('closeOnboardingTour()') < source.indexOf('finishPostTourFirstAction()'));
    assert.ok(source.indexOf('data-post-tour-walkthrough') < source.indexOf('data-post-tour-bean-do-it'));
    assert.doesNotMatch(source, /Skip this step/);
    assert.match(source, /sendBeanMessageContent\(action\.beanPrompt\)/);
    assert.match(source, /finishPostTourFirstAction/);
    assert.doesNotMatch(source, /openCalendarImport: true/);
    assert.doesNotMatch(source, /data-guided-tour-next/);
    assert.doesNotMatch(source, /guidedTourPanelMarkup/);
    assert.doesNotMatch(baseShellSource, /\.hb-guided-tour-card/);
    assert.match(source, /const cardTop = Math\.min\(Math\.max\(Math\.round\(viewportHeight \* 0\.36\), safeTop\), maxTop\)/);
    assert.match(onboardingPolishSource, /\.hb-onboarding-tour-card \{[\s\S]*?position:\s*fixed|\.hb-onboarding-tour-scrim,[\s\S]*?position:\s*fixed/);
    assert.match(source, /registerGuidedSignupAccount/);
    assert.match(source, /state\.phase = 'waitlist'/);
    assert.match(source, /currently at capacity/);
    assert.match(source, /controlled rollout/);
    assert.match(source, /usually within 1–2 days/);
    assert.doesNotMatch(source, /You won’t choose a plan or pay while you wait/);
    assert.ok(source.indexOf("state.phase = 'waitlist'") < source.indexOf('startSignupDashboardPreview(result)'));
    assert.match(baseShellSource, /\.hb-guided-chat-composer/);
    assert.match(baseShellSource, /--hb-guided-zero-text/);
    assert.match(baseShellSource, /--hb-guided-zero-line/);
    assert.match(baseShellSource, /\.hb-guided-immersive-topbar \{\n\s*display:\s*none/);
    assert.match(baseShellSource, /\.hb-guided-immersive-shell \.hb-guided-chat-composer \{[\s\S]*?border-bottom:\s*1px solid var\(--hb-guided-zero-line\)/);
    assert.match(baseShellSource, /\.hb-guided-immersive-shell \.hb-guided-chat-bubble-user,[\s\S]*?display:\s*none/);
    assert.doesNotMatch(baseShellSource, /--hb-guided-atmosphere/);
    assert.doesNotMatch(cssRuleContaining(baseShellSource, '.hb-guided-immersive-app'), /radial-gradient|circle at/);
    assert.doesNotMatch(baseShellSource, /hb-guided-bubble-arrive/);
    assert.doesNotMatch(cssRuleContaining(baseShellSource, '.hb-guided-immersive-shell .hb-guided-chat-composer'), /backdrop-filter:\s*blur/);
    assert.doesNotMatch(source, /public-beta-banner-register/);
});

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

test('assignable item forms use one Personal-first workspace selection contract', () => {
    assert.match(source, /function personalWorkspaceId\(\)/);
    assert.match(source, /editing \? sourceWorkspaceId : personalWorkspaceId\(\)/);
    assert.match(source, /<strong>Workspaces<\/strong>/);
    assert.match(source, /Current workspace/);
    assert.match(source, /workspaceDisplayName\(workspace\)/);
    assert.match(source, /modal\.type === 'note-create'/);
    assert.match(source, /sync_to_workspace_ids: syncTo/);
    assert.doesNotMatch(source, /const defaultWorkspaceId = String\(currentWorkspaceId\(\)/);
    assert.doesNotMatch(source, /Saved in \$\{escapeHtml\(workspaceDisplayName/);
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

test('month grid uses a five-week rolling window with its anchor in the exact center', () => {
    const anchor = new Date(2026, 6, 24, 9, 30);
    const days = centeredMonthGridDays(anchor);
    const centerIndex = Math.floor(days.length / 2);

    assert.equal(centeredMonthCellCount, 35);
    assert.equal(days.length, 35);
    assert.equal(days[centerIndex].getFullYear(), 2026);
    assert.equal(days[centerIndex].getMonth(), 6);
    assert.equal(days[centerIndex].getDate(), 24);
    assert.equal(days[0].getDate(), 7);
    assert.equal(days.at(-1).getMonth(), 7);
    assert.equal(days.at(-1).getDate(), 10);
    assert.match(source, /data-month-grid-center="\$\{dateOnly\(days\[centerIndex\]\)\}"/);
    assert.match(source, /monthCellMarkup\(day, sameMonth\(day, selected\), index === centerIndex\)/);
    assert.doesNotMatch(source, /const leading = first\.getDay\(\)/);
});

test('all-day month events use date-only exclusive end bounds', () => {
    assert.match(source, /function allDayEventStartDate\(event = \{\}\)/);
    assert.match(source, /metadata\.all_day_start_date \|\| metadata\.allDayStartDate \|\| event\.starts_at \|\| event\.startsAt/);
    assert.match(source, /function allDayEventExclusiveEndDate\(event = \{\}\)/);
    assert.match(source, /metadata\.all_day_exclusive_end_date \|\| metadata\.allDayExclusiveEndDate \|\| event\.ends_at \|\| event\.endsAt/);
    assert.match(source, /dayValue >= startDate && dayValue < exclusiveEndDate/);
    assert.doesNotMatch(cssRuleContaining(calendarSource, '.hb-month-cell'), /border-radius:\s*4px/);
});

test('beta bar and calendar month grid extend square to their edges', () => {
    assert.match(cssRuleContaining(baseShellSource, '.hb-beta-banner'), /appearance:\s*none/);
    assert.match(cssRuleContaining(baseShellSource, '.hb-beta-banner'), /border-radius:\s*0/);
    assert.match(cssRuleContaining(typographyOverridesSource, '.heybean-app-body .hb-beta-banner'), /border-radius:\s*0/);
    assert.match(cssRuleContaining(typographyOverridesSource, '.heybean-app-body .hb-beta-banner'), /margin:\s*0/);
    assert.match(cssRuleContaining(dashboardSource, '.hb-calendar-card.hb-card-pad'), /padding:\s*0/);
    assert.match(cssRuleContaining(dashboardSource, '.hb-calendar-card .hb-timeline'), /border-radius:\s*0/);
    assert.match(cssRuleContaining(calendarSource, '.hb-month-grid'), /border-radius:\s*0/);
    assert.match(cssRuleContaining(calendarSource, '.hb-month-cell'), /border-radius:\s*0/);
    assert.match(cssRuleContaining(calendarSource, '.hb-month-weekday'), /border-radius:\s*0/);
    assert.match(cssRuleContaining(typographyOverridesSource, '.heybean-app-body .hb-card.hb-calendar-card'), /border-radius:\s*0/);
    assert.match(typographyOverridesSource, /\.heybean-app-body \.hb-card\.hb-calendar-card\.hb-card-pad\s*\{[^}]*padding:\s*0;[^}]*overflow:\s*hidden;/s);
    assert.match(typographyOverridesSource, /\.heybean-app-body \.hb-calendar-card \.hb-calendar,[^}]*\.heybean-app-body \.hb-calendar-card \.hb-month-grid\s*\{[^}]*gap:\s*0;/s);
});

test('critical items consistently render a gold star immediately before their title', () => {
    assert.match(source, /function criticalTitleMarkup\(item, title, className = ''\)/);
    assert.match(source, /<span class="\$\{classes\}">\$\{criticalStarMarkup\(item\)\}<span class="hb-critical-title-text">/);
    assert.match(source, /criticalTitleMarkup\(event, eventTitleText\(event\), 'hb-month-event-title'\)/);
    assert.match(source, /criticalTitleMarkup\(item, item\.title \|\| 'Untitled', 'hb-command-center-title'\)/);
    assert.match(source, /isCritical: taskCritical\(task\)/);
    assert.match(source, /isCritical: reminderCritical\(reminder\)/);
    assert.match(source, /criticalTitleMarkup\(\{ isCritical: critical \}, item\.title \|\| item\.name \|\| 'Untitled'\)/);
    assert.match(dashboardSource, /\.hb-critical-title\s*\{[^}]*display:\s*inline-flex;[^}]*gap:\s*4px;/s);
    assert.match(dashboardSource, /\.hb-star\s*\{[^}]*font-size:\s*12px;[^}]*line-height:\s*1;/s);
    assert.doesNotMatch(calendarSource, /\.hb-month-event-title \.hb-critical-star\s*\{/);
    assert.doesNotMatch(source, /hb-item-critical-star/);
    assert.doesNotMatch(dashboardSource, /\.hb-item-critical-star/);
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
    assert.match(source, /function clientLocationPayload/);
    assert.match(source, /function isWeatherIntent/);
    assert.match(source, /navigator\.geolocation\.getCurrentPosition/);
    assert.match(source, /\.\.\.await clientLocationPayload\(content\)/);
    assert.match(source, /\/bean\/sessions', \{ method: 'POST', body: clientTimezonePayload\(\) \}/);
    assert.doesNotMatch(source, /\/bean\/sessions'[\s\S]{0,100}workspace_id: currentWorkspaceId\(\)/);
    assert.match(source, /bean_workspace_id: Number\(realtime\.dashboard_context\?\.workspace_id \|\| 0\)/);
    assert.match(source, /\/bean\/messages', \{ method: 'POST', body: \{ session_id: sessionId, content, \.\.\.clientTimezonePayload\(\), \.\.\.await clientLocationPayload\(content\) \}/);
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
    assert.match(source, /function beanWakeTailSubmitDelay/);
    assert.match(source, /weatherIntentHasEnoughLocationContext/);
    assert.match(source, /return 1300/);
    assert.match(source, /statusText = 'Hey Bean heard — keep talking…'/);
    assert.match(source, /state\.bean\.voiceTranscript = wakeTail/);
    assert.doesNotMatch(source, /sendBeanVoiceTranscript\(wakeTail\)/);
    assert.doesNotMatch(source, /wake_tail_direct_fallback_request_sent/);
    assert.doesNotMatch(source, /source: 'wake_tail_direct_fallback'/);
    assert.match(source, /startBeanVoiceSession\(\{ wakeEvent: event, wakeTail \}\)/);
    assert.match(source, /submitCompleteBeanWakeTail\(wakeTail\)/);
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
    assert.match(agentConfigSource, /voiceMaxDurationSeconds = Number\(env\.ELEVENLABS_MAX_DURATION_SECONDS \|\| 60\)/);
    assert.match(agentConfigSource, /voiceInitialWaitSeconds = Number\(env\.ELEVENLABS_INITIAL_WAIT_SECONDS \|\| env\.ELEVENLABS_SILENCE_TIMEOUT_SECONDS \|\| 5\)/);
    assert.match(agentConfigSource, /voiceSilenceEndCallSeconds = Number\(env\.ELEVENLABS_SILENCE_END_CALL_SECONDS \|\| 15\)/);
    assert.match(agentConfigSource, /turnTimeout: voiceTurnTimeoutSeconds/);
    assert.match(agentConfigSource, /initialWaitTime: voiceInitialWaitSeconds/);
    assert.match(agentConfigSource, /silenceEndCallTimeout: voiceSilenceEndCallSeconds/);
    assert.match(agentConfigSource, /turnEagerness: 'eager'/);
    assert.match(agentConfigSource, /speculativeTurn: true/);
    assert.match(agentConfigSource, /timeoutSeconds: 5/);
    assert.match(agentConfigSource, /useLlmGeneratedMessage: true/);
    assert.match(agentConfigSource, /llmGeneratedMessagePromptOverride/);
    assert.match(agentConfigSource, /still waiting on a tool or model response/);
    assert.match(agentConfigSource, /maxSoftTimeoutsPerGeneration: 1/);
    assert.match(agentConfigSource, /Do not ask "Are you still there\?"/);
    assert.match(agentConfigSource, /clientEvents: \['audio', 'user_transcript', 'agent_response', 'interruption'\]/);
    assert.match(agentConfigSource, /textOnly: false/);
    assert.match(source, /bean_dashboard_context: JSON\.stringify\(realtime\.dashboard_context \|\| \{\}\)/);
    assert.match(source, /function clientLocationPrehydrationPayload/);
    assert.match(source, /permission\?\.state !== 'granted'/);
    assert.match(source, /\.\.\.await clientLocationPrehydrationPayload\(\)/);
    assert.match(agentConfigSource, /dashboard_context/);
    assert.match(agentConfigSource, /local weather for today plus the next 7 days/);
    assert.match(agentConfigSource, /dashboard_context\.weather/);
    assert.match(agentConfigSource, /upcoming horizon/);
    assert.match(agentConfigSource, /answer directly from dashboard_context/);
    assert.match(agentConfigSource, /\*_local timestamp fields/);
    assert.match(agentConfigSource, /call askBean/);
    assert.match(agentConfigSource, /weather\/forecast/);
    assert.match(source, /isLikelyBeanAssistantEcho/);
    assert.match(source, /reason: 'assistant_speaking'/);
    assert.match(source, /beanPendingVoiceResponse/);
    assert.match(source, /watchPendingBeanVoiceResponse/);
    assert.match(source, /resolvePendingBeanVoiceResponseFromActivity/);
    assert.match(source, /voice_request_error/);
    assert.match(source, /voice_request_timed_out/);
    assert.match(source, /voice_request_recovered_to_wake/);
    assert.match(source, /Voice reset — listening for “Hey Bean”/);
    assert.doesNotMatch(source, /Voice hit a problem/);
    assert.match(source, /const beanVoiceInitialIdleCloseMs = 9000/);
    assert.match(source, /const beanVoiceFollowUpIdleCloseMs = 15000/);
    assert.match(source, /function markBeanVoiceActivity/);
    assert.match(source, /beanVoiceRequestCount > 0 \? beanVoiceFollowUpIdleCloseMs : beanVoiceInitialIdleCloseMs/);
    assert.match(source, /elapsedSinceActivity < idleCloseMs/);
    assert.match(source, /if \(!state\.bean\.voiceActive \|\| state\.bean\.busy \|\| beanPendingVoiceResponse\) return;/);
    assert.match(source, /watchPendingBeanVoiceResponse\(turnId, content\);\n\s*state\.bean\.busy = true;\n\s*state\.bean\.mode = 'working';\n\s*state\.bean\.statusText = 'Working…';/);
    assert.match(source, /clearBeanPendingVoiceResponse\(\);\n\s*state\.bean\.busy = false;\n\s*render\(\);\n\s*return answer;/);
    assert.match(source, /const beanVoiceBackgroundHandoffMs = 10000/);
    assert.match(source, /const beanVoiceBackgroundHandoffMinSpeakMs = 6500/);
    assert.match(source, /beanVoiceBackgroundHandoffMessage = 'This is taking a bit, so I’ll finish it in the background and come back when it’s ready\.'/);
    assert.match(source, /Promise\.race\(\[/);
    assert.match(source, /voice_background_handoff_started/);
    assert.match(source, /voice_background_handoff_closed/);
    assert.match(source, /voice_background_result_ready/);
    assert.match(source, /voice_background_result_starting/);
    assert.match(source, /scheduleBeanVoiceBackgroundHandoffClose/);
    assert.match(source, /beanVoiceBackgroundHandoff\.spokenAt = Date\.now\(\)/);
    assert.match(source, /beanVoiceBackgroundHandoffMinSpeakMs - \(Date\.now\(\) - spokenAt\)/);
    assert.doesNotMatch(source, /overrides:\s*[^\n]*firstMessage/);
    assert.match(source, /BACKGROUND_RESULT_DELIVERY:/);
    assert.match(source, /sendUserMessage\?\.\(backgroundDeliveryPrompt\)/);
    assert.match(source, /voice_background_result_prompt_sent/);
    assert.match(agentConfigSource, /BACKGROUND_RESULT_DELIVERY:/);
    assert.match(agentConfigSource, /do not call askBean for it/);
    assert.match(source, /bean_background_original_request/);
    assert.match(source, /bean_background_result: backgroundResultMessage/);
    assert.match(source, /startBeanVoiceSession\(\{\n\s*backgroundResultMessage: content,/);
    assert.match(source, /scheduleBeanVoiceBackgroundHandoffClose\('mode_listening_after_handoff'\)/);
    assert.match(source, /state\.bean\.statusText = 'Bean is back…'/);
    assert.match(source, /latestPersistedAssistant !== content/);
    assert.match(source, /background_handoff: isBackgroundHandoff/);
    assert.match(source, /scheduleBeanVoiceIdleClose\(`\$\{reason\}_after_activity`\)/);
    assert.match(source, /markBeanVoiceActivity\(\);\n\s*logBeanVoiceLifecycleEvent\('bean_response_received'/);
    assert.match(source, /markBeanVoiceActivity\(\);\n\s*beanLastSpokenAnswer = content/);
    assert.match(source, /setBeanIdleStatus/);
    assert.match(source, /beanIdleStatusText/);
    assert.match(source, /const voiceOwnsIdleStatus = state\.bean\.voiceActive/);
    assert.match(source, /payloadMode === 'wake_listening' \|\| payloadMode === 'privacy' \|\| event\.label === 'Done'/);
    assert.match(source, /event\.type === 'assistant_message' && liveStatusEvent\) \{\n\s*setBeanIdleStatus\(\);/);
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
