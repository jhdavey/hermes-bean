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

export async function reportRealtimeUsageReliably(report, {
    send,
    retryDelaysMs = [250, 750, 1500],
    delay = (milliseconds) => new Promise((resolve) => globalThis.setTimeout(resolve, milliseconds)),
} = {}) {
    if (typeof send !== 'function') throw new TypeError('Realtime usage reporting requires a sender.');
    const schedule = Array.isArray(retryDelaysMs) ? retryDelaysMs : [];
    let attempt = 0;
    while (true) {
        try {
            return await send(report, attempt);
        } catch (error) {
            if (Number(error?.status || 0) === 402 || attempt >= schedule.length) throw error;
            await delay(Math.max(0, Number(schedule[attempt]) || 0));
            attempt += 1;
        }
    }
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

    const normalizedStage = ['local_wake', 'startup', 'admission', 'usage_accounting'].includes(stage)
        ? stage
        : 'local_wake';
    const fallback = normalizedStage === 'startup'
        ? ['voice_startup_failure', 'Browser voice startup failed.']
        : normalizedStage === 'admission'
            ? ['voice_admission_failure', 'Browser voice admission failed.']
            : normalizedStage === 'usage_accounting'
                ? ['voice_usage_accounting_failure', 'Browser voice usage accounting failed.']
            : ['local_wake_failure', 'Private wake detection failed.'];

    return {
        stage: normalizedStage,
        code: chain[0]?.code || fallback[0],
        message: chain[0]?.message || fallback[1],
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

export function stripRealtimeLocalWakePrefix(text, { wakeConfirmed = false } = {}) {
    const transcript = String(text || '').trim();
    if (!wakeConfirmed) return transcript;
    return transcript
        .replace(/^\s*(?:(?:hey|they|he)[\s,.-]+(?:bean|ben|bin|bing|being|beane|beam)|habe(?:en|ing)|bean)\b[\s,.:;!?-]*/i, '')
        .trim();
}

export function isRealtimeWakeAddressOnly(text, { wakeConfirmed = false } = {}) {
    if (!wakeConfirmed) return false;
    const transcript = String(text || '').trim();
    if (!transcript) return true;
    return stripRealtimeLocalWakePrefix(transcript, { wakeConfirmed: true }) === ''
        && /^(?:(?:hey|they|he)[\s,.-]+(?:bean|ben|bin|bing|being|beane|beam)|habe(?:en|ing)|bean)\b[\s.!?,-]*$/i.test(transcript);
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
