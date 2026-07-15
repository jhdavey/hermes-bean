import { LocalWakeGate } from './localWakeGate.js';
import { BeanVoicePcmTransport } from './beanVoicePcmTransport.js';
import {
    acquireRealtimeMicrophone,
    realtimeMicrophoneConstraints,
} from './beanVoiceBrowserSupport.js';
import { BeanVoiceProjectionStream } from './beanVoiceProjectionStream.js';
import { BeanVoiceRealtimeTransport } from './beanVoiceRealtimeTransport.js';

export const BEAN_VOICE_MODES = Object.freeze({
    OFF: 'off',
    STARTING: 'starting',
    WAKE_ONLY: 'wake_only',
    PRE_ADMITTING: 'pre_admitting',
    LISTENING: 'listening',
    THINKING: 'thinking',
    WORKING: 'working',
    SPEAKING: 'speaking',
    FOLLOW_UP: 'follow_up',
    FAILED: 'failed',
});

function text(value) {
    return String(value ?? '').trim();
}

function integer(value, fallback = 0) {
    const number = Number(value);
    return Number.isSafeInteger(number) && number >= 0 ? number : fallback;
}

function numericIdentifier(value) {
    const number = Number(value);
    return Number.isSafeInteger(number) && number > 0 ? number : text(value);
}

function stableTurnId(randomUUID = () => globalThis.crypto?.randomUUID?.()) {
    let suffix = '';
    try { suffix = text(randomUUID?.()); } catch (_) {}
    if (!suffix) suffix = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    return `browser-voice-${suffix}`;
}

function createLocalAudioContext() {
    const AudioContext = globalThis.AudioContext || globalThis.webkitAudioContext;
    return typeof AudioContext === 'function' ? new AudioContext() : null;
}

function primeLocalAudioContext(audioContext) {
    if (audioContext?.state !== 'suspended' || typeof audioContext.resume !== 'function') return;
    const result = audioContext.resume();
    result?.catch?.(() => {});
}

function viewState(runtime) {
    const mode = runtime.mode;
    const activityVisible = runtime.enabled && [
        BEAN_VOICE_MODES.WAKE_ONLY,
        BEAN_VOICE_MODES.PRE_ADMITTING,
        BEAN_VOICE_MODES.LISTENING,
        BEAN_VOICE_MODES.SPEAKING,
        BEAN_VOICE_MODES.FOLLOW_UP,
    ].includes(mode);
    return Object.freeze({
        mode,
        enabled: runtime.enabled,
        listening: runtime.enabled && [
            BEAN_VOICE_MODES.WAKE_ONLY,
            BEAN_VOICE_MODES.PRE_ADMITTING,
            BEAN_VOICE_MODES.LISTENING,
            BEAN_VOICE_MODES.FOLLOW_UP,
        ].includes(mode),
        recording: activityVisible && runtime.activityLevel >= 0.055,
        processing: [
            BEAN_VOICE_MODES.STARTING,
            BEAN_VOICE_MODES.PRE_ADMITTING,
            BEAN_VOICE_MODES.THINKING,
            BEAN_VOICE_MODES.WORKING,
        ].includes(mode),
        thinking: mode === BEAN_VOICE_MODES.THINKING,
        working: mode === BEAN_VOICE_MODES.WORKING,
        speaking: mode === BEAN_VOICE_MODES.SPEAKING,
        followUp: mode === BEAN_VOICE_MODES.FOLLOW_UP,
        activityLevel: runtime.activityLevel,
        playbackActive: Boolean(runtime.transport?.snapshot?.().playbackActive),
        activeTurnId: runtime.activeTurnId || null,
        realtimeSessionId: runtime.realtimeSessionId || null,
        error: runtime.error || '',
    });
}

/**
 * The sole browser voice orchestrator. It owns media and ephemeral UI state;
 * Laravel owns semantic interpretation, tools, durable lifecycle, and speech
 * authorization. No transcript or assistant response text enters this class.
 */
