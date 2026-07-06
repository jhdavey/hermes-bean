# HeyBean System Blueprint

This document maps the current HeyBean system as implemented in this repository. It covers the Laravel backend/API, Flutter mobile app, Laravel web app, and the way Bean runtimes, agent profiles, tools, memory, background work, and realtime voice coexist.

## Executive Shape

HeyBean is a personal assistant product with one canonical backend and two client surfaces:

- `web/` is the Laravel application. It owns API contracts, auth, billing, workspaces, persisted assistant state, domain data, integrations, background jobs, usage guardrails, realtime session brokering, and the Bean tool runtime.
- `app/` is the Flutter mobile app. It owns the consumer command center, local state, optimistic dashboard updates, mobile voice/WebRTC orchestration, push/local notifications, Stripe payment sheet flow, and all direct calls to the Laravel API.
- `web/resources/js/heybean/webApp.js` is the browser command center shipped by Laravel/Vite. It is a large vanilla JS app that mirrors much of the command center behavior for web users.
- `shared/` contains cross-surface contracts. Today this is small, with `shared/voice_contract.json` as the explicit shared voice behavior contract.
- `scripts/` contains deployment and asset helpers.

The root README captures the major runtime decision: there is no separate runtime-manager service for the MVP. Laravel owns the app-facing runtime/session API and calls model/runtime services through a dedicated adapter layer. If process lifecycle management becomes too complex, a runtime-manager can be extracted later without changing Flutter's public API contract.

## Top-Level Layout

```text
hermes-bean/
  app/                         Flutter mobile app
    lib/main.dart              Entry point and part-file aggregator
    lib/hermes_api_client.dart Typed HTTP client for Laravel API
    lib/bean_realtime_conversation.dart
                                Mobile WebRTC realtime voice controller
    lib/src/...                UI sections and app runtime helpers
  web/                         Laravel app, API, web UI, queues, runtime
    routes/api.php             Authenticated JSON API surface
    routes/web.php             Marketing, legal, auth shell, web app routes
    app/Http/Controllers/Api/  API controllers
    app/Services/              Domain services and Bean runtime services
    app/Services/HermesToolRuntime/
                                Tool runtime traits and model/tool adapters
    app/Jobs/                  Queue jobs for assistant runs and memory
    app/Models/                Eloquent models for users, workspaces, assistant state, domain state
    database/migrations/       Schema history
    resources/js/heybean/      Browser command center
    resources/views/           Laravel Blade views
  docs/                        Product and implementation documentation
  shared/                      Cross-client contracts
  scripts/                     Deploy and asset scripts
```

## Core System Boundaries

### Laravel Backend

Laravel is the system of record and runtime owner. It provides:

- User auth and bearer tokens.
- Billing and plan limits.
- Workspace membership and workspace-scoped data access.
- Conversation sessions, messages, assistant runs, and activity events.
- Tasks, reminders, calendar events, event categories, notes, memory, approvals, and blockers.
- Dashboard change feed for client refresh/polling.
- Google Calendar and Outlook sync.
- Places, weather, live lookup, and external lookup support.
- Realtime voice session brokering to OpenAI Realtime.
- Background queue processing for long-running Bean work.
- Admin controls for model settings, usage, plan limits, issue reports, live lookup providers, and Hermes CLI maintenance.

Key files:

- `web/routes/api.php`
- `web/app/Providers/AppServiceProvider.php`
- `web/app/Services/HermesRuntimeService.php`
- `web/app/Services/HermesToolRuntimeService.php`
- `web/app/Services/HermesToolRuntime/*`
- `web/app/Services/AssistantRunService.php`
- `web/app/Jobs/ProcessAssistantRun.php`
- `web/app/Http/Controllers/Api/RealtimeSessionController.php`

### Flutter App

Flutter is a thin but sophisticated client. It does not own durable business state. It:

