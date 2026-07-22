# Bean ElevenLabs Agent Voice

Bean voice uses ElevenLabs Agent / Conversational AI for the realtime browser conversation layer. OpenAI remains available to Hermes for Bean reasoning; the browser voice path does not use OpenAI Realtime or a custom ElevenLabs Speech Engine bridge.

## Runtime shape

```text
Authenticated HeyBean web app
  -> local “Hey Bean” wake detection after the user enables listening
  -> /api/bean/elevenlabs/conversation-token (authenticated Laravel)
  -> ElevenLabs Agent WebRTC conversation
  -> ElevenLabs owns STT/TTS, turn-taking, interruptions, backchannels, and silence handling
  -> Agent calls client tool: askBean({ message })
  -> Browser client tool calls /api/bean/messages with the authenticated user's token
  -> Laravel BeanRuntimeService / per-user Hermes / bean_dashboard tools
  -> Tool result returns to ElevenLabs Agent, which speaks the response
```

Public Laravel pages use a separate boundary:

```text
Public landing / pricing / legal page
  -> explicit visitor click to enable mic
  -> local “Hey Bean” wake detection
  -> /bean/landing/conversation-token (session + CSRF + rate limited)
  -> dedicated ElevenLabs Landing Guide agent
  -> Agent calls client tool: askLandingBean({ message, destination })
  -> /bean/landing/messages
  -> isolated per-browser Hermes home with the heybean-guide skill only
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

The landing guide is disabled on every page load and requires an explicit visitor click before requesting microphone access. Enabling the mic starts only local wake detection; ElevenLabs usage begins when the wake phrase starts a ConvAI/WebRTC session. Landing cost/abuse controls are configured with `BEAN_LANDING_*` environment variables documented in `web/.env.example`.

## Voice ownership and cost controls

Browser voice should remain provider-owned after wake detection:

- Local browser code owns only privacy state, microphone permission, wake detection, event logging, and the `askBean`/`askLandingBean` client-tool bridge.
- ElevenLabs owns realtime STT/TTS, turn-taking, interruptions, backchannels, silence, and follow-ups.
- Laravel owns conversation tokens, auth/session/rate limits, usage metering, the Bean runtime bridge, and dashboard/tool safety.
- Hermes owns reasoning, memory, tool choice, and final wording for authenticated Bean requests.

Authenticated app voice is intentionally short-lived but should not cut off natural speech:

- ElevenLabs Agent `maxDurationSeconds` defaults to `60`.
- Authenticated Agent initial wait defaults to `5` seconds so first-turn health checks such as “can you hear me?” do not sit in a long silence window.
- Authenticated Agent turn eagerness defaults to `eager` while still leaving ElevenLabs in charge of turn-taking.
- Authenticated Agent silence-end-call timeout defaults to `15` seconds.
- The browser client closes abandoned first-turn sessions after about `9` seconds.
- The browser client gives about a `15` second post-response follow-up grace while keeping the total Agent session capped at `60` seconds.

Public landing voice remains tighter by default because it is unauthenticated:

- Landing max duration defaults to `60` seconds.
- Landing initial wait defaults to `5` seconds.
- Landing silence-end-call timeout defaults to `5` seconds.
- Landing max meaningful visitor turns defaults to `20`.
- Landing browser/session/IP/global rate limits are enforced before minting conversation tokens.

A conversation longer than the max duration is not blocked at the app level, but it becomes multiple voice sessions: after the Agent session reaches the cap, the user must wake Bean again to continue. That preserves a cost safety cap without creating a brittle parallel client-side command brain.

Usage is persisted in `bean_usage_records`:

- `provider=elevenlabs`, `usage_type=voice_session`: elapsed seconds between `voice_session_started` and `voice_session_closed`, estimated credits, and estimated USD cost. Product-app records use `source=elevenlabs_agent`; public landing records use `source=landing_page` and `service=landing_conversational_ai_agent` so admin can separate landing usage from signed-in app usage.
- `provider=openai`, `usage_type=llm_tokens`: Bean/Hermes run token estimates and estimated USD cost. These are marked `is_estimate=true` because Hermes does not currently expose exact provider token usage back to Laravel.

The admin dashboard summary exposes `ai_usage.today`, `ai_usage.week`, and `ai_usage.month` with OpenAI tokens/cost, ElevenLabs voice seconds/minutes/credits/cost, source breakdowns, and top users.

## Configure/update the ElevenLabs Agents

From `web/`:

```bash
npm run elevenlabs:agent-configure
npm run elevenlabs:landing-agent-configure
```

The authenticated command creates or updates:

- ElevenLabs client tool `askBean`
- HeyBean Voice Agent prompt
- turn settings / interruption ignore terms / soft timeout
- the agent's tool binding

If no `ELEVENLABS_AGENT_ID` is present, the script creates a new agent and prints the id. Put that id in `.env` as `ELEVENLABS_AGENT_ID`, enable `ELEVENLABS_AGENT_ENABLED=true`, then cache Laravel config.

The landing command creates or updates a separate `askLandingBean` client tool and public voice agent. If no `ELEVENLABS_LANDING_AGENT_ID` is present, save the printed id in `.env` under that name before caching Laravel config. The public runtime deliberately has no `bean_dashboard` tool and cannot access authenticated data.

The landing command also applies provider-side controls: authentication-only conversation tokens, GPT-4.1 Nano without a reasoning layer, a concurrency and daily call cap with bursting disabled, focus and prompt-injection guardrails, no voice recording, and zero-day transcript/audio retention. Laravel independently applies session, IP, global, message, and per-conversation turn limits. Configure both Cloudflare Turnstile keys to require a managed bot challenge before Laravel mints an ElevenLabs token.

The public Hermes guide can return only two allowlisted browser actions: `features` and `pricing`. Laravel strips the silent action marker from the spoken answer. The required ElevenLabs client-tool destination (`none`, `features`, or `pricing`) provides a structured voice fallback if the landing model omits its marker. The browser scrolls to `#features` or `#plans` when that section exists; cross-page pricing/features requests navigate to `/pricing#plans` or `/#features` only after Bean finishes speaking. This is the UI-action boundary for public guided-tour behavior and does not allow arbitrary selectors or URLs.

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
cd web
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
