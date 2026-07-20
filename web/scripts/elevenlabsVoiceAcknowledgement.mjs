const HASH_SEED = 5381;

function normalize(text) {
    return String(text || '')
        .toLowerCase()
        .replace(/[’']/g, '')
        .replace(/[^a-z0-9\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

export function canonicalizeBeanVoiceTranscript(text) {
    let normalized = normalize(text)
        .replace(/\bhey\s+bean\b/g, ' ')
        .replace(/\bheybean\b/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

    const words = normalized.split(' ').filter(Boolean);
    if (words.length > 1 && words.length % 2 === 0) {
        const midpoint = words.length / 2;
        const first = words.slice(0, midpoint).join(' ');
        const second = words.slice(midpoint).join(' ');
        if (first === second) normalized = first;
    }

    normalized = normalized.replace(/\s+/g, ' ').trim();

    if (normalized.includes('can you hear me')) return 'can you hear me';
    if (normalized === 'do you hear me') return 'can you hear me';
    if (/^(ok|okay|yes|yeah|yep|no|nope|thanks|thank you|stop|dismiss|cancel)$/.test(normalized)) return normalized;

    return normalized;
}

export function isIgnorableVoiceTranscript(text) {
    return canonicalizeBeanVoiceTranscript(text) === '';
}

export function isSimplePostHealthEcho(text) {
    const canonical = canonicalizeBeanVoiceTranscript(text);
    return /^(ok|okay|yes|yeah|yep|no|nope|thanks|thank you)$/.test(canonical);
}

export function isDuplicateVoiceTranscript(previous, next) {
    if (!previous || !next) return false;
    return canonicalizeBeanVoiceTranscript(previous) === canonicalizeBeanVoiceTranscript(next);
}

export function getLocalFastVoiceResponse(message) {
    const canonical = canonicalizeBeanVoiceTranscript(message);
    if (canonical === 'can you hear me') return 'Yes — I can hear you.';
    if (canonical === 'stop' || canonical === 'dismiss' || canonical === 'cancel') return 'Okay.';
    return null;
}

function includesAny(text, words) {
    return words.some((word) => text.includes(word));
}

function stableIndex(text, length) {
    if (length <= 1) return 0;
    let hash = HASH_SEED;
    for (const char of String(text || '')) {
        hash = ((hash << 5) + hash + char.charCodeAt(0)) >>> 0;
    }
    return hash % length;
}

function priorConversationText(transcript) {
    return [...(transcript || [])]
        .slice(-8)
        .map((message) => message?.content || '')
        .join(' ');
}

function inferDomain(message, transcript = []) {
    const current = normalize(message);
    const context = normalize(`${message} ${priorConversationText(transcript)}`);

    if (includesAny(current, ['task', 'tasks', 'todo', 'to do', 'overdue', 'complete', 'completed', 'done'])) return 'tasks';
    if (includesAny(current, ['calendar', 'schedule', 'event', 'events', 'meeting', 'meetings', 'appointment', 'appointments'])) return 'calendar';
    if (includesAny(current, ['reminder', 'reminders', 'remind me'])) return 'reminders';
    if (includesAny(current, ['note', 'notes', 'folder', 'folders'])) return 'notes';
    if (includesAny(current, ['dashboard', 'workspace', 'household'])) return 'dashboard';

    const followUpOnly = /^(what about|how about|and|then|what is|what are|tomorrow|today|next week|next month|that|those|it|them)\b/.test(current);
    if (followUpOnly) {
        if (includesAny(context, ['task', 'tasks', 'todo', 'to do', 'overdue', 'complete', 'completed'])) return 'tasks';
        if (includesAny(context, ['calendar', 'schedule', 'event', 'events', 'meeting', 'meetings', 'appointment'])) return 'calendar';
        if (includesAny(context, ['reminder', 'reminders', 'remind me'])) return 'reminders';
        if (includesAny(context, ['note', 'notes', 'folder', 'folders'])) return 'notes';
    }

    if (includesAny(current, ['today', 'tomorrow', 'this week', 'next week'])) return 'dashboard';

    return 'general';
}

function inferAction(message) {
    const current = normalize(message);
    if (/\b(add|create|make|new)\b/.test(current)) return 'create';
    if (/\b(mark|complete|finish|done)\b/.test(current)) return 'complete';
    if (/\b(move|reschedule|change|update|edit|rename)\b/.test(current)) return 'update';
    if (/\b(delete|remove|clear)\b/.test(current)) return 'delete';
    return 'query';
}

function isSimpleConversation(message) {
    const current = normalize(message);
    if (!current) return true;
    return [
        /^can you hear me\??$/,
        /^do you hear me\??$/,
        /^hello\b/,
        /^hi\b/,
        /^hey\b/,
        /^thanks?\b/,
        /^thank you\b/,
        /^stop\b/,
        /^dismiss\b/,
        /^cancel\b/,
        /^ok\b/,
        /^okay\b/,
        /^yes\b/,
        /^no\b/,
    ].some((pattern) => pattern.test(current));
}

export function chooseBeanVoiceAcknowledgement(message, transcript = []) {
    if (isSimpleConversation(message)) return null;

    const domain = inferDomain(message, transcript);
    const action = inferAction(message);
    const current = normalize(message);
    const tomorrow = current.includes('tomorrow');
    const today = current.includes('today');

    if (action === 'create') {
        const lines = {
            tasks: ['Okay, adding that task.', 'Got it — I’ll add that task.'],
            calendar: ['Okay, adding that to your calendar.', 'Got it — checking your calendar details.'],
            reminders: ['Okay, setting that reminder.', 'Got it — I’ll add that reminder.'],
            notes: ['Okay, adding that note.', 'Got it — I’ll save that note.'],
            dashboard: ['Okay, I’ll do that.', 'Got it — working on it.'],
            general: ['Okay, I’ll do that.', 'Got it.'],
        };
        return lines[domain]?.[stableIndex(message, lines[domain].length)] || lines.general[0];
    }

    if (action === 'complete') return 'Okay, marking that complete.';
    if (action === 'update') return domain === 'calendar' ? 'Okay, checking your calendar.' : 'Okay, I’ll update that.';
    if (action === 'delete') return 'Okay, I’ll check that first.';

    if (domain === 'tasks') {
        if (tomorrow) return 'Checking tomorrow’s tasks.';
        if (today) return 'Checking today’s tasks.';
        return ['Checking your tasks.', 'Let me check your tasks.'][stableIndex(message, 2)];
    }

    if (domain === 'calendar') {
        if (tomorrow) return 'Checking tomorrow’s calendar.';
        if (today) return 'Checking today’s calendar.';
        return ['Checking your calendar.', 'Let me check your calendar.'][stableIndex(message, 2)];
    }

    if (domain === 'reminders') return ['Checking your reminders.', 'Let me check your reminders.'][stableIndex(message, 2)];
    if (domain === 'notes') return ['Checking your notes.', 'Let me check your notes.'][stableIndex(message, 2)];
    if (domain === 'dashboard') return ['Checking your dashboard.', 'Let me check your dashboard.'][stableIndex(message, 2)];

    return ['One sec.', 'Let me check.'][stableIndex(message, 2)];
}
