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

export function voiceAcknowledgementForCommand(transcript) {
    const command = normalizedVoiceCommand(transcript);
    if (!command || voiceCancelRequested(command)) return '';
    if (/\b(?:what|when|where|who|how many|show|tell me|do i have|what do i have|what's|whats)\b/.test(command)
        && /\b(?:calendar|event|events|schedule|task|tasks|reminder|reminders|today|tomorrow|this week|agenda)\b/.test(command)) {
        return 'Let me check that real quick.';
    }
    if (/\b(?:move|reschedule|schedule|create|add|update|change|delete|cancel|complete|finish|remind|remember|plan)\b/.test(command)) {
        return "I'm on it.";
    }
    return '';
}
