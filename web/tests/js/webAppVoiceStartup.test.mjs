import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

import {
    BROWSER_VOICE_CONVERSATION_STATES,
    BROWSER_VOICE_EFFECTS,
    BROWSER_VOICE_TIMER_KEYS,
    BrowserVoiceControllerV2,
} from '../../resources/js/heybean/browserVoiceControllerV2.js';
import {
    BROWSER_VOICE_LOCAL_PCM_RATE,
    BrowserVoiceRealtimeInputTransportV2,
} from '../../resources/js/heybean/browserVoiceRealtimeInputV2.js';
import {
    BROWSER_VOICE_REALTIME_INGRESS_RESULTS,
    BrowserVoiceProviderItemRegistryV2,
    bindBrowserVoiceProviderSpeechStartedV2,
    createBrowserVoiceProviderTurnIdentityV2,
    currentBrowserVoiceProviderItemBindingV2,
    routeBrowserVoiceRealtimeIngressV2,
} from '../../resources/js/heybean/browserVoiceRealtimeIngressV2.js';
import {
    RealtimeInputTranscriptBuffer,
    VoiceClientFailureReporter,
    voiceClientFailureId,
} from '../../resources/js/heybean/realtimeVoiceTurn.js';

const source = await readFile(new URL('../../resources/js/heybean/webApp.js', import.meta.url), 'utf8');
globalThis.window = { matchMedia: () => null };
const {
    BROWSER_VOICE_PROVIDER_PROTOCOL_FAILURES,
    activateBrowserVoiceV2LocalWakeTransport,
    applyBrowserVoiceV2ProviderProtocolFailure,
    applyBrowserVoiceV2PotentialBargeProofCleanup,
    applyBrowserVoiceV2TranscriptionFailure,
    applyBrowserVoiceV2WakeOnlyPrivacyBoundary,
    browserVoiceV2LocalWakeMatchesCompletedBarge,
    browserVoiceV2ProviderInputIsActive,
    browserVoiceV2ProviderProtocolFailureForEvent,
    isMeaningfulBrowserVoiceV2Interruption,
    teardownBrowserVoiceV2ProviderFailure,
} = await import('../../resources/js/heybean/webApp.js');

test('[BV2-TIMEZONE-01] browser admission and chat metadata never invent UTC when the local zone is unavailable', () => {
    assert.match(source, /function resolvedBrowserTimeZone\(\)/);
    assert.match(source, /timezone: resolvedBrowserTimeZone\(\)/);
    assert.doesNotMatch(source, /resolvedOptions\(\)\.timeZone \|\| 'UTC'/);
});

