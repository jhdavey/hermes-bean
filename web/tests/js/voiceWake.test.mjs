import assert from 'node:assert/strict';

import {
    commandAfterWakePhrase,
    voiceCommandNeedsAgentWork,
    voiceCommandWantsDetailedChat,
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

assert.equal(voiceCancelRequested('nevermind'), true);
assert.equal(voiceCancelRequested('stop talking bean'), true);
assert.equal(voiceCancelRequested('cancel my meeting tomorrow'), false);
assert.equal(voiceCommandNeedsAgentWork('what should we have for dinner tonight'), false);
assert.equal(voiceCommandNeedsAgentWork('what is on my calendar today'), true);
assert.equal(voiceCommandNeedsAgentWork('move my task from 7pm to 5pm'), true);
assert.equal(voiceCommandNeedsAgentWork('plan my day'), true);
assert.equal(voiceCommandNeedsAgentWork('plan dinner'), false);
assert.equal(voiceCommandNeedsAgentWork('cheapest flights from MCO to Dublin tomorrow one way'), true);
assert.equal(voiceCommandNeedsAgentWork('can you give me a taco recipe'), false);
assert.equal(voiceCommandWantsDetailedChat('give me a 30 minute full body workout but I do not have equipment'), true);
assert.equal(voiceCommandWantsDetailedChat('can you give me a taco recipe'), true);
assert.equal(voiceCommandWantsDetailedChat('what should we have for dinner tonight'), false);
assert.equal(voiceCommandWantsDetailedChat('move my task from 7pm to 5pm'), false);
