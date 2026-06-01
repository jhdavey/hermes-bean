import assert from 'node:assert/strict';

import {
    commandAfterWakePhrase,
    voiceAcknowledgementForCommand,
    voiceCancelRequested,
} from '../../resources/js/voiceWake.js';

const accepted = new Map([
    ['Hey Bean plan today', 'plan today'],
    ['hay been add a reminder', 'add a reminder'],
    ['hey beam move my focus block', 'move my focus block'],
    ['HeyBean start my day', 'start my day'],
    ['hi bean what is next', 'what is next'],
    ['okay bean reschedule school pickup', 'reschedule school pickup'],
    ['hey bing open reminders', 'open reminders'],
    ['hey bain open reminders', 'open reminders'],
    ['hey bane open reminders', 'open reminders'],
    ['hey dean add groceries', 'add groceries'],
    ['hey B plan dinner', 'plan dinner'],
    ['a bean start listening', 'start listening'],
    ['noise first hey bean then plan', 'then plan'],
    ['hey bean', ''],
]);

for (const [transcript, expected] of accepted) {
    assert.equal(commandAfterWakePhrase(transcript), expected, transcript);
}

const rejected = [
    '',
    'green beans are on the grocery list',
    'I have been planning today',
    'hello there',
    'maybe add groceries',
    'a green bean recipe',
];

for (const transcript of rejected) {
    assert.equal(commandAfterWakePhrase(transcript), null, transcript);
}

assert.equal(voiceAcknowledgementForCommand("what's on my calendar for today"), 'Let me check that real quick.');
assert.equal(voiceAcknowledgementForCommand('move my task from 7pm to 5pm'), "I'm on it.");
assert.equal(voiceAcknowledgementForCommand('cancel my meeting tomorrow'), "I'm on it.");
assert.equal(voiceCancelRequested('nevermind'), true);
assert.equal(voiceCancelRequested('stop talking bean'), true);
assert.equal(voiceCancelRequested('cancel my meeting tomorrow'), false);
