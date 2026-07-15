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

async function deliverVoiceDiagnosticReliably(payload, {
    send,
    retryDelaysMs = [250, 750, 1500],
    shouldContinue = () => true,
} = {}) {
    const schedule = Array.isArray(retryDelaysMs) ? retryDelaysMs : [];
    for (let attempt = 0; ; attempt += 1) {
        if (!shouldContinue()) throw new Error('Voice diagnostic delivery was superseded.');
        try {
            return await send(payload, attempt);
        } catch (error) {
            if (attempt >= schedule.length) throw error;
            await new Promise((resolve) => globalThis.setTimeout(
                resolve,
                Math.max(0, Number(schedule[attempt]) || 0),
            ));
        }
    }
}

const VOICE_CLIENT_FAILURE_STAGES = Object.freeze([
    'local_wake',
    'startup',
    'admission',
    'connection',
    'delivery',
    'projection',
    'playback',
    'realtime_sideband',
]);

const CONTENT_NEUTRAL_FAILURE_MESSAGES = Object.freeze({
    connection: 'Browser voice connection failed.',
    delivery: 'Browser voice delivery failed.',
    projection: 'Browser voice projection failed.',
    playback: 'Browser voice playback failed.',
    realtime_sideband: 'Browser voice sideband failed.',
});

const CONTENT_NEUTRAL_FAILURE_CODES = Object.freeze({
    connection: 'voice_connection_failure',
    delivery: 'voice_delivery_failure',
    projection: 'voice_projection_failure',
    playback: 'voice_playback_failure',
    realtime_sideband: 'voice_realtime_sideband_failure',
});

const PERSISTED_FAILURE_MESSAGES = Object.freeze({
    local_wake: 'Private wake detection failed.',
    startup: 'Browser voice startup failed.',
    admission: 'Browser voice admission failed.',
    connection: CONTENT_NEUTRAL_FAILURE_MESSAGES.connection,
    delivery: CONTENT_NEUTRAL_FAILURE_MESSAGES.delivery,
    projection: CONTENT_NEUTRAL_FAILURE_MESSAGES.projection,
    playback: CONTENT_NEUTRAL_FAILURE_MESSAGES.playback,
    realtime_sideband: CONTENT_NEUTRAL_FAILURE_MESSAGES.realtime_sideband,
});

const PERSISTED_FAILURE_CODES = Object.freeze({
    local_wake: 'local_wake_failure',
    startup: 'voice_startup_failure',
    admission: 'voice_admission_failure',
    connection: CONTENT_NEUTRAL_FAILURE_CODES.connection,
    delivery: CONTENT_NEUTRAL_FAILURE_CODES.delivery,
    projection: CONTENT_NEUTRAL_FAILURE_CODES.projection,
    playback: CONTENT_NEUTRAL_FAILURE_CODES.playback,
    realtime_sideband: CONTENT_NEUTRAL_FAILURE_CODES.realtime_sideband,
});

const CONTROLLED_LOCAL_FAILURE_CODES = Object.freeze({
    local_wake: new Set([
        'activated_pcm_delivery_failed',
        'already_started',
        'audio_context_closed',
        'audio_context_resume_timeout',
        'audio_sink_unavailable',
        'decode_failed',
        'dormant_rearm_failed',
        'gate_close_failed',
        'gate_open_failed',
        'incomplete_readiness_barrier',
        'initialization_failed',
        'invalid_audio',
        'invalid_generation',
        'invalid_local_pcm',
        'invalid_message',
        'invalid_message_type',
        'invalid_pcm_ack_sequence',
        'invalid_release_boundary',
        'invalid_sequence',
        'invalid_source_sequence',
        'invalid_utterance_boundary',
        'microphone_stream_required',
        'missing_release_boundary',
        'pcm_ack_timeout',
        'pcm_decode_rejected',
        'pcm_transfer_failed',
        'processor_failed',
        'processor_unavailable',
        'reset_failed',
        'runtime_load_failed',
        'source_sequence_gap',
        'stale_start',
        'start_failed',
        'unhandled_rejection',
        'unsafe_asset_url',
        'unsupported',
        'worker_error',
    ]),
    startup: new Set([
        'AbortError',
        'NotAllowedError',
        'NotFoundError',
        'NotReadableError',
        'OverconstrainedError',
        'SecurityError',
        'TypeError',
        'audio_context_closed',
        'audio_context_resume_timeout',
        'audio_sink_unavailable',
        'microphone_stream_required',
        'reset_failed',
        'stale_start',
        'start_failed',
        'unsupported',
    ]),
});

