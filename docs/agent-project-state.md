# HeyBean Agent Project State

Last updated: 2026-07-24T21:21:45Z

Purpose: give future Hermes/agent sessions a compact, durable starting point for HeyBean work without relying on chat context. Read this file before coding when the task touches Bean, HeyBean, voice UX, runtime/tooling, deployment, or project direction.

## Current repository/deploy state

- Local project path: `/Users/joshuadavey/development/projects/hermes-bean`
- Main branch behavior at last update: user-level Bean runtime continuity plus unified multi-workspace assignment controls for web and Flutter.
- Local working tree at last update was clean after the Bean runtime/workspace-assignment release.
- Production host/path: `forge@heybean.org:/home/forge/heybean.org/current`
- Production deployed 2026-07-24: user-level Bean runtime continuity and unified Personal-first workspace assignment controls, alongside the existing persistent landing-to-signup Bean continuation.
- Production known server-only state: untracked `.env`, `storage`, `web/storage`; do not clean production storage/env casually.
- Production Bean voice timing last verified in prior work: authenticated `60/5/10/15/15` = max duration / provider soft timeout / background handoff / silence end-call / follow-up idle close. Public landing/signup voice uses a separate fast ElevenLabs Landing Guide that answers directly with public facts, uses a 30s silence-end default so users can type signup fields without the session dropping, and only calls action/client tools.
- Public landing Bean onboarding is single-path tap-to-wake: visitors click/tap the centered hero Bean above the feature icons. The public landing page has a light/dark toggle next to Login, dark-mode styling, and the visitor's public theme choice is stored locally, carried into inline signup, and saved as the account `theme_mode` during registration. The public top bar uses a Bean icon + `Bean` brand at the left, centered nav links, no nav CTA, and `Login` on the far right. The hero Bean control is icon-only with no circular listening/ring indicator; the horizontal hero tool row only shows Calendar, Tasks, Notes, and Reminders. While the landing page is at the hero top, Bean stays centered above the hero icons; as the visitor scrolls, Bean travels upward with its original page position, then shrinks into the compact top-centered presence with a white backdrop only after reaching the top dock. Pricing/plan anchor movement now leaves the pricing heading and plan names below that backdrop. Public/register Bean has inline critical sizing plus explicit image dimensions so the logo never paints at its natural 790px size before bundled CSS loads. Bean-led signup from landing no longer hard-reloads `/register`: public CTAs and the Landing Guide signup/onboarding tool fade the landing page into the embedded Zero Chrome signup flow from any scroll position, force the view back to the signup top, update the URL to `/register...`, and keep the same Bean DOM instance mounted as the signup Bean. Public landing CTAs say `Try it for free`; the hero headline is `Hi! I'm Bean. Your new assistant!`. A plain hearing-check confirmation such as “yes” should only get `Great — I’m Bean. I can give you a quick tour or answer questions.` and must not move/scroll the page; the browser also defensively suppresses any same-turn section tool call that follows a plain hearing-check confirmation, then clears that suppression on the next user transcript so immediate follow-up requests like “give me the tour” or “yes, start signup” still move. Pricing voice questions should show the pricing/plans area immediately when the visitor asks, before Bean starts or while it starts talking prices; the browser defensively scrolls on the visitor pricing transcript and suppresses duplicate pricing tool scrolls from the hosted Agent. Bean should not push signup while answering pricing/features; it starts inline signup only when the visitor explicitly agrees to start or try signup. For voice-led signup, the browser waits a minimum spoken window and for ElevenLabs to leave speaking mode before fading into inline signup, then gracefully stops the public voice session after the signup view is visible so Bean does not cut off its own transition sentence. The browser also treats Bean’s exact signup transition sentence as a backup trigger, because the hosted Landing Guide can sometimes say the line without invoking the section tool; this still respects the same delayed fade timing.
- `/register` now follows the Zero Chrome contract: no register banner/top utility chrome/cards/progress rails/chat trail by default; visible onboarding elements are only the top-centered hovering Bean icon, tiny `Tap Bean for voice · volume on · allow mic` text, Bean’s current step message, relevant step controls, and a minimal line input/send affordance. Typed private values are never replayed in visible history or voice progress prompts.
- Signup/private account fields are deterministic text-only: name, theme, email, and password do not keep an ongoing ElevenLabs voice session and do not dispatch signup-progress prompts. The same visual public signup Bean remains hovering through these fields; tapping it during private fields only focuses/highlights the current input. After admitted account creation, the same Bean presence re-enters with `Alright, your account is created...` and offers quick tour, first action, or dive in as a Zero Chrome choice panel: no modal/card chrome, no duplicate Bean icon, and simple centered options under Bean; the normal authenticated dashboard Bean is suppressed during this connected onboarding conversation and the public signup Bean is removed when deferred paywall/subscription flow opens. Waitlist/capacity still stops before dashboard tour/paywall. Dashboard tour step text/buttons are fixed near Bean rather than flowing below the dashboard or anchoring to oversized target rectangles.
- Flutter guided signup mirrors the Zero Chrome web structure: top-centered Bean, tiny mic/volume copy, current Bean message, minimal line input, no card/topbar/progress/helper chrome. Keep web and Flutter onboarding visually matched when changing either surface.

## Product/runtime direction

- HeyBean is a consumer AI assistant/product with Laravel-owned Bean runtime boundaries and a web-first UX direction, later Flutter.
- Laravel owns auth/safety boundary, scoped app/dashboard tools, TimeContext normalization, instrumentation, usage metering, and UI mirroring.
- Hermes/model owns conversation, memory, reasoning, tool choice, and final wording.
- Bean should preserve conversational context for app-data follow-ups; do not answer unrelated generic time/date facts when prior context implies a resource query.
- Public landing Bean remains isolated from private dashboard/app tools. Public landing voice should use the fast ElevenLabs Landing Guide directly for bounded product/pricing/theme questions; do not put Hermes on that voice hot path unless the scope becomes private/account-specific.
- Authenticated Bean can use scoped dashboard/resource actions through the Laravel-owned tool bridge.
- Authenticated Bean is user-level rather than workspace-level: every chat session is anchored to the user's personal workspace and all chats share the user's one Hermes home and durable memory. Reads span every accessible workspace by default; shared-workspace mutations explicitly target a workspace by id or exact name. Dashboard workspace switching never selects or creates a different Bean agent.
- Assignable task, reminder, event, and note creation/editing surfaces use one simple workspace checkbox section in web and Flutter. Personal is preselected on creation without being labeled as a default. The active dashboard workspace is labeled `current` but is not auto-selected unless it is Personal. Editing preserves and locks the item's existing primary copy while allowing its other workspace copies to be added or removed.

## Voice UX direction

- Production browser voice uses ElevenLabs Agent/Conversational AI for realtime STT/TTS/turn-taking.
- Authenticated app browser wake phrase is local: “Hey Bean”. Local wake should activate capture but should not become a brittle parallel command brain. Public landing Bean intentionally uses tap-to-start instead of wake detection to simplify first-time visitor success.
- ElevenLabs should own voice turn-taking; avoid client-side fallback layers that duplicate provider responsibility.
- Credit/cost controls should not make Bean miss speech. Prefer metering, quotas, admin visibility, and a max session safety cap over rushed idle windows.
- Current intended authenticated voice timing: max session `60s`, initial wait `5s`, silence end-call `15s`, follow-up idle `15s`, with eager ElevenLabs turn-taking.
- Wake-tail fragments like “what’s/can/spec” should not submit as commands. Prefer stable/full provider transcript or provider-native final turn behavior.
- Long voice tool calls now use a 5s provider soft timeout and 10s background handoff. The handoff session is kept open long enough to finish speaking before closure; the final result returns in a fresh ElevenLabs session via `sendUserMessage('BACKGROUND_RESULT_DELIVERY: ...')`, not `overrides.agent.firstMessage`.

## Weather/external tools state

- `external.weather` exists as a first-class Bean dashboard action backed by Open-Meteo forecast/geocoding.
- It supports coordinates, explicit named locations, and recovery of named locations from the original Bean run input before falling back to browser/session coordinates.
- Open-Meteo requires no API key.
- Generic external lookup remains separate as `external.lookup`.

## Development guardrails

- Load/apply `clean-scalable-shipping` for production work, regressions, UX fixes, and architecture decisions.
- Prefer targeted rollback/simplification over patches when a regression follows a prior change.
- Avoid phrase-specific fast paths, brittle heuristics, duplicate runtime brains, and workaround stacking.
- Do not add a deterministic local Bean brain or hard-coded semantic domains.
- Private dashboard facts must be grounded in `bean_dashboard` tool results.
- Never expose secrets or raw internal runtime/tool/provider errors to users.

## Standard bootstrap for future sessions

1. Read this file.
2. Read `AGENTS.md`.
3. For architecture/runtime work, read:
   - `docs/bean-ai-architecture.md`
   - `docs/bean-model-routing.md`
   - `docs/bean-action-schema.md`
4. For voice work, read `docs/bean-elevenlabs-agent-voice.md`.
5. For UX benchmark/scenario work, read `docs/bean-ux-evaluation-scenarios.md` and run:
   ```bash
   cd web
   php artisan bean:ux-benchmark --days=7
   php artisan bean:ux-evaluate-scenarios --recent=500
   ```
6. Check local git state:
   ```bash
   git status --short --branch
   git log -5 --oneline
   ```
7. If production state matters, verify it read-only before assuming:
   ```bash
   ssh -o BatchMode=yes forge@heybean.org 'cd /home/forge/heybean.org/current && git status --short --branch && git log -3 --oneline'
   ```
8. After deploying, verify the deployed commit/config and update this file when the durable architecture or deploy state changes.

## Verification expectations

Typical web/backend changes:

```bash
cd web
php artisan test
npm test
npm run build
git diff --check
```

Voice/runtime changes usually need focused regression tests plus production-safe smoke/benchmark after deploy.

Flutter-facing changes:

```bash
cd app
flutter analyze
flutter test
```

Deployment expectations:

- Push `origin/main`.
- Verify production `HEAD` equals expected commit.
- Run deploy script or Forge-safe deploy path.
- Verify app health and relevant runtime config.
- Do not run destructive production cleanup against `.env` or storage.

## Update policy

Update this file when:

- Product/runtime direction changes.
- Voice/provider/tool architecture changes.
- A significant deploy changes production behavior.
- A new recurring pitfall or rollback lesson is discovered.
- Verification/deploy commands change.

Keep it compact: durable state only, not a full changelog. Commit/push updates with the work they describe when practical.
