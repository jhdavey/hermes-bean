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
                resolve,
            };
        });
    }

    isActive() {
        return Boolean(this.active);
    }

    bindResponse(responseId) {
        if (!this.active) return false;
        this.active.responseId = String(responseId || '');
        return true;
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
