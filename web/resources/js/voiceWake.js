const wakeStarter = '(?:hey|hay)';
const beanVariant =
    '(?:bean|beans|been|ben|beam|beem|bien)';
const compactBeanVariant = 'b(?:ean|eans|een|en|eam|eem|ien)';

const wakePhrasePattern = new RegExp(
    [
        `^\\s*${wakeStarter}\\s*,?\\s*${beanVariant}\\b[\\s,.:;!?-]*`,
        `^\\s*${wakeStarter}\\s*${compactBeanVariant}\\b[\\s,.:;!?-]*`,
        `^\\s*${wakeStarter}\\s*,?\\s*(?:b|bee)\\b[\\s,.:;!?-]*`,
    ].join('|'),
    'i',
);

export function commandAfterWakePhrase(transcript) {
    const text = String(transcript || '').replace(/\s+/g, ' ').trim();
    if (!text) return null;
    const match = text.match(wakePhrasePattern);
    if (!match) return null;
    return text.slice(match.index + match[0].length).replace(/\s+/g, ' ').trim();
}

export function normalizedVoiceCommand(transcript) {
    return String(transcript || '')
        .toLowerCase()
        .replace(/[^a-z0-9\s']/g, ' ')
        .replace(/\s+/g, ' ')
        .replace(/^(?:hey|hay)\s+(?:bean|beans|been|ben|beam|beem|bien)\s+/, '')
        .replace(/^(?:hey|hay)b(?:ean|eans|een|en|eam|eem|ien)\s+/, '')
        .replace(/^bean\s+/, '')
        .replace(/\s+bean$/, '')
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

export function voiceCommandNeedsAgentWork(transcript) {
    const command = normalizedVoiceCommand(transcript);
    if (!command) return false;
    if (/\b(?:calendar|calendars|event|events|task|tasks|todo|to do|reminder|reminders|agenda|approval|approvals|workspace|workspaces|google calendar)\b/.test(command)) {
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
    if (/\b(?:add|create|put|move|reschedule|schedule|update|change|delete|remove|cancel|complete|finish|mark|remind|remember)\b/.test(command)) {
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

    if (/\b(?:flight|flights|airfare|airfares|ticket|tickets|hotel|hotels|rental car|rentals|reservation|reservations|booking|bookings|price|prices|cheapest|available|availability|weather|forecast|news|traffic|stock|stocks|sports|score|scores)\b/.test(command)) {
        return true;
    }
    if (/\b(?:today|tonight|tomorrow|current|currently|latest|now|right now|near me|nearby|local)\b/.test(command)
        && /\b(?:open|opens|closed|closes|close|closing|hours|hour|available|availability|price|prices|cost|costs|status|delay|delays)\b/.test(command)) {
        return true;
    }
    if (/\b(?:add|create|put|move|reschedule|schedule|update|change|delete|remove|cancel|complete|finish|mark|remind|remember)\b/.test(command)) {
        return true;
    }
    return /\b(?:plan|organize|prioritize)\b/.test(command)
        && /\b(?:day|today|tomorrow|week|schedule|work|tasks|calendar|morning|afternoon|evening)\b/.test(command);
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
