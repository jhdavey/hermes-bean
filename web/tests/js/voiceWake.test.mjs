import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import {
    commandAfterWakePhrase,
    realtimeSpokenAnswerAllowsBackgroundQueue,
    voiceCommandNeedsAgentWork,
    voiceCommandRequiresBackgroundWork,
    voiceCommandWantsDetailedChat,
    voiceCancelRequested,
} from '../../resources/js/voiceWake.js';

const contract = JSON.parse(readFileSync(new URL('../../../shared/voice_contract.json', import.meta.url), 'utf8'));

for (const item of contract.wake.accepted) {
    assert.equal(commandAfterWakePhrase(item.transcript), item.command, item.transcript);
}

for (const transcript of contract.wake.rejected) {
    assert.equal(commandAfterWakePhrase(transcript), null, transcript);
}

for (const item of contract.intent) {
    assert.equal(voiceCommandNeedsAgentWork(item.transcript), item.needsAgentWork, `${item.transcript} needsAgentWork`);
    assert.equal(voiceCommandRequiresBackgroundWork(item.transcript), item.requiresBackgroundWork, `${item.transcript} requiresBackgroundWork`);
    if (Object.prototype.hasOwnProperty.call(item, 'wantsDetailedChat')) {
        assert.equal(voiceCommandWantsDetailedChat(item.transcript), item.wantsDetailedChat, `${item.transcript} wantsDetailedChat`);
    }
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
assert.equal(voiceCommandNeedsAgentWork('can you create notes'), false);
assert.equal(voiceCommandNeedsAgentWork('could you create something'), false);
assert.equal(voiceCommandNeedsAgentWork('are you able to schedule events'), false);
assert.equal(voiceCommandNeedsAgentWork('can you create a note called groceries'), true);
assert.equal(voiceCommandNeedsAgentWork('could you schedule an event tomorrow at 9am'), true);
assert.equal(voiceCommandNeedsAgentWork('when am I supposed to take out the trash'), true);
assert.equal(voiceCommandNeedsAgentWork('which recycling bin do I put out'), true);
assert.equal(voiceCommandRequiresBackgroundWork('what is on my calendar today'), true);
assert.equal(voiceCommandRequiresBackgroundWork('what is on my to-do list today'), true);
assert.equal(voiceCommandRequiresBackgroundWork('what tasks do I have today'), true);
assert.equal(voiceCommandRequiresBackgroundWork('move my task from 7pm to 5pm'), true);
assert.equal(voiceCommandRequiresBackgroundWork('what is the weather in Orlando Florida right now'), true);
assert.equal(voiceCommandRequiresBackgroundWork('cheapest flights from MCO to Dublin tomorrow one way'), true);
assert.equal(voiceCommandRequiresBackgroundWork('when does my local store close today'), true);
assert.equal(voiceCommandRequiresBackgroundWork('plan my day'), true);
assert.equal(voiceCommandRequiresBackgroundWork('can you create notes'), false);
assert.equal(voiceCommandRequiresBackgroundWork('could you create something'), false);
assert.equal(voiceCommandRequiresBackgroundWork('can you create a note called groceries'), true);
assert.equal(voiceCommandRequiresBackgroundWork('could you schedule an event tomorrow at 9am'), true);
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
        'what is the weather for this evening',
        "Right now in Orlando, it's 85 degrees with clear skies. I'll check this evening's forecast and get back to you.",
    ),
    true,
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
