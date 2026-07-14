# Browser Voice v2 release evidence

Last updated July 13, 2026. This document covers authenticated browser voice
only. Flutter/native voice is out of scope. `bean-voice-rules.md` is the
authoritative product contract; this file records implementation and evidence,
not new product behavior.

## Current status

The audited local candidate satisfies every deterministic repository gate. It
is not a claim of 100% real-world reliability and is not representative acoustic
certification. The candidate still needs deployment followed by the owner's
physical-microphone smoke on the deployed development site. No current local
result below is presented as evidence that production already contains these
changes.

Wake assets are runtime v12. The wake detector is first-party and
self-contained: no external wake service, account, license key, remote
inference, or runtime network request. Incident phrases are held-out QA inputs
only; production decision code contains no phrase-specific negative aliases or
exceptions.

## July 13 full-system audit

The audit followed the complete journeys in `bean-voice-rules.md`, not the last
reported symptom. It found seven structural defects that could produce the
repeating wake, follow-up, write, dock, response, and diagnostic inconsistencies:

1. Browser and server both owned semantic completeness. The browser could split
   a complete request or ask a generic question before Laravel saw the full
   transcript.
2. queue middleware and the transactional voice scheduler both owned v2 job
   ordering. A run could wait or exhaust attempts outside the durable lifecycle.
3. the complex runtime could persist a provisional assistant message before the
   voice finalizer persisted the durable final. Chat and speech could therefore
   observe different response writers.
4. task writes had a second time parser independent from the typed reminder and
   calendar parser, allowing the same spoken clock time to resolve differently;
5. pre-turn browser failures were written only to the application log, so wake,
   startup, admission, clarification, connection, and usage-accounting failures
   could be absent from the admin quality report;
6. the multi-engine wake runner awaited an in-page replay promise without an
   enforceable outer deadline, so a stalled browser engine could hang the gate;
   and
7. the strict-wake verifier operating point and 100 ms worklet batch had only
   Chromium evidence. WebKit exposed both insufficient cross-engine recall and
   inadequate latency headroom against the 500 ms p95 contract.

The candidate removes those duplicate owners:

- `BrowserVoiceControllerV2` alone owns browser conversation state, endpointing,
  live draft visibility, follow-up gating, and interruption transitions.
- the browser performs only a domain-agnostic check for an unmistakably open
  grammatical boundary. Laravel is the sole semantic completeness and specific
  clarification authority.
- `VoiceTurnLifecycleService` alone owns v2 capacity, priority, dependencies,
  resource serialization, terminal state, and the final assistant message.
  `ProcessAssistantRun` uses no `WithoutOverlapping` middleware for a v2 run.
- the complex runtime returns generated response text without persisting a
  provisional voice response. The lifecycle finalizer persists exactly one
  assistant message after the outcome is known.
- task, reminder, and calendar writes share the typed date/time parser.
- projected durable final text is the exact input to HTTP speech synthesis; the
  speech endpoint rejects a text mismatch.
- Realtime is transcription-only: `create_response=false`,
  `interrupt_response=false`, no tools, and no provider response authority.
- one-word conversational answers such as “yes” are accepted without another
  wake only when Bean's preceding durable response actually asked a question.
  The same room speech after a normal answer remains private and invisible.
- the generic acoustic verifier, rather than phrase exceptions, owns every
  strict-wake candidate decision.
- sanitized pre-turn client failures are durable admin diagnostic events; the
  server sanitizes them independently of the browser and owns no lifecycle
  transition through that telemetry path.
- each acoustic-engine replay and adapter benchmark now has a tested outer
  deadline, so the command records an engine failure instead of hanging.
- the single generic strict-wake verifier is calibrated from held-out
  cross-engine scores, and the local worklet sends bounded 80 ms batches. No
  phrase-specific branch was added to recover recall or latency.

Legacy `voice_turn_sequence` metadata remains only in the non-v2 typed/native
compatibility path. It is not read by Browser Voice v2. No legacy browser work
queue, provider tool bridge, wake ASR owner, per-user allowlist, or release flag
exists beside the v2 owners.

## Contract-to-test traceability

| Contract journey | Primary deterministic proof |
| --- | --- |
| Fresh-load readiness and first wake | `[BV2-STARTUP-01..04]`, `[BV2-WAKE-01]`, `[BV2-WAKE-03..04]`, `[BV2-WAKE-08..10]`, `[BV2-BROWSER-01]` |
| Dormant privacy, missed `Hey`, strict wake, third-person mention, generic near-miss rejection, and re-arm | `[BV2-WAKE-11]`, local-wake gate tests, `[BV2-BROWSER-01]`, v12 prerecorded corpus |
| Live partials and exact two-second endpoint | `[BV2-TRANSCRIPT-01..04]`, `[BV2-BROWSER-01]` |
| Silent open-fragment continuation and durable five-second clarification | `[BV2-CLARIFY-01..06]`, `[BV2-BROWSER-14]`, lifecycle clarification journeys |
| Fifteen-second follow-up, expected one-word answer, ambient rejection, and strict-wake reset | `[BV2-FOLLOWUP-01..08]`, `[BV2-CONTEXT-01]`, `[BV2-BROWSER-08..09]`, `[BV2-BROWSER-13]` |
| Meaningful/false interruption and playback-only Stop | `[BV2-BARGE-01..04]`, `[BV2-STOP-01..05]`, `[BV2-BROWSER-02..03]` |
| Natural client-timezone time/date | instant time/date lifecycle tests |
| Typed calendar/task/reminder/note reads and read bypass | typed-read and three-job scheduler lifecycle journeys |
| Typed writes, shared temporal parsing, correction, and exactly-once receipt | lifecycle and work-control write journeys; `[BV2-BROWSER-09]` |
| Local/remote weather routing, retry, and scoped failure | lifecycle weather/provider/context journeys |
| Complex generated note and exactly one durable response | runtime-failure generated-note journeys; `[BV2-BROWSER-12]` |
| Three running jobs, visible fourth queue, dependency and resource serialization | work-control scheduler journeys; `[BV2-BROWSER-03]` |
| Exact chat/speech parity and non-overlapping acknowledgement/final | `[BV2-ACK-01..03]`, `[BV2-SPEECH-01..05]`, `[BV2-SPEECH-TRANSPORT-01..05]` |
| Reload, reconnect, stale/out-of-order events, and no duplicate/replay | `[BV2-RELOAD-01..02]`, `[BV2-RECOVERY-01]`, `[BV2-SEQUENCE-01..02]`, `[BV2-BROWSER-04..06]` |
| Ambiguous admission, idempotent recovery, and final-delivery retry | `[BV2-ADMISSION-01..08]`, `[BV2-DELIVERY-01]` |
| Provider/worker/transport/deadline faults terminate naturally and remain diagnosable | runtime-failure, deadline, diagnostic, and `[BV2-BROWSER-05]` journeys |
| Per-user plan usage, upgrade response, admin unlimited, and exact-once accounting | voice usage, plan entitlement, runtime limit, and `[BV2-USAGE-01..03]` journeys |
| One accepted message, one terminal state, one final, no raw audio persistence | lifecycle idempotency and invariant-audit tests |

