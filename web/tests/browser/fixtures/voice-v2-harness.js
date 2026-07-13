import {
    BROWSER_VOICE_CONVERSATION_STATES,
    BROWSER_VOICE_EFFECTS,
    BrowserVoiceControllerV2,
} from '/resources/js/heybean/browserVoiceControllerV2.js';
import {
    BrowserVoicePlaybackAdapterV2,
    BrowserVoiceSpeechSchedulerV2,
} from '/resources/js/heybean/browserVoiceSpeechV2.js';
import {
    BrowserVoiceV2Client,
    normalizeVoiceV2Snapshot,
} from '/resources/js/heybean/browserVoiceV2Client.js';
import {
    isStrictRealtimeWakePhrase,
    stripRealtimeLocalWakePrefix,
} from '/resources/js/heybean/realtimeVoiceTurn.js';

const STORE_KEY = 'bean-voice-v2-browser-journey-server';
const SESSION_ID = 41;
const READY_PARTS = Object.freeze([
    'track',
    'worklet',
    'model',
    'recognizer',
    'derived_track',
    'warm_decode',
    'provider',
]);

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

function emptyServerState() {
    return {
        cursor: 0,
        turns: [],
        jobs: [],
        messages: [],
        delivery: [],
        failuresRemaining: 0,
    };
}

class PersistentFakeVoiceServer {
    constructor() {
        this.calls = [];
    }

    reset() {
        localStorage.setItem(STORE_KEY, JSON.stringify(emptyServerState()));
        this.calls = [];
    }

    read() {
        try {
            return { ...emptyServerState(), ...JSON.parse(localStorage.getItem(STORE_KEY) || '{}') };
        } catch {
            return emptyServerState();
        }
    }

    write(state) {
        localStorage.setItem(STORE_KEY, JSON.stringify(state));
        return clone(state);
    }

    snapshot() {
        const state = this.read();
        return clone({
            cursor: state.cursor,
            turns: state.turns,
            jobs: state.jobs,
            messages: state.messages,
            events: state.delivery.map((entry, index) => ({
                cursor: index + 1,
                turn_id: entry.turn_id,
                type: entry.event,
                payload: entry.timing || {},
            })),
        });
    }

    failNextStateRequests(count = 1) {
        const state = this.read();
        state.failuresRemaining = Math.max(0, Number(count) || 0);
        this.write(state);
    }

    seedTurns(turns) {
        const state = this.read();
        for (const input of turns) {
            const turnId = String(input.turn_id || input.turnId || '').trim();
            if (!turnId) continue;
            const currentIndex = state.turns.findIndex((turn) => turn.turn_id === turnId);
            const current = currentIndex >= 0 ? state.turns[currentIndex] : {};
            const turn = {
                turn_id: turnId,
                transcript: input.transcript || current.transcript || '',
                state: input.state || current.state || 'accepted',
                version: Number(input.version ?? current.version ?? 1),
                lane: input.lane || current.lane || 'complex_agent',
                handler: input.handler || current.handler || 'agent',
                acknowledgement_required: Boolean(
                    input.acknowledgement_required ?? current.acknowledgement_required,
                ),
                acknowledgement_text: input.acknowledgement_text || current.acknowledgement_text || '',
                final_text: input.final_text ?? current.final_text ?? '',
                final_delivered_at: input.final_delivered_at ?? current.final_delivered_at ?? '',
                jobs: clone(input.jobs || current.jobs || []),
            };
            if (currentIndex >= 0) state.turns[currentIndex] = turn;
            else state.turns.push(turn);

            if (!state.messages.some((message) => message.id === `user:${turnId}`)) {
                state.messages.push({ id: `user:${turnId}`, role: 'user', turn_id: turnId, content: turn.transcript });
            }
            for (const job of turn.jobs) this.#upsertJob(state, { ...job, turn_id: turnId });
            if (turn.final_text && !state.messages.some((message) => message.id === `final:${turnId}`)) {
                state.messages.push({ id: `final:${turnId}`, role: 'assistant', turn_id: turnId, content: turn.final_text });
            }
        }
        state.cursor += 1;
        return this.write(state);
    }

