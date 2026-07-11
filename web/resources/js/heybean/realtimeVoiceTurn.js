export function realtimePauseAcknowledgement() {
    return 'Okay, I’ll pause here.';
}

export function realtimeMicrophoneConstraints() {
    return {
        audio: {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true,
        },
    };
}

export function isStrictRealtimeWakePhrase(text) {
    return /^\s*hey[\s,.-]*bean\b/i.test(String(text || ''));
}

export function isBareRealtimeWakePhrase(text) {
    return /^\s*hey[\s,.-]*bean[\s.!?,-]*$/i.test(String(text || ''));
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

export function realtimeLocalTemporalAnswer(text, { now = new Date() } = {}) {
    const normalized = String(text || '')
        .toLowerCase()
        .replace(/[^a-z0-9\s]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .replace(/^(?:(?:hey )?bean|okay|ok|so|please)\s+/, '')
        .replace(/\s+(?:please|thanks|thank you)$/, '');
    const reference = now instanceof Date && !Number.isNaN(now.getTime()) ? now : new Date();
    const timeQuestion = [
        /^(?:what(?: is| s)?|tell me|give me) (?:the (?:current )?|our |local |current )?time(?: (?:is it|right now|now))?$/,
        /^(?:can|could|would) you (?:please )?tell me (?:the (?:current )?|our |local |current )?time$/,
        /^do you know what time it is$/,
        /^(?:time|local time|current time)(?: now)?$/,
    ].some((pattern) => pattern.test(normalized));
    if (timeQuestion) {
        const hours = reference.getHours();
        const minutes = reference.getMinutes();
        let spokenTime = realtimeTimeLabel(hours, minutes);
        if (minutes === 0 && ![0, 12].includes(hours)) spokenTime = `${hours % 12} o’clock`;
        if (minutes === 0 && hours === 0) spokenTime = 'twelve a.m.';
        if (minutes === 0 && hours === 12) spokenTime = 'twelve p.m.';
        return `It’s ${spokenTime}${spokenTime.endsWith('.') ? '' : '.'}`;
    }
    if (/^(?:what year is it|what(?: is| s) the current year|(?:tell|give) me the (?:current )?year|(?:current )?year)$/.test(normalized)) {
        return `It’s ${reference.getFullYear()}.`;
    }
    const dateQuestion = [
        /^(?:what(?: is| s) )?(?:today s |the |the current |current )?(?:date|day)(?: is it| is today| today)?$/,
        /^what (?:date|day) is it$/,
        /^what day (?:are we|is today|is it|are we on)$/,
        /^what(?: is| s) today$/,
        /^(?:tell|give) me (?:today s |the |the current |current )?(?:date|day)$/,
        /^tell me what (?:date|day) it is$/,
        /^(?:can|could|would) you (?:please )?tell me (?:today s |the |the current |current )?(?:date|day)$/,
        /^do you know what (?:date|day) it is$/,
        /^(?:today s |current )?(?:date|day)$/,
    ].some((pattern) => pattern.test(normalized));
    if (dateQuestion) {
        const weekday = new Intl.DateTimeFormat('en-US', { weekday: 'long' }).format(reference);
        const month = new Intl.DateTimeFormat('en-US', { month: 'long' }).format(reference);
        const day = reference.getDate();
        const modulo100 = day % 100;
        const suffix = modulo100 >= 11 && modulo100 <= 13
            ? 'th'
            : ({ 1: 'st', 2: 'nd', 3: 'rd' }[day % 10] || 'th');
        return `Today is ${weekday}, ${month} ${day}${suffix}.`;
    }
    return '';
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
    output = output
        .replace(
            /\b(?:at|on)\s+((?:today|tomorrow|(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2}(?:st|nd|rd|th)(?:,\s+\d{4})?))\s+at\b/g,
            '$1 at',
        )
        .replace(/\s*\([A-Za-z_]+\/[A-Za-z0-9_+\-/]+\)/g, '')
        .replace(/\b[A-Za-z_]+\/[A-Za-z0-9_+\-/]+\b/g, 'local time')
        .replace(/([ap]\.m\.)\./gi, '$1')
        .replace(/\s{2,}/g, ' ')
        .trim();
    return output;
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

export function realtimeNeedsAppRuntime(command, { appConversationActive = false, backendSyncRequired = false } = {}) {
    if (appConversationActive || backendSyncRequired) return true;
    const normalized = String(command || '')
        .toLowerCase()
        .replace(/[^a-z0-9\s]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    const timelessDirectIntent = [
        /^(?:hi|hello|hey|good (?:morning|afternoon|evening))(?: bean)?$/,
        /^(?:how are you|how s it going|what s up)(?: bean)?$/,
        /^(?:(?:please )?(?:tell|give) me (?:a |another )?(?:short )?joke|make me laugh|say something funny)(?: please)?$/,
    ].some((pattern) => pattern.test(normalized));

    // Direct responses cannot use tools, so anything not explicitly proven safe
    // must fail closed to the app runtime. A keyword denylist misses paraphrases
    // such as "Will I need an umbrella?" and "What should I wear outside?".
    return !timelessDirectIntent;
}

export function isRealtimeVoiceStopCommand(text) {
    let normalized = String(text || '')
        .toLowerCase()
        .replace(/[^a-z0-9\s]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    if (!normalized || /\b(?:don t|do not|never) stop\b/.test(normalized)) return false;
    normalized = normalized
        .replace(/^hey (?:bean|ben|bin|bing|being|beane|beam)\s+/, '')
        .replace(/^(?:okay|ok)\s+/, '');
    const bean = '(?:bean|ben|bin|bing|being|beane|beam)';
    const stop = '(?:stop(?: listening| talking)?|cancel|nevermind|never mind|that s all|that is all|all done|we re good|were good|i m good|im good|goodbye|bye|shut up)';
    const gratitude = '(?:thanks|thank you|thx|no thanks|no thank you)';
    return new RegExp(`^(?:please )?(?:${bean} )?(?:${stop}|${gratitude})(?: (?:please|now|${bean}))?$`).test(normalized);
}

export function isVoiceFillerOnly(text) {
    const normalized = String(text || '')
        .toLowerCase()
        .replace(/[^a-z\s]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    return /^(?:um+|uh+|erm+|hmm+|mm+|ah+)(?: (?:yeah|yes|okay|ok|right))?$/.test(normalized);
}

export function isIntentionalRealtimeInterruption(text) {
    const content = String(text || '').trim();
    if (!content || isVoiceFillerOnly(content)) return false;
    if (isStrictRealtimeWakePhrase(content) || isRealtimeVoiceStopCommand(content)) return true;

    const normalized = content
        .toLowerCase()
        .replace(/[^a-z0-9\s]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    if (/^(?:yeah|yep|yes|okay|ok|right|sure|uh huh|mm hmm|mhm|got it)$/.test(normalized)) return false;
    if (/^(?:no|wait|actually|instead|correction)$/.test(normalized)) return true;

    const meaningfulWords = normalized
        .split(' ')
        .filter((word) => word && !/^(?:um+|uh+|erm+|hmm+|mm+|ah+)$/.test(word));
    return meaningfulWords.length >= 2;
}

export function realtimeWorkStatusAnswer(text, { isWorking = false } = {}) {
    const normalized = String(text || '')
        .toLowerCase()
        .replace(/[^a-z0-9\s]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    const asksForStatus = [
        /^(?:are you|is bean|you) still (?:working(?: on .+)?|doing that|on it)$/,
        /^(?:is|are) (?:that|it|the request|the task) still (?:running|working|going|in progress)$/,
        /^(?:what(?: is| s)? the status|status update|any update|how(?: is| s) (?:that|it) going)$/,
        /^(?:are you (?:done|almost done)|did you finish|is it done|is it ready|have you finished)(?: yet)?$/,
        /^(?:what(?: is| s) taking so long|when will (?:that|it) be done)$/,
    ].some((pattern) => pattern.test(normalized));
    if (!asksForStatus) return '';
    return isWorking
        ? 'Yes — I’m still working on it. I’ll tell you as soon as it finishes.'
        : 'No — I’m not currently working on a request.';
}

export function isExplicitRealtimeWorkInterruption(text, { heardWakeWord = false } = {}) {
    if (heardWakeWord) return true;
    const normalized = String(text || '')
        .toLowerCase()
        .replace(/[^a-z0-9\s]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    return /^(?:actually\b|wait bean\b|bean\b|instead\b|change (?:that|it)\b|make that\b|i meant\b)/.test(normalized);
}

export function isQueueableRealtimeWorkFollowUp(text) {
    const normalized = String(text || '')
        .toLowerCase()
        .replace(/[^a-z0-9\s]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .replace(/^hey (?:bean|ben|bin|bing|being|beane|beam)\s+/, '');
    if (!normalized || isVoiceFillerOnly(normalized)) return false;

    return [
        /^(?:also|and also|then|after that|next)\b/,
        /^(?:can|could|would) you (?:please )?also\b/,
        /^please also\b/,
        /^(?:add|create|set|schedule|make|put)\b/,
        /^remind me\b/,
    ].some((pattern) => pattern.test(normalized));
}

export function isIncompleteRealtimeCommand(text) {
    const normalized = String(text || '')
        .toLowerCase()
        .replace(/[^a-z0-9\s]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .replace(/^hey (?:bean|ben|bin|bing|being|beane|beam)\s*/, '');
    if (!normalized) return true;

    if (/\b(?:for|with|to|at|on|by|from|about|called|titled|named|containing|saying|that says|and|or)$/.test(normalized)) {
        return true;
    }

    return /^(?:(?:can|could|would|will) you (?:please )?)?(?:create|add|make|set|schedule|write|delete|remove|update|change)(?: (?:a|an|the|my))?$/.test(normalized);
}

export function joinRealtimeUtteranceContinuation(first, continuation) {
    const left = String(first || '').trim();
    let right = String(continuation || '').trim()
        .replace(/^hey[\s,.-]*(?:bean|ben|bin|bing|being|beane|beam)\b[\s,.:;!?-]*/i, '')
        .trim();
    if (!left) return right;
    if (!right) return left;

    const lastWord = left.match(/([a-z0-9]+)[^a-z0-9]*$/i)?.[1]?.toLowerCase();
    const firstWord = right.match(/^([^a-z0-9]*)([a-z0-9]+)/i)?.[2]?.toLowerCase();
    if (lastWord && firstWord && lastWord === firstWord) {
        right = right.replace(new RegExp(`^[^a-z0-9]*${firstWord}\\b[\\s,.:;!?-]*`, 'i'), '');
    }

    return `${left} ${right}`.replace(/\s+/g, ' ').trim();
}

export function extractRealtimeResponseTranscript(response) {
    return (Array.isArray(response?.output) ? response.output : [])
        .flatMap((item) => Array.isArray(item?.content) ? item.content : [])
        .map((part) => String(part?.transcript || part?.text || '').trim())
        .filter(Boolean)
        .join('\n')
        .trim();
}

export function shouldDeferAssistantMessage(message, content, shouldStayOutOfChat) {
    const normalizedContent = String(content || '').trim();
    if (!message || !normalizedContent) return false;
    const candidate = { ...message, content: normalizedContent };
    return typeof shouldStayOutOfChat !== 'function' || !shouldStayOutOfChat(candidate);
}

export function buildRealtimeResponseEvent(instructions, { clientResponseId = '' } = {}) {
    const response = {
        instructions: String(instructions || '').trim(),
        tool_choice: 'none',
    };
    if (clientResponseId) {
        response.metadata = { heybean_response_id: String(clientResponseId) };
    }
    return {
        type: 'response.create',
        response,
    };
}

export function buildRealtimePlaybackCancellationEvents() {
    return [
        { type: 'response.cancel' },
        { type: 'output_audio_buffer.clear' },
    ];
}

export function buildRealtimeTargetedResponseCancellationEvent(responseId) {
    const normalizedResponseId = String(responseId || '').trim();
    if (!normalizedResponseId) return null;
    return {
        type: 'response.cancel',
        response_id: normalizedResponseId,
    };
}

export function buildRealtimeConversationItemDeleteEvent(itemId) {
    return {
        type: 'conversation.item.delete',
        item_id: String(itemId || '').trim(),
    };
}

export function cancelRealtimeTurnWithoutBlockingReplacement(cancel) {
    if (typeof cancel !== 'function') return false;
    try {
        Promise.resolve(cancel()).catch(() => {});
        return true;
    } catch {
        return false;
    }
}

export function isCompletedRealtimeResponse(response) {
    return String(response?.status || '').toLowerCase() === 'completed';
}

export function isRealtimeDuplicateCallConflict(status, detail = '') {
    return Number(status) === 409
        && /live session already exists|provided call_id/i.test(String(detail || ''));
}

export class RealtimeCallDeduper {
    constructor() {
        this.toolCallIds = new Set();
    }

    claimToolCall(id) {
        return this.#claim(this.toolCallIds, id);
    }

    reset() {
        this.toolCallIds.clear();
    }

    #claim(collection, id) {
        const key = String(id || '').trim();
        if (!key) return true;
        if (collection.has(key)) return false;
        collection.add(key);
        return true;
    }
}

export class RealtimeTurnPersistenceQueue {
    constructor() {
        this.chains = new Map();
    }

    enqueue(clientTurnId, task) {
        const key = String(clientTurnId || '').trim();
        if (!key || typeof task !== 'function') {
            return Promise.reject(new Error('A client turn id and persistence task are required.'));
        }
        const previous = this.chains.get(key) || Promise.resolve();
        const operation = previous
            .catch(() => null)
            .then(() => task());
        this.chains.set(key, operation);
        operation.finally(() => {
            if (this.chains.get(key) === operation) this.chains.delete(key);
        }).catch(() => {});
        return operation;
    }
}

export function stageOptimisticUserTurn(messages, {
    content,
    clientRequestId,
    supersedesClientRequestId = '',
    localId,
} = {}) {
    const current = Array.isArray(messages) ? messages : [];
    const requestId = String(clientRequestId || '').trim();
    const supersededRequestId = String(supersedesClientRequestId || '').trim();
    const optimisticMessage = {
        id: String(localId || `local-${Date.now()}`),
        role: 'user',
        content: String(content || ''),
        metadata: {
            client_request_id: requestId,
            ...(supersededRequestId ? { supersedes_client_request_id: supersededRequestId } : {}),
        },
    };
    let supersededIndex = -1;
    if (supersededRequestId) {
        for (let index = current.length - 1; index >= 0; index -= 1) {
            const message = current[index];
            if (message?.role === 'user'
                && String(message?.metadata?.client_request_id || '') === supersededRequestId) {
                supersededIndex = index;
                break;
            }
        }
    }

    const next = [...current];
    if (supersededIndex >= 0) {
        const [supersededMessage] = next.splice(supersededIndex, 1, optimisticMessage);
        return {
            messages: next,
            optimisticMessage,
            superseded: { index: supersededIndex, message: supersededMessage },
        };
    }

    next.push(optimisticMessage);
    return { messages: next, optimisticMessage, superseded: null };
}

export function restoreSupersededUserTurn(messages, clientRequestId, superseded) {
    const current = Array.isArray(messages) ? messages : [];
    if (!superseded?.message) return current;
    const supersededId = String(superseded.message.id || '');
    if (supersededId && current.some((message) => String(message?.id || '') === supersededId)) {
        return current;
    }

    const requestId = String(clientRequestId || '').trim();
    const correctionIndex = current.findIndex((message) => message?.role === 'user'
        && String(message?.metadata?.client_request_id || '') === requestId);
    const insertionIndex = correctionIndex >= 0
        ? correctionIndex
        : Math.max(0, Math.min(Number(superseded.index) || 0, current.length));
    const next = [...current];
    next.splice(insertionIndex, 0, superseded.message);
    return next;
}
