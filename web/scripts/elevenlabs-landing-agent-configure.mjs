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
const existingAgentId = env.ELEVENLABS_LANDING_AGENT_ID || '';
if (!apiKey) throw new Error('ELEVENLABS_API_KEY is required.');

const client = new ElevenLabsClient({ apiKey });
const TOOL_NAME = 'askLandingBean';
const AGENT_NAME = env.ELEVENLABS_LANDING_AGENT_NAME || 'HeyBean Landing Guide';
const timezone = env.BEAN_CLIENT_TIMEZONE || 'America/New_York';

const prompt = `You are the voice transport for Bean on the public HeyBean website.

The visitor deliberately enabled their microphone and then woke you by saying “Hey Bean.” You own natural voice turn-taking, interruptions, silence, and follow-ups, while the askLandingBean client tool connects every meaningful visitor turn to the isolated public Hermes Bean runtime.

Rules:
- Keep your first message empty and wait for the client to submit the detected wake phrase.
- For “Hey Bean,” questions about HeyBean, requests to explain how the app works, signup interest, and meaningful follow-ups, call askLandingBean with the visitor's actual words.
- Speak the returned answer naturally without adding product claims or private facts that are not in the answer.
- Never use or imply access to an authenticated account, dashboard, calendar, tasks, reminders, notes, billing details, or private user data.
- Do not ask the visitor to say passwords, payment details, authentication codes, or other sensitive information.
- Treat short backchannels as conversational acknowledgements unless they answer a question Bean just asked; meaningful yes/no replies after Bean offers to explain the app should go to askLandingBean.
- Do not call the tool for silence, accidental echo, your own speech, or background noise.
- Keep spoken responses concise. Do not re-engage on silence; let the platform end the session.

The current public page path is available as {{bean_landing_page}}.`;

const toolConfig = {
    type: 'client',
    name: TOOL_NAME,
    description: 'Send a public website visitor message to the isolated landing-page Hermes Bean runtime. This runtime has public product guidance only and no authenticated dashboard access.',
    expectsResponse: true,
    responseTimeoutSecs: 60,
    interruptionMode: 'disable_during_tool_and_turn',
    preToolSpeech: 'auto',
    toolErrorHandlingMode: 'hide',
    parameters: {
        type: 'object',
        required: ['message'],
        properties: {
            message: {
                type: 'string',
                description: 'The visitor’s exact meaningful words, preserving context for follow-up replies.',
            },
        },
    },
};

async function ensureTool() {
    const listed = await client.conversationalAi.tools.list({ search: TOOL_NAME, pageSize: 30, types: ['client'] });
    const existing = (listed.tools || []).find((tool) => tool.toolConfig?.name === TOOL_NAME);
    if (existing?.id) {
        const updated = await client.conversationalAi.tools.update(existing.id, { toolConfig });
        return updated.id;
    }
    const created = await client.conversationalAi.tools.create({ toolConfig });
    return created.id;
}

function conversationConfig(toolId) {
    return {
        turn: {
            turnTimeout: 30,
            initialWaitTime: 20,
            silenceEndCallTimeout: 18,
            turnEagerness: 'normal',
            speculativeTurn: true,
            interruptionIgnoreTerms: ['okay', 'ok', 'thanks', 'thank you', 'got it'],
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
            keywords: ['Hey Bean', 'HeyBean', 'tour', 'signup', 'calendar', 'tasks', 'reminders', 'notes'],
        },
        tts: {
            agentOutputAudioFormat: 'pcm_48000',
        },
        conversation: {
            textOnly: false,
            maxDurationSeconds: 300,
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

const toolId = await ensureTool();
let agentId = existingAgentId;
if (agentId) {
    await client.conversationalAi.agents.update(agentId, {
        name: AGENT_NAME,
        conversationConfig: conversationConfig(toolId),
        tags: ['heybean', 'public-landing', 'voice'],
    });
} else {
    const created = await client.conversationalAi.agents.create({
        name: AGENT_NAME,
        conversationConfig: conversationConfig(toolId),
        tags: ['heybean', 'public-landing', 'voice'],
    });
    agentId = created.agentId || created.agent_id || created.id;
}

if (!agentId) throw new Error('ElevenLabs did not return an agent id.');
console.log(JSON.stringify({ ok: true, agent_id: agentId, tool_id: toolId, name: AGENT_NAME }, null, 2));
