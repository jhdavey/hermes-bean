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

Public Laravel pages use a separate boundary:

```text
Public landing / pricing / legal page
  -> local “Hey Bean” wake detection after the visitor taps to enable
  -> /bean/landing/conversation-token (session + CSRF + rate limited)
  -> dedicated ElevenLabs Landing Guide agent
  -> Agent calls client tool: askLandingBean({ message })
  -> /bean/landing/messages
  -> isolated per-browser Hermes home with the heybean-guide skill
  -> no authenticated dashboard plugin, account data, or private tools
```

## Required env

```bash
ELEVENLABS_API_KEY=...
ELEVENLABS_AGENT_ENABLED=true
ELEVENLABS_AGENT_ID=agent_...
ELEVENLABS_LANDING_AGENT_ID=agent_...
# optional
ELEVENLABS_AGENT_ENVIRONMENT=
ELEVENLABS_AGENT_BRANCH_ID=
ELEVENLABS_AGENT_NAME="HeyBean Voice Agent"
ELEVENLABS_LANDING_AGENT_NAME="HeyBean Landing Guide"
ELEVENLABS_LANDING_LLM=gpt-4.1-nano
CLOUDFLARE_TURNSTILE_SITE_KEY=...
CLOUDFLARE_TURNSTILE_SECRET_KEY=...
```

The landing guide is disabled on every page load and requires an explicit visitor click before requesting microphone access. Its default cost and abuse envelope is an eight-minute conversation with at most 20 meaningful visitor turns, 3 sessions per browser per hour, 6 per browser per day, and 150 landing sessions globally per day. All values are configurable through the `BEAN_LANDING_*` environment variables documented in `.env.example`.

## Configure/update the ElevenLabs Agent

From `web/`:

```bash
npm run elevenlabs:agent-configure
npm run elevenlabs:landing-agent-configure
```

The script creates or updates:

- ElevenLabs client tool `askBean`
- HeyBean Voice Agent prompt
- turn settings / interruption ignore terms / soft timeout
- the agent's tool binding

If no `ELEVENLABS_AGENT_ID` is present, the script creates a new agent and prints the id. Put that id in `.env` as `ELEVENLABS_AGENT_ID`, enable `ELEVENLABS_AGENT_ENABLED=true`, then cache Laravel config.

The landing command creates or updates a separate `askLandingBean` client tool and public voice agent. If no `ELEVENLABS_LANDING_AGENT_ID` is present, save the printed id in `.env` under that name before caching Laravel config. The public runtime deliberately has no `bean_dashboard` tool and cannot access authenticated data.

The landing command also applies the provider-side controls: authentication-only conversation tokens, GPT-4.1 Nano without a reasoning layer, a concurrency and daily call cap with bursting disabled, focus and prompt-injection guardrails, no voice recording, and zero-day transcript/audio retention. Laravel independently applies session, IP, global, message, and per-conversation turn limits. Configure both Cloudflare Turnstile keys to require a managed bot challenge before Laravel mints an ElevenLabs token.

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
