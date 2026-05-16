# HeyBean App Store Privacy Questionnaire Draft

Use this as a practical guide when filling App Store Connect → App Privacy. Treat Apple’s live labels/questions as authoritative.

## Tracking

- Does this app track users across apps and websites owned by other companies? **No**

Only change this if analytics/ad/tracking SDKs are added later.

## Data Types Likely Collected

### Contact Info

- Email Address: **Yes**
  - Linked to user: Yes
  - Used for: App Functionality, Account Management
- Name: **Only if collected/provided**
  - Linked to user: Yes
  - Used for: App Functionality, Account Management

### User Content

- Other User Content: **Yes**
  - Includes assistant chat messages, tasks, reminders, calendar events, categories, workspace/household names, preferences.
  - Linked to user: Yes
  - Used for: App Functionality, Product Personalization

### Identifiers

- User ID: **Yes**
  - Includes internal account/user/workspace IDs and bearer-token/session records.
  - Linked to user: Yes
  - Used for: App Functionality, Account Management, Fraud Prevention/Security

### Usage Data

- Product Interaction: **Yes, if Apple considers activity/progress logs as usage data**
  - Includes assistant/session activity events used to operate the app experience.
  - Linked to user: Yes
  - Used for: App Functionality, Analytics/reliability if applicable

### Diagnostics

- Crash Data / Performance Data / Other Diagnostic Data: **Yes, if server logs or platform diagnostics are reviewed for reliability**
  - Linked to user: Possibly, because authenticated requests can be associated with accounts.
  - Used for: App Functionality, Analytics/reliability

## Data Not Intentionally Collected

- Precise location
- Contacts/address book
- Health/fitness data
- Financial info
- Sensitive info
- Browsing history
- Search history
- Purchases
- Advertising data

## Google Calendar Disclosure

If the user connects Google Calendar, HeyBean accesses selected Google Calendar data to:

- List/sync selected calendars.
- Import calendar events into HeyBean views.
- Create/update/delete calendar events when requested by the user.

This is user-controlled and optional. The privacy policy includes Google API Limited Use language.

## Account Deletion / Export

Declare that users can request/delete account data from in-app Settings and public account-deletion instructions:

- In app: Settings → Export Account Data / Delete Account
- Public instructions: https://heybean.org/account-deletion

## Notes for Review Consistency

- Do not claim “data not linked to user” for core app data; the app is account-based.
- Do not claim “not collected” for chat/calendar/tasks/reminders; those are core app functionality.
- Do not declare tracking unless a tracking SDK or cross-app ad measurement is introduced.
