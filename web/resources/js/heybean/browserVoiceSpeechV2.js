export const BROWSER_VOICE_PLAYBACK_STATES = Object.freeze({
    IDLE: 'idle',
    BUFFERING_ACK: 'buffering_ack',
    PLAYING_ACK: 'playing_ack',
    BUFFERING_FINAL: 'buffering_final',
    PLAYING_FINAL: 'playing_final',
    POTENTIALLY_INTERRUPTED: 'potentially_interrupted',
    STOPPED: 'stopped',
});

export const BROWSER_VOICE_SPEECH_PURPOSES = Object.freeze({
    ACKNOWLEDGEMENT: 'acknowledgement',
    FINAL: 'final',
    CLARIFICATION: 'clarification',
    INTERRUPTION: 'interruption',
    CANCELLATION: 'cancellation',
});

const DEFAULT_ACK_GRACE_MS = 350;
const DEFAULT_DUCK_VOLUME = 0.2;

function clean(value) {
    return String(value || '').trim();
}

function isAcknowledgement(item) {
    return item?.purpose === BROWSER_VOICE_SPEECH_PURPOSES.ACKNOWLEDGEMENT;
}

function bufferingState(item) {
    return isAcknowledgement(item)
        ? BROWSER_VOICE_PLAYBACK_STATES.BUFFERING_ACK
        : BROWSER_VOICE_PLAYBACK_STATES.BUFFERING_FINAL;
}

function playingState(item) {
    return isAcknowledgement(item)
        ? BROWSER_VOICE_PLAYBACK_STATES.PLAYING_ACK
        : BROWSER_VOICE_PLAYBACK_STATES.PLAYING_FINAL;
}

/**
 * Minimal adapter boundary for provider- or element-backed audio. The adapter
 * controls one playback handle only; it has no access to server work state.
 */
export class BrowserVoicePlaybackAdapterV2 {
    constructor({ play, setVolume = null, stop = null } = {}) {
        if (typeof play !== 'function') throw new TypeError('A playback play function is required.');
        this.playFunction = play;
        this.volumeFunction = setVolume;
        this.stopFunction = stop;
    }

    play(item, listeners) {
        return this.playFunction(item, listeners);
    }

    setVolume(handle, volume, item) {
        return this.volumeFunction?.(handle, volume, item);
    }

    stop(handle, reason, item) {
        return this.stopFunction?.(handle, reason, item);
    }
}

/**
 * Ephemeral speech ordering only. This is intentionally not a durable request
 * or background-work queue; server state remains authoritative for both.
 */
export class BrowserVoiceSpeechSchedulerV2 {
    constructor({
        playback,
        clock = () => Date.now(),
        timers = {},
        acknowledgementGraceMs = DEFAULT_ACK_GRACE_MS,
        duckVolume = DEFAULT_DUCK_VOLUME,
        onEvent = null,
        maxEvents = 500,
    } = {}) {
        if (!playback || typeof playback.play !== 'function') {
            throw new TypeError('A playback adapter with play() is required.');
        }
        this.playback = playback;
        this.clock = clock;
        this.setTimeout = timers.setTimeout || globalThis.setTimeout?.bind(globalThis);
        this.clearTimeout = timers.clearTimeout || globalThis.clearTimeout?.bind(globalThis);
        this.acknowledgementGraceMs = Math.max(0, Number(acknowledgementGraceMs));
        this.duckVolume = Math.min(1, Math.max(0, Number(duckVolume)));
        this.onEvent = onEvent;
        this.maxEvents = Math.max(10, Number(maxEvents) || 500);

        this.state = BROWSER_VOICE_PLAYBACK_STATES.IDLE;
        this.generation = 1;
        this.sequence = 0;
        this.current = null;
        this.pendingSpeech = [];
        this.graceByTurn = new Map();
        this.finalTurns = new Set();
        this.seenItemIds = new Set();
        this.events = [];
        this.interruptionReturnState = null;
        this.captureBlocked = false;
        this.captureDeferredItemId = '';
    }

    snapshot() {
        return Object.freeze({
            state: this.state,
            generation: this.generation,
            current: this.current ? Object.freeze({ ...this.current.item, started: this.current.started }) : null,
            pending: Object.freeze(this.pendingSpeech.map((entry) => Object.freeze({ ...entry.item }))),
            acknowledgementGraceTurns: Object.freeze([...this.graceByTurn.keys()]),
            captureBlocked: this.captureBlocked,
            captureDeferredItemId: this.captureDeferredItemId || null,
        });
    }

