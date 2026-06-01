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
    if (voiceCommandLooksLikeAppLookup(command)) {
        return pickAcknowledgement(command, [
            'Let me check that real quick.',
            "I'll take a quick look.",
            "Ok, I'll check that now.",
        ]);
    }
    if (voiceCommandLooksLikeQuestion(command) && !voiceCommandLooksLikeAction(command)) {
        return pickAcknowledgement(command, [
            'Let me think about that for a second.',
            "Ok, I'll take a look.",
            "Give me a second to think that through.",
        ]);
    }
    if (voiceCommandLooksLikeAction(command)) {
        return acknowledgementForAction(command);
    }
    return pickAcknowledgement(command, [
        "Got it, I'll take a look.",
        "Ok, I'll help with that.",
        "Sure, I'll check on that.",
    ]);
}

function acknowledgementForAction(command) {
    if (/\b(?:remind|reminder|reminders)\b/.test(command)) {
        if (/\b(?:delete|remove|cancel)\b/.test(command)) {
            return pickAcknowledgement(command, [
                "Ok, I'll remove that reminder.",
                "Got it, I'll take care of that reminder.",
                "No problem, I'll update your reminders.",
            ]);
        }
        return pickAcknowledgement(command, [
            "Ok, no problem. I'll remind you.",
            "Got it, I'll add that reminder.",
            "Sure, I'll set that reminder for you.",
        ]);
    }
    if (/\b(?:calendar|event|events|schedule|appointment|meeting|meetings)\b/.test(command)) {
        if (/\b(?:move|reschedule|change|update)\b/.test(command)) {
            return pickAcknowledgement(command, [
                "Got it, I'll update that on your calendar.",
                "Ok, I'll move that on your calendar.",
                "No problem, I'll make that calendar change.",
            ]);
        }
        if (/\b(?:delete|remove|cancel)\b/.test(command)) {
            return pickAcknowledgement(command, [
                "Ok, I'll remove that from your calendar.",
                "Got it, I'll take care of that calendar change.",
                "No problem, I'll update your calendar.",
            ]);
        }
        return pickAcknowledgement(command, [
            "Got it, I'll add that to your calendar.",
            "Ok, I'll put that on your calendar.",
            "Sure, I'll create that calendar event.",
        ]);
    }
    if (/\b(?:task|tasks|todo|to do)\b/.test(command)) {
        if (/\b(?:complete|finish|done)\b/.test(command)) {
            return pickAcknowledgement(command, [
                "Got it, I'll mark that task complete.",
                "Ok, I'll update that task.",
                "No problem, I'll take care of that task.",
            ]);
        }
        return pickAcknowledgement(command, [
            "Got it, I'll update that task.",
            "Ok, I'll add that to your tasks.",
            "No problem, I'll take care of that task.",
        ]);
    }
    if (/\b(?:remember|memory|preference|preferences)\b/.test(command)) {
        return pickAcknowledgement(command, [
            "Got it, I'll remember that.",
            "Ok, I'll save that for later.",
            "Sure, I'll keep that in mind.",
        ]);
    }
    if (/\b(?:plan|organize|prioritize)\b/.test(command)) {
        return pickAcknowledgement(command, [
            "Ok, I'll work on that plan.",
            "Got it, I'll map that out.",
            "Sure, I'll help organize that.",
        ]);
    }
    return pickAcknowledgement(command, [
        "Ok, no problem. I'll take care of that.",
        "Got it, I'll get that started.",
        "Sure, I'll work on that now.",
    ]);
}

function voiceCommandLooksLikeAppLookup(command) {
    return /\b(?:what|when|where|who|how many|show|tell me|do i have|what do i have|what's|whats)\b/.test(command)
        && /\b(?:calendar|event|events|schedule|task|tasks|reminder|reminders|today|tomorrow|this week|agenda)\b/.test(command);
}

function voiceCommandLooksLikeQuestion(command) {
    return /^(?:what|when|where|who|why|how|should i|do i|am i|is|are|will|would it|could it)\b/.test(command)
        || /\b(?:can you tell me|could you tell me|would you tell me|tell me|tell me about|explain|look up|find out)\b/.test(command);
}

function voiceCommandLooksLikeAction(command) {
    if (/\b(?:best way to|how to|how should|should i|what is|what's|whats|why)\b/.test(command)) {
        return /^(?:can|could|would)\s+you\s+(?:please\s+)?(?:add|create|move|reschedule|schedule|update|change|delete|cancel|complete|finish|remind|remember|plan|organize|prioritize)\b/.test(command);
    }
    return /\b(?:add|create|put|make|move|reschedule|schedule|update|change|delete|remove|cancel|complete|finish|mark|remind|remember|plan|organize|prioritize)\b/.test(command);
}

function pickAcknowledgement(command, options) {
    const index = Math.abs(hashText(command)) % options.length;
    return options[index];
}

function hashText(text) {
    let hash = 0;
    for (const character of text) {
        hash = ((hash << 5) - hash) + character.charCodeAt(0);
        hash |= 0;
    }
    return hash;
}
