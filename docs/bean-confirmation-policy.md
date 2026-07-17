# Bean Confirmation Policy

## Auto-execute

- Create task/reminder/calendar event/note.
- List/search/read dashboard resources.
- Update or complete an item only when an explicit id is supplied or one unambiguous match is found.
- Date/time and weather lookup.

## Confirmation required

- Any delete.
- Bulk update/delete/reschedule.
- Ambiguous updates where multiple records match.
- Any action affecting another workspace member or membership.
- Any future external side effect such as email/message send.

## MVP behavior

When confirmation is required, Bean records a confirmation request and explains exactly what would change. The action is not executed until an explicit approval endpoint is called.
