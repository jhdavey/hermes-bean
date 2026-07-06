const wakeStarter = '(?:hey|hay|hi|hello|okay|ok|kay)';
const beanVariant =
    '(?:bean|beans|been|ben|beam|beem|bein|being|bin|bing|bien|bain|bane|dean|deen)';
const compactBeanVariant = 'b(?:ean|eans|een|en|eam|eem|ein|eing|in|ing|ien|ain|ane)';

const wakePhrasePattern = new RegExp(
    [
        `(?:^|\\s)${wakeStarter}\\s*,?\\s*${beanVariant}\\b[\\s,.:;!?-]*`,
        `(?:^|\\s)${wakeStarter}\\s*${compactBeanVariant}\\b[\\s,.:;!?-]*`,
        `(?:^|\\s)${wakeStarter}\\s*,?\\s*(?:b|bee)\\b[\\s,.:;!?-]*`,
        '^\\s*a\\s+bean\\b[\\s,.:;!?-]*',
    ].join('|'),
    'i',
);

export function commandAfterWakePhrase(transcript) {
    const text = String(transcript || '').replace(/\s+/g, ' ').trim();
    if (!text) return null;
    const match = text.match(wakePhrasePattern);
    if (!match) return null;
    return stripLeadingVoiceFillers(text.slice(match.index + match[0].length)
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .trim());
}

