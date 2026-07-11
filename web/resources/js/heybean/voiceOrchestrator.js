export const VOICE_SESSION_STATES = Object.freeze({
    OFF: 'off',
    STARTING: 'starting',
    WAKE_ONLY: 'wake_only',
    ACTIVE: 'active',
    RECOVERING: 'recovering',
    FAILED: 'failed',
});

export const VOICE_TURN_PHASES = Object.freeze({
    IDLE: 'idle',
    CAPTURING: 'capturing',
    TRANSCRIBING: 'transcribing',
    QUEUED: 'queued',
    DISPATCHING: 'dispatching',
    WORKING: 'working',
    SPEAKING: 'speaking',
    FOLLOW_UP: 'follow_up',
    RECOVERING: 'recovering',
    TERMINAL: 'terminal',
});

export const VOICE_TERMINAL_OUTCOMES = Object.freeze([
    'completed',
    'interrupted',
    'cancelled',
    'failed',
    'timed_out',
    'superseded',
]);

const ACTIVE_SESSION_STATES = new Set([
    VOICE_SESSION_STATES.ACTIVE,
    VOICE_SESSION_STATES.RECOVERING,
]);

function freezeAdmission(accepted, activated, reason, state, epoch) {
    return Object.freeze({ accepted, activated, reason, state, epoch });
}

class VoiceResponseLifecycle {
    constructor(owner, clock, timers) {
        this.owner = owner;
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
            this.owner.transition(VOICE_TURN_PHASES.SPEAKING, 'response.begin', { purpose });
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

    isActive() { return Boolean(this.active); }
    currentClientResponseId() { return this.active?.clientResponseId || ''; }
    bindResponse(responseId, clientResponseId) {
        if (!this.active || String(clientResponseId || '') !== this.active.clientResponseId) return false;
        return this.claimResponse(responseId, true);
    }
    acceptsResponse(responseId) { return this.claimResponse(responseId, false); }
    markAudioStarted(responseId) {
        if (!this.claimResponse(responseId, false)) return false;
        this.active.audioStarted = true;
        this.active.audioStartedAtMs ??= this.clock();
        return true;
    }
    markResponseDone(responseId) {
        if (!this.claimResponse(responseId, false)) return null;
        this.active.responseDone = true;
        return this.active.audioStarted && !this.active.audioStopped ? null : this.finish(responseId);
    }
    markAudioStopped(responseId) {
        if (!this.claimResponse(responseId, false)) return null;
        this.active.audioStopped = true;
        return this.active.responseDone ? this.finish(responseId) : null;
    }
    captureTranscript(transcript) {
        const text = String(transcript || '').trim();
        if (this.active && text) this.active.transcript = text;
    }

    finish(responseId = '') {
        if (!this.active) return null;
        if (responseId && responseId !== this.active.responseId) return null;
        return this.settle(false, 'completed');
    }

    cancel(reason = 'cancelled') {
        if (!this.active) return null;
        return this.settle(true, String(reason || 'cancelled'));
    }

    claimResponse(responseId, allowBind) {
        if (!this.active) return false;
        const id = String(responseId || '');
        if (!id || this.closedResponseIds.has(id)) return false;
        if (this.active.responseId && this.active.responseId !== id) return false;
        if (!this.active.responseId && !allowBind) return false;
        this.active.responseId = id;
        return true;
    }

    settle(cancelled, reason) {
        const active = this.active;
        this.active = null;
        if (active.timeoutId !== null && this.clearTimeout) this.clearTimeout(active.timeoutId);
        if (active.responseId) {
            this.closedResponseIds.add(active.responseId);
            if (this.closedResponseIds.size > 100) this.closedResponseIds.delete(this.closedResponseIds.values().next().value);
        }
        const finishedAtMs = this.clock();
        const result = {
            purpose: active.purpose,
            transcript: active.transcript,
            cancelled,
            reason,
            startedAtMs: active.startedAtMs,
            audioStartedAtMs: active.audioStartedAtMs,
            audioStartLatencyMs: active.audioStartedAtMs === null ? null : Math.max(0, active.audioStartedAtMs - active.startedAtMs),
            responseDurationMs: Math.max(0, finishedAtMs - active.startedAtMs),
        };
        this.owner.transition(
            cancelled ? VOICE_TURN_PHASES.RECOVERING : VOICE_TURN_PHASES.FOLLOW_UP,
            `response.${reason}`,
            { purpose: active.purpose },
        );
        active.resolve(result);
        return result;
    }
}

export class VoiceOrchestrator {
    constructor({ clock = () => Date.now(), timers = {}, maxTranscriptIds = 2048, maxEvents = 1000 } = {}) {
        this.clock = clock;
        this.sessionState = VOICE_SESSION_STATES.OFF;
        this.phase = VOICE_TURN_PHASES.IDLE;
        this.connectionGeneration = 0;
        this.epoch = 0;
        this.turnSequence = 0;
        this.activeTurn = null;
        this.backendActive = false;
        this.outputActive = false;
        this.localWakePending = false;
        this.pendingTranscript = '';
        this.turnGuardUntil = 0;
        this.ignoreInputUntil = 0;
        this.queue = [];
        this.terminals = new Map();
        this.transcriptIds = new Set();
        this.transcriptOrigins = new Map();
        this.maxTranscriptIds = Math.max(1, Number(maxTranscriptIds) || 2048);
        this.maxEvents = Math.max(10, Number(maxEvents) || 1000);
        this.events = [];
        this.responses = new VoiceResponseLifecycle(this, clock, timers);
    }

