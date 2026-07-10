export const LOCAL_WAKE_GATE_PROCESSOR_URL = '/voice/wake/gate-processor.js';
export const LOCAL_WAKE_WORKER_URL = '/voice/wake/wake-worker.js?v=3';
export const LOCAL_WAKE_GATE_PROCESSOR_NAME = 'hey-bean-gate';

export class LocalWakeGateError extends Error {
    constructor(message, { code = 'local_wake_gate_error', cause } = {}) {
        super(message, cause ? { cause } : undefined);
        this.name = 'LocalWakeGateError';
        this.code = code;
    }
}

function injected(options, name, fallback) {
    return Object.prototype.hasOwnProperty.call(options, name) ? options[name] : fallback;
}

function errorFromEvent(event, fallback) {
    if (event?.error instanceof Error) return event.error;
    if (event instanceof Error) return event;
    return new Error(String(event?.message || fallback));
}

function isSameOriginStaticPath(value) {
    return typeof value === 'string' && /^\/(?!\/)/.test(value);
}

export class LocalWakeGate {
    constructor(options = {}) {
        this.AudioContext = injected(
            options,
            'AudioContext',
            globalThis.AudioContext || globalThis.webkitAudioContext,
        );
        this.AudioWorkletNode = injected(options, 'AudioWorkletNode', globalThis.AudioWorkletNode);
        this.Worker = injected(options, 'Worker', globalThis.Worker);
        this.MediaStream = injected(options, 'MediaStream', globalThis.MediaStream);
        this.gateProcessorUrl = options.gateProcessorUrl || LOCAL_WAKE_GATE_PROCESSOR_URL;
        this.wakeWorkerUrl = options.wakeWorkerUrl || LOCAL_WAKE_WORKER_URL;
        this.processorName = options.processorName || LOCAL_WAKE_GATE_PROCESSOR_NAME;
        const requestedInFlightPcm = Math.floor(Number(options.maxInFlightPcm));
        this.maxInFlightPcm = Number.isFinite(requestedInFlightPcm) && requestedInFlightPcm > 0
            ? Math.min(requestedInFlightPcm, 32)
            : 4;
        this.onDetected = typeof options.onDetected === 'function' ? options.onDetected : () => {};
        this.onActivity = typeof options.onActivity === 'function' ? options.onActivity : () => {};
        this.onError = typeof options.onError === 'function' ? options.onError : () => {};

        this.state = 'idle';
        this.generation = 0;
        this.workerReady = false;
        this.gateOpen = false;
        this.nextPcmSequence = 1;
        this.inFlightPcm = new Set();

        this.audioContext = options.audioContext || null;
        this.rawStream = null;
        this.derivedStream = null;
        this.sourceNode = null;
        this.workletNode = null;
        this.destinationNode = null;
        this.worker = null;
    }

    isOpen() {
        return this.gateOpen;
    }

    isReady() {
        return this.workerReady;
    }

    currentGeneration() {
        return this.generation;
    }

    pendingPcmChunks() {
        return this.inFlightPcm.size;
    }

