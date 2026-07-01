# Guided Onboarding Tour Demo Panels

This spec defines the short scripted demo panels shown after guided signup when the user asks Bean for a dashboard tour. The first implementation uses native mock panels in Flutter. These requirements describe the higher-fidelity 3-5 second cropped recordings or animated panels to create next.

## Global Setup

- Seed date: Tuesday, July 7, 2026.
- Seed workspace: `Personal`.
- Seed accent: use the user's selected theme color, default green for unauthenticated/signup.
- Device frame: iPhone portrait, 430 x 932 logical reference.
- Capture style: cropped to the active app area only, no simulator chrome.
- Timing: 3-5 seconds each, loopable, no hard cuts, no audio baked into the asset.
- Bean narration: rendered as chat text beside or above the panel, not embedded in the video.
- Motion: focus highlight should be subtle, using the active theme color with a thin outline and low-alpha fill.
- Accessibility: every panel needs a static text fallback and can be skipped.

## Panel 1: Command Center

Goal: Show that the dashboard is a live command center where Bean and the user's day stay connected.

Seed items:
- Calendar event: `8:30 AM Team sync`, 30 minutes.
- Task: `Review launch notes`, due today at 12:15 PM, category `Work`.
- Reminder: `Move laundry`, today at 6:00 PM.

Script:
1. Start on the main Today/Bean dashboard for July 7, 2026.
2. Show the Bean button active at the bottom.
3. Display a short user prompt in the composer: `Plan my afternoon`.
4. Highlight the today list in order:
   - Event row: `8:30 AM Team sync`
   - Task row: `Review launch notes`
   - Reminder row: `6:00 PM Move laundry`
5. End with the Bean button and today list both visible.

Bean narration:
`This is your command center. Ask Bean for what you need, and your events, tasks, and reminders stay visible as the day changes.`

Acceptance:
- The user can tell within 2 seconds that Bean is the primary control.
- The highlighted rows clearly show one event, one task, and one reminder.
- No unrelated dashboard clutter appears in the crop.

## Panel 2: Calendar Views

Goal: Show that top controls move between day and month calendar views.

Seed items:
- Calendar event: `Dentist`, July 7, 2026, 10:00 AM-11:00 AM.
- Calendar event: `Dinner with Lauren`, July 7, 2026, 6:30 PM-8:00 PM.
- Calendar event: `Beach day`, July 11, 2026, all day.

Script:
1. Start on day view with July 7 selected.
2. Tap the top day button, then show it returning to today.
3. Tap the month button.
4. Transition to month view.
5. Highlight July 7 and July 11 event dots/items.

Bean narration:
`Calendar buttons at the top help you move between today, day view, and month view without losing your place.`

Acceptance:
- Day and month controls are visibly distinct.
- The transition does not feel like a page jump; use a 150-250 ms ease.
- Calendar text remains readable in dark and light themes.

## Panel 3: Tasks

Goal: Show task creation and completion.

Seed items:
- Existing task: `Review launch notes`, due July 7 at 12:15 PM.
- Existing task: `Order air filters`, due July 8 at 7:00 PM.

Script:
1. Start on Tasks screen.
2. Show a quick create action adding `Send invoice`.
3. New task appears at the top or correct due bucket.
4. Tap checkbox for `Review launch notes`.
5. Task checks off with a short completion animation and moves out of active list or becomes visibly completed.

Bean narration:
`Tasks are for things you need to complete. Bean can create them from a sentence, and you can check them off when done.`

Acceptance:
- The creation action is obvious without needing explanatory labels.
- The checked state is visible on first glance.
- No task row shifts unpredictably before the completion animation finishes.

## Panel 4: Reminders

Goal: Show reminders as lightweight time-based nudges separate from tasks.

Seed items:
- Reminder: `Take vitamins`, July 7 at 8:00 AM.
- Reminder: `Move laundry`, July 7 at 6:00 PM.
- Reminder: `Call Mom`, July 12 at 5:00 PM.

Script:
1. Start on Reminders screen.
2. Show quick create adding `Water plants tomorrow morning`.
3. Highlight the created reminder's time.
4. Tap complete on `Take vitamins`.
5. Show remaining reminders still visible.

Bean narration:
`Reminders are lightweight nudges. Use them for quick time-based follow-up without cluttering your task list.`

Acceptance:
- Reminders do not look identical to tasks; time should be more prominent.
- Completed reminder feedback is immediate.
- The panel reinforces that reminders are time-based.

## Panel 5: Notes

Goal: Show notes, folders, and formatting.

Seed folders:
- `House`
- `Travel`
- `Ideas`

Seed note:
- Folder: `Travel`
- Title: `Ireland plan`
- Body:
  - `Flights`
  - `Hotels`
  - `Packing`

Script:
1. Start on Notes screen.
2. Select `Travel` folder.
3. Open `Ireland plan`.
4. Select `Packing` and apply a checkbox or bullet format.
5. Apply bold to `Flights`.
6. End with formatted note visible.

Bean narration:
`Notes hold plans, lists, and longer writing. Folders keep them organized, and formatting helps structure what matters.`

Acceptance:
- Folder selection is clear.
- Formatting change is visible without zooming.
- The note editor feels useful, not like a static text preview.

## Implementation Notes

- Store production assets under `app/assets/onboarding/tour/` once recordings or generated animations exist.
- Keep the native Flutter mock panels as fallback when assets fail to load.
- Do not use real user data in recordings.
- Each panel should have a deterministic seeded state so screenshots and tests remain stable.
- The tour must remain optional and skippable at every step.
