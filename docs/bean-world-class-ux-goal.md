# Bean World-Class UX Goal

## Goal

Make Bean feel like a dependable household AI operator: fast, context-aware, voice-native, and boringly reliable.

Current user-rated experience baseline: **4/10**. Reported gaps: slow responses, bugs in normal conversations, failed follow-up turns, background audio creeping into chat history, and dashboard/Hermes failures surfacing in everyday use.

## Product direction

Bean is Hermes-first:

```text
Bean Web / Flutter / Voice UI
  → Laravel Bean API: auth, session mapping, activity/UI mirror, instrumentation
    → per-user Hermes agent with isolated HERMES_HOME
      → bean_dashboard Hermes tool
        → Laravel scoped dashboard executor
          → Bean database/dashboard state
```

Do **not** rebuild a local deterministic Bean brain. Laravel remains a scoped tool host, auth/safety boundary, TimeContext normalizer, instrumentation layer, and UI mirror. Hermes/model owns conversation memory, reasoning, tool choice, and final wording.

## World-class benchmarks

### 1. Task success

Targets:

- ≥95% successful first-try completion for common dashboard tasks.
- ≥90% successful completion for multi-step tasks.
- ≤2% generic failure responses: `I could not complete that request.`
- 0 exposed internal errors.
- Dashboard facts must be grounded in dashboard tool results.

Measured by `php artisan bean:ux-benchmark` from `bean_runs`, `bean_tool_calls`, and `bean_quality_traces`.

### 2. Voice reliability

Targets:

- ≥98% first-command capture success after wake word.
- ≥95% follow-up capture success after Bean speaks.
- ≤1% background-audio false submissions.
- ≤1% assistant echo/duplicate submissions.
- 100% failure turns exit back to wake-word-only mode.
- 100% dismiss/stop commands work.

Measured by `bean_voice_events` lifecycle events:

```text
wake_detected
voice_session_started
user_transcript_received
bean_request_sent
bean_response_received
assistant_speech_started
assistant_speech_finished
followup_window_opened
followup_transcript_received
followup_window_expired
voice_session_closed
background_audio_ignored
background_audio_submitted
assistant_echo_ignored
assistant_echo_submitted
duplicate_submission
dismiss_command_detected
dismiss_closed
failure_wake_reset
```

### 3. Speed and continuity

Targets:

- Text p50 simple response <2s.
- Text p50 dashboard query <5s.
- Text p95 dashboard query <12s.
- Multi-step p95 <20s.
- Voice wake-to-listening <1s.
- End of user speech to visible thinking <500ms.
- End of speech to spoken dashboard answer <7s target.
- Follow-up window opens within 700ms after Bean stops speaking.
- ≥95% correct follow-up/reference resolution.
- ≤3% unnecessary clarification rate.
- ≤2% immediate context-loss rate.

The first instrumentation milestone measures backend/run latency and voice funnel reliability. Follow-up/reference quality and unnecessary clarification need evaluator scenarios plus review labels as the next milestone.

## Durable continuity system

A future session must be able to resume without chat history:

1. Read this file.
2. Read `docs/bean-world-class-ux-progress.json`.
3. Run:

```bash
cd web
php artisan bean:ux-benchmark --days=7
php artisan bean:evaluate --production-smoke --recent=200
```

4. Use the benchmark report to pick the largest failing target cluster.
5. Update `docs/bean-world-class-ux-progress.json` after meaningful work blocks or by rerunning `bean:ux-benchmark`.

Never rely only on Hermes/session memory for the project plan.

## Verification gates before claiming progress

Run locally:

```bash
cd web
php artisan test
npm test
npm run build
php artisan bean:ux-benchmark --days=7
```

For production-safe verification:

```bash
cd /home/forge/heybean.org/current
php artisan bean:evaluate --production-smoke --recent=200
php artisan bean:ux-benchmark --days=7
```

Live production probes are allowed only when safe/read-only or when test data is immediately cleaned up.

## Current implementation milestones

### Milestone 1 — scorecard and continuity

- Add `bean_voice_events` lifecycle instrumentation.
- Add `php artisan bean:ux-benchmark` daily/7-day scorecard.
- Add durable goal/progress docs.
- Add tests for voice event recording and benchmark aggregation.

### Milestone 2 — evaluation suite

- Add repeatable local/staging/prod-safe UX scenarios using the real Hermes/tool loop where safe.
- Cover common dashboard queries, CRUD, shared workspace queries, follow-up references, destructive confirmations, source lookup + note creation, voice failure recovery, background audio, and echo rejection.

### Milestone 3 — close gaps

- Use measured failure clusters to improve Hermes home/session/runtime/tool bridge reliability.
- Improve follow-up/reference continuity through Hermes sessions/memory/tool result grounding.
- Improve latency without adding a local Bean brain.
- Harden voice against background audio and failure-mode leaks.
