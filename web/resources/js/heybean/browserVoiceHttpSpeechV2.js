function clean(value) {
    return String(value || '').trim();
}

/**
 * Plays exactly one server-synthesized Browser Voice v2 speech item.
 * The speech scheduler remains the ordering owner; this class owns only the
 * abortable HTTP stream and its corresponding audio nodes. Raw PCM is
 * scheduled as it arrives so the browser does not wait for the complete
 * response before playback begins.
 */
export class BrowserVoiceHttpSpeechTransportV2 {
    constructor({
        requestAudio,
        createAbortController = () => new AbortController(),
        createAudioContext = () => {
            const AudioContextClass = globalThis.AudioContext || globalThis.webkitAudioContext;
            if (!AudioContextClass) throw new Error('Streaming audio is not supported by this browser.');
            return new AudioContextClass({ sampleRate: 24_000, latencyHint: 'interactive' });
        },
        timers = {},
        startupTimeoutMs = 8_000,
        createId = null,
        onEvent = null,
    } = {}) {
        if (typeof requestAudio !== 'function') {
            throw new TypeError('HTTP speech transport requires a requestAudio function.');
        }
        this.requestAudio = requestAudio;
        this.createAbortController = createAbortController;
        this.createAudioContext = createAudioContext;
        this.setTimeout = timers.setTimeout || globalThis.setTimeout?.bind(globalThis);
        this.clearTimeout = timers.clearTimeout || globalThis.clearTimeout?.bind(globalThis);
        this.startupTimeoutMs = Math.max(1, Number(startupTimeoutMs) || 8_000);
        this.createId = createId;
        this.onEvent = onEvent;
        this.sequence = 0;
        this.generation = 1;
        this.current = null;
        this.volume = 1;
        this.audioContext = null;
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
                bytesReceived: this.current.bytesReceived,
                streamEnded: this.current.streamEnded,
            }) : null,
        });
    }

    /** Unlock Web Audio while the Bean-button click still owns user activation. */
    prime() {
        try {
            const context = this.#audioContext();
            if (context.state === 'suspended') void context.resume().catch(() => {});
            return true;
        } catch (_) {
            return false;
        }
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
            gainNode: null,
            sources: new Set(),
            reader: null,
            streamEnded: false,
            bytesReceived: 0,
            expectedBytes: null,
            nextStartAt: 0,
            timer: null,
            requested: false,
            audioStarted: false,
            settled: false,
        };
        this.current = current;
        current.timer = this.setTimeout?.(() => {
            if (this.current !== current || this.generation !== generation) return;
            current.controller.abort();
            this.#fail(current, new Error('Bean voice did not begin before the playback deadline.'));
        }, this.startupTimeoutMs);

        Promise.resolve(this.requestAudio(item, { signal: current.controller.signal }))
            .then((response) => {
                if (this.current !== current || this.generation !== generation || current.controller.signal.aborted) return;
                if (!response?.body || typeof response.body.getReader !== 'function') {
                    throw new Error('Bean voice requires a streaming audio response.');
                }
                current.requested = true;
                this.#record('transport.requested', current, { mode: 'streaming_pcm' });
                return this.#playPcmStream(current, response);
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
        if (current.gainNode) current.gainNode.gain.value = this.volume;
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
        if (current.timer !== null && current.timer !== undefined) this.clearTimeout?.(current.timer);
        current.timer = null;
        if (current.listeners.onStart?.() === false) {
            this.stop(current.handle, 'scheduler_rejected_start');
            return false;
        }
        this.#record('transport.audio_started', current);
        return true;
    }

    async #playPcmStream(current, response) {
        const sampleRate = Math.max(8_000, Number(response.headers?.get?.('X-Bean-Audio-Sample-Rate')) || 24_000);
        const encoding = clean(response.headers?.get?.('X-Bean-Audio-Encoding')).toLowerCase();
        if (encoding && encoding !== 'pcm_s16le') {
            throw new Error('Bean voice returned an unsupported streaming audio encoding.');
        }
        const expectedBytes = Number(response.headers?.get?.('Content-Length'));
        current.expectedBytes = Number.isSafeInteger(expectedBytes) && expectedBytes > 0 ? expectedBytes : null;
        const context = this.#audioContext();
        if (context.state === 'suspended') await context.resume();
        if (this.current !== current || current.controller.signal.aborted) return;
        current.gainNode = context.createGain();
        current.gainNode.gain.value = this.volume;
        current.gainNode.connect(context.destination);
        current.reader = response.body.getReader();

        let carry = null;
        while (this.current === current && !current.controller.signal.aborted) {
            const { done, value } = await current.reader.read();
            if (done) break;
            let bytes = value instanceof Uint8Array ? value : new Uint8Array(value || 0);
            if (!bytes.byteLength) continue;
            current.bytesReceived += bytes.byteLength;
            if (carry !== null) {
                const joined = new Uint8Array(bytes.byteLength + 1);
                joined[0] = carry;
                joined.set(bytes, 1);
                bytes = joined;
                carry = null;
            }
            if (bytes.byteLength % 2 === 1) {
                carry = bytes[bytes.byteLength - 1];
                bytes = bytes.subarray(0, bytes.byteLength - 1);
            }
            if (bytes.byteLength) this.#schedulePcm(current, bytes, sampleRate, context);
        }
        if (this.current !== current || current.controller.signal.aborted) return;
        if (carry !== null) throw new Error('Bean voice returned an incomplete PCM sample.');
        if (current.expectedBytes !== null && current.bytesReceived !== current.expectedBytes) {
            throw new Error('Bean voice audio stream ended before the complete response arrived.');
        }
        if (!current.audioStarted) throw new Error('Bean voice playback returned no audio.');
        current.streamEnded = true;
        this.#record('transport.stream_ended', current, { bytesReceived: current.bytesReceived });
        if (current.sources.size === 0) this.#complete(current);
    }

    #schedulePcm(current, bytes, sampleRate, context) {
        const samples = new Float32Array(bytes.byteLength / 2);
        const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
        for (let index = 0; index < samples.length; index += 1) {
            samples[index] = view.getInt16(index * 2, true) / 32_768;
        }
        const buffer = context.createBuffer(1, samples.length, sampleRate);
        buffer.copyToChannel(samples, 0);
        const source = context.createBufferSource();
        source.buffer = buffer;
        source.connect(current.gainNode);
        const startsAt = Math.max(context.currentTime, current.nextStartAt || 0);
        current.nextStartAt = startsAt + buffer.duration;
        current.sources.add(source);
        source.onended = () => {
            current.sources.delete(source);
            try { source.disconnect(); } catch (_) {}
            if (this.current === current && current.streamEnded && current.sources.size === 0) {
                this.#complete(current);
            }
        };
        source.start(startsAt);
        this.#started(current);
    }

    #audioContext() {
        if (!this.audioContext || this.audioContext.state === 'closed') {
            this.audioContext = this.createAudioContext();
        }
        return this.audioContext;
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
        if (current.reader) {
            try { void current.reader.cancel().catch?.(() => {}); } catch (_) {}
        }
        for (const source of current.sources) {
            try { source.onended = null; } catch (_) {}
            try { source.stop(); } catch (_) {}
            try { source.disconnect(); } catch (_) {}
        }
        current.sources.clear();
        if (current.gainNode) {
            try { current.gainNode.disconnect(); } catch (_) {}
        }
        current.reader = null;
        current.gainNode = null;
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
