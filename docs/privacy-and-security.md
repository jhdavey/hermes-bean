# Hermes Bean Privacy and Security

This document describes the current privacy/security posture and the controls that must stay true for a production App Store launch.

## Data inventory

- Account data:
  - Name
  - Email address
  - Hashed password
  - Hashed API access tokens
  - Token timestamps, including last-used and optional expiry fields
- Assistant content:
  - Conversation sessions and messages
  - Runtime/activity events
  - User-created tasks, reminders, calendar-like events, approvals, and blockers
  - Metadata attached to assistant sessions/messages/resources
- Operational data:
  - Server request logs and application logs may include IP address, user agent, timestamps, endpoint paths, status codes, and error context depending on deployment logging configuration.
- Data not currently collected by the app by default:
  - Precise location
  - Contacts
  - Photos/media library
  - Camera or microphone input
  - Device advertising identifier
  - Cross-app tracking data
  - Native calendar/reminder store access

## Privacy policy content to publish

The public privacy policy should cover:

- What data Hermes Bean collects and why.
- How assistant content is used to provide planning, reminders, scheduling, and follow-up features.
- Whether any subprocessors, LLM/runtime providers, hosting providers, analytics, crash reporters, or monitoring services process user content.
- How users export data through `GET /api/account/export`.
- How users delete their account in-app and what deletion removes.
- Data retention windows for backups, logs, abuse-prevention records, and support requests.
- Contact channels for privacy requests and support.
- Jurisdiction-specific rights if the app is offered in GDPR/CCPA or similar regions.

## Account deletion

- App UX: after sign-in, the command center shows **Account settings** with a **Delete account** action.
- API: `DELETE /api/account` requires a valid bearer token.
- Current behavior: deletes the authenticated user and relies on database cascade constraints/relationships to remove owned tokens and assistant-domain data.
- Tests cover route availability, auth requirement, token invalidation after deletion, owned data deletion, and other-user data isolation.
- Production follow-up: consider adding a final confirmation dialog and optional recent-password re-auth before destructive deletion, while keeping the route discoverable and App Review-compliant.

## Authentication and tokens

- Passwords are hashed by the Laravel user model cast.
- API tokens are generated from 32 random bytes and stored only as SHA-256 hashes.
- Auth middleware resolves the user from a valid, unexpired bearer token.
- Logout deletes the presented token.
- Account deletion removes the user and stored personal access tokens.
- Production follow-up: set explicit token expiry/rotation policy and document session lifetime in the privacy policy.

## User data isolation

- Auth-required API routes are grouped behind bearer-token middleware.
- Route-model access to assistant sessions is scoped to the authenticated owner.
- Domain resource writes verify that supplied session IDs belong to the requesting user.
- Export returns only records owned by the authenticated user.
- Tests cover cross-user session access, cross-user resource attachment, export isolation, and account deletion isolation.

## Network and API security controls

- HTTPS is required for production API traffic.
- CORS is allow-list based through `HERMES_ALLOWED_ORIGINS`; avoid wildcard origins in production.
- API responses include basic security headers:
  - `X-Content-Type-Options: nosniff`
  - `X-Frame-Options: DENY`
  - `Referrer-Policy: no-referrer`
  - `Permissions-Policy: camera=(), microphone=(), geolocation=()`
  - `Cache-Control: no-store`
- API rate limiting is configurable through:
  - `HERMES_API_RATE_LIMIT_PER_MINUTE`
  - `HERMES_API_RATE_LIMIT_DECAY_SECONDS`
- JSON auth/rate-limit errors use a stable `error.code` and `error.message` shape.

## Retention and deletion policy to finalize

Owner decisions still needed:

- How long server logs are retained.
- How long database backups are retained after account deletion.
- Whether support tickets or privacy requests are kept separately.
- Whether abuse-prevention records are retained after account deletion.
- Whether assistant/runtime providers store prompts, messages, or telemetry and for how long.

## Minimal-permission policy

- Keep the mobile app permissionless unless a feature requires native capabilities.
- Before adding any permission, document the purpose, update App Privacy labels, add `Info.plist` usage strings, and add tests/manual QA for denied-permission behavior.
- Current policy is compatible with a low-risk App Store privacy posture because Hermes Bean does not need camera, microphone, contacts, location, photos, native calendar, native reminders, or tracking permissions for the current feature set.

## Security checklist before production

- Set `APP_ENV=production`, `APP_DEBUG=false`, and a strong `APP_KEY`.
- Set `HERMES_ALLOWED_ORIGINS` to the exact production app/web origins.
- Set a production HTTPS `APP_URL` and production Flutter API base URL.
- Use managed TLS certificates and redirect HTTP to HTTPS at the edge/load balancer.
- Store secrets only in the deployment secret manager; never commit `.env` values.
- Configure database backups, encryption at rest, and restoration drills.
- Configure centralized logs with redaction for bearer tokens and passwords.
- Add monitoring/alerting for 5xx rates, auth failures, queue/runtime failures, and elevated 429 rates.
- Review LLM/runtime subprocessors and data-processing terms before sending real user content.
- Run dependency audits and keep Laravel/Flutter dependencies patched.
- Run all automated tests and smoke-test account export/deletion on production-like infrastructure.
