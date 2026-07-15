const ACTIVE_TURN_STATES = new Set(['pre_admitted', 'awaiting_audio', 'awaiting_clarification', 'accepted', 'running']);
const ACTIVE_JOB_STATES = new Set(['queued', 'running', 'finalizing']);

function text(value) {
    return String(value ?? '').trim();
}

function integer(value, fallback = 0) {
    const number = Number(value);
    return Number.isSafeInteger(number) && number >= 0 ? number : fallback;
}

function objects(value) {
    return Array.isArray(value) ? value.filter((item) => item && typeof item === 'object') : [];
}

function identifier(value) {
    const number = Number(value);
    return Number.isSafeInteger(number) && number > 0 ? number : text(value);
}

function normalizeJob(job = {}) {
    return Object.freeze({
        id: text(job.id || job.job_id || job.jobId),
        turnId: text(job.turn_id || job.turnId || job.voice_turn?.turn_id),
        label: text(job.label || job.work_label || 'Bean work'),
        status: text(job.status).toLowerCase(),
        version: integer(job.version || job.updated_sequence),
        updatedAt: text(job.updated_at || job.updatedAt || job.completed_at || job.completedAt),
    });
}

function normalizeTurn(turn = {}) {
    return Object.freeze({
        turnId: text(turn.turn_id || turn.turnId || turn.id),
        state: text(turn.state).toLowerCase(),
        version: integer(turn.version),
        stopPlayback: Boolean(turn.stop_playback ?? turn.stopPlayback),
        stopPlaybackDirectiveId: text(turn.stop_playback_directive_id || turn.stopPlaybackDirectiveId),
        closeAfterResponse: Boolean(turn.close_after_response ?? turn.closeAfterResponse),
        jobs: objects(turn.jobs).map(normalizeJob),
    });
}

function normalizeSpeechAuthorization(item = {}) {
    const metadata = item.metadata && typeof item.metadata === 'object' ? item.metadata : item;
    return Object.freeze({
        authorizationId: text(
            item.authorization_id
            || item.authorizationId
            || item.id
            || metadata.authorization_id
            || metadata.authorizationId,
        ),
        turnId: text(item.turn_id || item.turnId || metadata.turn_id || metadata.turnId),
        speechItemId: text(
            item.speech_item_id
            || item.speechItemId
            || metadata.speech_item_id
            || metadata.speechItemId,
        ),
        purpose: text(item.purpose || metadata.purpose).toLowerCase(),
        realtimeSessionId: text(
            item.realtime_session_id
            || item.realtimeSessionId
            || metadata.realtime_session_id
            || metadata.realtimeSessionId,
        ),
        controllerGeneration: integer(
            item.controller_generation
            ?? item.controllerGeneration
            ?? metadata.controller_generation
            ?? metadata.controllerGeneration,
            -1,
        ),
        providerConnectionGeneration: integer(
            item.provider_connection_generation
            ?? item.providerConnectionGeneration
            ?? metadata.provider_connection_generation
            ?? metadata.providerConnectionGeneration,
            -1,
        ),
        approvedTextSha256: text(
            item.approved_text_sha256
            || item.approvedTextSha256
            || metadata.approved_text_sha256
            || metadata.approvedTextSha256,
        ).toLowerCase(),
        playbackCapability: text(
            item.playback_capability
            || item.playbackCapability
            || metadata.playback_capability
            || metadata.playbackCapability,
        ),
        expiresAt: text(item.expires_at || item.expiresAt || metadata.expires_at || metadata.expiresAt),
    });
}