export class BeanVoiceRuntime {
    constructor({
        request,
        openProjectionStream,
        ensureConversationSession,
        openRealtimeSession,
        currentWorkspaceId = () => null,
        acquireMicrophone = null,
        getUserMedia = null,
        localAudioContextFactory = createLocalAudioContext,
        localWakeGateFactory = (options) => new LocalWakeGate(options),
        inputTransportFactory = (options) => new BeanVoicePcmTransport(options),
        transportFactory = (options) => new BeanVoiceRealtimeTransport(options),
        projectionFactory = (options) => new BeanVoiceProjectionStream(options),
        createTurnId = stableTurnId,
        onViewState = () => {},
        onWorkProjection = () => {},
        onDashboardInvalidated = () => {},
        onFailure = () => {},
        timers = {},
        clock = () => Date.now(),
        followUpMs = 15000,
    } = {}) {
        if (typeof request !== 'function'
            || typeof openProjectionStream !== 'function'
            || typeof ensureConversationSession !== 'function'
            || typeof openRealtimeSession !== 'function') {
            throw new TypeError('BeanVoiceRuntime requires application, Realtime, and projection transports.');
        }
        this.request = request;
        this.openProjectionStream = openProjectionStream;
        this.ensureConversationSession = ensureConversationSession;
        this.openRealtimeSession = openRealtimeSession;
        this.currentWorkspaceId = currentWorkspaceId;
        this.acquireMicrophone = acquireMicrophone || (() => acquireRealtimeMicrophone(
            getUserMedia || ((constraints) => navigator.mediaDevices.getUserMedia(constraints)),
            realtimeMicrophoneConstraints(),
        ));
        this.localAudioContextFactory = typeof localAudioContextFactory === 'function'
            ? localAudioContextFactory
            : createLocalAudioContext;
        this.localWakeGateFactory = localWakeGateFactory;
        this.createTurnId = createTurnId;
        this.onViewState = onViewState;
        this.onWorkProjection = onWorkProjection;
        this.onDashboardInvalidated = onDashboardInvalidated;
        this.onFailure = onFailure;
        this.setTimeout = timers.setTimeout || globalThis.setTimeout?.bind(globalThis);
        this.clearTimeout = timers.clearTimeout || globalThis.clearTimeout?.bind(globalThis);
        this.clock = clock;
        this.followUpMs = Math.max(1000, Number(followUpMs) || 15000);

        this.mode = BEAN_VOICE_MODES.OFF;
        this.enabled = false;
        this.error = '';
        this.activityLevel = 0;
        this.generation = 0;
        this.providerConnectionGeneration = 0;
        this.conversationSessionId = '';
        this.realtimeSessionId = '';
        this.activeTurnId = '';
        this.conversationEpoch = 0;
        this.localWakeGate = null;
        this.rawMicrophoneStream = null;
        this.followUpTimer = null;
        this.activityTimer = null;
        this.pendingBarge = null;
        this.pendingContextualCapture = null;
        this.turnStates = new Map();
        this.stopDirectiveIds = new Set();
        this.closeAfterResponseTurnIds = new Set();
        this.deliveryKeys = new Set();
        this.deliveryInFlight = new Map();

        let transport = null;
        this.inputTransport = inputTransportFactory({
            send: (event) => transport?.sendInputEvent(event) === true,
            bufferedAmount: () => transport?.bufferedAmount() || 0,
        });
        transport = transportFactory({
            openSession: (sdp, context) => this.openRealtimeSession(sdp, context),
            inputTransport: this.inputTransport,
            onEvent: (event) => this.#handleRealtimeEvent(event),
            onFailure: (error, stage) => this.#handleFailure(error, stage),
            timers,
        });
        this.transport = transport;
        this.projection = projectionFactory({
            request,
            openStream: openProjectionStream,
            onProjection: (projection, context) => this.#handleProjection(projection, context),
            onError: (error, context) => this.#handleProjectionError(error, context),
            timers,
        });
    }

    snapshot() {
        return viewState(this);
    }

    prime() {
        return this.transport.prime();
    }

    attachSession(sessionId) {
        const normalized = text(sessionId);
        if (!normalized) return false;
        if (this.conversationSessionId === normalized) return true;
        this.conversationSessionId = normalized;
        if (this.enabled && this.realtimeSessionId) this.projection.start(normalized);
        return true;
    }

    detachSession() {
        this.conversationSessionId = '';
        this.projection.stop();
    }

