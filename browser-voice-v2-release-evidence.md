# Browser Voice v2 release evidence

Last updated July 14, 2026. This document covers authenticated browser voice
only. Flutter/native voice is out of scope. `bean-voice-rules.md` is the
authoritative product contract; this file records implementation and evidence,
not new product behavior.

## Current status

The audited candidate satisfies every deterministic repository gate and was
deployed to the development production site as commit `0377ac34` on July 14,
2026. This is not a claim of 100% real-world reliability and is not
representative acoustic certification. The candidate still needs the owner's
physical-microphone and audible-response smoke on the deployed site.

Wake assets are runtime v13. The wake detector is first-party and
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

## July 14 speech-delivery follow-up

The first deployed audit candidate exposed a separate response-delivery defect:
the browser started one 20-second timer before HTTP synthesis and left it armed
for the full audio duration. Provider generation, two complete HTTP buffers,
and playback all consumed the same deadline. A response could therefore remain
silent for several seconds, begin playing, and then be aborted mid-sentence.

The corrected path keeps the same single `BrowserVoiceSpeechSchedulerV2`
ordering owner and makes only its transport progressive:

- the exact durable acknowledgement or final text remains the only speech
  input, and the server continues to reject a text mismatch;
- the server requests headerless 24 kHz signed 16-bit little-endian PCM and
  streams provider chunks through the authenticated same-origin endpoint with
  proxy buffering disabled;
- the browser schedules PCM through one reusable `AudioContext` as chunks
  arrive, primed synchronously by the Bean-button gesture for autoplay-gated
  browsers;
- the start deadline is cleared only when the first audio buffer begins and can
  never terminate a response already playing;
- Stop aborts the HTTP reader and audio nodes but does not cancel durable work;
  late chunks cannot restart speech; and
- truncated or failed playback records its bounded error code and message on
  the durable turn for admin diagnostics instead of reporting false completion.

The default speech model changed from `tts-1` to `gpt-4o-mini-tts` after live
same-machine first-byte checks of comparable short answers measured 1,891 ms
and 2,434 ms for `tts-1`, versus 613 ms and 1,281 ms for the new default. These
four observations are diagnostic samples, not a p95 certification. The selected
model remains configurable through `OPENAI_SPEECH_MODEL`, and speech usage is
still preflighted and metered once per stable speech item.

## July 14 dormant-privacy follow-up

Production durable evidence correlated the reported dog-directed conversation
to one `new_conversation` turn more than seven minutes after the preceding Bean
response. That ruled out the 15-second follow-up window and its timers. The
false turn contained the full ambient sentence, identifying the local
missed-`Hey` path as the activation source rather than a strict `Hey Bean`
release.

The defect was an evidence-composition error in the single wake worker: the
first-party missed-`Hey` classifier could promote arbitrary utterance audio
without an independent local indication that the user had said “Bean.” Runtime
v13 retains one decision owner and now requires both signals before opening the
gate:

- a proposal-only, on-device timing candidate for “Bean”; and
- acceptance by the existing first-party address classifier.

Neither signal can activate by itself. Strict `Hey Bean` continues to use its
strict local timing stream and the same first-party verifier. Candidate speech
remains invisible and no pre-confirmation PCM or text leaves the browser. The
reported sentence is a held-out QA input only; production code contains no
phrase-specific ignore rule.

## July 14 named-reschedule follow-up

Production evidence for a fully specified request to reschedule the overdue
task titled “Clean Outdoor Grout” showed correct admission to
`app.task.reschedule`, followed by a typed-executor failure with no committed
side effect. The write path had conflated two different temporal roles: it used
the requested destination time both to find the existing target and to update
it. Its separate title parser also failed when a named target was followed by a
destination.

The existing `BrowserVoiceTypedWriteParser` now owns named mutation targets,
current target times, and reschedule destinations as distinct values. Both job
serialization and authoritative execution consume that same interpretation;
the duplicate target-title parsers were removed. `[BV2-WRITE-01]` exercises the
exact production sentence end to end with a decoy task already at the requested
destination, then verifies the correct resource receipt, final response, replay
idempotency, dock state, and reload reconstruction.

