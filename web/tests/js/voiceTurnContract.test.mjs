import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import {
    voiceTextIsBackgroundAcknowledgement,
    voiceTurnNeedsCompletionWait,
} from '../../resources/js/heybean/voiceTurnContract.js';

const contract = JSON.parse(readFileSync(new URL('../../../shared/voice_contract.json', import.meta.url), 'utf8'));
const webAppSource = readFileSync(new URL('../../resources/js/heybean/webApp.js', import.meta.url), 'utf8');

assert.equal(webAppSource.includes('fetchKioskQuickReply'), false);

for (const text of contract.terminalQuickReplies.forbiddenAckOnly) {
    assert.equal(voiceTextIsBackgroundAcknowledgement(text), true, text);
    assert.equal(
        voiceTurnNeedsCompletionWait({
            quickReplyText: text,
            assistantContent: '',
            resultStatus: 'completed',
        }),
        true,
        text,
    );
}

assert.equal(
    voiceTurnNeedsCompletionWait({
        quickReplyText: "I'll check that now.",
        assistantContent: 'You have dentist at 2 PM and dinner at 6 PM today.',
        resultStatus: 'completed',
    }),
    false,
);

assert.equal(
    voiceTurnNeedsCompletionWait({
        quickReplyText: "I'll check that now.",
        assistantContent: '',
        resultStatus: 'failed',
    }),
    false,
);

assert.equal(
    voiceTurnNeedsCompletionWait({
        quickReplyText: 'You have dentist at 2 PM and dinner at 6 PM today.',
        assistantContent: '',
        resultStatus: 'completed',
    }),
    false,
);
