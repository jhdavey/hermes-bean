const BROWSER_SEND_TYPES = new Set([
    'input_audio_buffer.append',
    'input_audio_buffer.clear',
    'input_audio_buffer.commit',
    'output_audio_buffer.clear',
    'response.cancel',
]);

const PROVIDER_TOOL_EVENT_PATTERN = /(?:function_call|mcp_call|tool_call)/i;
const CONTROL_RESPONSE_PURPOSES = new Set(['semantic_plan', 'semantic_composition']);
const PLAYBACK_RESPONSE_PURPOSES = new Set(['acknowledgement', 'clarification', 'final']);

function text(value) {
    return String(value ?? '').trim();
}

function integer(value, fallback = -1) {
    const number = Number(value);
    return Number.isSafeInteger(number) && number >= 0 ? number : fallback;
}

function metadataForResponse(payload = {}) {
    const response = payload.response && typeof payload.response === 'object' ? payload.response : {};
    return response.metadata && typeof response.metadata === 'object' ? response.metadata : {};
}

function normalizedAuthorization(value = {}) {
    return Object.freeze({
        authorizationId: text(value.authorizationId || value.authorization_id || value.id),
        turnId: text(value.turnId || value.turn_id),
        speechItemId: text(value.speechItemId || value.speech_item_id),
        purpose: text(value.purpose).toLowerCase(),
        realtimeSessionId: text(value.realtimeSessionId || value.realtime_session_id),
        controllerGeneration: integer(value.controllerGeneration ?? value.controller_generation),
        providerConnectionGeneration: integer(
            value.providerConnectionGeneration
            ?? value.provider_connection_generation,
        ),
        approvedTextSha256: text(value.approvedTextSha256 || value.approved_text_sha256).toLowerCase(),
        playbackCapability: text(value.playbackCapability || value.playback_capability),
        expiresAt: text(value.expiresAt || value.expires_at),
    });
}

function responseBinding(payload = {}) {
    const response = payload.response && typeof payload.response === 'object' ? payload.response : {};
    const metadata = metadataForResponse(payload);
    return Object.freeze({
        responseId: text(response.id || payload.response_id),
        authorizationId: text(metadata.authorization_id || metadata.authorizationId),
        turnId: text(metadata.turn_id || metadata.turnId),
        speechItemId: text(metadata.speech_item_id || metadata.speechItemId),
        purpose: text(metadata.purpose).toLowerCase(),
        realtimeSessionId: text(metadata.realtime_session_id || metadata.realtimeSessionId),
        controllerGeneration: integer(metadata.controller_generation ?? metadata.controllerGeneration),
        providerConnectionGeneration: integer(
            metadata.provider_connection_generation
            ?? metadata.providerConnectionGeneration,
        ),
        approvedTextSha256: text(
            metadata.approved_text_sha256
            || metadata.approvedTextSha256,
        ).toLowerCase(),
        playbackCapability: text(metadata.playback_capability || metadata.playbackCapability),
    });
}

export function beanVoiceResponseAuthorizationFailure({
    binding,
    authorization,
    realtimeSessionId,
    playbackCapability,
    controllerGeneration,
    providerConnectionGeneration,
    consumedSpeechItemIds = new Set(),
} = {}) {
    if (!binding?.responseId) return 'missing_provider_response_id';
    if (!binding.speechItemId) return 'missing_speech_item_id';
    if (consumedSpeechItemIds.has(binding.speechItemId)) return 'speech_item_replayed';
    if (!authorization?.speechItemId || authorization.speechItemId !== binding.speechItemId) {
        return 'speech_item_not_authorized';
    }
    if (!text(playbackCapability)
        || binding.playbackCapability !== text(playbackCapability)
        || authorization.playbackCapability !== text(playbackCapability)) {
        return 'playback_capability_mismatch';
    }
    if (!text(realtimeSessionId)
        || binding.realtimeSessionId !== text(realtimeSessionId)
        || authorization.realtimeSessionId !== text(realtimeSessionId)) {
        return 'realtime_session_mismatch';
    }
    if (!binding.turnId || binding.turnId !== authorization.turnId) return 'turn_id_mismatch';
    if (!binding.authorizationId
        || !authorization.authorizationId
        || binding.authorizationId !== authorization.authorizationId) {
        return 'authorization_id_mismatch';
    }
    if (!binding.purpose || binding.purpose !== authorization.purpose) return 'speech_purpose_mismatch';
    if (!binding.approvedTextSha256
        || binding.approvedTextSha256 !== authorization.approvedTextSha256) {
        return 'approved_text_hash_mismatch';
    }
    if (binding.controllerGeneration !== integer(controllerGeneration)
        || authorization.controllerGeneration !== integer(controllerGeneration)) {
        return 'controller_generation_mismatch';
    }
    if (binding.providerConnectionGeneration !== integer(providerConnectionGeneration)
        || authorization.providerConnectionGeneration !== integer(providerConnectionGeneration)) {
        return 'provider_generation_mismatch';
    }
    const expiresAt = Date.parse(authorization.expiresAt);
    if (!authorization.expiresAt || !Number.isFinite(expiresAt)) return 'speech_authorization_expiry_missing';
    if (expiresAt <= Date.now()) return 'speech_authorization_expired';
    return null;
}

