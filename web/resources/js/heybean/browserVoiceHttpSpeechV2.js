function clean(value) {
    return String(value || '').trim();
}

/**
 * Plays exactly one server-synthesized Browser Voice v2 speech item.
 * The speech scheduler remains the ordering owner; this class owns only the
 * abortable HTTP request and its corresponding HTMLAudioElement playback.
 */
export class BrowserVoiceHttpSpeechTransportV2 {
    constructor({
        requestAudio,
        createAudio = (url) => new Audio(url),
        createObjectURL = (blob) => URL.createObjectURL(blob),
        revokeObjectURL = (url) => URL.revokeObjectURL(url),
        createAbortController = () => new AbortController(),
        timers = {},
        timeoutMs = 20_000,
        createId = null,
        onEvent = null,
    } = {}) {
        if (typeof requestAudio !== 'function') {
            throw new TypeError('HTTP speech transport requires a requestAudio function.');
        }
        this.requestAudio = requestAudio;
        this.createAudio = createAudio;
        this.createObjectURL = createObjectURL;
        this.revokeObjectURL = revokeObjectURL;
        this.createAbortController = createAbortController;
        this.setTimeout = timers.setTimeout || globalThis.setTimeout?.bind(globalThis);
        this.clearTimeout = timers.clearTimeout || globalThis.clearTimeout?.bind(globalThis);
        this.timeoutMs = Math.max(1, Number(timeoutMs) || 20_000);
        this.createId = createId;
        this.onEvent = onEvent;
        this.sequence = 0;
        this.generation = 1;
        this.current = null;
        this.volume = 1;
    }

    snapshot() {
        return Object.freeze({
            generation: this.generation,
            current: this.current ? Object.freeze({
                transportId: this.current.transportId,
                itemId: this.current.item.id,
                turnId: this.current.item.turnId,
                requested: this.current.requested,
                audioStarted: this.current.audioStarted,
            }) : null,
        });
    }

    play(item, listeners = {}) {
        if (this.current) throw new Error('HTTP speech transport already owns a response.');
        this.sequence += 1;
        const transportId = clean(this.createId?.()) || `browser-voice-speech-${this.generation}-${this.sequence}`;
        const generation = this.generation;
        const handle = Object.freeze({ transportId, generation });
        const current = {
            handle,
            transportId,
            item,
            listeners,
            controller: this.createAbortController(),
            audio: null,
            objectUrl: '',
            timer: null,
            requested: false,
            audioStarted: false,
            settled: false,
        };
        this.current = current;
        current.timer = this.setTimeout?.(() => {
            if (this.current !== current || this.generation !== generation) return;
            current.controller.abort();
            this.#fail(current, new Error('Bean voice playback timed out.'));
        }, this.timeoutMs);

        Promise.resolve(this.requestAudio(item, { signal: current.controller.signal }))
            .then((blob) => {
                if (this.current !== current || this.generation !== generation || current.controller.signal.aborted) return;
                if (!blob || Number(blob.size) <= 0) throw new Error('Bean voice playback returned no audio.');
                current.requested = true;
                current.objectUrl = this.createObjectURL(blob);
                current.audio = this.createAudio(current.objectUrl);
                current.audio.volume = this.volume;
                current.audio.addEventListener('playing', () => this.#started(current), { once: true });
                current.audio.addEventListener('ended', () => this.#complete(current), { once: true });
                current.audio.addEventListener('error', () => {
                    this.#fail(current, new Error('Bean voice playback failed.'));
                }, { once: true });
                this.#record('transport.requested', current);
                return Promise.resolve(current.audio.play()).catch((error) => {
                    this.#fail(current, error instanceof Error ? error : new Error('Bean voice playback could not start.'));
                });
            })
            .catch((error) => {
                if (current.controller.signal.aborted || this.current !== current) return;
                this.#fail(current, error instanceof Error ? error : new Error('Bean voice playback could not be requested.'));
            });

        return handle;
    }

    setVolume(handle, volume) {
        const current = this.current;
        if (!current || !handle || handle.transportId !== current.transportId || handle.generation !== this.generation) {
            return false;
        }
        this.volume = Math.max(0, Math.min(1, Number(volume) || 0));
        if (current.audio) current.audio.volume = this.volume;
        return true;
    }

    stop(handle, reason = 'stopped') {
        const current = this.current;
        if (!current || !handle || handle.transportId !== current.transportId || handle.generation !== this.generation) {
            return false;
        }
        current.controller.abort();
        this.#release(current);
        this.#record('transport.stopped', current, { reason });
        return true;
    }

    reset(reason = 'reset') {
        if (this.current) this.stop(this.current.handle, reason);
        this.generation += 1;
    }

    #started(current) {
        if (this.current !== current || current.audioStarted) return false;
        current.audioStarted = true;
        if (current.listeners.onStart?.() === false) {
            this.stop(current.handle, 'scheduler_rejected_start');
            return false;
        }
        this.#record('transport.audio_started', current);
        return true;
    }

    #complete(current) {
        if (this.current !== current || current.settled) return false;
        current.settled = true;
        this.#release(current);
        current.listeners.onEnd?.('completed');
        this.#record('transport.completed', current);
        return true;
    }

    #fail(current, error) {
        if (this.current !== current || current.settled) return false;
        current.settled = true;
        current.controller.abort();
        this.#release(current);
        current.listeners.onError?.(error);
        this.#record('transport.failed', current, { error: clean(error?.message || error) });
        return true;
    }

    #release(current) {
        if (current.timer !== null && current.timer !== undefined) this.clearTimeout?.(current.timer);
        current.timer = null;
        if (current.audio) {
            try { current.audio.pause(); } catch (_) {}
            try { current.audio.removeAttribute?.('src'); } catch (_) {}
            try { current.audio.load?.(); } catch (_) {}
        }
        if (current.objectUrl) {
            try { this.revokeObjectURL(current.objectUrl); } catch (_) {}
        }
        current.audio = null;
        current.objectUrl = '';
        if (this.current === current) this.current = null;
    }

    #record(type, current, detail = {}) {
        this.onEvent?.(Object.freeze({
            type,
            atMs: Date.now(),
            transportId: current.transportId,
            itemId: current.item.id,
            turnId: current.item.turnId,
            ...detail,
        }));
    }
}