    updateTurn(turnId, changes = {}) {
        const state = this.read();
        const index = state.turns.findIndex((turn) => turn.turn_id === turnId);
        if (index < 0) throw new Error(`Unknown turn: ${turnId}`);
        const previous = state.turns[index];
        const next = {
            ...previous,
            ...clone(changes),
            version: Math.max(Number(previous.version || 0) + 1, Number(changes.version || 0)),
        };
        state.turns[index] = next;
        for (const job of next.jobs || []) this.#upsertJob(state, { ...job, turn_id: turnId });
        if (next.final_text) {
            const message = state.messages.find((item) => item.id === `final:${turnId}`);
            if (message) message.content = next.final_text;
            else state.messages.push({ id: `final:${turnId}`, role: 'assistant', turn_id: turnId, content: next.final_text });
        }
        state.cursor += 1;
        return this.write(state);
    }

    async request(path, options = {}) {
        this.calls.push({ path, options: clone(options) });
        if (path.startsWith('/assistant/voice/state?')) {
            const state = this.read();
            if (state.failuresRemaining > 0) {
                state.failuresRemaining -= 1;
                this.write(state);
                throw new TypeError('Synthetic voice state transport unavailable');
            }
            return this.snapshot();
        }

        if (path === '/assistant/voice/turns' && options.method === 'POST') {
            const input = options.body || {};
            const state = this.read();
            let turn = state.turns.find((item) => item.turn_id === input.turn_id);
            if (!turn) {
                turn = {
                    turn_id: input.turn_id,
                    transcript: input.transcript,
                    state: 'accepted',
                    version: 1,
                    lane: 'complex_agent',
                    handler: 'agent',
                    acknowledgement_required: false,
                    acknowledgement_text: '',
                    final_text: '',
                    jobs: [],
                };
                state.turns.push(turn);
                state.messages.push({
                    id: `user:${input.turn_id}`,
                    role: 'user',
                    turn_id: input.turn_id,
                    content: input.transcript,
                });
                state.cursor += 1;
                this.write(state);
            }
            return this.snapshot();
        }

        const deliveryMatch = path.match(/^\/assistant\/voice\/turns\/([^/]+)\/delivery$/);
        if (deliveryMatch && options.method === 'POST') {
            const turnId = decodeURIComponent(deliveryMatch[1]);
            const state = this.read();
            const key = `${turnId}:${options.body?.event || ''}`;
            if (!state.delivery.some((entry) => entry.key === key)) {
                state.delivery.push({ key, turn_id: turnId, ...clone(options.body || {}) });
                state.cursor += 1;
                this.write(state);
            }
            return { ok: true };
        }

        if (path === '/assistant/voice/cancellations' && options.method === 'POST') {
            return { ok: true };
        }

        throw new Error(`Unhandled synthetic request: ${options.method || 'GET'} ${path}`);
    }

    #upsertJob(state, input) {
        const id = String(input.id || input.job_id || '').trim();
        if (!id) return;
        const next = {
            id,
            turn_id: String(input.turn_id || '').trim(),
            label: input.label || 'Bean work',
            status: input.status || 'queued',
            version: Number(input.version || 1),
        };
        const index = state.jobs.findIndex((job) => String(job.id) === id);
        if (index < 0) state.jobs.push(next);
        else if (Number(state.jobs[index].version || 0) <= next.version) state.jobs[index] = next;
    }
}

class SyntheticPlayback {
    constructor({ autoStart = false } = {}) {
        this.autoStart = autoStart;
        this.handles = [];
        this.stops = [];
        this.volumes = [];
        this.activeCount = 0;
        this.maxActive = 0;
    }

    play = (item, listeners) => {
        const handle = { item, listeners, started: false, ended: false, stopped: false };
        this.handles.push(handle);
        if (this.autoStart) queueMicrotask(() => this.start(handle));
        return handle;
    };

    setVolume = (handle, volume, item) => {
        this.volumes.push({ itemId: item?.id || null, volume, handle: Boolean(handle) });
    };

    stop = (handle, reason, item) => {
        if (handle && !handle.ended) {
            handle.stopped = true;
            if (handle.started) this.activeCount = Math.max(0, this.activeCount - 1);
        }
        this.stops.push({ itemId: item?.id || null, turnId: item?.turnId || null, reason });
    };

    start(handle = this.handles.at(-1)) {
        if (!handle || handle.started || handle.ended || handle.stopped) return false;
        handle.started = true;
        this.activeCount += 1;
        this.maxActive = Math.max(this.maxActive, this.activeCount);
        handle.listeners.onStart();
        return true;
    }