const RELOAD_SCOPED_FAILURE_STAGES = Object.freeze(new Set([
    'local_wake',
    'startup',
    'connection',
]));

const VOICE_CLIENT_FAILURE_OUTBOX_VERSION = 1;
const VOICE_CLIENT_FAILURE_OUTBOX_KEY_PREFIX = 'heybean.voice_client_failure_outbox.v1';
const VOICE_CLIENT_FAILURE_OUTBOX_OVERFLOW_CODE = 'voice_diagnostic_outbox_overflow';
const VOICE_CLIENT_FAILURE_OUTBOX_OVERFLOW_MESSAGE = 'Browser voice diagnostic outbox reached its local capacity.';
const VOICE_CLIENT_FAILURE_OUTBOX_CORRUPT_CODE = 'voice_diagnostic_outbox_corrupt';
const VOICE_CLIENT_FAILURE_OUTBOX_CORRUPT_MESSAGE = 'Browser voice diagnostic outbox discarded corrupted local state.';
const VOICE_CLIENT_FAILURE_OUTBOX_INTERNAL_CODES = Object.freeze(new Set([
    VOICE_CLIENT_FAILURE_OUTBOX_OVERFLOW_CODE,
    VOICE_CLIENT_FAILURE_OUTBOX_CORRUPT_CODE,
]));

function normalizedVoiceClientFailureStage(stage) {
    return VOICE_CLIENT_FAILURE_STAGES.includes(stage) ? stage : 'local_wake';
}

function sanitizedVoiceFailureCode(value) {
    return String(value || '').replace(/[^a-z0-9_.-]+/gi, '_').slice(0, 80);
}

function sanitizedVoiceFailureMessage(value) {
    return String(value || '')
        .replace(/\bBearer\s+\S+/gi, 'Bearer [redacted]')
        .replace(/\b(?:sk|pk)-[A-Za-z0-9_-]+\b/g, '[redacted]')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 240);
}

function controlledLocalFailureCode(stage, value) {
    const code = sanitizedVoiceFailureCode(value);
    return CONTROLLED_LOCAL_FAILURE_CODES[stage]?.has(code) ? code : '';
}

export function sanitizedVoiceClientFailure(error, stage = 'local_wake') {
    const normalizedStage = normalizedVoiceClientFailureStage(stage);
    const contentNeutralMessage = CONTENT_NEUTRAL_FAILURE_MESSAGES[normalizedStage] || '';
    const contentNeutralCode = CONTENT_NEUTRAL_FAILURE_CODES[normalizedStage] || '';
    const chain = [];
    const seen = new Set();
    let current = error;
    while (current && typeof current === 'object' && chain.length < 4 && !seen.has(current)) {
        seen.add(current);
        const code = contentNeutralCode || sanitizedVoiceFailureCode(current.code);
        const rawMessage = String(current.message || '');
        const message = contentNeutralMessage || sanitizedVoiceFailureMessage(rawMessage);
        if (!code && !rawMessage) {
            current = current.cause;
            continue;
        }
        if (code || message) chain.push({ code: code || null, message: message || null });
        current = current.cause;
    }

    const fallback = [
        PERSISTED_FAILURE_CODES[normalizedStage],
        PERSISTED_FAILURE_MESSAGES[normalizedStage],
    ];

    return {
        stage: normalizedStage,
        code: chain[0]?.code || fallback[0],
        message: contentNeutralMessage || chain[0]?.message || fallback[1],
        cause_chain: chain,
    };
}

function stableVoiceFailureHash(value) {
    let first = 0x811c9dc5;
    let second = 0x9e3779b9;
    for (const character of String(value || '')) {
        const code = character.codePointAt(0) || 0;
        first = Math.imul(first ^ code, 0x01000193) >>> 0;
        second = Math.imul(second ^ code, 0x85ebca6b) >>> 0;
    }
    return `${first.toString(16).padStart(8, '0')}${second.toString(16).padStart(8, '0')}`;
}

