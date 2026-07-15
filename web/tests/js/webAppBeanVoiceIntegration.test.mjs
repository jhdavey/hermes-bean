import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(
    new URL('../../resources/js/heybean/webApp.js', import.meta.url),
    'utf8',
);
const runtimeSource = await readFile(
    new URL('../../resources/js/heybean/BeanVoiceRuntime.js', import.meta.url),
    'utf8',
);
const transportSource = await readFile(
    new URL('../../resources/js/heybean/beanVoiceRealtimeTransport.js', import.meta.url),
    'utf8',
);

test('[BV-INTEGRATION-01] web app delegates browser voice lifecycle to exactly one runtime', () => {
    assert.match(source, /import \{ BeanVoiceRuntime \} from '\.\/BeanVoiceRuntime\.js'/);
    assert.equal((source.match(/new BeanVoiceRuntime\(/g) || []).length, 1);
    assert.doesNotMatch(source, /new BrowserVoiceControllerV2|new BrowserVoiceV2Client/);
    assert.doesNotMatch(source, /RealtimeInputTranscriptBuffer|BrowserVoiceHttpSpeechTransportV2/);
    assert.doesNotMatch(source, /realtimePeerConnection|realtimeDataChannel|realtimeRemoteAudio/);
    assert.match(source, /beanVoiceRuntime\.toggle\(\)/);
    assert.match(source, /beanVoiceRuntime\.stopPlayback\('button_stop'\)/);
});

test('[BV-INTEGRATION-02] browser uses authenticated projection streaming and no HTTP speech authority', () => {
    const streamStart = source.indexOf('async function openBeanVoiceProjectionStream(');
    const streamEnd = source.indexOf('\n    async function ensureBeanVoiceConversationSession(', streamStart);
    const stream = source.slice(streamStart, streamEnd);
    assert.match(stream, /Accept: 'text\/event-stream'/);
    assert.match(stream, /Authorization: 'Bearer ' \+ state\.token/);
    assert.doesNotMatch(stream, /token=|access_token/);
    assert.doesNotMatch(source, /\/assistant\/voice\/speech/);
    assert.doesNotMatch(source, /requestBrowserVoiceV2SpeechAudio/);
});

test('[BV-INTEGRATION-03] voice messages remain durable but never render or announce in browser chat', () => {
    assert.match(source, /function messageVisibleInChat\(message\)/);
    assert.match(source, /display_mode[^\n]+voice_only/);
    assert.match(source, /origin[^\n]+spoken_voice/);
    assert.match(source, /state\.messages = normalizeList\(session\.messages\)\.filter\(messageVisibleInChat\)/);
    assert.match(source, /const messages = state\.messages\.filter\(messageVisibleInChat\)/);
    assert.match(source, /return state\.messages\.filter\(messageVisibleInChat\)/);
});

test('[BV-INTEGRATION-04] content-free pre-admission precedes activated PCM and browser cannot create responses', () => {
    const admissionStart = runtimeSource.indexOf('async #preAdmitWake(');
    const admissionEnd = runtimeSource.indexOf('\n    #wakeReleased(', admissionStart);
    const admission = runtimeSource.slice(admissionStart, admissionEnd);
    assert.match(admission, /this\.request\('\/assistant\/voice\/turns'/);
    assert.match(admission, /admitted\?\.sideband_ready !== true/);
    assert.match(admission, /this\.transport\.activateInput\(detection\.generation\)/);
    assert.match(admission, /mode: 'new_conversation'/);
    assert.match(admission, /epoch: conversationEpoch/);
    assert.doesNotMatch(admission, /transcript|content|message|audio/);

    assert.doesNotMatch(transportSource, /['"]response\.create['"]\s*,/);
    assert.doesNotMatch(transportSource, /conversation\.item\.create|function_call_output/);
    assert.match(transportSource, /if \(type === 'response\.created'\) return this\.#bindResponse/);
});

test('[BV-INTEGRATION-04A] follow-up and clarification use the same pre-admission route', () => {
    const followUpStart = runtimeSource.indexOf('async #prepareContextualCapture(');
    const followUpEnd = runtimeSource.indexOf('\n    #localGateReady(', followUpStart);
    const implementation = runtimeSource.slice(followUpStart, followUpEnd);
    assert.match(implementation, /clarification \? text\(response\?\.turnId\) : text\(this\.createTurnId\(\)\)/);
    assert.match(implementation, /input_generation: inputGeneration/);
    assert.match(implementation, /mode: 'contextual_follow_up'/);
    assert.match(implementation, /epoch: this\.conversationEpoch/);
    assert.match(implementation, /this\.request\('\/assistant\/voice\/turns'/);
    assert.doesNotMatch(runtimeSource, /\/clarifications/);
});

test('[BV-INTEGRATION-04B] physical Stop never cancels durable Bean work', () => {
    const stopStart = source.indexOf('function stopBrowserVoiceV2Playback(');
    const stopEnd = source.indexOf('\n    async function sendChatContent(', stopStart);
    const implementation = source.slice(stopStart, stopEnd);
    assert.match(implementation, /beanVoiceRuntime\.stopPlayback\('button_stop'\)/);
    assert.doesNotMatch(implementation, /cancellations|cancelBeanTurn|assistant\/runs/);
});

test('[BV-INTEGRATION-05] realtime session creation carries durable and generation identities', () => {
    const sessionStart = source.indexOf('async function openBeanVoiceRealtimeSession(');
    const sessionEnd = source.indexOf('\n    function beanVoiceRunState(', sessionStart);
    const implementation = source.slice(sessionStart, sessionEnd);
    assert.match(implementation, /session_id: conversationSessionId/);
    assert.match(implementation, /controller_generation:/);
    assert.match(implementation, /provider_connection_generation:/);
    assert.match(implementation, /\/assistant\/voice\/realtime\/session/);
});

test('[BV-AUDIO-NATIVE-01] browser media has no raw microphone WebRTC track, STT, TTS, or response authority', () => {
    assert.doesNotMatch(transportSource, /\.addTrack\s*\(/);
    assert.match(transportSource, /addTransceiver\?\.\('audio', \{ direction: 'recvonly' \}\)/);
    assert.doesNotMatch(source, /\/assistant\/voice\/(?:speech|transcription|usage)/);
    assert.doesNotMatch(runtimeSource, /response\.create|conversation\.item\.create|function_call_output/);
});

test('[BV-FAILURE-01] playback diagnostics preserve their stable server stage', () => {
    const reportStart = source.indexOf('function reportBeanVoiceFailure(');
    const reportEnd = source.indexOf('\n    function beanVoiceActivityProperties(', reportStart);
    const implementation = source.slice(reportStart, reportEnd);
    assert.match(implementation, /requestedStage === 'playback_authorization'/);
    assert.match(implementation, /\? 'playback'/);
    assert.match(implementation, /'delivery'/);
    assert.match(implementation, /'projection'/);
    assert.match(implementation, /'realtime_sideband'/);
});

test('[BV-RELOAD-01] pagehide tears down the sole voice runtime', () => {
    assert.match(source, /window\.addEventListener\('pagehide', \(\) => stopRealtimeVoiceForContextChange\(\)\)/);
    const teardownStart = source.indexOf('function stopRealtimeVoiceForContextChange(');
    const teardownEnd = source.indexOf('\n    async function submitChat(', teardownStart);
    const implementation = source.slice(teardownStart, teardownEnd);
    assert.match(implementation, /beanVoiceRuntime\.detachSession\(\)/);
    assert.match(implementation, /beanVoiceRuntime\.stop\('context_changed'\)/);
});

test('[BV-CONCURRENCY-01] dock projection retains unchanged active jobs beside each changed job', () => {
    const projectionStart = source.indexOf('function applyBeanVoiceWorkProjection(');
    const projectionEnd = source.indexOf('\n    function reportBeanVoiceFailure(', projectionStart);
    const implementation = source.slice(projectionStart, projectionEnd);
    assert.match(implementation, /\[\.\.\.activeJobs, \.\.\.jobs\]/);
    assert.match(implementation, /projectedById\.set\(key, job\)/);
});