    async start() {
        if (this.enabled || this.mode === BEAN_VOICE_MODES.STARTING) return false;
        this.prime();
        const generation = ++this.generation;
        this.providerConnectionGeneration += 1;
        this.enabled = true;
        this.error = '';
        this.mode = BEAN_VOICE_MODES.STARTING;
        this.#emitView();

        let startupLocalWakeFailure = null;
        let preparedAudioContext = null;
        let gate = null;
        try {
            // Web Audio must be created and resumed in the original button
            // gesture. Conversation and microphone setup are asynchronous and
            // would otherwise consume the browser's transient activation.
            preparedAudioContext = this.localAudioContextFactory();
            try {
                gate = this.localWakeGateFactory({
                    audioContext: preparedAudioContext,
                    consumerReady: false,
                    beforeRelease: (detection) => this.#preAdmitWake(detection, generation),
                    onDetected: (detection) => this.#wakeReleased(detection, generation),
                    onReleaseRejected: (event) => this.#wakeReleaseRejected(event, generation),
                    onReady: (event) => this.#localGateReady(event, generation),
                    onActivatedPcm: (event) => {
                        if (!this.#current(generation) || this.transport.appendActivatedPcm(event) !== true) {
                            throw new Error('Activated microphone PCM was not accepted by Realtime.');
                        }
                    },
                    onActivity: ({ level }) => this.#updateActivity(level, generation),
                    onDiagnostic: (diagnostic) => this.#diagnostic(diagnostic),
                    onError: (error) => {
                        if (!this.#current(generation)) return;
                        if (this.mode === BEAN_VOICE_MODES.STARTING) {
                            startupLocalWakeFailure = startupLocalWakeFailure || error;
                            return;
                        }
                        this.#handleFailure(error, 'local_wake');
                    },
                });
            } catch (error) {
                try { preparedAudioContext?.close?.(); } catch (_) {}
                throw error;
            }
            this.localWakeGate = gate;
            primeLocalAudioContext(preparedAudioContext);

            const sessionPromise = this.ensureConversationSession();
            const microphonePromise = Promise.resolve(this.acquireMicrophone()).then((stream) => {
                if (!this.#current(generation) || this.localWakeGate !== gate) {
                    stream?.getTracks?.().forEach((track) => track.stop());
                    return null;
                }
                this.rawMicrophoneStream = stream;
                return stream;
            });
            const [session, rawMicrophoneStream] = await Promise.all([
                sessionPromise,
                microphonePromise,
            ]);
            if (!this.#current(generation) || this.localWakeGate !== gate) {
                rawMicrophoneStream?.getTracks?.().forEach((track) => track.stop());
                return false;
            }
            const sessionId = text(session?.id || session?.session_id || session?.sessionId);
            if (!sessionId) throw new Error('Bean voice could not establish its conversation session.');
            this.conversationSessionId = sessionId;
            this.rawMicrophoneStream = rawMicrophoneStream;
            const [localAudio, realtimeSession] = await Promise.all([
                gate.start(rawMicrophoneStream),
                this.transport.connect({
                    controllerGeneration: generation,
                    providerConnectionGeneration: this.providerConnectionGeneration,
                    context: Object.freeze({
                        conversationSessionId: sessionId,
                        workspaceId: this.currentWorkspaceId() || null,
                        controllerGeneration: generation,
                        providerConnectionGeneration: this.providerConnectionGeneration,
                    }),
                }),
            ]);
            if (!this.#current(generation) || this.localWakeGate !== gate) return false;
            if (Number(localAudio?.sampleRate) !== 16000) {
                throw new Error('Private wake detection did not provide 16 kHz local PCM.');
            }
            this.realtimeSessionId = text(realtimeSession?.realtimeSessionId);
            if (!this.realtimeSessionId) throw new Error('Bean voice did not receive its public Realtime session ID.');
            const consumerGeneration = Number(gate.primeConsumerAdmission?.());
            if (!Number.isSafeInteger(consumerGeneration) || consumerGeneration < 0) {
                throw new Error('Private wake detection could not prime a clean consumer generation.');
            }
            const localReadiness = await gate.waitForConsumerAdmissionReady?.({
                generation: consumerGeneration,
            });
            if (!this.#current(generation) || this.localWakeGate !== gate) return false;
            if (Number(localReadiness?.generation) !== consumerGeneration) {
                throw new Error('Private wake detection completed a stale admission barrier.');
            }
            if (!gate.isConsumerAdmissionReady()) {
                throw new Error('Private wake detection did not complete its admission barrier.');
            }
            this.projection.start(sessionId);
            this.#clearActivityTimer();
            this.activityLevel = 0;
            this.mode = BEAN_VOICE_MODES.WAKE_ONLY;
            this.#emitView();
            return true;
        } catch (error) {
            if (!this.#current(generation)) return false;
            this.#handleFailure(startupLocalWakeFailure || error, 'startup');
            await this.stop('startup_failed');
            this.mode = BEAN_VOICE_MODES.FAILED;
            this.error = 'Bean couldn’t connect voice right now. Tap Bean to try again.';
            this.#emitView();
            return false;
        }
    }

    async stop(reason = 'voice_stopped') {
        const pendingCapture = this.pendingContextualCapture;
        if (pendingCapture && !pendingCapture.clarification && !pendingCapture.speechStarted) {
            void this.#discardUnstartedTurn(pendingCapture.turnId, reason);
        }
        this.enabled = false;
        this.generation += 1;
        this.#clearFollowUpTimer();
        this.#clearActivityTimer();
        this.projection.stop();
        this.transport.close(reason);
        const gate = this.localWakeGate;
        this.localWakeGate = null;
        this.rawMicrophoneStream?.getTracks?.().forEach((track) => track.stop());
        this.rawMicrophoneStream = null;
        this.realtimeSessionId = '';
        this.activeTurnId = '';
        this.conversationEpoch = 0;
        this.pendingBarge = null;
        this.pendingContextualCapture = null;
        this.activityLevel = 0;
        this.turnStates.clear();
        this.stopDirectiveIds.clear();
        this.closeAfterResponseTurnIds.clear();
        this.deliveryKeys.clear();
        this.deliveryInFlight.clear();
        this.mode = BEAN_VOICE_MODES.OFF;
        this.#emitView();
        try { await gate?.stop?.(); } catch (_) {}
        return true;
    }

    toggle(event = null) {
        event?.preventDefault?.();
        if (this.enabled || this.mode === BEAN_VOICE_MODES.STARTING) return this.stop('button_toggle');
        return this.start();
    }

    stopPlayback(reason = 'button_stop', directiveId = '', directiveTurnId = '') {
        const current = this.transport.snapshot().activeResponse;
        if (!this.transport.stopPlayback(reason)) {
            if (!text(directiveId) || !text(directiveTurnId)) return false;
            void this.#delivery({ turnId: text(directiveTurnId) }, 'playback_stopped', reason, {
                directiveId: text(directiveId),
            });
            return true;
        }
        if (current?.turnId) {
            void this.#delivery({
                ...current,
                turnId: text(directiveTurnId) || current.turnId,
            }, 'playback_stopped', reason, {
                directiveId: text(directiveId),
            });
        }
        this.pendingBarge = null;
        return true;
    }