    drainEvents() {
        return this.events.splice(0);
    }

    scheduleAcknowledgement({
        turnId,
        text,
        id = '',
        graceMs = this.acknowledgementGraceMs,
        priority = 0,
        metadata = {},
    } = {}) {
        const turn = clean(turnId);
        const content = clean(text);
        if (!turn || !content || this.finalTurns.has(turn) || this.graceByTurn.has(turn)) return false;
        if (this.#hasPurpose(turn, BROWSER_VOICE_SPEECH_PURPOSES.ACKNOWLEDGEMENT)) return false;

        const item = this.#createItem({
            id: clean(id) || `${turn}:ack`,
            turnId: turn,
            text: content,
            purpose: BROWSER_VOICE_SPEECH_PURPOSES.ACKNOWLEDGEMENT,
            priority,
            metadata,
        });
        if (!item) return false;

        const delayMs = Math.max(0, Number(graceMs));
        const generation = this.generation;
        const handle = this.setTimeout?.(() => {
            const pending = this.graceByTurn.get(turn);
            if (!pending || pending.handle !== handle || pending.generation !== this.generation) return;
            this.graceByTurn.delete(turn);
            this.#record('acknowledgement.grace_elapsed', { turnId: turn, itemId: item.id });
            this.#enqueue(item);
        }, delayMs);
        this.graceByTurn.set(turn, { handle, item, generation });
        this.#record('acknowledgement.scheduled', { turnId: turn, itemId: item.id, graceMs: delayMs });
        return true;
    }

    finalReady({ turnId, text, id = '', priority = 0, metadata = {} } = {}) {
        const turn = clean(turnId);
        const content = clean(text);
        if (!turn || !content || this.finalTurns.has(turn)) return false;

        const item = this.#createItem({
            id: clean(id) || `${turn}:final`,
            turnId: turn,
            text: content,
            purpose: BROWSER_VOICE_SPEECH_PURPOSES.FINAL,
            priority,
            metadata,
        });
        if (!item) return false;
        this.finalTurns.add(turn);

        const grace = this.graceByTurn.get(turn);
        if (grace) {
            this.clearTimeout?.(grace.handle);
            this.graceByTurn.delete(turn);
            this.#record('acknowledgement.suppressed', { turnId: turn, itemId: grace.item.id, reason: 'fast_final' });
        }

        this.pendingSpeech = this.pendingSpeech.filter((entry) => {
            const suppress = entry.item.turnId === turn && isAcknowledgement(entry.item);
            if (suppress) this.#record('acknowledgement.suppressed', {
                turnId: turn,
                itemId: entry.item.id,
                reason: 'final_before_start',
            });
            return !suppress;
        });

        if (this.current?.item.turnId === turn && isAcknowledgement(this.current.item) && !this.current.started) {
            this.#terminateCurrent('final_before_ack_start', { suspend: false, pump: false });
        }

        this.#record('final.ready', { turnId: turn, itemId: item.id });
        this.#enqueue(item);
        return true;
    }

    enqueueSpeech({
        turnId,
        text,
        purpose = BROWSER_VOICE_SPEECH_PURPOSES.FINAL,
        id = '',
        priority = 0,
        metadata = {},
    } = {}) {
        const item = this.#createItem({ id, turnId, text, purpose, priority, metadata });
        if (!item) return false;
        return this.#enqueue(item);
    }

    potentialInterruption(reason = 'potential_speech') {
        if (!this.current?.started || ![
            BROWSER_VOICE_PLAYBACK_STATES.PLAYING_ACK,
            BROWSER_VOICE_PLAYBACK_STATES.PLAYING_FINAL,
        ].includes(this.state)) return false;
        this.interruptionReturnState = this.state;
        this.state = BROWSER_VOICE_PLAYBACK_STATES.POTENTIALLY_INTERRUPTED;
        this.playback.setVolume?.(this.current.handle, this.duckVolume, this.current.item);
        this.#record('playback.ducked', {
            itemId: this.current.item.id,
            turnId: this.current.item.turnId,
            reason,
            volume: this.duckVolume,
        });
        return true;
    }

    rejectInterruption(reason = 'not_meaningful') {
        if (this.state !== BROWSER_VOICE_PLAYBACK_STATES.POTENTIALLY_INTERRUPTED || !this.current) return false;
        this.playback.setVolume?.(this.current.handle, 1, this.current.item);
        this.state = this.interruptionReturnState || playingState(this.current.item);
        this.interruptionReturnState = null;
        this.#record('playback.volume_restored', {
            itemId: this.current.item.id,
            turnId: this.current.item.turnId,
            reason,
            volume: 1,
        });
        return true;
    }

    confirmInterruption(reason = 'meaningful_barge_in') {
        if (!this.current || ![
            BROWSER_VOICE_PLAYBACK_STATES.POTENTIALLY_INTERRUPTED,
            BROWSER_VOICE_PLAYBACK_STATES.PLAYING_ACK,
            BROWSER_VOICE_PLAYBACK_STATES.PLAYING_FINAL,
        ].includes(this.state)) return false;
        this.interruptionReturnState = null;
        return this.#terminateCurrent(reason, { suspend: true, pump: false });
    }

    stopCurrent(reason = 'user_stop') {
        this.interruptionReturnState = null;
        if (!this.current) {
            const deferredItemId = reason === 'wake' ? this.captureDeferredItemId : '';
            if (deferredItemId) {
                const deferred = this.pendingSpeech.find((entry) => entry.item.id === deferredItemId);
                this.pendingSpeech = this.pendingSpeech.filter((entry) => entry.item.id !== deferredItemId);
                this.captureDeferredItemId = '';
                this.state = BROWSER_VOICE_PLAYBACK_STATES.STOPPED;
                this.#record('playback.stopped', {
                    itemId: deferred?.item.id || deferredItemId,
                    turnId: deferred?.item.turnId || null,
                    purpose: deferred?.item.purpose || null,
                    reason,
                    started: false,
                });
                return true;
            }
            this.state = BROWSER_VOICE_PLAYBACK_STATES.STOPPED;
            this.#record('playback.stopped', { itemId: null, turnId: null, reason });
            return false;
        }
        return this.#terminateCurrent(reason, { suspend: true, pump: false });
    }

    /**
     * Prevent provider audio from beginning while the microphone owns the
     * interaction. A buffered item has not been heard, so it is canceled at
     * the provider boundary and returned to this ephemeral speech queue.
     */
    captureStarted(reason = 'capture_started') {
        if (this.captureBlocked) return false;
        this.captureBlocked = true;

        if (this.current && !this.current.started) {
            const buffered = this.current;
            this.captureDeferredItemId = buffered.item.id;
            this.pendingSpeech.push({ item: buffered.item, order: buffered.order });
            this.pendingSpeech.sort((left, right) => right.item.priority - left.item.priority || left.order - right.order);
            this.#terminateCurrent(reason, { suspend: true, pump: false });
            this.#record('playback.deferred_for_capture', {
                itemId: buffered.item.id,
                turnId: buffered.item.turnId,
                purpose: buffered.item.purpose,
                reason,
            });
            return true;
        }

        if (!this.current && this.state === BROWSER_VOICE_PLAYBACK_STATES.IDLE) {
            this.state = BROWSER_VOICE_PLAYBACK_STATES.STOPPED;
        }
        this.#record('playback.capture_blocked', { reason });
        return true;
    }

    captureEnded(reason = 'capture_ended', { resume = true } = {}) {
        if (!this.captureBlocked) return false;
        this.captureBlocked = false;
        this.captureDeferredItemId = '';
        if (resume && !this.current && this.state === BROWSER_VOICE_PLAYBACK_STATES.STOPPED) {
            this.state = BROWSER_VOICE_PLAYBACK_STATES.IDLE;
        }
        this.#record('playback.capture_released', { reason, resumed: resume });
        if (resume) this.#pump();
        return true;
    }

    resume() {
        if (this.state !== BROWSER_VOICE_PLAYBACK_STATES.STOPPED) return false;
        this.state = BROWSER_VOICE_PLAYBACK_STATES.IDLE;
        this.#record('playback.scheduler_resumed');
        this.#pump();
        return true;
    }

    reset(reason = 'reset') {
        this.generation += 1;
        for (const pending of this.graceByTurn.values()) this.clearTimeout?.(pending.handle);
        this.graceByTurn.clear();
        if (this.current) this.#terminateCurrent(reason, { suspend: false, pump: false });
        this.pendingSpeech = [];
        this.finalTurns.clear();
        this.seenItemIds.clear();
        this.current = null;
        this.state = BROWSER_VOICE_PLAYBACK_STATES.IDLE;
        this.interruptionReturnState = null;
        this.captureBlocked = false;
        this.captureDeferredItemId = '';
        this.#record('playback.scheduler_reset', { reason });
    }

    #createItem({ id, turnId, text, purpose, priority, metadata }) {
        const turn = clean(turnId);
        const content = clean(text);
        const speechPurpose = clean(purpose);
        if (!turn || !content || !speechPurpose) return null;
        const itemId = clean(id) || `${turn}:${speechPurpose}:${this.sequence + 1}`;
        if (this.seenItemIds.has(itemId)) return null;
        this.seenItemIds.add(itemId);
        return Object.freeze({
            id: itemId,
            turnId: turn,
            text: content,
            purpose: speechPurpose,
            priority: Number(priority) || 0,
            metadata: Object.freeze({ ...metadata }),
        });
    }

    #hasPurpose(turnId, purpose) {
        if (this.current?.item.turnId === turnId && this.current.item.purpose === purpose) return true;
        return this.pendingSpeech.some((entry) => entry.item.turnId === turnId && entry.item.purpose === purpose);
    }

    #enqueue(item) {
        if (!item) return false;
        this.sequence += 1;
        this.pendingSpeech.push({ item, order: this.sequence });
        this.pendingSpeech.sort((left, right) => right.item.priority - left.item.priority || left.order - right.order);
        this.#record('speech.queued', { itemId: item.id, turnId: item.turnId, purpose: item.purpose });
        this.#pump();
        return true;
    }

    #pump() {
        if (this.captureBlocked || this.current || this.state !== BROWSER_VOICE_PLAYBACK_STATES.IDLE || !this.pendingSpeech.length) return;
        const entry = this.pendingSpeech.shift();
        const token = Symbol(entry.item.id);
        const generation = this.generation;
        this.current = { item: entry.item, order: entry.order, token, generation, handle: null, started: false };
        this.state = bufferingState(entry.item);
        this.#record('playback.buffering', {
            itemId: entry.item.id,
            turnId: entry.item.turnId,
            purpose: entry.item.purpose,
        });

        const listeners = {
            onStart: () => {
                if (!this.#claim(token, generation)) return false;
                if (this.current.started) return false;
                this.current.started = true;
                this.state = playingState(this.current.item);
                this.#record('playback.started', {
                    itemId: this.current.item.id,
                    turnId: this.current.item.turnId,
                    purpose: this.current.item.purpose,
                    metadata: this.current.item.metadata,
                });
                return true;
            },
            onEnd: (reason = 'completed') => this.#finishCurrent(token, generation, clean(reason) || 'completed'),
            onError: (error) => this.#finishCurrent(token, generation, 'playback_error', error),
        };

        try {
            const handle = this.playback.play(entry.item, listeners);
            if (handle && typeof handle.then === 'function') {
                handle.then((resolved) => {
                    if (this.#claim(token, generation)) this.current.handle = resolved;
                }).catch((error) => listeners.onError(error));
            } else if (this.#claim(token, generation)) {
                this.current.handle = handle;
            }
        } catch (error) {
            listeners.onError(error);
        }
    }

    #claim(token, generation) {
        return Boolean(this.current && this.current.token === token && this.current.generation === generation
            && this.generation === generation);
    }

    #finishCurrent(token, generation, reason, error = null) {
        if (!this.#claim(token, generation)) return false;
        const finished = this.current;
        this.current = null;
        this.interruptionReturnState = null;
        this.state = BROWSER_VOICE_PLAYBACK_STATES.IDLE;
        this.#record('playback.finished', {
            itemId: finished.item.id,
            turnId: finished.item.turnId,
            purpose: finished.item.purpose,
            reason,
            metadata: finished.item.metadata,
            error: error ? String(error?.message || error) : null,
        });
        this.#pump();
        return true;
    }

    #terminateCurrent(reason, { suspend, pump }) {
        if (!this.current) return false;
        const stopped = this.current;
        this.current = null;
        this.interruptionReturnState = null;
        this.state = suspend ? BROWSER_VOICE_PLAYBACK_STATES.STOPPED : BROWSER_VOICE_PLAYBACK_STATES.IDLE;
        try {
            this.playback.stop?.(stopped.handle, reason, stopped.item);
        } finally {
            this.#record('playback.stopped', {
                itemId: stopped.item.id,
                turnId: stopped.item.turnId,
                purpose: stopped.item.purpose,
                reason,
                started: stopped.started,
            });
        }
        if (pump && !suspend) this.#pump();
        return true;
    }

    #record(type, detail = {}) {
        const entry = Object.freeze({
            type,
            atMs: this.clock(),
            state: this.state,
            generation: this.generation,
            ...detail,
        });
        this.events.push(entry);
        if (this.events.length > this.maxEvents) this.events.splice(0, this.events.length - this.maxEvents);
        this.onEvent?.(entry, this.snapshot());
    }
}
