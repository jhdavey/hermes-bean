# Bean Model Routing

Bean routes product chat through per-user Hermes agents. There is no Laravel local deterministic router or test-only local runtime.

## Runtime

```text
/api/bean/messages
  → BeanRuntimeService
  → HermesAgentRuntimeService
  → hermes chat, then hermes chat --resume {real_hermes_session_id}
```

`HermesAgentRuntimeService` sets:

```bash
HERMES_HOME=/absolute/path/to/storage/hermes/users/{user_id}
BEAN_TOOL_CONTEXT=/path/to/signed/context.json
BEAN_ARTISAN=/path/to/artisan
BEAN_PHP=php
```

and invokes Hermes with the configured provider/model/toolsets/skills:

```bash
hermes chat \
  --resume {real_hermes_session_id_after_first_turn} \
  --provider custom \
  --model gpt-4.1-mini \
  --source bean \
  --max-turns 24 \
  --toolsets bean_dashboard,skills,memory,session_search,web \
  --skills bean-dashboard \
  "<user message>"
```

## Configuration

```env
BEAN_HERMES_BINARY=hermes
BEAN_HERMES_USERS_PATH=storage/hermes/users
BEAN_HERMES_SOURCE=bean
BEAN_HERMES_PROVIDER=custom
BEAN_HERMES_MODEL=gpt-4.1-mini
BEAN_HERMES_BASE_URL=https://api.openai.com/v1
BEAN_HERMES_TIMEOUT_SECONDS=120
BEAN_HERMES_MAX_TURNS=24
BEAN_HERMES_TOOLSETS=bean_dashboard,skills,memory,session_search,web
BEAN_HERMES_SKILLS=bean-dashboard
BEAN_HERMES_PHP_BINARY=php
```

`OPENAI_API_KEY` is still required by the default per-user Hermes config. Bean uses Hermes provider `custom` with `BEAN_HERMES_BASE_URL=https://api.openai.com/v1` because current Hermes installs expose OpenAI-compatible endpoints through the `custom` provider, not a literal `openai` provider. Generated Bean user homes set `agent.reasoning_effort: none` so non-reasoning fast models such as `gpt-4.1-mini` do not receive unsupported reasoning parameters.

## Default Bean tool

Each user's Hermes home includes the `bean-dashboard` plugin. It registers:

```text
bean_dashboard(action, arguments)
```

The plugin is a thin adapter. It sends the action and arguments to Laravel through the signed `bean:dashboard-tool` bridge. Laravel executes via `BeanActionExecutor` and records `bean_tool_calls`.

## Confirmation path

Hermes should call dashboard tools normally. If Laravel returns `requires_confirmation: true`, Hermes asks the user to confirm. The current Bean API also short-circuits simple affirmative replies (`yes`, `confirm`, `do it`, etc.) when a pending confirmation exists in the session, so confirmations remain reliable for voice and UI flows.

## Removed paths

Removed:

- `BeanTextModel`;
- local heuristic routing;
- `BEAN_RUNTIME_DRIVER`;
- seeded deterministic Bean runtime tests;
- seeded deterministic `bean:evaluate`.

Kept:

- `BeanActionExecutor` as the scoped dashboard tool host;
- `BeanTimeContext` for deterministic timezone/date interpretation at the tool boundary;
- confirmation records;
- activity/UI mirroring;
- production trace smoke audit.
