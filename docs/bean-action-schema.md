# Bean Action Schema

Bean uses two classes of tools:

1. **Generic read/query tools** for broad app-data questions and reasoning.
2. **Strict mutation actions** for validated domain changes through Laravel services.

The goal is to avoid a per-phrase action patch treadmill: reads are composable; writes are safe and typed.

## Action object shape

```json
{"action":"resource.query","arguments":{"resource":"tasks","query":"travel card","include_workspaces":true}}
```

```json
{"action":"task.create","arguments":{"title":"Call mom","due_at":"2026-07-18T09:00:00-04:00"}}
```

## Generic read/query actions

- `resource.query`: search/filter tasks, reminders, calendar events, notes, and workspace-aware context. Supports resource type, query/title text, status, date scope, workspace, and include flags such as workspaces/linked copies.
- `resource.relationships`: resolve resource relationships such as workspace membership/linked workspace copies for a found item.
- Future: `resource.describe`, `resource.aggregate`, `resource.recent`, `resource.explain_visibility`.

Generic reads must:

- scope to authenticated user accessible workspaces;
- include enough structured facts for the model's next step or final answer;
- avoid private data leakage across workspaces;
- never mutate data;
- return workspace names/linked workspace context when relevant.

## Strict domain mutation/read actions

Existing resource actions remain available where strict actions are useful:

- `task.list`, `task.search`, `task.context`, `task.create`, `task.update`, `task.complete`, `task.delete`
- `reminder.list`, `reminder.search`, `reminder.create`, `reminder.update`, `reminder.complete`, `reminder.delete`
- `calendar_event.list`, `calendar_event.search`, `calendar_event.create`, `calendar_event.update`, `calendar_event.delete`
- `note.list`, `note.search`, `note.create`, `note.update`, `note.delete`
- `dashboard.summary`

Longer-term, most factual read/context requests should use generic read tools, while strict domain actions remain the mutation API and compatibility surface.

## External/context actions

- `time.now`
- `weather.lookup`

## Required executor behavior

- Domain-resource mutations must call the same shared domain services used by the normal API; Bean must not keep a separate Eloquent CRUD implementation.
- Scope all reads/writes to authenticated user accessible workspaces.
- Return structured success/error/confirmation payloads with factual fields for the model's next step or final answer.
- Emit activity entries for tool started/completed/failed/confirmation requested.
- Emit dashboard changes for mutations through existing model observers or notifier.
- Never directly run arbitrary model-provided code, URLs, SQL, or shell commands.

## Answering behavior

For factual questions, Bean should not stop at tool completion. It should answer from the results:

- Bad: `I’ll retrieve the list of workspaces. Done.`
- Good: `Pay the travel card is in Personal and Family.`

When results are ambiguous, Bean should name the possible matches and ask a targeted clarification.
