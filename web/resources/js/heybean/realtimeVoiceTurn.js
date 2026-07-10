const REALTIME_FOLLOW_UP_WINDOW_MS = 60_000;

export function realtimeFollowUpExpiry(now = Date.now()) {
    return Number(now) + REALTIME_FOLLOW_UP_WINDOW_MS;
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
