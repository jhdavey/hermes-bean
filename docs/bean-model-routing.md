# Bean Model Routing

Bean now routes product chat toward a Hermes-first runtime.

## Runtime routing

`config/bean.php` controls the driver:

```php
'bean.runtime_driver' // hermes | local
```

- `hermes` is the production/default architecture.
- `local` is test-only so CI can verify dashboard contracts without a live model process.

## Hermes agent routing

For each Bean session, Laravel invokes a per-user Hermes home:

```bash
HERMES_HOME=storage/hermes/users/{user_id} \
BEAN_TOOL_CONTEXT=<signed context json> \
BEAN_ARTISAN=<web/artisan> \
hermes chat \
  --continue bean-session-{bean_session_id} \
  --query "<user message>" \
  --quiet \
  --source bean \
  --toolsets bean_dashboard,skills,memory,session_search,web \
  --skills bean-dashboard
```

Hermes owns the normal model loop, context window, compression, memory, tool calling, and final answer.

## Dashboard tool routing

Hermes agents get the `bean_dashboard` plugin tool by default. The tool is generic by design:

```json
{
  "action": "task.create",
  "arguments": {"title": "Call mom", "due_at": "tomorrow morning"}
}
```

The plugin does not access the database. It shells back into Laravel:

```bash
php artisan bean:dashboard-tool <signed-context>
```

Laravel verifies the signed context and executes through `BeanActionExecutor`, preserving auth, workspace scope, TimeContext, confirmations, and domain-service writes.

## Guidance for the model

The default `bean-dashboard` skill tells Hermes:

- use `bean_dashboard` for private dashboard facts or mutations;
- never invent private app data;
- ask confirmation when the tool returns `requires_confirmation`;
- confirm success only from returned structured results;
- keep durable memory for stable preferences only, not current dashboard truth.

## Retired direction

The product should not continue growing Laravel-side semantic routing, final-answer synthesis, or short-term memory layers. If the model needs to do more, expose a better Bean tool or improve the Bean skill rather than adding another PHP reasoning patch.
