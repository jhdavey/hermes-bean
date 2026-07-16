import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../resources/js/heybean/webApp.js', import.meta.url), 'utf8');

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
