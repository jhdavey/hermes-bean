# Bean ElevenLabs Speech Engine POC

This POC tests the proposed migration path where ElevenLabs owns the realtime voice loop and Bean remains the authenticated backend brain.

## Architecture

```text
Browser POC page
  -> Laravel /api/bean/elevenlabs/conversation-token
  -> ElevenLabs WebRTC conversation token
  -> ElevenLabs Speech Engine
  -> POC WebSocket server scripts/elevenlabs-speech-engine-poc.mjs
  -> Laravel Bean API /api/bean/sessions and /api/bean/messages
  -> streamed text back to ElevenLabs
  -> ElevenLabs speaks naturally to the user
```

This is deliberately feature-flagged and separate from the current production Bean voice button.

## What the POC proves

- ElevenLabs can manage mic/audio, turn-taking, interruption, and speech playback.
- Bean can remain the source of truth for reasoning, private dashboard facts, sessions, and actions.
- The user can compare perceived latency and naturalness against the current OpenAI realtime voice frontend.

## What the POC does not prove yet

- Multi-user production auth for Speech Engine. The local POC server uses one temporary Bean API token via `BEAN_API_TOKEN`.
- True streaming Bean answer tokens. The current Bean `/api/bean/messages` endpoint returns after a run completes; the POC sends an immediate short acknowledgement, then speaks the finished Bean answer.
- Final production deployment topology. Production would need a managed WebSocket process and a per-conversation auth bridge instead of a static POC token.

## Required ElevenLabs setup

1. Create or open an ElevenLabs account.
2. Create a Speech Engine resource.
3. Point its WebSocket URL to the public URL for the POC server, for example:

```text
wss://<ngrok-or-public-host>/ws
```

4. Copy the Speech Engine ID. It should look like:

```text
seng_...
```

## Local environment

In `web/.env`, set:

```bash
ELEVENLABS_API_KEY=<redacted>
ELEVENLABS_SPEECH_ENGINE_ID=seng_...
ELEVENLABS_SPEECH_ENGINE_POC_ENABLED=true
```

For the standalone Speech Engine bridge process, export:

```bash
export ELEVENLABS_API_KEY=<redacted>
export ELEVENLABS_SPEECH_ENGINE_ID=seng_...
export BEAN_API_BASE_URL=https://heybean.org/api # or local Laravel API URL
export BEAN_API_TOKEN=<temporary Bean API token for the test account>
export BEAN_CLIENT_TIMEZONE=America/New_York
```

Never commit real values.

## Running locally

Terminal 1: expose the POC WebSocket server publicly.

```bash
ngrok http 3001
```

Use the HTTPS host from ngrok, but configure ElevenLabs with `wss://.../ws`.

Terminal 2: run the Speech Engine bridge.

```bash
cd web
npm run elevenlabs:poc
```

Terminal 3: run the Laravel app as usual, then open:

```text
/elevenlabs-voice-poc
```

You must be signed in to Bean in the same browser first because the POC page mints the ElevenLabs conversation token through the authenticated Bean API.

## Test script

1. `Can you hear me?`
2. `What tasks do I have today?`
3. `What about tomorrow?`
4. `Create a task called test voice task.`
5. `Mark it complete.`
6. Interrupt Bean while it is speaking.
7. Say `stop` or click Stop.

## Success criteria before full migration

- First audible acknowledgement under 1,200ms.
- Follow-up turn works without manual reset.
- Interruption/barge-in stops the current response.
- Bean answers are grounded in the existing Bean backend/tool path.
- No static Bean API token or broad database access is required in the production design.
- The voice feels materially better than the current 6/10 baseline.

## Production migration notes

If the POC passes, replace the static `BEAN_API_TOKEN` bridge with a per-conversation auth bridge:

- Laravel creates a short-lived voice bridge session for the authenticated user.
- The ElevenLabs conversation carries only an opaque bridge token or session reference.
- The Speech Engine server validates the bridge token with Laravel before each turn.
- Bean voice telemetry records ElevenLabs conversation IDs and per-turn timing.
- The current OpenAI realtime voice frontend remains behind a fallback flag until ElevenLabs proves stable.
