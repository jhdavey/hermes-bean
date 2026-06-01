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

assert.match(voiceAcknowledgementForCommand("what's on my calendar for today"), /check|look/i);
assert.match(voiceAcknowledgementForCommand('move my task from 7pm to 5pm'), /task/i);
assert.match(voiceAcknowledgementForCommand('add dinner with Lauren to my calendar'), /calendar|event/i);
assert.match(voiceAcknowledgementForCommand('cancel my meeting tomorrow'), /calendar|meeting/i);
assert.match(voiceAcknowledgementForCommand('remind me to call Lauren at 5'), /remind|reminder/i);
assert.match(voiceAcknowledgementForCommand('remember that I prefer morning workouts'), /remember|save|mind/i);
assert.match(voiceAcknowledgementForCommand('what is the best way to plan my day'), /think|look/i);
assert.match(voiceAcknowledgementForCommand('how many tasks do I have next week'), /check|look/i);
assert.match(voiceAcknowledgementForCommand('can you plan my day'), /plan|map|organize/i);
assert.match(voiceAcknowledgementForCommand('tell me a joke'), /think|look/i);
assert.match(voiceAcknowledgementForCommand('start a conversation'), /help|look|check/i);
assert.equal(voiceCancelRequested('nevermind'), true);
assert.equal(voiceCancelRequested('stop talking bean'), true);
assert.equal(voiceCancelRequested('cancel my meeting tomorrow'), false);
