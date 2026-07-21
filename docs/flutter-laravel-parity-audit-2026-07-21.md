# Flutter vs Laravel Parity Audit — 2026-07-21

Laravel web is the source of truth for the current HeyBean MVP. This audit focused on bringing Flutter/mobile up to date without major mobile style redesigns.

## Scope audited

- Bean assistant and voice entry points
- Bean timezone propagation and runtime contract
- Daily sticky notes
- Notes markdown storage/editor behavior
- User settings parity
- Mobile permissions/build viability

## Findings and implementation

### Bean assistant/runtime

**Laravel source of truth**

- `/api/bean/messages` accepts `client_timezone`, `workspace_id`, and optional `session_id`.
- Laravel owns the Bean runtime/action contract and applies the saved user timezone as the primary time source.
- Web voice uses ElevenLabs Agent WebRTC with dashboard context and local timestamp policy.

**Flutter gap**

- Flutter text Bean assistant existed, but did not send timezone.
- Flutter had no mobile voice input/output surface.
- Flutter realtime session client still pointed at the obsolete `/bean/realtime/session` shape instead of the current ElevenLabs conversation token shape.

**Implemented**

- `BeanApiClient.sendBeanMessage()` now accepts and sends `client_timezone`.
- `sendBeanMessage()` also accepts optional `source`, so native/mobile voice turns can identify their origin without changing the text path.
- Added client coverage for Laravel Bean session/activity/voice-event/confirmation routes: `/bean/sessions`, `/bean/sessions/{id}/activity`, `/bean/voice-events`, and `/bean/confirmations/{id}/approve`.
- Bean assistant panel now renders pending confirmations and can approve them through Laravel.
- Flutter registration seeds timezone with `flutter_timezone` where available.
- `BeanApiClient.createBeanRealtimeSession()` now maps to `/bean/elevenlabs/conversation-token` and parses the current token/dashboard-context response for future native ElevenLabs mobile work.
- Initial mobile pass added a mic button with native STT/TTS over Laravel `/bean/messages`; the follow-up below replaces that production voice path with ElevenLabs Agent audio.
- Android/iOS microphone permissions added.

**Follow-up implementation — ElevenLabs audio parity**

- Flutter now uses the native ElevenLabs Agents Flutter SDK (`elevenlabs_agents`, LiveKit/WebRTC) for Bean voice audio instead of device STT/TTS.
- The mic button mints the same Laravel `/bean/elevenlabs/conversation-token` payload as web, then starts an ElevenLabs Agent conversation with `bean_session_id`, `bean_client_timezone`, `bean_workspace_id`, and serialized `bean_dashboard_context` dynamic variables.
- The Flutter ElevenLabs client registers the same `askBean` client tool. Tool calls route back through Laravel `/bean/messages` with `source: elevenlabs_agent`, update the Bean panel/activity/confirmations, refresh dashboard data, and return Laravel's answer to ElevenLabs for speech.
- Flutter records mobile ElevenLabs lifecycle telemetry through `/bean/voice-events` with `source: flutter_elevenlabs_agent`.
- Legacy Flutter voice dependencies (`speech_to_text`, `flutter_tts`) were removed from the production voice path.

**Mobile-context note**

- The web app still owns local wake-word mode around the ElevenLabs session. Flutter now uses the same ElevenLabs Agent/token/tool system for active mic sessions, but starts from the mobile mic button rather than a browser wake-listening loop.

### Daily sticky notes

**Laravel source of truth**

- `/api/daily-sticky-note` GET/PUT with `date`, `workspace_id`, and autosaved `content`.
- Web shows a daily scratchpad in the command/today context.

**Flutter gap**

- No API client methods.
- No mobile UI surface.

**Implemented**

- Added `BeanDailyStickyNote` model.
- Added `getDailyStickyNote()` and `updateDailyStickyNote()` client methods.
- Added a Daily sticky note card to the Today screen, scoped by selected day and active workspace, with autosave and status feedback.

### Notes markdown

**Laravel source of truth**

- Notes now accept/store `body_markdown`; legacy `body_html`/`plain_text` are no longer the authoritative write contract.
- Web editor is markdown-backed.

**Flutter gap**

- Flutter client still sent `body_html` and `plain_text`, which no longer matches Laravel note validation.
- Flutter model did not read `body_markdown`.

**Implemented**

- `createNote()` and `updateNote()` now write `body_markdown` and stop sending `body_html`/`plain_text`.
- `BeanNote` now reads/carries `bodyMarkdown`.
- Notes editor loads markdown first, falling back to legacy plain/html only for old payloads.
- Existing mobile note layout is preserved; text area now acts as a markdown source editor while keeping the existing mobile notes UX.

### Settings parity

**Laravel source of truth**

Current settings exposed through `/auth/me` include:

- email
- theme
- theme mode
- command center label
- notification preferences
- preferred map app
- timezone
- workspace/calendar/billing/account controls

**Flutter gap**

- Flutter had email, theme, command center label, notifications, map, workspace/calendar/billing/account controls.
- Flutter was missing editable timezone.

**Implemented**

- `BeanUser` now parses/carries `timezone`.
- `updateMe()` now accepts `timezone`.
- Settings screen now includes a Timezone card with IANA timezone field, Device shortcut, validation via Laravel, and save status.
- Account settings now expose data export and copy the Laravel `/account/export` payload to the clipboard.
- Settings now expose event/task/reminder category add/edit/delete using the existing category editor and Laravel event-category API.

## Verification

- `dart format lib test` passed.
- `flutter analyze` passed with no issues.
- `flutter test` passed: 12 tests.
- `./gradlew assembleDebug -PallowDebugReleaseSigning=true` passed.
- `flutter build ios --simulator --no-codesign` passed.

## Remaining optional follow-up

- Decide whether mobile should stay native STT/TTS for MVP or implement a native ElevenLabs/LiveKit conversation client for closer audio/voice identity parity with web.
- Add device-smoke QA for real microphone permissions and speech recognition on iOS/Android hardware.
