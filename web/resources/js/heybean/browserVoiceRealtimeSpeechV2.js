const OUTPUT_EVENT_TYPES = new Set([
    'response.created',
    'response.done',
    'output_audio_buffer.started',
    'output_audio_buffer.stopped',
    'output_audio_buffer.cleared',
    'response.audio_transcript.delta',
    'response.audio_transcript.done',
    'response.output_audio_transcript.delta',
    'response.output_audio_transcript.done',
    'response.text.delta',
    'response.output_text.delta',
    'response.output_text.done',
]);

function clean(value) {
    return String(value || '').trim();
}

/**
 * Owns exactly one OpenAI Realtime speech response for Browser Voice v2.
 * It reports playback start from the provider's audible buffer event, not from
 * response submission, and never owns conversation or durable task state.
 */
export class BrowserVoiceRealtimeSpeechTransportV2 {
    constructor({
        send,
        buildRequest,
        buildCancel,
        clock = () => Date.now(),
        timers = {},
        timeoutMs = 20_000,
        createId = null,
        onEvent = null,
    } = {}) {
        if (typeof send !== 'function' || typeof buildRequest !== 'function' || typeof buildCancel !== 'function') {
            throw new TypeError('Realtime speech transport requires send, buildRequest, and buildCancel functions.');
        }
        this.send = send;
        this.buildRequest = buildRequest;
        this.buildCancel = buildCancel;
        this.clock = clock;
        this.setTimeout = timers.setTimeout || globalThis.setTimeout?.bind(globalThis);
        this.clearTimeout = timers.clearTimeout || globalThis.clearTimeout?.bind(globalThis);
        this.timeoutMs = Math.max(1, Number(timeoutMs) || 20_000);
        this.createId = createId;
        this.onEvent = onEvent;
        this.sequence = 0;
        this.generation = 1;
        this.current = null;
        this.abandonedClientIds = new Set();
    }

    snapshot() {
        return Object.freeze({
            generation: this.generation,
            current: this.current ? Object.freeze({
                transportId: this.current.transportId,
                clientResponseId: this.current.clientResponseId,
                providerResponseId: this.current.providerResponseId,
                itemId: this.current.item.id,
                turnId: this.current.item.turnId,
                audioStarted: this.current.audioStarted,
                responseDone: this.current.responseDone,
                audioStopped: this.current.audioStopped,
            }) : null,
        });
    }

    play(item, listeners = {}) {
        if (this.current) throw new Error('Realtime speech transport already owns a response.');
        this.sequence += 1;
        const transportId = clean(this.createId?.()) || `browser-voice-speech-${this.generation}-${this.sequence}`;
        const clientResponseId = `${transportId}:response`;
        const generation = this.generation;
        const handle = Object.freeze({ transportId, generation });
        const current = {
            handle,
            transportId,
            clientResponseId,
            providerResponseId: '',
            item,
            listeners,
            audioStarted: false,
            responseDone: false,
            audioStopped: false,
            timer: null,
        };
        current.timer = this.setTimeout?.(() => {
            if (this.current !== current || this.generation !== generation) return;
            this.#fail(current, new Error('Bean voice playback timed out.'));
        }, this.timeoutMs);
        this.current = current;
        const request = this.buildRequest(item, clientResponseId);
        if (!request || this.send(request) === false) {
            this.#fail(current, new Error('Bean voice playback could not be requested.'));
            return handle;
        }
        this.#record('transport.requested', current);
        return handle;
    }

    stop(handle, reason = 'stopped') {
        const current = this.current;
        if (!current || !handle || handle.transportId !== current.transportId || handle.generation !== this.generation) {
            return false;
        }
        this.abandonedClientIds.add(current.clientResponseId);
        this.#cancelTimer(current);
        this.current = null;
        this.buildCancel(current.providerResponseId).filter(Boolean).forEach((event) => this.send(event));
        this.#record('transport.stopped', current, { reason });
        return true;
    }

    reset(reason = 'reset') {
        if (this.current) this.stop(this.current.handle, reason);
        this.generation += 1;
        if (this.abandonedClientIds.size > 128) {
            this.abandonedClientIds = new Set([...this.abandonedClientIds].slice(-64));
        }
    }

    handleEvent(payload = {}) {
        const type = clean(payload.type);
        if (!OUTPUT_EVENT_TYPES.has(type)) return false;

        if (type === 'response.created') {
            const clientResponseId = clean(payload.response?.metadata?.heybean_response_id);
            const providerResponseId = clean(payload.response?.id);
            const current = this.current;
            if (!current || clientResponseId !== current.clientResponseId) {
                // The server configures Realtime as transcription-only. Fail
                // closed if the provider ever creates an unowned response:
                // ignoring its events is insufficient because WebRTC audio can
                // still reach the speaker without application playback state.
                this.buildCancel(providerResponseId).filter(Boolean).forEach((event) => this.send(event));
                return true;
            }
            current.providerResponseId = providerResponseId;
            this.#record('transport.bound', current);
            return true;
        }

        const current = this.current;
        if (!current) return true;
        const responseId = clean(payload.response_id || payload.response?.id);
        if (responseId && (!current.providerResponseId || responseId !== current.providerResponseId)) return true;

        if (type === 'output_audio_buffer.started') {
            if (!current.audioStarted) {
                current.audioStarted = true;
                if (current.listeners.onStart?.() === false) {
                    this.stop(current.handle, 'scheduler_rejected_start');
                    return true;
                }
                this.#record('transport.audio_started', current);
            }
            return true;
        }
        if (type === 'output_audio_buffer.stopped') {
            current.audioStopped = true;
            this.#record('transport.audio_stopped', current);
            this.#finishIfComplete(current);
            return true;
        }
        if (type === 'output_audio_buffer.cleared') return true;

        if (type === 'response.done') {
            const status = clean(payload.response?.status).toLowerCase();
            if (status !== 'completed') {
                const detail = clean(payload.response?.status_details?.error?.message) || 'Bean voice playback failed.';
                this.#fail(current, new Error(detail));
                return true;
            }
            current.responseDone = true;
            this.#record('transport.response_done', current);
            this.#finishIfComplete(current);
            return true;
        }

        // Output transcript/text belongs only to TTS verification. The durable
        // server final is already visible and remains the sole chat message.
        return true;
    }

    #finishIfComplete(current) {
        if (this.current !== current || !current.responseDone || !current.audioStopped) return false;
        this.#cancelTimer(current);
        this.current = null;
        current.listeners.onEnd?.('completed');
        this.#record('transport.completed', current);
        return true;
    }

    #fail(current, error) {
        if (this.current !== current) return false;
        this.abandonedClientIds.add(current.clientResponseId);
        this.#cancelTimer(current);
        this.current = null;
        this.buildCancel(current.providerResponseId).filter(Boolean).forEach((event) => this.send(event));
        current.listeners.onError?.(error);
        this.#record('transport.failed', current, { error: clean(error?.message || error) });
        return true;
    }

    #cancelTimer(current) {
        if (current.timer !== null && current.timer !== undefined) this.clearTimeout?.(current.timer);
        current.timer = null;
    }

    #record(type, current, detail = {}) {
        this.onEvent?.(Object.freeze({
            type,
            atMs: this.clock(),
            transportId: current.transportId,
            clientResponseId: current.clientResponseId,
            providerResponseId: current.providerResponseId || null,
            itemId: current.item.id,
            turnId: current.item.turnId,
            ...detail,
        }));
    }
}
