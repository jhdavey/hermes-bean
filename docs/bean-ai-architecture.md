# Bean AI Architecture

Bean's target architecture is Hermes-first: the Bean web/Flutter/voice UI is a product shell over a real, isolated Hermes agent for each Bean user.

```text
Bean Web / Flutter / Voice UI
  → Bean API: auth, session mapping, activity/UI mirror
    → per-user Hermes agent with HERMES_HOME=storage/hermes/users/{user_id}
      → bean_dashboard Hermes tool
        → Laravel BeanActionExecutor / domain services
          → HeyBean dashboard database
```

## Core principle

Do not make Bean smarter by adding Laravel reasoning layers. Make Bean smarter by giving each user a real Hermes agent with good tools, good skills, scoped data access, and verifiable tool results.

## Ownership boundaries

Hermes owns:

- conversation history and in-conversation memory;
- context-window management and compression;
- persistent user memory/preferences;
- skills;
- tool choice and multi-step reasoning;
- final user-facing response wording.

Laravel owns:

- authentication and user isolation;
- workspace scoping and permissions;
- dashboard schemas/contracts;
- TimeContext normalization;
- confirmation requirements;
- DB writes through shared domain services;
- activity events, dashboard change events, and UI response shape.

Hermes must not write Bean tables directly. It calls scoped Bean tools. Laravel validates, executes, and returns structured results.

## Per-user isolation

Each Bean user gets a separate Hermes home:

```text
storage/hermes/users/{user_id}/
  config.yaml
  sessions/
  memories/
  skills/bean-dashboard/SKILL.md
  plugins/bean-dashboard/
  tmp/
  logs/
```

This isolates sessions, memory, skills, plugin config, and runtime state between users. The Bean session stores the mapped Hermes session name in `bean_sessions.metadata.hermes_session_name`.

## Current runtime path

The existing `/api/bean/*` UI contract is preserved. Flutter and Laravel UI continue to send messages to `/api/bean/messages` and render Bean sessions/messages/runs/activity.

In production-like environments, `config('bean.runtime_driver')` defaults to `hermes`:

1. `BeanRuntimeService` resolves or creates the Bean session.
2. `HermesUserHomeService` provisions the user's isolated Hermes home, default Bean plugin, and `bean-dashboard` skill.
3. `HermesAgentRuntimeService` creates the Bean run/message/activity mirror.
4. Laravel invokes `hermes chat --continue bean-session-{id}` with that user's `HERMES_HOME`.
5. Hermes sees normal conversation history/memory/compression and can call the `bean_dashboard` plugin tool.
6. The plugin calls `php artisan bean:dashboard-tool <signed-context>`.
7. Laravel verifies the signed context, scopes the run/session/user, executes the action through `BeanActionExecutor`, and returns JSON.
8. Hermes reads the result and produces the final response.
9. Laravel mirrors the final response back into `bean_messages`, `bean_runs`, and activity events for the current UI.

## Test/local fallback

Automated tests default `BEAN_RUNTIME_DRIVER=local` through `APP_ENV=testing` so CI does not require a live Hermes process or model credentials. This is a test harness path, not the product architecture.

## Memory model

- Conversation continuity: Hermes session history + compression.
- Durable user preferences/facts: Hermes persistent memory under that user's isolated home.
- Current dashboard truth: Bean dashboard tools only.

Dashboard facts should not be answered from memory because app state can change outside the chat.

## Voice

Voice remains a Bean UI transport. Wake detection and Realtime voice capture produce text turns for the same Bean/Hermes session. The assistant should not maintain a separate voice-only brain or mutate dashboard state through Realtime directly.
