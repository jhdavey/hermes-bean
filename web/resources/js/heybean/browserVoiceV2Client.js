const ACTIVE_TURN_STATES = new Set([
    'capturing',
    'awaiting_clarification',
    'accepted',
    'running',
]);

const ACTIVE_JOB_STATES = new Set(['queued', 'running']);

export function assessBrowserVoiceV2Completeness(transcript, { hasHomeLocation = false } = {}) {
    const text = stableText(transcript);
    if (!text) return { decision: 'uncertain' };

    const normalized = text.toLowerCase();
    const creates = /\b(?:create|add|make|set|schedule|save|book|remind me)\b/.test(normalized);
    const complexGeneration = /\b(?:plan|draft|brainstorm|generate|pick|random)\b/.test(normalized);
    const hasClockTime = /\b(?:noon|midnight)\b/.test(normalized)
        || /\b(?:[01]?\d|2[0-3])(?::[0-5]\d)?\s*(?:a\.?m\.?|p\.?m\.?)\b/.test(normalized)
        || /\b(?:one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve)(?:\s+(?:fifteen|thirty|forty[ -]?five))?\s*(?:a\.?m\.?|p\.?m\.?)\b/.test(normalized)
        || /\b(?:[01]?\d|2[0-3]):[0-5]\d\b/.test(normalized);

    if (creates && !complexGeneration && /\b(?:reminder|remind me)\b/.test(normalized)) {
        const hasSubject = /\bremind me to\s+\S+/.test(normalized)
            || /\b(?:reminder|remind me)\b.*\b(?:titled|called|named|about|to)\s+\S+/.test(normalized);
        if (!hasSubject) return { decision: 'incomplete', question: 'What should I remind you about?' };
        if (!hasClockTime) return { decision: 'incomplete', question: 'What time should I remind you?' };
        // The reminder parser owns its payload. "Reminder to do the salt"
        // must not fall through and reinterpret "to do" as a task noun.
        return { decision: 'complete' };
    }
    const calendarCreate = creates
        && !complexGeneration
        && !/\b(?:reminder|remind me|task|todo|to do|notes?)\b/.test(normalized)
        && (/\b(?:calendar|event|meeting|appointment)\b/.test(normalized)
            || /^(?:(?:can|could|would|will) you\s+)?(?:please\s+)?(?:schedule|book)\b/.test(normalized));
    if (calendarCreate) {
        const dateOrClock = '(?:today|tomorrow|tonight|noon|midnight|monday|tuesday|wednesday|thursday|friday|saturday|sunday|january|february|march|april|may|june|july|august|september|october|november|december|\\d)';
        const hasSubject = /\b(?:titled|called|named)\s+\S+/.test(normalized)
            || new RegExp(`\\b(?:create|add|make|set|schedule|save|book)\\s+(?:a |an |the )?(?:calendar )?(?:event|meeting|appointment)\\b\\s+(?!(?:for|on|at)\\b|${dateOrClock})\\S+`).test(normalized)
            || /\b(?:add|put)\s+\S.+?\s+(?:to|on)\s+(?:my\s+|the\s+)?calendar\b/.test(normalized)
            || new RegExp(`^(?:(?:can|could|would|will) you\\s+)?(?:please\\s+)?(?:schedule|book)\\s+(?!${dateOrClock})\\S+`).test(normalized);
        if (!hasSubject) return { decision: 'incomplete', question: 'What should I schedule?' };
        if (!hasClockTime) return { decision: 'incomplete', question: 'What time should I schedule it?' };
    }
    const reschedules = /\b(?:move|reschedule)\b/.test(normalized);
    if (reschedules && /\b(?:reminder|remind me)\b/.test(normalized) && !hasClockTime) {
        return { decision: 'incomplete', question: 'What time should I move the reminder to?' };
    }
    if (reschedules && /\b(?:calendar|event|meeting|appointment)\b/.test(normalized) && !hasClockTime) {
        return { decision: 'incomplete', question: 'What time should I move the calendar event to?' };
    }
    if (creates && !complexGeneration && /\bnotes?\b/.test(normalized)) {
        const hasContent = /\b(?:titled|called|named|that says|saying|with (?:the )?(?:text|content))\s+\S+/.test(normalized)
            || /\b(?:create|add|make|set|save)\s+(?:a |an |the )?note\b\s+(?!for\b|on\b|at\b|today\b|tomorrow\b)\S+/.test(normalized)
            || /\S+\s+(?:as|into)\s+(?:a |an )?note\b/.test(normalized);
        if (!hasContent) return { decision: 'incomplete', question: 'What should the note include?' };
    }
    if (creates && !complexGeneration && /\b(?:task|todo|to do)\b/.test(normalized)
        && !/\b(?:titled|called|named)\s+\S+/.test(normalized)
        && !/\b(?:create|add|make|set)\s+(?:a |an |the )?(?:task|todo|to do)\b\s+\S+/.test(normalized)) {
        return { decision: 'incomplete', question: 'What task should I create?' };
    }
    if (/\b(?:weather|forecast|temperature|rain|storm)\b/.test(normalized)
        && !hasHomeLocation
        && !hasExplicitWeatherLocation(normalized)) {
        return { decision: 'incomplete', question: 'Which location should I check?' };
    }
    if (!isIncompleteCommand(text)) return { decision: 'complete' };
    if (/\b(?:note|notes)\b/.test(normalized)) return { decision: 'incomplete', question: 'What should the note include?' };
    if (/\bremind(?:er)?\b/.test(normalized)) return { decision: 'incomplete', question: 'What should I remind you about, and when?' };
    if (/\b(?:calendar|event|meeting|appointment)\b/.test(normalized)) return { decision: 'incomplete', question: 'What should I schedule, and when?' };
    if (/\b(?:task|todo|to do)\b/.test(normalized)) return { decision: 'incomplete', question: 'What task should I create?' };
    return { decision: 'uncertain' };
}

