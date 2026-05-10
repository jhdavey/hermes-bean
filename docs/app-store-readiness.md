# Hermes Bean App Store Readiness

This checklist tracks the minimum production-readiness work required before submitting Hermes Bean to Apple App Review.

## Current readiness status

- Backend account lifecycle: implemented for email/password users.
  - Register: `POST /api/auth/register`
  - Login: `POST /api/auth/login`
  - Current user: `GET /api/auth/me`
  - Logout: `POST /api/auth/logout`
  - Data export: `GET /api/account/export`
  - Delete account: `DELETE /api/account`
- Flutter account deletion UX: visible after sign-in under **Account settings** as **Delete account**.
- Security basics: bearer-token API auth, hashed stored tokens, per-user ownership checks, API rate limiting, CORS allow-list support, no-store/security headers.
- App Store metadata, privacy URLs, and production support URLs: still needed from owner.

## App Review requirements checklist

- Account deletion:
  - In-app path: sign in → command center → **Account settings** → **Delete account**.
  - Backend endpoint: `DELETE /api/account` requires bearer auth and deletes the user, access tokens, and owned assistant data through database cascades.
  - App Store note: if a confirmation dialog or password re-auth is added later, keep final deletion reachable in-app without requiring email/support-only workflows.
- Privacy policy:
  - Required before submission.
  - Must be linked in App Store Connect and inside any public marketing/support site.
  - Must describe the data inventory in `docs/privacy-and-security.md`.
- Support URL:
  - Required by App Store Connect.
  - Owner still needs to provide a stable URL.
- Account deletion URL:
  - Apple may request a web support/deletion URL in addition to in-app deletion.
  - Owner still needs to provide a stable URL if a public account portal is desired.
- Sign in with Apple:
  - Current app uses first-party email/password only.
  - If any third-party/social login is added later, Sign in with Apple will likely be required by App Review for parity.
- Minimal permissions:
  - Do not request camera, microphone, contacts, location, photos, calendars, reminders, notifications, or background modes until a user-visible feature needs them.
  - Update `Info.plist`, App Privacy labels, and privacy policy before adding any native permission.
- HTTPS:
  - Production API base URL must be HTTPS only.
  - Do not submit builds pointing at localhost, raw IPs, self-signed TLS, or non-production staging services.
- App Privacy labels:
  - Declare account identifiers, user-provided assistant content, tasks/reminders/calendar-like items, diagnostics/logs if collected, and any analytics if added.
  - Do not declare tracking unless cross-app/site tracking is deliberately introduced and App Tracking Transparency is implemented.

## URLs still needed from owner

- Privacy Policy URL: TBD
- Support URL: TBD
- Account Deletion / Data Request URL: TBD
- Production API URL: TBD
- Terms of Use URL, if used: TBD

## Pre-submission verification

- Run backend tests: `cd web && php artisan test`
- Run backend style: `cd web && ./vendor/bin/pint`
- Run Flutter tests: `cd app && flutter test`
- Run Flutter analyzer: `cd app && flutter analyze`
- Verify the production Flutter build points at the HTTPS API URL.
- Exercise account deletion on a production-like environment with a test account.
- Confirm App Store screenshots do not expose personal data, secrets, local hostnames, or staging domains.