## Automated gate results for this candidate

| Gate | Result |
| --- | --- |
| Full PHP application suite | **Pass:** 439/439 tests, 4,579 assertions; direct PHPUnit process with 512 MB limit |
| Focused Browser Voice PHP audit | **Pass:** 173/173 tests, 1,930 assertions |
| Explicit diagnostic/admin subset | **Pass:** 23/23 tests, 288 assertions |
| Explicit fault/recovery subset | **Pass:** 99/99 tests, 1,021 assertions |
| Browser Voice JavaScript | **Pass:** 130/130 tests |
| Playwright complete browser journeys | **Pass:** 12/12 journeys |
| Replay corpus privacy/schema and bounded-runner behavior | **Pass:** 5/5 tests |
| Default multi-engine wake/adapter replay | **Pass:** 3/3 executed engines; Edge explicitly not installed |
| Production Vite build | **Pass:** `app-BVJPtKbR.js`, `app-BNZ4BLyh.css` |
| Wake asset SHA-256 manifest | **Pass:** every listed v12 asset |
| Changed PHP Pint check | **Pass** |
| Composer strict validation | **Pass** |
| `git diff --check` | **Pass** |
| Local invariant command | **Pass:** zero violations; local database contained zero voice turns, so populated invariant proof comes from deterministic tests |

The first `php artisan test` invocation inherited Laravel's spawned worker default
of 128 MB and stopped after 357 passing tests/3,828 assertions in the unrelated
`TopWorkspaceSwitcherAssetTest`. The direct PHPUnit invocation applied 512 MB to
the actual test process and completed all 439 tests. This was runner memory
configuration, not an assertion failure, and is recorded to avoid hiding the
failed invocation.

## Wake-model evidence

The v12 manifest records a 119/120 (99.17%) deterministic prerecorded Bean
Voice QA result against the agreed 95% acceptance threshold independently in
Playwright Chromium, installed Google Chrome, and Playwright WebKit: 24/24
isolated strict wakes, 6/6 wake-plus-command releases, 6/6 continuous-speech
wakes, 23/24 missed-`Hey` address journeys, 0/54 false activations across nine
privacy families, 6/6 reject/reset/immediate-wake recoveries, zero
pre-confirmation PCM, complete activated-PCM handoff, and no runtime errors in
each engine. Wake p95 was 424.9 ms in Chromium, 427.8 ms in installed Chrome,
and 479 ms in WebKit, all below the 500 ms contract target.

The corpus includes the reported incident phrases as held-out negatives, along
with phonetic near misses, third-person Bean mentions, ordinary conversation,
and other privacy families. Those strings do not occur in production decision
code or training negatives. The first-party classifier must reject them by the
same generic acoustic rule used for all candidates.

The unchanged default `npm run benchmark:voice:browser` command passed all
three executed engines. Microsoft Edge was explicitly classified as not
installed; installed Safari cannot be automated by this Playwright runner. Its
JSON distinguishes local model/gate evidence from provider/network and
representative product evidence and never emits raw PCM. Playwright WebKit is an
engine proxy, not installed Safari; an installed Chrome headless replay is not a
physical-microphone or audible product certification; Edge is not installed on
this machine.

## Deployment and remaining external verification

The public development site previously passed deployment-presence preflight for
an older bundle. That historical result does not certify this local candidate.
After these changes are reviewed and deployed:

1. run `npm run preflight:voice:production` to confirm the enabled shell marker,
   current hashed client bundle, v12 manifest/worker, health endpoint, and all
   authenticated route boundaries;
2. run the production invariant audit against populated production data;
3. use the owner's account for fresh-load first wake, microphone restart, live
   partials, two-second endpoint, expected and ambient follow-ups, background
   audio rejection, false and meaningful barge-in, playback Stop, explicit job
   cancellation, three-job concurrency, read bypass, local/remote weather,
   direct writes, contextual corrections, complex note creation, provider fault,
   reload/reconnect, exact chat/speech parity, usage-limit upgrade UX, and admin
   diagnostics; and
4. record visible/audible Chrome, Safari, and Edge samples in quiet speech,
   background music, nearby conversation, and speaker-echo conditions with the
   p50/p95 metrics required by `bean-voice-rules.md`.

Until that physical deployed-development evidence exists, the accurate status
is: deterministic local candidate passed; representative release certification
pending.
