import assert from 'node:assert/strict';
import test from 'node:test';
import { chooseBeanVoiceAcknowledgement } from '../../scripts/elevenlabsVoiceAcknowledgement.mjs';

test('ElevenLabs POC acknowledgements skip simple conversational turns', () => {
    assert.equal(chooseBeanVoiceAcknowledgement('Can you hear me?'), null);
    assert.equal(chooseBeanVoiceAcknowledgement('Stop'), null);
    assert.equal(chooseBeanVoiceAcknowledgement('Thanks'), null);
});

test('ElevenLabs POC acknowledgements name the dashboard surface, not Bean', () => {
    const examples = [
        'What tasks do I have today?',
        'What is on my calendar tomorrow?',
        'Do I have any reminders?',
        'Find the note about insurance.',
        'Show my dashboard for tomorrow.',
    ];

    for (const phrase of examples) {
        const ack = chooseBeanVoiceAcknowledgement(phrase);
        assert.ok(ack, `${phrase} should get a latency bridge`);
        assert.doesNotMatch(ack, /bean/i);
    }

    assert.match(chooseBeanVoiceAcknowledgement('What tasks do I have today?'), /today’s tasks|tasks/i);
    assert.match(chooseBeanVoiceAcknowledgement('What is on my calendar tomorrow?'), /tomorrow’s calendar|calendar/i);
});

test('ElevenLabs POC acknowledgements use context for short follow ups', () => {
    const transcript = [
        { role: 'user', content: 'What tasks do I have today?' },
        { role: 'agent', content: 'You have two tasks today.' },
        { role: 'user', content: 'What about tomorrow?' },
    ];

    assert.equal(chooseBeanVoiceAcknowledgement('What about tomorrow?', transcript), 'Checking tomorrow’s tasks.');
});

test('ElevenLabs POC acknowledgements use action wording for mutations', () => {
    assert.match(chooseBeanVoiceAcknowledgement('Create a task called test voice task.'), /task/i);
    assert.equal(chooseBeanVoiceAcknowledgement('Mark it complete.'), 'Okay, marking that complete.');
    assert.equal(chooseBeanVoiceAcknowledgement('Delete that reminder.'), 'Okay, I’ll check that first.');
});