export function createVoiceClientFailureNonce({
    randomUUID = () => globalThis.crypto?.randomUUID?.(),
    now = () => Date.now(),
    random = () => Math.random(),
} = {}) {
    let candidate = '';
    try {
        candidate = String(randomUUID?.() || '');
    } catch (_) {}
    if (!candidate) {
        const timestamp = Math.max(0, Number(now?.()) || Date.now()).toString(36);
        const entropy = Math.floor(Math.abs(Number(random?.()) || 0) * Number.MAX_SAFE_INTEGER).toString(36);
        candidate = `page-${timestamp}-${entropy}`;
    }
    const nonce = candidate.replace(/[^A-Za-z0-9._-]+/g, '_').slice(0, 80);
    if (!nonce) throw new TypeError('Browser voice diagnostic identity requires a page nonce.');
    return nonce;
}

export function voiceClientFailureIdentityParts(stage, identityParts = [], pageNonce = '') {
    const normalizedStage = normalizedVoiceClientFailureStage(stage);
    const identity = (Array.isArray(identityParts) ? identityParts : [identityParts]).flat();
    if (!RELOAD_SCOPED_FAILURE_STAGES.has(normalizedStage)) return identity;
    const nonce = String(pageNonce || '').trim().replace(/[^A-Za-z0-9._-]+/g, '_').slice(0, 80);
    if (!nonce) throw new TypeError(`${normalizedStage} diagnostics require a page-stable nonce.`);
    return [nonce, ...identity];
}

export function voiceClientFailureId(stage, ...identityParts) {
    const normalizedStage = normalizedVoiceClientFailureStage(stage);
    const identity = identityParts
        .flat()
        .map((part) => String(part ?? '').trim().replace(/[^A-Za-z0-9:._-]+/g, '_'))
        .filter(Boolean)
        .join(':') || 'unspecified';
    const prefix = `browser_voice_v2:${normalizedStage}:`;
    const hash = stableVoiceFailureHash(identity);
    const readableLimit = Math.max(1, 191 - prefix.length - hash.length - 1);
    return `${prefix}${identity.slice(0, readableLimit)}:${hash}`;
}

function boundedVoiceClientFailurePayload(payload = {}, { allowOutboxDiagnostics = false } = {}) {
    const failureId = String(payload.failure_id || '')
        .trim()
        .replace(/[^A-Za-z0-9:._-]+/g, '_')
        .slice(0, 191);
    if (!failureId) throw new TypeError('Browser voice client failures require a stable failure id.');

    const stage = normalizedVoiceClientFailureStage(payload.stage);
    const requestedCode = sanitizedVoiceFailureCode(payload.code);
    const internalOutboxDiagnostic = allowOutboxDiagnostics
        && stage === 'startup'
        && VOICE_CLIENT_FAILURE_OUTBOX_INTERNAL_CODES.has(requestedCode);
    const code = internalOutboxDiagnostic
        ? requestedCode
        : controlledLocalFailureCode(stage, requestedCode) || PERSISTED_FAILURE_CODES[stage];
    const message = internalOutboxDiagnostic
        ? requestedCode === VOICE_CLIENT_FAILURE_OUTBOX_OVERFLOW_CODE
            ? VOICE_CLIENT_FAILURE_OUTBOX_OVERFLOW_MESSAGE
            : VOICE_CLIENT_FAILURE_OUTBOX_CORRUPT_MESSAGE
        : PERSISTED_FAILURE_MESSAGES[stage];
    const causeChain = internalOutboxDiagnostic && requestedCode === VOICE_CLIENT_FAILURE_OUTBOX_OVERFLOW_CODE
        ? Array.isArray(payload.cause_chain)
            ? payload.cause_chain.slice(0, 1).map((cause) => {
                const count = Math.max(1, Math.min(1000000000, Number(
                    String(cause?.code || '').match(/^overflow_count_(\d+)$/)?.[1] || 1,
                ) || 1));
                return {
                    code: `overflow_count_${count}`,
                    message: 'Additional browser voice diagnostics were summarized locally.',
                };
            })
            : []
        : Array.isArray(payload.cause_chain)
            ? payload.cause_chain.slice(0, 4).map((cause) => ({
                code: controlledLocalFailureCode(stage, cause?.code) || PERSISTED_FAILURE_CODES[stage],
                message: PERSISTED_FAILURE_MESSAGES[stage],
            }))
            : [];
    const sessionId = Number(payload.session_id);
    const turnId = String(payload.turn_id || '')
        .trim()
        .replace(/[^A-Za-z0-9:._-]+/g, '_')
        .slice(0, 191);

    return Object.freeze({
        failure_id: failureId,
        stage,
        code,
        message,
        cause_chain: causeChain.map((cause) => Object.freeze(cause)),
        ...(Number.isSafeInteger(sessionId) && sessionId > 0 ? { session_id: sessionId } : {}),
        ...(turnId ? { turn_id: turnId } : {}),
    });
}