    snapshot() {
        return Object.freeze({
            sessionState: this.sessionState,
            phase: this.phase,
            connectionGeneration: this.connectionGeneration,
            epoch: this.epoch,
            activeTurn: this.activeTurn ? { ...this.activeTurn } : null,
            backendActive: this.backendActive,
            responseActive: this.responseActive,
            localWakePending: this.localWakePending,
            queueLength: this.queue.length,
        });
    }

    get responseActive() { return this.outputActive || this.responses.isActive(); }
    set responseActive(value) {
        this.outputActive = Boolean(value);
        this.record(this.outputActive ? 'response.active' : 'response.inactive');
    }

    transition(phase, event, detail = {}) {
        const previous = this.phase;
        this.phase = phase;
        this.record(event, { previousPhase: previous, nextPhase: phase, ...detail });
        this.assertInvariants();
    }

    record(event, detail = {}) {
        this.events.push(Object.freeze({
            event,
            atMs: this.clock(),
            sessionState: this.sessionState,
            phase: this.phase,
            generation: this.connectionGeneration,
            epoch: this.epoch,
            turnId: this.activeTurn?.id || null,
            ...detail,
        }));
        if (this.events.length > this.maxEvents) this.events.splice(0, this.events.length - this.maxEvents);
    }

    drainEvents() {
        return this.events.splice(0);
    }

    start() {
        this.connectionGeneration += 1;
        this.sessionState = VOICE_SESSION_STATES.STARTING;
        this.transition(VOICE_TURN_PHASES.IDLE, 'session.start');
        return this.connectionGeneration;
    }

    connected(generation = this.connectionGeneration) {
        if (generation !== this.connectionGeneration) return false;
        this.sessionState = VOICE_SESSION_STATES.WAKE_ONLY;
        this.transition(VOICE_TURN_PHASES.IDLE, 'session.connected');
        return true;
    }

    fail(reason = 'failed') {
        this.sessionState = VOICE_SESSION_STATES.FAILED;
        this.responses.cancel(reason);
        this.backendActive = false;
        this.localWakePending = false;
        this.pendingTranscript = '';
        this.transition(VOICE_TURN_PHASES.TERMINAL, 'session.failed', { reason });
    }

    capture() { return this.epoch; }
    isActive() { return ACTIVE_SESSION_STATES.has(this.sessionState); }
    isCurrent(epoch) { return epoch === this.epoch; }
    canContinue(epoch) { return this.isActive() && this.isCurrent(epoch); }

    activate() {
        if (!this.isActive()) {
            this.sessionState = VOICE_SESSION_STATES.ACTIVE;
            this.epoch += 1;
            this.transition(VOICE_TURN_PHASES.CAPTURING, 'conversation.activate');
        }
        return this.epoch;
    }

    activateFromLocalWake() {
        const activated = !this.isActive();
        this.activate();
        this.localWakePending = true;
        this.transition(VOICE_TURN_PHASES.CAPTURING, 'wake.detected');
        return freezeAdmission(true, activated, 'local_wake', this.sessionState, this.epoch);
    }

