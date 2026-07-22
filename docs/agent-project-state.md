# HeyBean Agent Project State

Last updated: 2026-07-22T02:20:54Z

Purpose: give future Hermes/agent sessions a compact, durable starting point for HeyBean work without relying on chat context. Read this file before coding when the task touches Bean, HeyBean, voice UX, runtime/tooling, deployment, or project direction.

## Current repository/deploy state

- Local project path: `/Users/joshuadavey/development/projects/hermes-bean`
- Main branch status at last update: `main...origin/main`, clean locally.
- Latest local/main commit at last update: `3bd22b28 Speed up landing Bean and add homepage pricing`
- Production host/path: `forge@heybean.org:/home/forge/heybean.org/current`
- Production commit at last update: `3bd22b28 Speed up landing Bean and add homepage pricing`
- Production known server-only state: untracked `.env`, `storage`, `web/storage`; deleted tracked `web/storage/**/.gitignore` paths show in production status and should not be cleaned casually.
- Production Bean voice timing at last update: `60/9/15/15` = max duration / ElevenLabs initial wait / silence end-call / follow-up idle close.

## Product/runtime direction

- HeyBean is a consumer AI assistant/product with Laravel-owned Bean runtime boundaries and a web-first UX direction, later Flutter.
- Laravel owns auth/safety boundary, scoped app/dashboard tools, TimeContext normalization, instrumentation, usage metering, and UI mirroring.
- Hermes/model owns conversation, memory, reasoning, tool choice, and final wording.
- Bean should preserve conversational context for app-data follow-ups; do not answer unrelated generic time/date facts when prior context implies a resource query.
- Public landing Bean remains isolated from private dashboard/app tools.
- Authenticated Bean can use scoped dashboard/resource actions through the Laravel-owned tool bridge.

## Voice UX direction

- Production voice uses ElevenLabs Agent/Conversational AI for browser realtime/STT/TTS/turn-taking.
- Browser wake phrase is local: “Hey Bean”. Local wake should activate capture but should not become a brittle parallel command brain.
- ElevenLabs should own voice turn-taking; avoid client-side fallback layers that duplicate provider responsibility.
- Credit/cost controls should not make Bean miss speech. Prefer metering, quotas, admin visibility, and a max session safety cap over rushed idle windows.
- Current intended voice timing: max session `60s`, initial wait `9s`, silence end-call `15s`, follow-up idle `15s`.
- Wake-tail fragments like “what’s/can/spec” should not submit as commands. Prefer stable/full provider transcript or provider-native final turn behavior.

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
3. For Bean UX work, read:
   - `docs/bean-world-class-ux-goal.md`
   - `docs/bean-world-class-ux-progress.json`
   - `docs/bean-ux-evaluation-scenarios.md`
   - `docs/bean-voice-live-sample-harness.md` for voice work
4. Check local git state:
   ```bash
   git status --short --branch
   git log -5 --oneline
   ```
5. If production state matters, verify it read-only before assuming:
   ```bash
   ssh -o BatchMode=yes forge@heybean.org 'cd /home/forge/heybean.org/current && git status --short --branch && git log -3 --oneline'
   ```
6. For meaningful Bean UX changes, run/update the relevant benchmark:
   ```bash
   cd web
   php artisan bean:ux-benchmark --days=7
   ```
7. After deploying, verify the deployed commit/config and update this file.

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
