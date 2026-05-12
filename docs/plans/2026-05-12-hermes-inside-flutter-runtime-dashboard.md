# Hermes Inside Flutter Runtime Dashboard Implementation Plan

> **For Hermes:** Use subagent-driven-development skill to implement this plan task-by-task.

**Goal:** Remove all stub/demo runtime behavior and make the Flutter app feel like the real Hermes chat dashboard: the user sends a request, gets the actual Hermes response, and sees simple chat-level run state while the request is in flight.

**Architecture:** Laravel remains the server-side owner of auth, tenancy, Hermes CLI invocation, app state, and fail-closed safety. Flutter stays a thin client: it sends chat messages, renders assistant/user messages, shows a lightweight run-state indicator at the top of chat, and refreshes dashboard resources after responses. Do not add runtime-run tables, live tool-call streams, or tool timelines for this version.

**Tech Stack:** Laravel API, existing `HermesRuntimeService` / `HermesCliRuntimeService`, existing conversation/session/message/activity/approval/blocker models, Flutter chat/dashboard UI.

---

## Current product decisions

- No stub parser, no local demo loop, no fake assistant success paths.
- No new `runtime_runs` records.
- No live SSE/WebSocket stream of tool calls/thinking.
- No full tool-call UI/cards in the app.
- No special approval/blocker UI beyond normal assistant response/dashboard state for now.
- Flutter should show actual assistant responses to user requests.
- Approvals/blockers should be returned/explained in natural language by Hermes/backend response where possible.
- Chat should show a simple run-state/status at the top: idle, sending, waiting/blocked, failed, completed.
- Backend should keep fail-closed behavior when Hermes CLI is missing, times out, or fails.

---

## Phase 1: Remove stub/demo runtime functionality

### Task 1: Bind production runtime only

**Objective:** Make `HermesRuntimeService` always resolve to the real server-hosted runtime adapter.

**Files:**
- Modify: `web/app/Providers/AppServiceProvider.php`
- Modify: `web/config/services.php`
- Modify: `web/database/migrations/2026_05_10_000001_create_assistant_domain_tables.php`

**Steps:**
1. Remove the `StubHermesRuntimeService` import and conditional binding.
2. Bind `HermesRuntimeService::class` directly to `HermesCliRuntimeService::class` for now.
3. Change default `services.hermes_runtime.mode` from `stub` to `cli`.
4. Change `conversation_sessions.runtime_mode` default from `stub` to `cli`.
5. Verify missing CLI config creates a blocker instead of a fake response.

**Status:** Done.

### Task 2: Delete stub/demo code and tests

**Objective:** Remove deterministic parser/demo-loop artifacts so they cannot be accidentally used.

**Files:**
- Delete: `web/app/Services/StubHermesRuntimeService.php`
- Delete/rewrite: `web/tests/Feature/HermesDemoLoopTest.php`
- Modify: `web/routes/console.php`
- Update docs that instruct `php artisan hermes-bean:demo`.

**Steps:**
1. Remove the `hermes-bean:demo` Artisan command.
2. Remove tests asserting stub text, broad demo planning, or demo Artisan command output.
3. Replace runtime API tests with fake Hermes CLI scripts that return structured JSON.
4. Run `cd web && php artisan test`.

**Status:** Done; backend tests pass.

---

## Phase 2: Real response contract cleanup

### Task 3: Make backend response shape intentionally chat-first

**Objective:** Ensure the message API returns the real assistant response and enough high-level state for Flutter to show chat status without a new runtime-run table.

**Files:**
- Modify if needed: `web/app/Services/HermesCliRuntimeService.php`
- Modify if needed: `web/app/Http/Controllers/Api/ConversationMessageController.php`
- Tests: `web/tests/Feature/HermesRuntimeApiTest.php`

**Response shape should continue to include:**
- `status`: `completed` or `blocked`
- `session.status`
- `user_message`
- `assistant_message` when available
- `blocker` when fail-closed/blocked
- `events` for audit/debug only, not live app UI

**Rules:**
- If Hermes returns normal text/JSON message, show that as `assistant_message.content`.
- If Hermes requests an approval or blocks on setup, include a natural-language assistant message where possible.
- If Hermes CLI is missing/failed/timed out, return `status=blocked`, `assistant_message=null`, and `blocker` with reason. Flutter can show this as failed/blocked run state.

### Task 4: Keep approvals backend-capable but not app-complex

**Objective:** Preserve existing approval records and approve/deny endpoints, but do not build a mid-run interrupt system yet.

**Files:**
- Keep: `web/app/Services/StructuredHermesActionService.php`
- Keep: `web/app/Http/Controllers/Api/DomainResourceController.php`

**Rules:**
- Risky structured actions can still create pending approvals.
- Low-risk structured actions can still execute automatically.
- Flutter does not need a special inline approval/tool timeline for this pass.
- The assistant's natural language response should explain when something needs approval.

---

## Phase 3: Flutter chat-level run state only

### Task 5: Add top-of-chat run-state indicator

**Objective:** Show simple Hermes state at the top of the chat view, not tool internals.

**Files:**
- Modify: `app/lib/main.dart`
- Update tests: `app/test/widget_test.dart`

**States:**
- `Ready` / idle
- `Hermes is working…` while `_busy` after sending a message
- `Blocked` when last send returns `status=blocked` or a blocker exists
- `Failed` when send throws API/network error
- Optional: `Updated` / completed after a successful response

**UI placement:**
- Top of the chat panel/card.
- Keep it compact: small pill or row under the chat title.
- Do not show individual tool calls, stdout/stderr, or event stream details.

### Task 6: Make chat render natural responses cleanly

**Objective:** Ensure the user sees actual Hermes replies, including approval/blocker explanations, as natural language chat messages.

**Files:**
- Modify: `app/lib/main.dart`
- Modify if needed: `app/lib/hermes_api_client.dart`

**Behavior:**
- Send message.
- Show busy run state.
- Render returned `assistant_message.content`.
- If blocked and no assistant message exists, render a friendly local fallback using `blocker.reason` such as: “Hermes is blocked: [reason]”.
- Refresh `/today` after each response to update tasks/reminders/calendar/approvals/blockers.

---

## Verification checklist

- `StubHermesRuntimeService` no longer exists.
- No API path can produce `Stub Hermes runtime received`.
- Default runtime mode is real server Hermes/CLI and fail-closed when not configured.
- Demo Artisan command is removed.
- Backend tests use fake CLI executables, not stub parsing.
- Message responses show actual Hermes response content.
- Flutter chat has a compact run-state indicator at top.
- Flutter does not add runtime-run models, streams, or tool-call timeline UI.
- Flutter analyzer/tests pass.
- Laravel tests pass.
