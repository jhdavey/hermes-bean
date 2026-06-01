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
    return text.slice(match.index + match[0].length).replace(/\s+/g, ' ').trim();
}

export function normalizedVoiceCommand(transcript) {
    return String(transcript || '')
        .toLowerCase()
        .replace(/[^a-z0-9\s']/g, ' ')
        .replace(/\s+/g, ' ')
        .replace(/^(hey\s+bean|heybean|bean)\s+/, '')
        .replace(/\s+bean$/, '')
        .trim();
}

export function voiceCancelRequested(transcript) {
    const command = normalizedVoiceCommand(transcript);
    if (!command) return false;
    if (/^(?:stop|stop it|stop talking|be quiet|quiet|cancel|cancel that|cancel this|cancel response|cancel request|never\s*mind|nevermind|forget it|that's all|that is all)$/.test(command)) {
        return true;
    }
    return /\b(?:stop talking|be quiet|never\s*mind|nevermind|forget it)\b/.test(command);
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
    if (/\b(?:add|create|put|move|reschedule|schedule|update|change|delete|remove|cancel|complete|finish|mark|remind|remember)\b/.test(command)) {
        return true;
    }
    if (/\b(?:plan|organize|prioritize)\b/.test(command)
        && /\b(?:day|today|tomorrow|week|schedule|work|tasks|calendar|morning|afternoon|evening)\b/.test(command)) {
        return true;
    }
    return /\b(?:what do i have|what have i got|do i have anything|anything on|what'?s next|whats next|what is next|next up)\b/.test(command);
}

export function voiceCommandWantsDetailedChat(transcript) {
    const command = normalizedVoiceCommand(transcript);
    if (!command || voiceCommandNeedsAgentWork(command)) return false;
    return /\b(?:recipe|workout|exercise|routine|training|stretch|stretches|meal plan|instructions|step by step|steps)\b/.test(command)
        || (/\b(?:give|make|build|write|create)\b/.test(command)
            && /\b(?:plan|guide|list|schedule)\b/.test(command)
            && !/\b(?:calendar|event|task|reminder)\b/.test(command));
}
