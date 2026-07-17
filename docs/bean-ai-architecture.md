# Bean AI Architecture

Bean is evolving from a Laravel-owned structured assistant MVP into a domain-aware intelligent assistant runtime for HeyBean. Web ships first; Flutter reuses the same runtime. Clients render presence, chat, voice controls, activity, and dashboard refreshes, but do not duplicate CRUD, safety, tool execution, or reasoning state.

See `docs/bean-intelligent-assistant-runtime-plan.md` for the active implementation plan.

## Non-negotiable boundaries

- Laravel owns auth, workspace scoping, permissions, model selection, action execution, external API keys, audit/activity, dashboard change events, and confirmation policy.
- Text chat and voice use the same Bean runtime, action schema, read/query tools, mutation actions, confirmation policy, and activity log.
- The model may interpret, plan, request reads, propose mutations, and synthesize final answers, but only Laravel executes mutations.
- Mutations go through the same shared domain services used by the normal API. Bean must not grow a separate resource-control layer.
- Destructive, ambiguous, bulk, external-send, workspace membership, billing/account/settings, and unsafe actions require confirmation before execution.
- Voice uses local wake detection first. Privacy mode means no microphone stream. Wake-listening mode may keep local browser/native wake detection active, but must not stream audio to OpenAI until `Hey Bean` is detected.

## Target runtime flow

1. Client creates or reuses a Bean session.
2. User sends text, or local wake detection starts a realtime voice turn.
3. Laravel creates a Bean run and activity events.
4. Runtime loads session working memory: recent entities, recent lists, current workspace, and unresolved ambiguity.
5. Planner interprets the request and selects generic read tools and/or strict mutation actions.
6. Laravel executes read tools or safe actions through scoped services and records structured results.
7. If needed, the model performs final answer synthesis from actual tool results.
8. Dashboard changes and Bean activity are emitted over SSE.
9. Session memory is updated with mentioned resources/lists for follow-up questions.
10. Client refreshes affected dashboard resources and presents the final text/voice answer.

## Runtime layers

### Domain intelligence

Bean needs declarative knowledge of HeyBean concepts: workspaces, linked workspace copies, tasks, reminders, calendar events, notes, folders, categories, due-by-today, overdue, recurrence, scheduled/completed states, visibility rules, and safety classes.

### Universal read/query layer

Reads should be flexible and composable. Bean should use generic tools such as `resource.query`, `resource.describe`, `resource.relationships`, `resource.aggregate`, `resource.recent`, and `resource.explain_visibility` instead of requiring a new action for every factual question.

### Strict mutation layer

Writes remain allowlisted and domain-service-backed: task/reminder/calendar/note create/update/complete/delete plus future safe capabilities. Laravel validates and confirms; the model never writes directly.

### Answer synthesis

For factual requests, Bean should retrieve data, inspect results, and answer naturally. It should not return generic `Done` when the user asked for information. The final answer should be grounded in tool output and hide internal tool/action names.

### Conversation state

Bean tracks recent resources and lists so follow-ups like “that task,” “the second one,” “what workspace is it in,” and “move it to tomorrow” work without bespoke phrase patches.

### Evaluation harness

Assistant quality is measured with representative text and voice scenarios. Every production miss should become an eval case so quality compounds over time.

## Current implementation status

Implemented foundation:

- Laravel-owned Bean sessions/runs/messages/activity.
- Shared domain service layer for mutations.
- Text chat and web Bean panel.
- OpenAI structured text model with deterministic fallback.
- OpenAI Realtime session minting and wake-first web voice flow.
- Initial strict domain actions for tasks, reminders, calendar events, and notes.
- Initial generic/context read improvements and task workspace answers.

Next architecture target:

- Promote generic `resource.query` and answer synthesis as the default path for factual app-data questions.
- Add session working memory for recent entities and follow-up resolution.
- Grow eval coverage across dashboard/workspace/task/reminder/calendar/note questions and voice turns.
