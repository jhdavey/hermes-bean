#!/usr/bin/env node
import { createServer } from 'node:http';
import { ElevenLabsClient } from '@elevenlabs/elevenlabs-js';
import {
    canonicalizeBeanVoiceTranscript,
    chooseBeanVoiceAcknowledgement,
    getLocalFastVoiceResponse,
    isDuplicateVoiceTranscript,
} from './elevenlabsVoiceAcknowledgement.mjs';

const required = (name) => {
    const value = process.env[name];
    if (!value) throw new Error(`${name} is required`);
    return value;
};

const ELEVENLABS_API_KEY = required('ELEVENLABS_API_KEY');
const ELEVENLABS_SPEECH_ENGINE_ID = required('ELEVENLABS_SPEECH_ENGINE_ID');
const BEAN_API_BASE_URL = (process.env.BEAN_API_BASE_URL || 'http://127.0.0.1:8000/api').replace(/\/$/, '');
const BEAN_API_TOKEN = required('BEAN_API_TOKEN');
const BEAN_CLIENT_TIMEZONE = process.env.BEAN_CLIENT_TIMEZONE || 'America/New_York';
const PORT = Number(process.env.ELEVENLABS_SPEECH_ENGINE_POC_PORT || 3001);
const PATH = process.env.ELEVENLABS_SPEECH_ENGINE_POC_PATH || '/ws';
const ACK_ENABLED = process.env.BEAN_ELEVENLABS_POC_ACK_ENABLED !== 'false';
const DUPLICATE_TRANSCRIPT_SUPPRESS_MS = Number(process.env.BEAN_ELEVENLABS_POC_DUPLICATE_SUPPRESS_MS || 3500);

const elevenlabs = new ElevenLabsClient({ apiKey: ELEVENLABS_API_KEY });
const httpServer = createServer();
const beanSessions = new Map();

function conversationKey(session) {
    return session?.conversationId || session?.conversation_id || 'default';
}

function latestUserMessage(transcript) {
    return [...(transcript || [])]
        .reverse()
        .find((message) => String(message?.role || '').toLowerCase() === 'user')?.content?.trim() || '';
}

async function beanApi(path, options = {}) {
    const response = await fetch(`${BEAN_API_BASE_URL}${path}`, {
        method: options.method || 'GET',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            Authorization: `Bearer ${BEAN_API_TOKEN}`,
            ...(options.headers || {}),
        },
        body: options.body ? JSON.stringify(options.body) : undefined,
        signal: options.signal,
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const message = payload?.message || `Bean API returned HTTP ${response.status}`;
        throw new Error(message);
    }
    return Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload;
}

async function ensureBeanSession(state, signal) {
    if (state.beanSessionId) return state.beanSessionId;
    const session = await beanApi('/bean/sessions', {
        method: 'POST',
        body: { client_timezone: BEAN_CLIENT_TIMEZONE },
        signal,
    });
    state.beanSessionId = session.id;
    return state.beanSessionId;
}

function latestAssistantMessage(messages) {
    return [...(messages || [])]
        .reverse()
        .find((message) => String(message?.role || '').toLowerCase() === 'assistant')?.content?.trim() || '';
}

async function askBean(state, content, signal) {
    const sessionId = await ensureBeanSession(state, signal);
    const started = Date.now();
    const result = await beanApi('/bean/messages', {
        method: 'POST',
        body: {
            session_id: sessionId,
            content,
            client_timezone: BEAN_CLIENT_TIMEZONE,
        },
        signal,
    });
    const answer = latestAssistantMessage(result.messages);
    const elapsed = Date.now() - started;
    console.log(`[bean] answered in ${elapsed}ms`);
    return answer || 'Bean finished that, but I did not get a spoken answer back.';
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

        yield await askBean(state, content, signal);
    } catch (error) {
        if (signal?.aborted) return;
        console.error('[bean] request failed:', error?.message || error);
        yield 'I hit a snag checking your dashboard. Please try again.';
    }
}

await elevenlabs.speechEngine.attach(ELEVENLABS_SPEECH_ENGINE_ID, httpServer, PATH, {
    debug: process.env.ELEVENLABS_SPEECH_ENGINE_DEBUG !== 'false',

    onInit(conversationId) {
        beanSessions.set(conversationId, { beanSessionId: null, startedAt: Date.now() });
        console.log(`[elevenlabs] session started: ${conversationId}`);
    },

    async onTranscript(transcript, signal, session) {
        const key = conversationKey(session);
        const state = beanSessions.get(key) || { beanSessionId: null, startedAt: Date.now() };
        beanSessions.set(key, state);

        const content = latestUserMessage(transcript);
        if (!content) return;

        const now = Date.now();
        const previousTranscript = state.lastTranscript || '';
        const duplicate = isDuplicateVoiceTranscript(previousTranscript, content);
        if (duplicate && now - (state.lastTranscriptAt || 0) < DUPLICATE_TRANSCRIPT_SUPPRESS_MS) {
            console.log(`[elevenlabs] suppressed duplicate transcript: ${content}`);
            return;
        }

        state.lastTranscript = content;
        state.lastCanonicalTranscript = canonicalizeBeanVoiceTranscript(content);
        state.lastTranscriptAt = now;

        console.log(`[elevenlabs] transcript: ${content}`);
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

httpServer.listen(PORT, () => {
    console.log(`Bean ElevenLabs Speech Engine POC listening on :${PORT}${PATH}`);
    console.log(`Configure ElevenLabs Speech Engine ws_url as wss://<your-public-host>${PATH}`);
});
