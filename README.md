# Hermes Bean

Hermes Bean is a Hermes-first personal assistant product. Hermes is the core operating system; the Flutter app and Laravel dashboard are the friendly consumer interface around it.

## Authoritative voice contract

All Bean voice behavior and voice implementation work is governed by [`bean-voice-rules.md`](bean-voice-rules.md). Read and update that contract whenever voice expectations change. The current browser-only replacement architecture and release gate are defined in [`browser-voice-implementation-spec.md`](browser-voice-implementation-spec.md).

## Product thesis

The user talks to a powerful Hermes agent, and the agent owns a workspace it can read, write, and operate. The UI shows the agent doing work instead of hiding it behind generic chatbot responses.

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
- `web/` — Laravel API + web dashboard + Hermes runtime bridge

## Runtime architecture decision

No separate runtime-manager service for the MVP.

Laravel should own the app-facing runtime/session API and call Hermes through a dedicated adapter/service layer. Flutter should remain a thin client for chat, approvals, dashboard views, notifications, and workspace state. If the Hermes process lifecycle becomes too complex later, we can extract a separate runtime-manager service from the Laravel adapter without changing the Flutter contract.

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

### Browser Voice v2 runtime

Browser voice is governed by [`bean-voice-rules.md`](bean-voice-rules.md); the implementation, automated gates, and remaining external certification are recorded in [`browser-voice-v2-release-evidence.md`](browser-voice-v2-release-evidence.md). It is enabled only with `BROWSER_VOICE_V2=true`; release certification is recorded as evidence and is not a second runtime gate or user allowlist. Production must run at least three concurrent Laravel queue-worker processes and the Laravel scheduler continuously. Three workers are required for the documented three-job voice concurrency; the server-side capacity policy cannot create concurrency when only one worker process exists. The scheduler enforces voice hard/no-progress deadlines every second independently of a potentially blocked request worker, so a once-per-minute cron without Laravel's sub-minute scheduler loop is not sufficient. Deployments must restart queue workers and interrupt any still-running sub-minute schedule loop so neither process continues executing the previous release; `scripts/forge-deploy.sh` does both.

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
php artisan test --filter=BrowserVoiceV2
```

Disabling the feature flag stops new Browser Voice v2 admissions. Recovery, delivery, and explicit cancellation remain available for work already admitted so rollback cannot strand a request.
