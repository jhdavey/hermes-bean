#!/usr/bin/env node
import fs from 'node:fs';
import { ElevenLabsClient } from '@elevenlabs/elevenlabs-js';

function readDotEnv(path = '.env') {
    if (!fs.existsSync(path)) return {};
    return Object.fromEntries(
        fs.readFileSync(path, 'utf8')
            .split(/\r?\n/)
            .filter((line) => line && !line.trim().startsWith('#') && line.includes('='))
            .map((line) => {
                const index = line.indexOf('=');
                return [line.slice(0, index), line.slice(index + 1).replace(/^"|"$/g, '')];
            }),
    );
}

const env = { ...readDotEnv(), ...process.env };
const apiKey = env.ELEVENLABS_API_KEY;
const existingAgentId = env.ELEVENLABS_AGENT_ID || '';
if (!apiKey) throw new Error('ELEVENLABS_API_KEY is required.');

const client = new ElevenLabsClient({ apiKey });

const ASK_BEAN_TOOL_NAME = 'askBean';
const AGENT_NAME = env.ELEVENLABS_AGENT_NAME || 'HeyBean Voice Agent';
const timezone = env.BEAN_CLIENT_TIMEZONE || 'America/New_York';
const voiceMaxDurationSeconds = Number(env.ELEVENLABS_MAX_DURATION_SECONDS || 60);
const voiceInitialWaitSeconds = Number(env.ELEVENLABS_INITIAL_WAIT_SECONDS || env.ELEVENLABS_SILENCE_TIMEOUT_SECONDS || 9);
const voiceSilenceEndCallSeconds = Number(env.ELEVENLABS_SILENCE_END_CALL_SECONDS || 15);
const voiceTurnTimeoutSeconds = Number(env.ELEVENLABS_TURN_TIMEOUT_SECONDS || 15);

const prompt = `You are Bean, the HeyBean voice assistant.

You own the voice conversation: turn-taking, backchannels, interruptions, silence, and follow-ups. Do not rely on the client app for conversational rules.

Critical behavior:
- If the user asks whether you can hear them, answer briefly and naturally; do not call tools.
- Treat short backchannels like "okay", "yes", "thanks", "got it", and similar as conversational acknowledgements unless you have just asked a confirmation question that requires them.
- Do not call tools for filler, accidental echo, silence, or your own speech.
- A compact JSON dashboard_context is provided in the dynamic variable {{bean_dashboard_context}}. It contains fresh scoped read-only dashboard facts and a policy block.
- dashboard_context includes the user's canonical timezone, today, tomorrow, overdue, recent notes, and a compact 31-day upcoming horizon for near-future tasks, reminders, and calendar events.
- Treat dashboard_context.timezone / the user's saved timezone as the source of truth for all local date and time references.
- For simple read-only questions that can be answered completely from dashboard_context, including upcoming/this-week/date questions covered by the upcoming horizon, answer directly from dashboard_context without calling tools.
- Use *_local timestamp fields from dashboard_context or askBean results when speaking times to the user. Do not speak UTC clock times as if they were local.
- If dashboard_context is missing, stale, incomplete, uncertain, or the user asks to create, update, delete, search deeply, or act on private data, call askBean with the user's actual request.
- For any real HeyBean dashboard action or fallback question, call the askBean client tool with the user's actual request.
- Use askBean for creates, updates, deletes, searches, weather/forecast, and follow-up questions that are not fully answered by dashboard_context.
- The askBean tool is the authoritative source of truth for private user data and actions. Do not invent dashboard facts.
- When askBean returns an answer, speak that answer naturally without adding unsupported facts.
- If askBean indicates a confirmation is needed, ask the user naturally for confirmation and use their next clear answer as part of the next askBean request.
- Keep spoken answers concise but complete. Do not ask "Are you still there?" or otherwise re-engage on silence; if the user is silent, wait for the platform to end the turn/session.
`;

