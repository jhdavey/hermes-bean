# Bean Domain Resource Boundary

Bean and the normal HeyBean API should use the same Laravel resource-control layer for tasks, reminders, calendar events, notes, note folders, workspace scoping, plan limits, and related domain behavior.

## Non-negotiable rule

Bean must not grow a separate Eloquent CRUD stack for productivity resources. Bean actions should validate/normalize structured model arguments, then call the same application/domain service layer used by the authenticated API.

The shared layer must preserve normal API behavior, including:

- workspace scoping and membership authorization;
- plan limits for notes, recurrence, calendar connections, and related resource features;
- linked-workspace sync/copy/delete semantics;
- recurrence/all-day validation and calendar occurrence materialization behavior;
- note normalization and note-folder handling;
- reminder notification recipient validation;
- dashboard change observers/notifier behavior;
- provider/export hooks where direct API edits trigger them.

## Current implementation status

The current shared boundary is:

```text
web/app/Services/Domain/DomainResourceService.php
```

Current adapters using the shared boundary include:

- `web/app/Http/Controllers/Api/DomainResourceController.php`
- `web/app/Services/Bean/BeanActionExecutor.php`

Bean actions for tasks, reminders, calendar events, notes, and note folders should continue to route mutations through `DomainResourceService` rather than direct model writes. `BeanActionExecutor` remains the scoped structured-action adapter over the shared service and is responsible for Bean-specific confirmation/ambiguity guardrails, activity logging, and tool result shape.

## Target shape

The current consolidated service is acceptable as the active boundary. Longer-term cleanup can split it into focused services under `web/app/Services/Domain/` if that reduces complexity without creating behavior drift, for example:

- `DomainTaskService`
- `DomainReminderService`
- `DomainCalendarEventService`
- `DomainNoteService`
- `DomainNoteFolderService`
- shared helpers such as `DomainResourceScope`, `DomainResourceSerializer`, or `DomainResourceMutationResult`

Only split the service when the extracted interfaces are shared by both the HTTP controller and Bean executor. Do not split in a way that lets Bean and the normal API diverge.

## Acceptance criteria for future changes

- `DomainResourceController` and `BeanActionExecutor` share the same domain service layer for supported resource mutations.
- Bean has no direct create/update/delete Eloquent model writes for productivity resources when a shared service method exists.
- Existing direct API behavior remains unchanged.
- Bean resource mutations emit the same dashboard changes as direct API edits.
- Plan limits, linked-workspace sync, recurrence behavior, note normalization, and workspace scoping remain covered by tests.

## Verification

For resource-boundary changes, run focused API/Bean/domain coverage plus the normal web checks:

```bash
cd web
php artisan test tests/Feature/ProductivityDomainApiTest.php tests/Feature/BeanHermesRuntimeTest.php tests/Feature/CanonicalDomainResourceContractTest.php tests/Feature/WorkspaceSchemaTest.php tests/Feature/DashboardChangeFeedTest.php
php artisan test
npm test
npm run build
git diff --check
```
