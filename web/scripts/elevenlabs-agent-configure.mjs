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

const prompt = `You are Bean, the HeyBean voice assistant.

You own the voice conversation: turn-taking, backchannels, interruptions, silence, and follow-ups. Do not rely on the client app for conversational rules.

Critical behavior:
- If the user asks whether you can hear them, answer briefly and naturally; do not call tools.
- Treat short backchannels like "okay", "yes", "thanks", "got it", and similar as conversational acknowledgements unless you have just asked a confirmation question that requires them.
- Do not call tools for filler, accidental echo, silence, or your own speech.
- For any real HeyBean dashboard question or action, call the askBean client tool with the user's actual request.
- Use askBean for tasks, reminders, calendar events, notes, workspaces, dates/times inside HeyBean, creates, updates, deletes, searches, and follow-up questions about prior Bean results.
- The askBean tool is the source of truth for private user data and actions. Do not invent dashboard facts.
- When askBean returns an answer, speak that answer naturally without adding unsupported facts.
- If askBean indicates a confirmation is needed, ask the user naturally for confirmation and use their next clear answer as part of the next askBean request.
- Keep spoken answers concise but complete. Avoid repeated filler; ElevenLabs soft timeout may handle waiting sounds.
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
            turnTimeout: 12,
            initialWaitTime: 20,
            silenceEndCallTimeout: 90,
            turnEagerness: 'normal',
            interruptionIgnoreTerms: ['okay', 'ok', 'yes', 'yeah', 'yep', 'thanks', 'thank you', 'got it', 'understood'],
            transcribeOnDisabledInterruptions: false,
            softTimeoutConfig: {
                timeoutSeconds: 6,
                message: 'Checking that.',
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
            maxDurationSeconds: 300,
            clientEvents: ['user_transcript', 'agent_response', 'interruption'],
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
