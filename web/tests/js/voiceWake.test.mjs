import assert from 'node:assert/strict';

import {
    commandAfterWakePhrase,
    realtimeSpokenAnswerAllowsBackgroundQueue,
    voiceCommandNeedsAgentWork,
    voiceCommandRequiresBackgroundWork,
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
assert.equal(voiceCancelRequested('never mind'), true);
assert.equal(voiceCancelRequested('bean stop'), true);
assert.equal(voiceCancelRequested('stop bean'), true);
assert.equal(voiceCancelRequested('hey bean stop'), true);
assert.equal(voiceCancelRequested('bean cancel'), true);
assert.equal(voiceCancelRequested('stop listening'), true);
assert.equal(voiceCancelRequested('stop talking bean'), true);
assert.equal(voiceCancelRequested('cancel my meeting tomorrow'), false);
assert.equal(voiceCommandNeedsAgentWork('what should we have for dinner tonight'), false);
assert.equal(voiceCommandNeedsAgentWork('what is on my calendar today'), true);
assert.equal(voiceCommandNeedsAgentWork('move my task from 7pm to 5pm'), true);
assert.equal(voiceCommandNeedsAgentWork('plan my day'), true);
assert.equal(voiceCommandNeedsAgentWork('plan dinner'), false);
assert.equal(voiceCommandNeedsAgentWork('cheapest flights from MCO to Dublin tomorrow one way'), true);
assert.equal(voiceCommandNeedsAgentWork('what is the weather in Orlando Florida right now'), true);
assert.equal(voiceCommandNeedsAgentWork('when does my local store close today'), true);
assert.equal(voiceCommandNeedsAgentWork('is the place near me open tonight'), true);
assert.equal(voiceCommandNeedsAgentWork('can you give me a taco recipe'), false);
assert.equal(voiceCommandNeedsAgentWork('when am I supposed to take out the trash'), true);
assert.equal(voiceCommandNeedsAgentWork('which recycling bin do I put out'), true);
assert.equal(voiceCommandRequiresBackgroundWork('what is on my calendar today'), false);
assert.equal(voiceCommandRequiresBackgroundWork('what is on my to-do list today'), false);
assert.equal(voiceCommandRequiresBackgroundWork('what tasks do I have today'), false);
assert.equal(voiceCommandRequiresBackgroundWork('move my task from 7pm to 5pm'), true);
assert.equal(voiceCommandRequiresBackgroundWork('what is the weather in Orlando Florida right now'), true);
assert.equal(voiceCommandRequiresBackgroundWork('cheapest flights from MCO to Dublin tomorrow one way'), true);
assert.equal(voiceCommandRequiresBackgroundWork('when does my local store close today'), true);
assert.equal(voiceCommandRequiresBackgroundWork('plan my day'), true);
assert.equal(voiceCommandRequiresBackgroundWork('when am I supposed to take out the trash'), false);
assert.equal(voiceCommandWantsDetailedChat('give me a 30 minute full body workout but I do not have equipment'), true);
assert.equal(voiceCommandWantsDetailedChat('can you give me a taco recipe'), true);
assert.equal(voiceCommandWantsDetailedChat('what should we have for dinner tonight'), false);
assert.equal(voiceCommandWantsDetailedChat('move my task from 7pm to 5pm'), false);

assert.equal(
    realtimeSpokenAnswerAllowsBackgroundQueue(
        'what is on my todo list today',
        'You have three tasks today: pack lunches, call the vet, and review the budget.',
    ),
    false,
);
assert.equal(
    realtimeSpokenAnswerAllowsBackgroundQueue(
        'what is on my todo list today',
        'For today, you’ve got pack lunches, call the vet, and review the budget on your list.',
    ),
    false,
);
assert.equal(
    realtimeSpokenAnswerAllowsBackgroundQueue(
        'what tasks do I have today',
        "You've got three tasks today.",
    ),
    false,
);
assert.equal(
    realtimeSpokenAnswerAllowsBackgroundQueue(
        'what is on my calendar today',
        'You have dentist at 2 PM and dinner at 6 PM today.',
    ),
    false,
);
assert.equal(
    realtimeSpokenAnswerAllowsBackgroundQueue(
        'what is the weather like',
        "It's 88 degrees and partly cloudy right now.",
    ),
    false,
);
assert.equal(
    realtimeSpokenAnswerAllowsBackgroundQueue(
        "what's the weather like",
        "Right now in Orlando, it's 85 degrees with clear skies. I'm still checking the weather.",
    ),
    false,
);
assert.equal(
    realtimeSpokenAnswerAllowsBackgroundQueue(
        "what's the weather like",
        "The weather is clear in Orlando right now. Let me keep checking.",
    ),
    false,
);
assert.equal(
    realtimeSpokenAnswerAllowsBackgroundQueue(
        "and what's the weather like in Orlando right now",
        "I don't have the current weather for Orlando right now. Let me check that for you.",
    ),
    true,
);
assert.equal(
    realtimeSpokenAnswerAllowsBackgroundQueue(
        "and what's the weather like in Orlando right now",
        'I do not have the current weather for Orlando right now. Let me get that.',
    ),
    true,
);
assert.equal(
    realtimeSpokenAnswerAllowsBackgroundQueue(
        'move my task from 7pm to 5pm',
        "Sure, I'll update that task now.",
    ),
    true,
);
assert.equal(
    realtimeSpokenAnswerAllowsBackgroundQueue(
        'cheapest flights from MCO to Dublin tomorrow',
        "I'll check the latest flight options now.",
    ),
    true,
);
