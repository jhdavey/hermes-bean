export function realtimeFollowUpExpiry() {
    return Number.POSITIVE_INFINITY;
}

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
    return /^(um+|uh+|erm+|hmm+|mm+|ah+|okay um|ok um)$/.test(normalized);
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

export const REALTIME_CONVERSATION_STATES = Object.freeze({
    WAKE_ONLY: 'wake_only',
    ACTIVE: 'active',
});

export class RealtimeConversationController {
    constructor({ maxTranscriptIds = 2_048 } = {}) {
        this.state = REALTIME_CONVERSATION_STATES.WAKE_ONLY;
        this.epoch = 0;
        this.maxTranscriptIds = Math.max(1, Number(maxTranscriptIds) || 2_048);
        this.transcriptIds = new Set();
        this.transcriptOrigins = new Map();
    }

    capture() {
        return this.epoch;
    }

    snapshot() {
        return Object.freeze({ state: this.state, epoch: this.epoch });
    }

    isActive() {
        return this.state === REALTIME_CONVERSATION_STATES.ACTIVE;
    }

    isCurrent(epoch) {
        return epoch === this.epoch;
    }

    canContinue(epoch) {
        return this.isActive() && this.isCurrent(epoch);
    }

    activate() {
        if (!this.isActive()) {
            this.state = REALTIME_CONVERSATION_STATES.ACTIVE;
            this.epoch += 1;
        }
        return this.capture();
    }

    activateFromLocalWake() {
        const activated = !this.isActive();
        this.activate();
        return this.#admission(true, activated, 'local_wake');
    }

    stop() {
        this.state = REALTIME_CONVERSATION_STATES.WAKE_ONLY;
        this.epoch += 1;
        return this.capture();
    }

    sleep() {
        this.state = REALTIME_CONVERSATION_STATES.WAKE_ONLY;
        return this.capture();
    }

    noteTranscriptOrigin(id) {
        const key = String(id || '').trim();
        if (!key || this.transcriptOrigins.has(key)) return;
        this.transcriptOrigins.set(key, this.snapshot());
        if (this.transcriptOrigins.size > this.maxTranscriptIds) {
            this.transcriptOrigins.delete(this.transcriptOrigins.keys().next().value);
        }
    }

    supersedeTranscript({ content } = {}) {
        const transcript = String(content || '').trim();
        if (!transcript) return this.#admission(false, false, 'empty');
        if (!this.isActive()) return this.#admission(false, false, 'wake_required');
        this.epoch += 1;
        return this.#admission(true, false, 'superseded');
    }

    resumeTranscript({ content, epoch } = {}) {
        const transcript = String(content || '').trim();
        if (!transcript) return this.#admission(false, false, 'empty');
        if (!this.canContinue(epoch)) return this.#admission(false, false, 'stale');
        return this.#admission(true, false, 'resumed');
    }

    admitTranscript({ id = '', content, heardWakeWord = false } = {}) {
        const transcript = String(content || '').trim();
        if (!transcript) return this.#admission(false, false, 'empty');
        if (!this.#claimTranscript(id)) return this.#admission(false, false, 'duplicate');

        const origin = this.transcriptOrigins.get(String(id || '').trim());
        const originatedInCurrentActiveEpoch = this.isActive()
            && (!origin || (origin.state === REALTIME_CONVERSATION_STATES.ACTIVE && origin.epoch === this.epoch));
        let activated = false;
        if (!originatedInCurrentActiveEpoch) {
            if (!heardWakeWord) return this.#admission(false, false, 'wake_required');
            this.activate();
            activated = true;
        }

        return this.#admission(true, activated, 'accepted');
    }

    #admission(accepted, activated, reason) {
        return Object.freeze({
            accepted,
            activated,
            reason,
            state: this.state,
            epoch: this.epoch,
        });
    }

    #claimTranscript(id) {
        const key = String(id || '').trim();
        if (!key) return true;
        if (this.transcriptIds.has(key)) return false;
        this.transcriptIds.add(key);
        if (this.transcriptIds.size > this.maxTranscriptIds) {
            this.transcriptIds.delete(this.transcriptIds.values().next().value);
        }
        return true;
    }
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

export class RealtimeResponseLifecycle {
    constructor(clock = () => Date.now(), timers = {}) {
        this.clock = clock;
        this.setTimeout = timers.setTimeout || globalThis.setTimeout?.bind(globalThis);
        this.clearTimeout = timers.clearTimeout || globalThis.clearTimeout?.bind(globalThis);
        this.active = null;
        this.closedResponseIds = new Set();
        this.requestSequence = 0;
    }

