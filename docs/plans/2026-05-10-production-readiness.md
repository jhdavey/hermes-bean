# Hermes Bean Production Readiness Implementation Plan

> **For Hermes:** Use subagent-driven-development skill to implement this plan task-by-task.

**Goal:** Move Hermes Bean from a scaffold/demo into a live API-driven personal-assistant MVP with auth, real Hermes runtime invocation, basic App Store readiness, security, account deletion, and an end-to-end daily-use flow.

**Architecture:** Laravel remains the durable API/workspace layer and owns authentication, user-scoped assistant data, account deletion, runtime invocation, audit/activity events, and compliance endpoints. Flutter becomes a live client with login/register, authenticated API calls, session/message/event polling, and real personal-assistant screens. Hermes runtime is invoked through a safe service boundary with timeout, environment-configured executable, captured output, and fail-safe blocker behavior.

**Tech Stack:** Laravel 13/PHP 8.3, SQLite-compatible migrations, bearer tokens stored hashed in database, Symfony Process or PHP proc_open for Hermes CLI invocation, Flutter/Dart Material 3 using old HeyBean design language.

---

## Task 1: Backend auth, user ownership, and account deletion

**Objective:** Add production-grade MVP auth without adding a heavy external dependency: register/login/logout/me/delete/export endpoints; hashed bearer tokens; user ownership on all assistant records; and account deletion that removes user data.

**Files:**
- Modify: `web/database/migrations/0001_01_01_000000_create_users_table.php`
- Create: `web/database/migrations/2026_05_10_000002_add_user_scope_and_api_tokens.php`
- Modify: `web/app/Models/User.php`
- Create: `web/app/Models/PersonalAccessToken.php`
- Create: `web/app/Http/Middleware/AuthenticateBearerToken.php`
- Create: `web/app/Http/Controllers/Api/AuthController.php`
- Modify: `web/routes/api.php`
- Modify: existing assistant models/controllers to require authenticated user and scope queries by `user_id`
- Test: `web/tests/Feature/AuthAndAccountLifecycleTest.php`

**Steps:**
1. Write failing tests for register/login/me/logout/delete/export and user data isolation.
2. Add hashed token table and middleware.
3. Require auth for assistant/domain routes.
4. Assign `user_id` on create and scope all route model lookups.
5. Implement account deletion: revoke tokens, delete assistant data, anonymize or delete user.
6. Verify `php artisan test`.
7. Commit `feat: add auth and account lifecycle`.

## Task 2: Real Hermes runtime adapter

**Objective:** Replace stub-only behavior with a production-shaped Hermes CLI adapter while retaining a safe local fallback and blocker behavior.

**Files:**
- Create: `web/app/Services/HermesCliRuntimeService.php`
- Modify: `web/app/Providers/AppServiceProvider.php`
- Modify: `web/config/services.php`
- Modify: `web/.env.example`
- Modify: `web/app/Services/HermesRuntimeService.php` if contract needs runtime metadata
- Test: `web/tests/Feature/HermesCliRuntimeServiceTest.php`

**Steps:**
1. Write failing tests using a fake executable/script or injected command runner.
2. Add env config: `HERMES_RUNTIME_MODE=cli`, `HERMES_CLI_PATH`, timeout, workdir, profile.
3. Implement CLI invocation with timeout, sanitized environment, no shell interpolation, captured stdout/stderr.
4. Persist user message, assistant message, `runtime.hermes_cli_started`, `runtime.hermes_cli_completed` or `runtime.hermes_cli_failed` events.
5. On missing CLI, timeout, or non-zero exit, create blocker instead of generic response.
6. Keep tests deterministic; do not require live external model calls in CI.
7. Commit `feat: add Hermes CLI runtime adapter`.

## Task 3: Live Flutter API-driven screens

**Objective:** Replace static-only state with live auth/session/message/event flows while preserving old HeyBean styling.

**Files:**
- Modify: `app/lib/hermes_api_client.dart`
- Modify: `app/lib/main.dart`
- Create supporting state/controller files if useful under `app/lib/`
- Test: `app/test/hermes_api_client_test.dart`, `app/test/widget_test.dart`

**Steps:**
1. Write failing tests for auth methods and UI live loading/send behavior with fake API client.
2. Add client methods: register, login, logout, me, delete account, export account, list/create tasks/reminders/calendar as needed.
3. Add auth/token state and screen states: signed-out, loading, signed-in.
4. Chat send calls backend and refreshes events.
5. Today/Tasks/Reminders/Calendar/Activity render data from API models, with graceful offline/demo fallback.
6. Add account settings with delete-account path visible for App Store compliance.
7. Verify `flutter test` and `flutter analyze`.
8. Commit `feat: connect Flutter app to live API`.

## Task 4: First real daily personal-assistant flow

**Objective:** Implement the first daily-use loop: daily planning chat creates/reminds/schedules, shows Today summary, activity grounding, approvals/blockers, and account-owned data.

**Files:**
- Backend controllers/services/tests as needed
- Flutter UI/state/tests as needed
- Docs: `docs/daily-assistant-flow.md`

**Steps:**
1. Write backend feature test for an authenticated user: start session, ask daily planning prompt, create task/reminder/calendar event, inspect Today summary, activity feed, blocker/approval.
2. Write Flutter widget/client test for signed-in daily flow using fake API.
3. Implement minimum endpoints/UI required to pass.
4. Document the flow with curl and app run steps.
5. Commit `feat: add daily assistant flow`.

## Task 5: App Store, privacy, and security readiness

**Objective:** Add MVP production readiness artifacts: privacy policy skeleton, account deletion support, transport/security notes, release checklist, App Store metadata checklist, and security headers/cors/rate-limiting basics.

**Files:**
- Create: `docs/app-store-readiness.md`
- Create: `docs/privacy-and-security.md`
- Modify: `web/bootstrap/app.php` or route middleware as needed
- Modify: `web/config/*` as needed
- Test: `web/tests/Feature/SecurityReadinessTest.php`

**Steps:**
1. Write tests for auth-required routes, rate limiting, no cross-user data, delete-account endpoint availability.
2. Add API rate limiting and JSON error shape.
3. Add docs covering privacy policy inputs still needed from product owner, account deletion UX, data retention, encryption/HTTPS requirement, minimal permissions, App Store Sign in with Apple decision, support URL placeholders.
4. Verify tests.
5. Commit `docs: add App Store and security readiness`.

## Final verification

Run:

```bash
cd web && php artisan test
cd ../app && flutter test && flutter analyze
cd .. && git status --short && git push origin main
```

Final review must explicitly check:
- No unauthenticated access to user data.
- Account deletion is available in API and Flutter UI.
- Live Flutter screens can use authenticated API client.
- Real Hermes runtime path exists and is safe/fail-closed.
- App Store readiness docs identify remaining owner-provided URLs/legal copy.
