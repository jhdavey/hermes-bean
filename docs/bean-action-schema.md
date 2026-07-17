# Bean Action Schema

Bean actions are JSON objects:

```json
{"action":"task.create","arguments":{"title":"Call mom","due_at":"2026-07-18T09:00:00-04:00"}}
```

## Domain actions

- `task.list`, `task.search`, `task.create`, `task.update`, `task.complete`, `task.delete`
- `reminder.list`, `reminder.search`, `reminder.create`, `reminder.update`, `reminder.complete`, `reminder.delete`
- `calendar_event.list`, `calendar_event.search`, `calendar_event.create`, `calendar_event.update`, `calendar_event.delete`
- `note.list`, `note.search`, `note.create`, `note.update`, `note.delete`
- `dashboard.summary`

## External/context actions

- `time.now`
- `weather.lookup`

## Required executor behavior

- Scope all reads/writes to authenticated user accessible workspaces.
- Return structured success/error/confirmation payloads.
- Emit activity entries for tool started/completed/failed/confirmation requested.
- Emit dashboard changes for mutations through existing model observers or notifier.
- Never directly run arbitrary model-provided code, URLs, SQL, or shell commands.