function normalizeEvent(event = {}) {
    const payload = event.payload && typeof event.payload === 'object' ? event.payload : {};
    const metadata = event.metadata && typeof event.metadata === 'object' ? event.metadata : {};
    const nested = [
        payload.playback_authorization,
        payload.speech_authorization,
        payload.timing,
        metadata.playback_authorization,
        metadata.speech_authorization,
        metadata.timing,
    ].filter((value) => value && typeof value === 'object');
    const safeSource = Object.assign({}, payload, metadata, ...nested);
    const safePayload = {
        authorization_id: text(safeSource.authorization_id || safeSource.authorizationId),
        turn_id: text(safeSource.turn_id || safeSource.turnId),
        speech_item_id: text(safeSource.speech_item_id || safeSource.speechItemId),
        purpose: text(safeSource.purpose).toLowerCase(),
        realtime_session_id: text(safeSource.realtime_session_id || safeSource.realtimeSessionId),
        controller_generation: integer(
            safeSource.controller_generation ?? safeSource.controllerGeneration,
            -1,
        ),
        provider_connection_generation: integer(
            safeSource.provider_connection_generation ?? safeSource.providerConnectionGeneration,
            -1,
        ),
        approved_text_sha256: text(
            safeSource.approved_text_sha256 || safeSource.approvedTextSha256,
        ).toLowerCase(),
        playback_capability: text(safeSource.playback_capability || safeSource.playbackCapability),
        expires_at: text(safeSource.expires_at || safeSource.expiresAt),
        reason: text(safeSource.reason),
        directive_id: text(safeSource.directive_id || safeSource.directiveId),
    };
    return Object.freeze({
        id: integer(event.id || event.event_id),
        type: text(event.type || event.event_type).toLowerCase(),
        turnId: text(event.turn_id || event.turnId || safeSource.turn_id || safeSource.turnId),
        payload: Object.freeze(safePayload),
    });
}

function eventSpeechAuthorizations(events) {
    return events
        .filter((event) => ['speech_authorized', 'realtime_speech_authorized', 'playback_authorized'].includes(event.type))
        .map((event) => normalizeSpeechAuthorization({ ...event.payload, turn_id: event.turnId }))
        .filter((item) => item.speechItemId);
}

export function normalizeBeanVoiceProjection(payload = {}) {
    const source = payload?.data && typeof payload.data === 'object' ? payload.data : payload;
    const projection = source?.projection && typeof source.projection === 'object' ? source.projection : source;
    const events = objects(projection.events).map(normalizeEvent);
    const turns = [
        ...(projection.turn && typeof projection.turn === 'object' ? [projection.turn] : []),
        ...objects(projection.turns),
    ].map(normalizeTurn).filter((turn) => turn.turnId);
    const jobsById = new Map();
    [...objects(projection.jobs).map(normalizeJob), ...turns.flatMap((turn) => turn.jobs)].forEach((job) => {
        if (!job.id) return;
        const previous = jobsById.get(job.id);
        if (!previous || job.version >= previous.version) jobsById.set(job.id, job);
    });
    const explicitAuthorizations = objects(
        projection.speech_authorizations
        || projection.speechAuthorizations
        || projection.playback_authorizations
        || projection.playbackAuthorizations,
    ).map(normalizeSpeechAuthorization);
    const authorizationsById = new Map();
    [...explicitAuthorizations, ...eventSpeechAuthorizations(events)].forEach((authorization) => {
        if (authorization.speechItemId) authorizationsById.set(authorization.speechItemId, authorization);
    });
    const invalidations = objects(
        projection.dashboard_invalidations
        || projection.dashboardInvalidations,
    ).map((item) => Object.freeze({
        id: text(item.id || item.invalidation_id || item.invalidationId),
        resource: text(item.resource || item.type),
    }));

    return Object.freeze({
        cursor: integer(projection.cursor ?? projection.event_cursor),
        turns: Object.freeze(turns),
        jobs: Object.freeze([...jobsById.values()]),
        events: Object.freeze(events),
        speechAuthorizations: Object.freeze([...authorizationsById.values()]),
        dashboardInvalidations: Object.freeze(invalidations),
        activeTurns: Object.freeze(turns.filter((turn) => ACTIVE_TURN_STATES.has(turn.state))),
        activeJobs: Object.freeze([...jobsById.values()].filter((job) => ACTIVE_JOB_STATES.has(job.status))),
    });
}

export function parseBeanVoiceSseChunk(buffer, chunk, onEvent) {
    const normalized = `${buffer || ''}${String(chunk || '').replace(/\r\n/g, '\n')}`;
    const frames = normalized.split('\n\n');
    const remainder = frames.pop() || '';
    frames.forEach((frame) => {
        let id = '';
        let event = 'message';
        const data = [];
        frame.split('\n').forEach((line) => {
            if (!line || line.startsWith(':')) return;
            if (line.startsWith('id:')) id = line.slice(3).trim();
            else if (line.startsWith('event:')) event = line.slice(6).trim() || 'message';
            else if (line.startsWith('data:')) data.push(line.slice(5).replace(/^ /, ''));
        });
        if (!data.length) return;
        let payload;
        try {
            payload = JSON.parse(data.join('\n'));
        } catch (_) {
            return;
        }
        onEvent(Object.freeze({ id, event, payload }));
    });
    return remainder;
}

