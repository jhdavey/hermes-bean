import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../resources/js/heybean/webApp.js', import.meta.url), 'utf8');

function implementationBetween(startMarker, endMarker) {
    const start = source.indexOf(startMarker);
    const end = source.indexOf(endMarker, start);
    assert.ok(start >= 0 && end > start, `${startMarker} must remain discoverable`);
    return source.slice(start, end);
}

test('web reminders use only scheduled and completed domain statuses', () => {
    const markup = implementationBetween('function remindersMarkup()', '\n    function planLimitUpgradeMarkup(');
    assert.match(markup, /const status = completed \? 'completed' : 'scheduled'/);
    assert.match(markup, /data-reminder-filter="scheduled"/);
    assert.match(markup, /No scheduled reminders/);
    assert.doesNotMatch(markup, /pending/i);

    const toggle = implementationBetween('async function toggleReminder(', '\n    function handleChatInput(');
    assert.match(toggle, /status: completed \? 'scheduled' : 'completed'/);
    assert.doesNotMatch(toggle, /['"]pending['"]/i);

    const selectors = implementationBetween('function scheduledReminders()', '\n    function criticalItems(');
    assert.match(selectors, /reminder\?\.status === 'scheduled'/);
    assert.doesNotMatch(selectors, /toLowerCase|pending|complete'|done/i);
});

test('web calendar editor uses only scheduled and cancelled domain statuses', () => {
    const fields = implementationBetween('function eventDetailFieldsMarkup(', '\n    function eventReminderFieldsMarkup(');
    assert.match(fields, /\['scheduled', 'cancelled'\]/);
    assert.match(fields, /item\?\.status \|\| 'scheduled'/);
    assert.doesNotMatch(fields, /confirmed|tentative|canceled/i);

    const request = implementationBetween('function itemSaveRequest(', '\n    function eventPlaceMetadataFromFormData(');
    assert.match(request, /status: item\?\.status \|\| 'scheduled'/);
    assert.match(request, /status: data\.status \|\| 'scheduled'/);
    assert.doesNotMatch(request, /confirmed|tentative|canceled/i);
});

test('Bean work lifecycle ignores unknown server statuses instead of completing them', () => {
    const normalizer = implementationBetween('function beanWorkEventStatus(', '\n    function beanWorkEventLabel(');
    assert.match(normalizer, /return null/);
    assert.doesNotMatch(normalizer, /return ['"]completed['"]/);

    const eventProjection = implementationBetween('function beanWorkItemFromEvent(', '\n    function beanWorkEventStatus(');
    assert.match(eventProjection, /!label \|\| !canonicalStatus/);
    assert.match(eventProjection, /assistant\.semantic_operation\.receipt/);
    assert.doesNotMatch(eventProjection, /assistant\.work_item\.planned/);
});
