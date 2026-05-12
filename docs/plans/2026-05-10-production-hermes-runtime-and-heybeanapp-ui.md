# Production Hermes Runtime + HeyBeanApp UI Migration Plan

> **For Hermes:** Use subagent-driven-development skill to implement this plan task-by-task.

**Goal:** Convert Hermes Bean from a demo parser into a production-ready, multi-user, server-hosted Hermes assistant while reusing the previous HeyBeanApp layouts and calendar visual language.

**Architecture:** Flutter stays a thin, HeyBeanApp-styled command center. Laravel owns auth, tenancy, durable resources, approval state, and runtime orchestration. Each user receives a unique server-hosted Hermes agent/profile/settings bundle seeded from defaults; requests route through a model-router-ready runtime adapter that starts on GPT-5.5 but can later choose models per request.

**Tech Stack:** Flutter, Laravel 13, Forge, SQLite/Postgres-ready schema, Hermes Agent CLI/runtime profiles, structured JSON action schema.

---

## Locked decisions from Harley

- Runtime location: Forge/server-hosted; do not use Harley's local Mac Hermes agent.
- Tenancy: multi-user ready from the beginning.
- Agent identity: each user has a unique Hermes agent/profile/settings; default settings are only a seed.
- Model: GPT-5.5-style starting point, but model-router-ready for cheaper/specialized future routing.
- Permissions: non-risky requested actions may run automatically; destructive/risky actions, outgoing payments, and outgoing mail require confirmation.
- Calendar: internal Hermes Bean calendar only for now.
- Approval UX: app-only notification at the top of the home screen for now; push/Telegram notifications later.
- UI: mimic previous `heybeanapp` screens/layouts/calendar style unless Hermes Bean introduces a required feature.

## Production architecture target

```text
Flutter HeyBean-style app
  -> Laravel API auth/tenant/session boundary
  -> Runtime adapter resolves user's AgentProfile
  -> Server-hosted unique Hermes profile/home/settings
  -> GPT-5.5 initially through router abstraction
  -> Structured actions emitted by Hermes
  -> Laravel validates action risk + persists resources/events/approvals
  -> Flutter refreshes home/calendar/tasks/reminders/activity
```

## Structured action envelope

Hermes output must eventually conform to this shape, not plain text only:

```json
{
  "assistant_message": "I added workout to your calendar.",
  "actions": [
    {
      "type": "calendar_event.create",
      "risk": "low",
      "requires_approval": false,
      "payload": {
        "title": "workout",
        "starts_at": "2026-05-10T18:00:00Z",
        "ends_at": "2026-05-10T19:00:00Z"
      }
    }
  ]
}
```

Risk policy defaults:

- `low`: create/update internal tasks, reminders, internal calendar events, activity records.
- `medium`: large/bulk changes, external API calls, account settings changes.
- `high`: deletion/destructive changes, outgoing mail/messages, payments, purchases, deployments, private-file access outside allowed scope.
- `medium`/`high` actions create approval records and appear in the app notification banner before execution.

## Task 1: Backend tenant profile foundation

**Objective:** Add persistent per-user agent profiles and default settings.

**Files:**
- Create migration: `web/database/migrations/*_create_agent_profiles_table.php`
- Create model: `web/app/Models/AgentProfile.php`
- Modify: `web/app/Models/User.php`
- Modify: `web/app/Http/Controllers/Api/AuthController.php`
- Test: `web/tests/Feature/AgentProfileTest.php`

**Implementation details:**

- Add `agent_profiles` table with `user_id`, `slug`, `display_name`, `status`, `provider`, `model`, `router_mode`, `runtime_home`, `settings`, `tool_policy`, `approval_policy`, `metadata`.
- `user_id` unique; a user owns exactly one default profile for MVP.
- Seed defaults on registration.
- Include profile in `/api/auth/me` and `/api/today` responses.

**Verification:**

```bash
cd web
php artisan test --filter=AgentProfileTest
```

## Task 2: Runtime adapter contract

**Objective:** Stop treating stub parsing as the production contract.

**Files:**
- Create: `web/app/Services/AgentProfileService.php`
- Create: `web/app/Services/StructuredHermesActionService.php`
- Modify: `web/app/Services/HermesCliRuntimeService.php`
- Modify: `web/config/services.php`
- Test: `web/tests/Feature/HermesRuntimeContractTest.php`

**Implementation details:**

- Runtime start resolves the authenticated user's `AgentProfile`.
- Session `runtime_mode` should be `cli` for the current server-hosted adapter and later `server_hermes` when the persistent runtime manager lands; no parser fallback remains.
- Runtime payload includes user profile, current dashboard state, allowed action schema, and approval policy.
- CLI output parser should look for JSON envelope first, then plain-text fallback.

## Task 3: Structured actions + approval gating

**Objective:** Persist action results or approvals based on risk policy.

**Files:**
- Create model/migration if needed: `agent_actions`
- Modify existing `approvals` usage
- Test: `web/tests/Feature/StructuredActionExecutionTest.php`

**Implementation details:**

- Low-risk actions execute immediately into `tasks`, `reminders`, `calendar_events`, and `activity_events`.
- Risky/destructive actions create `approvals` with `payload.action` and do not execute until approved.
- Approval status appears in `/api/today`.

## Task 4: HeyBeanApp UI shell migration

**Objective:** Replace the current single-page command center with the previous HeyBeanApp shell style.

**Reference files:**
- `../heybeanapp/lib/core/theme/app_theme.dart`
- `../heybeanapp/lib/app/routes/main_shell_screen.dart`
- `../heybeanapp/lib/features/calendar/presentation/screens/calendar_screen.dart`

**Hermes Bean files:**
- Modify: `app/lib/main.dart`
- Add split files later when the file becomes unwieldy.

**Implementation details:**

- Use the same green/white gradient, glow, rounded cards, bottom nav dock, and central Bean/Hermes action button style.
- Home screen top area includes approval notification banner when pending approvals exist.
- Keep screens minimal at first: Home, Calendar, Tasks, Reminders, Activity, Settings/Account, Chat.

## Task 5: Internal calendar view parity

**Objective:** Make Hermes Bean calendar look and behave like HeyBeanApp's calendar views while backed by internal `calendar_events`.

**Implementation details:**

- Add day/month segmented switch.
- Day view: horizontal date strip + events grouped by selected day.
- Month view: grid with event count dots using HeyBeanApp styling.
- No Apple/Google calendar sync yet.

## Task 6: Deployment hardening

**Objective:** Prepare Forge to run server-hosted unique Hermes agents safely.

**Implementation details:**

- Decide server `HERMES_HOME` root, e.g. `/home/forge/heybean.org/hermes-users`.
- Runtime profiles live under user-specific directories or Hermes named profiles.
- Secrets stay server-side in `.env`/encrypted settings, not Flutter.
- Fail closed: if Hermes unavailable, create blocker and show app approval/error state, not fake success.

## Acceptance checks

- New registered user automatically has a unique `agent_profile`.
- `/api/today` includes profile + pending approvals.
- A pending approval can render at the top of the Flutter home screen.
- Calendar UI follows HeyBeanApp visual style.
- Stub runtime remains fallback only, not the declared production architecture.
- Backend tests and Flutter tests pass.
