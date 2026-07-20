#!/usr/bin/env node
import { createServer } from 'node:http';
import { ElevenLabsClient } from '@elevenlabs/elevenlabs-js';
import {
    canonicalizeBeanVoiceTranscript,
    chooseBeanVoiceAcknowledgement,
    getLocalFastVoiceResponse,
    isDuplicateVoiceTranscript,
    isIgnorableVoiceTranscript,
    isSimplePostHealthEcho,
} from './elevenlabsVoiceAcknowledgement.mjs';

const required = (name) => {
    const value = process.env[name];
    if (!value) throw new Error(`${name} is required`);
    return value;
};

const ELEVENLABS_API_KEY = required('ELEVENLABS_API_KEY');
const ELEVENLABS_SPEECH_ENGINE_ID = required('ELEVENLABS_SPEECH_ENGINE_ID');
const BEAN_API_BASE_URL = (process.env.BEAN_API_BASE_URL || 'http://127.0.0.1/api').replace(/\/$/, '');
const BEAN_VOICE_BRIDGE_SECRET = required('BEAN_VOICE_BRIDGE_SECRET');
const BEAN_CLIENT_TIMEZONE = process.env.BEAN_CLIENT_TIMEZONE || 'America/New_York';
const PORT = Number(process.env.ELEVENLABS_SPEECH_ENGINE_PORT || process.env.ELEVENLABS_SPEECH_ENGINE_POC_PORT || 3001);
const HOST = process.env.ELEVENLABS_SPEECH_ENGINE_HOST || '127.0.0.1';
const PATH = process.env.ELEVENLABS_SPEECH_ENGINE_PATH || process.env.ELEVENLABS_SPEECH_ENGINE_POC_PATH || '/ws';
const ACK_ENABLED = process.env.BEAN_ELEVENLABS_ACK_ENABLED !== 'false';
const DUPLICATE_TRANSCRIPT_SUPPRESS_MS = Number(process.env.BEAN_ELEVENLABS_DUPLICATE_SUPPRESS_MS || 3500);
const INFLIGHT_ANSWER_REUSE_MS = Number(process.env.BEAN_ELEVENLABS_INFLIGHT_REUSE_MS || 180000);
const BRIDGE_REGISTRATION_RETRY_MS = Number(process.env.BEAN_ELEVENLABS_REGISTRATION_RETRY_MS || 450);
const BRIDGE_REGISTRATION_RETRY_ATTEMPTS = Number(process.env.BEAN_ELEVENLABS_REGISTRATION_RETRY_ATTEMPTS || 4);

const elevenlabs = new ElevenLabsClient({ apiKey: ELEVENLABS_API_KEY });
const httpServer = createServer((request, response) => {
    if (request.url === '/healthz') {
        response.writeHead(200, { 'Content-Type': 'application/json' });
        response.end(JSON.stringify({ ok: true, service: 'bean-elevenlabs-voice-bridge' }));
        return;
    }
    response.writeHead(404, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify({ ok: false }));
});
const beanSessions = new Map();

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function conversationKey(session) {
    return session?.conversationId || session?.conversation_id || 'default';
}

function latestUserMessage(transcript) {
    return [...(transcript || [])]
        .reverse()
        .find((message) => String(message?.role || '').toLowerCase() === 'user')?.content?.trim() || '';
}

function latestAssistantMessage(messages) {
    return [...(messages || [])]
        .reverse()
        .find((message) => String(message?.role || '').toLowerCase() === 'assistant')?.content?.trim() || '';
}

async function beanBridgeApi(path, options = {}) {
    const response = await fetch(`${BEAN_API_BASE_URL}${path}`, {
        method: options.method || 'GET',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Bean-Voice-Bridge-Secret': BEAN_VOICE_BRIDGE_SECRET,
            ...(options.headers || {}),
        },
        body: options.body ? JSON.stringify(options.body) : undefined,
        signal: options.signal,
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const message = payload?.message || `Bean bridge API returned HTTP ${response.status}`;
        const error = new Error(message);
        error.status = response.status;
        throw error;
    }
    return Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload;
}

async function askBean(state, content) {
    const conversationId = state.conversationId;
    if (!conversationId) throw new Error('ElevenLabs conversation was not initialized.');

    const started = Date.now();
    let lastError = null;
    for (let attempt = 0; attempt <= BRIDGE_REGISTRATION_RETRY_ATTEMPTS; attempt += 1) {
        try {
            const result = await beanBridgeApi('/bean/elevenlabs/bridge/message', {
                method: 'POST',
                body: {
                    conversation_id: conversationId,
                    content,
                    client_timezone: state.clientTimezone || BEAN_CLIENT_TIMEZONE,
                },
            });
            const answer = latestAssistantMessage(result.messages);
            const elapsed = Date.now() - started;
            console.log(`[bean] answered in ${elapsed}ms, conversation_id=${conversationId}`);
            return answer || 'Bean finished that, but I did not get a spoken answer back.';
        } catch (error) {
            lastError = error;
            if (error?.status !== 404 || attempt >= BRIDGE_REGISTRATION_RETRY_ATTEMPTS) break;
            console.log(`[bean] bridge session not registered yet; retrying (${attempt + 1}/${BRIDGE_REGISTRATION_RETRY_ATTEMPTS})`);
            await sleep(BRIDGE_REGISTRATION_RETRY_MS);
        }
    }
    throw lastError || new Error('Bean bridge request failed.');
}

