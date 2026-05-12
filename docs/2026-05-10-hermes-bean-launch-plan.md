# Hermes Bean Launch Plan

Created: 2026-05-10

## Goal

Build Hermes as the core operating system and make the app/dashboard the friendly consumer interface around it.

This is a clean Hermes-first direction, not an attempt to retrofit Hermes into the existing HeyBean assistant stack.

## Why this exists

The previous HeyBean assistant architecture was HeyBean-first: product-specific intent/action layers mediated the assistant experience. That made the assistant feel generic whenever the request did not map cleanly to a known app action.

Hermes Bean flips the architecture:

- Hermes is the agentic runtime and source of intelligence.
- Laravel is the durable workspace/API/dashboard layer Hermes can operate.
- Flutter is the consumer command center for chat, approvals, notifications, and personal-assistant views.

## Runtime-manager decision

Do **not** create a separate runtime-manager service for MVP.

Reasoning:

- A separate service adds deployment, auth, session routing, logs, and queue complexity before the product loop is proven.
- Laravel can expose the app-facing API and maintain durable state for conversations, dashboard objects, approvals, and job metadata.
- Laravel can call Hermes through a `HermesRuntimeService` / adapter boundary that is easy to extract later.
- Flutter should not control Hermes directly; it should call Laravel.

Extraction trigger for a separate runtime manager later:

- multiple concurrent long-running Hermes sessions per user become hard to supervise inside Laravel;
- streaming/tool progress needs a process supervisor independent of PHP workers;
- multi-tenant runtime isolation becomes a launch requirement;
- scaling requires dedicated worker hosts.

## MVP product scope

Personal assistant / scheduling / task / reminder features only.

### MVP includes

1. **Hermes-native chat session**
   - User can talk naturally to the assistant.
   - Session persists.
   - Assistant can perform multi-step actions instead of generic answers.
   - UI displays tool/action/progress events.

2. **Agent-owned workspace dashboard**
   - Hermes can read/write dashboard state.
   - Dashboard exposes only personal assistant surfaces for MVP:
     - calendar
     - todos
     - chores
     - maintenance tasks
     - reminders
     - scheduled/background jobs
     - approvals/blockers

3. **Action model**
   - Create/update/complete tasks.
   - Create/update/snooze/complete reminders.
   - Create/update calendar events.
   - Schedule background jobs.
   - Ask for approval when needed.

4. **Skills and memory awareness**
   - Assistant should explain/use remembered preferences.
   - Assistant should load/use skills when relevant.
   - UI should make perceived intelligence visible: “I checked your reminders,” “I scheduled this,” “I need approval,” etc.

5. **Mobile command center**
   - Chat-first Flutter app.
   - Dashboard tabs for Today, Tasks, Reminders, Calendar, Activity.
   - Human approval UI.
   - Push/local notification path can be stubbed for MVP.

### Explicitly excluded from MVP

- End-user project management.
- Team/household collaboration complexity.
- Public integrations marketplace.
- Real production runtime autoscaling.
- Rebuilding all HeyBean screens/features one-for-one.

## Initial architecture

```text
Flutter app
  ├─ chat UI
  ├─ Today/tasks/reminders/calendar/activity views
  └─ approval controls
        │ HTTPS / SSE or polling
        ▼
Laravel web/API/dashboard
  ├─ auth/users
  ├─ conversation sessions/messages
  ├─ tool/progress events
  ├─ personal assistant domain models
  ├─ approval/blocker model
  ├─ scheduler/job metadata
  └─ HermesRuntimeService adapter
        │ CLI/API invocation
        ▼
Hermes Agent runtime
  ├─ tools
  ├─ skills
  ├─ memory
  ├─ cron jobs
  ├─ subagents
  └─ dashboard state operations via Laravel API/tools
```

## Build phases

### HB-1 Scaffold monorepo

Status: done

- Create root repo at `/Users/joshuadavey/development/projects/hermes-bean`.
- Create Flutter app in `app/`.
- Create Laravel app in `web/`.
- Do not create a separate runtime-manager service for MVP.
- Add launch plan and README.
- Run baseline Flutter/Laravel tests.
- Initial git commit.

### HB-2 Laravel domain foundation

Status: done

- Conversation sessions/messages.
- Activity/tool event log.
- Tasks with type: todo, chore, maintenance.
- Reminders.
- Calendar events.
- Approvals/blockers.
- Scheduler job records.
- API tests.

### HB-3 Hermes runtime adapter

Status: done

- `HermesRuntimeService` boundary.
- Start/resume session contract.
- Send message contract.
- Stream or poll progress events.
- Record tool execution events to Laravel.
- Fail safe into approval/blocker state instead of generic responses.

### HB-4 Flutter command-center shell

Status: done

- Use the old HeyBean app styling: light green/natural palette, white and soft-green surfaces, rounded cards/inputs, subtle green background glows/gradients, Material 3 polish.
- Chat screen with visible progress/action events.
- Today dashboard.
- Tasks/reminders/calendar tabs.
- Approval prompt UI.
- Basic API client tests.

### HB-5 Perceived-intelligence evaluation harness

Status: done

- Prompt suite for personal assistant behavior.
- Score generic vs action-taking responses.
- Test multi-turn context: “move that,” “remind me tomorrow,” “what did you just schedule?”
- Require visible grounding: assistant names what it checked/changed.

### HB-6 Server-hosted runtime loop

Status: updated target

- Laravel + Flutter app connected to the real server-hosted Hermes runtime adapter.
- Chat creates/updates tasks/reminders/calendar events only through Hermes structured actions.
- Activity feed shows real Hermes runtime/tool events.
- Approval/blocker flow works without parser/demo fallback.
- No project management surfaces exposed.

## Design direction

Use the same visual direction as the old HeyBean Flutter app, especially `heybeanapp/lib/core/theme/app_theme.dart`:

- light green/natural background palette (`#F8FBF6`, `#F1F7EE`, `#EAF2E6`)
- white and soft-green surfaces
- green primary/accent actions (`#16A34A`, `#15803D`)
- rounded 12–16px inputs, buttons, dialogs, and cards
- subtle green radial glows/background gradients
- Material 3 polish with calm, friendly, consumer-assistant feel
- visible Hermes agent progress/activity states throughout chat, Today, approvals, and Activity views
