# Hermes Bean

Hermes Bean is a Hermes-first personal assistant product. Hermes is the core operating system; the Flutter app and Laravel dashboard are the friendly consumer interface around it.

## Product thesis

The user talks to a powerful Hermes agent, and the agent owns a workspace it can read, write, and operate. The UI shows the agent doing work instead of hiding it behind generic chatbot responses.

## MVP scope

Personal assistant only — no project management in the MVP.

Included for MVP:

- Native conversation sessions
- Tool execution visibility/progress
- Agent-owned dashboard state
- Calendar surface
- Tasks: todos, chores, maintenance
- Reminders
- Skills and memory awareness
- Scheduled/background jobs
- Human approval/blocker states

Deferred until after MVP:

- Project management/Kanban for end users
- Household/social collaboration depth beyond basic personal-assistant objects
- Full marketplace/integration system
- Production mobile store release work

## Directory layout

- `app/` — Flutter mobile app / consumer command center
- `web/` — Laravel API + web dashboard + Hermes runtime bridge
- `docs/` — product, architecture, and launch planning

## Runtime architecture decision

No separate runtime-manager service for the MVP.

Laravel should own the app-facing runtime/session API and call Hermes through a dedicated adapter/service layer. Flutter should remain a thin client for chat, approvals, dashboard views, notifications, and workspace state. If the Hermes process lifecycle becomes too complex later, we can extract a separate runtime-manager service from the Laravel adapter without changing the Flutter contract.

## Development

```bash
# Flutter
cd app
flutter test
flutter analyze

# Laravel
cd web
php artisan test
npm install --ignore-scripts
npm run build
```