function hasExplicitWeatherLocation(text) {
    const temporalTokens = new Set([
        'today', 'tomorrow', 'tonight', 'later', 'now', 'noon', 'midnight',
        'morning', 'afternoon', 'evening', 'night', 'week', 'weekend',
    ]);
    for (const match of String(text || '').matchAll(/\b(in|at|near|around|for)\s+([a-z0-9][a-z0-9'-]*)/g)) {
        const preposition = match[1];
        const token = match[2];
        if (temporalTokens.has(token) || /^(?:a\.?m\.?|p\.?m\.?)$/.test(token)) continue;
        if (/^\d{1,2}(?:a\.?m\.?|p\.?m\.?)$/.test(token)) continue;
        if (/^\d+$/.test(token) && !(preposition === 'in' && /^\d{5}(?:-\d{4})?$/.test(token))) continue;
        if (['me', 'here', 'home'].includes(token)) continue;
        return true;
    }
    return false;
}

export function resolveBrowserVoiceV2AdmissionClarification(error, expectedTurnId, messages = []) {
    const turnId = stableText(expectedTurnId);
    const payloadTurnId = stableText(error?.payload?.turn_id);
    if (Number(error?.status) !== 422
        || error?.payload?.code !== 'voice_request_incomplete'
        || !turnId
        || (payloadTurnId && payloadTurnId !== turnId)) {
        return null;
    }

    return {
        turnId,
        question: stableText(error?.payload?.question || error?.message || 'What detail should I use?'),
        messages: normalizedObjects(messages).filter((message) => {
            const messageTurnId = stableText(message?.metadata?.client_turn_id || message?.turn_id);
            return messageTurnId !== turnId;
        }),
    };
}

function isIncompleteCommand(text) {
    const normalized = stableText(text)
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

export class BrowserVoiceV2Client {
    constructor(options = {}) {
        if (typeof options.request !== 'function') {
            throw new TypeError('BrowserVoiceV2Client requires a request function.');
        }
        this.request = options.request;
        this.onSnapshot = typeof options.onSnapshot === 'function' ? options.onSnapshot : () => {};
        this.onError = typeof options.onError === 'function' ? options.onError : () => {};
        this.setTimer = options.setTimer || globalThis.setTimeout?.bind(globalThis);
        this.clearTimer = options.clearTimer || globalThis.clearTimeout?.bind(globalThis);
        this.pollWaitSeconds = positiveInteger(options.pollWaitSeconds, 1);
        this.pollIntervalMs = positiveInteger(options.pollIntervalMs, 350);
        this.retryDelayMs = positiveInteger(options.retryDelayMs, 350);
        this.maxRetryDelayMs = positiveInteger(options.maxRetryDelayMs, 2000);
        this.generation = 0;
        this.sessionId = '';
        this.cursor = 0;
        this.pollTimer = null;
        this.running = false;
        this.failureCount = 0;
    }

    async admit(input = {}) {
        const turnId = stableText(input.turnId || input.turn_id);
        const sessionId = stableText(input.sessionId || input.session_id);
        const transcript = stableText(input.transcript);
        if (!turnId || !sessionId || !transcript) {
            throw new TypeError('Voice admission requires turnId, sessionId, and transcript.');
        }
        return this.request('/assistant/voice/turns', {
            method: 'POST',
            body: {
                turn_id: turnId,
                session_id: numericIdentifier(sessionId),
                transcript,
                timezone: stableText(input.timezone),
                location_context: input.locationContext || input.location_context || null,
                controller_generation: nonNegativeInteger(input.controllerGeneration || input.controller_generation),
                provider_connection_generation: nonNegativeInteger(
                    input.providerConnectionGeneration
                    || input.provider_connection_generation
                    || input.connectionGeneration
                    || input.connection_generation,
                ),
                transcript_timing: input.transcriptTiming || input.transcript_timing || {},
                conversation_context: normalizedConversationContext(
                    input.conversationContext || input.conversation_context,
                ),
                client_context: input.clientContext || input.client_context || {},
            },
            timeoutMs: positiveInteger(input.timeoutMs, 8000),
        });
    }

    async cancel(input = {}) {
        const sessionId = stableText(input.sessionId || input.session_id || this.sessionId);
        const turnId = stableText(input.turnId || input.turn_id);
        const jobId = stableText(input.jobId || input.job_id);
        const all = input.all === true;
        if (!sessionId || (!turnId && !jobId && !all)) {
            throw new TypeError('Voice cancellation requires a session and a turn, job, or all selector.');
        }
        return this.request('/assistant/voice/cancellations', {
            method: 'POST',
            body: {
                session_id: numericIdentifier(sessionId),
                ...(turnId ? { turn_id: turnId } : {}),
                ...(jobId ? { job_id: numericIdentifier(jobId) } : {}),
                ...(all ? { all: true } : {}),
            },
            timeoutMs: positiveInteger(input.timeoutMs, 5000),
        });
    }

    async markDelivery(input = {}) {
        const turnId = stableText(input.turnId || input.turn_id);
        const event = stableText(input.event);
        const sessionId = stableText(input.sessionId || input.session_id || this.sessionId);
        const supportedEvents = [
            'acknowledgement_started',
            'final_text_delivered',
            'final_audio_started',
            'playback_started',
            'playback_finished',
            'playback_stopped',
            'potential_interruption',
            'interruption_confirmed',
            'interruption_rejected',
        ];
        if (!turnId || !sessionId || !supportedEvents.includes(event)) {
            throw new TypeError('Voice delivery requires a session, turn id, and supported event.');
        }
        return this.request(`/assistant/voice/turns/${encodeURIComponent(turnId)}/delivery`, {
            method: 'POST',
            body: {
                session_id: numericIdentifier(sessionId),
                event,
                timing: input.timing || {},
            },
            timeoutMs: positiveInteger(input.timeoutMs, 5000),
        });
    }

    async snapshot(sessionId = this.sessionId, options = {}) {
        const normalizedSessionId = stableText(sessionId);
        if (!normalizedSessionId) throw new TypeError('Voice state requires a session id.');
        const params = new URLSearchParams({
            session_id: String(numericIdentifier(normalizedSessionId)),
            cursor: String(nonNegativeInteger(options.cursor ?? options.after ?? this.cursor)),
            wait: String(nonNegativeInteger(options.wait ?? 0)),
        });
        return this.request(`/assistant/voice/state?${params.toString()}`, {
            timeoutMs: positiveInteger(options.timeoutMs, options.wait ? 5000 : 3000),
        });
    }

    start(sessionId, options = {}) {
        const normalizedSessionId = stableText(sessionId);
        if (!normalizedSessionId) throw new TypeError('Voice state polling requires a session id.');
        this.stop();
        this.running = true;
        this.sessionId = normalizedSessionId;
        this.cursor = nonNegativeInteger(options.cursor);
        this.failureCount = 0;
        const generation = ++this.generation;
        this.#poll(generation, true);
        return generation;
    }

    stop() {
        this.running = false;
        this.generation += 1;
        if (this.pollTimer !== null && this.clearTimer) this.clearTimer(this.pollTimer);
        this.pollTimer = null;
        return this.generation;
    }

    isCurrent(generation) {
        return this.running && generation === this.generation;
    }

    async #poll(generation, initial) {
        if (!this.isCurrent(generation)) return;
        try {
            const snapshot = await this.snapshot(this.sessionId, {
                after: this.cursor,
                wait: initial ? 0 : this.pollWaitSeconds,
                timeoutMs: initial ? 3000 : (this.pollWaitSeconds + 3) * 1000,
            });
            if (!this.isCurrent(generation)) return;
            const projection = normalizeVoiceV2Snapshot(snapshot);
            this.cursor = Math.max(this.cursor, projection.cursor);
            this.failureCount = 0;
            this.onSnapshot(projection, { initial, generation });
            this.#schedule(generation, this.pollIntervalMs, false);
        } catch (error) {
            if (!this.isCurrent(generation)) return;
            this.failureCount += 1;
            this.onError(error, { generation, failureCount: this.failureCount });
            const delay = Math.min(
                this.maxRetryDelayMs,
                this.retryDelayMs * (2 ** Math.min(this.failureCount - 1, 4)),
            );
            this.#schedule(generation, delay, false);
        }
    }

    #schedule(generation, delay, initial) {
        if (!this.isCurrent(generation) || !this.setTimer) return;
        this.pollTimer = this.setTimer(() => {
            this.pollTimer = null;
            this.#poll(generation, initial);
        }, Math.max(0, delay));
    }
}

function normalizedConversationContext(value) {
    const mode = stableText(value?.mode);
    return {
        mode: mode === 'contextual_follow_up' ? mode : 'new_conversation',
        epoch: nonNegativeInteger(value?.epoch),
    };
}

/**
 * Tracks ambiguous admissions independently by stable turn ID. Beginning a
 * newer turn cannot supersede recovery for an older turn; only a new attempt
 * for the same stable ID replaces its scope.
 */
export class BrowserVoiceAdmissionRegistryV2 {
    constructor() {
        this.scopes = new Map();
        this.sequence = 0;
    }

    begin(turnId) {
        const normalizedTurnId = stableText(turnId);
        if (!normalizedTurnId) throw new TypeError('Admission scope requires a stable turn id.');
        this.sequence += 1;
        const scope = Object.freeze({ turnId: normalizedTurnId, token: this.sequence });
        this.scopes.set(normalizedTurnId, scope);
        return scope;
    }

    isCurrent(scope) {
        return Boolean(scope?.turnId && this.scopes.get(scope.turnId) === scope);
    }

    finish(scope) {
        if (!this.isCurrent(scope)) return false;
        this.scopes.delete(scope.turnId);
        return true;
    }
}

/**
 * Idempotent client receipt helper. A receipt becomes confirmed only after
 * the delivery POST resolves; failures clear the in-flight slot so the next
 * snapshot or explicit retry can send the same server-idempotent marker.
 */
export function deliverBrowserVoiceV2ReceiptOnce({ key, confirmed, inFlight, deliver } = {}) {
    const receiptKey = stableText(key);
    if (!receiptKey || !(confirmed instanceof Set) || !(inFlight instanceof Map) || typeof deliver !== 'function') {
        throw new TypeError('Delivery receipt requires a key, confirmed set, in-flight map, and delivery function.');
    }
    if (confirmed.has(receiptKey)) return Promise.resolve(false);
    if (inFlight.has(receiptKey)) return inFlight.get(receiptKey);

    const request = Promise.resolve()
        .then(() => deliver())
        .then(() => {
            confirmed.add(receiptKey);
            return true;
        })
        .finally(() => {
            if (inFlight.get(receiptKey) === request) inFlight.delete(receiptKey);
        });
    inFlight.set(receiptKey, request);
    return request;
}

/**
 * Reconciles an ambiguous admission without ever changing its stable turn ID.
 * The same idempotent POST is attempted once, followed by bounded state reads.
 */
export async function recoverBrowserVoiceV2Admission(options = {}) {
    const client = options.client;
    const admissionInput = { ...(options.admissionInput || {}) };
    const turnId = stableText(options.turnId || admissionInput.turnId || admissionInput.turn_id);
    const sessionId = stableText(options.sessionId || admissionInput.sessionId || admissionInput.session_id);
    if (!client || typeof client.admit !== 'function' || typeof client.snapshot !== 'function') {
        throw new TypeError('Admission recovery requires a voice client.');
    }
    if (!turnId || !sessionId) throw new TypeError('Admission recovery requires a stable turn and session id.');

    const clock = typeof options.clock === 'function' ? options.clock : () => Date.now();
    const sleep = typeof options.sleep === 'function'
        ? options.sleep
        : (delayMs) => new Promise((resolve) => globalThis.setTimeout(resolve, delayMs));
    const isCurrent = typeof options.isCurrent === 'function' ? options.isCurrent : () => true;
    const deadlineMs = positiveInteger(options.deadlineMs, 3000);
    const retryTimeoutMs = positiveInteger(options.retryTimeoutMs, 1200);
    const snapshotTimeoutMs = positiveInteger(options.snapshotTimeoutMs, 900);
    const pollIntervalMs = positiveInteger(options.pollIntervalMs, 200);
    const maxSnapshotAttempts = positiveInteger(options.maxSnapshotAttempts, 12);
    const deadlineAt = clock() + deadlineMs;
    let lastError = options.initialError || null;
    let snapshotAttempts = 0;

    const resultForProjection = (payload, source) => {
        const projection = normalizeVoiceV2Snapshot(payload);
        if (!projection.turns.some((turn) => turn.turnId === turnId)) return null;
        return {
            status: isCurrent() ? 'recovered' : 'stale',
            turnId,
            source,
            projection,
            error: lastError,
        };
    };
    const staleResult = (projection = null) => ({
        status: 'stale',
        turnId,
        source: 'superseded',
        projection,
        error: lastError,
    });

    if (!isCurrent()) return staleResult();

    try {
        const retried = await client.admit({
            ...admissionInput,
            turnId,
            sessionId,
            timeoutMs: Math.min(retryTimeoutMs, Math.max(1, deadlineAt - clock())),
        });
        const recovered = resultForProjection(retried, 'idempotent_retry');
        if (recovered) return recovered;
    } catch (error) {
        lastError = error;
        if (Number(error?.status) === 422) {
            return { status: 'rejected', turnId, source: 'idempotent_retry', projection: null, error };
        }
    }

    if (!isCurrent()) return staleResult();

    while (clock() < deadlineAt && snapshotAttempts < maxSnapshotAttempts) {
        snapshotAttempts += 1;
        try {
            const snapshot = await client.snapshot(sessionId, {
                cursor: 0,
                timeoutMs: Math.min(snapshotTimeoutMs, Math.max(1, deadlineAt - clock())),
            });
            const recovered = resultForProjection(snapshot, 'snapshot');
            if (recovered) return recovered;
        } catch (error) {
            lastError = error;
        }

        if (!isCurrent()) return staleResult();
        const remainingMs = deadlineAt - clock();
        if (remainingMs <= 0 || snapshotAttempts >= maxSnapshotAttempts) break;
        await sleep(Math.min(pollIntervalMs, remainingMs));
        if (!isCurrent()) return staleResult();
    }

    return isCurrent()
        ? { status: 'absent', turnId, source: 'deadline', projection: null, error: lastError }
        : staleResult();
}

export function normalizeVoiceV2Snapshot(payload = {}) {
    const source = payload?.data && typeof payload.data === 'object' ? payload.data : payload;
    const events = normalizedObjects(source?.events).map(normalizeEvent);
    const singularTurn = source?.turn && typeof source.turn === 'object' ? [source.turn] : [];
    const turns = [...singularTurn, ...normalizedObjects(source?.turns)]
        .map((turn) => normalizeTurn(turn, events))
        .filter((turn) => turn.turnId)
        .filter((turn, index, all) => all.findIndex((candidate) => candidate.turnId === turn.turnId) === index);
    const topLevelJobs = normalizedObjects(source?.jobs).map(normalizeJob);
    const nestedJobs = turns.flatMap((turn) => turn.jobs);
    const jobsById = new Map();
    [...topLevelJobs, ...nestedJobs].forEach((job) => {
        if (!job.id) return;
        const current = jobsById.get(job.id);
        if (!current || job.version >= current.version) jobsById.set(job.id, job);
    });
    const messages = normalizedObjects(source?.messages);
    return {
        cursor: nonNegativeInteger(source?.cursor ?? source?.event_cursor),
        turns,
        jobs: [...jobsById.values()],
        messages,
        events,
        eventPageFull: events.length >= 500,
        activeTurns: turns.filter((turn) => ACTIVE_TURN_STATES.has(turn.state)),
        activeJobs: [...jobsById.values()].filter((job) => ACTIVE_JOB_STATES.has(job.status)),
    };
}

function normalizeTurn(turn, events = []) {
    const turnId = stableText(turn?.turn_id || turn?.turnId || turn?.id);
    const finalAudioStarted = Boolean(turn?.final_audio_started ?? turn?.finalAudioStarted)
        || events.some((event) => event.turnId === turnId && (
            event.type === 'final_audio_started'
            || (event.type === 'playback_started' && event.purpose === 'final')
        ));
    return {
        ...turn,
        turnId,
        state: stableText(turn?.state).toLowerCase(),
        lane: stableText(turn?.lane),
        handler: stableText(turn?.handler),
        version: nonNegativeInteger(turn?.version),
        transcript: stableText(turn?.transcript),
        acknowledgementRequired: Boolean(turn?.acknowledgement_required ?? turn?.acknowledgementRequired),
        acknowledgementText: stableText(turn?.acknowledgement_text || turn?.acknowledgementText),
        finalText: stableText(
            turn?.final_text
            || turn?.finalText
            || turn?.final_message?.content
            || turn?.finalMessage?.content,
        ),
        finalDeliveredAt: stableText(turn?.final_delivered_at || turn?.finalDeliveredAt),
        finalAudioStarted,
        jobs: normalizedObjects(turn?.jobs).map(normalizeJob),
    };
}

function normalizeEvent(event) {
    const payload = event?.payload && typeof event.payload === 'object' ? event.payload : {};
    return {
        ...event,
        turnId: stableText(event?.turn_id || event?.turnId),
        type: stableText(event?.type || event?.event_type).toLowerCase(),
        purpose: stableText(payload?.purpose || event?.purpose).toLowerCase(),
        payload,
    };
}

function normalizeJob(job) {
    return {
        ...job,
        id: stableText(job?.id || job?.job_id || job?.jobId),
        turnId: stableText(job?.turn_id || job?.turnId || job?.voice_turn?.turn_id),
        label: stableText(job?.label || job?.work_label || job?.input || 'Bean work'),
        status: stableText(job?.status).toLowerCase(),
        version: nonNegativeInteger(job?.version || job?.updated_sequence),
    };
}

function normalizedObjects(value) {
    return Array.isArray(value) ? value.filter((item) => item && typeof item === 'object') : [];
}

function stableText(value) {
    return String(value ?? '').trim();
}

function numericIdentifier(value) {
    const number = Number(value);
    return Number.isSafeInteger(number) && number > 0 ? number : stableText(value);
}

function nonNegativeInteger(value) {
    const number = Number(value);
    return Number.isSafeInteger(number) && number >= 0 ? number : 0;
}

function positiveInteger(value, fallback) {
    const number = Number(value);
    return Number.isSafeInteger(number) && number > 0 ? number : fallback;
}