function getBeanAnswer(state, content) {
    const canonical = canonicalizeBeanVoiceTranscript(content);
    const now = Date.now();
    const inflight = state.inflightAnswer;

    if (inflight && inflight.canonical === canonical && now - inflight.startedAt < INFLIGHT_ANSWER_REUSE_MS) {
        console.log(`[bean] reusing in-flight answer for: ${canonical}`);
        return inflight.promise;
    }

    const promise = askBean(state, content)
        .finally(() => {
            if (state.inflightAnswer?.promise === promise) {
                state.inflightAnswer = null;
            }
        });

    state.inflightAnswer = { canonical, promise, startedAt: now };
    return promise;
}

async function* responseStream(state, content, transcript, signal) {
    const localFastResponse = getLocalFastVoiceResponse(content);
    if (localFastResponse) {
        yield localFastResponse;
        return;
    }

    try {
        const acknowledgement = ACK_ENABLED ? chooseBeanVoiceAcknowledgement(content, transcript) : null;
        if (acknowledgement) {
            yield `${acknowledgement} `;
        }

        const answer = await getBeanAnswer(state, content);
        if (!signal?.aborted) yield answer;
    } catch (error) {
        if (signal?.aborted) return;
        console.error('[bean] request failed:', error?.message || error);
        yield 'I hit a snag checking your dashboard. Please try again.';
    }
}

await elevenlabs.speechEngine.attach(ELEVENLABS_SPEECH_ENGINE_ID, httpServer, PATH, {
    debug: process.env.ELEVENLABS_SPEECH_ENGINE_DEBUG !== 'false',

    onInit(conversationId) {
        beanSessions.set(conversationId, {
            conversationId,
            clientTimezone: BEAN_CLIENT_TIMEZONE,
            startedAt: Date.now(),
            lastTranscript: '',
            lastActionableTranscript: '',
            lastLocalHealthCheckAt: 0,
            lastTranscriptAt: 0,
        });
        console.log(`[elevenlabs] session started: ${conversationId}`);
    },

    async onTranscript(transcript, signal, session) {
        const key = conversationKey(session);
        const state = beanSessions.get(key) || {
            conversationId: key,
            clientTimezone: BEAN_CLIENT_TIMEZONE,
            startedAt: Date.now(),
            lastTranscript: '',
            lastActionableTranscript: '',
            lastLocalHealthCheckAt: 0,
            lastTranscriptAt: 0,
        };
        state.conversationId = key;
        beanSessions.set(key, state);

        const rawContent = latestUserMessage(transcript);
        if (!rawContent) return;

        const now = Date.now();
        const previousTranscript = state.lastTranscript || '';
        const duplicate = isDuplicateVoiceTranscript(previousTranscript, rawContent);
        const duplicateRecent = duplicate && now - (state.lastTranscriptAt || 0) < DUPLICATE_TRANSCRIPT_SUPPRESS_MS;
        const content = isIgnorableVoiceTranscript(rawContent) && state.lastActionableTranscript
            ? state.lastActionableTranscript
            : rawContent;
        const replayingPlaceholder = content !== rawContent;

        state.lastTranscript = rawContent;
        state.lastCanonicalTranscript = canonicalizeBeanVoiceTranscript(content);
        state.lastTranscriptAt = now;
        if (!isIgnorableVoiceTranscript(rawContent)) {
            state.lastActionableTranscript = content;
        }

        if (isIgnorableVoiceTranscript(content)) {
            console.log(`[elevenlabs] ignoring placeholder transcript: ${rawContent}`);
            return;
        }

        const isHealthCheck = canonicalizeBeanVoiceTranscript(content) === 'can you hear me';
        if (isHealthCheck) {
            state.lastLocalHealthCheckAt = now;
        } else if (state.lastLocalHealthCheckAt && now - state.lastLocalHealthCheckAt < 15000 && isSimplePostHealthEcho(content)) {
            console.log(`[elevenlabs] ignoring post-health-check echo transcript: ${content}`);
            return;
        }

        const label = replayingPlaceholder ? 'replaying previous transcript after placeholder' : (duplicateRecent ? 'replaying duplicate transcript' : 'transcript');
        console.log(`[elevenlabs] ${label}: ${content}`);
        session.sendResponse(responseStream(state, content, transcript, signal));
    },

    onClose(session) {
        const key = conversationKey(session);
        beanSessions.delete(key);
        console.log(`[elevenlabs] session ended: ${key}`);
    },

    onDisconnect(session) {
        const key = conversationKey(session);
        beanSessions.delete(key);
        console.log(`[elevenlabs] session disconnected: ${key}`);
    },

    onError(error, session) {
        console.error('[elevenlabs] error:', error?.message || error);
        if (session) beanSessions.delete(conversationKey(session));
    },
});

httpServer.listen(PORT, HOST, () => {
    console.log(`Bean ElevenLabs voice bridge listening on http://${HOST}:${PORT}${PATH}`);
    console.log(`Health check: http://${HOST}:${PORT}/healthz`);
});
