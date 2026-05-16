# HeyBean App Store Connect Listing Prep

Use this as the source-of-truth draft when creating the App Store Connect app record.

## App Identity

- App name: HeyBean
- Subtitle: AI planning for real life
- Bundle ID currently in iOS project: `com.hermesbean.hermesBeanApp`
- SKU suggestion: `heybean-ios-001`
- Primary language: English (U.S.)
- Version: `1.0.0`
- Build number: `1`
- Category suggestion: Productivity
- Secondary category suggestion: Lifestyle
- Content rights: No, the app does not contain, show, or access third-party content that requires rights beyond user-provided/connected calendar data.

## Production URLs

- Marketing URL: https://heybean.org/
- Privacy Policy URL: https://heybean.org/privacy
- Terms of Use URL: https://heybean.org/terms
- Support URL: https://heybean.org/support
- Account Deletion URL: https://heybean.org/account-deletion

## Promotional Text

HeyBean turns scattered life admin into a clear daily plan. Capture tasks, reminders, calendar changes, and household updates with one focused AI assistant.

## Description

HeyBean is an AI executive assistant for real life — built for busy people who need one calm place to plan the day, capture what changed, and keep home and work moving.

Tell Bean what changed, and HeyBean helps turn it into organized calendar events, tasks, reminders, and household plans. Use it to keep track of today, prep the week, coordinate shared households, and stay on top of the important things without juggling five different apps.

What HeyBean helps with:

- Plan your day with a focused calendar, task list, and reminders.
- Capture new events, errands, and follow-ups in plain language.
- Keep critical items visible so the most important things do not get buried.
- Organize events, tasks, and reminders with color-coded categories.
- Connect Google Calendar to bring your schedule into one place.
- Create households/workspaces so shared planning can stay organized.
- Review account, privacy, support, export, and deletion options from Settings.

HeyBean is designed for practical daily coordination: appointments, school pickup, workouts, errands, family logistics, work follow-ups, and the small changes that usually get lost in texts or notes.

The MVP is intentionally focused: your calendar, tasks, reminders, and Bean chat in one mobile command center.

## Keywords

AI assistant, calendar, tasks, reminders, planner, productivity, household, schedule, family, personal assistant

## What's New

Initial HeyBean MVP launch: AI planning, calendar, tasks, reminders, Google Calendar connection, households/workspaces, legal/support/account controls, and production-ready app icons.

## App Review Notes

HeyBean is a productivity app that lets users register with email/password, manage calendar events/tasks/reminders, chat with an assistant, connect Google Calendar, and manage account export/deletion from Settings.

Suggested review path:

1. Create an account with an email and 12+ character password.
2. Complete the short onboarding/preferences flow if shown.
3. Use the Calendar, Tasks, Reminders, Bean, and Settings tabs.
4. In Settings, review Privacy, Terms, Support, Account Export, and Delete Account controls.
5. Google Calendar connection is optional. If not connected, the core app still works with local app data.

No special hardware is required.

If Apple needs a demo account, create one manually in production before submission and add it here in App Store Connect. Do not commit demo credentials to git.

## Age Rating Notes

Likely age rating: 4+ or 9+, depending on Apple's questionnaire responses.

Suggested questionnaire stance:

- No unrestricted web access.
- No gambling/contests.
- No medical/treatment information.
- No explicit/sexual content.
- No user-generated public social network content.
- App uses account login and productivity data.
- Assistant output is productivity-focused; if Apple asks about AI/chat, disclose that users can send free-form prompts to the assistant for planning/productivity help.

## App Privacy / Nutrition Label Draft

This must match the final App Store Connect questionnaire and the live privacy policy.

Data collected / linked to user:

- Contact Info: email address, name if provided.
- User Content: calendar events, tasks, reminders, assistant chat messages, workspace/household names, categories/preferences.
- Identifiers: app account ID, authentication token/session identifier.
- Usage Data: activity/progress events needed to operate the assistant and app features.
- Diagnostics: server logs/errors may be processed for reliability/security.

Data use purposes:

- App Functionality
- Account Management
- Product Personalization / assistant preferences
- Analytics / reliability diagnostics, if represented by server logs

Tracking:

- Do not declare tracking unless a future SDK or ad/third-party tracking tool is added.

Third-party data sharing:

- Google Calendar data is accessed only if the user connects Google Calendar.
- Google Calendar access is used to sync selected calendars and create/update calendar events as requested.
- Privacy policy includes Google API Services User Data Policy / Limited Use language.

## Sign In With Apple

Current app supports email/password and Google Calendar OAuth, but not Sign in with Apple.

Apple may require Sign in with Apple only if third-party/social login is offered for account sign-in. Google Calendar OAuth here is an optional calendar connection, not a sign-in method. If Apple flags it, clarify that Google is used only after account creation to connect calendars.

## Screenshot Checklist

Prepare App Store screenshots for required iPhone sizes. Good screens to capture:

1. Today calendar with seeded events and critical count.
2. Bean chat showing a planning prompt/result.
3. Tasks with categories/critical items.
4. Reminders with date/time picker or list view.
5. Settings showing privacy/support/account controls or Google Calendar connection.

Suggested captions:

- Tell Bean what changed.
- See your day at a glance.
- Tasks, reminders, and calendar together.
- Keep critical items visible.
- Connect your calendar when you are ready.

## Before Archive/Submit

- Confirm App Store Connect app record uses bundle ID `com.hermesbean.hermesBeanApp`, or update the project before archiving if you choose a different bundle ID.
- Confirm app display name: HeyBean.
- Confirm version: 1.0.0, build: 1.
- Log into Xcode with the Apple Developer account.
- Ensure a valid Apple Distribution signing identity/provisioning profile is available.
- Fill in privacy nutrition labels from this draft.
- Add privacy/support/marketing URLs.
- Upload screenshots.
- Add demo credentials only in App Store Connect if needed; never commit them.
