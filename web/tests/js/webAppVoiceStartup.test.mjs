import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../resources/js/heybean/webApp.js', import.meta.url), 'utf8');

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
    const freshBoundary = connect.indexOf('localWakeGate.resetAfterTurn()', providerTransport);
    const finalLocalBarrier = connect.indexOf(
        'await waitForLocalWakeReady(localWakeGate, connectionGeneration)',
        freshBoundary,
    );
    const returnReadyGate = connect.indexOf('return localWakeGate;', finalLocalBarrier);

    assert.ok(localWakeSetup >= 0 && recvOnly > localWakeSetup, 'local wake setup must start before negotiation');
    assert.ok(offer > recvOnly && localDescription > offer, 'the negotiated local description must own the submitted SDP');
    assert.ok(parallelSetup > localDescription, 'local wake readiness and same-origin SDP setup must overlap');
    assert.doesNotMatch(connect, /direction:\s*'sendrecv'|addTrack\(/);
    assert.ok(providerTransport > parallelSetup, 'provider capture readiness must be awaited');
    assert.ok(freshBoundary > providerTransport, 'startup audio must be discarded after provider readiness');
    assert.ok(finalLocalBarrier > freshBoundary, 'the fresh local generation must become fully ready');
    assert.ok(returnReadyGate > finalLocalBarrier, 'only the fully ready local gate may leave startup');

    const start = source.indexOf('async function startVoiceWakeListening(');
    const end = source.indexOf('\n    function toggleVoiceWakeListening(', start);
    assert.ok(start >= 0 && end > start, 'voice-toggle startup must remain discoverable');
    const implementation = source.slice(start, end);
    const connectReady = implementation.indexOf('const readyWakeGate = await connectRealtimeVoice(connectionGeneration)');
    const localReadyCheck = implementation.indexOf('!readyWakeGate.isReady()', connectReady);
    const providerReady = implementation.indexOf('browserVoiceV2Controller.providerReady', localReadyCheck);
    const active = implementation.indexOf('realtimeVoiceActive = true', providerReady);
    const listening = implementation.indexOf('state.voiceWakeListening = true', active);
    const consumerReady = implementation.indexOf('readyWakeGate.setConsumerReady(true)', listening);
    const readyLabel = implementation.indexOf("state.chatRunState = 'Listening for “Hey Bean”…'", consumerReady);

    assert.ok(connectReady >= 0);
    assert.ok(localReadyCheck > connectReady, 'the final local barrier must be verified at handoff');
    assert.ok(providerReady > localReadyCheck, 'controller readiness must wait for both transports');
    assert.ok(active > providerReady && listening > active, 'capture must become active before accepting wakes');
    assert.ok(consumerReady > listening, 'the local detector must stay non-consuming throughout startup');
    assert.ok(readyLabel > consumerReady, 'the UI may claim listening only after consumer admission opens');

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

test('[BV2-PRIVACY-01] startup microphone activity is not presented as accepted listening', () => {
    const start = source.indexOf('function updateRealtimeVoiceActivity(');
    const end = source.indexOf('\n    function clearRealtimeVoiceInputFeedback(', start);
    assert.ok(start >= 0 && end > start, 'voice activity presentation must remain discoverable');
    const implementation = source.slice(start, end);

    assert.match(implementation, /realtimeLocalWakeGate\s*&& state\.voiceWakeListening\s*&& realtimeVoiceActive/);
    assert.doesNotMatch(implementation, /microphoneAvailable[\s\S]*state\.voiceProcessing/);
});

test('[BV2-BARGE-04] failed interruption transcription restores playback instead of admitting unknown speech', () => {
    const start = source.indexOf("if (type === 'conversation.item.input_audio_transcription.failed')");
    const end = source.indexOf("if (type === 'response.function_call_arguments.delta'", start);
    assert.ok(start >= 0 && end > start, 'the transcription-failure handler must remain discoverable');
    const implementation = source.slice(start, end);
    const claimCandidate = implementation.indexOf('const potentialBargeIn = browserVoiceV2PotentialBargeInItems.delete(transcriptId)');
    const reject = implementation.indexOf("browserVoiceV2Controller.rejectBargeIn('transcription_failed'");
    const captureFailure = implementation.indexOf("browserVoiceV2Controller.captureFailed('transcription_failed'");

    assert.ok(claimCandidate >= 0, 'the handler must claim the potential interruption exactly once');
    assert.ok(reject > claimCandidate, 'an unconfirmed interruption must restore the current playback');
    assert.ok(captureFailure > reject, 'ordinary capture failure handling must remain outside the interruption branch');
});

test('[BV2-USAGE-02] realtime usage is reported once and a plan limit closes voice with an upgrade path', () => {
    const start = source.indexOf('async function reportBrowserVoiceV2RealtimeUsage(');
    const end = source.indexOf('\n    function normalizeBrowserVoiceV2Speech(', start);
    assert.ok(start >= 0 && end > start, 'the realtime usage reporter must remain discoverable');
    const implementation = source.slice(start, end);

    assert.match(implementation, /browserVoiceV2RealtimeUsageEventIds\.has\(eventId\)/);
    assert.match(implementation, /\/assistant\/voice\/realtime\/usage/);
    assert.match(implementation, /reportRealtimeUsageReliably\(report/);
    assert.match(implementation, /sanitizedLocalWakeFailure\(error, 'usage_accounting'\)/);
    assert.match(implementation, /reason: Number\(error\?\.status \|\| 0\) === 402 \? 'usage_limit'/);
    assert.match(implementation, /state\.chatRunState = [\s\S]*'Upgrade to continue'/);

    const markupStart = source.indexOf('function errorMarkup(');
    const markupEnd = source.indexOf('\n    function isPlanLimitMessage(', markupStart);
    const markup = source.slice(markupStart, markupEnd);
    assert.match(markup, /Upgrade to keep going/);
    assert.match(markup, /href="\/pricing">View plans<\/a>/);
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
    const addressOnly = implementation.indexOf('isRealtimeWakeAddressOnly(transcript)');
    const deleteProviderItem = implementation.indexOf('buildRealtimeConversationItemDeleteEvent(transcriptId)', addressOnly);
    const final = implementation.indexOf('browserVoiceV2Controller.transcriptFinal(command');

    assert.ok(addressOnly >= 0, 'wake residue must have an explicit fail-closed branch');
    assert.ok(deleteProviderItem > addressOnly, 'wake residue must be erased from provider conversation state');
    assert.ok(final > deleteProviderItem, 'address-only handling must return before transcript admission');
});
