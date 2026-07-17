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
