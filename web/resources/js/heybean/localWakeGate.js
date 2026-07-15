export const LOCAL_WAKE_GATE_PROCESSOR_URL = '/voice/wake/gate-processor.js?v=17';
export const LOCAL_WAKE_WORKER_URL = '/voice/wake/wake-worker.js?v=17';
export const LOCAL_WAKE_GATE_PROCESSOR_NAME = 'hey-bean-gate';
export const LOCAL_WAKE_PCM_SAMPLE_RATE = 16000;
export const LOCAL_WAKE_PCM_RING_CHUNKS = 80;
export const LOCAL_WAKE_PCM_ACK_TIMEOUT_MS = 2000;
export const LOCAL_WAKE_CONSUMER_READY_TIMEOUT_MS = 15000;
export const LOCAL_WAKE_WORKER_FAILURE_DETAIL_TIMEOUT_MS = 250;

const LOCAL_WAKE_WORKER_FAILURE_CODES = Object.freeze(new Set([
    'decode_failed',
    'initialization_failed',
    'invalid_audio',
    'invalid_generation',
    'invalid_message',
    'invalid_message_type',
    'invalid_sequence',
    'reset_failed',
    'runtime_load_failed',
    'unhandled_rejection',
    'worker_error',
]));
const LOCAL_WAKE_FATAL_ACK_REASONS = Object.freeze(new Set(['decode_failed']));

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

function sanitizedWorkerFailureCode(value) {
    const code = String(value || '').trim();
    return LOCAL_WAKE_WORKER_FAILURE_CODES.has(code) ? code : 'worker_error';
}

