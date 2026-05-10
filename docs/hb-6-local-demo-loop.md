# HB-6 Local Demo Loop

This guide proves the local Hermes Bean loop without exposing project-management surfaces: chat creates/updates tasks, reminders, and calendar events; the activity feed shows Hermes actions; approval/blocker flow is visible.

## Prerequisites

From the repository root:

```bash
cd web
composer install
cp .env.example .env # if .env does not exist
php artisan key:generate # if APP_KEY is empty
php artisan migrate
```

## Option 1: one-command CLI demo

```bash
cd web
php artisan hermes-bean:demo --reset
```

Expected output includes:

```text
Created task: Replace air filter
Created reminder: take out bins
Created calendar event: dentist
Opened blocker: Needs user approval before contacting an external calendar provider.
Approved blocker: Approve external calendar sync
HB-6 demo complete.
```

This command writes assistant-domain records and activity-feed events into the configured local database.

## Option 2: API/chat loop with curl

Start Laravel:

```bash
cd web
php artisan serve --host=127.0.0.1 --port=8000
```

Create a chat session:

```bash
SESSION_ID=$(curl -s -X POST http://127.0.0.1:8000/api/assistant/sessions \
  -H 'Content-Type: application/json' \
  -d '{"title":"HB-6 local demo"}' | php -r 'echo json_decode(stream_get_contents(STDIN), true)["data"]["id"];')
```

Create task/reminder/calendar items from chat:

```bash
curl -s -X POST "http://127.0.0.1:8000/api/assistant/sessions/$SESSION_ID/messages" \
  -H 'Content-Type: application/json' \
  -d '{"content":"Demo: add task Replace air filter; remind me tomorrow to take out bins; schedule dentist tomorrow at 3pm."}'
```

Expected assistant message includes visible grounding:

```text
I checked this session and changed tasks, reminders, and calendar events. I recorded each action in the activity feed.
```

Move the previously scheduled calendar event using multi-turn context:

```bash
curl -s -X POST "http://127.0.0.1:8000/api/assistant/sessions/$SESSION_ID/messages" \
  -H 'Content-Type: application/json' \
  -d '{"content":"Move that to tomorrow at 4pm."}'
```

Ask what was just scheduled:

```bash
curl -s -X POST "http://127.0.0.1:8000/api/assistant/sessions/$SESSION_ID/messages" \
  -H 'Content-Type: application/json' \
  -d '{"content":"What did you just schedule?"}'
```

Expected assistant message includes the `dentist` calendar event and the updated `16:00` time.

Poll the activity feed:

```bash
curl -s "http://127.0.0.1:8000/api/assistant/sessions/$SESSION_ID/events"
```

Expected event types include:

- `runtime.session_started`
- `runtime.message_received`
- `assistant.task.created`
- `assistant.reminder.created`
- `assistant.calendar_event.created`
- `assistant.calendar_event.updated`
- `tool.executed`
- `runtime.message_completed`

## Approval/blocker proof

External runtime calls intentionally fail safe until integration is configured:

```bash
EXTERNAL_SESSION_ID=$(curl -s -X POST http://127.0.0.1:8000/api/assistant/sessions \
  -H 'Content-Type: application/json' \
  -d '{"title":"External provider demo","runtime_mode":"external"}' | php -r 'echo json_decode(stream_get_contents(STDIN), true)["data"]["id"];')

curl -s -X POST "http://127.0.0.1:8000/api/assistant/sessions/$EXTERNAL_SESSION_ID/messages" \
  -H 'Content-Type: application/json' \
  -d '{"content":"Use the external calendar provider to book this."}'
```

Expected result:

- HTTP `202 Accepted`.
- Session status becomes `blocked`.
- A blocker is opened with context naming the external runtime mode.
- Activity feed includes `runtime.blocked`.

## Flutter local app

Run the Flutter shell against the local Laravel API:

```bash
cd app
flutter pub get
flutter run -d macos --dart-define=HERMES_API_BASE_URL=http://127.0.0.1:8000/api
```

Use the same prompt sequence as the curl loop. Confirm the UI shows chat responses plus activity-feed updates for assistant actions.