## Contract-to-test traceability

| Contract journey | Primary deterministic proof |
| --- | --- |
| Fresh-load readiness and first wake | `[BV2-STARTUP-01..04]`, `[BV2-WAKE-01]`, `[BV2-WAKE-03..04]`, `[BV2-WAKE-08..10]`, `[BV2-BROWSER-01]` |
| Dormant privacy, missed `Hey`, strict wake, third-person mention, generic near-miss rejection, and re-arm | `[BV2-WAKE-11]`, local-wake gate tests, `[BV2-BROWSER-01]`, `[BV2-BROWSER-13]`, v13 prerecorded corpus |
| Live partials and exact two-second endpoint | `[BV2-TRANSCRIPT-01..04]`, `[BV2-BROWSER-01]` |
| Silent open-fragment continuation and durable five-second clarification | `[BV2-CLARIFY-01..06]`, `[BV2-BROWSER-14]`, lifecycle clarification journeys |
| Fifteen-second follow-up, expected one-word answer, ambient rejection, and strict-wake reset | `[BV2-FOLLOWUP-01..08]`, `[BV2-CONTEXT-01]`, `[BV2-BROWSER-08..09]`, `[BV2-BROWSER-13]` |
| Meaningful/false interruption and playback-only Stop | `[BV2-BARGE-01..04]`, `[BV2-STOP-01..05]`, `[BV2-BROWSER-02..03]` |
| Natural client-timezone time/date | instant time/date lifecycle tests |
| Typed calendar/task/reminder/note reads and read bypass | typed-read and three-job scheduler lifecycle journeys |
| Typed writes, shared temporal parsing, named-target reschedule, correction, and exactly-once receipt | lifecycle and work-control write journeys; `[BV2-WRITE-01]`; `[BV2-BROWSER-09]` |
| Local/remote weather routing, retry, and scoped failure | lifecycle weather/provider/context journeys |
| Complex generated note and exactly one durable response | runtime-failure generated-note journeys; `[BV2-BROWSER-12]` |
| Three running jobs, visible fourth queue, dependency and resource serialization | work-control scheduler journeys; `[BV2-BROWSER-03]` |
| Exact chat/speech parity, progressive playback, no deadline truncation, and non-overlapping acknowledgement/final | `[BV2-ACK-01..03]`, `[BV2-SPEECH-01..05]`, `[BV2-SPEECH-TRANSPORT-01..10]` |
| Reload, reconnect, stale/out-of-order events, and no duplicate/replay | `[BV2-RELOAD-01..02]`, `[BV2-RECOVERY-01]`, `[BV2-SEQUENCE-01..02]`, `[BV2-BROWSER-04..06]` |
| Ambiguous admission, idempotent recovery, and final-delivery retry | `[BV2-ADMISSION-01..08]`, `[BV2-DELIVERY-01]` |
| Provider/worker/transport/deadline faults terminate naturally and remain diagnosable | runtime-failure, deadline, diagnostic, and `[BV2-BROWSER-05]` journeys |
| Per-user plan usage, upgrade response, admin unlimited, and exact-once accounting | voice usage, plan entitlement, runtime limit, and `[BV2-USAGE-01..03]` journeys |
| One accepted message, one terminal state, one final, no raw audio persistence | lifecycle idempotency and invariant-audit tests |

## Automated gate results for this candidate