    finish(handle = this.handles.find((item) => item.started && !item.ended && !item.stopped)) {
        if (!handle || handle.ended || handle.stopped) return false;
        handle.ended = true;
        if (handle.started) this.activeCount = Math.max(0, this.activeCount - 1);
        handle.listeners.onEnd('completed');
        return true;
    }
}

class VoiceV2BrowserJourneyHarness {
    constructor() {
        this.server = new PersistentFakeVoiceServer();
        this.readiness = Object.fromEntries(READY_PARTS.map((part) => [part, false]));
        this.admissions = new Set();
        this.networkErrors = [];
        this.scheduledAcknowledgements = new Set();
        this.scheduledFinals = new Set();
        this.currentCursor = 0;
        this.turnCounter = 0;
        this.rehydrating = true;

        const params = new URLSearchParams(location.search);
        if (params.get('reset') === '1') {
            this.server.reset();
            history.replaceState({}, '', location.pathname);
        }

        this.dom = {
            state: document.querySelector('#voice-state'),
            input: document.querySelector('#voice-input'),
            chat: document.querySelector('#chat'),
            dock: document.querySelector('#dock'),
            diagnostics: document.querySelector('#diagnostics'),
        };

        this.playback = new SyntheticPlayback({ autoStart: params.get('autoStartPlayback') === '1' });
        this.speech = new BrowserVoiceSpeechSchedulerV2({
            playback: new BrowserVoicePlaybackAdapterV2({
                play: this.playback.play,
                setVolume: this.playback.setVolume,
                stop: this.playback.stop,
            }),
            acknowledgementGraceMs: 350,
            onEvent: (event) => this.#onSpeechEvent(event),
        });
        this.controller = new BrowserVoiceControllerV2({
            createTurnId: () => `browser-turn-${++this.turnCounter}`,
            speechScheduler: this.speech,
            onEffect: (effect) => this.#onControllerEffect(effect),
            onStateChange: (state, event) => this.#renderController(state, event),
        });
        this.client = new BrowserVoiceV2Client({
            request: this.server.request.bind(this.server),
            pollWaitSeconds: 1,
            pollIntervalMs: 25,
            retryDelayMs: 25,
            maxRetryDelayMs: 100,
            onSnapshot: (snapshot) => this.applyProjection(snapshot),
            onError: (error, context) => {
                this.networkErrors.push({ message: String(error?.message || error), ...context });
                this.#renderDiagnostics();
            },
        });
        this.controller.start();
        this.ready = this.pollOnce({ rehydrating: true }).finally(() => {
            this.rehydrating = false;
        });
    }

    markReady(part) {
        if (!(part in this.readiness)) throw new Error(`Unknown readiness part: ${part}`);
        this.readiness[part] = true;
        if (READY_PARTS.every((name) => this.readiness[name])
            && this.controller.snapshot().conversationState === BROWSER_VOICE_CONVERSATION_STATES.STARTING) {
            this.controller.providerReady({ source: 'readiness', sequence: 1 });
        }
        return this.snapshot();
    }

    markAllReady() {
        for (const part of READY_PARTS) this.markReady(part);
        return this.snapshot();
    }

    ambientSpeech() {
        return this.snapshot();
    }

    wake(turnId = `browser-turn-${++this.turnCounter}`) {
        const result = this.controller.wakeConfirmed({ turnId, source: 'wake-gate' });
        if (result.state.conversationState === BROWSER_VOICE_CONVERSATION_STATES.ACTIVATING) {
            this.controller.activationReady({ source: 'provider' });
            this.controller.speechStarted({ source: 'provider' });
        }
        return result;
    }

    followUp(turnId = `browser-turn-${++this.turnCounter}`) {
        return this.controller.speechStarted({ turnId, source: 'provider' });
    }

    partial(text, event = {}) {
        return this.controller.transcriptPartial(text, { source: 'provider', ...event });
    }

    final(text, event = {}) {
        return this.controller.transcriptFinal(text, { source: 'provider', ...event });
    }

    providerTranscript(text, turnId = `browser-turn-${++this.turnCounter}`) {
        const transcript = String(text || '').trim();
        if (isStrictRealtimeWakePhrase(transcript)) {
            const wake = this.controller.wakeConfirmed({ turnId, source: 'provider-strict-wake' });
            if (wake.state.conversationState === BROWSER_VOICE_CONVERSATION_STATES.ACTIVATING) {
                this.controller.activationReady({ source: 'provider-strict-wake' });
            }
        }
        const command = stripRealtimeLocalWakePrefix(transcript);
        if (command) this.controller.transcriptFinal(command, { source: 'provider' });
        return this.snapshot();
    }

