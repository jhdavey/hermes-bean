# Hermes Bean

Hermes Bean is a Hermes-first personal assistant product. Hermes is the core operating system; the Flutter app and Laravel dashboard are the friendly consumer interface around it.

## Authoritative voice contract

All Bean voice behavior, architecture, acceptance journeys, and release requirements are governed by [`bean-voice-rules.md`](bean-voice-rules.md). Read and update that contract whenever voice expectations change.

## Product thesis

The user talks to a powerful Hermes agent, and the agent owns a workspace it can read, write, and operate. The UI shows the agent doing work instead of hiding it behind generic chatbot responses.

## Semantic architecture

Hermes is the sole interpreter for Bean conversation, whether the request comes from voice, web chat, or Flutter chat. Hermes decides what the user means, whether a detail is missing, which typed operations are intended, how conversational references resolve, and what Bean says back. If meaning is unresolved, Hermes asks one focused follow-up instead of application code guessing.

Deterministic application code owns only trusted ingress and privacy gates, tool schemas, authorization and subscription checks, database/provider execution, idempotency, lifecycle, cancellation, deadlines, recovery, and durable response delivery. It does not route conversational prose, answer time/date locally, infer mutation targets or temporal values, split multi-clause requests, or repair Hermes output with phrase rules.

## MVP scope

Personal assistant only — no project management in the MVP.

Included for MVP:

- Native conversation sessions
- Tool execution visibility/progress
- Agent-owned dashboard state
- Calendar surface
- Tasks: todos, chores, maintenance
- Reminders
- Skills and memory awareness
- Scheduled/background jobs
- Human approval/blocker states

## Design direction

Use the same visual style as the old HeyBean app: light green/natural background palette, white and soft-green surfaces, green primary actions, rounded cards/inputs, subtle green glow/gradient backgrounds, and Material 3 polish. The UI should make Hermes feel visibly active with progress/action/activity states rather than hiding work behind plain chatbot responses.

Deferred until after MVP:

- Project management/Kanban for end users
- Household/social collaboration depth beyond basic personal-assistant objects
- Full marketplace/integration system
- Production mobile store release work

## Directory layout

- `app/` — Flutter mobile app / consumer command center
- `web/` — Laravel API + web dashboard + Hermes semantic runtime

## Runtime architecture decision

Laravel owns the app-facing runtime/session API and calls the configured OpenAI semantic interpreter through one Hermes adapter. Hermes owns understanding and conversational response composition; schema-validated application services own execution, safety, idempotency, durable lifecycle state, and the contract's narrow receipt-grounded CRUD confirmations. There is no local Hermes process or CLI runtime. Flutter remains a thin client for chat, approvals, dashboard views, notifications, and workspace state.

## Development

```bash
# Flutter
cd app
flutter test
flutter analyze

# Laravel
cd web
php artisan test
npm install --ignore-scripts
npm run build
```

### Browser voice runtime

Browser voice is governed by [`bean-voice-rules.md`](bean-voice-rules.md). It is enabled only with `BROWSER_VOICE_V2=true`; this is an admissions kill switch, not a user allowlist. Browser audio goes directly to one OpenAI Realtime call after local wake admission. A Laravel sideband on that same call owns Hermes tools, application execution, durable lifecycle, and response authorization; no separate transcription or HTTP text-to-speech path exists.

Production must continuously run `php artisan voice:realtime-sidebands`, at least three concurrent Laravel queue workers listening to the dedicated `voice-high` queue, and the Laravel scheduler. The sideband processes use durable per-session leases, so a bounded warm handoff may overlap safely without creating a second lifecycle owner.

The live site uses Forge zero-downtime releases. Its deployment-script editor must contain the exact recipe in [`scripts/forge-zero-downtime-deploy.template`](scripts/forge-zero-downtime-deploy.template); editing a release-local `/home/forge/heybean.org/deploy.sh` does not change Forge's stored recipe. The recipe prepares the newly cloned release, activates it, lets Forge signal its supervised queues, and only then runs `scripts/forge-deploy.sh activate` from the new `current` target. That post-activation command idempotently signals the old sideband/queue generations, launches and verifies one current-release sideband plus three `voice-high` workers, stores process metadata outside disposable release directories, and fails if a stale sideband survives the handoff. `scripts/forge-deploy.sh status` is the read-only process preflight. A Forge/Supervisor-managed sideband and worker group remains recommended before broader multi-user rollout; the repository-managed processes are the current single-owner live-test runtime.

Before enabling new voice admissions:

```bash
cd web
# Set BROWSER_VOICE_V2=true in the deployed environment first.
php artisan migrate --force
php artisan config:clear
php artisan config:cache
php artisan schedule:list
php artisan browser-voice:audit-invariants
npm run preflight:voice:production
npm run test:voice:all
php artisan test --filter='RealtimeVoice|BrowserVoiceProjection|HermesSemanticProtocol|ReceiptGroundedVoiceFinalizer'
```

Disabling the feature flag stops new browser-voice admissions. Recovery, delivery, and explicit cancellation remain available for work already admitted so rollback cannot strand a request.