/**
 * Authenticated fetch-SSE is the primary projection transport. The same
 * reducer consumes SSE and long-poll recovery so overlap cannot duplicate a
 * speech authorization, turn transition, job update, or dashboard refresh.
 */
export class BeanVoiceProjectionStream {
    constructor({
        request,
        openStream,
        onProjection = () => {},
        onError = () => {},
        timers = {},
        pollWaitSeconds = 1,
        retryDelayMs = 350,
        maxRetryDelayMs = 2500,
    } = {}) {
        if (typeof request !== 'function' || typeof openStream !== 'function') {
            throw new TypeError('Bean voice projection requires request and authenticated stream functions.');
        }
        this.request = request;
        this.openStream = openStream;
        this.onProjection = onProjection;
        this.onError = onError;
        this.setTimeout = timers.setTimeout || globalThis.setTimeout?.bind(globalThis);
        this.clearTimeout = timers.clearTimeout || globalThis.clearTimeout?.bind(globalThis);
        this.pollWaitSeconds = Math.max(0, integer(pollWaitSeconds, 1));
        this.retryDelayMs = Math.max(50, integer(retryDelayMs, 350));
        this.maxRetryDelayMs = Math.max(this.retryDelayMs, integer(maxRetryDelayMs, 2500));
        this.sessionId = '';
        this.cursor = 0;
        this.generation = 0;
        this.running = false;
        this.abortController = null;
        this.retryTimer = null;
        this.retryResolve = null;
        this.failureCount = 0;
        this.turnVersions = new Map();
        this.jobVersions = new Map();
        this.speechItemIds = new Set();
        this.invalidationIds = new Set();
    }

    start(sessionId, { cursor = 0 } = {}) {
        const normalized = text(sessionId);
        if (!normalized) throw new TypeError('Bean voice projection requires a conversation session id.');
        this.stop();
        this.running = true;
        this.sessionId = normalized;
        this.cursor = integer(cursor);
        this.failureCount = 0;
        this.turnVersions.clear();
        this.jobVersions.clear();
        this.speechItemIds.clear();
        this.invalidationIds.clear();
        const generation = ++this.generation;
        void this.#run(generation);
        return generation;
    }

    stop() {
        this.running = false;
        this.generation += 1;
        this.abortController?.abort?.();
        this.abortController = null;
        if (this.retryTimer !== null) this.clearTimeout?.(this.retryTimer);
        this.retryTimer = null;
        this.retryResolve?.();
        this.retryResolve = null;
    }

    async snapshot({ wait = 0, cursor = this.cursor, signal = null } = {}) {
        if (!this.sessionId) throw new TypeError('Bean voice projection is not attached to a session.');
        const params = new URLSearchParams({
            session_id: String(identifier(this.sessionId)),
            cursor: String(integer(cursor)),
            wait: String(integer(wait)),
        });
        return this.request(`/assistant/voice/state?${params.toString()}`, {
            signal,
            timeoutMs: wait ? (integer(wait) + 3) * 1000 : 3000,
        });
    }

    isCurrent(generation) {
        return this.running && generation === this.generation;
    }

