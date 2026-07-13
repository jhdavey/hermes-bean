export function realtimeMicrophoneConstraints() {
    return {
        audio: {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true,
        },
    };
}

export async function acquireRealtimeMicrophone(getUserMedia, constraints, {
    retryDelaysMs = [250, 750, 1500],
    delay = (milliseconds) => new Promise((resolve) => globalThis.setTimeout(resolve, milliseconds)),
} = {}) {
    const retrySchedule = Array.isArray(retryDelaysMs) ? retryDelaysMs : [];
    for (let attempt = 0; ; attempt += 1) {
        try {
            return await getUserMedia(constraints);
        } catch (error) {
            const abortSignature = `${error?.name || ''} ${error?.code || ''} ${error?.message || ''}`;
            if (!/abort|(^|\s)20(\s|$)/i.test(abortSignature) || attempt >= retrySchedule.length) {
                throw error;
            }
            await delay(Math.max(0, Number(retrySchedule[attempt]) || 0));
        }
    }
}

export function realtimeUsageReportFromProviderEvent(payload = {}) {
    const type = String(payload?.type || '');
    const transcription = type === 'conversation.item.input_audio_transcription.completed';
    const speech = type === 'response.done';
    if (!transcription && !speech) return null;
    const usage = transcription ? payload?.usage : payload?.response?.usage;
    if (!usage || typeof usage !== 'object') return null;
    const totalTokens = Number(usage.total_tokens ?? 0);
    const inputTokens = Number(usage.input_tokens ?? 0);
    const outputTokens = Number(usage.output_tokens ?? 0);
    if (![totalTokens, inputTokens, outputTokens].some((value) => Number.isFinite(value) && value > 0)) return null;
    const sourceId = String(payload?.event_id
        || (transcription ? payload?.item_id || payload?.item?.id : payload?.response?.id)
        || '').trim();
    if (!sourceId) return null;

    return {
        providerEventId: `${transcription ? 'transcription' : 'speech'}:${sourceId}`,
        eventType: transcription ? 'transcription' : 'speech',
        usage,
    };
}

export function sanitizedLocalWakeFailure(error, stage = 'local_wake') {
    const chain = [];
    const seen = new Set();
    let current = error;
    while (current && typeof current === 'object' && chain.length < 4 && !seen.has(current)) {
        seen.add(current);
        const code = String(current.code || '').replace(/[^a-z0-9_.-]+/gi, '_').slice(0, 80);
        const message = String(current.message || '')
            .replace(/\bBearer\s+\S+/gi, 'Bearer [redacted]')
            .replace(/\bsk-[A-Za-z0-9_-]+\b/g, '[redacted]')
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, 240);
        if (code || message) chain.push({ code: code || null, message: message || null });
        current = current.cause;
    }

    return {
        stage: stage === 'startup' ? 'startup' : 'local_wake',
        code: chain[0]?.code || (stage === 'startup' ? 'voice_startup_failure' : 'local_wake_failure'),
        message: chain[0]?.message || (stage === 'startup'
            ? 'Browser voice startup failed.'
            : 'Private wake detection failed.'),
        cause_chain: chain,
    };
}

export function isStrictRealtimeWakePhrase(text) {
    return /^\s*hey[\s,.-]*bean\b/i.test(String(text || ''));
}

export function shouldDisplayRealtimeTranscriptDraft(text) {
    const draft = String(text || '').trim();
    if (!draft) return false;
    return !/^h(?:e(?:y(?:[\s,.-]*b(?:e(?:a(?:n)?)?)?)?)?)?[\s.!?,-]*$/i.test(draft);
}

function realtimeLocalDateKey(date) {
    const value = date instanceof Date ? date : new Date(date);
    if (Number.isNaN(value.getTime())) return '';
    return [
        value.getFullYear(),
        String(value.getMonth() + 1).padStart(2, '0'),
        String(value.getDate()).padStart(2, '0'),
    ].join('-');
}

function realtimeDateLabel(dateKey, now) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(dateKey || ''));
    if (!match) return dateKey;
    const [, yearText, monthText, dayText] = match;
    const today = realtimeLocalDateKey(now);
    const tomorrowDate = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1);
    if (dateKey === today) return 'today';
    if (dateKey === realtimeLocalDateKey(tomorrowDate)) return 'tomorrow';
    const year = Number(yearText);
    const month = new Intl.DateTimeFormat('en-US', { month: 'long' })
        .format(new Date(year, Number(monthText) - 1, 1));
    const day = Number(dayText);
    const modulo100 = day % 100;
    const suffix = modulo100 >= 11 && modulo100 <= 13
        ? 'th'
        : ({ 1: 'st', 2: 'nd', 3: 'rd' }[day % 10] || 'th');
    return `${month} ${day}${suffix}${year !== now.getFullYear() ? `, ${year}` : ''}`;
}

function realtimeTimeLabel(hoursText, minutesText) {
    const hours = Number(hoursText);
    const minutes = Number(minutesText);
    if (!Number.isInteger(hours) || hours < 0 || hours > 23 || !Number.isInteger(minutes) || minutes < 0 || minutes > 59) {
        return `${hoursText}:${minutesText}`;
    }
    const period = hours < 12 ? 'a.m.' : 'p.m.';
    const twelveHour = hours % 12 || 12;
    return minutes === 0
        ? `${twelveHour} ${period}`
        : `${twelveHour}:${String(minutes).padStart(2, '0')} ${period}`;
}