/**
 * Browser media/control plane for one Realtime WebRTC call. It never creates a
 * provider response or handles provider tools. Remote media is muted until a
 * response is bound to a current, one-use Laravel playback authorization.
 */
export class BeanVoiceRealtimeTransport {
    constructor({
        openSession,
        inputTransport,
        peerConnectionFactory = () => new RTCPeerConnection(),
        audioFactory = () => new Audio(),
        onEvent = () => {},
        onFailure = () => {},
        timers = {},
        readyTimeoutMs = 10000,
    } = {}) {
        if (typeof openSession !== 'function') throw new TypeError('Bean voice Realtime transport requires a session opener.');
        if (!inputTransport || typeof inputTransport.append !== 'function') {
            throw new TypeError('Bean voice Realtime transport requires its activated PCM transport.');
        }
        this.openSession = openSession;
        this.inputTransport = inputTransport;
        this.peerConnectionFactory = peerConnectionFactory;
        this.audioFactory = audioFactory;
        this.onEvent = onEvent;
        this.onFailure = onFailure;
        this.setTimeout = timers.setTimeout || globalThis.setTimeout?.bind(globalThis);
        this.clearTimeout = timers.clearTimeout || globalThis.clearTimeout?.bind(globalThis);
        this.readyTimeoutMs = Math.max(1000, Number(readyTimeoutMs) || 10000);
        this.generation = 0;
        this.controllerGeneration = -1;
        this.providerConnectionGeneration = -1;
        this.realtimeSessionId = '';
        this.playbackCapability = '';
        this.peerConnection = null;
        this.dataChannel = null;
        this.remoteAudio = null;
        this.authorizations = new Map();
        this.consumedSpeechItemIds = new Set();
        this.activeResponse = null;
        this.connected = false;
    }

    snapshot() {
        return Object.freeze({
            generation: this.generation,
            connected: this.connected,
            realtimeSessionId: this.realtimeSessionId || null,
            controllerGeneration: this.controllerGeneration,
            providerConnectionGeneration: this.providerConnectionGeneration,
            playbackActive: Boolean(this.activeResponse?.started),
            activeResponse: this.activeResponse ? Object.freeze({ ...this.activeResponse }) : null,
            authorizationCount: this.authorizations.size,
        });
    }

    prime() {
        const audio = this.#audio();
        audio.autoplay = true;
        audio.volume = 0;
        audio.muted = true;
        try {
            const result = audio.play?.();
            result?.catch?.(() => {});
        } catch (_) {}
        return audio;
    }