    async #run(generation) {
        while (this.isCurrent(generation)) {
            this.abortController = new AbortController();
            try {
                await this.#consumeStream(generation, this.abortController.signal);
                if (!this.isCurrent(generation)) return;
                // The server intentionally bounds each authenticated SSE
                // response. A clean EOF is a normal cursor-preserving renew,
                // not a transport failure and not a reason to poll.
                this.failureCount = 0;
            } catch (error) {
                if (!this.isCurrent(generation) || error?.name === 'AbortError') return;
                this.failureCount += 1;
                this.onError(error, Object.freeze({
                    generation,
                    failureCount: this.failureCount,
                    transport: 'sse',
                }));
                await this.#recoverByPolling(generation);
                if (!this.isCurrent(generation)) return;
                const delay = Math.min(
                    this.maxRetryDelayMs,
                    this.retryDelayMs * (2 ** Math.min(this.failureCount - 1, 4)),
                );
                await this.#wait(generation, delay);
            }
        }
    }

    async #consumeStream(generation, signal) {
        const params = new URLSearchParams({
            session_id: String(identifier(this.sessionId)),
            cursor: String(this.cursor),
        });
        const response = await this.openStream(`/assistant/voice/stream?${params.toString()}`, {
            signal,
            cursor: this.cursor,
        });
        if (!response?.ok) {
            const error = new Error(`Bean voice stream failed with status ${Number(response?.status || 0)}.`);
            error.status = Number(response?.status || 0);
            throw error;
        }
        if (!/text\/event-stream/i.test(String(response.headers?.get?.('Content-Type') || ''))) {
            throw new Error('Bean voice stream did not return event-stream content.');
        }
        const reader = response.body?.getReader?.();
        if (!reader) throw new Error('Bean voice stream did not provide a readable body.');
        const decoder = new TextDecoder();
        let buffer = '';
        try {
            while (this.isCurrent(generation)) {
                const { done, value } = await reader.read();
                if (done) break;
                buffer = parseBeanVoiceSseChunk(buffer, decoder.decode(value, { stream: true }), (frame) => {
                    if (!this.isCurrent(generation)) return;
                    const eventCursor = integer(frame.id || frame.payload?.cursor || frame.payload?.event_cursor);
                    this.#apply(frame.payload, {
                        generation,
                        transport: 'sse',
                        event: frame.event,
                        cursor: eventCursor,
                    });
                });
            }
        } finally {
            try { await reader.cancel(); } catch (_) {}
        }
    }

    async #recoverByPolling(generation) {
        try {
            const payload = await this.snapshot({
                wait: this.pollWaitSeconds,
                cursor: this.cursor,
                signal: this.abortController?.signal || null,
            });
            if (!this.isCurrent(generation)) return;
            this.#apply(payload, { generation, transport: 'poll', recovery: true });
        } catch (error) {
            if (!this.isCurrent(generation) || error?.name === 'AbortError') return;
            this.onError(error, Object.freeze({
                generation,
                failureCount: this.failureCount,
                transport: 'poll',
            }));
        }
    }

    #apply(payload, context) {
        const projection = normalizeBeanVoiceProjection(payload);
        const eventCursor = integer(context.cursor || projection.cursor);
        if (eventCursor && eventCursor < this.cursor) return false;
        this.cursor = Math.max(this.cursor, eventCursor, projection.cursor);

        const turns = projection.turns.filter((turn) => {
            const previous = integer(this.turnVersions.get(turn.turnId));
            if (this.turnVersions.has(turn.turnId) && turn.version <= previous) return false;
            this.turnVersions.set(turn.turnId, Math.max(previous, turn.version));
            return true;
        });
        const jobs = projection.jobs.filter((job) => {
            const previous = integer(this.jobVersions.get(job.id));
            if (this.jobVersions.has(job.id) && job.version <= previous) return false;
            this.jobVersions.set(job.id, Math.max(previous, job.version));
            return true;
        });
        const speechAuthorizations = projection.speechAuthorizations.filter((authorization) => {
            if (this.speechItemIds.has(authorization.speechItemId)) return false;
            this.speechItemIds.add(authorization.speechItemId);
            return true;
        });
        const dashboardInvalidations = projection.dashboardInvalidations.filter((invalidation) => {
            const key = invalidation.id || `${this.cursor}:${invalidation.resource}`;
            if (this.invalidationIds.has(key)) return false;
            this.invalidationIds.add(key);
            return true;
        });
        this.failureCount = 0;
        this.onProjection(Object.freeze({
            ...projection,
            turns: Object.freeze(turns),
            jobs: Object.freeze(jobs),
            speechAuthorizations: Object.freeze(speechAuthorizations),
            dashboardInvalidations: Object.freeze(dashboardInvalidations),
        }), Object.freeze({ ...context, cursor: this.cursor }));
        return true;
    }

    #wait(generation, delayMs) {
        if (!this.isCurrent(generation) || !this.setTimeout) return Promise.resolve();
        return new Promise((resolve) => {
            this.retryResolve = resolve;
            this.retryTimer = this.setTimeout(() => {
                this.retryTimer = null;
                this.retryResolve = null;
                resolve();
            }, Math.max(0, delayMs));
        });
    }
}