    async start(rawStream) {
        if (!['idle', 'stopped'].includes(this.state)) {
            const error = new LocalWakeGateError('The local wake gate is already running.', {
                code: 'already_started',
            });
            this.#stopTracks(rawStream);
            this.#reportError(error);
            throw error;
        }

        this.rawStream = rawStream;
        const generation = this.generation + 1;
        this.generation = generation;
        this.state = 'starting';

        try {
            this.#assertSupported(rawStream);

            this.audioContext = this.audioContext || new this.AudioContext();
            if (typeof this.audioContext?.audioWorklet?.addModule !== 'function') {
                throw new LocalWakeGateError('AudioWorklet is unavailable.', { code: 'unsupported' });
            }

            await this.audioContext.audioWorklet.addModule(this.gateProcessorUrl);
            this.#assertCurrent(generation);

            this.sourceNode = this.audioContext.createMediaStreamSource(rawStream);
            this.workletNode = new this.AudioWorkletNode(this.audioContext, this.processorName, {
                numberOfInputs: 1,
                numberOfOutputs: 1,
                outputChannelCount: [1],
                processorOptions: { gateOpen: false },
            });
            this.destinationNode = this.audioContext.createMediaStreamDestination();

            const derivedTrack = this.destinationNode?.stream?.getAudioTracks?.()[0];
            if (!derivedTrack) {
                throw new LocalWakeGateError('The wake gate could not create a derived audio track.', {
                    code: 'derived_track_unavailable',
                });
            }
            if (rawStream.getAudioTracks().includes(derivedTrack)) {
                throw new LocalWakeGateError('The wake gate returned the raw microphone track.', {
                    code: 'raw_track_passthrough',
                });
            }
            this.derivedStream = new this.MediaStream([derivedTrack]);

            const workerUrl = `${this.wakeWorkerUrl}${this.wakeWorkerUrl.includes('?') ? '&' : '?'}generation=${encodeURIComponent(generation)}`;
            this.worker = new this.Worker(workerUrl, { name: 'heybean-local-wake' });
            this.#bindHandlers(generation);

            // Both the processor option and this first control message make the
            // only provider-facing track silent before any raw audio is connected.
            this.#postGate(false, generation);
            this.sourceNode.connect(this.workletNode);
            this.workletNode.connect(this.destinationNode);
            if (this.audioContext.state === 'suspended' && typeof this.audioContext.resume === 'function') {
                let resumeTimer = null;
                try {
                    await Promise.race([
                        this.audioContext.resume(),
                        new Promise((_, reject) => {
                            resumeTimer = globalThis.setTimeout(() => reject(new LocalWakeGateError(
                                'The browser did not activate private microphone processing.',
                                { code: 'audio_context_resume_timeout' },
                            )), 5000);
                        }),
                    ]);
                } finally {
                    if (resumeTimer !== null) globalThis.clearTimeout(resumeTimer);
                }
                this.#assertCurrent(generation);
            }
            if (this.audioContext.state === 'closed') {
                throw new LocalWakeGateError('The local audio context closed during startup.', {
                    code: 'audio_context_closed',
                });
            }
            this.state = this.workerReady ? 'armed' : 'listening';

            return Object.freeze({
                stream: this.derivedStream,
                track: derivedTrack,
            });
        } catch (cause) {
            const error = cause instanceof LocalWakeGateError
                ? cause
                : new LocalWakeGateError('The local wake gate failed to start.', {
                    code: 'start_failed',
                    cause,
                });

            if (generation === this.generation) {
                this.#forceClosed(generation);
                this.generation += 1;
                this.state = 'failed';
                this.workerReady = false;
                this.inFlightPcm.clear();
                await this.#teardown();
                this.#reportError(error);
            }

            throw error;
        }
    }

    close() {
        this.#reportActivity(0, this.generation);
        const closeError = this.#forceClosed(this.generation);
        if (closeError) {
            this.#fail(closeError, this.generation);
            return false;
        }

        if (this.state === 'open') {
            this.state = this.workerReady ? 'armed' : 'listening';
        }

        return true;
    }

