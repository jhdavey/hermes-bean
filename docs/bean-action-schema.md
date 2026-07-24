# Bean Action Schema

Bean dashboard actions are now exposed to each user's Hermes agent through the default `bean_dashboard` tool.

## Generic Hermes tool shape

Hermes calls one generic tool:

```json
{
  "action": "resource.query",
  "arguments": {
    "resource": "tasks",
    "query": "travel card",
    "include_workspaces": true
  }
}
```

```json
{
  "action": "task.create",
  "arguments": {
    "title": "Call mom",
    "due_at": "tomorrow morning"
  }
}
```

Creates default to the user's personal workspace. A shared workspace can be targeted explicitly:

```json
{
  "action": "task.create",
  "arguments": {
    "workspace_name": "Family HQ",
    "title": "Call the plumber"
  }
}
```

The generic wrapper keeps Hermes flexible while Laravel remains strict at execution time.

## Supported action families

- `dashboard.summary`
- `workspace.list`
- `settings.show`, `settings.update`
- `time.now`
- `external.lookup`
- `external.weather`
- `resource.query`
- `resource.relationships`
- `task.list`, `task.search`, `task.context`, `task.create`, `task.update`, `task.complete`, `task.delete`
- `reminder.list`, `reminder.search`, `reminder.create`, `reminder.update`, `reminder.complete`, `reminder.delete`
- `calendar_event.list`, `calendar_event.search`, `calendar_event.create`, `calendar_event.update`, `calendar_event.delete`
- `note.list`, `note.search`, `note.create`, `note.update`, `note.delete`

Read actions search all accessible workspaces by default and accept `workspace_id` or exact `workspace_name` to narrow the query. Update, complete, and delete actions can resolve items across all accessible workspaces. Create actions default to the personal workspace and accept the same workspace targeting arguments.

## Required executor behavior

Laravel must:

- scope every read/write to the signed Bean user/session/run context;
- validate workspace access and ownership;
- normalize dates through Bean TimeContext;
- enforce plan limits and resource schemas;
- require confirmation for destructive/unsafe actions;
- return structured success/error/confirmation payloads;
- emit Bean activity events and dashboard change events;
- avoid arbitrary SQL, shell, code, or URL execution from model-provided arguments.

## Response contract for Hermes

Tool results should be factual and verifiable enough for Hermes to answer naturally:

```json
{
  "ok": true,
  "action": "task.create",
  "item": {
    "id": 123,
    "resource_type": "task",
    "title": "Call mom"
  }
}
```

Hermes should only say an action is done after a successful tool result. If the result contains `requires_confirmation: true`, Hermes should ask for confirmation and not claim the mutation happened.