function sanitizedWorkerFailureMessage(value, fallback = 'The local wake detector failed.') {
    const message = String(value || fallback)
        .replace(/\bBearer\s+\S+/gi, 'Bearer [redacted]')
        .replace(/\b(?:sk|pk)-[A-Za-z0-9_-]+\b/g, '[redacted]')
        .replace(/[\u0000-\u001f\u007f]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 240);
    return message || fallback;
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
        // Each worklet chunk is 80 ms at 16 kHz. Keep a bounded, memory-only
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
        // A confirmed acoustic wake is still not sufficient to release PCM.
        // The application may synchronously or asynchronously establish its
        // durable, sideband-ready turn before this gate opens.
        this.beforeRelease = typeof options.beforeRelease === 'function'
            ? options.beforeRelease
            : () => true;
        this.onReleaseRejected = typeof options.onReleaseRejected === 'function'
            ? options.onReleaseRejected
            : () => {};
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
        const now = injected(options, 'now', () => (
            typeof globalThis.performance?.now === 'function'
                ? globalThis.performance.now()
                : Date.now()
        ));
        this.now = typeof now === 'function' ? now : Date.now;
        const requestedPcmAckTimeoutMs = Math.floor(Number(options.pcmAckTimeoutMs));
        this.pcmAckTimeoutMs = Number.isFinite(requestedPcmAckTimeoutMs)
            ? Math.max(250, Math.min(5000, requestedPcmAckTimeoutMs))
            : LOCAL_WAKE_PCM_ACK_TIMEOUT_MS;
        const requestedConsumerReadyTimeoutMs = Math.floor(Number(options.consumerReadyTimeoutMs));
        this.consumerReadyTimeoutMs = Number.isFinite(requestedConsumerReadyTimeoutMs)
            ? Math.max(1000, Math.min(60000, requestedConsumerReadyTimeoutMs))
            : LOCAL_WAKE_CONSUMER_READY_TIMEOUT_MS;
        this.state = 'idle';
        this.generation = 0;
        this.workletReady = false;
        this.audioSinkReady = false;
        this.workerReady = false;
        this.audioFlowReady = false;
        this.gateOpen = false;
        this.readyReportedGeneration = 0;
        this.pendingWakeConfirmation = null;
        this.pendingReleaseAuthorization = null;
        this.nextPcmSequence = 1;
        this.lastAcknowledgedPcmSequence = 0;
        this.consumerWakeSequenceFloor = this.consumerReady
            ? this.nextPcmSequence
            : Number.POSITIVE_INFINITY;
        this.inFlightPcm = new Set();
        this.inFlightPcmSentAt = new Map();
        this.pcmAckWatchdogTimer = null;
        this.pcmAckWatchdogToken = null;
        this.pendingWorkerFailure = null;
        this.workerFailureDetailTimer = null;
        this.consumerAdmissionWaiters = new Set();
        this.lastReadinessError = null;
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
            && !['failed', 'failure_pending', 'stopping', 'stopped'].includes(this.state);
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
        this.#resolveConsumerAdmissionWaiters(this.generation);

        return this.consumerReady;
    }

    primeConsumerAdmission() {
        if (!this.worker || !this.workletNode
            || ['failed', 'failure_pending', 'stopping', 'stopped'].includes(this.state)) return false;

        this.setConsumerReady(true);
        try {
            // Startup capture may contain speech recorded before Realtime was
            // ready. Rotate to one clean, consumer-enabled generation so the
            // published ready state can only follow fresh local PCM decode.
            return this.#rearmDormant('consumer_admission');
        } catch (cause) {
            this.#fail(new LocalWakeGateError('The local wake detector could not prime consumer admission.', {
                code: 'consumer_admission_prime_failed',
                cause,
            }), this.generation);
            return false;
        }
    }

    waitForConsumerAdmissionReady({
        generation = this.generation,
        timeoutMs = this.consumerReadyTimeoutMs,
    } = {}) {
        const expectedGeneration = Number(generation);
        if (!Number.isSafeInteger(expectedGeneration) || expectedGeneration < 0) {
            return Promise.reject(new LocalWakeGateError(
                'Consumer admission requires a valid local wake generation.',
                { code: 'invalid_readiness_generation' },
            ));
        }
        if (['failed', 'failure_pending', 'stopping', 'stopped'].includes(this.state)) {
            return Promise.reject(this.lastReadinessError || new LocalWakeGateError(
                'The local wake detector stopped before consumer admission was ready.',
                { code: 'consumer_admission_stopped' },
            ));
        }
        if (expectedGeneration !== this.generation) {
            return Promise.reject(new LocalWakeGateError(
                'The consumer admission generation was superseded.',
                { code: 'consumer_admission_generation_superseded' },
            ));
        }
        if (this.isConsumerAdmissionReady()) {
            return Promise.resolve(this.#consumerAdmissionReadyEvent(expectedGeneration));
        }

        const deadlineMs = Math.max(1000, Math.min(60000, Number(timeoutMs)
            || this.consumerReadyTimeoutMs));
        return new Promise((resolve, reject) => {
            const waiter = {
                generation: expectedGeneration,
                resolve,
                reject,
                timer: null,
            };
            waiter.timer = this.setTimeout(() => {
                if (!this.consumerAdmissionWaiters.has(waiter)
                    || waiter.generation !== this.generation) return;
                const error = new LocalWakeGateError(
                    'The local wake detector did not complete consumer admission in time.',
                    { code: 'consumer_admission_timeout' },
                );
                this.#fail(error, waiter.generation);
            }, deadlineMs);
            this.consumerAdmissionWaiters.add(waiter);
            this.#resolveConsumerAdmissionWaiters(expectedGeneration);
        });
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
        this.lastReadinessError = null;
        this.workletReady = false;
        this.audioSinkReady = false;
        this.workerReady = false;
        this.audioFlowReady = false;
        this.readyReportedGeneration = 0;
        this.pendingWakeConfirmation = null;
        this.pendingReleaseAuthorization = null;
        this.lastAcknowledgedPcmSequence = 0;
        this.consumerWakeSequenceFloor = this.consumerReady
            ? this.nextPcmSequence
            : Number.POSITIVE_INFINITY;
        this.bufferedPcm = [];
        this.localPcmRing = [];
        this.lastSourceSequence = -1;
        this.#resetPcmAckWatchdog();
        this.#clearPendingWorkerFailure();

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
                this.lastReadinessError = error;
                this.#rejectConsumerAdmissionWaiters(error, generation);
                this.#forceClosed(generation);
                this.generation += 1;
                this.state = 'failed';
                this.workletReady = false;
                this.audioSinkReady = false;
                this.workerReady = false;
                this.audioFlowReady = false;
                this.pendingWakeConfirmation = null;
                this.pendingReleaseAuthorization = null;
                this.#resetPcmAckWatchdog();
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
        this.#clearPcmAckWatchdogTimer();
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
        if (!this.worker || !this.workletNode
            || ['failed', 'failure_pending'].includes(this.state)) return false;

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

    openContextualCapture({ generation = this.generation } = {}) {
        const expectedGeneration = Number(generation);
        if (!Number.isSafeInteger(expectedGeneration)
            || expectedGeneration !== this.generation
            || this.gateOpen
            || this.pendingReleaseAuthorization
            || !this.consumerReady
            || !this.isReady()) return false;

        try {
            // No pre-admission PCM crosses this boundary. Contextual capture
            // begins from the first live worklet chunk after the durable turn
            // and Realtime input generation have both been activated.
            this.bufferedPcm = [];
            this.localPcmRing = [];
            this.#postGate(true, expectedGeneration);
            this.gateOpen = true;
            this.state = 'open';
            return true;
        } catch (cause) {
            this.#fail(new LocalWakeGateError('The contextual voice gate could not open safely.', {
                code: 'gate_open_failed',
                cause,
            }), expectedGeneration);
            return false;
        }
    }

    async stop() {
        this.#reportActivity(0, this.generation);
        const stoppedError = new LocalWakeGateError(
            'The local wake detector stopped before consumer admission was ready.',
            { code: 'consumer_admission_stopped' },
        );
        this.lastReadinessError = stoppedError;
        this.#rejectConsumerAdmissionWaiters(stoppedError);
        const closeError = this.#forceClosed(this.generation);
        if (closeError) this.#reportError(closeError);

        this.#resetPcmAckWatchdog();
        this.#clearPendingWorkerFailure();
        this.generation += 1;
        this.workletReady = false;
        this.audioSinkReady = false;
        this.workerReady = false;
        this.audioFlowReady = false;
        this.pendingWakeConfirmation = null;
        this.pendingReleaseAuthorization = null;
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
        if (generation !== this.generation || ['failed', 'failure_pending'].includes(this.state)) return;

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
                this.#fail(new LocalWakeGateError('Activated microphone audio could not reach Realtime input.', {
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
        this.inFlightPcmSentAt.set(sequence, this.#monotonicNow());
        this.#armPcmAckWatchdog(generation);

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
            this.inFlightPcmSentAt.delete(sequence);
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
        if (this.state === 'failure_pending') {
            if (data.type === 'error') {
                this.#fail(this.#workerMessageError(data), generation);
            }
            return;
        }

        if (data.type === 'ack') {
            const sequence = Number(data.sequence);
            const oldestInFlightSequence = this.inFlightPcm.values().next().value;
            if (!Number.isSafeInteger(sequence)
                || sequence < 0
                || sequence !== oldestInFlightSequence) {
                this.#fail(new LocalWakeGateError(
                    'The local wake detector returned an invalid audio acknowledgement.',
                    { code: 'invalid_pcm_ack_sequence' },
                ), generation);
                return;
            }
            const activationPending = data.accepted !== true
                && data.reason === 'activation_pending'
                && this.gateOpen;
            if (data.accepted !== true && !activationPending) {
                const fatalReason = String(data.reason || '');
                if (LOCAL_WAKE_FATAL_ACK_REASONS.has(fatalReason)) {
                    this.#beginWorkerFailureDetailWait({
                        generation,
                        reason: fatalReason,
                        sequence,
                    });
                    return;
                }
                this.#fail(new LocalWakeGateError(
                    'The local wake detector rejected live microphone processing.',
                    { code: 'pcm_decode_rejected' },
                ), generation);
                return;
            }

            this.#clearPcmAckWatchdogTimer();
            this.inFlightPcm.delete(sequence);
            this.inFlightPcmSentAt.delete(sequence);
            if (data.accepted === true) {
                this.lastAcknowledgedPcmSequence = sequence;
                this.audioFlowReady = true;
                this.#maybeBecomeReady(generation);
            }
            this.#drainBufferedPcm(generation);
            this.#armPcmAckWatchdog(generation);
            return;
        }
        if (data.type === 'error') {
            this.#fail(this.#workerMessageError(data), generation);
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
        if (data.type === 'wake_proposal') {
            this.onDiagnostic(Object.freeze({
                type: 'wake_proposal',
                generation,
                proposalType: ['strict', 'address'].includes(data.proposalType)
                    ? data.proposalType
                    : '',
                timestampCount: Number.isSafeInteger(Number(data.timestampCount))
                    ? Math.max(0, Math.min(128, Number(data.timestampCount)))
                    : null,
                requiredTailSamples: Number(data.requiredTailSamples) === 2560 ? 2560 : null,
            }));
            return;
        }
        if (data.type === 'classification_decision') {
            this.onDiagnostic(Object.freeze({
                type: 'classification_decision',
                generation,
                accepted: data.accepted === true,
                proposalType: ['strict', 'address'].includes(data.proposalType)
                    ? data.proposalType
                    : '',
                winningClass: [
                    'reject',
                    'strict_wake',
                    'missed_hey_confirmation',
                ].includes(data.winningClass) ? data.winningClass : '',
                probability: Number.isFinite(Number(data.probability))
                    ? Number(data.probability)
                    : null,
                threshold: Number.isFinite(Number(data.threshold))
                    ? Number(data.threshold)
                    : null,
                sampleCount: Number(data.sampleCount) === 21760 ? 21760 : null,
                tailSamples: Number(data.tailSamples) === 2560 ? 2560 : null,
            }));
            return;
        }
        if (data.type === 'dormant_discard') {
            if (this.gateOpen || this.pendingWakeConfirmation) return;
            this.onDiagnostic(Object.freeze({
                type: 'wake_candidate_discarded',
                generation,
                reason: String(data.reason || data.type).slice(0, 80),
                proposalSeen: data.proposalSeen === true,
                classificationDecisionSeen: data.classificationDecisionSeen === true,
            }));
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
            const activation = ['strict_wake', 'missed_hey_confirmation'].includes(data.activation)
                ? data.activation
                : null;
            const expectedPolicy = activation === 'strict_wake'
                ? 'post_address_tail'
                : 'utterance_onset';
            const expectedVariant = activation === 'strict_wake' ? 'HEY BEAN' : 'BEAN';
            if (!releaseBoundary) {
                throw new LocalWakeGateError('The confirmed wake did not include a safe local PCM boundary.', {
                    code: 'missing_release_boundary',
                });
            }
            if (!activation
                || data.keyword !== 'HEY_BEAN'
                || data.variant !== expectedVariant
                || releaseBoundary.policy !== expectedPolicy) {
                throw new LocalWakeGateError('The confirmed wake did not match its accepted acoustic class.', {
                    code: 'invalid_wake_classification',
                });
            }
            if (!Number.isSafeInteger(detectedSourceSequence) || detectedSourceSequence < 0
                || releaseBoundary.sourceSequence > detectedSourceSequence) {
                throw new LocalWakeGateError('The confirmed wake reported an invalid source boundary.', {
                    code: 'invalid_release_boundary',
                });
            }
            const detection = Object.freeze({
                type: 'wake_confirmed',
                generation,
                keyword: data.keyword,
                variant: data.variant,
                activation,
                sourceSequence: detectedSourceSequence,
                releaseBoundary: Object.freeze({ ...releaseBoundary }),
            });
            this.pendingWakeConfirmation = null;
            const authorizationToken = Object.freeze({ generation, detection });
            this.pendingReleaseAuthorization = authorizationToken;
            const authorization = this.beforeRelease(detection);
            if (authorization && typeof authorization.then === 'function') {
                this.state = 'admission_pending';
                Promise.resolve(authorization).then(
                    (approved) => this.#completeReleaseAuthorization(
                        authorizationToken,
                        approved === true,
                        releaseBoundary,
                    ),
                    (error) => this.#completeReleaseAuthorization(
                        authorizationToken,
                        false,
                        releaseBoundary,
                        error,
                    ),
                );
                return;
            }
            this.#completeReleaseAuthorization(
                authorizationToken,
                authorization === true,
                releaseBoundary,
            );
        } catch (cause) {
            this.#fail(new LocalWakeGateError('The local wake gate could not open safely.', {
                code: 'gate_open_failed',
                cause,
            }), generation);
        }
    }

    #completeReleaseAuthorization(token, approved, releaseBoundary, error = null) {
        if (this.pendingReleaseAuthorization !== token
            || token.generation !== this.generation
            || this.gateOpen
            || ['failed', 'failure_pending', 'stopping', 'stopped'].includes(this.state)) return;
        this.pendingReleaseAuthorization = null;
        if (!approved) {
            try {
                this.onReleaseRejected(Object.freeze({
                    ...token.detection,
                    error: error || null,
                }));
            } catch (_) {}
            this.#rearmAfterRejectedDormantAudio(token.generation, 'pre_admission_rejected');
            return;
        }

        try {
            this.bufferedPcm = [];
            this.onDetected(token.detection);
            if (token.generation !== this.generation
                || ['failed', 'failure_pending', 'stopping', 'stopped'].includes(this.state)) return;
            this.#postGate(true, token.generation);
            this.gateOpen = true;
            this.state = 'open';
            this.#flushLocalPcm(releaseBoundary, token.generation);
        } catch (cause) {
            this.#fail(new LocalWakeGateError('The local wake gate could not open safely.', {
                code: 'gate_open_failed',
                cause,
            }), token.generation);
        }
    }

    #maybeBecomeReady(generation) {
        if (generation !== this.generation || !this.isReady()) return;

        if (!this.gateOpen) this.state = 'armed';
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
        this.#resolveConsumerAdmissionWaiters(generation);

        if (this.pendingWakeConfirmation && this.consumerReady) {
            const confirmation = this.pendingWakeConfirmation;
            this.pendingWakeConfirmation = null;
            this.#confirmWake(confirmation, generation);
        }
    }

    #consumerAdmissionReadyEvent(generation) {
        return Object.freeze({
            type: 'consumer_admission_ready',
            generation,
            barriers: Object.freeze({
                worklet: true,
                model: true,
                warmDecode: true,
                recognitionStream: true,
                localPcmCapture: true,
                liveAudioDecode: true,
                consumerGeneration: true,
            }),
        });
    }

    #resolveConsumerAdmissionWaiters(generation) {
        if (generation !== this.generation || !this.isConsumerAdmissionReady()) return;
        const event = this.#consumerAdmissionReadyEvent(generation);
        for (const waiter of [...this.consumerAdmissionWaiters]) {
            if (waiter.generation !== generation) continue;
            this.consumerAdmissionWaiters.delete(waiter);
            if (waiter.timer !== null) this.clearTimeout(waiter.timer);
            waiter.timer = null;
            waiter.resolve(event);
        }
    }

    #rejectConsumerAdmissionWaiters(error, generation = null) {
        for (const waiter of [...this.consumerAdmissionWaiters]) {
            if (generation !== null && waiter.generation !== generation) continue;
            this.consumerAdmissionWaiters.delete(waiter);
            if (waiter.timer !== null) this.clearTimeout(waiter.timer);
            waiter.timer = null;
            waiter.reject(error);
        }
    }

    #rearmAfterRejectedDormantAudio(generation, reason) {
        if (generation !== this.generation || this.gateOpen
            || ['failed', 'failure_pending'].includes(this.state)) return;

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
        if (!this.worker || !this.workletNode
            || ['failed', 'failure_pending'].includes(this.state)) return false;

        const supersededGeneration = this.generation;
        this.#rejectConsumerAdmissionWaiters(new LocalWakeGateError(
            'The consumer admission generation was superseded.',
            { code: 'consumer_admission_generation_superseded' },
        ), supersededGeneration);
        const generation = this.generation + 1;
        this.generation = generation;
        this.workletReady = false;
        this.workerReady = false;
        this.audioFlowReady = false;
        this.readyReportedGeneration = 0;
        this.pendingWakeConfirmation = null;
        this.pendingReleaseAuthorization = null;
        this.lastAcknowledgedPcmSequence = 0;
        this.consumerWakeSequenceFloor = this.consumerReady
            ? this.nextPcmSequence
            : Number.POSITIVE_INFINITY;
        this.#resetPcmAckWatchdog();
        this.#clearPendingWorkerFailure();
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

    #monotonicNow() {
        const value = Number(this.now());
        return Number.isFinite(value) ? value : Date.now();
    }

    #armPcmAckWatchdog(generation) {
        if (this.pcmAckWatchdogTimer !== null
            || generation !== this.generation
            || ['failed', 'failure_pending', 'stopping', 'stopped'].includes(this.state)) return;

        const sequence = this.inFlightPcm.values().next().value;
        if (!Number.isSafeInteger(sequence)) return;
        const sentAt = Number(this.inFlightPcmSentAt.get(sequence));
        const elapsed = Number.isFinite(sentAt)
            ? Math.max(0, this.#monotonicNow() - sentAt)
            : this.pcmAckTimeoutMs;
        const remaining = Math.max(0, this.pcmAckTimeoutMs - elapsed);
        const token = Object.freeze({ generation, sequence });
        this.pcmAckWatchdogToken = token;
        this.pcmAckWatchdogTimer = this.setTimeout(() => {
            if (this.pcmAckWatchdogToken !== token) return;
            this.pcmAckWatchdogTimer = null;
            this.pcmAckWatchdogToken = null;
            if (generation !== this.generation
                || this.inFlightPcm.values().next().value !== sequence
                || ['failed', 'failure_pending', 'stopping', 'stopped'].includes(this.state)) return;
            this.#fail(new LocalWakeGateError(
                'The local wake detector stopped acknowledging microphone audio.',
                { code: 'pcm_ack_timeout' },
            ), generation);
        }, remaining);
    }

    #clearPcmAckWatchdogTimer() {
        if (this.pcmAckWatchdogTimer !== null) {
            this.clearTimeout(this.pcmAckWatchdogTimer);
        }
        this.pcmAckWatchdogTimer = null;
        this.pcmAckWatchdogToken = null;
    }

    #resetPcmAckWatchdog() {
        this.#clearPcmAckWatchdogTimer();
        this.inFlightPcm.clear();
        this.inFlightPcmSentAt.clear();
    }

    #workerMessageError(data) {
        const pending = this.pendingWorkerFailure;
        const declaredCode = sanitizedWorkerFailureCode(data?.code);
        const code = pending?.reason || declaredCode;
        const message = sanitizedWorkerFailureMessage(
            data?.message,
            code === 'decode_failed'
                ? 'The local wake detector failed while decoding microphone audio.'
                : 'The local wake detector failed.',
        );

        return new LocalWakeGateError(message, {
            code,
            ...(pending?.cause ? { cause: pending.cause } : {}),
        });
    }

    #beginWorkerFailureDetailWait({ generation, reason, sequence }) {
        if (generation !== this.generation
            || !LOCAL_WAKE_FATAL_ACK_REASONS.has(reason)
            || ['failed', 'failure_pending', 'stopping', 'stopped'].includes(this.state)) return;

        this.#forceClosed(generation);
        this.#resetPcmAckWatchdog();
        this.workletReady = false;
        this.audioSinkReady = false;
        this.workerReady = false;
        this.audioFlowReady = false;
        this.pendingWakeConfirmation = null;
        this.bufferedPcm = [];
        this.localPcmRing = [];
        this.lastSourceSequence = -1;
        this.state = 'failure_pending';

        // Stop capture synchronously while retaining only the worker message
        // handler for its already-posted, ordered fatal detail.
        this.#disconnect(this.sourceNode);
        this.#disconnect(this.workletNode);
        this.#stopTracks(this.rawStream);

        const pending = {
            generation,
            reason,
            sequence,
            cause: new LocalWakeGateError(
                'The local wake detector rejected microphone decoding before its fatal detail.',
                { code: 'pcm_decode_rejected' },
            ),
        };
        this.pendingWorkerFailure = pending;
        this.workerFailureDetailTimer = this.setTimeout(() => {
            if (this.pendingWorkerFailure !== pending
                || generation !== this.generation
                || this.state !== 'failure_pending') return;
            this.workerFailureDetailTimer = null;
            this.#fail(new LocalWakeGateError(
                'The local wake detector failed while decoding microphone audio.',
                { code: reason, cause: pending.cause },
            ), generation);
        }, LOCAL_WAKE_WORKER_FAILURE_DETAIL_TIMEOUT_MS);
    }

    #clearPendingWorkerFailure() {
        if (this.workerFailureDetailTimer !== null) {
            this.clearTimeout(this.workerFailureDetailTimer);
        }
        this.workerFailureDetailTimer = null;
        this.pendingWorkerFailure = null;
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

        this.lastReadinessError = error;
        this.#rejectConsumerAdmissionWaiters(error, generation);
        this.#clearPendingWorkerFailure();
        const closeError = this.#forceClosed(generation);
        this.#resetPcmAckWatchdog();
        this.generation += 1;
        this.workletReady = false;
        this.audioSinkReady = false;
        this.workerReady = false;
        this.audioFlowReady = false;
        this.pendingWakeConfirmation = null;
        this.pendingReleaseAuthorization = null;
        this.bufferedPcm = [];
        this.localPcmRing = [];
        this.lastSourceSequence = -1;
        this.state = 'failed';
        void this.#teardown();
        this.#reportError(closeError || error);
    }

    async #teardown() {
        this.#resetPcmAckWatchdog();
        this.#clearPendingWorkerFailure();
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