    endUtterance(decision = 'complete', detail = {}) {
        this.controller.speechEnded({ source: 'provider' });
        const snapshot = this.controller.snapshot();
        this.controller.dispatch({
            type: 'timer_fired',
            timerKey: 'endpoint',
            turnId: snapshot.activeTurn?.id || null,
            atMs: snapshot.deadlines.endpointAt,
            source: 'synthetic-endpoint',
        });
        this.controller.completenessDecided(decision, { source: 'completeness', ...detail });
        return this.snapshot();
    }

    potentialBarge() {
        return this.controller.potentialBargeIn('potential_speech', { source: 'provider' });
    }

    rejectBarge() {
        return this.controller.rejectBargeIn('background_noise', { source: 'provider' });
    }

    confirmBarge(turnId = `browser-turn-${++this.turnCounter}`) {
        const result = this.controller.confirmBargeIn({ turnId, source: 'provider' });
        if (result.state.conversationState === BROWSER_VOICE_CONVERSATION_STATES.ACTIVATING) {
            this.controller.activationReady({ source: 'provider' });
            this.controller.speechStarted({ source: 'provider' });
        }
        return result;
    }

    stopPlayback() {
        return this.controller.stopPlayback('user_stop');
    }

    startPlayback() {
        return this.playback.start(this.playback.handles.at(-1));
    }

    finishPlayback() {
        return this.playback.finish();
    }

    providerFailure(reason = 'provider_unavailable') {
        return this.controller.dispatch({ type: 'connection_failed', reason, source: 'provider' });
    }

    async waitForAdmissions() {
        while (this.admissions.size) await Promise.all([...this.admissions]);
    }

    async pollOnce({ rehydrating = false } = {}) {
        const snapshot = await this.client.snapshot(SESSION_ID, { cursor: this.currentCursor });
        return this.applyProjection(normalizeVoiceV2Snapshot(snapshot), { rehydrating });
    }

    startPolling() {
        return this.client.start(SESSION_ID, { cursor: this.currentCursor });
    }

    stopPolling() {
        this.client.stop();
    }

    applyProjection(input, { rehydrating = this.rehydrating } = {}) {
        const projection = input?.turns && input?.activeTurns
            ? input
            : normalizeVoiceV2Snapshot(input);
        if (projection.cursor < this.currentCursor) return this.snapshot();
        this.currentCursor = Math.max(this.currentCursor, projection.cursor);
        this.#renderProjection(projection);

        for (const turn of projection.turns) {
            if (turn.acknowledgementRequired && turn.acknowledgementText
                && ['accepted', 'running'].includes(turn.state)
                && !this.scheduledAcknowledgements.has(turn.turnId)) {
                this.scheduledAcknowledgements.add(turn.turnId);
                this.speech.scheduleAcknowledgement({
                    turnId: turn.turnId,
                    text: turn.acknowledgementText,
                    id: `${turn.turnId}:ack`,
                    metadata: { naturalClosing: false },
                });
            }
            if (turn.finalAudioStarted) this.scheduledFinals.add(turn.turnId);
            if (turn.finalText && !turn.finalAudioStarted && !this.scheduledFinals.has(turn.turnId)) {
                this.scheduledFinals.add(turn.turnId);
                this.speech.finalReady({
                    turnId: turn.turnId,
                    text: turn.finalText,
                    id: `${turn.turnId}:final`,
                    metadata: { naturalClosing: Boolean(turn.natural_closing) },
                });
            }
        }
        return this.snapshot();
    }

    seedTurns(turns) {
        this.server.seedTurns(turns);
        return this.pollOnce();
    }

    updateTurn(turnId, changes) {
        this.server.updateTurn(turnId, changes);
        return this.pollOnce();
    }

    failNextStateRequests(count = 1) {
        this.server.failNextStateRequests(count);
    }