- Authenticates with Laravel and stores bearer tokens locally.
- Renders the command center across Bean chat, today, calendar, tasks, reminders, notes, memory, settings, account, billing, and onboarding.
- Uses `HermesApiClient` as its API boundary.
- Runs mobile realtime voice via WebRTC and the OpenAI Realtime data channel, while sending privileged tool calls back to Laravel.
- Maintains local optimistic writes and merges them with server refreshes.
- Polls dashboard changes and assistant activity events.
- Registers push tokens and manages local reminder notifications.

Key files:

- `app/lib/main.dart`
- `app/lib/hermes_api_client.dart`
- `app/lib/bean_realtime_conversation.dart`
- `app/lib/src/shell/command_center_shell.dart`
- `app/lib/src/shell/command_center_content.dart`
- `app/lib/src/core/runtime_services.dart`

### Laravel Web App

The Laravel web app has two faces:

- Public Blade pages for landing, pricing, legal, support, reset-password, and workspace invitation acceptance.
- A browser command center mounted at `/app`, `/dashboard`, `/admin`, `/login`, `/register`, `/subscribe`, and `/forgot-password`.

The browser command center is implemented as a Vite-built vanilla JS application:

- `web/resources/views/app.blade.php` mounts `#heybean-web-app`.
- `web/resources/js/app.js` calls `mountHeyBeanWebApp`.
- `web/resources/js/heybean/webApp.js` owns web app state, views, API calls, dashboard polling, chat, voice behavior, admin screens, and billing flows.
- `web/resources/js/voiceWake.js` contains wake phrase, voice intent, and background-work routing helpers.

## Runtime and Agent Model

### Current Runtime Binding

`AppServiceProvider` binds:

```php
HermesRuntimeService::class => HermesToolRuntimeService::class
```

That means all app-facing assistant operations resolve through `HermesToolRuntimeService`, not an external long-lived runtime manager.

`HermesRuntimeService` defines the app contract:

- `startSession`
- `resumeSession`
- `cancelSession`
- `progressEvents`
- `sendMessage`
- `sendExistingMessage`

`HermesToolRuntimeService` implements that contract with three trait layers:

- `RuntimeSupport`: prompt construction, context payloads, tool schemas, routing modes, safe fallback text, date/time helpers, and model route support.
- `NativeToolRuntime`: model function/tool call execution against Laravel domain actions and read tools.
- `CrudPlannerRuntime`: fast-path planner for app CRUD requests before the native tool loop.

### Agent Profiles

`AgentProfileService` creates one profile per workspace. A profile stores:

- Provider and model.
- Runtime home path.
- Personality settings.
- Onboarding settings.
- Tool policy.
- Approval policy.
- TTS/realtime voice preferences.
- Memory settings.

Profiles are workspace-scoped, so the practical unit of agency is:

```text
User + Workspace + AgentProfile + ConversationSession
```

The profile is not a separate running process. It is persisted configuration that Laravel injects into model context and uses to decide behavior.

### Hermes CLI Coexistence

There is a `HermesMaintenanceService` that shells out to a configured `hermes` CLI for status and update operations. This is exposed through admin routes:

- `GET /api/admin/hermes/status`
- `POST /api/admin/hermes/update`

This CLI bridge is currently operational/maintenance oriented. The main assistant runtime path is Laravel's `HermesToolRuntimeService` calling OpenAI-compatible chat completions and executing Laravel-owned tools.

## Assistant Data Model

The assistant runtime is persisted around these core tables:

- `conversation_sessions`: one chat/voice/onboarding runtime session.
- `conversation_messages`: user and assistant messages.
- `assistant_runs`: queued/running/completed/cancelled background work.
- `activity_events`: progress, tool, runtime, approval, and UI-visible work events.
- `agent_profiles`: workspace-scoped Bean configuration.
- `memory_items`, `memory_events`, `memory_summaries`, `memory_links`: durable Bean memory and recall support.
- `approvals`: human approval checkpoints for higher-risk actions.
- `blockers`: unresolved issues Bean or the user may need to clear.

Domain data is workspace-scoped:

- `tasks`
- `reminders`
- `calendar_events`
- `event_categories`
- `notes`
- `note_folders`
- `workspaces`
- `workspace_memberships`
- `workspace_item_links`