    sleep() {
        if (this.hasPendingWork()) return false;
        this.sessionState = VOICE_SESSION_STATES.WAKE_ONLY;
        this.activeTurn = null;
        this.transition(VOICE_TURN_PHASES.IDLE, 'conversation.sleep');
        return this.epoch;
    }

    stop(reason = 'stopped') {
        this.responses.cancel(reason);
        this.backendActive = false;
        if (this.activeTurn && !this.terminals.has(this.activeTurn.id)) {
            this.terminal(reason === 'superseded' ? 'superseded' : 'cancelled', { reason });
        }
        this.epoch += 1;
        this.sessionState = VOICE_SESSION_STATES.WAKE_ONLY;
        this.localWakePending = false;
        this.pendingTranscript = '';
        this.activeTurn = null;
        this.clearQueue(reason);
        this.transition(VOICE_TURN_PHASES.IDLE, 'conversation.stop', { reason });
        return this.epoch;
    }

    disconnect(reason = 'voice_stopped') {
        this.stop(reason);
        this.connectionGeneration += 1;
        this.sessionState = VOICE_SESSION_STATES.OFF;
        this.transition(VOICE_TURN_PHASES.IDLE, 'session.disconnected', { reason });
        return this.connectionGeneration;
    }

    noteTranscriptOrigin(id) {
        const key = String(id || '').trim();
        if (!key || this.transcriptOrigins.has(key)) return;
        this.transcriptOrigins.set(key, { state: this.isActive() ? 'active' : 'wake_only', epoch: this.epoch });
        this.trimMap(this.transcriptOrigins);
    }

    admitTranscript({ id = '', content, heardWakeWord = false } = {}) {
        const transcript = String(content || '').trim();
        if (!transcript) return freezeAdmission(false, false, 'empty', this.sessionState, this.epoch);
        if (!this.claimTranscript(id)) return freezeAdmission(false, false, 'duplicate', this.sessionState, this.epoch);
        const origin = this.transcriptOrigins.get(String(id || '').trim());
        const currentOrigin = this.isActive() && (!origin || (origin.state === 'active' && origin.epoch === this.epoch));
        let activated = false;
        if (!currentOrigin) {
            if (!heardWakeWord) return freezeAdmission(false, false, 'wake_required', this.sessionState, this.epoch);
            this.activate();
            activated = true;
        }
        this.localWakePending = false;
        if (!this.backendActive && !this.responseActive) this.beginTurn(transcript, id);
        return freezeAdmission(true, activated, 'accepted', this.sessionState, this.epoch);
    }

    supersedeTranscript({ content } = {}) {
        const transcript = String(content || '').trim();
        if (!transcript) return freezeAdmission(false, false, 'empty', this.sessionState, this.epoch);
        if (!this.isActive()) return freezeAdmission(false, false, 'wake_required', this.sessionState, this.epoch);
        if (this.activeTurn && !this.terminals.has(this.activeTurn.id)) this.terminal('superseded');
        this.epoch += 1;
        this.beginTurn(transcript);
        return freezeAdmission(true, false, 'superseded', this.sessionState, this.epoch);
    }

    resumeTranscript({ content, epoch } = {}) {
        const transcript = String(content || '').trim();
        if (!transcript) return freezeAdmission(false, false, 'empty', this.sessionState, this.epoch);
        if (!this.canContinue(epoch)) return freezeAdmission(false, false, 'stale', this.sessionState, this.epoch);
        this.beginTurn(transcript);
        return freezeAdmission(true, false, 'resumed', this.sessionState, this.epoch);
    }

    beginTurn(transcript, transcriptId = '') {
        if (this.activeTurn && !this.terminals.has(this.activeTurn.id) && !this.backendActive && !this.responses.isActive()) {
            this.terminal('superseded');
        }
        this.activeTurn = {
            id: `voice-turn-${++this.turnSequence}`,
            epoch: this.epoch,
            transcript: String(transcript || '').trim(),
            transcriptId: String(transcriptId || ''),
            acceptedAtMs: this.clock(),
        };
        this.transition(VOICE_TURN_PHASES.DISPATCHING, 'turn.accepted');
        return this.activeTurn;
    }