export class VoiceClientFailureReporter {
    constructor({
        send,
        isOnline = () => globalThis.navigator?.onLine !== false,
        eventTarget = globalThis,
        storage = null,
        scopeId = null,
        nonceFactory = () => createVoiceClientFailureNonce(),
        onPersistenceFailure = () => {},
        setTimeout = globalThis.setTimeout,
        clearTimeout = globalThis.clearTimeout,
        retryDelaysMs = [250, 750, 1500],
        nextAttemptMs = 5000,
        maxPending = 64,
        maxDelivered = 128,
    } = {}) {
        if (typeof send !== 'function') throw new TypeError('Browser voice client failure reporting requires a sender.');
        this.send = send;
        this.isOnline = typeof isOnline === 'function' ? isOnline : () => true;
        this.eventTarget = eventTarget;
        this.storage = storage
            && typeof storage.getItem === 'function'
            && typeof storage.setItem === 'function'
            && typeof storage.removeItem === 'function'
            ? storage
            : null;
        this.nonceFactory = typeof nonceFactory === 'function'
            ? nonceFactory
            : () => createVoiceClientFailureNonce();
        this.onPersistenceFailure = typeof onPersistenceFailure === 'function'
            ? onPersistenceFailure
            : () => {};
        this.setTimeout = typeof setTimeout === 'function' ? setTimeout.bind(globalThis) : globalThis.setTimeout;
        this.clearTimeout = typeof clearTimeout === 'function' ? clearTimeout.bind(globalThis) : globalThis.clearTimeout;
        this.retryDelaysMs = Array.isArray(retryDelaysMs) ? retryDelaysMs.slice(0, 8) : [];
        this.nextAttemptMs = Math.max(250, Math.min(60000, Number(nextAttemptMs) || 5000));
        this.maxPending = Math.max(1, Math.min(256, Number(maxPending) || 64));
        this.maxDelivered = Math.max(1, Math.min(512, Number(maxDelivered) || 128));
        this.pending = new Map();
        this.delivered = new Set();
        this.inFlight = null;
        this.inFlightId = '';
        this.retryTimer = null;
        this.epoch = 0;
        this.scopeHash = '';
        this.scopeNonce = '';
        this.storageKey = '';
        this.nextOverflowSequence = 1;
        this.overflow = null;
        this.persistenceFailureNotified = false;
        this.handleOnline = () => {
            this.#clearRetryTimer();
            void this.flush();
        };
        this.eventTarget?.addEventListener?.('online', this.handleOnline);
        if (scopeId !== null && scopeId !== undefined) this.setScope(scopeId);
    }

    enqueue(payload) {
        if (!this.scopeHash) return false;
        const bounded = boundedVoiceClientFailurePayload(payload);
        const failureId = bounded.failure_id;
        if (this.delivered.has(failureId) || this.pending.has(failureId)) return false;

        if (this.pending.size >= this.maxPending) {
            const recorded = this.#recordOverflow(failureId);
            const persisted = this.#persist();
            return recorded && persisted;
        }
        this.pending.set(failureId, bounded);
        const persisted = this.#persist();
        void this.flush();
        return persisted;
    }