test('generic web chat admits every submit immediately and recovers only by exact stable run identity', () => {
    assert.doesNotMatch(source, /chatQueue|scheduleChatQueueDrain|drainChatQueue|client_queue_status/);
    const submitStart = source.indexOf('async function submitChat(');
    const submitEnd = source.indexOf('\n    async function copyChatMessage(', submitStart);
    const submit = source.slice(submitStart, submitEnd);
    assert.match(submit, /void sendChatContent\(/);
    assert.doesNotMatch(submit, /chatHasActiveTurn/);

    const recoveryStart = source.indexOf('async function recoverChatFailureFromServer(');
    const recoveryEnd = source.indexOf('\n    function replaceLocalUserMessage(', recoveryStart);
    const recovery = source.slice(recoveryStart, recoveryEnd);
    assert.match(recovery, /runs\/lookup\?client_request_id=/);
    assert.doesNotMatch(recovery, /messages\.slice|find\(\(message\).*assistant/);
});

test('[BV2-STARTUP-01] Bean session hydration starts before the unrelated 25-second dashboard feed', () => {
    const start = source.indexOf('async function loadSignedIn(options = {})');
    const end = source.indexOf('\n    function mergeUser(', start);
    assert.ok(start >= 0 && end > start, 'loadSignedIn must remain discoverable');
    const implementation = source.slice(start, end);
    const hydrate = implementation.indexOf('loadChatSessions({ resumeToday: true })');
    const hydrateFinally = implementation.indexOf('}).finally(() => {', hydrate);
    const feed = implementation.indexOf('startDashboardChangeFeed();');

    assert.ok(hydrate >= 0, 'chat session hydration must start during signed-in bootstrap');
    assert.ok(hydrateFinally > hydrate, 'chat session hydration must own a completion boundary');
    assert.ok(feed > hydrateFinally, 'the long-poll feed must start only after chat hydration settles');
    assert.equal((implementation.match(/startDashboardChangeFeed\(\);/g) || []).length, 1);
});

test('[BV2-WAKE-01] wake-ready is published only after provider transport and a fresh local barrier', () => {
    const connectStart = source.indexOf('async function connectRealtimeVoice(');
    const connectEnd = source.indexOf('\n    function handleRealtimeConnectionLoss(', connectStart);
    assert.ok(connectStart >= 0 && connectEnd > connectStart, 'realtime startup must remain discoverable');
    const connect = source.slice(connectStart, connectEnd);

    const localWakeSetup = connect.indexOf('const localWakePromise = localWakeGate.start');
    const recvOnly = connect.indexOf("peerConnection.addTransceiver('audio', { direction: 'recvonly' })");
    const offer = connect.indexOf('const offer = await peerConnection.createOffer()');
    const localDescription = connect.indexOf('peerConnection.localDescription?.sdp', offer);
    const parallelSetup = connect.indexOf('Promise.all([localWakePromise, openRealtimeSession(localOfferSdp)])');
    const providerTransport = connect.indexOf('transportReady,');
    const consumerEnabled = connect.indexOf('localWakeGate.setConsumerReady(true)', providerTransport);
    const freshBoundary = connect.indexOf('localWakeGate.resetAfterTurn()', consumerEnabled);
    const finalLocalBarrier = connect.indexOf(
        'await waitForLocalWakeReady(localWakeGate, connectionGeneration)',
        freshBoundary,
    );
    const finalConsumerBarrier = connect.indexOf(
        'await waitForLocalWakeConsumerAdmission(localWakeGate, connectionGeneration)',
        finalLocalBarrier,
    );
    const returnReadyGate = connect.indexOf('return localWakeGate;', finalConsumerBarrier);

    assert.ok(localWakeSetup >= 0 && recvOnly > localWakeSetup, 'local wake setup must start before negotiation');
    assert.ok(offer > recvOnly && localDescription > offer, 'the negotiated local description must own the submitted SDP');
    assert.ok(parallelSetup > localDescription, 'local wake readiness and same-origin SDP setup must overlap');
    assert.doesNotMatch(connect, /direction:\s*'sendrecv'|addTrack\(/);
    assert.ok(providerTransport > parallelSetup, 'provider capture readiness must be awaited');
    assert.ok(consumerEnabled > providerTransport, 'consumer admission may open only after provider readiness');
    assert.ok(freshBoundary > consumerEnabled, 'startup audio must be discarded in a consumer-enabled generation');
    assert.ok(finalLocalBarrier > freshBoundary, 'the fresh local generation must become fully ready');
    assert.ok(finalConsumerBarrier > finalLocalBarrier, 'the fresh generation must decode post-boundary PCM');
    assert.ok(returnReadyGate > finalConsumerBarrier, 'only the fully wake-admissible local gate may leave startup');

    const start = source.indexOf('async function startVoiceWakeListening(');
    const end = source.indexOf('\n    function toggleVoiceWakeListening(', start);
    assert.ok(start >= 0 && end > start, 'voice-toggle startup must remain discoverable');
    const implementation = source.slice(start, end);
    const connectReady = implementation.indexOf('const readyWakeGate = await connectRealtimeVoice(connectionGeneration)');
    const localReadyCheck = implementation.indexOf('!readyWakeGate.isReady()', connectReady);
    const consumerReadyCheck = implementation.indexOf('!readyWakeGate.isConsumerAdmissionReady()', localReadyCheck);
    const providerReady = implementation.indexOf('browserVoiceV2Controller.providerReady', consumerReadyCheck);
    const active = implementation.indexOf('realtimeVoiceActive = true', providerReady);
    const listening = implementation.indexOf('state.voiceWakeListening = true', active);
    const readyLabel = implementation.indexOf("'Listening for “Hey Bean”…'", listening);

    assert.ok(connectReady >= 0);
    assert.ok(localReadyCheck > connectReady, 'the final local barrier must be verified at handoff');
    assert.ok(consumerReadyCheck > localReadyCheck, 'handoff must verify wake admission, not only worker readiness');
    assert.ok(providerReady > consumerReadyCheck, 'controller readiness must wait for both transports');
    assert.ok(active > providerReady && listening > active, 'capture must become active before publishing listening');
    assert.ok(readyLabel > listening, 'the UI may claim listening only after consumer admission opens');

    const detectedStart = source.indexOf('function handleLocalWakeDetected(');
    const detectedEnd = source.indexOf('\n    function handleLocalWakeFailure(', detectedStart);
    const detected = source.slice(detectedStart, detectedEnd);
    assert.match(detected, /const completingStartup = state\.voiceProcessing/);
    assert.match(detected, /gate\.isConsumerAdmissionReady\(\)/);
    assert.match(detected, /\(!state\.voiceWakeListening && !completingStartup\)/);

    const stopStart = source.indexOf('function stopVoiceWakeListening(');
    const stopEnd = source.indexOf('\n    function stopRealtimeVoiceForContextChange(', stopStart);
    const stop = source.slice(stopStart, stopEnd);
    assert.match(stop, /realtimeLocalTeardown = Promise\.allSettled\(\[precedingTeardown, localWakeStopping\]\)/);

    const teardownBarrier = implementation.indexOf('await realtimeLocalTeardown;');
    const postBarrierGenerationCheck = implementation.indexOf(
        'if (connectionGeneration !== realtimeConnectionGeneration) return;',
        teardownBarrier,
    );
    assert.ok(teardownBarrier >= 0 && teardownBarrier < connectReady, 're-arm must finish the previous local teardown before reconnecting');
    assert.ok(
        postBarrierGenerationCheck > teardownBarrier && postBarrierGenerationCheck < connectReady,
        'a stop during the teardown wait must invalidate the pending restart',
    );
});

test('[BV2-PRIVACY-PCM-04] local wake clears provider input before LocalWakeGate flushes activated PCM', () => {
    const bridgeStart = source.indexOf('export function activateBrowserVoiceV2LocalWakeTransport(');
    const bridgeEnd = source.indexOf('\nexport function applyBrowserVoiceV2WakeOnlyPrivacyBoundary(', bridgeStart);
    const bridge = source.slice(bridgeStart, bridgeEnd);
    const clear = bridge.indexOf('inputTransport.activate');
    const controllerWake = bridge.indexOf('controller.wakeConfirmed');
    assert.ok(clear >= 0 && controllerWake > clear, 'input clear must precede capture activation');

    const detectedStart = source.indexOf('function handleLocalWakeDetected(');
    const detectedEnd = source.indexOf('\n    function handleLocalWakeFailure(', detectedStart);
    assert.ok(detectedStart >= 0 && detectedEnd > detectedStart);
    const detected = source.slice(detectedStart, detectedEnd);
    assert.match(detected, /activateBrowserVoiceV2LocalWakeTransport\(\{/);
    assert.match(detected, /inputTransport: browserVoiceV2InputTransport/);
    assert.match(detected, /generation: gate\.currentGeneration\(\)/);

    const gateStart = source.indexOf('const localWakeGate = new LocalWakeGate({');
    const microphoneStart = source.indexOf('navigator.mediaDevices.getUserMedia', gateStart);
    const gateOptions = source.slice(gateStart, microphoneStart);
    assert.match(gateOptions, /onActivatedPcm:[\s\S]*browserVoiceV2InputTransport\.append/);
});

test('[BV2-FIRST-WAKE-01:C] the production local-wake bridge reaches one transcript admission handoff', () => {
    const providerEvents = [];
    const controllerEffects = [];
    const providerItems = new BrowserVoiceProviderItemRegistryV2();
    let timerId = 0;
    const inputTransport = new BrowserVoiceRealtimeInputTransportV2({
        send: (event) => {
            providerEvents.push(event);
            return true;
        },
        encodeBase64: () => 'activated-pcm',
    });
    const controller = new BrowserVoiceControllerV2({
        clock: () => 100,
        timers: {
            setTimeout: () => ++timerId,
            clearTimeout: () => {},
        },
        createTurnId: () => 'first-production-wake',
        onEffect: (effect) => controllerEffects.push(effect),
    });
    controller.start();
    controller.providerReady({ source: 'webrtc' });

    const wake = activateBrowserVoiceV2LocalWakeTransport({
        controller,
        inputTransport,
        generation: 7,
    });
    assert.equal(wake.state.activeTurn.id, 'first-production-wake');
    assert.equal(inputTransport.append({
        generation: 7,
        sourceSequence: 1,
        sampleRate: BROWSER_VOICE_LOCAL_PCM_RATE,
        samples: new Float32Array(1600).fill(0.2),
        released: true,
    }), true);
    controller.activationReady({ source: 'local_wake_gate' });
    const started = bindBrowserVoiceProviderSpeechStartedV2(controller, providerItems, {
        providerItemId: 'provider-first-wake',
        connectionGeneration: 7,
    });
    assert.equal(started.binding.turnId, 'first-production-wake');
    routeBrowserVoiceRealtimeIngressV2(controller, {
        type: 'transcript_partial',
        text: 'Can you',
        providerItemId: 'provider-first-wake',
    });
    assert.equal(currentBrowserVoiceProviderItemBindingV2(providerItems, controller, {
        providerItemId: 'provider-first-wake',
        connectionGeneration: 7,
        consume: true,
    })?.turnId, 'first-production-wake');
    routeBrowserVoiceRealtimeIngressV2(controller, {
        type: 'transcript_final',
        text: 'Can you hear me?',
        providerItemId: 'provider-first-wake',
    });
    controller.dispatch({
        type: 'timer_fired',
        timerKey: BROWSER_VOICE_TIMER_KEYS.ENDPOINT,
        turnId: 'first-production-wake',
        source: 'timer:endpoint',
        atMs: 100,
    });

    assert.deepEqual(providerEvents.map((event) => event.type), [
        'input_audio_buffer.clear',
        'input_audio_buffer.append',
    ]);
    const admissions = controllerEffects.filter((effect) => effect.type === BROWSER_VOICE_EFFECTS.TURN_READY);
    assert.equal(admissions.length, 1);
    assert.equal(admissions[0].turnId, 'first-production-wake');
    assert.equal(admissions[0].transcript, 'Can you hear me?');
});

test('[BV2-TRANSCRIPT-03][BV2-PRIVACY-PCM-03][BV2-DIAGNOSTIC-03] first-wake transcription failure reports once, closes activated PCM, and rearms wake-only', async () => {
    const providerEvents = [];
    const diagnostics = [];
    const controllerEffects = [];
    const localWakeGate = {
        open: true,
        resetCount: 0,
        resetAfterTurn() {
            this.open = false;
            this.resetCount += 1;
            return true;
        },
    };
    const inputTransport = new BrowserVoiceRealtimeInputTransportV2({
        send: (event) => {
            providerEvents.push(event);
            return true;
        },
        encodeBase64: () => 'activated-pcm',
    });
    const providerItems = new BrowserVoiceProviderItemRegistryV2();
    let previousConversationState = BROWSER_VOICE_CONVERSATION_STATES.OFF;
    const controller = new BrowserVoiceControllerV2({
        clock: () => 250,
        createTurnId: () => 'failed-production-wake',
        onEffect: (effect) => controllerEffects.push(effect),
        onStateChange: (snapshot, event) => {
            const previous = previousConversationState;
            previousConversationState = snapshot.conversationState;
            applyBrowserVoiceV2WakeOnlyPrivacyBoundary({
                snapshot,
                previousConversationState: previous,
                event,
                inputTransport,
                localWakeGate,
            });
        },
    });
    const reporter = new VoiceClientFailureReporter({
        send: async (diagnostic) => {
            diagnostics.push(diagnostic);
            return { recorded: true };
        },
        eventTarget: null,
        scopeId: 'test-user-transcription',
        retryDelaysMs: [],
    });
    const reportFailure = (payload, transcriptId, turnId) => reporter.enqueue({
        failure_id: voiceClientFailureId('transcription', transcriptId || turnId),
        stage: 'transcription',
        code: payload?.error?.code,
        message: payload?.error?.message,
        cause_chain: [],
        turn_id: turnId,
    });

    controller.start();
    controller.providerReady({ source: 'webrtc' });
    activateBrowserVoiceV2LocalWakeTransport({
        controller,
        inputTransport,
        generation: 11,
    });
    assert.equal(inputTransport.append({
        generation: 11,
        sourceSequence: 8,
        sampleRate: BROWSER_VOICE_LOCAL_PCM_RATE,
        samples: new Float32Array(1600).fill(0.15),
        released: true,
    }), true);
    controller.activationReady({ source: 'local_wake_gate' });
    const started = bindBrowserVoiceProviderSpeechStartedV2(controller, providerItems, {
        providerItemId: 'provider-item-1',
        connectionGeneration: 11,
    });
    assert.equal(started.binding.turnId, 'failed-production-wake');
    routeBrowserVoiceRealtimeIngressV2(controller, {
        type: 'transcript_partial',
        text: 'Can you',
        providerItemId: 'provider-item-1',
    });
    const binding = currentBrowserVoiceProviderItemBindingV2(providerItems, controller, {
        providerItemId: 'provider-item-1',
        connectionGeneration: 11,
        consume: true,
    });

    applyBrowserVoiceV2TranscriptionFailure({
        controller,
        payload: {
            event_id: 'transcription-failure-1',
            error: { code: 'hostile provider code', message: 'transcript=private pcm=AAAA' },
        },
        binding,
        reportFailure,
    });
    await reporter.flush();

    const failed = controller.snapshot();
    assert.equal(failed.conversationState, BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY);
    assert.equal(failed.activeTurn, null);
    assert.deepEqual(failed.deadlines, { endpointAt: null, clarificationAt: null, followUpAt: null });
    assert.ok(failed.closedTurnIds.includes('failed-production-wake'));
    assert.equal(localWakeGate.open, false);
    assert.equal(localWakeGate.resetCount, 1);
    assert.equal(inputTransport.append({
        generation: 11,
        sourceSequence: 9,
        sampleRate: BROWSER_VOICE_LOCAL_PCM_RATE,
        samples: new Float32Array(1600).fill(0.3),
    }), false);
    assert.deepEqual(providerEvents.map((event) => event.type), [
        'input_audio_buffer.clear',
        'input_audio_buffer.append',
    ]);
    assert.equal(controllerEffects.some((effect) => effect.type === BROWSER_VOICE_EFFECTS.TURN_READY), false);
    assert.equal(diagnostics.length, 1);
    assert.equal(diagnostics[0].failure_id, voiceClientFailureId('transcription', 'provider-item-1'));
    assert.equal(diagnostics[0].code, 'voice_transcription_failure');
    assert.doesNotMatch(JSON.stringify(diagnostics[0]), /private|AAAA|hostile/);
    reporter.dispose();
});

test('[BV2-FIRST-WAKE-01:E][BV2-TRANSCRIPT-03] production binds provider starts through immutable ordered PCM admission identity', () => {
    const detectedStart = source.indexOf('function handleLocalWakeDetected(');
    const detectedEnd = source.indexOf('\n    function handleLocalWakeDiagnostic(', detectedStart);
    const detected = source.slice(detectedStart, detectedEnd);
    assert.match(detected, /createBrowserVoiceProviderTurnIdentityV2\(browserVoiceV2Controller/);
    assert.match(detected, /inputGeneration: gate\.currentGeneration\(\)/);
    assert.match(detected, /throughSourceSequence: detection\.sourceSequence/);
    assert.match(detected, /providerConnectionGeneration: connectionGeneration/);
    assert.match(detected, /browserVoiceV2PendingLocalWakeAdmissions\.push\(admission\)/);

    const claimStart = source.indexOf('function claimBrowserVoiceV2ProviderItemLocalWakeAdmission(');
    const claimEnd = source.indexOf('\n    function rememberBrowserVoiceV2CompletedProviderBarge(', claimStart);
    const claim = source.slice(claimStart, claimEnd);
    assert.match(claim, /browserVoiceV2PendingLocalWakeAdmissions\.shift\(\)/,
        'provider VAD must claim the oldest exact local PCM admission');

    const ingressStart = source.indexOf('function handleBrowserVoiceV2RealtimeEvent(');
    const ingressEnd = source.indexOf('\n    async function reportBrowserVoiceV2RealtimeUsage(', ingressStart);
    const ingress = source.slice(ingressStart, ingressEnd);
    assert.match(ingress, /turnIdentity: providerWakeAdmission/);
    assert.match(ingress, /inputGeneration: browserVoiceV2InputTransport\.activeGeneration/);
    assert.match(ingress, /throughSourceSequence: browserVoiceV2LastProviderInputPcm\?\.sourceSequence/);
    assert.match(ingress, /staleTurnIdentity[\s\S]*ITEM_TURN_MISMATCH/,
        'an ambiguous delayed item must fail the active input closed instead of binding the current turn');
});

test('[BV2-FIRST-WAKE-01:E][BV2-TRANSCRIPT-03][BV2-DIAGNOSTIC-03] delayed cross-turn provider identity fails the newer active capture closed exactly once', async () => {
    const diagnostics = [];
    const providerItems = new BrowserVoiceProviderItemRegistryV2();
    const inputTransport = new BrowserVoiceRealtimeInputTransportV2({
        send: () => true,
        encodeBase64: () => 'activated-pcm',
    });
    const turnIds = ['older-pcm-turn', 'newer-pcm-turn'];
    const controller = new BrowserVoiceControllerV2({ createTurnId: () => turnIds.shift() });
    controller.start();
    controller.providerReady({ source: 'webrtc' });
    activateBrowserVoiceV2LocalWakeTransport({ controller, inputTransport, generation: 201 });
    assert.equal(inputTransport.append({
        generation: 201,
        sourceSequence: 10,
        sampleRate: BROWSER_VOICE_LOCAL_PCM_RATE,
        samples: new Float32Array(1600).fill(0.1),
    }), true);
    const olderAdmission = createBrowserVoiceProviderTurnIdentityV2(controller, {
        inputGeneration: 201,
        throughSourceSequence: 10,
        providerConnectionGeneration: 44,
    });
    controller.activationReady({ source: 'local_wake_gate' });

    activateBrowserVoiceV2LocalWakeTransport({ controller, inputTransport, generation: 202 });
    assert.equal(inputTransport.append({
        generation: 202,
        sourceSequence: 20,
        sampleRate: BROWSER_VOICE_LOCAL_PCM_RATE,
        samples: new Float32Array(1600).fill(0.2),
    }), true);
    const delayed = bindBrowserVoiceProviderSpeechStartedV2(controller, providerItems, {
        providerItemId: 'delayed-older-provider-item',
        connectionGeneration: 44,
        turnIdentity: olderAdmission,
        inputGeneration: inputTransport.activeGeneration,
        throughSourceSequence: 20,
    });
    assert.equal(delayed.staleTurnIdentity, true);
    assert.equal(controller.snapshot().activeTurn.id, 'newer-pcm-turn');
    assert.equal(controller.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.ACTIVATING);

    let active = true;
    let teardownCount = 0;
    const reporter = new VoiceClientFailureReporter({
        send: async (diagnostic) => {
            diagnostics.push(diagnostic);
            return { recorded: true };
        },
        eventTarget: null,
        scopeId: 'cross-turn-provider-identity',
        retryDelaysMs: [],
    });
    const reportFailure = (error) => reporter.enqueue({
        failure_id: voiceClientFailureId('connection', 'generation-44', 'provider-turn-mismatch'),
        stage: 'connection',
        code: error?.code,
        message: error?.message,
        cause_chain: [],
        turn_id: 'newer-pcm-turn',
    });
    const teardown = (_message, options) => {
        assert.equal(options.reportFailure, false);
        if (!active) return;
        active = false;
        teardownCount += 1;
        inputTransport.deactivate();
        providerItems.clear();
        controller.disable('provider_protocol_failure');
    };
    for (let attempt = 0; attempt < 2; attempt += 1) {
        applyBrowserVoiceV2ProviderProtocolFailure({
            code: BROWSER_VOICE_PROVIDER_PROTOCOL_FAILURES.ITEM_TURN_MISMATCH,
            reportFailure,
            teardown,
        });
    }
    await reporter.flush();

    assert.equal(teardownCount, 1);
    assert.equal(diagnostics.length, 1);
    assert.equal(diagnostics[0].turn_id, 'newer-pcm-turn');
    assert.equal(controller.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.OFF);
    assert.equal(inputTransport.activeGeneration, null);
    assert.equal(controller.drainEffects().some((effect) => effect.type === BROWSER_VOICE_EFFECTS.TURN_READY), false);
    reporter.dispose();
});

test('[BV2-FIRST-WAKE-01:E][BV2-WAKE-01][BV2-TRANSCRIPT-03][BV2-DIAGNOSTIC-03] stale first-item failure cannot reset or fail the next wake', () => {
    const providerEvents = [];
    const diagnostics = [];
    const turnIds = ['older-wake-turn', 'next-wake-turn'];
    const providerItems = new BrowserVoiceProviderItemRegistryV2();
    const inputTransport = new BrowserVoiceRealtimeInputTransportV2({
        send: (event) => {
            providerEvents.push(event);
            return true;
        },
        encodeBase64: () => 'activated-pcm',
    });
    const localWakeGate = {
        generation: 40,
        resetCount: 0,
        resetAfterTurn() {
            this.generation += 1;
            this.resetCount += 1;
            return true;
        },
    };
    let previousConversationState = BROWSER_VOICE_CONVERSATION_STATES.OFF;
    const controller = new BrowserVoiceControllerV2({
        createTurnId: () => turnIds.shift(),
        onStateChange: (snapshot, event) => {
            const previous = previousConversationState;
            previousConversationState = snapshot.conversationState;
            applyBrowserVoiceV2WakeOnlyPrivacyBoundary({
                snapshot,
                previousConversationState: previous,
                event,
                inputTransport,
                localWakeGate,
            });
        },
    });
    const reportFailure = (_payload, transcriptId, turnId) => {
        diagnostics.push({ transcriptId, turnId });
    };

    controller.start();
    controller.providerReady({ source: 'webrtc' });
    activateBrowserVoiceV2LocalWakeTransport({
        controller,
        inputTransport,
        generation: localWakeGate.generation,
    });
    controller.activationReady({ source: 'local_wake_gate' });
    const olderStarted = bindBrowserVoiceProviderSpeechStartedV2(controller, providerItems, {
        providerItemId: 'provider-older-item',
        connectionGeneration: 40,
    });
    const olderBinding = currentBrowserVoiceProviderItemBindingV2(providerItems, controller, {
        providerItemId: 'provider-older-item',
        connectionGeneration: 40,
        consume: true,
    });
    assert.equal(olderStarted.binding.turnId, 'older-wake-turn');
    applyBrowserVoiceV2TranscriptionFailure({
        controller,
        payload: { error: { code: 'transcription_failed' } },
        binding: olderBinding,
        reportFailure,
    });
    assert.equal(controller.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY);
    assert.equal(localWakeGate.resetCount, 1);
    const nextWakeGeneration = localWakeGate.generation;

    const wakeOnly = controller.snapshot();
    controller.dispatch({
        type: 'capture_failed',
        turnId: 'older-wake-turn',
        reason: 'late_duplicate',
        source: 'provider_transcript',
        sequence: 1,
        generation: wakeOnly.generation,
        connectionGeneration: wakeOnly.connectionGeneration,
    });
    assert.equal(controller.snapshot().lastRejectedEvent.reason, 'stale_sequence');
    assert.equal(localWakeGate.resetCount, 1, 'a rejected event in wake-only must not create a new local generation');
    assert.equal(localWakeGate.generation, nextWakeGeneration);

    const nextWake = activateBrowserVoiceV2LocalWakeTransport({
        controller,
        inputTransport,
        generation: nextWakeGeneration,
    });
    assert.equal(nextWake.state.activeTurn.id, 'next-wake-turn');
    controller.activationReady({ source: 'local_wake_gate' });
    const nextStarted = bindBrowserVoiceProviderSpeechStartedV2(controller, providerItems, {
        providerItemId: 'provider-next-item',
        connectionGeneration: 40,
    });
    assert.equal(nextStarted.binding.turnId, 'next-wake-turn');
    assert.equal(inputTransport.append({
        generation: nextWakeGeneration,
        sourceSequence: 2,
        sampleRate: BROWSER_VOICE_LOCAL_PCM_RATE,
        samples: new Float32Array(1600).fill(0.2),
    }), true);

    const lateOlderBinding = currentBrowserVoiceProviderItemBindingV2(providerItems, controller, {
        providerItemId: 'provider-older-item',
        connectionGeneration: 40,
        consume: true,
    });
    assert.equal(lateOlderBinding, null);
    assert.equal(applyBrowserVoiceV2TranscriptionFailure({
        controller,
        payload: { error: { code: 'late_older_failure' } },
        binding: lateOlderBinding,
        reportFailure,
    }), null);
    assert.equal(controller.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.CAPTURING);
    assert.equal(controller.snapshot().activeTurn.id, 'next-wake-turn');
    assert.deepEqual(diagnostics, [{
        transcriptId: 'provider-older-item',
        turnId: 'older-wake-turn',
    }]);
    assert.equal(localWakeGate.resetCount, 1);

    const nextBinding = currentBrowserVoiceProviderItemBindingV2(providerItems, controller, {
        providerItemId: 'provider-next-item',
        connectionGeneration: 40,
        consume: true,
    });
    applyBrowserVoiceV2TranscriptionFailure({
        controller,
        payload: { error: { code: 'current_failure' } },
        binding: nextBinding,
        reportFailure,
    });
    assert.deepEqual(diagnostics.at(-1), {
        transcriptId: 'provider-next-item',
        turnId: 'next-wake-turn',
    });
    assert.equal(controller.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY);
    assert.equal(localWakeGate.resetCount, 2, 'only the accepted current-turn failure may rearm privacy');
    assert.deepEqual(providerEvents.map((event) => event.type), [
        'input_audio_buffer.clear',
        'input_audio_buffer.clear',
        'input_audio_buffer.append',
    ]);
});

test('[BV2-FIRST-WAKE-01:E][BV2-WAKE-01][BV2-PRIVACY-PCM-03][BV2-DIAGNOSTIC-03] missing provider identity tears down once and a fresh retry reconnects privately', async () => {
    const providerEvents = [];
    const diagnostics = [];
    const turnIds = ['missing-id-turn', 'retry-turn'];
    const inputTransport = new BrowserVoiceRealtimeInputTransportV2({
        send: (event) => {
            providerEvents.push(event);
            return true;
        },
        encodeBase64: () => 'activated-pcm',
    });
    const controller = new BrowserVoiceControllerV2({ createTurnId: () => turnIds.shift() });
    const rawTrack = { stopCount: 0, stop() { this.stopCount += 1; } };
    const localWakeGate = { stopCount: 0, stop() { this.stopCount += 1; } };
    let voiceActive = true;
    let teardownCount = 0;
    let retryMessage = '';
    const reporter = new VoiceClientFailureReporter({
        send: async (diagnostic) => {
            diagnostics.push(diagnostic);
            return { recorded: true };
        },
        eventTarget: null,
        scopeId: 'provider-protocol-missing-id',
        retryDelaysMs: [],
    });
    const reportFailure = (error) => reporter.enqueue({
        failure_id: voiceClientFailureId('connection', 'generation-51', 'missing-provider-item-id'),
        stage: 'connection',
        code: error?.code,
        message: error?.message,
        cause_chain: [],
        turn_id: controller.snapshot().activeTurn?.id || '',
    });
    const teardown = (message, options) => {
        assert.equal(options.reportFailure, false);
        if (!voiceActive) return;
        voiceActive = false;
        teardownCount += 1;
        retryMessage = message;
        controller.dispatch({ type: 'connection_lost', source: 'webrtc', reason: message });
        inputTransport.deactivate();
        localWakeGate.stop();
        rawTrack.stop();
        controller.disable('connection_lost');
    };

    controller.start();
    controller.providerReady({ source: 'webrtc' });
    activateBrowserVoiceV2LocalWakeTransport({ controller, inputTransport, generation: 51 });
    controller.activationReady({ source: 'local_wake_gate' });
    assert.equal(inputTransport.append({
        generation: 51,
        sourceSequence: 1,
        sampleRate: BROWSER_VOICE_LOCAL_PCM_RATE,
        samples: new Float32Array(1600).fill(0.1),
    }), true);

    const protocolCode = browserVoiceV2ProviderProtocolFailureForEvent({
        eventType: 'input_audio_buffer.speech_started',
        providerInputActive: browserVoiceV2ProviderInputIsActive({ inputTransport }),
        providerItemId: '',
    });
    assert.equal(protocolCode, BROWSER_VOICE_PROVIDER_PROTOCOL_FAILURES.MISSING_ITEM_ID);
    for (let attempt = 0; attempt < 2; attempt += 1) {
        applyBrowserVoiceV2ProviderProtocolFailure({
            code: protocolCode,
            reportFailure,
            teardown,
        });
    }
    await reporter.flush();

    assert.equal(teardownCount, 1);
    assert.equal(localWakeGate.stopCount, 1);
    assert.equal(rawTrack.stopCount, 1);
    assert.equal(controller.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.OFF);
    assert.equal(inputTransport.append({
        generation: 51,
        sourceSequence: 2,
        sampleRate: BROWSER_VOICE_LOCAL_PCM_RATE,
        samples: new Float32Array(1600).fill(0.2),
    }), false, 'no provider PCM may survive the fail-closed teardown');
    assert.match(retryMessage, /Tap the Bean button to reconnect and try again/);
    assert.equal(diagnostics.length, 1);
    assert.equal(diagnostics[0].code, 'voice_connection_failure');
    assert.equal(diagnostics[0].turn_id, 'missing-id-turn');

    voiceActive = true;
    controller.start();
    controller.providerReady({ source: 'webrtc' });
    const retry = activateBrowserVoiceV2LocalWakeTransport({
        controller,
        inputTransport,
        generation: 52,
    });
    controller.activationReady({ source: 'local_wake_gate' });
    assert.equal(retry.state.activeTurn.id, 'retry-turn');
    assert.equal(inputTransport.append({
        generation: 52,
        sourceSequence: 1,
        sampleRate: BROWSER_VOICE_LOCAL_PCM_RATE,
        samples: new Float32Array(1600).fill(0.3),
    }), true);
    assert.equal(controller.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.CAPTURING);
    assert.deepEqual(providerEvents.map((event) => event.type), [
        'input_audio_buffer.clear',
        'input_audio_buffer.append',
        'input_audio_buffer.clear',
        'input_audio_buffer.append',
    ]);
    reporter.dispose();

    assert.equal(browserVoiceV2ProviderProtocolFailureForEvent({
        eventType: 'conversation.item.input_audio_transcription.completed',
        providerInputActive: false,
        providerItemId: '',
    }), null, 'an id-less ambient event after dormant privacy teardown is ignored');
    assert.match(source, /const protocolFailure = browserVoiceV2ProviderProtocolFailureForEvent\(\{/);
    assert.match(source, /if \(protocolFailure\) return failBrowserVoiceV2ProviderProtocol\(protocolFailure\)/);
});

test('[BV2-TRANSCRIPT-03][BV2-BARGE-04][BV2-PRIVACY-PCM-03] the production identity policy fails active follow-up and barge input closed but ignores dormant events', async () => {
    const diagnostics = [];
    const turnIds = ['follow-up-owner', 'barge-owner'];
    const inputTransport = new BrowserVoiceRealtimeInputTransportV2({
        send: () => true,
        encodeBase64: () => 'activated-pcm',
    });
    const controller = new BrowserVoiceControllerV2({ createTurnId: () => turnIds.shift() });
    let protocolGeneration = 70;
    let voiceActive = false;
    let teardownCount = 0;
    const reporter = new VoiceClientFailureReporter({
        send: async (diagnostic) => {
            diagnostics.push(diagnostic);
            return { recorded: true };
        },
        eventTarget: null,
        scopeId: 'provider-identity-active-contexts',
        retryDelaysMs: [],
    });
    const reportFailure = (error) => reporter.enqueue({
        failure_id: voiceClientFailureId(
            'connection',
            `generation-${protocolGeneration}`,
            'missing-provider-item-id',
        ),
        stage: 'connection',
        code: error?.code,
        message: error?.message,
        cause_chain: [],
        turn_id: controller.snapshot().activeTurn?.id || '',
    });
    const teardown = (_message, options) => {
        assert.equal(options.reportFailure, false);
        if (!voiceActive) return;
        voiceActive = false;
        teardownCount += 1;
        controller.dispatch({ type: 'connection_lost', source: 'webrtc' });
        inputTransport.deactivate();
        controller.disable('provider_protocol_failure');
    };
    const connectCapture = (generation) => {
        protocolGeneration = generation;
        voiceActive = true;
        controller.start();
        controller.providerReady({ source: 'webrtc' });
        activateBrowserVoiceV2LocalWakeTransport({ controller, inputTransport, generation });
        controller.activationReady({ source: 'local_wake_gate' });
    };
    const submitCurrentTurn = (text) => {
        controller.transcriptFinal(text, { source: 'provider_transcript' });
        controller.speechEnded({ source: 'provider_vad', observedSilenceMs: 2_000 });
        const snapshot = controller.snapshot();
        controller.dispatch({
            type: 'timer_fired',
            timerKey: BROWSER_VOICE_TIMER_KEYS.ENDPOINT,
            turnId: snapshot.activeTurn.id,
            source: `timer:endpoint:${protocolGeneration}`,
            atMs: snapshot.deadlines.endpointAt,
        });
    };
    const policyForIdless = (eventType) => browserVoiceV2ProviderProtocolFailureForEvent({
        eventType,
        providerInputActive: browserVoiceV2ProviderInputIsActive({ inputTransport }),
        providerItemId: '',
    });
    const failClosedTwice = (code) => {
        for (let attempt = 0; attempt < 2; attempt += 1) {
            applyBrowserVoiceV2ProviderProtocolFailure({ code, reportFailure, teardown });
        }
    };

    connectCapture(70);
    submitCurrentTurn('Read my tasks.');
    assert.equal(controller.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP);
    assert.equal(browserVoiceV2ProviderInputIsActive({ inputTransport }), true);
    const followUpFailure = policyForIdless('conversation.item.input_audio_transcription.delta');
    assert.equal(followUpFailure, BROWSER_VOICE_PROVIDER_PROTOCOL_FAILURES.MISSING_ITEM_ID);
    failClosedTwice(followUpFailure);
    assert.equal(controller.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.OFF);
    assert.equal(browserVoiceV2ProviderInputIsActive({ inputTransport }), false);

    connectCapture(71);
    submitCurrentTurn('Read my calendar.');
    controller.playbackStarted({ turnId: 'barge-owner' });
    assert.equal(controller.snapshot().speechActive, true);
    assert.equal(browserVoiceV2ProviderInputIsActive({ inputTransport }), true);
    const bargeFailure = policyForIdless('input_audio_buffer.speech_started');
    assert.equal(bargeFailure, BROWSER_VOICE_PROVIDER_PROTOCOL_FAILURES.MISSING_ITEM_ID);
    failClosedTwice(bargeFailure);
    assert.equal(controller.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.OFF);
    assert.equal(browserVoiceV2ProviderInputIsActive({ inputTransport }), false);

    protocolGeneration = 72;
    voiceActive = true;
    controller.start();
    controller.providerReady({ source: 'webrtc' });
    assert.equal(controller.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY);
    assert.equal(browserVoiceV2ProviderInputIsActive({ inputTransport }), false);
    assert.equal(policyForIdless('conversation.item.input_audio_transcription.completed'), null);
    assert.equal(teardownCount, 2, 'dormant id-less activity does not create another teardown');

    await reporter.flush();
    assert.equal(diagnostics.length, 2);
    assert.deepEqual(diagnostics.map((item) => item.turn_id), ['follow-up-owner', 'barge-owner']);
    reporter.dispose();
});

test('[BV2-BARGE-04][BV2-PRIVACY-PCM-03][BV2-DIAGNOSTIC-03] natural-close barge proof keeps input alive only until exact rejection or expiry', () => {
    const prepare = ({ suffix, generation }) => {
        let now = 0;
        let timerId = 0;
        const scheduled = new Map();
        const providerItems = new BrowserVoiceProviderItemRegistryV2();
        const inputTransport = new BrowserVoiceRealtimeInputTransportV2({
            send: () => true,
            encodeBase64: () => 'activated-pcm',
        });
        const localWakeGate = {
            resetCount: 0,
            resetAfterTurn() {
                this.resetCount += 1;
                return true;
            },
        };
        let previousConversationState = BROWSER_VOICE_CONVERSATION_STATES.OFF;
        const controller = new BrowserVoiceControllerV2({
            clock: () => now,
            timers: {
                setTimeout: (callback, delay) => {
                    const id = ++timerId;
                    scheduled.set(id, {
                        delay,
                        run: () => {
                            if (!scheduled.has(id)) return false;
                            scheduled.delete(id);
                            callback();
                            return true;
                        },
                    });
                    return id;
                },
                clearTimeout: (id) => scheduled.delete(id),
            },
            createTurnId: () => `natural-owner-${suffix}`,
            onStateChange: (snapshot, event) => {
                const previous = previousConversationState;
                previousConversationState = snapshot.conversationState;
                applyBrowserVoiceV2WakeOnlyPrivacyBoundary({
                    snapshot,
                    previousConversationState: previous,
                    event,
                    inputTransport,
                    localWakeGate,
                });
            },
        });
        controller.start();
        controller.providerReady({ source: 'webrtc' });
        activateBrowserVoiceV2LocalWakeTransport({ controller, inputTransport, generation });
        controller.activationReady({ source: 'local_wake_gate' });
        assert.equal(inputTransport.append({
            generation,
            sourceSequence: 1,
            sampleRate: BROWSER_VOICE_LOCAL_PCM_RATE,
            samples: new Float32Array(1600).fill(0.1),
        }), true);
        controller.transcriptFinal('Thanks, goodbye.', { source: 'provider_transcript' });
        controller.speechEnded({ source: 'provider_vad', observedSilenceMs: 2_000 });
        let snapshot = controller.snapshot();
        controller.dispatch({
            type: 'timer_fired',
            timerKey: BROWSER_VOICE_TIMER_KEYS.ENDPOINT,
            turnId: snapshot.activeTurn.id,
            source: `timer:endpoint:${suffix}`,
            atMs: snapshot.deadlines.endpointAt,
        });
        controller.playbackStarted({
            turnId: `natural-owner-${suffix}`,
            naturalClosing: true,
        });
        const started = bindBrowserVoiceProviderSpeechStartedV2(controller, providerItems, {
            providerItemId: `natural-provider-${suffix}`,
            connectionGeneration: generation,
        });
        controller.potentialBargeIn('potential_speech', {
            source: 'provider_vad',
            ownerTurnId: started.binding.turnId,
            providerItemId: started.binding.providerItemId,
        });
        controller.playbackFinished({
            turnId: `natural-owner-${suffix}`,
            naturalClosing: true,
        });
        snapshot = controller.snapshot();
        assert.equal(snapshot.conversationState, BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP);
        assert.equal(snapshot.potentialBargeIn.returnToWakeOnly, true);
        assert.equal(browserVoiceV2ProviderInputIsActive({ inputTransport }), true);
        assert.equal(inputTransport.append({
            generation,
            sourceSequence: 2,
            sampleRate: BROWSER_VOICE_LOCAL_PCM_RATE,
            samples: new Float32Array(1600).fill(0.2),
        }), true, 'the owning playback finish cannot cut off an already-started utterance');
        return {
            controller,
            inputTransport,
            localWakeGate,
            providerItems,
            binding: started.binding,
            scheduled,
            setNow: (value) => { now = value; },
            generation,
        };
    };

    const rejected = prepare({ suffix: 'rejected', generation: 81 });
    const diagnostics = [];
    assert.ok(applyBrowserVoiceV2TranscriptionFailure({
        controller: rejected.controller,
        payload: { error: { code: 'transcription_failed' } },
        binding: rejected.binding,
        action: 'reject_barge_in',
        reportFailure: (_payload, providerItemId, turnId) => diagnostics.push({ providerItemId, turnId }),
    }));
    assert.equal(rejected.controller.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY);
    assert.equal(rejected.controller.snapshot().potentialBargeIn, null);
    assert.equal(rejected.controller.snapshot().deadlines.followUpAt, null);
    assert.equal(rejected.localWakeGate.resetCount, 1);
    assert.equal(browserVoiceV2ProviderInputIsActive({ inputTransport: rejected.inputTransport }), false);
    assert.equal([...rejected.scheduled.values()].some((timer) => timer.delay === 15_000), false,
        'rejection cancels the proof-only deadline instead of leaving a stale timer');
    assert.equal(applyBrowserVoiceV2TranscriptionFailure({
        controller: rejected.controller,
        payload: { error: { code: 'duplicate_transcription_failed' } },
        binding: rejected.binding,
        action: 'reject_barge_in',
        reportFailure: () => diagnostics.push({ duplicate: true }),
    }), null);
    assert.equal(diagnostics.length, 1);
    assert.equal(rejected.controller.snapshot().rejectedEventCount, 0);

    const expired = prepare({ suffix: 'expired', generation: 82 });
    const expiry = [...expired.scheduled.values()].find((timer) => timer.delay === 15_000);
    assert.ok(expiry, 'natural-close potential barge owns one bounded expiry');
    expired.setNow(15_000);
    assert.equal(expiry.run(), true);
    assert.equal(expired.controller.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY);
    assert.equal(expired.controller.snapshot().potentialBargeIn, null);
    assert.equal(expired.localWakeGate.resetCount, 1);
    assert.equal(browserVoiceV2ProviderInputIsActive({ inputTransport: expired.inputTransport }), false);
    assert.equal(currentBrowserVoiceProviderItemBindingV2(
        expired.providerItems,
        expired.controller,
        {
            providerItemId: 'natural-provider-expired',
            connectionGeneration: expired.generation,
            consume: true,
        },
    ), null);
    assert.equal(expired.controller.snapshot().rejectedEventCount, 0);
    assert.equal(browserVoiceV2ProviderProtocolFailureForEvent({
        eventType: 'conversation.item.input_audio_transcription.completed',
        providerInputActive: browserVoiceV2ProviderInputIsActive({ inputTransport: expired.inputTransport }),
        providerItemId: '',
    }), null, 'expired dormant input remains privacy-armed and cannot create a protocol teardown');
});

test('[BV2-BARGE-04][BV2-PRIVACY-PCM-03][BV2-TRANSCRIPT-03] ordinary and natural proof expiry erase the exact provisional provider item once', () => {
    for (const naturalClosing of [false, true]) {
        const suffix = naturalClosing ? 'natural' : 'ordinary';
        const generation = naturalClosing ? 92 : 91;
        const providerItemId = `expiring-${suffix}-provider-item`;
        const providerEvents = [];
        const providerItems = new BrowserVoiceProviderItemRegistryV2();
        const potentialItems = new Set();
        const providerWakeTurnIds = new Map();
        const transcripts = new RealtimeInputTranscriptBuffer();
        const cleanupEffects = [];
        let draft = '';
        let clearDraftCount = 0;
        let now = 0;
        let timerId = 0;
        const scheduled = new Map();
        const inputTransport = new BrowserVoiceRealtimeInputTransportV2({
            send: (event) => {
                providerEvents.push(event);
                return true;
            },
            encodeBase64: () => 'activated-pcm',
        });
        const localWakeGate = {
            resetCount: 0,
            resetAfterTurn() {
                this.resetCount += 1;
                return true;
            },
        };
        let previousConversationState = BROWSER_VOICE_CONVERSATION_STATES.OFF;
        const controller = new BrowserVoiceControllerV2({
            clock: () => now,
            timers: {
                setTimeout: (callback, delay) => {
                    const id = ++timerId;
                    scheduled.set(id, {
                        delay,
                        run: () => {
                            if (!scheduled.has(id)) return false;
                            scheduled.delete(id);
                            callback();
                            return true;
                        },
                    });
                    return id;
                },
                clearTimeout: (id) => scheduled.delete(id),
            },
            createTurnId: () => `expiring-${suffix}-owner`,
            onEffect: (effect) => {
                if (effect.type !== BROWSER_VOICE_EFFECTS.DISCARD_POTENTIAL_BARGE_IN) return;
                cleanupEffects.push(effect);
                applyBrowserVoiceV2PotentialBargeProofCleanup({
                    effect,
                    registry: providerItems,
                    transcripts,
                    potentialItems,
                    providerWakeTurnIds,
                    connectionGeneration: generation,
                    clearDraft: (text) => {
                        draft = text;
                        clearDraftCount += 1;
                    },
                    sendProviderEvent: (event) => {
                        providerEvents.push(event);
                        return true;
                    },
                });
            },
            onStateChange: (snapshot, event) => {
                const previous = previousConversationState;
                previousConversationState = snapshot.conversationState;
                applyBrowserVoiceV2WakeOnlyPrivacyBoundary({
                    snapshot,
                    previousConversationState: previous,
                    event,
                    inputTransport,
                    localWakeGate,
                });
            },
        });

        controller.start();
        controller.providerReady({ source: 'webrtc' });
        activateBrowserVoiceV2LocalWakeTransport({ controller, inputTransport, generation });
        controller.activationReady({ source: 'local_wake_gate' });
        controller.transcriptFinal('Read my tasks.', { source: 'provider_transcript' });
        controller.speechEnded({ source: 'provider_vad', observedSilenceMs: 2_000 });
        const endpoint = [...scheduled.values()].find((timer) => timer.delay === 0);
        assert.ok(endpoint);
        assert.equal(endpoint.run(), true);
        controller.drainEffects();
        controller.playbackStarted({
            turnId: `expiring-${suffix}-owner`,
            naturalClosing,
        });
        const started = bindBrowserVoiceProviderSpeechStartedV2(controller, providerItems, {
            providerItemId,
            connectionGeneration: generation,
        });
        assert.ok(started.binding);
        potentialItems.add(providerItemId);
        controller.potentialBargeIn('potential_speech', {
            source: 'provider_vad',
            ownerTurnId: started.binding.turnId,
            providerItemId,
        });
        transcripts.append({ itemId: providerItemId, contentIndex: 0, delta: 'visible provisional ' });
        transcripts.append({ itemId: providerItemId, contentIndex: 2, delta: 'provider residue' });
        draft = 'visible provisional provider residue';
        controller.playbackFinished({
            turnId: `expiring-${suffix}-owner`,
            naturalClosing,
        });
        const expiry = [...scheduled.values()].find((timer) => timer.delay === 15_000);
        assert.ok(expiry);
        now = 15_000;
        assert.equal(expiry.run(), true);

        assert.equal(controller.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY);
        assert.equal(controller.snapshot().potentialBargeIn, null);
        assert.equal(draft, '');
        assert.equal(clearDraftCount, 1);
        assert.equal(potentialItems.has(providerItemId), false);
        assert.equal(providerWakeTurnIds.has(providerItemId), false);
        assert.equal(transcripts.complete({ itemId: providerItemId, contentIndex: 0 }), '');
        assert.equal(transcripts.complete({ itemId: providerItemId, contentIndex: 2 }), '');
        assert.equal(inputTransport.activeGeneration, null);
        assert.equal(localWakeGate.resetCount, 1);
        assert.equal(currentBrowserVoiceProviderItemBindingV2(providerItems, controller, {
            providerItemId,
            connectionGeneration: generation,
            consume: true,
        }), null, 'the expiry effect seals the item before a late terminal event can claim it');
        assert.deepEqual(providerEvents.filter((event) => event.type === 'conversation.item.delete'), [{
            type: 'conversation.item.delete',
            item_id: providerItemId,
        }]);
        assert.equal(cleanupEffects.length, 1);
        assert.equal(applyBrowserVoiceV2PotentialBargeProofCleanup({
            effect: cleanupEffects[0],
            registry: providerItems,
            transcripts,
            potentialItems,
            providerWakeTurnIds,
            connectionGeneration: generation,
            clearDraft: () => { clearDraftCount += 1; },
            sendProviderEvent: (event) => {
                providerEvents.push(event);
                return true;
            },
        }), false, 'a duplicate/late cleanup effect is an exact no-op');
        assert.equal(clearDraftCount, 1);
        assert.equal(providerEvents.filter((event) => event.type === 'conversation.item.delete').length, 1);
        assert.equal(controller.drainEffects().some((effect) => effect.type === BROWSER_VOICE_EFFECTS.TURN_READY), false);
    }
});

test('[BV2-TRANSCRIPT-03][BV2-PRIVACY-PCM-03][BV2-DIAGNOSTIC-03] provider item capacity never evicts and fails the active capture closed exactly once', async () => {
    const diagnostics = [];
    const registry = new BrowserVoiceProviderItemRegistryV2({ limit: 2 });
    const inputTransport = new BrowserVoiceRealtimeInputTransportV2({
        send: () => true,
        encodeBase64: () => 'activated-pcm',
    });
    const controller = new BrowserVoiceControllerV2({ createTurnId: () => 'capacity-turn' });
    controller.start();
    controller.providerReady({ source: 'webrtc' });
    activateBrowserVoiceV2LocalWakeTransport({ controller, inputTransport, generation: 61 });
    controller.activationReady({ source: 'local_wake_gate' });

    for (const providerItemId of ['sealed-oldest', 'sealed-newest']) {
        const started = bindBrowserVoiceProviderSpeechStartedV2(controller, registry, {
            providerItemId,
            connectionGeneration: 61,
        });
        assert.equal(started.binding.turnId, 'capacity-turn');
        assert.equal(currentBrowserVoiceProviderItemBindingV2(registry, controller, {
            providerItemId,
            connectionGeneration: 61,
            consume: true,
        })?.turnId, 'capacity-turn');
    }
    const exhausted = bindBrowserVoiceProviderSpeechStartedV2(controller, registry, {
        providerItemId: 'must-not-evict',
        connectionGeneration: 61,
    });
    assert.equal(exhausted.result, BROWSER_VOICE_REALTIME_INGRESS_RESULTS.CAPACITY_EXHAUSTED);
    assert.equal(registry.size, 2);
    assert.equal(registry.has({ providerItemId: 'sealed-oldest', connectionGeneration: 61 }), true);

    let active = true;
    let teardownCount = 0;
    const reporter = new VoiceClientFailureReporter({
        send: async (diagnostic) => {
            diagnostics.push(diagnostic);
            return { recorded: true };
        },
        eventTarget: null,
        scopeId: 'provider-protocol-capacity',
        retryDelaysMs: [],
    });
    const reportFailure = (error) => reporter.enqueue({
        failure_id: voiceClientFailureId('connection', 'generation-61', 'provider-item-capacity'),
        stage: 'connection',
        code: error?.code,
        message: error?.message,
        cause_chain: [],
        turn_id: 'capacity-turn',
    });
    const teardown = (_message, options) => {
        assert.equal(options.reportFailure, false);
        if (!active) return;
        active = false;
        teardownCount += 1;
        inputTransport.deactivate();
        registry.clear();
        controller.disable('provider_protocol_failure');
    };
    const protocolCode = browserVoiceV2ProviderProtocolFailureForEvent({
        eventType: 'input_audio_buffer.speech_started',
        providerInputActive: browserVoiceV2ProviderInputIsActive({ inputTransport }),
        providerItemId: 'must-not-evict',
        providerItemCapacityExhausted: registry.capacityExhausted,
    });
    assert.equal(protocolCode, BROWSER_VOICE_PROVIDER_PROTOCOL_FAILURES.ITEM_CAPACITY_EXHAUSTED);
    for (let attempt = 0; attempt < 2; attempt += 1) {
        applyBrowserVoiceV2ProviderProtocolFailure({
            code: protocolCode,
            reportFailure,
            teardown,
        });
    }
    await reporter.flush();

    assert.equal(teardownCount, 1);
    assert.equal(diagnostics.length, 1);
    assert.equal(controller.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.OFF);
    assert.equal(inputTransport.append({
        generation: 61,
        sourceSequence: 1,
        sampleRate: BROWSER_VOICE_LOCAL_PCM_RATE,
        samples: new Float32Array(1600).fill(0.1),
    }), false);
    assert.equal(registry.size, 0, 'the ended connection may clear its sealed identity scope');
    reporter.dispose();

    assert.match(source, /providerItemCapacityExhausted:[\s\S]*browserVoiceV2ProviderItems\.capacityExhausted/);
});

test('[BV2-DIAGNOSTIC-03][BV2-PRIVACY-PCM-03] provider error reports once and uses the connection-wide fail-closed teardown', async () => {
    const providerEvents = [];
    const diagnostics = [];
    const inputTransport = new BrowserVoiceRealtimeInputTransportV2({
        send: (event) => {
            providerEvents.push(event);
            return true;
        },
        encodeBase64: () => 'activated-pcm',
    });
    const controller = new BrowserVoiceControllerV2({ createTurnId: () => 'provider-failure-turn' });
    controller.start();
    controller.providerReady({ source: 'webrtc' });
    activateBrowserVoiceV2LocalWakeTransport({ controller, inputTransport, generation: 19 });
    controller.activationReady({ source: 'local_wake_gate' });
    assert.equal(inputTransport.append({
        generation: 19,
        sourceSequence: 4,
        sampleRate: BROWSER_VOICE_LOCAL_PCM_RATE,
        samples: new Float32Array(1600).fill(0.1),
    }), true);

    const rawTrack = { stopped: false, stop() { this.stopped = true; } };
    const localWakeGate = { open: true, stopped: false, stop() { this.open = false; this.stopped = true; } };
    let voiceActive = true;
    let teardownCount = 0;
    let terminalMessage = '';
    const reporter = new VoiceClientFailureReporter({
        send: async (diagnostic) => {
            diagnostics.push(diagnostic);
            return { recorded: true };
        },
        eventTarget: null,
        scopeId: 'test-user-connection',
        retryDelaysMs: [],
    });
    const reportFailure = (error, message) => reporter.enqueue({
        failure_id: voiceClientFailureId('connection', 'generation-19'),
        stage: 'connection',
        code: error?.code,
        message,
        cause_chain: [],
        turn_id: 'provider-failure-turn',
    });
    const teardown = (message, options) => {
        assert.equal(options.reportFailure, false, 'the teardown may not queue a second diagnostic');
        if (!voiceActive) return;
        voiceActive = false;
        teardownCount += 1;
        terminalMessage = message;
        inputTransport.deactivate();
        localWakeGate.stop();
        rawTrack.stop();
        controller.disable('connection_lost');
    };

    teardownBrowserVoiceV2ProviderFailure({
        error: { code: 'provider exploded' },
        providerMessage: 'api_key=secret transcript=private pcm=AAAA',
        reportFailure,
        teardown,
    });
    await reporter.flush();

    assert.equal(teardownCount, 1);
    assert.equal(voiceActive, false);
    assert.equal(localWakeGate.open, false);
    assert.equal(localWakeGate.stopped, true);
    assert.equal(rawTrack.stopped, true);
    assert.equal(controller.snapshot().conversationState, BROWSER_VOICE_CONVERSATION_STATES.OFF);
    assert.match(terminalMessage, /Tap the Bean button to reconnect/);
    assert.equal(inputTransport.append({
        generation: 19,
        sourceSequence: 5,
        sampleRate: BROWSER_VOICE_LOCAL_PCM_RATE,
        samples: new Float32Array(1600).fill(0.2),
    }), false);
    assert.deepEqual(providerEvents.map((event) => event.type), [
        'input_audio_buffer.clear',
        'input_audio_buffer.append',
    ]);
    assert.equal(diagnostics.length, 1);
    assert.equal(diagnostics[0].failure_id, voiceClientFailureId('connection', 'generation-19'));
    assert.equal(diagnostics[0].code, 'voice_connection_failure');
    assert.doesNotMatch(JSON.stringify(diagnostics[0]), /secret|private|AAAA|exploded/);
    reporter.dispose();
});

test('[BV2-WAKE-OWNER-01] Realtime transcript text cannot become a second wake owner', () => {
    const detectedStart = source.indexOf('function handleLocalWakeDetected(');
    const detectedEnd = source.indexOf('\n    function handleLocalWakeFailure(', detectedStart);
    const detected = source.slice(detectedStart, detectedEnd);
    assert.match(detected, /activateBrowserVoiceV2LocalWakeTransport/);

    const bridgeStart = source.indexOf('export function activateBrowserVoiceV2LocalWakeTransport(');
    const bridgeEnd = source.indexOf('\nexport function applyBrowserVoiceV2WakeOnlyPrivacyBoundary(', bridgeStart);
    const bridge = source.slice(bridgeStart, bridgeEnd);
    assert.match(bridge, /return controller\.wakeConfirmed\(\{ source \}\)/);

    const ingressStart = source.indexOf('function handleBrowserVoiceV2RealtimeEvent(');
    const ingressEnd = source.indexOf('\n    async function reportBrowserVoiceV2RealtimeUsage(', ingressStart);
    const ingress = source.slice(ingressStart, ingressEnd);
    assert.match(ingress, /const wakeConfirmed = browserVoiceV2ProviderWakeTurnIds\.has\(transcriptId\)/);
    assert.doesNotMatch(ingress, /wakeConfirmed\(|provider_strict_wake|activateBrowserVoiceV2ProviderWake/);
    assert.doesNotMatch(source, /function activateBrowserVoiceV2ProviderWake/);
});

test('[BV2-PRIVACY-01] startup microphone activity is not presented as accepted listening', () => {
    const start = source.indexOf('function updateRealtimeVoiceActivity(');
    const end = source.indexOf('\n    function clearRealtimeVoiceInputFeedback(', start);
    assert.ok(start >= 0 && end > start, 'voice activity presentation must remain discoverable');
    const implementation = source.slice(start, end);

    assert.match(implementation, /realtimeLocalWakeGate\s*&& state\.voiceWakeListening\s*&& realtimeVoiceActive/);
    assert.doesNotMatch(implementation, /microphoneAvailable[\s\S]*state\.voiceProcessing/);
});

test('[BV2-BARGE-04] failed interruption transcription restores playback instead of admitting unknown speech', () => {
    const deltaStart = source.indexOf("if (type === 'conversation.item.input_audio_transcription.delta')");
    const completedStart = source.indexOf("if (type === 'conversation.item.input_audio_transcription.completed')", deltaStart);
    const start = source.indexOf("if (type === 'conversation.item.input_audio_transcription.failed')");
    const end = source.indexOf("if (type === 'response.function_call_arguments.delta'", start);
    assert.ok(deltaStart >= 0 && completedStart > deltaStart && start > completedStart && end > start);
    const delta = source.slice(deltaStart, completedStart);
    const completed = source.slice(completedStart, start);
    const implementation = source.slice(start, end);
    const claimCandidate = implementation.indexOf('const potentialBargeIn = browserVoiceV2PotentialBargeInItems.delete(transcriptId)');
    const reject = implementation.indexOf("action: 'reject_barge_in'");
    const normalCaptureBranch = implementation.indexOf('const followUpCandidate = browserVoiceV2Controller.snapshot().followUpCandidate');
    const captureFailure = implementation.indexOf('applyBrowserVoiceV2TranscriptionFailure({', normalCaptureBranch);

    assert.doesNotMatch(delta, /confirmBargeIn/);
    assert.match(delta, /browserVoiceV2PotentialBargeInItems\.has\(transcriptId\)/);
    assert.match(delta, /updateVoiceWakeDraft\(commandDraft\)/);
    assert.match(completed, /browserVoiceV2Controller\.confirmBargeIn/);
    assert.match(completed, /realtimeInputTranscripts\.discardItem\(\{ itemId: transcriptId \}\)/);
    assert.doesNotMatch(completed, /realtimeInputTranscripts\.discard\(/);
    assert.ok(claimCandidate >= 0, 'the handler must claim the potential interruption exactly once');
    assert.ok(reject > claimCandidate, 'an unconfirmed interruption must restore the current playback');
    assert.ok(normalCaptureBranch > reject && captureFailure > normalCaptureBranch,
        'ordinary capture failure handling must remain outside the interruption branch');
});

test('[BV2-TRANSCRIPT-03] every sealed or terminal provider item clears all buffered content indices', () => {
    const effectsStart = source.indexOf('function handleBrowserVoiceV2ControllerEffect(effect)');
    const activateCapture = source.indexOf('if (effect.type === BROWSER_VOICE_EFFECTS.ACTIVATE_CAPTURE)', effectsStart);
    const effects = source.slice(effectsStart, activateCapture);
    const deltaStart = source.indexOf("if (type === 'conversation.item.input_audio_transcription.delta')");
    const completedStart = source.indexOf("if (type === 'conversation.item.input_audio_transcription.completed')", deltaStart);
    const failedStart = source.indexOf("if (type === 'conversation.item.input_audio_transcription.failed')", completedStart);
    const terminalEnd = source.indexOf("if (type === 'response.function_call_arguments.delta'", failedStart);
    const delta = source.slice(deltaStart, completedStart);
    const completed = source.slice(completedStart, failedStart);
    const failed = source.slice(failedStart, terminalEnd);

    assert.match(effects, /DISCARD_FOLLOW_UP_CANDIDATE[\s\S]*realtimeInputTranscripts\.discardItem\(\{ itemId: effect\.providerItemId \}\)/);
    assert.match(delta, /if \(!binding\)[\s\S]*realtimeInputTranscripts\.discardItem\(\{ itemId: transcriptId \}\)/);
    assert.match(completed, /if \(!binding\)[\s\S]*realtimeInputTranscripts\.discardItem\(\{ itemId: transcriptId \}\)/);
    assert.ok(
        completed.indexOf('realtimeInputTranscripts.complete({')
            < completed.lastIndexOf('realtimeInputTranscripts.discardItem({ itemId: transcriptId })'),
        'a valid terminal completion reads its selected transcript before erasing every remaining index',
    );
    assert.match(failed, /realtimeInputTranscripts\.discardItem\(\{ itemId: transcriptId \}\)/);
    assert.doesNotMatch(source, /realtimeInputTranscripts\.discard\(/);
});

test('[BV2-BARGE-06] only the exact completed active-conversation PCM can absorb a delayed local callback', () => {
    const snapshot = {
        generation: 4,
        connectionGeneration: 9,
        activeTurn: { id: 'stable-barge-turn' },
    };
    const completedBarge = {
        turnId: 'stable-barge-turn',
        controllerGeneration: 4,
        connectionGeneration: 9,
        gateGeneration: 3,
        throughSourceSequence: 412,
    };

    assert.equal(browserVoiceV2LocalWakeMatchesCompletedBarge({
        snapshot,
        completedBarge,
        connectionGeneration: 9,
        gateGeneration: 3,
        sourceSequence: 412,
    }), true);
    assert.equal(browserVoiceV2LocalWakeMatchesCompletedBarge({
        snapshot,
        completedBarge,
        connectionGeneration: 9,
        gateGeneration: 3,
        sourceSequence: 413,
    }), false, 'a later utterance must remain a new local wake');
    assert.equal(browserVoiceV2LocalWakeMatchesCompletedBarge({
        snapshot: { ...snapshot, activeTurn: { id: 'different-turn' } },
        completedBarge,
        connectionGeneration: 9,
        gateGeneration: 3,
        sourceSequence: 412,
    }), false);
    assert.equal(browserVoiceV2LocalWakeMatchesCompletedBarge({
        snapshot,
        completedBarge,
        connectionGeneration: 9,
        gateGeneration: 3,
    }), false, 'missing PCM identity must fail open to the local wake owner');

    const detectedStart = source.indexOf('function handleLocalWakeDetected(');
    const detectedEnd = source.indexOf('\n    function handleLocalWakeFailure(', detectedStart);
    const detected = source.slice(detectedStart, detectedEnd);
    assert.match(detected, /browserVoiceV2LocalWakeMatchesCompletedBarge/);
    assert.ok(
        detected.indexOf('browserVoiceV2LocalWakeMatchesCompletedBarge')
            < detected.indexOf('activateBrowserVoiceV2LocalWakeTransport'),
        'exact completed PCM must be deduplicated before local wake admission',
    );

    const ingressStart = source.indexOf('function handleBrowserVoiceV2RealtimeEvent(');
    const ingressEnd = source.indexOf('\n    async function reportBrowserVoiceV2RealtimeUsage(', ingressStart);
    const ingress = source.slice(ingressStart, ingressEnd);
    assert.doesNotMatch(ingress, /isStrictRealtimeWakePhrase|const strictWake/);
});

test('[BV2-USAGE-02] realtime usage is reported once and a plan limit closes voice with an upgrade path', () => {
    const start = source.indexOf('async function reportBrowserVoiceV2RealtimeUsage(');
    const end = source.indexOf('\n    function handleRealtimeEvent(', start);
    assert.ok(start >= 0 && end > start, 'the realtime usage reporter must remain discoverable');
    const implementation = source.slice(start, end);

    assert.match(implementation, /browserVoiceV2RealtimeUsageEventIds\.has\(eventId\)/);
    assert.match(implementation, /\/assistant\/voice\/realtime\/usage/);
    assert.match(implementation, /reportVoiceEventReliably\(report/);
    assert.match(implementation, /timeoutMs:\s*10000/);
    assert.match(implementation, /shouldRetry: \(error\) => Number\(error\?\.status \|\| 0\) !== 402/);
    assert.match(implementation, /reportBrowserVoiceV2ClientFailure\(error, \{[\s\S]*stage: 'usage_accounting'/);
    assert.match(implementation, /identity: \[usageSessionId, eventId\]/);
    assert.match(implementation, /reason: Number\(error\?\.status \|\| 0\) === 402 \? 'usage_limit'/);
    assert.match(implementation, /state\.chatRunState = [\s\S]*'Upgrade to continue'/);

    const markupStart = source.indexOf('function errorMarkup(');
    const markupEnd = source.indexOf('\n    function isPlanLimitMessage(', markupStart);
    const markup = source.slice(markupStart, markupEnd);
    assert.match(markup, /Upgrade to keep going/);
    assert.match(markup, /href="\/pricing">View plans<\/a>/);
});

test('[BV2-BARGE-05] repeated Bean wording remains a meaningful interruption for Hermes', () => {
    assert.equal(isMeaningfulBrowserVoiceV2Interruption('Delete the first one.'), true);
    assert.equal(isMeaningfulBrowserVoiceV2Interruption('  '), false);
    assert.doesNotMatch(source, /isBrowserVoiceV2PlaybackEcho|spoken\.includes\(candidate\)|overlap\s*>=/);
});

test('[BV2-DIAGNOSTIC-02] a post-readiness local wake failure records its sanitized cause before teardown', () => {
    const start = source.indexOf('function handleLocalWakeFailure(');
    const end = source.indexOf('\n    async function waitForLocalWakeReady(', start);
    assert.ok(start >= 0 && end > start, 'the local wake failure boundary must remain discoverable');
    const implementation = source.slice(start, end);

    assert.match(implementation, /reportBrowserVoiceV2ClientFailure\(error, \{/);
    assert.match(implementation, /stage: 'local_wake'/);
    assert.match(implementation, /identity: \[connectionGeneration, gate\.currentGeneration\(\)\]/);
    assert.match(implementation, /handleRealtimeConnectionLoss/);
    assert.match(source, /onError: \(error\) => handleLocalWakeFailure\(localWakeGate, connectionGeneration, error\)/);
});

test('[BV2-DIAGNOSTIC-03] every browser client failure uses one bounded reliable idempotent reporter', () => {
    assert.equal(
        (source.match(/\/assistant\/voice\/client-failures/g) || []).length,
        1,
        'one transport owns all browser client-failure delivery',
    );
    assert.match(source, /const browserVoiceV2ClientFailureReporter = new VoiceClientFailureReporter\(\{/);
    assert.match(source, /send: \(failure\) => api\('\/assistant\/voice\/client-failures'/);
    assert.match(source, /storage: globalThis\.localStorage/);
    assert.match(source, /const browserVoiceV2ClientFailurePageNonce = createVoiceClientFailureNonce\(\)/);
    assert.match(source, /onPersistenceFailure: \(diagnostic\) => \{\s+console\.error\(diagnostic\.message, \{ code: diagnostic\.code \}\)/);
    assert.match(source, /function scopeBrowserVoiceV2ClientFailures\(user = state\.user\)/);
    assert.match(source, /browserVoiceV2ClientFailureReporter\.setScope\(userId\)/);

    const loadSignedInStart = source.indexOf('async function loadSignedIn(options = {})');
    const loadSignedInEnd = source.indexOf('\n    function mergeUser(', loadSignedInStart);
    assert.ok(loadSignedInStart >= 0 && loadSignedInEnd > loadSignedInStart, 'signed-in initialization must remain discoverable');
    assert.match(source.slice(loadSignedInStart, loadSignedInEnd), /state\.user = user;\s+scopeBrowserVoiceV2ClientFailures\(user\)/);

    const clearTokenStart = source.indexOf('function clearToken({ discardVoiceDiagnostics = false } = {})');
    const clearTokenEnd = source.indexOf('\n    function isUnauthenticatedError(', clearTokenStart);
    assert.ok(clearTokenStart >= 0 && clearTokenEnd > clearTokenStart, 'logout cleanup must remain discoverable');
    const clearToken = source.slice(clearTokenStart, clearTokenEnd);
    assert.match(clearToken, /browserVoiceV2ClientFailureReporter\.deactivateCurrentScope\(\)/);
    assert.match(clearToken, /discardVoiceDiagnostics[\s\S]*browserVoiceV2ClientFailureReporter\.clearCurrentScope\(\)/);
    assert.match(source, /clearToken\(\{ discardVoiceDiagnostics: true \}\)/);

    const start = source.indexOf('function reportBrowserVoiceV2ClientFailure(');
    const end = source.indexOf('\n    function failBrowserVoiceV2Admission(', start);
    assert.ok(start >= 0 && end > start, 'the shared client-failure boundary must remain discoverable');
    const implementation = source.slice(start, end);
    assert.match(implementation, /failure_id: voiceClientFailureId\(stage, voiceClientFailureIdentityParts\(/);
    assert.match(implementation, /browserVoiceV2ClientFailurePageNonce/);
    assert.match(implementation, /sanitizedVoiceClientFailure\(error, stage\)/);
    assert.match(implementation, /realtimeTurnSessionIds\.get\(stableTurnId\)/);
    assert.match(implementation, /browserVoiceV2ClientFailureReporter\.enqueue\(body\)/);
    assert.doesNotMatch(implementation, /dispatch\(|admissionFailed|captureFailed|stopVoiceWakeListening/);
});

test('[BV2-FIRST-WAKE-01:D] sanitized local candidate decisions remain observable without transmitting dormant content', () => {
    const start = source.indexOf('function handleLocalWakeDiagnostic(');
    const end = source.indexOf('\n    function handleLocalWakeFailure(', start);
    assert.ok(start >= 0 && end > start, 'the local wake diagnostic boundary must remain discoverable');
    const implementation = source.slice(start, end);

    assert.match(implementation, /localWakeConnectionIsCurrent/);
    assert.match(implementation, /console\.info\('Browser Voice v2 local wake decision', diagnostic\)/);
    assert.doesNotMatch(implementation, /api\(|client-failures/);
    assert.match(source, /onDiagnostic: \(diagnostic\) => handleLocalWakeDiagnostic\(/);
});

test('[BV2-DIAGNOSTIC-03] provider transcription failure posts sanitized telemetry with the stable voice identity', () => {
    const reporterStart = source.indexOf('function reportBrowserVoiceV2TranscriptionFailure(');
    const reporterEnd = source.indexOf('\n    function handleBrowserVoiceV2RealtimeEvent(', reporterStart);
    assert.ok(reporterStart >= 0 && reporterEnd > reporterStart, 'the transcription reporter must remain discoverable');
    const reporter = source.slice(reporterStart, reporterEnd);
    assert.match(reporter, /reportBrowserVoiceV2ClientFailure\(payload\?\.error, \{/);
    assert.match(reporter, /stage: 'transcription'/);
    assert.match(reporter, /identity: \[transcriptId \|\| payload\?\.event_id \|\| turnId \|\| realtimeConnectionGeneration\]/);
    assert.match(reporter, /turnId,/);
    assert.doesNotMatch(reporter, /payload[^?]*\.transcript|payload[^?]*\.audio|payload[^?]*\.pcm/);

    const handlerStart = source.indexOf("if (type === 'conversation.item.input_audio_transcription.failed')");
    const handlerEnd = source.indexOf("\n        if (type === 'response.function_call_arguments.delta'", handlerStart);
    const handler = source.slice(handlerStart, handlerEnd);
    assert.match(handler, /currentBrowserVoiceProviderItemBindingV2\(/);
    assert.match(handler, /consume: true/);
    assert.match(handler, /realtimeInputTranscripts\.discardItem\(\{ itemId: transcriptId \}\)/);
    assert.doesNotMatch(handler, /realtimeInputTranscripts\.discard\(/);
    assert.match(handler, /if \(!binding\) return true/);
    assert.match(handler, /applyBrowserVoiceV2TranscriptionFailure\(\{/);
    assert.match(handler, /binding,/);
    assert.match(handler, /reportFailure: reportBrowserVoiceV2TranscriptionFailure/);
    assert.ok(
        handler.indexOf('currentBrowserVoiceProviderItemBindingV2(')
            < handler.indexOf('if (!binding) return true'),
        'an exact current item binding must be consumed before any failure can affect lifecycle',
    );
});

test('[BV2-DIAGNOSTIC-03] a post-readiness provider connection failure is reported once per generation', () => {
    const reporterStart = source.indexOf('function reportBrowserVoiceV2ConnectionFailure(');
    const reporterEnd = source.indexOf('\n    function handleRealtimeConnectionLoss(', reporterStart);
    const reporter = source.slice(reporterStart, reporterEnd);
    assert.match(reporter, /reportBrowserVoiceV2ClientFailure\(/);
    assert.match(reporter, /stage: 'connection'/);
    assert.match(reporter, /identity: \[realtimeConnectionGeneration\]/);
    assert.match(reporter, /turnId,/);

    const lossStart = source.indexOf('function handleRealtimeConnectionLoss(');
    const lossEnd = source.indexOf('\n    function bindBrowserVoiceV2ProviderItemToLocalWake(', lossStart);
    const loss = source.slice(lossStart, lossEnd);
    assert.match(loss, /reportBrowserVoiceV2ConnectionFailure\(error, message\)/);
    assert.match(loss, /stopVoiceWakeListening/);

    const errorStart = source.indexOf("if (type === 'error') {");
    const errorEnd = source.indexOf('\n        return false;', errorStart);
    const providerError = source.slice(errorStart, errorEnd);
    assert.match(providerError, /return teardownBrowserVoiceV2ProviderFailure\(\{/);
    assert.match(providerError, /reportFailure: reportBrowserVoiceV2ConnectionFailure/);
    assert.match(providerError, /teardown: handleRealtimeConnectionLoss/);
});

test('[BV2-DIAGNOSTIC-03] exhausted durable admission reports a sanitized diagnostic and retains a recovery envelope', () => {
    const start = source.indexOf('function failBrowserVoiceV2Admission(');
    const end = source.indexOf('\n    async function admitBrowserVoiceV2Turn(', start);
    assert.ok(start >= 0 && end > start, 'the admission failure boundary must remain discoverable');
    const failure = source.slice(start, end);
    assert.match(failure, /reportBrowserVoiceV2ClientFailure\(error, \{/);
    assert.match(failure, /stage: 'admission'/);
    assert.match(failure, /identity: \[turnId\]/);

    const admissionStart = source.indexOf('async function admitBrowserVoiceV2Turn(');
    const admissionEnd = source.indexOf('\n    function applyBrowserVoiceV2Snapshot(', admissionStart);
    const admission = source.slice(admissionStart, admissionEnd);
    assert.match(admission, /timeoutMs:\s*8500/);
    assert.match(admission, /recovery\.error \|\| error/);
});

test('[BV2-DIAGNOSTIC-04] exhausted clarification transport preserves the draft and reports durable admin telemetry', () => {
    const start = source.indexOf('async function clarifyBrowserVoiceV2Turn(');
    const end = source.indexOf('\n    function applyBrowserVoiceV2Snapshot(', start);
    assert.ok(start >= 0 && end > start, 'the clarification recovery boundary must remain discoverable');
    const implementation = source.slice(start, end);

    assert.match(implementation, /for \(let attempt = 0; attempt < 2; attempt \+= 1\)/);
    assert.match(implementation, /snapshot\.turns\.find/);
    assert.match(implementation, /resolvedClarificationIds\?\.includes\(clarificationId\)/);
    assert.doesNotMatch(implementation, /transcript\.endsWith/);
    assert.match(implementation, /Your words are still in the input/);
    assert.match(implementation, /reportBrowserVoiceV2ClientFailure\(lastError, \{/);
    assert.match(implementation, /stage: 'clarification'/);
    assert.match(implementation, /identity: \[clarificationId\]/);
    assert.match(implementation, /sessionId,/);
    assert.match(implementation, /turnId,/);
});

test('[BV2-STARTUP-03] startup uses one same-origin SDP owner and fails closed with a natural retry prompt', () => {
    const openStart = source.indexOf('async function openRealtimeSession(');
    const openEnd = source.indexOf('\n    function clearRealtimeDisconnectedTimer(', openStart);
    const open = source.slice(openStart, openEnd);
    assert.match(open, /openRealtimeSession\(sdp\)/);
    assert.match(open, /\/assistant\/voice\/realtime\/session/);
    assert.match(open, /body:[\s\S]*sdp/);

    const connectStart = source.indexOf('async function connectRealtimeVoice(');
    const connectEnd = source.indexOf('\n    function handleRealtimeConnectionLoss(', connectStart);
    const connect = source.slice(connectStart, connectEnd);
    assert.match(connect, /peerConnection\.localDescription\?\.sdp \|\| offer\.sdp/);
    assert.match(connect, /openRealtimeSession\(localOfferSdp\)/);
    assert.match(connect, /session\?\.sdp/);
    assert.match(connect, /const answerSdp = String\(session\?\.sdp \|\| ''\);/);
    assert.doesNotMatch(connect, /const answerSdp = String\(session\?\.sdp \|\| ''\)\.trim\(\)/);
    assert.match(connect, /setRemoteDescription\(\{ type: 'answer', sdp: answerSdp \}\)/);
    assert.doesNotMatch(connect, /client_secret|realtime_url|api\.openai\.com/);

    const start = source.indexOf('async function startVoiceWakeListening(');
    const end = source.indexOf('\n    function toggleVoiceWakeListening(', start);
    const implementation = source.slice(start, end);
    const diagnostic = implementation.indexOf('reportBrowserVoiceV2ClientFailure(error, {');
    const stop = implementation.indexOf('stopVoiceWakeListening', diagnostic);
    const naturalFailure = implementation.indexOf('Bean couldn’t connect voice right now.', stop);
    assert.ok(diagnostic >= 0 && stop > diagnostic && naturalFailure > stop);
    assert.doesNotMatch(implementation, /state\.error\s*=\s*error\?\.message|state\.error\s*=\s*error\.message/);

    const stateErrorStart = source.indexOf('function handleBrowserVoiceV2StateError(');
    const stateErrorEnd = source.indexOf('\n    function updateVoiceWakeDraft(', stateErrorStart);
    const stateError = source.slice(stateErrorStart, stateErrorEnd);
    assert.match(stateError, /if \(\/abort\/i\.test\(abortSignature\)\) return;/);
    assert.ok(
        stateError.indexOf('/abort/i.test(abortSignature)') < stateError.indexOf('state.error = friendlyError'),
        'an expected canceled poll must not overwrite the terminal startup result with raw AbortError copy',
    );
});

test('[BV2-PROVIDER-OWNER-01] the server is the sole Realtime session configuration owner', () => {
    assert.doesNotMatch(source, /realtimeInstructionsUpdate|type:\s*'session\.update'/);
});

test('[BV2-WAKE-RESIDUE-01] an address-only provider transcript cannot reach admission', () => {
    const completed = source.indexOf("if (type === 'conversation.item.input_audio_transcription.completed')");
    const failed = source.indexOf("if (type === 'conversation.item.input_audio_transcription.failed')", completed);
    assert.ok(completed >= 0 && failed > completed);
    const implementation = source.slice(completed, failed);
    const addressOnly = implementation.indexOf('isRealtimeWakeAddressOnly(transcript, { wakeConfirmed })');
    const deleteProviderItem = implementation.indexOf('buildRealtimeConversationItemDeleteEvent(transcriptId)', addressOnly);
    const final = implementation.indexOf("type: 'transcript_final'");

    assert.ok(addressOnly >= 0, 'wake residue must have an explicit fail-closed branch');
    assert.ok(deleteProviderItem > addressOnly, 'wake residue must be erased from provider conversation state');
    assert.ok(final > deleteProviderItem, 'address-only handling must return before transcript admission');
});

test('[BV2-SEMANTIC-04] activated speech has no browser intent or spoken-Stop shortcut', () => {
    assert.doesNotMatch(source, /browserVoiceFollowUpRelevanceV2|classifyBrowserVoiceFollowUpRelevance/);
    assert.doesNotMatch(source, /isBrowserVoiceV2PlaybackStop|isSpokenStopShortcut/);
    assert.doesNotMatch(source, /isBrowserVoiceV2NaturalClosing|isLikelyNonEnglishRealtimeTranscript|isVoiceFillerOnly/);
    assert.match(source, /stripRealtimeLocalWakePrefix\(transcript, \{ wakeConfirmed \}\)/);
    assert.match(source, /naturalClosing: Boolean\(turn\.close_after_response \?\? turn\.closeAfterResponse \?\? false\)/);
    assert.match(source, /responseExpected: Boolean\(turn\.response_expected \?\? turn\.responseExpected \?\? false\)/);
});

test('[BV2-STOP-09] playback Stop is applied once and its Hermes final remains eligible for speech', () => {
    assert.match(source, /browserVoiceV2AppliedStopDirectiveIds\.has\(stopDirectiveId\)/);
    assert.match(source, /browserVoiceV2Controller\.stopPlayback\('semantic_spoken_stop'\)/);
    assert.match(source, /directive_id: stopDirectiveId/);
    assert.doesNotMatch(source, /suppressFinalAudio|suppress_final_audio/);
    assert.match(source, /text: turn\.finalText/);
});