    beginTranscribing(transcriptId = '') {
        this.transition(VOICE_TURN_PHASES.TRANSCRIBING, 'transcript.started', { transcriptId });
    }

    settleTranscript(reason = 'settled') {
        this.localWakePending = false;
        const nextPhase = this.backendActive
            ? VOICE_TURN_PHASES.WORKING
            : this.responseActive
                ? VOICE_TURN_PHASES.SPEAKING
                : this.isActive()
                    ? VOICE_TURN_PHASES.FOLLOW_UP
                    : VOICE_TURN_PHASES.IDLE;
        this.transition(nextPhase, `transcript.${reason}`);
    }

    beginWork() {
        if (!this.activeTurn || this.terminals.has(this.activeTurn.id)) return false;
        this.backendActive = true;
        this.transition(VOICE_TURN_PHASES.WORKING, 'backend.started');
        return true;
    }

    endWork(reason = 'completed') {
        this.backendActive = false;
        if (this.activeTurn && this.terminals.has(this.activeTurn.id)) {
            this.record(`backend.${reason}`);
            return;
        }
        this.transition(this.responses.isActive() ? VOICE_TURN_PHASES.SPEAKING : VOICE_TURN_PHASES.FOLLOW_UP, `backend.${reason}`);
    }

    terminal(outcome, detail = {}) {
        if (!VOICE_TERMINAL_OUTCOMES.includes(outcome)) throw new Error(`Unsupported voice terminal outcome: ${outcome}`);
        const turn = this.activeTurn;
        if (!turn || this.terminals.has(turn.id)) return false;
        this.terminals.set(turn.id, { outcome, atMs: this.clock(), ...detail });
        this.transition(VOICE_TURN_PHASES.TERMINAL, `turn.${outcome}`, detail);
        return true;
    }

    enqueue(item) {
        const queued = {
            id: String(item?.id || `voice-queued-${++this.turnSequence}`),
            epoch: Number(item?.epoch ?? this.epoch),
            transcript: String(item?.transcript || '').trim(),
            enqueuedAtMs: this.clock(),
            ...item,
        };
        if (!queued.transcript) return null;
        this.queue.push(queued);
        this.record('queue.enqueued', { queuedTurnId: queued.id, queueLength: this.queue.length });
        return queued;
    }

    peekQueue() { return this.queue[0] || null; }
    dequeue() {
        const queued = this.queue.shift() || null;
        if (queued) this.record('queue.dequeued', { queuedTurnId: queued.id, queueLength: this.queue.length });
        return queued;
    }
    clearQueue(reason = 'cancelled') {
        const cleared = this.queue.splice(0);
        if (cleared.length) this.record('queue.cleared', { reason, count: cleared.length });
        return cleared;
    }
    hasQueue() { return this.queue.length > 0; }
    hasPendingWork() { return this.backendActive || this.responseActive || this.hasQueue() || this.phase === VOICE_TURN_PHASES.TRANSCRIBING; }

    claimTranscript(id) {
        const key = String(id || '').trim();
        if (!key) return true;
        if (this.transcriptIds.has(key)) return false;
        this.transcriptIds.add(key);
        if (this.transcriptIds.size > this.maxTranscriptIds) this.transcriptIds.delete(this.transcriptIds.values().next().value);
        return true;
    }

    trimMap(map) {
        if (map.size > this.maxTranscriptIds) map.delete(map.keys().next().value);
    }

    assertInvariants() {
        if (this.backendActive && !this.activeTurn) throw new Error('Voice invariant: backend work requires an active turn.');
        if (this.backendActive && this.terminals.has(this.activeTurn.id)) throw new Error('Voice invariant: terminal turns cannot own backend work.');
        if (this.queue.some((item) => !item.transcript)) throw new Error('Voice invariant: queued turns require transcripts.');
        const ids = this.queue.map((item) => item.id);
        if (new Set(ids).size !== ids.length) throw new Error('Voice invariant: queued turn ids must be unique.');
        if (this.sessionState === VOICE_SESSION_STATES.WAKE_ONLY && this.backendActive) {
            throw new Error('Voice invariant: wake-only state cannot own backend work.');
        }
    }
}
