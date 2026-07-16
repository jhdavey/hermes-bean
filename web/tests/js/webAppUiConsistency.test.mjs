import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../resources/js/heybean/webApp.js', import.meta.url), 'utf8');
const dashboardCss = await readFile(new URL('../../resources/css/heybean/dashboard.css', import.meta.url), 'utf8');
const notesCss = await readFile(new URL('../../resources/css/heybean/notes.css', import.meta.url), 'utf8');

function implementationBetween(startMarker, endMarker) {
    const start = source.indexOf(startMarker);
    const end = source.indexOf(endMarker, start);
    assert.ok(start >= 0 && end > start, `${startMarker} must remain discoverable`);
    return source.slice(start, end);
}

test('task and reminder rows use only a category rail and omit category metadata', () => {
    const itemCss = dashboardCss.slice(
        dashboardCss.indexOf('.hb-item {'),
        dashboardCss.indexOf('.hb-item > .hb-icon-button'),
    );
    assert.match(itemCss, /border:\s*0/);
    assert.match(itemCss, /background:\s*transparent/);
    assert.match(itemCss, /inset 3px 0 0/);
    assert.doesNotMatch(itemCss, /border-bottom/);

    const taskSubtitle = implementationBetween('function taskSubtitle(', '\n    function reminderSubtitle(');
    const reminderSubtitle = implementationBetween('function reminderSubtitle(', '\n    function eventsForDay(');
    assert.doesNotMatch(taskSubtitle, /\.category/);
    assert.doesNotMatch(reminderSubtitle, /\.category/);
});

test('create and edit item modals expose a consistent Save action', () => {
    const modal = implementationBetween('function itemModalMarkup(', '\n    function formSectionMarkup(');
    assert.match(modal, /data-modal-save-button>Save<\/button>/);
    assert.doesNotMatch(modal, /data-modal-save-button>\$\{editing \? 'Save' : 'Create'\}/);
});

test('initial dashboard hydration stays behind one launch loader and suppresses partial refresh errors', () => {
    const load = implementationBetween('async function loadSignedIn(', '\n    function mergeUser(');
    assert.match(load, /state\.phase = 'loading'/);
    assert.match(load, /state\.error = ''/);
    assert.doesNotMatch(load, /state\.error = refreshError \?/);
});

test('notes persist local drafts immediately and flush queued changes on page exit', () => {
    const schedule = implementationBetween('function scheduleNoteAutosave(', '\n    function flushNoteAutosave(');
    assert.match(schedule, /saveDashboardCache\(\)/);
    assert.match(source, /flushAllNoteAutosaves\(\{ keepalive: true \}\)/);
    assert.match(source, /const noteSaveInFlight = new Set\(\)/);
    assert.doesNotMatch(source, /class="hb-note-save-state"/);

    assert.match(notesCss, /\.hb-note-list-line\s*\{[\s\S]*display:\s*flex/);
    assert.match(notesCss, /flex:\s*0 0 18px/);
});