    begin(purpose = 'speech', options = {}) {
        this.cancel('superseded');
        return new Promise((resolve) => {
            this.active = {
                purpose,
                clientResponseId: `heybean-response-${++this.requestSequence}`,
                transcript: '',
                responseId: '',
                audioStarted: false,
                audioStopped: false,
                responseDone: false,
                startedAtMs: this.clock(),
                audioStartedAtMs: null,
                timeoutId: null,
                onTimeout: typeof options.onTimeout === 'function' ? options.onTimeout : null,
                resolve,
            };
            const timeoutMs = Math.max(0, Number(options.timeoutMs) || 0);
            if (timeoutMs > 0 && this.setTimeout) {
                const clientResponseId = this.active.clientResponseId;
                this.active.timeoutId = this.setTimeout(() => {
                    if (this.active?.clientResponseId !== clientResponseId) return;
                    const onTimeout = this.active.onTimeout;
                    this.cancel('timed_out');
                    onTimeout?.();
                }, timeoutMs);
            }
        });
    }

    isActive() {
        return Boolean(this.active);
    }

    currentClientResponseId() {
        return this.active?.clientResponseId || '';
    }

    bindResponse(responseId, clientResponseId) {
        if (!this.active || String(clientResponseId || '') !== this.active.clientResponseId) return false;
        return this.#claimResponse(responseId, true);
    }

    acceptsResponse(responseId) {
        return this.#claimResponse(responseId, false);
    }

    markAudioStarted(responseId) {
        if (!this.#claimResponse(responseId, false)) return false;
        this.active.audioStarted = true;
        this.active.audioStartedAtMs ??= this.clock();
        return true;
    }

    markResponseDone(responseId) {
        if (!this.#claimResponse(responseId, false)) return null;
        this.active.responseDone = true;
        return this.active.audioStarted && !this.active.audioStopped ? null : this.finish(responseId);
    }

    markAudioStopped(responseId) {
        if (!this.#claimResponse(responseId, false)) return null;
        this.active.audioStopped = true;
        return this.active.responseDone ? this.finish(responseId) : null;
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
        this.#clearResponseTimeout(current);
        this.#closeResponse(current.responseId);
        const finishedAtMs = this.clock();
        const result = {
            purpose: current.purpose,
            transcript: current.transcript,
            cancelled: false,
            reason: 'completed',
            startedAtMs: current.startedAtMs,
            audioStartedAtMs: current.audioStartedAtMs,
            audioStartLatencyMs: current.audioStartedAtMs === null ? null : Math.max(0, current.audioStartedAtMs - current.startedAtMs),
            responseDurationMs: Math.max(0, finishedAtMs - current.startedAtMs),
        };
        current.resolve(result);
        return result;
    }

    #claimResponse(responseId, allowBind) {
        if (!this.active) return false;
        const id = String(responseId || '');
        if (!id) return false;
        if (this.closedResponseIds.has(id)) return false;
        if (this.active.responseId && this.active.responseId !== id) return false;
        if (!this.active.responseId && !allowBind) return false;
        this.active.responseId = id;
        return true;
    }

    #closeResponse(responseId) {
        const id = String(responseId || '').trim();
        if (!id) return;
        this.closedResponseIds.add(id);
        if (this.closedResponseIds.size > 100) {
            this.closedResponseIds.delete(this.closedResponseIds.values().next().value);
        }
    }

    #clearResponseTimeout(response) {
        if (response?.timeoutId !== null && this.clearTimeout) {
            this.clearTimeout(response.timeoutId);
        }
    }

    cancel(reason = 'cancelled') {
        if (!this.active) return null;
        const active = this.active;
        this.active = null;
        this.#clearResponseTimeout(active);
        this.#closeResponse(active.responseId);
        const finishedAtMs = this.clock();
        const result = {
            purpose: active.purpose,
            transcript: active.transcript,
            cancelled: true,
            reason: String(reason || 'cancelled'),
            startedAtMs: active.startedAtMs,
            audioStartedAtMs: active.audioStartedAtMs,
            audioStartLatencyMs: active.audioStartedAtMs === null ? null : Math.max(0, active.audioStartedAtMs - active.startedAtMs),
            responseDurationMs: Math.max(0, finishedAtMs - active.startedAtMs),
        };
        active.resolve(result);
        return result;
    }
}