    async connect({
        controllerGeneration,
        providerConnectionGeneration,
        context = {},
    } = {}) {
        this.close('reconnect');
        const generation = ++this.generation;
        this.controllerGeneration = integer(controllerGeneration);
        this.providerConnectionGeneration = integer(providerConnectionGeneration);
        const peer = this.peerConnectionFactory();
        const audio = this.#audio();
        audio.autoplay = true;
        audio.volume = 0;
        audio.muted = true;
        this.peerConnection = peer;
        peer.ontrack = (event) => {
            if (!this.#current(generation, peer)) return;
            const [stream] = event.streams || [];
            if (stream) audio.srcObject = stream;
        };
        peer.addTransceiver?.('audio', { direction: 'recvonly' });
        const channel = peer.createDataChannel('oai-events');
        this.dataChannel = channel;

        let resolveReady;
        let rejectReady;
        let channelReady = channel.readyState === 'open';
        let mediaReady = peer.connectionState === 'connected';
        const ready = new Promise((resolve, reject) => {
            resolveReady = resolve;
            rejectReady = reject;
        });
        // Negotiation can fail before Promise.race begins awaiting readiness.
        // The subsequent intentional close still emits a data-channel close,
        // so mark that early rejection handled without changing its state.
        void ready.catch(() => {});
        const settleReady = () => {
            if (channelReady && mediaReady) resolveReady();
        };
        channel.addEventListener('open', () => {
            if (!this.#current(generation, peer)) return;
            channelReady = true;
            settleReady();
        });
        channel.addEventListener('message', (event) => {
            if (!this.#current(generation, peer)) return;
            let payload;
            try { payload = JSON.parse(event.data); } catch (_) { return; }
            this.handleProviderEvent(payload);
        });
        channel.addEventListener('close', () => {
            if (!this.#current(generation, peer)) return;
            rejectReady(new Error('Realtime data channel closed before readiness.'));
            this.#connectionFailure('realtime_data_channel_closed');
        });
        channel.addEventListener('error', () => {
            if (!this.#current(generation, peer)) return;
            rejectReady(new Error('Realtime data channel failed before readiness.'));
            this.#connectionFailure('realtime_data_channel_failed');
        });
        peer.addEventListener('connectionstatechange', () => {
            if (!this.#current(generation, peer)) return;
            if (peer.connectionState === 'connected') {
                mediaReady = true;
                settleReady();
            } else if (['failed', 'closed'].includes(peer.connectionState)) {
                rejectReady(new Error('Realtime peer connection failed before readiness.'));
                this.#connectionFailure('realtime_peer_connection_failed');
            }
        });
        settleReady();

        try {
            const offer = await peer.createOffer();
            await peer.setLocalDescription(offer);
            const sdp = String(peer.localDescription?.sdp || offer.sdp || '');
            if (!sdp.trim()) throw new Error('Realtime offer did not include SDP.');
            const session = await this.openSession(sdp, context);
            if (!this.#current(generation, peer)) throw Object.assign(new Error('Realtime connection was superseded.'), { name: 'AbortError' });
            const realtimeSessionId = text(session?.realtime_session_id || session?.realtimeSessionId);
            const playbackCapability = text(session?.playback_capability || session?.playbackCapability);
            if (!realtimeSessionId || !playbackCapability) {
                throw new Error('Realtime session did not include its public session and playback capability.');
            }
            // SDP is a framed protocol: the final line terminator is required.
            // Validate emptiness separately and relay the provider answer intact.
            const answerSdp = typeof session?.sdp === 'string' ? session.sdp : '';
            if (!answerSdp.trim()) throw new Error('Realtime session did not include an SDP answer.');
            this.realtimeSessionId = realtimeSessionId;
            this.playbackCapability = playbackCapability;
            try {
                await peer.setRemoteDescription({ type: 'answer', sdp: answerSdp });
            } catch (_) {
                throw Object.assign(
                    new Error('Realtime remote description could not be applied.'),
                    { code: 'realtime_remote_description_failed' },
                );
            }
            let timer = null;
            try {
                await Promise.race([
                    ready,
                    new Promise((_, reject) => {
                        timer = this.setTimeout?.(
                            () => reject(new Error('Realtime transport readiness timed out.')),
                            this.readyTimeoutMs,
                        );
                    }),
                ]);
            } finally {
                if (timer !== null) this.clearTimeout?.(timer);
            }
            if (!this.#current(generation, peer)) throw Object.assign(new Error('Realtime connection was superseded.'), { name: 'AbortError' });
            this.connected = true;
            this.onEvent(Object.freeze({ type: 'transport_ready', realtimeSessionId }));
            return Object.freeze({ ...session, realtimeSessionId, playbackCapability });
        } catch (error) {
            if (this.#current(generation, peer)) this.close('connect_failed');
            throw error;
        }
    }

    appendActivatedPcm(event) {
        if (!this.connected) return false;
        return this.inputTransport.append(event) === true;
    }

    activateInput(generation) {
        if (!this.connected) return false;
        return this.inputTransport.activate({ generation }) === true;
    }

    deactivateInput() {
        this.inputTransport.deactivate();
    }

    sendInputEvent(event) {
        return this.#send(event);
    }

    bufferedAmount() {
        return Number(this.dataChannel?.bufferedAmount || 0);
    }

    authorizeSpeech(value) {
        const authorization = normalizedAuthorization(value);
        const expiresAt = Date.parse(authorization.expiresAt);
        if (!authorization.authorizationId
            || !authorization.turnId
            || !authorization.speechItemId
            || !PLAYBACK_RESPONSE_PURPOSES.has(authorization.purpose)
            || authorization.realtimeSessionId !== this.realtimeSessionId
            || authorization.playbackCapability !== this.playbackCapability
            || authorization.controllerGeneration !== this.controllerGeneration
            || authorization.providerConnectionGeneration !== this.providerConnectionGeneration
            || !/^[a-f0-9]{64}$/.test(authorization.approvedTextSha256)
            || !Number.isFinite(expiresAt)
            || expiresAt <= Date.now()
            || this.consumedSpeechItemIds.has(authorization.speechItemId)) return false;
        this.authorizations.set(authorization.speechItemId, authorization);
        return true;
    }

    handleProviderEvent(payload = {}) {
        const type = text(payload.type);
        if (!type) return false;
        if (PROVIDER_TOOL_EVENT_PATTERN.test(type)) {
            this.onEvent(Object.freeze({ type: 'provider_tool_ignored', providerEventType: type }));
            return true;
        }
        if (type.includes('input_audio_transcription')) {
            // Internal provider transcript events are neither rendered nor used
            // for browser admission. Laravel sideband owns semantic handoff.
            return true;
        }
        if (type === 'response.created') return this.#bindResponse(payload);
        if (type === 'output_audio_buffer.started') return this.#playbackStarted(payload);
        if (type === 'output_audio_buffer.stopped') return this.#playbackStopped(payload, 'finished');
        if (type === 'output_audio_buffer.cleared') return this.#playbackStopped(payload, 'cleared');
        if (type === 'response.done') {
            const responseId = text(payload.response?.id || payload.response_id);
            if (this.activeResponse && responseId === this.activeResponse.responseId) {
                this.onEvent(Object.freeze({ type: 'response_done', ...this.activeResponse }));
            }
            return true;
        }
        if (type === 'input_audio_buffer.speech_started') {
            this.onEvent(Object.freeze({
                type: 'input_speech_started',
                providerItemId: text(payload.item_id || payload.item?.id),
            }));
            return true;
        }
        if (type === 'input_audio_buffer.speech_stopped') {
            this.onEvent(Object.freeze({
                type: 'input_speech_stopped',
                providerItemId: text(payload.item_id || payload.item?.id),
            }));
            return true;
        }
        if (type === 'input_audio_buffer.committed') {
            this.onEvent(Object.freeze({
                type: 'input_committed',
                providerItemId: text(payload.item_id || payload.item?.id),
            }));
            return true;
        }
        if (type === 'error') {
            const message = text(payload.error?.message);
            if (/no active response|already.*(?:done|cancel)|cancell?ation failed/i.test(message)) return true;
            this.#connectionFailure('realtime_provider_error', payload.error);
            return true;
        }
        return false;
    }

    duck(reason = 'potential_barge_in') {
        if (!this.activeResponse?.started) return false;
        this.#setAudible(0.2);
        this.onEvent(Object.freeze({ type: 'playback_ducked', reason, ...this.activeResponse }));
        return true;
    }

    restore(reason = 'barge_rejected') {
        if (!this.activeResponse?.started) return false;
        this.#setAudible(1);
        this.onEvent(Object.freeze({ type: 'playback_restored', reason, ...this.activeResponse }));
        return true;
    }

    stopPlayback(reason = 'button_stop') {
        if (!this.activeResponse) return false;
        const response = { ...this.activeResponse };
        this.#setAudible(0);
        if (response.responseId) this.#send({ type: 'response.cancel', response_id: response.responseId });
        this.#send({ type: 'output_audio_buffer.clear' });
        this.#consumeActiveResponse();
        this.onEvent(Object.freeze({ type: 'playback_stopped', reason, ...response }));
        return true;
    }

    close(reason = 'closed') {
        // Invalidate every listener captured by the closing peer before its
        // close/error events can fire. An intentional teardown is not a
        // connection failure and stale events cannot affect the next call.
        this.generation += 1;
        this.connected = false;
        this.#setAudible(0);
        this.inputTransport.deactivate();
        this.authorizations.clear();
        this.consumedSpeechItemIds.clear();
        this.activeResponse = null;
        this.realtimeSessionId = '';
        this.playbackCapability = '';
        try { this.dataChannel?.close?.(); } catch (_) {}
        try { this.peerConnection?.close?.(); } catch (_) {}
        try {
            this.remoteAudio?.pause?.();
            if (this.remoteAudio) this.remoteAudio.srcObject = null;
        } catch (_) {}
        this.dataChannel = null;
        this.peerConnection = null;
        this.onEvent(Object.freeze({ type: 'transport_closed', reason }));
    }

    #bindResponse(payload) {
        const binding = responseBinding(payload);
        if (CONTROL_RESPONSE_PURPOSES.has(binding.purpose)) {
            this.onEvent(Object.freeze({
                type: 'control_response_ignored',
                purpose: binding.purpose,
                responseId: binding.responseId || null,
            }));
            return true;
        }
        const authorization = this.authorizations.get(binding.speechItemId);
        const failure = beanVoiceResponseAuthorizationFailure({
            binding,
            authorization,
            realtimeSessionId: this.realtimeSessionId,
            playbackCapability: this.playbackCapability,
            controllerGeneration: this.controllerGeneration,
            providerConnectionGeneration: this.providerConnectionGeneration,
            consumedSpeechItemIds: this.consumedSpeechItemIds,
        });
        if (failure || this.activeResponse) {
            this.#setAudible(0);
            if (binding.responseId) this.#send({ type: 'response.cancel', response_id: binding.responseId });
            this.#send({ type: 'output_audio_buffer.clear' });
            this.onFailure(Object.assign(new Error('Realtime audio response was not authorized.'), {
                code: failure || 'overlapping_provider_response',
                responseId: binding.responseId || null,
                speechItemId: binding.speechItemId || null,
            }), 'playback');
            return true;
        }
        this.activeResponse = {
            ...binding,
            started: false,
            boundAtMs: Date.now(),
        };
        this.#setAudible(1);
        this.onEvent(Object.freeze({ type: 'response_authorized', ...this.activeResponse }));
        return true;
    }

    #playbackStarted(payload) {
        const responseId = text(payload.response_id || payload.response?.id || this.activeResponse?.responseId);
        if (!this.activeResponse || responseId !== this.activeResponse.responseId) {
            this.#setAudible(0);
            if (responseId) this.#send({ type: 'response.cancel', response_id: responseId });
            this.#send({ type: 'output_audio_buffer.clear' });
            this.onFailure(Object.assign(new Error('Realtime output began without an authorized response.'), {
                code: 'unbound_output_audio',
                responseId: responseId || null,
            }), 'playback');
            return true;
        }
        this.activeResponse.started = true;
        this.activeResponse.startedAtMs = Date.now();
        this.#setAudible(1);
        this.onEvent(Object.freeze({ type: 'playback_started', ...this.activeResponse }));
        return true;
    }

    #playbackStopped(payload, reason) {
        const responseId = text(payload.response_id || payload.response?.id || this.activeResponse?.responseId);
        if (!this.activeResponse || responseId !== this.activeResponse.responseId) {
            this.#setAudible(0);
            return true;
        }
        const response = { ...this.activeResponse };
        this.#setAudible(0);
        this.#consumeActiveResponse();
        this.onEvent(Object.freeze({ type: 'playback_finished', reason, ...response }));
        return true;
    }

    #consumeActiveResponse() {
        if (!this.activeResponse) return;
        this.consumedSpeechItemIds.add(this.activeResponse.speechItemId);
        this.authorizations.delete(this.activeResponse.speechItemId);
        this.activeResponse = null;
    }

    #send(event) {
        const type = text(event?.type);
        if (!BROWSER_SEND_TYPES.has(type) || !this.dataChannel || this.dataChannel.readyState !== 'open') return false;
        this.dataChannel.send(JSON.stringify(event));
        return true;
    }

    #setAudible(volume) {
        const audio = this.remoteAudio;
        if (!audio) return;
        const normalized = Math.max(0, Math.min(1, Number(volume) || 0));
        audio.volume = normalized;
        audio.muted = normalized === 0;
        if (normalized > 0) {
            try { audio.play?.()?.catch?.(() => {}); } catch (_) {}
        }
    }

    #audio() {
        if (!this.remoteAudio) this.remoteAudio = this.audioFactory();
        return this.remoteAudio;
    }

    #current(generation, peer) {
        return generation === this.generation && peer === this.peerConnection;
    }

    #connectionFailure(code, detail = null) {
        const error = Object.assign(new Error('Bean voice Realtime transport failed.'), { code, detail });
        this.onFailure(error, 'connection');
    }
}
