# Bean Domain Service Extraction Plan

> **For Hermes:** Use subagent-driven-development skill to implement this plan task-by-task.

**Goal:** Make Bean and the normal HeyBean API use the exact same resource control layer for tasks, reminders, calendar events, notes, note folders, and related workspace/domain behavior.

**Architecture:** Extract controller-heavy CRUD behavior from `App\Http\Controllers\Api\DomainResourceController` into shared Laravel domain services. `DomainResourceController` becomes a thin HTTP adapter, and `BeanActionExecutor` becomes a structured-action adapter over the same services. Bean must not keep or grow a separate Eloquent CRUD stack.

**Tech Stack:** Laravel services, existing Eloquent models/observers, existing `WorkspaceService`, `PlanLimitService`, `WorkspaceItemSyncService`, existing feature tests.

---

## Non-negotiable rule

Bean must not directly create/update/delete productivity resources through a separate resource layer. All Bean mutations must go through the same domain service methods used by the normal authenticated API.

The shared layer must preserve existing behavior from `DomainResourceController`, including:

- workspace scoping and membership authorization;
- plan limits for notes, recurrence, calendar connections, and related resource features;
- linked-workspace sync/copy/delete semantics;
- recurrence/all-day validation and calendar occurrence materialization behavior;
- note normalization and note-folder handling;
- reminder notification recipient validation;
- dashboard change observers/notifier behavior;
- provider/export hooks where direct API edits trigger them.

## Current implementation status

Implemented in this slice:

- Added `App\Services\Domain\DomainResourceService` as the shared resource-control layer.
- Updated `DomainResourceController` task/reminder/calendar/note/note-folder/event-category mutation endpoints to call the service.
- Updated Bean task/reminder/calendar/note create/update/complete/delete actions to call the same service instead of direct Eloquent writes.
- Added Bean regression coverage proving:
  - recurring-task creation is blocked by the same domain plan limits;
  - completing a recurring task advances its due date instead of archiving it, matching the normal API behavior.
- Verified the focused API/Bean/domain suites after extraction.

Future cleanup can split the consolidated service into smaller resource-specific services once the shared boundary is stable. The important constraint is already in place: both adapters call the same application service for domain mutations.

## Target structure

The current implementation uses:

- `web/app/Services/Domain/DomainResourceService.php`

Longer-term, this can be split into focused services under `web/app/Services/Domain/`, for example:

- `DomainTaskService`
- `DomainReminderService`
- `DomainCalendarEventService`
- `DomainNoteService`
- `DomainNoteFolderService`
- optional shared helpers such as `DomainResourceScope`, `DomainResourceSerializer`, `DomainResourceMutationResult`

Each service should expose methods shaped for application use, not HTTP use, e.g.:

```php
$task = $tasks->create($user, $workspaceId, $payload);
$task = $tasks->update($user, $workspaceId, $taskId, $payload);
$tasks->delete($user, $workspaceId, $taskId, deleteFromWorkspaceIds: [...]);
```

Controllers may validate HTTP request payloads before calling the service. Bean may validate/normalize structured action payloads before calling the same service. The service must remain the authority for workspace safety and domain invariants.

## Implementation sequence

### Task 1: Add tests that prove API behavior before extraction

**Objective:** Lock current direct API behavior so extraction cannot change it.

**Files:**
- Modify existing feature tests under `web/tests/Feature/`.

**Steps:**
1. Add/extend tests around task/reminder/calendar/note create/update/delete and linked-workspace behavior that currently lives in `DomainResourceController`.
2. Include at least one test for notes plan-limit enforcement and one for calendar recurrence/all-day validation.
3. Run:
   ```bash
   cd web && php artisan test tests/Feature/ProductivityDomainApiTest.php tests/Feature/CanonicalDomainResourceContractTest.php tests/Feature/WorkspaceSchemaTest.php tests/Feature/DashboardChangeFeedTest.php
   ```
4. Expected: all existing behavior passes before refactor.

### Task 2: Extract one resource family first

**Objective:** Prove the pattern on the smallest safe resource before broad extraction.

**Recommended first resource:** tasks.

**Steps:**
1. Create `web/app/Services/Domain/DomainTaskService.php`.
2. Move task create/update/complete/delete logic and shared scoping into the service without changing request/response shape.
3. Update `DomainResourceController` task methods to call `DomainTaskService`.
4. Update `BeanActionExecutor` task actions to call `DomainTaskService` instead of direct `Task::create/update/delete`.
5. Run focused task/API/Bean tests.
6. Commit.

### Task 3: Extract reminders

Repeat the same pattern for reminders, preserving notification recipient validation, status handling, due/remind time normalization, linked-workspace behavior, and dashboard changes.

### Task 4: Extract calendar events

Extract calendar events only after task/reminder pattern is stable. This is the riskiest domain because of recurrence, all-day behavior, generated occurrences, delete modes, workspace sync, Google/Outlook interactions, and date normalization.

**Required verification:**
```bash
cd web && php artisan test tests/Feature/ProductivityDomainApiTest.php tests/Feature/DirectCalendarCanonicalApiTest.php tests/Feature/RecurringCalendarEventExpansionTest.php tests/Feature/GoogleCalendarSyncTest.php tests/Feature/WorkspaceSchemaTest.php
```

### Task 5: Extract notes and note folders

Extract notes/note folders while preserving notes access, note limits, folder idempotency, body normalization, folder reassignment on delete, linked-workspace sync, and dashboard changes.

### Task 6: Remove Bean direct model writes

After services exist for all resource families, remove direct domain-resource `Task::`, `Reminder::`, `CalendarEvent::`, and `Note::` writes from `BeanActionExecutor`. Bean should only:

1. resolve a structured action;
2. run confirmation/ambiguity guardrails;
3. call the shared domain service;
4. log activity and return structured results.

### Task 7: Regression sweep

Run:

```bash
cd web
php artisan test
node --check resources/js/heybean/webApp.js
npm test
npm run build
```

Expected: all tests pass and browser assets build.

## Acceptance criteria

- `DomainResourceController` and `BeanActionExecutor` share the same domain service layer for every supported resource family.
- Bean has no direct create/update/delete Eloquent model writes for productivity resources.
- Existing direct API behavior remains unchanged.
- Bean resource mutations emit the same dashboard changes as direct API edits.
- Plan limits, linked-workspace sync, recurrence behavior, note normalization, and workspace scoping are covered by tests.