    resetAfterTurn() {
        this.close();
        if (!this.worker || !this.workletNode || this.state === 'failed') return false;

        const generation = this.generation + 1;
        this.generation = generation;
        this.workerReady = false;
        this.inFlightPcm.clear();
        this.state = 'listening';

        try {
            this.#bindHandlers(generation);
            this.#postGate(false, generation);
            this.worker.postMessage({ type: 'reset', generation });
            return generation;
        } catch (cause) {
            this.#fail(new LocalWakeGateError('The local wake detector could not reset.', {
                code: 'reset_failed',
                cause,
            }), generation);
            return false;
        }
    }

    async stop() {
        this.#reportActivity(0, this.generation);
        const closeError = this.#forceClosed(this.generation);
        if (closeError) this.#reportError(closeError);

        this.generation += 1;
        this.workerReady = false;
        this.inFlightPcm.clear();
        this.state = 'stopping';

        await this.#teardown();
        this.state = 'stopped';
    }

    #assertSupported(rawStream) {
        const dependencies = [this.AudioContext, this.AudioWorkletNode, this.Worker, this.MediaStream];
        if (dependencies.some((dependency) => typeof dependency !== 'function')) {
            throw new LocalWakeGateError('Local wake-word audio isolation is unsupported.', {
                code: 'unsupported',
            });
        }
        if (!isSameOriginStaticPath(this.gateProcessorUrl) || !isSameOriginStaticPath(this.wakeWorkerUrl)) {
            throw new LocalWakeGateError('Wake-word assets must use static same-origin paths.', {
                code: 'unsafe_asset_url',
            });
        }
        if (typeof rawStream?.getAudioTracks !== 'function' || rawStream.getAudioTracks().length === 0) {
            throw new LocalWakeGateError('A raw microphone audio stream is required.', {
                code: 'microphone_stream_required',
            });
        }
    }

    #assertCurrent(generation) {
        if (generation !== this.generation || this.state !== 'starting') {
            throw new LocalWakeGateError('Wake gate startup was superseded.', {
                code: 'stale_start',
            });
        }
    }

    #bindHandlers(generation) {
        this.workletNode.port.onmessage = (event) => this.#handleWorkletMessage(event, generation);
        this.workletNode.port.onmessageerror = (event) => this.#fail(
            errorFromEvent(event, 'The wake gate audio message was unreadable.'),
            generation,
        );
        this.workletNode.onprocessorerror = (event) => this.#fail(
            errorFromEvent(event, 'The wake gate audio processor failed.'),
            generation,
        );
        this.worker.onmessage = (event) => this.#handleWorkerMessage(event, generation);
        this.worker.onerror = (event) => this.#fail(
            errorFromEvent(event, 'The local wake detector failed.'),
            generation,
        );
        this.worker.onmessageerror = (event) => this.#fail(
            errorFromEvent(event, 'The local wake detector returned an unreadable message.'),
            generation,
        );
    }

    #handleWorkletMessage(event, generation) {
        if (generation !== this.generation || this.state === 'failed') return;

        const data = event?.data || {};
        if (data.type === 'error') {
            this.#fail(new LocalWakeGateError(String(data.message || 'The wake gate processor failed.'), {
                code: 'processor_failed',
            }), generation);
            return;
        }
        if (data.type === 'activity') {
            if (data.generation !== generation) return;
            this.#reportActivity(data.level, generation, data.rms);
            return;
        }
        if (data.type !== 'audio' || !this.workerReady || this.gateOpen || !this.worker) return;
        if (this.inFlightPcm.size >= this.maxInFlightPcm) return;

        let pcm = data.samples;
        if (ArrayBuffer.isView(pcm)) {
            pcm = pcm.buffer.slice(pcm.byteOffset, pcm.byteOffset + pcm.byteLength);
        }
        if (!(pcm instanceof ArrayBuffer)) return;

        const sequence = this.nextPcmSequence;
        this.nextPcmSequence += 1;
        this.inFlightPcm.add(sequence);

        try {
            this.worker.postMessage({
                type: 'audio',
                generation,
                sequence,
                samples: pcm,
            }, [pcm]);
        } catch (cause) {
            this.inFlightPcm.delete(sequence);
            this.#fail(new LocalWakeGateError('The local wake detector could not accept audio.', {
                code: 'pcm_transfer_failed',
                cause,
            }), generation);
        }
    }

    #handleWorkerMessage(event, generation) {
        if (generation !== this.generation || this.state === 'failed') return;

        const data = event?.data || {};
        if (data.generation !== generation) return;

        if (data.type === 'ack') {
            this.inFlightPcm.delete(Number(data.sequence));
            return;
        }
        if (data.type === 'error') {
            this.#fail(new LocalWakeGateError(String(data.message || 'The local wake detector failed.'), {
                code: 'detector_failed',
            }), generation);
            return;
        }
        if (data.type === 'ready') {
            this.workerReady = true;
            if (!this.gateOpen && this.state !== 'starting') this.state = 'armed';
            return;
        }
        if (data.type !== 'detected' || !this.workerReady || this.gateOpen) return;

        try {
            this.onDetected(Object.freeze({
                generation,
                keyword: String(data.keyword || ''),
                variant: String(data.variant || ''),
                result: data.result || null,
            }));
            if (generation !== this.generation || this.state === 'failed') return;
            this.#postGate(true, generation);
            this.gateOpen = true;
            this.state = 'open';
        } catch (cause) {
            this.#fail(new LocalWakeGateError('The local wake gate could not open safely.', {
                code: 'gate_open_failed',
                cause,
            }), generation);
        }
    }

    #postGate(open, generation) {
        if (!this.workletNode?.port || typeof this.workletNode.port.postMessage !== 'function') {
            if (open) {
                throw new LocalWakeGateError('The wake gate processor is unavailable.', {
                    code: 'processor_unavailable',
                });
            }
            return;
        }
        this.workletNode.port.postMessage({ type: open ? 'open' : 'close', generation });
    }

    #forceClosed(generation) {
        this.gateOpen = false;

        try {
            this.#postGate(false, generation);
            return null;
        } catch (cause) {
            // If control messaging itself is broken, sever both sides of the
            // audio graph synchronously so an already-open processor cannot pass audio.
            this.#disconnect(this.sourceNode);
            this.#disconnect(this.workletNode);
            return new LocalWakeGateError('The local wake gate could not close normally.', {
                code: 'gate_close_failed',
                cause,
            });
        }
    }

    #fail(error, generation) {
        if (generation !== this.generation || ['failed', 'stopping', 'stopped'].includes(this.state)) return;

        const closeError = this.#forceClosed(generation);
        this.generation += 1;
        this.workerReady = false;
        this.inFlightPcm.clear();
        this.state = 'failed';
        void this.#teardown();
        this.#reportError(closeError || error);
    }

    async #teardown() {
        const worker = this.worker;
        const workletNode = this.workletNode;
        const sourceNode = this.sourceNode;
        const destinationNode = this.destinationNode;
        const audioContext = this.audioContext;
        const rawStream = this.rawStream;
        const derivedStream = this.derivedStream;

        this.worker = null;
        this.workletNode = null;
        this.sourceNode = null;
        this.destinationNode = null;
        this.audioContext = null;
        this.rawStream = null;
        this.derivedStream = null;

        if (worker) {
            try {
                worker.postMessage?.({ type: 'close', generation: this.generation });
            } catch {
                // Worker termination below is the terminal fallback.
            }
            worker.onmessage = null;
            worker.onerror = null;
            worker.onmessageerror = null;
            worker.terminate?.();
        }
        if (workletNode?.port) {
            try {
                workletNode.port.postMessage?.({ type: 'destroy', generation: this.generation });
            } catch {
                // Disconnect and context shutdown below keep the path closed.
            }
            workletNode.port.onmessage = null;
            workletNode.port.onmessageerror = null;
            workletNode.port.close?.();
        }
        if (workletNode) workletNode.onprocessorerror = null;

        this.#disconnect(sourceNode);
        this.#disconnect(workletNode);
        this.#disconnect(destinationNode);

        let closing;
        try {
            closing = audioContext?.close?.();
        } catch {
            closing = null;
        }

        this.#stopTracks(rawStream, derivedStream);

        if (closing && typeof closing.then === 'function') {
            try {
                await closing;
            } catch {
                // Tracks and graph have already been synchronously closed.
            }
        }
    }

    #disconnect(node) {
        try {
            node?.disconnect?.();
        } catch {
            // Disconnect is best-effort during terminal fail-closed teardown.
        }
    }

    #stopTracks(...streams) {
        const tracks = new Set();
        streams.forEach((stream) => {
            const streamTracks = typeof stream?.getTracks === 'function'
                ? stream.getTracks()
                : stream?.getAudioTracks?.() || [];
            streamTracks.forEach((track) => tracks.add(track));
        });
        tracks.forEach((track) => {
            try {
                track?.stop?.();
            } catch {
                // A failed track cannot become a provider passthrough.
            }
        });
    }

    #reportError(error) {
        try {
            this.onError(error);
        } catch {
            // Consumer error handlers cannot reopen or bypass the gate.
        }
    }

    #reportActivity(level, generation, rms = 0) {
        const normalizedLevel = Math.max(0, Math.min(1, Number(level) || 0));
        const normalizedRms = Math.max(0, Math.min(1, Number(rms) || 0));
        try {
            this.onActivity(Object.freeze({
                generation,
                level: normalizedLevel,
                rms: normalizedRms,
            }));
        } catch {
            // Presentation feedback cannot interfere with the fail-closed gate.
        }
    }
}