    dispose() {
        return this.stop('disposed');
    }

    async #preAdmitWake(detection, generation) {
        if (!this.#current(generation)
            || this.localWakeGate?.currentGeneration() !== detection.generation
            || !this.realtimeSessionId) return false;
        const turnId = text(this.createTurnId());
        if (!turnId) return false;
        const startedAtMs = integer(this.clock());
        const conversationEpoch = Math.max(1, this.conversationEpoch + 1);
        const previousCapture = this.pendingContextualCapture;
        if (previousCapture && !previousCapture.clarification && !previousCapture.speechStarted) {
            void this.#discardUnstartedTurn(previousCapture.turnId, 'superseded_by_wake');
        }
        this.pendingContextualCapture = null;
        this.#clearFollowUpTimer();
        this.activeTurnId = turnId;
        this.mode = BEAN_VOICE_MODES.PRE_ADMITTING;
        this.#emitView();
        try {
            const admitted = await this.request('/assistant/voice/turns', {
                method: 'POST',
                body: {
                    session_id: numericIdentifier(this.conversationSessionId),
                    turn_id: turnId,
                    realtime_session_id: this.realtimeSessionId,
                    controller_generation: generation,
                    provider_connection_generation: this.providerConnectionGeneration,
                    input_generation: integer(detection.generation),
                    wake_detected_at_ms: startedAtMs,
                    client_milestones: {
                        wake_detected_at_ms: startedAtMs,
                        pre_admission_started_at_ms: startedAtMs,
                    },
                    conversation_context: {
                        mode: 'new_conversation',
                        epoch: conversationEpoch,
                    },
                },
                timeoutMs: 8000,
            });
            const responseTurnId = text(admitted?.turn_id || admitted?.turnId);
            if (!this.#current(generation)
                || responseTurnId !== turnId
                || admitted?.sideband_ready !== true) {
                if (responseTurnId === turnId) {
                    await this.#discardUnstartedTurn(turnId, 'admission_not_ready');
                }
                if (this.#current(generation)) {
                    this.#handleFailure(
                        new Error('Voice pre-admission was not sideband-ready for this turn.'),
                        'admission',
                        turnId,
                    );
                }
                return false;
            }
            if (this.transport.activateInput(detection.generation) !== true) {
                throw new Error('Realtime input did not accept the pre-admitted turn generation.');
            }
            this.conversationEpoch = conversationEpoch;
            this.turnStates.set(turnId, text(admitted?.state || 'pre_admitted'));
            return true;
        } catch (error) {
            await this.#discardUnstartedTurn(turnId, 'admission_failed');
            this.#handleFailure(error, 'admission', turnId);
            return false;
        }
    }

    #wakeReleased(detection, generation) {
        if (!this.#current(generation) || detection.generation !== this.localWakeGate?.currentGeneration()) return;
        this.mode = BEAN_VOICE_MODES.LISTENING;
        this.error = '';
        this.#emitView();
    }

    #wakeReleaseRejected(event, generation) {
        if (!this.#current(generation)) return;
        this.transport.deactivateInput();
        this.turnStates.delete(this.activeTurnId);
        this.activeTurnId = '';
        this.mode = BEAN_VOICE_MODES.WAKE_ONLY;
        this.error = 'Bean could not safely start that voice turn. Please say “Hey Bean” again.';
        this.#emitView();
    }

    #handleRealtimeEvent(event) {
        if (!event?.type) return;
        if (event.type === 'transport_ready') return;
        if (event.type === 'input_speech_started') {
            this.#clearFollowUpTimer();
            if (this.pendingContextualCapture) {
                this.pendingContextualCapture.windowOpen = false;
                this.pendingContextualCapture.speechStarted = true;
            }
            if (this.transport.snapshot().playbackActive) {
                this.pendingBarge = Object.freeze({
                    providerItemId: event.providerItemId || null,
                    responseId: this.transport.snapshot().activeResponse?.responseId || null,
                });
                if (this.transport.duck('potential_barge_in')) {
                    const active = this.transport.snapshot().activeResponse;
                    if (active?.turnId) void this.#delivery(active, 'potential_interruption', 'provider_vad');
                }
            } else {
                this.mode = BEAN_VOICE_MODES.LISTENING;
                this.#emitView();
            }
            return;
        }
        if (event.type === 'input_speech_stopped') {
            if (!this.pendingBarge) {
                this.mode = BEAN_VOICE_MODES.THINKING;
                this.#emitView();
            }
            return;
        }
        if (event.type === 'input_committed') {
            if (this.pendingContextualCapture) this.pendingContextualCapture.committed = true;
            this.transport.deactivateInput();
            this.localWakeGate?.close?.();
            if (!this.pendingBarge && !this.transport.snapshot().playbackActive) {
                this.mode = BEAN_VOICE_MODES.THINKING;
                this.#emitView();
            }
            return;
        }
        if (event.type === 'playback_started') {
            this.pendingBarge = null;
            this.error = '';
            this.mode = BEAN_VOICE_MODES.SPEAKING;
            this.#emitView();
            void this.#prepareContextualCapture(event);
            void this.#delivery(event, 'playback_started');
            if (event.purpose === 'acknowledgement') void this.#delivery(event, 'acknowledgement_started');
            if (event.purpose === 'final') void this.#delivery(event, 'final_audio_started');
            return;
        }
        if (event.type === 'playback_finished') {
            this.pendingBarge = null;
            void this.#delivery(event, 'playback_finished', event.reason);
            if (event.purpose === 'final' && this.closeAfterResponseTurnIds.has(event.turnId)) {
                this.#returnToWakeOnly('conversation_closed');
            } else {
                this.#enterContextualWindow();
            }
            return;
        }
        if (event.type === 'playback_stopped') {
            this.pendingBarge = null;
            if (event.reason === 'meaningful_barge_in') {
                this.mode = BEAN_VOICE_MODES.THINKING;
                this.#emitView();
            } else if (event.purpose === 'clarification') {
                this.#enterContextualWindow();
            } else {
                this.#returnToWakeOnly('playback_stopped');
            }
            return;
        }
        if (event.type === 'playback_restored') {
            this.pendingBarge = null;
            this.mode = BEAN_VOICE_MODES.SPEAKING;
            this.#emitView();
        }
    }

    #handleProjection(projection) {
        projection.speechAuthorizations.forEach((authorization) => {
            this.transport.authorizeSpeech(authorization);
        });
        projection.turns.forEach((turn) => {
            const previous = this.turnStates.get(turn.turnId);
            this.turnStates.set(turn.turnId, turn.state);
            if (turn.closeAfterResponse) this.closeAfterResponseTurnIds.add(turn.turnId);
            if (turn.stopPlayback && turn.stopPlaybackDirectiveId
                && !this.stopDirectiveIds.has(turn.stopPlaybackDirectiveId)) {
                this.stopDirectiveIds.add(turn.stopPlaybackDirectiveId);
                this.stopPlayback(
                    'semantic_spoken_stop',
                    turn.stopPlaybackDirectiveId,
                    turn.turnId,
                );
            }
            const active = this.transport.snapshot().activeResponse;
            const clarificationContinuation = active?.purpose === 'clarification'
                && this.pendingContextualCapture?.opened === true
                && this.pendingContextualCapture.turnId === turn.turnId;
            if (this.pendingBarge
                && active?.turnId
                && (turn.turnId !== active.turnId || clarificationContinuation)
                && previous !== turn.state
                && ['accepted', 'running', 'awaiting_clarification', 'completed'].includes(turn.state)) {
                if (active?.turnId) void this.#delivery(active, 'interruption_confirmed', 'durable_turn_admitted');
                this.transport.stopPlayback('meaningful_barge_in');
                this.pendingBarge = null;
                this.activeTurnId = turn.turnId;
                this.mode = BEAN_VOICE_MODES.THINKING;
                this.#emitView();
            } else if (this.pendingBarge
                && active?.turnId
                && (turn.turnId !== active.turnId || clarificationContinuation)
                && previous !== turn.state
                && ['failed', 'canceled'].includes(turn.state)) {
                if (this.transport.restore(`durable_turn_${turn.state}`)) {
                    void this.#delivery(active, 'interruption_rejected', `durable_turn_${turn.state}`);
                }
                this.pendingBarge = null;
            }
            if (this.pendingContextualCapture?.turnId === turn.turnId
                && ['failed', 'canceled'].includes(turn.state)) {
                this.pendingContextualCapture = null;
                this.transport.deactivateInput();
                this.localWakeGate?.close?.();
            }
            if (turn.turnId === this.activeTurnId
                && ['failed', 'canceled'].includes(turn.state)
                && !this.transport.snapshot().playbackActive
                && [
                    BEAN_VOICE_MODES.PRE_ADMITTING,
                    BEAN_VOICE_MODES.LISTENING,
                    BEAN_VOICE_MODES.THINKING,
                ].includes(this.mode)) {
                if (turn.state === 'failed') {
                    this.error = 'Bean couldn’t finish that voice request. Please try again.';
                }
                this.localWakeGate?.close?.();
                this.#returnToWakeOnly(`turn_${turn.state}`);
            }
        });
        projection.events.forEach((event) => {
            if (!this.pendingBarge) return;
            if (['interruption_rejected', 'barge_in_rejected', 'input_rejected'].includes(event.type)) {
                const active = this.transport.snapshot().activeResponse;
                if (this.transport.restore(event.type) && active?.turnId) {
                    void this.#delivery(active, 'interruption_rejected', event.type);
                }
                this.pendingBarge = null;
            }
        });
        this.onWorkProjection(Object.freeze({
            jobs: projection.jobs,
            activeJobs: projection.activeJobs,
            activeTurns: projection.activeTurns,
        }));
        if (projection.dashboardInvalidations.length) {
            this.onDashboardInvalidated(projection.dashboardInvalidations);
        }
        const captureMode = [
            BEAN_VOICE_MODES.PRE_ADMITTING,
            BEAN_VOICE_MODES.LISTENING,
            BEAN_VOICE_MODES.FOLLOW_UP,
        ].includes(this.mode);
        const processingTurnActive = projection.activeTurns.some((turn) => (
            ['accepted', 'running'].includes(turn.state)
        ));
        if (projection.activeJobs.length
            && !this.transport.snapshot().playbackActive
            && !captureMode) {
            this.mode = BEAN_VOICE_MODES.WORKING;
            this.#emitView();
        } else if (processingTurnActive
            && !this.transport.snapshot().playbackActive
            && !captureMode) {
            this.mode = BEAN_VOICE_MODES.THINKING;
            this.#emitView();
        }
    }

    #handleProjectionError(error, context) {
        if (Number(context?.failureCount || 0) < 3) return;
        this.#handleFailure(error, 'projection');
    }

    async #delivery(item, event, reason = '', { directiveId = '' } = {}) {
        const turnId = text(item?.turnId);
        const speechItemId = text(item?.speechItemId);
        if (!turnId || !this.conversationSessionId) return false;
        const key = `${turnId}:${speechItemId}:${event}:${reason}:${text(directiveId)}`;
        if (this.deliveryKeys.has(key)) return false;
        if (this.deliveryInFlight.has(key)) return this.deliveryInFlight.get(key);
        const request = this.request(`/assistant/voice/turns/${encodeURIComponent(turnId)}/delivery`, {
            method: 'POST',
            body: {
                session_id: numericIdentifier(this.conversationSessionId),
                event,
                timing: {
                    occurred_at_ms: integer(this.clock()),
                    ...(speechItemId ? { speech_item_id: speechItemId } : {}),
                    controller_generation: this.generation,
                    provider_connection_generation: this.providerConnectionGeneration,
                    ...(item?.purpose ? { purpose: text(item.purpose) } : {}),
                    ...(reason ? { reason } : {}),
                    ...(directiveId ? { directive_id: text(directiveId) } : {}),
                },
            },
            timeoutMs: 5000,
        }).then(() => {
            this.deliveryKeys.add(key);
            return true;
        }).catch((error) => {
            this.#handleFailure(error, 'delivery', turnId);
            return false;
        }).finally(() => {
            this.deliveryInFlight.delete(key);
        });
        this.deliveryInFlight.set(key, request);
        return request;
    }

    async #prepareContextualCapture(response) {
        if (!this.enabled || !this.localWakeGate || this.conversationEpoch < 1) return false;
        this.#clearFollowUpTimer();
        const purpose = text(response?.purpose).toLowerCase();
        const clarification = purpose === 'clarification';
        const existingCapture = this.pendingContextualCapture;
        if (!clarification
            && existingCapture
            && !existingCapture.clarification
            && !existingCapture.speechStarted
            && !existingCapture.committed
            && existingCapture.conversationEpoch === this.conversationEpoch) {
            return true;
        }
        if (existingCapture && !existingCapture.clarification && !existingCapture.speechStarted) {
            void this.#discardUnstartedTurn(existingCapture.turnId, 'contextual_capture_replaced');
        }
        this.pendingContextualCapture = null;
        this.transport.deactivateInput();
        const turnId = clarification ? text(response?.turnId) : text(this.createTurnId());
        if (!turnId) return false;
        const inputGeneration = Number(this.localWakeGate.resetAfterTurn?.());
        if (!Number.isSafeInteger(inputGeneration) || inputGeneration < 1) {
            this.#handleFailure(new Error('Contextual capture could not establish a fresh local generation.'), 'local_wake', turnId);
            this.pendingContextualCapture = null;
            return false;
        }
        const capture = {
            turnId,
            inputGeneration,
            conversationEpoch: this.conversationEpoch,
            clarification,
            timeoutMs: clarification ? 5000 : this.followUpMs,
            admitted: false,
            opened: false,
            windowOpen: false,
            speechStarted: false,
            committed: false,
        };
        this.pendingContextualCapture = capture;
        this.activeTurnId = turnId;
        const generation = this.generation;
        const startedAtMs = integer(this.clock());
        try {
            const admitted = await this.request('/assistant/voice/turns', {
                method: 'POST',
                body: {
                    session_id: numericIdentifier(this.conversationSessionId),
                    turn_id: turnId,
                    realtime_session_id: this.realtimeSessionId,
                    controller_generation: generation,
                    provider_connection_generation: this.providerConnectionGeneration,
                    input_generation: inputGeneration,
                    client_milestones: {
                        pre_admission_started_at_ms: startedAtMs,
                    },
                    conversation_context: {
                        mode: 'contextual_follow_up',
                        epoch: this.conversationEpoch,
                    },
                },
                timeoutMs: 8000,
            });
            if (!this.#current(generation) || this.pendingContextualCapture !== capture) return false;
            if (text(admitted?.turn_id || admitted?.turnId) !== turnId
                || admitted?.sideband_ready !== true) {
                throw new Error('Contextual voice pre-admission was not sideband-ready.');
            }
            capture.admitted = true;
            this.turnStates.set(turnId, text(admitted?.state || 'pre_admitted'));
            this.#tryOpenContextualCapture(capture);
            return true;
        } catch (error) {
            if (this.pendingContextualCapture === capture) this.pendingContextualCapture = null;
            if (!capture.clarification) {
                await this.#discardUnstartedTurn(turnId, 'contextual_admission_failed');
            }
            this.#handleFailure(error, 'admission', turnId);
            if (!this.transport.snapshot().playbackActive) this.#returnToWakeOnly('contextual_admission_failed');
            return false;
        }
    }

    #localGateReady(event, generation) {
        if (!this.#current(generation)) return;
        const capture = this.pendingContextualCapture;
        if (!capture || Number(event?.generation) !== capture.inputGeneration) return;
        this.#tryOpenContextualCapture(capture);
    }

    #tryOpenContextualCapture(capture) {
        if (!capture?.admitted
            || capture.opened
            || this.pendingContextualCapture !== capture
            || this.localWakeGate?.currentGeneration?.() !== capture.inputGeneration
            || !this.localWakeGate?.isReady?.()) return false;
        if (this.transport.activateInput(capture.inputGeneration) !== true) {
            this.pendingContextualCapture = null;
            if (!capture.clarification) {
                void this.#discardUnstartedTurn(capture.turnId, 'contextual_transport_rejected');
            }
            this.#handleFailure(new Error('Realtime input rejected contextual capture.'), 'connection', capture.turnId);
            if (!this.transport.snapshot().playbackActive) this.#returnToWakeOnly('contextual_transport_rejected');
            return false;
        }
        if (this.localWakeGate.openContextualCapture({ generation: capture.inputGeneration }) !== true) {
            this.transport.deactivateInput();
            this.pendingContextualCapture = null;
            if (!capture.clarification) {
                void this.#discardUnstartedTurn(capture.turnId, 'contextual_gate_rejected');
            }
            this.#handleFailure(new Error('The local gate rejected contextual capture.'), 'local_wake', capture.turnId);
            if (!this.transport.snapshot().playbackActive) this.#returnToWakeOnly('contextual_gate_rejected');
            return false;
        }
        capture.opened = true;
        return true;
    }

    #enterContextualWindow() {
        if (!this.enabled) return;
        const capture = this.pendingContextualCapture;
        if (!capture) {
            this.#returnToWakeOnly();
            return;
        }
        capture.windowOpen = true;
        this.#clearFollowUpTimer();
        this.mode = BEAN_VOICE_MODES.FOLLOW_UP;
        this.#emitView();
        const generation = this.generation;
        this.followUpTimer = this.setTimeout?.(() => {
            this.followUpTimer = null;
            if (!this.#current(generation) || this.pendingContextualCapture !== capture) return;
            this.#returnToWakeOnly('contextual_capture_expired');
        }, capture.timeoutMs);
    }

    #returnToWakeOnly(cleanupReason = 'contextual_capture_closed') {
        if (!this.enabled) return;
        this.#clearFollowUpTimer();
        const capture = this.pendingContextualCapture;
        if (capture && !capture.clarification && !capture.speechStarted) {
            void this.#discardUnstartedTurn(capture.turnId, cleanupReason);
        }
        this.pendingContextualCapture = null;
        this.transport.deactivateInput();
        this.localWakeGate?.resetAfterTurn?.();
        this.activeTurnId = '';
        this.pendingBarge = null;
        this.mode = BEAN_VOICE_MODES.WAKE_ONLY;
        this.#emitView();
    }

    async #discardUnstartedTurn(turnId, reason) {
        const normalizedTurnId = text(turnId);
        if (!normalizedTurnId || !this.conversationSessionId) return false;
        this.turnStates.delete(normalizedTurnId);
        try {
            await this.request('/assistant/voice/cancellations', {
                method: 'POST',
                body: {
                    session_id: numericIdentifier(this.conversationSessionId),
                    turn_id: normalizedTurnId,
                    reason: text(reason || 'contextual_capture_closed'),
                },
                timeoutMs: 3000,
            });
            return true;
        } catch (_) {
            // The server may have rejected pre-admission before creating a
            // durable turn. Its deadline reconciler remains lifecycle owner.
            return false;
        }
    }

    #updateActivity(level, generation) {
        if (!this.#current(generation)) return;
        this.#clearActivityTimer();
        this.activityLevel = Math.max(0, Math.min(1, Number(level) || 0));
        this.#emitView();
        if (this.activityLevel > 0) {
            this.activityTimer = this.setTimeout?.(() => {
                this.activityTimer = null;
                if (!this.#current(generation)) return;
                this.activityLevel = 0;
                this.#emitView();
            }, 180);
        }
    }

    #diagnostic(diagnostic) {
        if (diagnostic?.type !== 'failure') return;
        this.#handleFailure(Object.assign(new Error('Local wake diagnostic failed.'), {
            code: text(diagnostic.reason || diagnostic.code || 'local_wake_diagnostic'),
        }), 'local_wake');
    }

    #handleFailure(error, stage = 'connection', turnId = '') {
        try {
            this.onFailure(error, Object.freeze({
                stage,
                turnId: text(turnId || this.activeTurnId) || null,
                sessionId: this.conversationSessionId || null,
                realtimeSessionId: this.realtimeSessionId || null,
                controllerGeneration: this.generation,
                providerConnectionGeneration: this.providerConnectionGeneration,
            }));
        } catch (_) {}
        if (this.enabled && stage === 'playback') {
            this.error = 'Bean hit a playback problem and is retrying the response.';
            this.#emitView();
        }
        if (this.enabled
            && this.mode !== BEAN_VOICE_MODES.STARTING
            && ['connection', 'projection', 'local_wake'].includes(stage)) {
            const failedGeneration = this.generation;
            void this.stop(`${stage}_failed`);
            if (!this.enabled && this.generation === failedGeneration + 1) {
                this.mode = BEAN_VOICE_MODES.FAILED;
                this.error = 'Bean lost the voice connection. Tap Bean to try again.';
                this.#emitView();
            }
        }
    }

    #current(generation) {
        return this.enabled && generation === this.generation;
    }

    #emitView() {
        try { this.onViewState(this.snapshot()); } catch (_) {}
    }

    #clearFollowUpTimer() {
        if (this.followUpTimer !== null) this.clearTimeout?.(this.followUpTimer);
        this.followUpTimer = null;
    }

    #clearActivityTimer() {
        if (this.activityTimer !== null) this.clearTimeout?.(this.activityTimer);
        this.activityTimer = null;
    }
}
