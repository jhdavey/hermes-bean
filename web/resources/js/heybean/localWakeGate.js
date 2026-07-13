export const LOCAL_WAKE_GATE_PROCESSOR_URL = '/voice/wake/gate-processor.js?v=9';
export const LOCAL_WAKE_WORKER_URL = '/voice/wake/wake-worker.js?v=9';
export const LOCAL_WAKE_GATE_PROCESSOR_NAME = 'hey-bean-gate';
export const LOCAL_WAKE_ADDRESS_CONFIRMATION_MS = 3000;
export const LOCAL_WAKE_PCM_SAMPLE_RATE = 16000;
export const LOCAL_WAKE_PCM_RING_CHUNKS = 80;

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
        this.gateProcessorUrl = options.gateProcessorUrl || LOCAL_WAKE_GATE_PROCESSOR_URL;
        this.wakeWorkerUrl = options.wakeWorkerUrl || LOCAL_WAKE_WORKER_URL;
        this.processorName = options.processorName || LOCAL_WAKE_GATE_PROCESSOR_NAME;
        const requestedInFlightPcm = Math.floor(Number(options.maxInFlightPcm));
        this.maxInFlightPcm = Number.isFinite(requestedInFlightPcm) && requestedInFlightPcm > 0
            ? Math.min(requestedInFlightPcm, 32)
            : 12;
        const requestedBufferedPcm = Math.floor(Number(options.maxBufferedPcm));
        // Each worklet chunk is 100 ms at 16 kHz. Keep a bounded, memory-only
        // startup ring so a wake phrase spoken while the local model is warming
        // is decoded once the worker becomes ready instead of being discarded.
        this.maxBufferedPcm = Number.isFinite(requestedBufferedPcm) && requestedBufferedPcm > 0
            ? Math.min(requestedBufferedPcm, 240)
            : 80;
        const requestedLocalPcmRing = Math.floor(Number(options.maxLocalPcmRingChunks));
        this.maxLocalPcmRingChunks = Number.isFinite(requestedLocalPcmRing)
            && requestedLocalPcmRing > 0
            ? Math.min(requestedLocalPcmRing, 240)
            : LOCAL_WAKE_PCM_RING_CHUNKS;
        this.onDetected = typeof options.onDetected === 'function' ? options.onDetected : () => {};
        this.onActivatedPcm = typeof options.onActivatedPcm === 'function'
            ? options.onActivatedPcm
            : null;
        this.onReady = typeof options.onReady === 'function' ? options.onReady : () => {};
        this.onActivity = typeof options.onActivity === 'function' ? options.onActivity : () => {};
        this.onDiagnostic = typeof options.onDiagnostic === 'function' ? options.onDiagnostic : () => {};
        this.onError = typeof options.onError === 'function' ? options.onError : () => {};
        this.consumerReady = options.consumerReady !== false;
        const scheduleTimeout = injected(options, 'setTimeout', globalThis.setTimeout);
        const cancelTimeout = injected(options, 'clearTimeout', globalThis.clearTimeout);
        this.setTimeout = typeof scheduleTimeout === 'function'
            ? scheduleTimeout.bind(globalThis)
            : globalThis.setTimeout.bind(globalThis);
        this.clearTimeout = typeof cancelTimeout === 'function'
            ? cancelTimeout.bind(globalThis)
            : globalThis.clearTimeout.bind(globalThis);
        const requestedConfirmationMs = Math.floor(Number(options.addressConfirmationMs));
        this.addressConfirmationMs = Number.isFinite(requestedConfirmationMs)
            ? Math.max(250, Math.min(LOCAL_WAKE_ADDRESS_CONFIRMATION_MS, requestedConfirmationMs))
            : LOCAL_WAKE_ADDRESS_CONFIRMATION_MS;

        this.state = 'idle';
        this.generation = 0;
        this.workletReady = false;
        this.audioSinkReady = false;
        this.workerReady = false;
        this.audioFlowReady = false;
        this.gateOpen = false;
        this.readyReportedGeneration = 0;
        this.addressCandidateTimer = null;
        this.pendingWakeConfirmation = null;
        this.nextPcmSequence = 1;
        this.lastAcknowledgedPcmSequence = 0;
        this.consumerWakeSequenceFloor = this.consumerReady
            ? this.nextPcmSequence
            : Number.POSITIVE_INFINITY;
        this.inFlightPcm = new Set();
        this.bufferedPcm = [];
        this.localPcmRing = [];
        this.lastSourceSequence = -1;

        this.audioContext = options.audioContext || null;
        this.rawStream = null;
        this.sourceNode = null;
        this.workletNode = null;
        this.worker = null;
    }

    isOpen() {
        return this.gateOpen;
    }

    isReady() {
        return this.workletReady
            && this.audioSinkReady
            && this.workerReady
            && this.audioFlowReady
            && !['failed', 'stopping', 'stopped'].includes(this.state);
    }

    isConsumerAdmissionReady() {
        return this.isReady() && this.#consumerCanAdmitCurrentDecision();
    }

    currentGeneration() {
        return this.generation;
    }

    pendingPcmChunks() {
        return this.inFlightPcm.size;
    }

    bufferedPcmChunks() {
        return this.bufferedPcm.length;
    }

    setConsumerReady(ready = true) {
        const nextReady = ready === true;
        if (nextReady && !this.consumerReady) {
            // PCM already captured or sent belongs to startup and can never
            // admit a wake, even if its ordered worker result arrives later.
            this.bufferedPcm = [];
            this.localPcmRing = [];
            this.consumerWakeSequenceFloor = this.nextPcmSequence;
        } else if (!nextReady) {
            this.bufferedPcm = [];
            this.localPcmRing = [];
            this.consumerWakeSequenceFloor = Number.POSITIVE_INFINITY;
        }
        this.consumerReady = nextReady;
        if (this.consumerReady && this.pendingWakeConfirmation && this.isReady() && !this.gateOpen) {
            const confirmation = this.pendingWakeConfirmation;
            this.pendingWakeConfirmation = null;
            this.#confirmWake(confirmation, this.generation);
        }

        return this.consumerReady;
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
        this.workletReady = false;
        this.audioSinkReady = false;
        this.workerReady = false;
        this.audioFlowReady = false;
        this.readyReportedGeneration = 0;
        this.pendingWakeConfirmation = null;
        this.lastAcknowledgedPcmSequence = 0;
        this.consumerWakeSequenceFloor = this.consumerReady
            ? this.nextPcmSequence
            : Number.POSITIVE_INFINITY;
        this.bufferedPcm = [];
        this.localPcmRing = [];
        this.lastSourceSequence = -1;
        this.#clearAddressCandidateTimer();

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
                processorOptions: { captureActive: false },
            });
            if (!this.audioContext.destination) {
                throw new LocalWakeGateError('The wake gate could not create a private audio sink.', {
                    code: 'audio_sink_unavailable',
                });
            }
            this.audioSinkReady = true;

            const workerUrl = `${this.wakeWorkerUrl}${this.wakeWorkerUrl.includes('?') ? '&' : '?'}generation=${encodeURIComponent(generation)}`;
            this.worker = new this.Worker(workerUrl, { name: 'heybean-local-wake' });
            this.#bindHandlers(generation);

            // The worklet is a silent analysis sink. This generation boundary
            // erases startup PCM before any local wake can activate transport.
            this.#postGate(false, generation);
            this.sourceNode.connect(this.workletNode);
            this.workletNode.connect(this.audioContext.destination);
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
            if (this.state === 'starting') {
                this.state = this.isReady() ? 'armed' : 'listening';
            }

            return Object.freeze({
                sampleRate: LOCAL_WAKE_PCM_SAMPLE_RATE,
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
                this.workletReady = false;
                this.audioSinkReady = false;
                this.workerReady = false;
                this.audioFlowReady = false;
                this.pendingWakeConfirmation = null;
                this.#clearAddressCandidateTimer();
                this.inFlightPcm.clear();
                this.bufferedPcm = [];
                this.localPcmRing = [];
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
            this.state = this.isReady() ? 'armed' : 'listening';
        }

        return true;
    }

    resetAfterTurn() {
        if (!this.close()) return false;
        if (!this.worker || !this.workletNode || this.state === 'failed') return false;

        try {
            return this.#rearmDormant('turn_reset');
        } catch (cause) {
            this.#fail(new LocalWakeGateError('The local wake detector could not reset.', {
                code: 'reset_failed',
                cause,
            }), this.generation);
            return false;
        }
    }

    async stop() {
        this.#reportActivity(0, this.generation);
        const closeError = this.#forceClosed(this.generation);
        if (closeError) this.#reportError(closeError);

        this.#clearAddressCandidateTimer();
        this.generation += 1;
        this.workletReady = false;
        this.audioSinkReady = false;
        this.workerReady = false;
        this.audioFlowReady = false;
        this.pendingWakeConfirmation = null;
        this.inFlightPcm.clear();
        this.bufferedPcm = [];
        this.localPcmRing = [];
        this.lastSourceSequence = -1;
        this.state = 'stopping';

        await this.#teardown();
        this.state = 'stopped';
    }

    #assertSupported(rawStream) {
        const dependencies = [this.AudioContext, this.AudioWorkletNode, this.Worker];
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
        if (generation !== this.generation
            || ['failed', 'stopping', 'stopped'].includes(this.state)) {
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
            if (data.generation !== generation) return;
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
        if (data.type === 'processor_ready') {
            if (data.generation !== generation) return;
            this.workletReady = true;
            this.#maybeBecomeReady(generation);
            return;
        }
        if (data.type !== 'audio' || data.generation !== generation || !this.worker) return;

        const sourceSequence = Number(data.sequence);
        if (!Number.isSafeInteger(sourceSequence) || sourceSequence < 0) {
            this.#fail(new LocalWakeGateError('The wake gate emitted an invalid PCM sequence.', {
                code: 'invalid_source_sequence',
            }), generation);
            return;
        }
        if (sourceSequence !== this.lastSourceSequence + 1) {
            this.#fail(new LocalWakeGateError('The wake gate lost an ordered PCM chunk.', {
                code: 'source_sequence_gap',
            }), generation);
            return;
        }

        const samples = this.#normalizePcm(data.samples);
        if (!samples || samples.length === 0) {
            this.#fail(new LocalWakeGateError('The wake gate emitted invalid local PCM.', {
                code: 'invalid_local_pcm',
            }), generation);
            return;
        }
        this.lastSourceSequence = sourceSequence;

        if (this.gateOpen) {
            try {
                this.#emitActivatedPcm({ generation, sourceSequence, samples, released: false });
            } catch (cause) {
                this.#fail(new LocalWakeGateError('Activated microphone audio could not reach transcription.', {
                    code: 'activated_pcm_delivery_failed',
                    cause,
                }), generation);
            }
            return;
        }

        this.localPcmRing.push({ sourceSequence, sourceOffset: 0, samples });
        if (this.localPcmRing.length > this.maxLocalPcmRingChunks) {
            this.localPcmRing.splice(0, this.localPcmRing.length - this.maxLocalPcmRingChunks);
        }

        const pcm = samples.buffer.slice(samples.byteOffset, samples.byteOffset + samples.byteLength);
        this.bufferedPcm.push({ sourceSequence, pcm });
        if (this.bufferedPcm.length > this.maxBufferedPcm) {
            this.bufferedPcm.splice(0, this.bufferedPcm.length - this.maxBufferedPcm);
        }
        this.#drainBufferedPcm(generation);
    }

    #drainBufferedPcm(generation) {
        if (generation !== this.generation
            || !this.workerReady
            || this.gateOpen
            || !this.worker) return;

        while (this.bufferedPcm.length > 0 && this.inFlightPcm.size < this.maxInFlightPcm) {
            const pcm = this.bufferedPcm.shift();
            this.#sendPcm(pcm, generation);
        }
    }

    #sendPcm(entry, generation) {
        const pcm = entry?.pcm;
        const sourceSequence = Number(entry?.sourceSequence);
        if (!(pcm instanceof ArrayBuffer) || !Number.isSafeInteger(sourceSequence)) return;

        const sequence = this.nextPcmSequence;
        this.nextPcmSequence += 1;
        this.inFlightPcm.add(sequence);

        try {
            this.worker.postMessage({
                type: 'audio',
                generation,
                sequence,
                sourceSequence,
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

    #normalizePcm(value) {
        if (value instanceof Float32Array) return value;
        if (ArrayBuffer.isView(value)
            && value.byteLength % Float32Array.BYTES_PER_ELEMENT === 0) {
            const copy = value.buffer.slice(value.byteOffset, value.byteOffset + value.byteLength);
            return new Float32Array(copy);
        }
        if (value instanceof ArrayBuffer
            && value.byteLength % Float32Array.BYTES_PER_ELEMENT === 0) {
            return new Float32Array(value);
        }

        return null;
    }

    #normalizeBoundary(value) {
        const sourceSequence = Number(value?.sourceSequence);
        const sampleOffset = Number(value?.sampleOffset);
        if (!Number.isSafeInteger(sourceSequence) || sourceSequence < 0
            || !Number.isSafeInteger(sampleOffset) || sampleOffset < 0) return null;

        return {
            sourceSequence,
            sampleOffset,
            policy: String(value?.policy || ''),
        };
    }

    #pruneLocalPcmToBoundary(value, { requireRetained = true } = {}) {
        const boundary = this.#normalizeBoundary(value);
        if (!boundary) throw new Error('Invalid local PCM boundary.');
        const index = this.localPcmRing.findIndex(
            (entry) => entry.sourceSequence === boundary.sourceSequence,
        );
        if (index < 0) {
            if (requireRetained) throw new Error('The local PCM boundary is no longer retained.');
            return false;
        }

        this.localPcmRing.splice(0, index);
        const first = this.localPcmRing[0];
        const localOffset = boundary.sampleOffset - Number(first.sourceOffset || 0);
        if (localOffset < 0 || localOffset > first.samples.length) {
            throw new Error('The local PCM boundary falls outside its source chunk.');
        }
        if (localOffset > 0) {
            first.samples = first.samples.slice(localOffset);
            first.sourceOffset = boundary.sampleOffset;
        }

        return true;
    }

    #flushLocalPcm(boundary, generation) {
        this.#pruneLocalPcmToBoundary(boundary);
        const retained = this.localPcmRing;
        this.localPcmRing = [];
        for (const entry of retained) {
            if (entry.samples.length === 0) continue;
            this.#emitActivatedPcm({
                generation,
                sourceSequence: entry.sourceSequence,
                samples: entry.samples,
                released: true,
            });
        }
    }

    #emitActivatedPcm({ generation, sourceSequence, samples, released }) {
        if (typeof this.onActivatedPcm !== 'function') {
            throw new Error('Activated PCM does not have a provider transport consumer.');
        }
        this.onActivatedPcm(Object.freeze({
            generation,
            sourceSequence,
            sampleRate: LOCAL_WAKE_PCM_SAMPLE_RATE,
            samples,
            released: released === true,
        }));
    }

    #handleWorkerMessage(event, generation) {
        if (generation !== this.generation || this.state === 'failed') return;

        const data = event?.data || {};
        if (data.generation !== generation) return;

        if (data.type === 'ack') {
            const sequence = Number(data.sequence);
            this.inFlightPcm.delete(sequence);
            if (data.accepted === true) {
                if (Number.isSafeInteger(sequence) && sequence >= 0) {
                    this.lastAcknowledgedPcmSequence = sequence;
                }
                this.audioFlowReady = true;
                this.#maybeBecomeReady(generation);
            }
            this.#drainBufferedPcm(generation);
            return;
        }
        if (data.type === 'error') {
            this.#fail(new LocalWakeGateError(String(data.message || 'The local wake detector failed.'), {
                code: String(data.code || 'detector_failed').slice(0, 80),
            }), generation);
            return;
        }
        if (data.type === 'ready') {
            if (data.modelReady !== true
                || data.warmDecodeReady !== true
                || data.recognitionStreamReady !== true) {
                this.#fail(new LocalWakeGateError('The wake detector reported an incomplete readiness barrier.', {
                    code: 'incomplete_readiness_barrier',
                }), generation);
                return;
            }
            this.workerReady = true;
            this.#drainBufferedPcm(generation);
            this.#maybeBecomeReady(generation);
            return;
        }
        if (data.type === 'utterance_started') {
            if (this.gateOpen) return;
            try {
                this.#pruneLocalPcmToBoundary(data.boundary);
            } catch (cause) {
                this.#fail(new LocalWakeGateError('The local wake detector reported an invalid utterance boundary.', {
                    code: 'invalid_utterance_boundary',
                    cause,
                }), generation);
            }
            return;
        }
        if (data.type === 'classification_decision') {
            this.onDiagnostic(Object.freeze({
                type: 'classification_decision',
                generation,
                decisionType: String(data.decisionType || ''),
                accepted: data.accepted === true,
                expectedClass: String(data.expectedClass || ''),
                winningClass: String(data.winningClass || ''),
                probability: Number(data.probability),
                threshold: Number(data.threshold),
                sampleCount: Number(data.sampleCount),
            }));
            return;
        }
        if (data.type === 'address_candidate') {
            if (!this.#consumerCanAdmitCurrentDecision()) {
                this.#rearmAfterRejectedDormantAudio(generation, 'consumer_not_ready');
                return;
            }
            if (!this.isReady() || this.gateOpen || this.pendingWakeConfirmation) return;
            this.#beginAddressCandidate(generation);
            return;
        }
        if (data.type === 'address_rejected' || data.type === 'dormant_discard') {
            if (this.gateOpen || this.pendingWakeConfirmation) return;
            this.#rearmAfterRejectedDormantAudio(generation, data.type);
            return;
        }
        if (data.type !== 'wake_confirmed' || !this.workerReady || this.gateOpen) return;

        // A provider cannot consume the command audio until its gated WebRTC
        // track is ready. Never retain a startup wake for later admission: the
        // worklet's bounded command pre-roll may have expired by then. Rotate
        // the dormant generation instead so startup speech stays private and
        // the first post-readiness wake starts from a clean audio boundary.
        if (!this.#consumerCanAdmitCurrentDecision()) {
            this.#rearmAfterRejectedDormantAudio(generation, 'consumer_not_ready');
            return;
        }

        if (!this.isReady()) {
            if (this.workletReady && this.audioSinkReady) {
                this.pendingWakeConfirmation = { ...data };
                this.state = 'activation_pending';
            }
            return;
        }

        this.#confirmWake(data, generation);
    }

    #consumerCanAdmitCurrentDecision() {
        return this.consumerReady
            && Number.isSafeInteger(this.lastAcknowledgedPcmSequence)
            && this.lastAcknowledgedPcmSequence >= this.consumerWakeSequenceFloor;
    }

    #confirmWake(data, generation) {
        if (generation !== this.generation || this.gateOpen || !this.isReady() || !this.consumerReady) return;

        try {
            const releaseBoundary = this.#normalizeBoundary(data.releaseBoundary);
            const detectedSourceSequence = Number(data.sourceSequence);
            if (!releaseBoundary) {
                throw new LocalWakeGateError('The confirmed wake did not include a safe local PCM boundary.', {
                    code: 'missing_release_boundary',
                });
            }
            if (!Number.isSafeInteger(detectedSourceSequence) || detectedSourceSequence < 0
                || releaseBoundary.sourceSequence > detectedSourceSequence) {
                throw new LocalWakeGateError('The confirmed wake reported an invalid source boundary.', {
                    code: 'invalid_release_boundary',
                });
            }
            this.#clearAddressCandidateTimer();
            this.pendingWakeConfirmation = null;
            this.bufferedPcm = [];
            this.onDetected(Object.freeze({
                type: 'wake_confirmed',
                generation,
                keyword: String(data.keyword || ''),
                variant: String(data.variant || ''),
                activation: data.activation === 'missed_hey_confirmation'
                    ? 'missed_hey_confirmation'
                    : 'strict_wake',
                sourceSequence: detectedSourceSequence,
                releaseBoundary: Object.freeze({ ...releaseBoundary }),
            }));
            if (generation !== this.generation || this.state === 'failed') return;
            this.#postGate(true, generation);
            this.gateOpen = true;
            this.state = 'open';
            this.#flushLocalPcm(releaseBoundary, generation);
        } catch (cause) {
            this.#fail(new LocalWakeGateError('The local wake gate could not open safely.', {
                code: 'gate_open_failed',
                cause,
            }), generation);
        }
    }

    #maybeBecomeReady(generation) {
        if (generation !== this.generation || !this.isReady()) return;

        if (!this.gateOpen && this.state !== 'confirming') this.state = 'armed';
        if (this.readyReportedGeneration !== generation) {
            this.readyReportedGeneration = generation;
            try {
                this.onReady(Object.freeze({
                    type: 'ready',
                    generation,
                    barriers: Object.freeze({
                        worklet: true,
                        model: true,
                        warmDecode: true,
                        recognitionStream: true,
                        localPcmCapture: true,
                        liveAudioDecode: true,
                    }),
                }));
            } catch {
                // Readiness observers cannot weaken or open the gate.
            }
        }

        if (this.pendingWakeConfirmation && this.consumerReady) {
            const confirmation = this.pendingWakeConfirmation;
            this.pendingWakeConfirmation = null;
            this.#confirmWake(confirmation, generation);
        }
    }

    #beginAddressCandidate(generation) {
        if (generation !== this.generation || this.gateOpen || this.addressCandidateTimer !== null) return;

        this.state = 'confirming';
        this.addressCandidateTimer = this.setTimeout(() => {
            this.addressCandidateTimer = null;
            if (generation !== this.generation || this.gateOpen || this.state !== 'confirming') return;
            this.#rearmAfterRejectedDormantAudio(generation, 'address_timeout');
        }, this.addressConfirmationMs);
    }

    #rearmAfterRejectedDormantAudio(generation, reason) {
        if (generation !== this.generation || this.gateOpen || this.state === 'failed') return;

        this.#clearAddressCandidateTimer();
        try {
            this.#rearmDormant(reason);
        } catch (cause) {
            this.#fail(new LocalWakeGateError('The local wake detector could not discard rejected dormant audio.', {
                code: 'dormant_rearm_failed',
                cause,
            }), this.generation);
        }
    }

    #rearmDormant(reason) {
        if (!this.worker || !this.workletNode || this.state === 'failed') return false;

        this.#clearAddressCandidateTimer();
        const generation = this.generation + 1;
        this.generation = generation;
        this.workletReady = false;
        this.workerReady = false;
        this.audioFlowReady = false;
        this.readyReportedGeneration = 0;
        this.pendingWakeConfirmation = null;
        this.lastAcknowledgedPcmSequence = 0;
        this.consumerWakeSequenceFloor = this.consumerReady
            ? this.nextPcmSequence
            : Number.POSITIVE_INFINITY;
        this.inFlightPcm.clear();
        this.bufferedPcm = [];
        this.localPcmRing = [];
        this.lastSourceSequence = -1;
        this.gateOpen = false;
        this.state = 'listening';
        this.#bindHandlers(generation);
        // A newer generation synchronously erases the worklet resampler and all
        // main-thread PCM retained for a rejected dormant utterance.
        this.#postGate(false, generation);
        this.worker.postMessage({ type: 'reset', generation, reason });

        return generation;
    }

    #clearAddressCandidateTimer() {
        if (this.addressCandidateTimer === null) return;
        this.clearTimeout(this.addressCandidateTimer);
        this.addressCandidateTimer = null;
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
        this.workletNode.port.postMessage({
            type: open ? 'activate' : 'close',
            generation,
        });
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
        this.#clearAddressCandidateTimer();
        this.generation += 1;
        this.workletReady = false;
        this.audioSinkReady = false;
        this.workerReady = false;
        this.audioFlowReady = false;
        this.pendingWakeConfirmation = null;
        this.inFlightPcm.clear();
        this.bufferedPcm = [];
        this.localPcmRing = [];
        this.lastSourceSequence = -1;
        this.state = 'failed';
        void this.#teardown();
        this.#reportError(closeError || error);
    }

    async #teardown() {
        this.#clearAddressCandidateTimer();
        const worker = this.worker;
        const workletNode = this.workletNode;
        const sourceNode = this.sourceNode;
        const audioContext = this.audioContext;
        const rawStream = this.rawStream;

        this.worker = null;
        this.workletNode = null;
        this.sourceNode = null;
        this.audioContext = null;
        this.rawStream = null;

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

        let closing;
        try {
            closing = audioContext?.close?.();
        } catch {
            closing = null;
        }

        this.#stopTracks(rawStream);

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