    async flush() {
        if (this.inFlight) return this.inFlight;
        if (!this.isOnline()) return false;
        const next = this.pending.entries().next().value;
        if (!next) return true;

        this.#clearRetryTimer();
        const [failureId, payload] = next;
        const epoch = this.epoch;
        this.inFlightId = failureId;
        this.inFlight = (async () => {
            try {
                await deliverVoiceDiagnosticReliably(payload, {
                    send: this.send,
                    retryDelaysMs: this.retryDelaysMs,
                    shouldContinue: () => epoch === this.epoch,
                });
                if (epoch !== this.epoch) return false;
                if (this.pending.get(failureId) === payload) this.pending.delete(failureId);
                this.delivered.add(failureId);
                while (this.delivered.size > this.maxDelivered) {
                    this.delivered.delete(this.delivered.values().next().value);
                }
                this.#materializeOverflowIfSpace();
                this.#persist();
                return true;
            } catch (_) {
                if (epoch === this.epoch && this.pending.get(failureId) === payload) {
                    this.pending.delete(failureId);
                    this.pending.set(failureId, payload);
                    this.#persist();
                    this.#scheduleRetry();
                }
                return false;
            } finally {
                if (epoch === this.epoch) {
                    this.inFlight = null;
                    this.inFlightId = '';
                    if (!this.retryTimer && this.pending.size > 0) void this.flush();
                }
            }
        })();
        return this.inFlight;
    }

    reset() {
        if (!this.scopeHash) {
            this.#deactivateMemory();
            return;
        }
        const scopeHash = this.scopeHash;
        const storageKey = this.storageKey;
        this.#deactivateMemory();
        this.scopeHash = scopeHash;
        this.scopeNonce = this.#createScopeNonce();
        this.storageKey = storageKey;
        this.#restore();
        this.#materializeOverflowIfSpace();
        this.#persist();
        void this.flush();
    }

    setScope(scopeId) {
        const scope = String(scopeId ?? '').trim();
        if (!scope) throw new TypeError('Browser voice client failure reporting requires an authenticated user scope.');
        const scopeHash = stableVoiceFailureHash(scope);
        if (scopeHash === this.scopeHash) {
            void this.flush();
            return false;
        }

        this.#deactivateMemory();
        this.scopeHash = scopeHash;
        this.scopeNonce = this.#createScopeNonce();
        this.storageKey = `${VOICE_CLIENT_FAILURE_OUTBOX_KEY_PREFIX}:${scopeHash}`;
        this.#restore();
        this.#materializeOverflowIfSpace();
        this.#persist();
        void this.flush();
        return true;
    }

    clearCurrentScope() {
        const storageKey = this.storageKey;
        if (storageKey && this.storage) {
            try {
                this.storage.removeItem(storageKey);
            } catch (_) {
                this.#notifyPersistenceFailure();
            }
        }
        this.#deactivateMemory();
    }

    deactivateCurrentScope() {
        this.#deactivateMemory();
    }

    dispose() {
        this.eventTarget?.removeEventListener?.('online', this.handleOnline);
        this.#deactivateMemory();
    }

    pendingCount() {
        return this.pending.size;
    }

    overflowCount() {
        return Number(this.overflow?.count || 0);
    }