| Gate | Result |
| --- | --- |
| Full PHP application suite | **Pass:** 440/440 tests, 4,625 assertions; direct PHPUnit process with 512 MB limit |
| Focused affected Browser Voice PHP journeys | **Pass:** 78/78 tests, 829 assertions |
| Explicit diagnostic/admin/fault subset | **Pass:** 63/63 tests, 756 assertions |
| Browser Voice JavaScript | **Pass:** 135/135 tests |
| Playwright complete browser journeys | **Pass:** 12/12 journeys |
| Replay corpus privacy/schema and bounded-runner behavior | **Pass:** 5/5 tests |
| Default multi-engine wake/adapter replay | **Pass:** 3/3 executed engines; Edge explicitly not installed |
| Production Vite build | **Pass:** `app-PZKAlOxq.js`, `app-BNZ4BLyh.css` |
| Wake asset SHA-256 manifest | **Pass:** every listed v13 asset, including worker and worklet byte counts |
| Changed PHP Pint check | **Pass** |
| Composer strict validation | **Pass** |
| `git diff --check` | **Pass** |
| Local invariant command | **Pass:** zero violations; local database contained zero voice turns, so populated local invariant proof comes from deterministic tests |
| Populated production invariant command | **Pass:** zero violations across 24 turns, 48 messages, 22 runs, and 233 events |
| Live TTS first-byte diagnostic | **Pass for sampled target:** 613 ms and 1,281 ms with progressive PCM; representative p95 still requires deployed owner testing |
| Production TTS first-byte diagnostic | **Pass for sampled request:** first 4 KB of PCM in 1,333 ms using `gpt-4o-mini-tts` |
| Public production-presence preflight | **Pass:** enabled v2 shell, `app-PZKAlOxq.js`, wake runtime v13 with exact worker checksum, and every authenticated route boundary |

The first `php artisan test` invocation inherited Laravel's spawned worker default
of 128 MB and stopped after 357 passing tests/3,846 assertions in the unrelated
`TopWorkspaceSwitcherAssetTest`. The direct PHPUnit invocation applied 512 MB to
the actual test process and completed all 440 tests. This was runner memory
configuration, not an assertion failure, and is recorded to avoid hiding the
failed invocation.

## Wake-model evidence

The v13 manifest records a 125/126 (99.21%) deterministic prerecorded Bean
Voice QA result against the agreed 95% acceptance threshold independently in
Playwright Chromium, installed Google Chrome, and Playwright WebKit: 24/24
isolated strict wakes, 6/6 wake-plus-command releases, 6/6 continuous-speech
wakes, 23/24 missed-`Hey` address journeys, 0/60 false activations across ten
privacy families, 6/6 reject/reset/immediate-wake recoveries, zero
pre-confirmation PCM, complete activated-PCM handoff, and no runtime errors in
each engine. That is zero false activations across 180 engine/corpus negative
runs. Wake p95 was 437.9 ms in Chromium, 439.3 ms in installed Chrome, and 487
ms in WebKit, all below the 500 ms contract target.

The corpus includes the reported ambient conversation as a held-out negative, along
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

The July 14 v13 wake-privacy candidate is present publicly as commit `0377ac34`.
The public preflight verified the enabled Browser Voice v2 shell, hashed
`app-PZKAlOxq.js` client, exact v13 manifest/worker checksum, and every
authenticated voice route boundary. The populated production invariant audit
passed with zero violations across 24 turns, 48 messages, 22 runs, and 233
events. Those checks prove deployed code and durable-data integrity, not a real
microphone, speaker, or room.

Remaining verification:

1. use the owner's account for fresh-load first wake, microphone restart, live
   partials, two-second endpoint, expected and ambient follow-ups, background
   audio rejection, false and meaningful barge-in, playback Stop, explicit job
   cancellation, three-job concurrency, read bypass, local/remote weather,
   direct writes, contextual corrections, complex note creation, provider fault,
   reload/reconnect, exact chat/speech parity, usage-limit upgrade UX, and admin
   diagnostics; and
2. record visible/audible Chrome, Safari, and Edge samples in quiet speech,
   background music, nearby conversation, and speaker-echo conditions with the
   p50/p95 metrics required by `bean-voice-rules.md`.

Until that physical deployed-development evidence exists, the accurate status
is: deterministic gates, deployment preflight, populated invariant audit, and
sampled production TTS first-byte check passed; representative release
certification pending.