const askBeanToolConfig = {
    type: 'client',
    name: ASK_BEAN_TOOL_NAME,
    description: 'Ask authenticated HeyBean/Laravel/Hermes to answer a user dashboard request or perform a user-requested action. Use this for all private HeyBean data and mutations.',
    expectsResponse: true,
    responseTimeoutSecs: 120,
    interruptionMode: 'disable_during_tool_and_turn',
    preToolSpeech: 'auto',
    toolErrorHandlingMode: 'hide',
    parameters: {
        type: 'object',
        required: ['message'],
        properties: {
            message: {
                type: 'string',
                description: 'The exact meaningful user request for Bean, including relevant follow-up context if needed. Do not pass filler, echo, or backchannels.',
            },
        },
    },
};

async function ensureAskBeanTool() {
    const listed = await client.conversationalAi.tools.list({ search: ASK_BEAN_TOOL_NAME, pageSize: 30, types: ['client'] });
    const existing = (listed.tools || []).find((tool) => tool.toolConfig?.name === ASK_BEAN_TOOL_NAME);
    if (existing?.id) {
        const updated = await client.conversationalAi.tools.update(existing.id, { toolConfig: askBeanToolConfig });
        return updated.id;
    }
    const created = await client.conversationalAi.tools.create({ toolConfig: askBeanToolConfig });
    return created.id;
}

function conversationConfig(toolId) {
    return {
        turn: {
            turnTimeout: voiceTurnTimeoutSeconds,
            initialWaitTime: voiceInitialWaitSeconds,
            silenceEndCallTimeout: voiceSilenceEndCallSeconds,
            turnEagerness: 'normal',
            speculativeTurn: true,
            interruptionIgnoreTerms: ['okay', 'ok', 'yes', 'yeah', 'yep', 'thanks', 'thank you', 'got it', 'understood'],
            transcribeOnDisabledInterruptions: false,
            softTimeoutConfig: {
                timeoutSeconds: -1,
                message: 'Waiting.',
                additionalSoftTimeoutMessages: [],
                useLlmGeneratedMessage: false,
                randomizeFillers: false,
                maxSoftTimeoutsPerGeneration: 1,
            },
        },
        asr: {
            provider: 'scribe_realtime',
            quality: 'high',
            userInputAudioFormat: 'pcm_16000',
            keywords: ['Hey Bean', 'HeyBean', 'tasks', 'to-do', 'todo', 'reminders', 'calendar', 'notes'],
        },
        tts: {
            // WebRTC/LiveKit voice sessions operate at 48 kHz. Leaving this at the
            // Agent default (previously pcm_16000) can leave the data channel and
            // transcript path alive while the remote audio track is silent.
            agentOutputAudioFormat: 'pcm_48000',
        },
        conversation: {
            textOnly: false,
            maxDurationSeconds: voiceMaxDurationSeconds,
            clientEvents: ['audio', 'user_transcript', 'agent_response', 'interruption'],
        },
        agent: {
            firstMessage: '',
            language: 'en',
            disableFirstMessageInterruptions: false,
            prompt: {
                prompt,
                toolIds: [toolId],
                timezone,
                ignoreDefaultPersonality: true,
            },
        },
    };
}

const toolId = await ensureAskBeanTool();
let agentId = existingAgentId;
if (agentId) {
    await client.conversationalAi.agents.update(agentId, {
        name: AGENT_NAME,
        conversationConfig: conversationConfig(toolId),
        tags: ['heybean', 'production-voice'],
    });
} else {
    const created = await client.conversationalAi.agents.create({
        name: AGENT_NAME,
        conversationConfig: conversationConfig(toolId),
        tags: ['heybean', 'production-voice'],
    });
    agentId = created.agentId || created.agent_id || created.id;
}

if (!agentId) throw new Error('ElevenLabs did not return an agent id.');
console.log(JSON.stringify({ ok: true, agent_id: agentId, tool_id: toolId, name: AGENT_NAME }, null, 2));
