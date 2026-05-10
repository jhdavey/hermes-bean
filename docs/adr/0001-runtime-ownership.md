# Architecture Decision Record: Runtime Ownership

Date: 2026-05-10

## Decision

Hermes Bean will **not** use a separate runtime-manager service for the MVP.

Laravel will own the app-facing runtime API and durable workspace state. A `HermesRuntimeService` adapter inside Laravel will invoke/control Hermes and record session state, messages, tool/progress events, approvals, and dashboard object mutations.

Flutter will remain a thin client and will not invoke Hermes directly.

## Context

The goal is to make Hermes the core operating system while presenting a friendly consumer personal-assistant app/dashboard.

The previous HeyBean approach added Hermes behind an existing product-specific assistant pipeline. That created an impedance mismatch: generic intent handling and narrow app actions hid Hermes' actual strengths.

Hermes Bean should instead expose Hermes-native concepts:

- conversation sessions
- tool execution
- skills
- memory
- scheduled jobs
- subagents
- dashboard/workspace state
- human approvals/blockers

## Why Laravel-owned runtime for MVP

Advantages:

- Simpler deployment and local development.
- One API boundary for Flutter.
- Durable SQLite/database state for assistant objects and progress events.
- Easy to test with Laravel feature tests.
- Adapter boundary preserves future extraction path.

Risks:

- Long-running Hermes processes may not fit naturally inside request/response PHP workers.
- Streaming progress may require queues, workers, SSE, or polling.
- Multi-user runtime isolation may eventually require a dedicated supervisor.

Mitigation:

- Design `HermesRuntimeService` as an interface, not inline controller logic.
- Store runtime jobs/events durably in Laravel tables.
- Use queue jobs or process supervision for long-running invocations.
- Extract to `runtime-manager/` only when the adapter boundary proves insufficient.

## Extraction triggers

Create a separate runtime-manager later if:

- concurrent Hermes sessions need robust process supervision;
- streaming/tool progress cannot be handled cleanly with Laravel queues/SSE/polling;
- mobile/background reliability requires a persistent agent runtime host;
- multi-tenant isolation, sandboxing, or runtime autoscaling becomes a launch requirement.