    #deactivateMemory() {
        this.epoch += 1;
        this.#clearRetryTimer();
        this.pending.clear();
        this.delivered.clear();
        this.inFlight = null;
        this.inFlightId = '';
        this.scopeHash = '';
        this.scopeNonce = '';
        this.storageKey = '';
        this.nextOverflowSequence = 1;
        this.overflow = null;
        this.persistenceFailureNotified = false;
    }

    #recordOverflow(failureId) {
        const fingerprint = stableVoiceFailureHash(failureId);
        if (!this.overflow) {
            this.overflow = {
                sequence: this.nextOverflowSequence,
                nonce: this.scopeNonce,
                count: 0,
                fingerprints: [],
            };
            this.nextOverflowSequence += 1;
        }
        if (this.overflow.fingerprints.includes(fingerprint)) return false;
        this.overflow.count = Math.min(1000000000, this.overflow.count + 1);
        if (this.overflow.fingerprints.length < 256) this.overflow.fingerprints.push(fingerprint);
        return true;
    }

    #materializeOverflowIfSpace() {
        if (!this.overflow || this.pending.size >= this.maxPending) return false;
        const { sequence, nonce, count } = this.overflow;
        const payload = boundedVoiceClientFailurePayload({
            failure_id: voiceClientFailureId(
                'startup',
                'diagnostic_outbox_overflow',
                this.scopeHash,
                nonce,
                sequence,
            ),
            stage: 'startup',
            code: VOICE_CLIENT_FAILURE_OUTBOX_OVERFLOW_CODE,
            message: VOICE_CLIENT_FAILURE_OUTBOX_OVERFLOW_MESSAGE,
            cause_chain: [{
                code: `overflow_count_${count}`,
                message: 'Additional browser voice diagnostics were summarized locally.',
            }],
        }, { allowOutboxDiagnostics: true });
        this.pending.set(payload.failure_id, payload);
        this.overflow = null;
        return true;
    }

    #restore() {
        if (!this.storage || !this.storageKey) return;
        let raw = null;
        try {
            raw = this.storage.getItem(this.storageKey);
        } catch (_) {
            this.#notifyPersistenceFailure();
            return;
        }
        if (raw === null) return;

        try {
            const parsed = JSON.parse(raw);
            const allowedStateKeys = new Set(['version', 'next_overflow_sequence', 'pending', 'overflow']);
            if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) throw new TypeError('invalid outbox');
            if (Object.keys(parsed).some((key) => !allowedStateKeys.has(key))) throw new TypeError('invalid outbox keys');
            if (parsed.version !== VOICE_CLIENT_FAILURE_OUTBOX_VERSION) throw new TypeError('invalid outbox version');
            if (!Array.isArray(parsed.pending) || parsed.pending.length > this.maxPending) throw new TypeError('invalid pending outbox');
            const nextSequence = Number(parsed.next_overflow_sequence);
            if (!Number.isSafeInteger(nextSequence) || nextSequence < 1) throw new TypeError('invalid outbox sequence');

            const restored = new Map();
            for (const rawPayload of parsed.pending) {
                this.#assertPersistedPayloadShape(rawPayload);
                const payload = boundedVoiceClientFailurePayload(rawPayload, { allowOutboxDiagnostics: true });
                if (JSON.stringify(payload) !== JSON.stringify(rawPayload)) throw new TypeError('non-canonical outbox payload');
                if (restored.has(payload.failure_id)) throw new TypeError('duplicate outbox payload');
                restored.set(payload.failure_id, payload);
            }

            let overflow = null;
            if (parsed.overflow !== null) {
                const rawOverflow = parsed.overflow;
                if (!rawOverflow || typeof rawOverflow !== 'object' || Array.isArray(rawOverflow)) throw new TypeError('invalid overflow');
                if (Object.keys(rawOverflow).some((key) => !['sequence', 'nonce', 'count', 'fingerprints'].includes(key))) {
                    throw new TypeError('invalid overflow keys');
                }
                const sequence = Number(rawOverflow.sequence);
                const nonce = String(rawOverflow.nonce || '');
                const count = Number(rawOverflow.count);
                if (!Number.isSafeInteger(sequence) || sequence < 1 || sequence >= nextSequence) throw new TypeError('invalid overflow sequence');
                if (!/^[A-Za-z0-9._-]{1,80}$/.test(nonce)) throw new TypeError('invalid overflow nonce');
                if (!Number.isSafeInteger(count) || count < 1 || count > 1000000000) throw new TypeError('invalid overflow count');
                if (!Array.isArray(rawOverflow.fingerprints) || rawOverflow.fingerprints.length > 256) {
                    throw new TypeError('invalid overflow fingerprints');
                }
                const fingerprints = rawOverflow.fingerprints.map((fingerprint) => String(fingerprint || ''));
                if (fingerprints.some((fingerprint) => !/^[a-f0-9]{16}$/.test(fingerprint))) {
                    throw new TypeError('invalid overflow fingerprint');
                }
                if (new Set(fingerprints).size !== fingerprints.length) throw new TypeError('duplicate overflow fingerprint');
                overflow = { sequence, nonce, count, fingerprints };
            }

            this.pending = restored;
            this.nextOverflowSequence = nextSequence;
            this.overflow = overflow;
        } catch (_) {
            this.#replaceCorruptState(raw);
        }
    }

    #assertPersistedPayloadShape(payload) {
        if (!payload || typeof payload !== 'object' || Array.isArray(payload)) throw new TypeError('invalid outbox payload');
        const allowedPayloadKeys = new Set(['failure_id', 'stage', 'code', 'message', 'cause_chain', 'session_id', 'turn_id']);
        if (Object.keys(payload).some((key) => !allowedPayloadKeys.has(key))) throw new TypeError('invalid outbox payload keys');
        if (!VOICE_CLIENT_FAILURE_STAGES.includes(payload.stage)) throw new TypeError('invalid outbox stage');
        if (!Array.isArray(payload.cause_chain) || payload.cause_chain.length > 4) throw new TypeError('invalid outbox cause chain');
        for (const cause of payload.cause_chain) {
            if (!cause || typeof cause !== 'object' || Array.isArray(cause)) throw new TypeError('invalid outbox cause');
            if (Object.keys(cause).some((key) => !['code', 'message'].includes(key))) throw new TypeError('invalid outbox cause keys');
        }
    }

    #replaceCorruptState(raw) {
        if (this.storage && this.storageKey) {
            try {
                this.storage.removeItem(this.storageKey);
            } catch (_) {}
        }
        this.pending.clear();
        this.overflow = null;
        this.nextOverflowSequence = 1;
        this.scopeNonce = this.#createScopeNonce();
        const rawText = String(raw ?? '');
        const corruptionFingerprint = stableVoiceFailureHash(`${rawText.length}:${rawText.slice(0, 4096)}`);
        const payload = boundedVoiceClientFailurePayload({
            failure_id: voiceClientFailureId(
                'startup',
                'diagnostic_outbox_corrupt',
                this.scopeHash,
                this.scopeNonce,
                corruptionFingerprint,
            ),
            stage: 'startup',
            code: VOICE_CLIENT_FAILURE_OUTBOX_CORRUPT_CODE,
            message: VOICE_CLIENT_FAILURE_OUTBOX_CORRUPT_MESSAGE,
            cause_chain: [],
        }, { allowOutboxDiagnostics: true });
        this.pending.set(payload.failure_id, payload);
        this.#persist();
    }

    #createScopeNonce() {
        return createVoiceClientFailureNonce({
            randomUUID: () => this.nonceFactory(),
        });
    }

    #persist() {
        if (!this.storage || !this.storageKey) return true;
        const state = {
            version: VOICE_CLIENT_FAILURE_OUTBOX_VERSION,
            next_overflow_sequence: this.nextOverflowSequence,
            pending: [...this.pending.values()],
            overflow: this.overflow
                ? {
                    sequence: this.overflow.sequence,
                    nonce: this.overflow.nonce,
                    count: this.overflow.count,
                    fingerprints: [...this.overflow.fingerprints],
                }
                : null,
        };
        try {
            this.storage.setItem(this.storageKey, JSON.stringify(state));
            this.persistenceFailureNotified = false;
            return true;
        } catch (_) {
            this.#notifyPersistenceFailure();
            return false;
        }
    }

    #notifyPersistenceFailure() {
        if (this.persistenceFailureNotified) return;
        this.persistenceFailureNotified = true;
        try {
            this.onPersistenceFailure(Object.freeze({
                code: 'voice_diagnostic_outbox_persist_failed',
                message: 'Browser voice diagnostics could not be saved for reload recovery.',
            }));
        } catch (_) {}
    }

    #scheduleRetry() {
        if (this.retryTimer || this.pending.size === 0) return;
        this.retryTimer = this.setTimeout(() => {
            this.retryTimer = null;
            void this.flush();
        }, this.nextAttemptMs);
    }

    #clearRetryTimer() {
        if (this.retryTimer === null) return;
        this.clearTimeout(this.retryTimer);
        this.retryTimer = null;
    }
}
