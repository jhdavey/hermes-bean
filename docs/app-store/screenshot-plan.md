# HeyBean Screenshot Plan

App Store screenshots should look like real in-app screens, not marketing mockups. Suggested capture flow after App Store Connect listing exists and before final submit.

## Target Screens

1. **Today Calendar**
   - Show the daily calendar section with seeded events.
   - Include a critical count if available.
   - Good caption: `See your day at a glance.`

2. **Bean Chat**
   - Prompt example: `Hey Bean, add soccer practice tomorrow at 6 and remind me at 4.`
   - Show assistant response and inline progress if available.
   - Good caption: `Tell Bean what changed.`

3. **Tasks**
   - Show open tasks with category colors and critical stars.
   - Good caption: `Keep tasks and priorities together.`

4. **Reminders**
   - Show upcoming reminders and natural date/time labels.
   - Good caption: `Remember the small things before they slip.`

5. **Settings / Google Calendar / Privacy**
   - Show Google Calendar connection or privacy/support/account controls.
   - Good caption: `Connect your calendar when you're ready.`

## Device Sizes

At minimum, prepare current required iPhone screenshot sets in App Store Connect. Apple’s required sizes can change, but commonly accepted modern iPhone sets include:

- 6.7" iPhone display screenshots
- 6.5" iPhone display screenshots
- 5.5" iPhone display screenshots, if Apple requests legacy coverage

The iOS project is configured for iPhone-only MVP launch with `TARGETED_DEVICE_FAMILY = 1`, which avoids iPad screenshot requirements and reduces review surface area. Re-enable iPad later after a dedicated iPad layout/review pass.

## Capture Notes

- Use production API unless using a dedicated seeded demo/review account.
- Avoid personal data in screenshots.
- Use the app’s real UI, current icons, and HeyBean branding.
- Do not use the website hero mockup as an App Store screenshot unless it exactly represents the app screen.