export function naturalizeRealtimeSpeechText(text, { now = new Date() } = {}) {
    const reference = now instanceof Date && !Number.isNaN(now.getTime()) ? now : new Date();
    let output = String(text || '');
    output = output.replace(
        /\b(\d{4}-\d{2}-\d{2})[T ]([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?\b/g,
        (_, date, hours, minutes) => `${realtimeDateLabel(date, reference)} at ${realtimeTimeLabel(hours, minutes)}`,
    );
    output = output.replace(
        /\b(\d{4}-\d{2}-\d{2})\b/g,
        (_, date) => realtimeDateLabel(date, reference),
    );
    output = output.replace(
        /\b([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?\b(?!\s*(?:a\.?m\.?|p\.?m\.?))/gi,
        (_, hours, minutes) => realtimeTimeLabel(hours, minutes),
    );
    return output
        .replace(
            /\b(?:at|on)\s+((?:today|tomorrow|(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2}(?:st|nd|rd|th)(?:,\s+\d{4})?))\s+at\b/g,
            '$1 at',
        )
        .replace(/\s*\([A-Za-z_]+\/[A-Za-z0-9_+\-/]+\)/g, '')
        .replace(/\b[A-Za-z_]+\/[A-Za-z0-9_+\-/]+\b/g, 'local time')
        .replace(/([ap]\.m\.)\./gi, '$1')
        .replace(/\s{2,}/g, ' ')
        .trim();
}

export function isLikelyNonEnglishRealtimeTranscript(text) {
    const letters = String(text || '').match(/\p{L}/gu) || [];
    if (!letters.length) return false;
    const latinLetters = letters.filter((letter) => /\p{Script=Latin}/u.test(letter));
    return latinLetters.length / letters.length < 0.65;
}

export function stripRealtimeLocalWakePrefix(text) {
    return String(text || '')
        .replace(/^\s*(?:(?:hey|they|he)[\s,.-]+(?:bean|ben|bin|bing|being|beane|beam)|habe(?:en|ing))\b[\s,.:;!?-]*/i, '')
        .trim();
}

export function isVoiceFillerOnly(text) {
    const normalized = String(text || '')
        .toLowerCase()
        .replace(/[^a-z\s]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    return /^(?:um+|uh+|erm+|hmm+|mm+|ah+)(?: (?:yeah|yes|okay|ok|right))?$/.test(normalized);
}

export class RealtimeInputTranscriptBuffer {
    constructor() {
        this.parts = new Map();
    }

    append({ itemId = '', contentIndex = 0, delta = '' } = {}) {
        const key = this.#key(itemId, contentIndex);
        if (!key) return '';
        const next = `${this.parts.get(key) || ''}${String(delta || '')}`;
        this.parts.set(key, next);
        return next;
    }

    complete({ itemId = '', contentIndex = 0, transcript = '' } = {}) {
        const key = this.#key(itemId, contentIndex);
        const buffered = key ? this.parts.get(key) || '' : '';
        if (key) this.parts.delete(key);
        return String(transcript || buffered).trim();
    }

    discard({ itemId = '', contentIndex = 0 } = {}) {
        const key = this.#key(itemId, contentIndex);
        if (key) this.parts.delete(key);
    }

    clear() {
        this.parts.clear();
    }

    #key(itemId, contentIndex) {
        const id = String(itemId || '').trim();
        if (!id) return '';
        const numericIndex = Number(contentIndex);
        const index = Number.isSafeInteger(numericIndex) && numericIndex >= 0 ? numericIndex : 0;
        return `${id}:${index}`;
    }
}

export function buildRealtimeResponseEvent(instructions, { clientResponseId = '' } = {}) {
    const response = {
        instructions: String(instructions || '').trim(),
        tool_choice: 'none',
    };
    if (clientResponseId) response.metadata = { heybean_response_id: String(clientResponseId) };
    return { type: 'response.create', response };
}

export function buildRealtimeTargetedResponseCancellationEvent(responseId) {
    const normalizedResponseId = String(responseId || '').trim();
    if (!normalizedResponseId) return null;
    return { type: 'response.cancel', response_id: normalizedResponseId };
}

export function buildRealtimeConversationItemDeleteEvent(itemId) {
    return {
        type: 'conversation.item.delete',
        item_id: String(itemId || '').trim(),
    };
}

export function isRealtimeDuplicateCallConflict(status, detail = '') {
    return Number(status) === 409
        && /live session already exists|provided call_id/i.test(String(detail || ''));
}

export function stageOptimisticUserTurn(messages, {
    content,
    clientRequestId,
    localId,
} = {}) {
    const optimisticMessage = {
        id: String(localId || `local-${Date.now()}`),
        role: 'user',
        content: String(content || ''),
        metadata: { client_request_id: String(clientRequestId || '').trim() },
    };
    return {
        messages: [...(Array.isArray(messages) ? messages : []), optimisticMessage],
        optimisticMessage,
    };
}
