# Bean AI Architecture

Bean is Hermes-first: the Bean web/Flutter/voice UI is a product shell over a real, isolated Hermes agent for each Bean user.

```text
Bean Web / Flutter / Voice UI
  → Bean API: auth, session mapping, activity/UI mirror
    → per-user Hermes agent with HERMES_HOME=storage/hermes/users/{user_id}
      → bean_dashboard Hermes tool
        → Laravel BeanActionExecutor / domain services
          → Bean database and dashboard state
```

## Core principle

Do not make Bean smarter by adding Laravel reasoning layers. Make Bean smarter by giving each user a real Hermes agent with good tools, good skills, scoped data access, and verifiable tool results.

## Per-user Hermes isolation

Every Bean user gets a dedicated Hermes home:

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

`HermesUserHomeService` provisions this home:

- when a user registers;
- when an existing user logs in, as a backfill path;
- when the user's first Bean chat session is created, as a final lazy safety net.

Deleting a Bean account removes that user's Hermes home after the database delete succeeds.

## Runtime flow

The existing `/api/bean/*` UI contract is preserved. Flutter and Laravel UI continue to send messages to `/api/bean/messages` and render Bean sessions/messages/runs/activity.

There is no local deterministic Bean router. `BeanRuntimeService` always routes user messages to `HermesAgentRuntimeService` after handling direct pending-confirmation approvals.

1. `BeanRuntimeService` resolves or creates the Bean session.
2. `HermesUserHomeService` ensures the user's isolated Hermes home, default Bean plugin, and `bean-dashboard` skill exist.
3. `HermesAgentRuntimeService` mirrors the user message/run/activity for the UI.
4. Laravel invokes `hermes chat --continue bean-session-{id}` with that user's `HERMES_HOME`.
5. Hermes owns conversation history, memory, context compression, tool choice, and final response.
6. When dashboard data is needed, Hermes calls the `bean_dashboard` plugin tool.
7. The plugin calls Laravel's signed `bean:dashboard-tool` bridge.
8. Laravel validates scope/schema/safety, executes the action, records tool calls, and returns structured results to Hermes.
9. Hermes confirms the result or asks for the next clarification/confirmation.

## Responsibilities

### Hermes owns

- conversation memory and continuation;
- durable user memories/profile state;
- context compression;
- skill loading;
- tool selection and multi-step loops;
- final response wording.

### Laravel owns

- authentication;
- user/workspace scoping;
- dashboard schemas and domain invariants;
- TimeContext normalization;
- destructive-action confirmation records;
- DB writes;
- activity streams and UI mirror data;
- production trace audits.

## Dashboard truth rule

Hermes memory can remember preferences and conversational context. It must not be treated as current dashboard truth.

For private dashboard facts or mutations, Hermes must call Bean dashboard tools and ground the answer in tool results.

## Testing strategy

Automated tests no longer use a deterministic local Bean router. Tests verify:

- the Bean API invokes a per-user Hermes home with a fake Hermes binary;
- user registration/login provisions Hermes homes;
- account deletion removes Hermes homes;
- the `bean_dashboard` tool bridge executes scoped Laravel actions;
- confirmations still gate destructive actions;
- Flutter/web UI contracts still parse the same response shape.

Seeded deterministic `bean:evaluate` was removed. `bean:evaluate --production-smoke` remains as a read-only audit of recorded Hermes traces.
