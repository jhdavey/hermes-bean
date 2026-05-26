# Daily personal-assistant flow

This flow exercises the first real signed-in daily assistant loop end to end:

- authenticate a user
- start a `Today` assistant session
- send a daily planning prompt
- let the backend create task, reminder, calendar, and activity-feed records
- read the user-scoped Today summary used by the Flutter app surfaces

## Backend setup

From the Laravel app:

```bash
cd web
cp .env.example .env # if needed
php artisan key:generate # if needed
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

## Curl walkthrough

Set the API base URL:

```bash
export API=http://127.0.0.1:8000/api
```

Register or sign in:

```bash
TOKEN=$(curl -s -X POST "$API/auth/register" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"name":"Daily Bean","email":"daily@example.com","password":"correct-horse-battery-staple","password_confirmation":"correct-horse-battery-staple"}' \
  | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["data"]["token"];')
```

If the account already exists, log in instead:

```bash
TOKEN=$(curl -s -X POST "$API/auth/login" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"email":"daily@example.com","password":"correct-horse-battery-staple"}' \
  | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["data"]["token"];')
```

Start a Today session:

```bash
SESSION_ID=$(curl -s -X POST "$API/assistant/sessions" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"title":"Today","metadata":{"intent":"daily_planning","source":"curl"}}' \
  | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["data"]["id"];')
```

Send the daily planning prompt:

```bash
curl -s -X POST "$API/assistant/sessions/$SESSION_ID/messages" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"content":"Plan my day: add task Review launch notes; remind me tomorrow to pack laptop; schedule Focus block tomorrow at 9am."}'
```

Read the activity feed for the session:

```bash
curl -s "$API/assistant/sessions/$SESSION_ID/events" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json'
```

Read the user-scoped Today summary:

```bash
curl -s "$API/today" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json'
```

The summary returns the latest session plus user-owned `tasks`, `reminders`, `calendar_events`, `activity_events`, `approvals`, `blockers`, and count metadata.

## Flutter app run

From the Flutter app:

```bash
cd app
flutter pub get
flutter run --dart-define=HERMES_API_BASE_URL=http://127.0.0.1:8000/api
```

For Android emulator runs, the app rewrites `localhost`/`127.0.0.1` to `10.0.2.2` so the emulator can reach the Mac-hosted Laravel server. For a physical phone, start Laravel with `php artisan serve --host=0.0.0.0 --port=8000` and use the Mac's LAN IP in `HERMES_API_BASE_URL`.

Sign in with the account above. The app starts a Today session, loads live Today/chat/task/reminder/calendar/activity surfaces, keeps the approval card visible, and refreshes surfaces after sending a planning prompt.

## Test commands

```bash
cd web
php artisan test

cd ../app
flutter test
flutter analyze
```
