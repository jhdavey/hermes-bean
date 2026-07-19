# Bean Intelligent Assistant Runtime Plan

This plan is superseded.

Bean no longer uses a Laravel-owned text-model planner, `bean_agent_step`, or deterministic local fallback router. The active architecture is Hermes-first:

```text
Bean UI / voice
  → Bean API
    → per-user Hermes agent with isolated HERMES_HOME
      → bean_dashboard tool
        → Laravel scoped dashboard executor
```

Current source-of-truth docs:

- `docs/bean-ai-architecture.md`
- `docs/bean-model-routing.md`
- `docs/bean-action-schema.md`

The retained Laravel responsibilities are auth, workspace scoping, TimeContext, schema validation, confirmations, DB mutations, activity/UI mirroring, and production trace audits. Conversation memory, tool choice, multi-step continuation, and final response wording belong to each user's Hermes agent.