`dashboard_changes` records resource mutations for clients to refresh visible data without a full blind reload.

## HTTP API Surfaces

The authenticated API under `web/routes/api.php` groups into these areas:

- Auth/account: `/auth/*`, `/account`, `/account/export`
- Billing: `/billing/*`
- Workspaces: `/workspaces/*`, `/workspace-invitations/*`
- Assistant sessions and chat: `/assistant/sessions/*`, `/assistant/runs/*`
- Realtime voice: `/assistant/realtime/*`
- Dashboard and summaries: `/today`, `/dashboard-changes`
- Domain CRUD: `/tasks`, `/reminders`, `/calendar-events`, `/event-categories`, `/notes`, `/note-folders`, `/memory-items`, `/approvals`, `/blockers`
- Integrations: `/google-calendar/*`, `/outlook-calendar/*`, `/places/*`
- Admin: `/admin/settings`, `/admin/plan-limits`, `/admin/hermes`, `/admin/live-lookup`, `/admin/usage`, issue report moderation

Flutter's `HermesApiClient` mirrors these endpoints with typed Dart models and methods. The browser app uses fetch wrappers inside `webApp.js`.

## Text Chat Flow

Direct text chat can run synchronously first, then fall back to queued background work.

```text
Flutter or Web UI
  -> POST /api/assistant/sessions
  -> POST /api/assistant/sessions/{session}/messages
      ConversationMessageController
        creates user message
        calls HermesRuntimeService::sendExistingMessage
          HermesToolRuntimeService
            builds runtime context
            preflights usage limits
            routes as full, app_crud, or read_lookup
            optionally uses CRUD planner
            calls model with native tool definitions
            executes Laravel tools/actions
            writes assistant message
            records usage and activity events
            queues memory extraction
  <- assistant message + events
```

If direct runtime execution fails or metadata requests queued behavior, Laravel creates an `assistant_runs` row and dispatches `ProcessAssistantRun`.

## Background Assistant Run Flow

Queued runs are used by realtime voice and by chat fallback paths.

```text
Client
  -> POST /api/assistant/sessions/{session}/runs
      AssistantRunService::queueRun
        creates user message
        creates assistant_run(status=queued)
        records runtime.run_queued
        dispatches ProcessAssistantRun

Queue worker
  -> ProcessAssistantRun
      marks run/session running
      records runtime.run_started
      calls HermesRuntimeService::sendExistingMessage
      stores assistant_message_id, result, completed_at
      records runtime.run_completed or runtime.run_failed

Client
  -> polls /api/assistant/runs/{run}
  -> polls /api/assistant/sessions/{session}/events
```

`AssistantRunService` includes stale-run reconciliation and limited recovery paths so client responses can recover from worker crashes, queue delays, and partial failures.

## Realtime Voice Flow

Realtime voice is split into low-latency speech and durable background work.

```text
Flutter BeanRealtimeConversation
  -> POST /api/assistant/realtime/sessions
      Laravel creates or resumes local ConversationSession
      Laravel requests OpenAI realtime client secret
      Laravel returns local session, client secret, model, voice, tool list

Flutter
  -> creates WebRTC peer connection
  -> POST /api/assistant/realtime/calls with SDP offer
      Laravel forwards SDP + realtime session config to OpenAI
      Laravel returns SDP answer

OpenAI Realtime data channel
  -> speaks short answers when dashboard snapshot is enough
  -> calls queue_bean_work for durable app changes or missing data

Flutter
  -> POST /api/assistant/realtime/tool-calls
      queue_bean_work creates assistant_run(source=realtime)
      cancel_bean_work cancels run

Flutter
  -> watches run/activity events
  -> persists realtime messages
  -> records realtime usage and client quality telemetry
```

Realtime instructions explicitly separate:

- Fast spoken responses from the dashboard context snapshot.
- Background Bean work for app writes, notes, memory, prior-request recall, live external facts, and anything missing from the snapshot.

The realtime route has only two model-callable tools:

- `queue_bean_work`
- `cancel_bean_work`

All privileged app state changes still run through the Laravel background assistant/tool runtime.