export function normalizedVoiceCommand(transcript) {
    const normalized = String(transcript || '')
        .toLowerCase()
        .replace(/[^a-z0-9\s']/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

    return stripLeadingVoiceFillers(stripNormalizedWakePhrase(stripLeadingVoiceFillers(normalized)))
        .replace(/\s+bean$/, '')
        .trim();
}

function stripLeadingVoiceFillers(command) {
    if (!command) return command;
    if (/^(?:uh huh|mm hmm|mhm)\b/.test(command)) return command;
    return command
        .replace(/^(?:(?:uh|um|umm|erm|er|ah|hmm|hm|mm|mhm|well|so)\s+)+/, '')
        .trim();
}

function stripNormalizedWakePhrase(command) {
    if (!command) return command;
    return command
        .replace(new RegExp(
            `^(?:${wakeStarter}\\s+${beanVariant}|${wakeStarter}\\s*${compactBeanVariant}|${wakeStarter}\\s+(?:b|bee)|a\\s+bean|heybean|bean)\\s+`,
            'i',
        ), '')
        .trim();
}

export function voiceCancelRequested(transcript) {
    const command = normalizedVoiceCommand(transcript);
    if (!command) return false;
    if (/^(?:stop|stop it|stop talking|be quiet|quiet|cancel|cancel that|cancel this|cancel response|cancel request|never\s*mind|nevermind|forget it|that's all|that is all|stop listening|we'?re done|we are done)$/.test(command)) {
        return true;
    }
    return /\b(?:stop talking|be quiet|never\s*mind|nevermind|forget it|stop listening)\b/.test(command);
}

export function voiceCommandIsCapabilityQuestion(transcript) {
    const command = normalizedVoiceCommand(transcript);
    if (!command) return false;
    const asksCapability = /^(?:can|could|would)\s+you\s+(?:really\s+|actually\s+)?(?:add|create|make|put|schedule|write|save|delete|remove|cancel|update|change|move|reschedule|complete|finish|mark|remind|remember|plan|organize|prioritize)\b/.test(command)
        || /^(?:are you able to|do you know how to|is it possible (?:for you )?to|can bean|could bean|does bean know how to|does bean support)\s+(?:add|create|make|put|schedule|write|save|delete|remove|cancel|update|change|move|reschedule|complete|finish|mark|remind|remember|plan|organize|prioritize)\b/.test(command);
    return asksCapability && !voiceCommandLooksConcreteAction(command);
}

function voiceCommandLooksConcreteAction(command) {
    if (/\b(?:called|named|titled|labelled|labeled|that says|saying|with title|with the title)\b/.test(command)) return true;
    if (/\b(?:today|tonight|tomorrow|yesterday|this morning|this afternoon|this evening|next week|next month|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/.test(command)) return true;
    if (/\b(?:at|by|before|after|from|until)\s+\d{1,2}(?::\d{2})?\s*(?:am|pm)?\b/.test(command)) return true;
    if (/\b\d{1,2}[/-]\d{1,2}(?:[/-]\d{2,4})?\b|\b\d{4}-\d{2}-\d{2}\b/.test(command)) return true;
    return /\b(?:for|about|to)\s+(?:me|my|the|a|an)\s+\w+/.test(command)
        && !/\b(?:something|anything|things|stuff|items)\b/.test(command);
}

export function voiceCommandNeedsAgentWork(transcript) {
    const command = normalizedVoiceCommand(transcript);
    if (!command) return false;
    if (voiceCommandIsCapabilityQuestion(command)) return false;
    if (/\b(?:calendar|calendars|event|events|agenda|schedule|schedules|meeting|meetings|appointment|appointments|task|tasks|todo|to do|reminder|reminders|approval|approvals|workspace|workspaces|google calendar)\b/.test(command)) {
        return true;
    }
    if (/\b(?:flight|flights|airfare|airfares|ticket|tickets|hotel|hotels|rental car|rentals|reservation|reservations|booking|bookings|price|prices|cheapest|available|availability|weather|forecast|news|traffic|stock|stocks|sports|score|scores)\b/.test(command)) {
        return true;
    }
    if (/\b(?:today|tonight|tomorrow|current|currently|latest|now|right now|near me|nearby|local)\b/.test(command)
        && /\b(?:open|opens|closed|closes|close|closing|hours|hour|available|availability|price|prices|cost|costs|status|delay|delays)\b/.test(command)) {
        return true;
    }
    if (/\b(?:trash|garbage|recycling|recycle|pickup|pick up)\b/.test(command)
        && /\b(?:when|what|which|supposed|take out|put out|do i|should i)\b/.test(command)) {
        return true;
    }
    if (/\b(?:add|create|put|move|reschedule|schedule|update|change|delete|remove|cancel|complete|finish|mark|remind|remember|undo|revert|reverse)\b/.test(command)) {
        return true;
    }
    if (/\b(?:plan|organize|prioritize)\b/.test(command)
        && /\b(?:day|today|tomorrow|week|schedule|work|tasks|calendar|morning|afternoon|evening)\b/.test(command)) {
        return true;
    }
    return /\b(?:what do i have|what have i got|do i have anything|anything on|what'?s next|whats next|what is next|next up)\b/.test(command);
}

export function voiceCommandRequiresBackgroundWork(transcript) {
    const command = normalizedVoiceCommand(transcript);
    if (!command) return false;
    if (voiceCommandIsCapabilityQuestion(command)) return false;

    if (/\b(?:flight|flights|airfare|airfares|ticket|tickets|hotel|hotels|rental car|rentals|reservation|reservations|booking|bookings|price|prices|cheapest|available|availability|weather|forecast|news|traffic|stock|stocks|sports|score|scores)\b/.test(command)) {
        return true;
    }
    if (/\b(?:calendar|calendars|event|events|agenda|schedule|schedules|meeting|meetings|appointment|appointments|task|tasks|todo|to do|reminder|reminders|approval|approvals|workspace|workspaces|google calendar)\b/.test(command)) {
        return true;
    }
    if (/\b(?:today|tonight|tomorrow|current|currently|latest|now|right now|near me|nearby|local)\b/.test(command)
        && /\b(?:open|opens|closed|closes|close|closing|hours|hour|available|availability|price|prices|cost|costs|status|delay|delays)\b/.test(command)) {
        return true;
    }
    if (/\b(?:add|create|put|move|reschedule|schedule|update|change|delete|remove|cancel|complete|finish|mark|remind|remember|undo|revert|reverse)\b/.test(command)) {
        return true;
    }
    if (/\b(?:plan|organize|prioritize)\b/.test(command)
        && /\b(?:day|today|tomorrow|week|schedule|work|tasks|calendar|morning|afternoon|evening)\b/.test(command)) {
        return true;
    }
    return /\b(?:what do i have|what have i got|do i have anything|anything on|what'?s next|whats next|what is next|next up)\b/.test(command);
}

export function realtimeSpokenAnswerAllowsBackgroundQueue(userTranscript, assistantText) {
    const spoken = normalizedVoiceCommand(assistantText);
    if (!spoken) return true;
    if (spoken.length > 180) return false;
    if (/\b(?:i(?:'|’)?ll let you know|i will let you know|when it(?:'|’)?s done|when it is done|once it(?:'|’)?s done|once it is done|i(?:'|’)?ll confirm|i will confirm|i(?:'|’)?ll update you|i will update you)\b/.test(spoken)) {
        return true;
    }
    if (spokenContainsConcreteAnswer(spoken, assistantText)) return false;
    if (/\b(?:i don t have|i do not have|i can t see|i cannot see|i don t know|i do not know|let me check|let me get|let me look|i(?:'|’)?ll check|i will check|i(?:'|’)?ll get|i will get|i(?:'|’)?m going to check|i am going to check|i need to check|i can check|checking|pulling|gathering|looking|working|finding|one moment|give me|hang on|hold on)\b/.test(spoken)) {
        return true;
    }
    if (/\b(?:i(?:'|’)?ll|i will|i(?:'|’)?m going to|i am going to|let me|i(?:'|’)?m checking|i am checking)\b/.test(spoken)) {
        return true;
    }
    if (/\b(?:sure|absolutely|yeah|okay|ok|got it)\b/.test(spoken)
        && /\b(?:check|look|pull|gather|find|work|handle|start|do that|take care)\b/.test(spoken)) {
        return true;
    }
    const userWords = new Set(comparableVoiceWords(userTranscript));
    const spokenWords = comparableVoiceWords(spoken);
    const novelWords = spokenWords.filter((word) => !userWords.has(word));
    return novelWords.length <= 2 && spokenWords.length <= 10;
}

function spokenContainsConcreteAnswer(spoken, originalText) {
    const raw = String(originalText || '');
    if (/[;:]/.test(raw) || /\b\d+\b/.test(spoken) || /\d/.test(raw)) return true;
    return /\b(?:you have|you ve got|you've got|you got|you have got|you have \w+ tasks|you ve \w+ tasks|there are|there is|here are|here s|here's|heres|it is|it s|it's|its|looks like|today you|today there|for today|on your list|todo list|to do list|tasks today|due|scheduled|starts|ends|temperature|degrees|degree|percent|humidity|wind|mph|clear skies|partly cloudy|cloudy|sunny|rain|raining|storm|storming|forecast says|weather is)\b/.test(spoken);
}

export function voiceCommandWantsDetailedChat(transcript) {
    const command = normalizedVoiceCommand(transcript);
    if (!command || voiceCommandNeedsAgentWork(command)) return false;
    return /\b(?:recipe|workout|exercise|routine|training|stretch|stretches|meal plan|instructions|step by step|steps)\b/.test(command)
        || (/\b(?:give|make|build|write|create)\b/.test(command)
            && /\b(?:plan|guide|list|schedule)\b/.test(command)
            && !/\b(?:calendar|event|task|reminder)\b/.test(command));
}

function comparableVoiceWords(value) {
    const stopWords = new Set([
        'about', 'after', 'again', 'also', 'and', 'are', 'bean', 'been', 'being', 'can', 'could',
        'for', 'from', 'get', 'got', 'have', 'here', 'into', 'just', 'like', 'now', 'okay',
        'one', 'out', 'right', 'sure', 'that', 'the', 'then', 'there', 'this', 'with', 'you',
        'your', 'youre', 'ill', 'ive', 'its',
    ]);
    return normalizedVoiceCommand(value)
        .split(' ')
        .map((word) => word.replace(/'s$/, ''))
        .filter((word) => word.length > 2 && !stopWords.has(word));
}
