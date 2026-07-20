# Bean ElevenLabs Agent Voice

Bean voice uses ElevenLabs Agent / Conversational AI for the realtime conversation layer. OpenAI remains available to Hermes for Bean reasoning; the browser voice path does not use OpenAI Realtime or a custom ElevenLabs Speech Engine bridge.

## Runtime shape

```text
Browser HeyBean app
  -> /api/bean/elevenlabs/conversation-token (authenticated Laravel)
  -> ElevenLabs Agent WebRTC conversation
  -> ElevenLabs owns STT/TTS, turn-taking, interruptions, backchannels, soft timeouts
  -> Agent calls client tool: askBean({ message })
  -> Browser client tool calls /api/bean/messages with the authenticated user's token
  -> Laravel BeanRuntimeService / Hermes / bean_dashboard tools
  -> Tool result returns to ElevenLabs Agent, which speaks the response
```

## Required env

```bash
ELEVENLABS_API_KEY=...
ELEVENLABS_AGENT_ENABLED=true
ELEVENLABS_AGENT_ID=agent_...
# optional
ELEVENLABS_AGENT_ENVIRONMENT=
ELEVENLABS_AGENT_BRANCH_ID=
ELEVENLABS_AGENT_NAME="HeyBean Voice Agent"
```

## Configure/update the ElevenLabs Agent

From `web/`:

```bash
npm run elevenlabs:agent-configure
```

The script creates or updates:

- ElevenLabs client tool `askBean`
- HeyBean Voice Agent prompt
- turn settings / interruption ignore terms / soft timeout
- the agent's tool binding

If no `ELEVENLABS_AGENT_ID` is present, the script creates a new agent and prints the id. Put that id in `.env` as `ELEVENLABS_AGENT_ID`, enable `ELEVENLABS_AGENT_ENABLED=true`, then cache Laravel config.

## Removed legacy voice paths

The app no longer uses:

- `/api/bean/realtime/session`
- OpenAI Realtime browser voice
- ElevenLabs Speech Engine Node bridge
- PM2 `bean-elevenlabs-voice-bridge`
- Nginx `/bean/elevenlabs/speech-engine/ws` proxy
- `/elevenlabs-voice-poc`
- `bean_voice_bridge_sessions`

The only retained local voice responsibility is wake-word detection before starting the ElevenLabs Agent session and authenticated execution of the `askBean` client tool.

## Verification

```bash
php artisan test
npm test
npm run build
php artisan route:list --path=bean/elevenlabs
```

Production smoke:

```bash
curl https://heybean.org/app
curl -H "Authorization: Bearer <token>" -X POST https://heybean.org/api/bean/elevenlabs/conversation-token
```

Live voice samples should be collected from the main app:

```text
https://heybean.org/app
```
