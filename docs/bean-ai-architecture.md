# Bean AI Architecture

Bean is a Laravel-owned assistant runtime for HeyBean. Web ships first; Flutter reuses the same runtime later. Clients render presence, chat, voice controls, activity, and dashboard refreshes, but do not duplicate CRUD or tool execution logic.

## Boundaries

- Laravel owns auth, workspace scoping, permissions, model selection, action execution, external API keys, audit/activity, and dashboard change events.
- Text chat and voice use the same Bean runtime, action schema, confirmation policy, and activity log.
- The LLM may propose structured actions, but only Laravel executes mutations.
- Destructive or ambiguous actions require confirmation before execution.
- Voice uses local wake detection first. Privacy mode means no microphone stream. Wake-listening mode may keep local browser/native wake detection active, but must not stream audio to OpenAI until `Hey Bean` is detected.

## Runtime flow

1. Client creates or reuses a Bean session.
2. User sends text, or local wake detection starts a realtime voice turn.
3. Laravel creates a Bean run and activity events.
4. Model router chooses fast structured text, stronger reasoning, or Realtime voice as appropriate.
5. Tool/action executor validates and runs allowed actions.
6. Dashboard changes and Bean activity are emitted over SSE.
7. Client refreshes affected dashboard resources and shows Bean confirmation.

## First implementation scope

- Text chat in web.
- Bean logo presence button in top-left topbar.
- Privacy/listening states with local wake boundary documented.
- SSE for Bean activity/run/dashboard events.
- CRUD tool coverage for tasks, reminders, calendar events, and notes.
- Date/time and Open-Meteo weather tools.
- Realtime voice endpoint and UI affordance only after local wake; actual always-on streaming is forbidden.
