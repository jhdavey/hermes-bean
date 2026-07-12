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

    const parallelSetup = connect.indexOf('Promise.all([localWakePromise, openRealtimeSession()])');
    const recvOnly = connect.indexOf("peerConnection.addTransceiver('audio', { direction: 'recvonly' })");
    const providerTransport = connect.indexOf('transportReady,');
    const freshBoundary = connect.indexOf('localWakeGate.resetAfterTurn()', providerTransport);
    const finalLocalBarrier = connect.indexOf(
        'await waitForLocalWakeReady(localWakeGate, connectionGeneration)',
        freshBoundary,
    );
    const returnReadyGate = connect.indexOf('return localWakeGate;', finalLocalBarrier);

    assert.ok(parallelSetup >= 0, 'local wake and provider session setup must remain parallel');
    assert.ok(recvOnly > parallelSetup, 'the provider WebRTC media path must remain receive-only');
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
    assert.match(implementation, /attempt < 1[\s\S]*setTimeout/);
    assert.match(implementation, /reason: Number\(error\?\.status \|\| 0\) === 402 \? 'usage_limit'/);
    assert.match(implementation, /state\.chatRunState = [\s\S]*'Upgrade to continue'/);

    const markupStart = source.indexOf('function errorMarkup(');
    const markupEnd = source.indexOf('\n    function isPlanLimitMessage(', markupStart);
    const markup = source.slice(markupStart, markupEnd);
    assert.match(markup, /Upgrade to keep going/);
    assert.match(markup, /href="\/pricing">View plans<\/a>/);
});