    snapshot() {
        return {
            controller: this.controller.snapshot(),
            speech: this.speech.snapshot(),
            readiness: { ...this.readiness },
            cursor: this.currentCursor,
            networkErrors: clone(this.networkErrors),
            server: this.server.read(),
            playback: {
                plays: this.playback.handles.map((handle) => ({
                    itemId: handle.item.id,
                    turnId: handle.item.turnId,
                    purpose: handle.item.purpose,
                    text: handle.item.text,
                    started: handle.started,
                    stopped: handle.stopped,
                    ended: handle.ended,
                })),
                stops: clone(this.playback.stops),
                volumes: clone(this.playback.volumes),
                maxActive: this.playback.maxActive,
            },
        };
    }

    async runSyntheticBenchmarks(samples = 100) {
        const count = Math.max(10, Math.min(1_000, Number(samples) || 100));
        const wake = [];
        const partial = [];
        const barge = [];
        const projection = [];

        for (let index = 0; index < count; index += 1) {
            this.controller.disable('benchmark_iteration');
            this.speech.reset('benchmark_iteration');
            this.controller.start();
            this.controller.providerReady({ source: `benchmark-ready-${index}` });

            let started = performance.now();
            this.wake(`benchmark-wake-${index}`);
            wake.push(performance.now() - started);

            started = performance.now();
            this.partial(`Recognized partial ${index}`);
            this.dom.input.textContent;
            partial.push(performance.now() - started);

            this.speech.finalReady({
                turnId: `benchmark-speech-${index}`,
                text: 'Synthetic benchmark playback.',
                id: `benchmark-speech-${index}:final`,
            });
            this.startPlayback();
            this.potentialBarge();
            started = performance.now();
            this.confirmBarge(`benchmark-barge-${index}`);
            barge.push(performance.now() - started);

            const snapshot = {
                cursor: this.currentCursor + 1,
                turns: [{
                    turn_id: `benchmark-projection-${index}`,
                    transcript: 'Benchmark work',
                    state: 'running',
                    version: 1,
                    jobs: [0, 1, 2].map((job) => ({
                        id: `benchmark-${index}-${job}`,
                        turn_id: `benchmark-projection-${index}`,
                        label: `Benchmark job ${job}`,
                        status: 'running',
                        version: 1,
                    })),
                }],
            };
            started = performance.now();
            this.applyProjection(snapshot);
            this.dom.dock.childElementCount;
            projection.push(performance.now() - started);
        }

        const finalAudioStart = await Promise.all(Array.from({ length: count }, (_, index) => (
            measureSyntheticSpeechStart((scheduler) => scheduler.finalReady({
                turnId: `benchmark-final-audio-${index}`,
                text: 'Instant synthetic final.',
            }))
        )));
        const acknowledgementAudioStart = await Promise.all(Array.from({ length: Math.min(count, 25) }, (_, index) => (
            measureSyntheticSpeechStart((scheduler) => scheduler.scheduleAcknowledgement({
                turnId: `benchmark-ack-audio-${index}`,
                text: 'I’ll check that.',
            }), { acknowledgementGraceMs: 350 })
        )));

        const metrics = {
            wake_controller_to_activating_ms: summarize(wake, 500),
            recognized_partial_to_dom_ms: summarize(partial, 150),
            final_ready_to_synthetic_audio_start_ms: summarize(finalAudioStart, 1_000),
            acknowledgement_scheduled_to_synthetic_audio_start_ms: summarize(acknowledgementAudioStart, 800),
            confirmed_barge_to_playback_stop_ms: summarize(barge, 200),
            three_job_snapshot_to_dom_ms: summarize(projection, 800),
        };
        return {
            classification: 'synthetic_browser_adapter_only',
            representative_release_certification: false,
            samples: count,
            browser: navigator.userAgent,
            metrics,
            pass: Object.values(metrics).every((metric) => metric.pass),
            limitation: 'Synthetic milestones exclude acoustic recognition, provider audio, real network, device load, Safari, and Edge.',
        };
    }

