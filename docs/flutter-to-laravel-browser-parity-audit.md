# Flutter to Laravel Browser Parity Audit

Date: 2026-05-23

Reference sources:
- Flutter app: `app/lib/main.dart`, `app/lib/hermes_api_client.dart`
- Browser app: `web/resources/js/app.js`, `web/resources/css/app.css`, `web/resources/views/app.blade.php`
- QA screenshots: `web/storage/app/qa-screenshots/`

## Implemented Parity

- Public landing remains on `/`; authenticated browser app lives on `/app` and `/dashboard`.
- `/login`, `/register`, and `/forgot-password` match the Flutter signed-out card flow: same title/logo treatment, email/password fields, remember-me, create-account, reset-password, privacy, terms, and support links.
- Browser auth uses the same API endpoints and token payloads as Flutter: `/api/auth/register`, `/api/auth/login`, `/api/auth/forgot-password`, `/api/auth/me`, `/api/auth/logout`.
- Theme tokens match Flutter `HeyBeanTheme`: `#f8fbf6`, `#f1f7ee`, `#eaf2e6`, `#ffffff`, `#f6faf4`, `#1f2937`, `#64748b`, `#16a34a`, `#15803d`, `#22c55e`, `#f59e0b`, `#dc2626`.
- Signed-in navigation matches Flutter destinations: Calendar, Tasks, Bean, Reminders, Settings, with a center Bean button.
- Calendar view now supports week strip, month grid toggle, selected day, hourly timeline rows, critical/event coloring, and event create/edit/delete.
- Tasks support active/done filters, complete/reopen, critical flag, category/color, due date, create/edit/delete.
- Reminders support pending/completed filters, complete/reopen, category/color, reminder time, repeat metadata, create/edit/delete.
- Bean chat supports session resume/start, `/new`, user/assistant bubbles, run state, activity refresh, error messages, and browser speech recognition where available.
- Approval guardrails are represented as a bottom approval sheet with approve, always approve, deny, and change instruction flows.
- Settings includes account email edit, Bean personality/priorities/context, notification preferences, workspace list/default, household create, invitation accept, member role/remove controls, Google Calendar connection/sync/disconnect/selection controls, calendar start/end hours, export, sign out, and delete account.
- Event editor includes recurrence, specific-day recurrence metadata, interval metadata, category datalist, color picker, critical toggle, and category management.

## Browser-Native Substitutions

- Flutter secure storage maps to browser `localStorage` or `sessionStorage` depending on Remember me.
- Flutter local notifications and app icon badge updates cannot be truly identical in the browser without web push/service worker permission flows; the browser app exposes notification preferences and in-app reminder state.
- Flutter voice long-press maps to a browser speech-recognition toggle because web pointer/permission behavior differs by browser.
- Flutter external URL launch fallback maps to `window.open`, copy-link, and check-connection controls.

## QA Notes

- Playwright screenshots were captured for `/login`, `/register`, `/forgot-password`, signed-in Calendar, Tasks, Bean, Reminders, and Settings at desktop and mobile sizes.
- A local QA account was created through the same API flow as Flutter and seeded with task, reminder, event, category, and approval data.
- Flutter web login reference screenshots were captured. Signed-in Flutter web automation could not be completed through Playwright because the Flutter canvas did not submit the login button in headless browser automation, so signed-in parity was audited from Flutter source and widget structure.

## Remaining Differences To Revisit With Device Builds

- Native push notification delivery, iOS/Android app badge updates, and mobile speech plugin behavior require device/simulator QA.
- Google OAuth completion must be verified with real Google credentials and allowed production/staging callback URLs.
- Recurring event exception behavior is wired through metadata and API fields, but single/future recurring deletion should be verified against real recurring event data.
- Pixel-perfect Flutter comparison should be repeated from real iOS/Android simulator screenshots, not only Flutter web.