## Bean Tool Runtime

The tool runtime is Laravel-owned. The model receives function definitions, but tool execution happens inside Laravel services.

Tool routing modes:

- `app_crud`: tasks, reminders, calendar, notes, and basic app writes/reads.
- `read_lookup`: read tools plus external lookup when the user asks for current outside information.
- `full`: all tools, including memory, workspace memory, blockers, categories, activity events, and profile updates.

Representative read tools:

- `search_tasks`
- `search_reminders`
- `search_calendar_events`
- `search_notes`
- `search_memory`
- `get_day_context`
- `get_request_history`
- `get_activity_timeline`
- `external_lookup`

Representative write tools:

- `create_task`, `update_task`, `delete_task`
- `create_reminder`, `update_reminder`, `delete_reminder`
- `create_calendar_event`, `update_calendar_event`, `delete_calendar_event`
- `create_note`, `update_note`, `delete_note`
- `remember_memory`, `update_memory`, `forget_memory`
- `update_agent_profile`
- `create_blocker`, `resolve_blocker`

Native tools are mapped to structured action types such as `task.create`, `reminder.update`, and `calendar_event.delete`. `StructuredHermesActionService` validates, normalizes, approves when needed, and applies those actions to Eloquent models.

## CRUD Planner Fast Path

For app CRUD requests, `CrudPlannerRuntime` tries to avoid a slower multi-turn tool loop:

1. It determines whether the message is a clear app data change.
2. It tries deterministic local planning.
3. If local planning is insufficient, it asks a smaller planner model for strict JSON actions.
4. It executes planned actions through the same structured action service.
5. It records planned work items and completion/failure events.
6. It writes a natural assistant response.

This path is still Laravel-owned. The planner only proposes actions; Laravel validates and executes.

## Memory and Recall

Bean memory has two layers:

- Hot injected context: `BeanMemoryService::runtimeContext` injects a bounded set of relevant memory items and summaries into non-CRUD runtime context.
- Explicit recall tools: request history, activity timeline, memory search, and day context expose specific records on demand.

After a completed turn, `BeanMemoryService::recordTurnCandidate` creates a `memory_events` row and dispatches `ExtractBeanMemoryFromTurn` after the response. The current extraction path is heuristic and creates durable memory only for patterns that look stable/useful.

Agent profile memory is also written to the configured runtime home when onboarding/profile settings change.

## Workspaces

Workspaces are the main scoping boundary for user data and Bean agency.

- Users get a personal workspace.
- Workspace memberships determine access.
- Most domain tables carry `workspace_id`.
- Agent profiles are workspace-scoped.
- The dashboard context snapshot can include multiple accessible workspaces.
- Workspace item links support cross-workspace sync/copy relationships.

The runtime usually acts in the current workspace unless the user clearly names another accessible workspace.

## Dashboard Synchronization

Clients use three synchronization strategies:

- Direct API refreshes for full domain lists.
- Optimistic local writes for immediately visible user actions.
- `dashboard_changes` polling for server-side mutations caused by Bean, integrations, or other clients.

Laravel observes dashboard resources through `DashboardResourceObserver` for tasks, reminders, and calendar events. The Flutter shell merges pending writes with refreshed server data and uses activity events to show Bean work progress.

## Integrations and External Data

Current integration services include:

- Google Calendar sync.
- Outlook Calendar sync.
- Google Places autocomplete/details/static map support.
- OpenMeteo weather lookup.
- Tavily and/or OpenAI-compatible external lookup for current facts.
- Firebase Cloud Messaging for push.
- Stripe for web checkout and mobile payment sheet setup.
- Resend/Postmark/mail infrastructure for notifications and transactional mail.

The runtime instruction is explicit: Bean should not invent current outside facts. It must use `external_lookup` or warmed dashboard context where available.

## Billing, Usage, and Plan Limits

Usage control is centralized in Laravel:

- `AiUsageService` preflights model/realtime calls and records usage.
- `PlanLimitService` gates plan features, history windows, notes, billing tier limits, and limit responses.
- Realtime usage is recorded through `/assistant/realtime/usage`.
- Admin usage summaries and logs are exposed under `/api/admin/usage/*`.

