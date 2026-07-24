import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const appCss = await readFile(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
const zeroChrome = await readFile(new URL('../../resources/css/heybean/zero-chrome.css', import.meta.url), 'utf8');
const utilityStyles = await readFile(new URL('../../resources/views/partials/utility-page-styles.blade.php', import.meta.url), 'utf8');
const legalLayout = await readFile(new URL('../../resources/views/legal/layout.blade.php', import.meta.url), 'utf8');

function ruleContaining(source, selector) {
    const start = source.indexOf(`${selector} {`);
    assert.notEqual(start, -1, `Missing selector ${selector}`);
    const end = source.indexOf('}', start);
    assert.notEqual(end, -1, `Missing closing brace for ${selector}`);
    return source.slice(start, end + 1);
}

test('the app-wide zero chrome layer loads last', () => {
    const calendarIndex = appCss.indexOf("@import './heybean/calendar-zero-chrome.css';");
    const systemIndex = appCss.indexOf("@import './heybean/zero-chrome.css';");

    assert.ok(calendarIndex >= 0);
    assert.ok(systemIndex > calendarIndex);
});

test('zero chrome covers every Laravel application surface family', () => {
    for (const selector of [
        '.hb-auth-card',
        '.hb-modal',
        '.hb-settings-grid',
        '.hb-admin-panel',
        '.hb-notes-app',
        '.hb-subscribe-plan',
        '.hb-bean-panel',
        '.hb-profile-popover',
    ]) {
        assert.match(zeroChrome, new RegExp(selector.replace('.', '\\.')));
    }

    assert.match(ruleContaining(zeroChrome, '.heybean-app-body :is(.hb-input, .hb-textarea, .hb-select)'), /border-bottom:\s*1px solid var\(--hb-zero-line-strong\)/);
    assert.match(ruleContaining(zeroChrome, '.heybean-app-body .hb-modal'), /border-radius:\s*0/);
    assert.match(ruleContaining(zeroChrome, '.heybean-app-body .hb-settings-grid'), /background:\s*var\(--hb-zero-paper\)/);
    assert.match(ruleContaining(zeroChrome, '.heybean-app-body .hb-admin-panel'), /padding:/);
    assert.doesNotMatch(zeroChrome, /linear-gradient|radial-gradient|backdrop-filter:\s*blur/);
});

test('standalone auth, invitation, reset, and legal views share the flat treatment', () => {
    assert.match(utilityStyles, /\.card\s*\{[^}]*border-top:\s*1px solid var\(--hb-line-strong\);[^}]*border-radius:\s*0;[^}]*box-shadow:\s*none;/s);
    assert.match(utilityStyles, /input\s*\{[^}]*border-bottom:\s*1px solid var\(--hb-line-strong\);[^}]*border-radius:\s*0;/s);
    assert.doesNotMatch(utilityStyles, /linear-gradient|radial-gradient|backdrop-filter/);

    assert.match(legalLayout, /main\s*\{[^}]*border-top:1px solid var\(--line-strong\);[^}]*border-radius:0;[^}]*box-shadow:none;/s);
    assert.match(legalLayout, /\.card\s*\{[^}]*border-top:1px solid var\(--line-strong\);[^}]*border-radius:0;/s);
    assert.doesNotMatch(legalLayout, /linear-gradient|radial-gradient|backdrop-filter/);
});