    #onControllerEffect(effect) {
        if (effect.type === BROWSER_VOICE_EFFECTS.DRAFT_CHANGED) {
            this.dom.input.textContent = effect.text || '';
        }
        if (effect.type === BROWSER_VOICE_EFFECTS.TURN_READY) {
            const admission = this.client.admit({
                turnId: effect.turnId,
                sessionId: SESSION_ID,
                transcript: effect.transcript,
                timezone: 'America/New_York',
                controllerGeneration: this.controller.snapshot().generation,
                providerConnectionGeneration: this.controller.snapshot().connectionGeneration,
                conversationContext: effect.conversationContext,
            }).then((snapshot) => this.applyProjection(snapshot))
                .finally(() => this.admissions.delete(admission));
            this.admissions.add(admission);
        }
    }

    #onSpeechEvent(event) {
        if (event.type === 'playback.started') {
            if (event.purpose === 'final') {
                void this.server.request(`/assistant/voice/turns/${encodeURIComponent(event.turnId)}/delivery`, {
                    method: 'POST',
                    body: {
                        session_id: SESSION_ID,
                        event: 'final_audio_started',
                        timing: { purpose: 'final', speech_item_id: event.itemId },
                    },
                });
            }
            this.controller.playbackStarted({
                turnId: event.turnId,
                naturalClosing: Boolean(event.metadata?.naturalClosing),
                source: 'speech-scheduler',
            });
        } else if (event.type === 'playback.finished') {
            this.controller.playbackFinished({
                turnId: event.turnId,
                naturalClosing: Boolean(event.metadata?.naturalClosing),
                source: 'speech-scheduler',
            });
        }
        this.#renderDiagnostics();
    }

    #renderController(state, event = {}) {
        const captureActive = Boolean(state.followUpCandidate) || [
            BROWSER_VOICE_CONVERSATION_STATES.ACTIVATING,
            BROWSER_VOICE_CONVERSATION_STATES.CAPTURING,
        ].includes(state.conversationState);
        if (captureActive) this.speech.captureStarted(`voice_${state.conversationState}`);
        else this.speech.captureEnded(`voice_${state.conversationState}`, {
            resume: event.type !== 'stop_playback',
        });
        this.dom.state.dataset.state = state.conversationState;
        this.dom.state.textContent = state.conversationState;
        this.#renderDiagnostics();
    }

    #renderProjection(projection) {
        const visibleTurns = projection.turns.filter((turn) => turn.state !== 'canceled');
        this.dom.chat.replaceChildren(...visibleTurns.flatMap((turn) => {
            const user = document.createElement('li');
            user.dataset.role = 'user';
            user.dataset.turnId = turn.turnId;
            user.textContent = turn.transcript;
            const nodes = [user];
            if (turn.finalText) {
                const assistant = document.createElement('li');
                assistant.dataset.role = 'assistant';
                assistant.dataset.turnId = turn.turnId;
                assistant.textContent = turn.finalText;
                nodes.push(assistant);
            }
            return nodes;
        }));

        this.dom.dock.replaceChildren(...projection.jobs.map((job) => {
            const item = document.createElement('li');
            item.dataset.jobId = job.id;
            item.dataset.turnId = job.turnId;
            item.dataset.status = job.status;
            item.textContent = `${job.label}: ${job.status}`;
            return item;
        }));
        this.#renderDiagnostics();
    }

    #renderDiagnostics() {
        this.dom.diagnostics.value = JSON.stringify({
            cursor: this.currentCursor,
            rejected: this.controller.snapshot().rejectedEventCount,
            networkErrors: this.networkErrors.length,
            playbackState: this.speech.snapshot().state,
        });
    }
}

function measureSyntheticSpeechStart(schedule, { acknowledgementGraceMs = 350 } = {}) {
    return new Promise((resolve, reject) => {
        const startedAt = performance.now();
        const timeout = setTimeout(() => reject(new Error('Synthetic speech did not start.')), 2_000);
        const scheduler = new BrowserVoiceSpeechSchedulerV2({
            acknowledgementGraceMs,
            playback: new BrowserVoicePlaybackAdapterV2({
                play: (_item, listeners) => {
                    queueMicrotask(() => {
                        listeners.onStart();
                        clearTimeout(timeout);
                        resolve(performance.now() - startedAt);
                        listeners.onEnd('completed');
                    });
                    return {};
                },
            }),
        });
        schedule(scheduler);
    });
}

function summarize(values, p95Target) {
    const ordered = [...values].sort((left, right) => left - right);
    const percentile = (value) => ordered[Math.min(ordered.length - 1, Math.ceil(value * ordered.length) - 1)] || 0;
    const p50 = percentile(0.5);
    const p95 = percentile(0.95);
    return {
        p50: Number(p50.toFixed(3)),
        p95: Number(p95.toFixed(3)),
        max: Number((ordered.at(-1) || 0).toFixed(3)),
        target_p95: p95Target,
        pass: p95 <= p95Target,
    };
}

window.voiceHarness = new VoiceV2BrowserJourneyHarness();
window.voiceHarnessReady = window.voiceHarness.ready;