This keeps clients from making direct cost-bearing calls with privileged server keys.

## Scheduled and Queue Work

Scheduled console tasks:

- `plan-history:prune` daily.
- `calendar-events:materialize-recurring` daily.
- `reminders:send-due-notifications` every minute.

Important queue jobs:

- `ProcessAssistantRun`
- `ExtractBeanMemoryFromTurn`

Operational smoke and quality tooling:

- `php artisan bean:production-smoke`
- voice-quality scenario through `RunBeanProductionSmokeSuite`
- admin command execution through `AdminCommandRunService`

## Coexistence Model

The clean mental model is:

```text
Clients
  Flutter mobile app
  Laravel web command center

API and System of Record
  Laravel controllers
  Laravel services
  Eloquent models
  Queues and scheduler

Bean Runtime
  ConversationSession
  AgentProfile
  HermesToolRuntimeService
  Native tools
  CRUD planner
  Memory service
  Activity events
  Assistant runs

Model Providers
  OpenAI-compatible chat completions
  OpenAI Realtime
  TTS/transcription models

Operational Hermes CLI
  Status/update bridge only
  Configured runtime homes
```

Bean is not one monolithic daemon in this codebase. Bean is the behavior that emerges from:

- Workspace-scoped agent profile configuration.
- Laravel's runtime service and tool execution layer.
- Persisted conversation/session/run/event state.
- Model calls through OpenAI-compatible APIs.
- Client surfaces that present work, speech, and dashboard changes.

## Main Flows At A Glance

### App Write From Text

```text
User types "Schedule dentist Tuesday at 3"
  -> Flutter/Web sends assistant message
  -> Laravel creates user message
  -> Runtime routes to app_crud
  -> CRUD planner or native tool loop plans calendar_event.create
  -> Structured action service validates and writes calendar_events
  -> Activity events record planned/executed/completed states
  -> Assistant message confirms result
  -> Dashboard change feed lets clients refresh
```

### App Write From Voice

```text
User says "Hey Bean, remind me to take out trash tonight"
  -> Realtime model gives short acknowledgement
  -> Realtime model calls queue_bean_work
  -> Laravel queues assistant_run(source=realtime)
  -> Queue worker runs same tool runtime as text
  -> Flutter watches run/events
  -> Completion is spoken briefly and shown in chat/dashboard
```

### Read-Only Voice Question

```text
User says "Hey Bean, what is next today?"
  -> Flutter refreshes dashboard context when needed
  -> Realtime instructions include snapshot
  -> Realtime model answers directly if snapshot has the answer
  -> No background run is queued
```

### Memory

```text
User says "Remember that I prefer morning workouts"
  -> Runtime routes full
  -> Model calls remember_memory
  -> Laravel creates memory_items row
  -> Agent profile runtime memory is refreshed
  -> Future runtime context can inject this preference
```

## Extension Points

Likely future extraction points:

- Runtime manager service: if `HermesToolRuntimeService` grows beyond HTTP/model orchestration or needs long-lived process lifecycle control.
- Shared API schema generation: Flutter and web currently depend on manually mirrored contracts.
- Shared voice intent contract: Flutter Dart and browser JS both implement realtime/voice behavior; more of this could move to `shared/`.
- Tool plugin registry: native tools are currently hardcoded in `RuntimeSupport` and `NativeToolRuntime`.
- Event broadcasting: clients currently poll activity/dashboard changes; websocket broadcasting could reduce latency and polling load.

## Operational Notes

Development commands from the root README:

```bash
cd app
flutter test
flutter analyze

cd ../web
php artisan test
npm install --ignore-scripts
npm run build
```

Laravel local dev can also use Composer's `dev` script, which starts the server, queue listener, logs, and Vite together:

```bash
cd web
composer run dev
```

Production deploy uses `scripts/forge-deploy.sh`, which checks out `main`, installs Composer and npm dependencies, builds Vite assets, runs migrations, clears/caches Laravel config/routes/views, and links storage.
