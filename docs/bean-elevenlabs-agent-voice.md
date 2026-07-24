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
  -> explicit visitor tap on the Bean button
  -> browser reminds the visitor to turn volume on and allow mic access
  -> /bean/landing/conversation-token (session + CSRF + rate limited)
  -> dedicated ElevenLabs Landing Guide agent
  -> first spoken turn: “Hey, can you hear me?”
  -> after the visitor confirms yes, Bean introduces itself once, asks whether they prefer light or dark mode, and does not move the page
  -> visitor theme choice is applied to the public landing page, stored locally, carried into signup, and saved as the new account theme preference
  -> Agent answers directly with GPT-4.1 Nano and public product/pricing facts
  -> optional action-only client tool: showLandingSection({ destination }); the browser suppresses same-turn hearing-check movement defensively but clears suppression on the next visitor transcript; pricing/cost/plan transcripts scroll to the pricing section immediately and de-dupe the later pricing tool call
  -> explicit signup agreement asks the browser to wait for Bean’s spoken transition sentence to finish, then fade the landing page into the embedded Zero Chrome signup flow, update the URL to /register?from=bean, and keep the same Bean DOM presence mounted while asking for first/last name without repeating “Hi, I’m Bean”; the browser also backs this up by recognizing the exact transition sentence if the hosted Agent speaks it but fails to invoke the section tool
  -> no authenticated dashboard plugin, account data, private tools, or Hermes turn on the voice hot path
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

The landing guide is disabled on every page load and requires an explicit visitor tap before requesting microphone access. The public landing flow does not require a separate wake phrase or preset choice chips: tapping Bean starts the short ConvAI/WebRTC session after browser mic permission, then Bean performs a “can you hear me?” check before answering questions, giving a short conversational tour, or opening onboarding when the visitor agrees to start signup. Landing cost/abuse controls are configured with `BEAN_LANDING_*` environment variables documented in `web/.env.example`.

## Voice ownership and cost controls

Browser voice should remain provider-owned after wake detection:

- Local browser code owns only privacy state, microphone permission, app voice wake detection, public landing tap-to-start onboarding, event logging, the authenticated `askBean` client-tool bridge, and the public landing `showLandingSection` UI action bridge.
- ElevenLabs owns realtime STT/TTS, turn-taking, interruptions, backchannels, silence, and follow-ups.
- Laravel owns conversation tokens, auth/session/rate limits, usage metering, the Bean runtime bridge, and dashboard/tool safety.
- Hermes owns reasoning, memory, tool choice, and final wording for authenticated Bean requests.

Authenticated app voice is intentionally short-lived but should not cut off natural speech:

- ElevenLabs Agent `maxDurationSeconds` defaults to `60`.
- Authenticated Agent initial wait defaults to `5` seconds so first-turn health checks such as “can you hear me?” do not sit in a long silence window.
- Authenticated Agent turn eagerness defaults to `eager` while still leaving ElevenLabs in charge of turn-taking.
- Authenticated Agent silence-end-call timeout defaults to `15` seconds.
- The browser client closes abandoned first-turn sessions after about `9` seconds.
- Long authenticated `askBean` tool calls hand off after about `10` seconds: ElevenLabs speaks a short background handoff, the app waits a minimum spoken window before closing that session, Laravel/Hermes continues the work, then the app opens a normal new Agent session and sends `BACKGROUND_RESULT_DELIVERY: ...` via `sendUserMessage` so Bean speaks the final result and keeps listening for follow-up.
- The browser client gives about a `15` second post-response follow-up grace while keeping the total Agent session capped at `60` seconds.

Public landing voice remains tighter by default because it is unauthenticated:

- Landing max duration defaults to `60` seconds.
- Landing first message is the hearing check only; the confirmation response introduces Bean once and asks for light/dark preference.
- Landing initial wait defaults to `5` seconds.
- Landing silence-end-call timeout defaults to `8` seconds unless production env overrides it higher.
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

The landing command creates or updates a separate action-only `showLandingSection` client tool and public voice agent. If no `ELEVENLABS_LANDING_AGENT_ID` is present, save the printed id in `.env` under that name before caching Laravel config. The public voice prompt is populated with current public product/pricing facts at configure time and deliberately has no `bean_dashboard` tool, authenticated account access, or Hermes turn on the voice hot path.

The landing command also applies provider-side controls: authentication-only conversation tokens, GPT-4.1 Nano without a reasoning layer, a concurrency and daily call cap with bursting disabled, focus and prompt-injection guardrails, no voice recording, and zero-day transcript/audio retention. Laravel independently applies session, IP, global, message, and per-conversation turn limits. Configure both Cloudflare Turnstile keys to require a managed bot challenge before Laravel mints an ElevenLabs token.

The public guide can return only allowlisted browser actions: `command_center`, `calendar_tasks`, `customization`, `features`, `pricing`, `signup`, `onboarding`, `how_it_works`, and the local `setLandingTheme` light/dark preference action. Laravel strips fallback `[[BEAN_UI:...]]` markers from spoken answers. The ElevenLabs client-tool destination provides structured voice movement when the landing model calls `showLandingSection`. The browser scrolls to matching public sections; `signup`/`onboarding` are the only conversion actions and start the embedded landing-page signup transition to `/register?from=bean` without a hard page reload. This is the UI-action boundary for public guided-tour behavior and does not allow arbitrary selectors or URLs.

## Removed legacy voice paths

The app no longer uses:

- `/api/bean/realtime/session`
- OpenAI Realtime browser voice
- ElevenLabs Speech Engine Node bridge
- PM2 `bean-elevenlabs-voice-bridge`
- Nginx `/bean/elevenlabs/speech-engine/ws` proxy
- `/elevenlabs-voice-poc`
- `bean_voice_bridge_sessions`

Authenticated app voice retains local wake-word detection before starting the ElevenLabs Agent session. Public landing voice uses explicit tap-to-start onboarding instead. Authenticated execution of the `askBean` client tool remains a local browser responsibility.

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
