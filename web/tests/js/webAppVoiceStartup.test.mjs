import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../resources/js/heybean/webApp.js', import.meta.url), 'utf8');
globalThis.window = { matchMedia: () => null };
const {
    browserVoiceV2LocalWakeMatchesCompletedBarge,
    isMeaningfulBrowserVoiceV2Interruption,
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
    const detectedStart = source.indexOf('function handleLocalWakeDetected(');
    const detectedEnd = source.indexOf('\n    function handleLocalWakeFailure(', detectedStart);
    assert.ok(detectedStart >= 0 && detectedEnd > detectedStart);
    const detected = source.slice(detectedStart, detectedEnd);
    const clear = detected.indexOf('browserVoiceV2InputTransport.activate');
    const controllerWake = detected.indexOf('browserVoiceV2Controller.wakeConfirmed');
    assert.ok(clear >= 0 && controllerWake > clear, 'input clear must precede capture activation');

    const gateStart = source.indexOf('const localWakeGate = new LocalWakeGate({');
    const microphoneStart = source.indexOf('navigator.mediaDevices.getUserMedia', gateStart);
    const gateOptions = source.slice(gateStart, microphoneStart);
    assert.match(gateOptions, /onActivatedPcm:[\s\S]*browserVoiceV2InputTransport\.append/);
});

test('[BV2-WAKE-OWNER-01] Realtime transcript text cannot become a second wake owner', () => {
    const detectedStart = source.indexOf('function handleLocalWakeDetected(');
    const detectedEnd = source.indexOf('\n    function handleLocalWakeFailure(', detectedStart);
    const detected = source.slice(detectedStart, detectedEnd);
    assert.match(detected, /browserVoiceV2Controller\.wakeConfirmed\(\{ source: 'local_wake_gate' \}\)/);

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
    const reject = implementation.indexOf("browserVoiceV2Controller.rejectBargeIn('transcription_failed'");
    const captureFailure = implementation.indexOf("browserVoiceV2Controller.captureFailed('transcription_failed'");

    assert.doesNotMatch(delta, /confirmBargeIn|PotentialBargeInItems\.delete/);
    assert.match(delta, /updateVoiceWakeDraft\(commandDraft\)/);
    assert.match(completed, /browserVoiceV2Controller\.confirmBargeIn/);
    assert.ok(claimCandidate >= 0, 'the handler must claim the potential interruption exactly once');
    assert.ok(reject > claimCandidate, 'an unconfirmed interruption must restore the current playback');
    assert.ok(captureFailure > reject, 'ordinary capture failure handling must remain outside the interruption branch');
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
            < detected.indexOf('browserVoiceV2Controller.wakeConfirmed'),
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
    assert.match(implementation, /reportRealtimeUsageReliably\(report/);
    assert.match(implementation, /timeoutMs:\s*10000/);
    assert.match(implementation, /sanitizedLocalWakeFailure\(error, 'usage_accounting'\)/);
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

    assert.match(implementation, /sanitizedLocalWakeFailure\(error\)/);
    assert.match(implementation, /\/assistant\/voice\/client-failures/);
    assert.match(implementation, /handleRealtimeConnectionLoss/);
    assert.match(source, /onError: \(error\) => handleLocalWakeFailure\(localWakeGate, connectionGeneration, error\)/);
});

test('[BV2-DIAGNOSTIC-03] exhausted durable admission reports a sanitized diagnostic and retains a recovery envelope', () => {
    const start = source.indexOf('function failBrowserVoiceV2Admission(');
    const end = source.indexOf('\n    async function admitBrowserVoiceV2Turn(', start);
    assert.ok(start >= 0 && end > start, 'the admission failure boundary must remain discoverable');
    const failure = source.slice(start, end);
    assert.match(failure, /sanitizedLocalWakeFailure\(error, 'admission'\)/);
    assert.match(failure, /\/assistant\/voice\/client-failures/);

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
    assert.match(implementation, /sanitizedLocalWakeFailure\(lastError, 'clarification'\)/);
    assert.match(implementation, /\/assistant\/voice\/client-failures/);
    assert.match(implementation, /session_id: Number\(sessionId\)/);
    assert.match(implementation, /turn_id: turnId/);
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
    const diagnostic = implementation.indexOf("sanitizedLocalWakeFailure(error, 'startup')");
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
