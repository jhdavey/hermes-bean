# Bean Presence UI

## Web placement

The Bean logo becomes a circular button at the far left of the topbar. The existing time/day/month controls move rightward, immediately before the nav buttons.

## States

- `privacy`: mic off, no wake detection, calm muted circle.
- `wake_listening`: local wake detection active, subtle pulsing ring.
- `listening`: active user turn after `Hey Bean`, stronger audio-reactive ring.
- `thinking`: shimmer/orbit animation.
- `working`: progress/ring sweep and status text such as `Creating reminder...`.
- `speaking`: soft ripple.
- `error`: warning accent and recoverable status.

## Status pill

Status expands from the Bean circle to the right, e.g. `[Bean] Checking your calendar...`. It maps directly from Bean SSE events.

## Panel

The Bean panel contains text chat, activity log, pending confirmations, and action history. It should not replace the dashboard; it explains what Bean did and lets the user inspect requests/responses/actions.
