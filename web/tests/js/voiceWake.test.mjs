import assert from 'node:assert/strict';

import { commandAfterWakePhrase } from '../../resources/js/voiceWake.js';

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
