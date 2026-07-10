export function realtimeFollowUpExpiry() {
    return Number.POSITIVE_INFINITY;
}

export function realtimeNeedsAppRuntime(command, { appConversationActive = false } = {}) {
    if (appConversationActive) return true;
    const normalized = String(command || '')
        .toLowerCase()
        .replace(/[^a-z0-9\s]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    return /\b(task|tasks|todo|todos|to do|remind\w*|note|notes|calendar|event|events|schedul\w*|appointment|appointments|dashboard|approval|approvals|weather|forecast|temperature|email|message|text|contact|contacts|account|profile|workspace|list|show|find|search|lookup|look up|create|add|make|update|change|edit|delete|remove|complete|finish|mark|move|reschedul\w*|cancel)\b/.test(normalized);
}

export function isVoiceFillerOnly(text) {
    const normalized = String(text || '')
        .toLowerCase()
        .replace(/[^a-z\s]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    return /^(um+|uh+|erm+|hmm+|mm+|ah+|okay um|ok um)$/.test(normalized);
}

export function extractRealtimeResponseTranscript(response) {
    return (Array.isArray(response?.output) ? response.output : [])
        .flatMap((item) => Array.isArray(item?.content) ? item.content : [])
        .map((part) => String(part?.transcript || part?.text || '').trim())
        .filter(Boolean)
        .join('\n')
        .trim();
}

export function canQueueRealtimeFollowUp({ content, wakeActivated, followUpActive, turnActive }) {
    return Boolean(String(content || '').trim()) && Boolean(wakeActivated || followUpActive || turnActive);
}

export function shouldDeferAssistantMessage(message, content, shouldStayOutOfChat) {
    const normalizedContent = String(content || '').trim();
    if (!message || !normalizedContent) return false;
    const candidate = { ...message, content: normalizedContent };
    return typeof shouldStayOutOfChat !== 'function' || !shouldStayOutOfChat(candidate);
}

export function buildRealtimeResponseEvent(instructions) {
    return {
        type: 'response.create',
        response: {
            instructions: String(instructions || '').trim(),
            tool_choice: 'none',
        },
    };
}

export class RealtimeCallDeduper {
    constructor() {
        this.transcriptIds = new Set();
        this.toolCallIds = new Set();
    }

    claimTranscript(id) {
        return this.#claim(this.transcriptIds, id);
    }

    claimToolCall(id) {
        return this.#claim(this.toolCallIds, id);
    }

    reset() {
        this.transcriptIds.clear();
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

export class RealtimeResponseLifecycle {
    constructor() {
        this.active = null;
    }

    begin(purpose = 'speech') {
        this.cancel();
        return new Promise((resolve) => {
            this.active = {
                purpose,
                transcript: '',
                responseId: '',
                audioStarted: false,
                resolve,
            };
        });
    }

    isActive() {
        return Boolean(this.active);
    }

    bindResponse(responseId) {
        return this.#claimResponse(responseId);
    }

    markAudioStarted(responseId) {
        if (!this.#claimResponse(responseId)) return false;
        this.active.audioStarted = true;
        return true;
    }

    markResponseDone(responseId) {
        if (!this.#claimResponse(responseId)) return null;
        return this.active.audioStarted ? null : this.finish(responseId);
    }

    markAudioStopped(responseId) {
        if (!this.#claimResponse(responseId)) return null;
        return this.finish(responseId);
    }

    captureTranscript(transcript) {
        if (!this.active) return;
        const text = String(transcript || '').trim();
        if (text) this.active.transcript = text;
    }

    finish(responseId = '') {
        if (!this.active) return null;
        const completedResponseId = String(responseId || '');
        if (completedResponseId && completedResponseId !== this.active.responseId) return null;
        const current = this.active;
        this.active = null;
        const result = {
            purpose: current.purpose,
            transcript: current.transcript,
            cancelled: false,
        };
        current.resolve(result);
        return result;
    }

    #claimResponse(responseId) {
        if (!this.active) return false;
        const id = String(responseId || '');
        if (!id) return true;
        if (this.active.responseId && this.active.responseId !== id) return false;
        this.active.responseId = id;
        return true;
    }

    cancel() {
        if (!this.active) return;
        const active = this.active;
        this.active = null;
        active.resolve({
            purpose: active.purpose,
            transcript: active.transcript,
            cancelled: true,
        });
    }
}
