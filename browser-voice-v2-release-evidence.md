# Browser Voice v2 release evidence

Last updated July 15, 2026. This document covers authenticated browser voice
only. Flutter/native voice is out of scope. `bean-voice-rules.md` is the
authoritative product contract; this file records implementation and evidence,
not new product behavior.

## Current status

The previously audited deterministic-routing candidate was deployed to the
development site as commit `837dac78` on July 14, 2026. Its gate counts below
are historical and do not certify the current single-semantic-path cutover.

The current semantic development candidate admits every activated spoken request to
`agent.semantic`. Hermes owns meaning, completeness, references, typed-operation
selection, decomposition, and response language. Deterministic code owns
validated execution, lifecycle, scheduling, receipts, cancellation, deadlines,
recovery, and the sole durable final. The superseded instant, typed-router,
work-status-parser, and complex-runtime expectations have been removed rather
than retained as compatibility paths. Its previously recorded tests remain
semantic/lifecycle evidence; they do not certify the wake cutover described
below. Deployed-device and representative latency evidence are still pending.

Wake runtime v16 is being cut over to one proposal-and-classification pipeline.
The bundled, pinned, same-origin KWS runtime supplies only high-recall
`HEY_BEAN`/`BEAN` proposals and timestamps. Exactly one Bean-authored model,
`bean-wake-model-v2.json`, evaluates each coalesced proposal with fixed local
context and exactly 2,560 samples (160 ms) of local tail. Its only classes are
`reject`, `strict_wake`, and `missed_hey_confirmation`; it is the sole acoustic
acceptance authority. Exact `Hey Bean` and the contract-approved `Hey beam`
pronunciation cross that same three-class decision. An accepted class must be
compatible with the proposal type before deterministic code may establish a
safe release boundary. A proposal alone releases no audio.

The uncertified v2 development artifact is present: 538,914 bytes with SHA-256
`176455f94dc53d5921b1a0f8dfb45220250fbe5f942da54e87721d69a8c8867c`.
Its fit evaluation accepted 2,788/3,132 proposal-conditioned strict rows and
1,163/3,784 missed-`Hey` rows, with zero false accepts across 5,588 fit reject
rows and 12 Kathy reject rows. The 212 JavaScript voice tests, 13 complete
Playwright journeys, production build, package checksums, and enabled local
deployment preflight pass. Representative physical-microphone and cross-engine
acoustic evidence remain pending, so `wakeModelQaCertified` and
`releaseCertified` remain false. The flags record evidence state; they do not
block the owner from testing this integrity-complete development deployment. No
external wake service, account, key, remote inference, or runtime network
request is used.

For the current private development phase, `origin/main` is deployed to the live
production environment, and that environment is the owner's single-user test
surface rather than a commercially certified release. The owner has explicitly
accepted live testing with the proposal failures recorded below. Those failures
do not block an otherwise operational owner-test push, but they remain failures,
keep both certification flags false, and continue to block commercial
certification or broader-user release.

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

The current cutover removes those duplicate owners:

- `BrowserVoiceControllerV2` owns browser conversation state, endpointing, live
  draft visibility, follow-up gating, and interruption transitions; it does not
  interpret an activated request.
- every non-empty activated transcript is durably admitted at the ordinary
  two-second endpoint. The browser has no grammar or phrase-shape capture
  extension; the server-admitted Hermes path is the sole semantic completeness
  and specific-clarification authority.
- every activated request enters the same Hermes semantic interpreter before a
  time/date answer, voice-state read, application operation, weather lookup,
  spoken Stop, cancellation, work-status check, or conversational answer.
- `VoiceTurnLifecycleService` alone owns v2 capacity, priority, dependencies,
  resource serialization, terminal state, and the final assistant message.
  `ProcessAssistantRun` uses no `WithoutOverlapping` middleware for a v2 run.
- schema-validated semantic operation jobs execute authoritative application or
  provider calls and retain idempotent receipts. A separate durable composition
  job receives those receipts and produces one grounded final response.
- no application parser infers a target, temporal value, correction, or
  multi-clause plan from activated transcript text.
- projected durable final text is the exact input to HTTP speech synthesis; the
  speech endpoint rejects a text mismatch.
- Realtime is transcription-only: `create_response=false`,
  `interrupt_response=false`, no tools, and no provider response authority.
- one-word conversational answers such as “yes” are accepted without another
  wake only when Bean's preceding durable response actually asked a question.
  The same room speech after a normal answer remains private and invisible.
- the bundled KWS graph supplies proposals and timestamps only. The one
  three-class `bean-wake-model-v2.json` classifier owns both strict and
  missed-`Hey` acceptance after exactly 160 ms of local tail, and `Hey beam`
  crosses the same strict decision as `Hey Bean`.
- sanitized pre-turn client failures are durable admin diagnostic events; the
  server sanitizes them independently of the browser and owns no lifecycle
  transition through that telemetry path.
- each acoustic-engine replay and adapter benchmark now has a tested outer
  deadline, so the command records an engine failure instead of hanging.
- the local worklet sends bounded 80 ms batches. Final three-class cross-engine
  replay is pending; no earlier wake architecture is reused as v2 evidence.

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
- Stop aborts the current HTTP reader and audio nodes but does not cancel durable
  work; late chunks cannot restart that speech item. A physical Stop creates no
  final, while a semantic spoken Stop's separate literal Hermes final remains
  eligible for normal non-overlapping delivery; and
- truncated or failed playback records its bounded error code and message on
  the durable turn for admin diagnostics instead of reporting false completion.

The default speech model changed from `tts-1` to `gpt-4o-mini-tts` after live
same-machine first-byte checks of comparable short answers measured 1,891 ms
and 2,434 ms for `tts-1`, versus 613 ms and 1,281 ms for the new default. These
four observations are diagnostic samples, not a p95 certification. The selected
model remains configurable through `OPENAI_SPEECH_MODEL`, and speech usage is
still preflighted and metered once per stable speech item.

## Historical July 14 dormant-privacy follow-up

Production durable evidence correlated the reported dog-directed conversation
to one `new_conversation` turn more than seven minutes after the preceding Bean
response. That ruled out the 15-second follow-up window and its timers. The
false turn contained the full ambient sentence, identifying the local
missed-`Hey` path as the activation source rather than a strict `Hey Bean`
release.

The defect was an evidence-composition error in the single wake worker: the
then-current missed-`Hey` classifier could promote arbitrary utterance audio
without an independent local indication that the user had said “Bean.” Runtime
v13 historically fixed that superseded path by requiring both signals before
opening the gate:

- a proposal-only, on-device timing candidate for “Bean”; and
- acceptance by the then-current two-class classifier.

In that historical runtime neither missed-Hey signal could activate by itself,
while strict `Hey Bean` used the bundled KWS result. That architecture and its
evidence do not certify the current three-class model. Candidate speech remained
invisible and no pre-confirmation PCM or text left the browser. The reported
sentence was a QA input only; production code contained no phrase-specific
ignore rule.

## July 14 single-semantic-path cutover

The development cutover removes transcript-specific instant handlers, typed
read/write parsers, local work-reference/status resolution, deterministic
subtask splitting, and the separate complex-plan runtime. Named resources,
corrections, pronouns, temporal language, and multi-clause decomposition are now
represented only by Hermes' sealed structured interpretation. Application code
validates that interpretation, creates durable typed-operation jobs, executes
authoritative operations, records receipts, and releases one composition job
after the dependency barrier terminalizes.

The semantic provider is server-configurable and currently defaults to
`gpt-5.6-luna` with reasoning effort `none`. Interpretation and composition
have separate connection, request, and reserved-output budgets; changing the
model does not change the operation schemas or deterministic lifecycle owner.
The tests below verify configuration and timeout behavior, not live provider
latency.

The replacement deterministic journeys prove that stale conversational
references clarify without speculative writes, Hermes can target active work
despite an interjected completed turn, three independent writes may run while a
typed read bypasses them, resolved same-resource mutations serialize with
deletion priority, failed dependencies skip without side effects, independent
siblings still finish, and only the composition barrier publishes the final.

The final semantic-boundary audit also removed post-Hermes interpretation from
the execution layer. Voice operations now accept one canonical schema: literal
absolute timestamps, exact domain statuses and recurrence scalars, explicit
all-day/folder/location/topic fields, direct update fields, and either a trusted
concrete resource ID or an exact-title unique reference sealed to one authorized
ID before jobs are staged. The explicit memory surface adds canonical
search/create/update/delete operations; because memory titles are optional,
memory mutation references may use an exact-title or exact-content unique search
that is sealed to one authorized active memory ID before staging. Memory creates
require an explicit canonical type and content, while ordinary identity or
preference disclosure never auto-persists. Metadata aliases, relative weather dates, inferred
locations/topics, fuzzy or positional mutation targets, calendar duration
inference, note retitling, and undeclared linked-workspace status cascades are
rejected or absent from the voice path. Acknowledgement publication is part of
the validated-plan staging transaction, so invalid plans cannot speak before
Hermes repairs or clarifies them.

## July 14 first-wake regression follow-up

The deployed browser opened Realtime sessions but released no PCM, recorded no
transcription usage, and admitted no voice turn for the owner's first test. That
evidence localizes the drop to the dormant local wake gate. Runtime v14 exposed
neither the bundled KWS outcome nor the superseded classifier outcome, so the
available owner test cannot identify which of those two owners dropped the wake.

An intermediate runtime-v15 experiment made an exact `HEY_BEAN` KWS result the
strict acceptance authority. The permitted fixed-tail benchmark proved that
design was not commercially adequate and therefore blocks reuse of that design:

| Engine | Bean Voice QA | False wakes | Wake p95 | Reset/re-arm |
| --- | ---: | ---: | ---: | ---: |
| Playwright Chromium | 204/248 (82.2581%) | 27/163 privacy trials | 636.4 ms | 0/6 |
| Installed Google Chrome | 216/248 (87.0968%) | 19/163 privacy trials | 610.8 ms | 0/6 |
| Playwright WebKit | 212/248 (85.4839%) | 20/163 privacy trials | 600.0 ms | 0/6 |

All three real-gate engine runs failed the 95% aggregate target, the 500 ms p95
target, and privacy/reset expectations. Every false wake released PCM and caused
provider append events. The accepted-wake transport mechanics themselves kept
zero callbacks before local confirmation, used the selected release boundaries,
and completed without runtime errors; all three synthetic adapter-only runs also
passed. Those narrower passes do not offset the acoustic/privacy failures. The
benchmark is retained only as honest evidence for rejecting the experiment and
does not certify the replacement architecture.

Runtime v16 now targets the contract's single three-class path. The KWS emits a
sanitized `wake_proposal`; `bean-wake-model-v2.json` classifies the coalesced
proposal after exactly 160 ms of local tail and emits one sanitized
`classification_decision`. A compatible accepted class may then produce
`wake_confirmed`; a proposal by itself releases no PCM. `Hey beam` is an approved
acoustic strict positive through that same model, while unaddressed and disallowed
near matches remain privacy negatives. The development artifact and its
deterministic first-wake, safe-release, reset/re-arm, and package-integrity
journeys pass. Cross-engine acoustic replay and representative microphone
results remain pending and must not be inferred from the failed experiment.

The complete-journey audit also found three independent ways the browser could
show microphone activity and then remain silent. A post-readiness worker that
stopped acknowledging PCM had no deadline, a failed first-wake transcription
could retain an unbounded capture state, and a provider error reported UI state
without tearing down the local gate and microphone graph. Runtime v16 closes all
three paths without adding another lifecycle owner:

- `LocalWakeGate` enforces one generation-scoped, FIFO two-second deadline for
  the oldest unacknowledged PCM chunk. A timeout or invalid acknowledgement
  closes first, tears down capture, and records the controlled
  `pcm_ack_timeout` or `invalid_pcm_ack_sequence` code.
- `BrowserVoiceControllerV2` returns a failed initial activation directly to
  wake-only. A failed contextual follow-up retains only its existing bounded
  fifteen-second window.
- the production provider-error bridge performs one connection-wide,
  fail-closed teardown, so no later PCM can cross the provider adapter.

Client failures now use a per-user durable outbox and stable incident identity.
Offline and reload delivery is idempotent, normal logout preserves unsent
records for the same user's next login, storage failure is explicitly visible,
and the server independently neutralizes messages and allowlists controlled
local codes. No transcript, PCM, provider body, or dormant candidate content is
stored in this path. The production-bridge regression covers activation through
the real PCM adapter, first-wake transcription failure through re-arm, and
provider failure through complete teardown rather than testing controller
methods in isolation.

## Contract-to-test traceability

| Contract journey | Primary deterministic proof |
| --- | --- |
| Fresh-load readiness and first wake | `[BV2-STARTUP-01..04]`, `[BV2-WAKE-01]`, `[BV2-WAKE-03..04]`, `[BV2-WAKE-08..10]`, `[BV2-FIRST-WAKE-01:A..E]`, `[BV2-BROWSER-01]` |
| Dormant privacy, missed `Hey`, exact/approved-acoustic strict wake, disallowed near-match rejection, and re-arm | `[BV2-WAKE-11]`, `[BV2-FIRST-WAKE-01:A..B,E]`, local-wake gate tests, `[BV2-BROWSER-01]`, `[BV2-BROWSER-13]`; frozen v2 proposal coverage failed and classifier/cross-engine replay remain pending |
| Live partials and exact two-second endpoint | `[BV2-TRANSCRIPT-01..04]`, `[BV2-BROWSER-01]` |
| Incomplete fragments admitted at the normal endpoint and durable five-second Hermes clarification | `[BV2-CLARIFY-01..06]`, `[BV2-BROWSER-14]`, lifecycle clarification journeys |
| Fifteen-second follow-up, expected one-word answer, ambient rejection, strict-wake reset, and authorized context | `[BV2-FOLLOWUP-01..08]`, `[BV2-CONTEXT-01]`, semantic context journeys, `[BV2-BROWSER-08..09]`, `[BV2-BROWSER-13]` |
| Meaningful/false interruption, visible-control Stop with no new final, and spoken semantic Stop with an unsuppressed literal Hermes final | `[BV2-BARGE-01..04]`, `[BV2-STOP-01..09]`, semantic Stop/directive journeys, `[BV2-BROWSER-02..03]` |
| Time, date, and voice-state requests crossing Hermes before trusted reads | semantic execution and semantic admission-path journeys |
| Typed calendar/task/reminder/note reads and read bypass after interpretation | semantic execution, complete-journey, and semantic scheduler journeys |
| Typed writes, named/contextual mutation, temporal arguments, correction, and exactly-once receipts | semantic execution and complete-journey tests; `[BV2-BROWSER-09]` |
| Explicit memory remember, search/read, update, and forget with canonical fields and authorized targets | semantic memory complete journeys and semantic context tests |
| Ambiguous memory clarification, ordinary-disclosure non-persistence, duplicate/reload, and exactly one memory final | semantic memory complete journeys |
| Local/remote weather selection, strict structured arguments, retry, and scoped failure | typed lookup routing and semantic complete-journey tests |
| Multi-clause work and exactly one durable grounded response | semantic complete-journey and semantic operation-barrier journeys; `[BV2-BROWSER-12]` |
| Three running writes, read bypass, dependency skip, resource serialization, and deletion priority | semantic scheduler and semantic operation-barrier journeys; `[BV2-BROWSER-03]` |
| Contextual work status and explicit cancellation selected by Hermes | semantic work-status and semantic Stop/cancellation journeys |
| Exact chat/speech parity, progressive playback, no deadline truncation, and non-overlapping acknowledgement/final | `[BV2-ACK-01..03]`, `[BV2-SPEECH-01..05]`, `[BV2-SPEECH-TRANSPORT-01..10]` |
| Reload, reconnect, stale/out-of-order events, and no duplicate/replay | semantic reload/idempotency journey, `[BV2-RELOAD-01..02]`, `[BV2-RECOVERY-01]`, `[BV2-SEQUENCE-01..02]`, `[BV2-BROWSER-04..06]` |
| Ambiguous admission, idempotent recovery, and final-delivery retry | `[BV2-ADMISSION-01..08]`, `[BV2-DELIVERY-01]` |
| Provider/worker/transport/deadline faults terminate naturally and remain diagnosable | semantic provider-failure, operation-barrier, committed-deadline, diagnostics, and `[BV2-BROWSER-05]` journeys |
| Per-user plan usage, upgrade response, admin unlimited, and exact-once accounting | semantic usage/note-limit, voice usage, plan entitlement, and `[BV2-USAGE-01..03]` journeys |
| One accepted message, one terminal state, one final, no raw audio persistence | semantic complete journeys and semantic-neutral invariant-audit tests |

## Previously recorded semantic/lifecycle cutover evidence

These results were recorded before the current wake replacement. They remain
evidence for the named semantic, lifecycle, and UI boundaries only; they are not
a current whole-repository pass and do not certify `bean-wake-model-v2.json`.

| Gate | Result |
| --- | --- |
| Full PHP application suite after the clean cutover | **Pass:** 376/376 tests, 5,669 assertions using the direct PHPUnit process with a 512 MB limit |
| Existing-database forward cutover and clean-install schema equivalence | **Pass:** 2/2 migration tests, 31 assertions; an `origin/main` SQLite schema upgraded in place matches the current fresh schema across changed columns, defaults, indexes, and foreign keys |
| Stale named-resource race and missing-timezone Hermes-ownership journeys | **Pass:** 2/2 tests, 63 assertions; typed negative receipts contain machine constraints, reach Hermes composition on the same stable turn, and produce one final |
| Multi-run stale-target/dependency/final barrier | **Pass:** 1/1 test, 34 assertions |
| Typed lookup and weather selection, ambiguity, deadlines, failure scope, and machine-readable receipt boundaries | **Pass:** 23/23 tests, 467 assertions |
| Browser Voice JavaScript | **Pass:** 155/155 tests |
| Playwright complete browser journeys | **Pass:** 12/12 journeys |
| Intermediate runtime-v15 KWS-acceptance benchmark | **Fail:** 0/3 real-gate engines passed; 204/248, 216/248, and 212/248 aggregate journeys with 27, 19, and 20 false wakes respectively; experiment superseded |
| Production Vite build | **Pass:** `app-D37h1_OA.js`, `app-CbPbUmi9.css` |
| Flutter chat/API regression suite (native voice remains deferred) | **Pass:** analyzer clean and 230/230 tests |
| Fresh-schema invariant command | **Pass:** zero violations across an empty freshly migrated database; populated proof comes from the deterministic complete-turn tests |
| Changed PHP formatting, Composer strict validation, and `git diff --check` | **Pass** |

The recorded semantic, lifecycle, browser-adapter, build, and invariant gates
passed at that point. The wake gate did not. A fresh whole-repository run,
three-class artifact integrity, three-class replay, authenticated deployment
preflight, live-provider measurement, and representative-device gates remain
pending. Functional test success remains separate from measured latency.

## Historical pre-cutover automated gate results

The following results belong to commit `837dac78` and are retained only as an
audit trail. They must not be reused as evidence for the semantic cutover.

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

The one permitted frozen proposal-coverage run completed before classifier
training and failed every positive-coverage threshold:

| Split and proposal class | Result | Commercial threshold | Status |
| --- | ---: | ---: | --- |
| Fit strict wake | 794/1,100 (72.18%) | at least 95% | **Fail** |
| Fit missed-`Hey` address | 946/1,300 (72.77%) | at least 95% | **Fail** |
| Kathy strict wake | 4/44 (9.09%) | at least 80% | **Fail** |
| Kathy missed-`Hey` address | 0/52 (0%) | at least 80% | **Fail** |

The fixed run stopped before training, so it produced no
`bean-wake-model-v2.json` artifact and no classifier-recall result. A preceding
12-file diagnostic covered only `Hey Bean.` and `Bean, can you.` at one speech
rate across six voices; its 6/6 strict and 5/6 address proposal results do not
override the complete frozen matrix. The exact counts above remain the current
proposal evidence and may not be hidden, rounded into a pass, or relabeled as
classifier recall.

This failure blocks commercial certification, not an owner-authorized push to
the current single-owner live development environment. `wakeModelQaCertified`
and `releaseCertified` remain false. Artifact integrity, classifier replay,
aggregate classifier numerator/denominator, 160 ms tail behavior, proposal-only
privacy proof, and three-engine latency remain pending. The failed intermediate
v15 benchmark recorded above also remains historical and must not be relabeled
as v2 evidence. `Hey beam` remains an acoustic pronunciation positive through
the same proposal and three-class path as `Hey Bean`; it is not a separate text
alias or runtime exception.

Owner testing does not relax Rules 6, 11, 13, or 15. Dormant privacy,
duplicate/stale-event safety, terminal failure diagnostics, and no raw-audio
retention remain hard across `[BV2-FIRST-WAKE-01:A–E]`, `[BV2-WAKE-01]`,
`[BV2-WAKE-11]`, `[BV2-TRANSCRIPT-03]`, `[BV2-DIAGNOSTIC-03]`,
`[BV2-PRIVACY-PCM-03]`, `[BV2-BARGE-04]`, and `[BV2-FOLLOWUP-01]`.

Grandpa UK, Grandpa US, and Rocko US were opened exactly once for a superseded
candidate. They are now regression inputs, not untouched held-out voices, and
must not be represented as fresh v2 certification evidence. No result for those
voices was generated or inspected during this documentation cutover.

### Historical runtime-v13 evidence

The v13 manifest recorded a 125/126 (99.21%) deterministic prerecorded Bean
Voice QA result against the agreed 95% acceptance threshold independently in
Playwright Chromium, installed Google Chrome, and Playwright WebKit: 24/24
isolated strict wakes, 6/6 wake-plus-command releases, 6/6 continuous-speech
wakes, 23/24 missed-`Hey` address journeys, 0/60 false activations across ten
privacy families, 6/6 reject/reset/immediate-wake recoveries, zero
pre-confirmation PCM, complete activated-PCM handoff, and no runtime errors in
each engine. That is zero false activations across 180 engine/corpus negative
runs. Wake p95 was 437.9 ms in Chromium, 439.3 ms in installed Chrome, and 487
ms in WebKit, all below the 500 ms contract target.

That historical corpus included the reported ambient conversation as a negative, along
with phonetic near misses, third-person Bean mentions, ordinary conversation,
and other privacy families. Those strings did not occur in production decision
code. The result belongs to a superseded classifier architecture and cannot
certify the v2 three-class model.

The then-current default `npm run benchmark:voice:browser` command passed all
three executed engines. Microsoft Edge was explicitly classified as not
installed; installed Safari cannot be automated by this Playwright runner. Its
JSON distinguishes local model/gate evidence from provider/network and
representative product evidence and never emits raw PCM. Playwright WebKit is an
engine proxy, not installed Safari; an installed Chrome headless replay is not a
physical-microphone or audible product certification; Edge is not installed on
this machine.

## Historical deployment and remaining external verification

The July 14 v13 wake-privacy candidate was present publicly as commit
`837dac78`; it predates and does not certify the single-semantic-path cutover.
The public preflight verified the enabled Browser Voice v2 shell, hashed
`app-PZKAlOxq.js` client, exact v13 manifest/worker checksum, and every
authenticated voice route boundary. The populated production invariant audit
passed with zero violations across 27 turns, 54 messages, 25 runs, and 261
events. Those checks prove deployed code and durable-data integrity, not a real
microphone, speaker, or room.

Remaining verification required for commercial certification of the combined
semantic and wake candidate:

1. generate `bean-wake-model-v2.json` from the repository trainer; verify model
   ID `bean-first-party-wake-v2`, schema `2.0.0`, classes `reject`,
   `strict_wake`, and `missed_hey_confirmation`, 21,760 input samples, and a
   2,560-sample/160 ms tail; then replace the manifest's pending byte/hash fields,
   mark the artifact available, and refresh `SHA256SUMS` while both certification
   flags remain false;
2. run the deterministic package and complete first-wake journeys proving a KWS
   proposal alone releases zero PCM, every accepted class is proposal-compatible,
   exact `Hey Bean` and approved `Hey beam` cross the same strict class, missed
   `Hey` uses the same model, rejection re-arms, and no pre-confirmation PCM or
   transcript escapes;
3. run the prerecorded v2 matrix in Playwright Chromium, installed Chrome, and
   Playwright WebKit and report each engine plus the combined aggregate
   numerator/denominator and p95. At least 95% of all executed Bean Voice QA
   journeys must pass across the combined matrix, wake p95 must be at most 500
   ms on every executed target, and every raw-audio/persistence, runtime,
   duplicate-work, and lifecycle hard check must pass;
4. run the full current repository tests and production build, deploy the clean
   cutover, and rerun the read-only production preflight against that deployment;
   then use the owner's account for fresh-load first wake, microphone restart, live
   partials, two-second endpoint, expected and ambient follow-ups, background
   audio rejection, false and meaningful barge-in, playback Stop, explicit job
   cancellation, three-job concurrency, read bypass, local/remote weather,
   direct writes, contextual corrections, complex note creation, provider fault,
   reload/reconnect, exact chat/speech parity, usage-limit upgrade UX, and admin
   diagnostics; and
5. record visible/audible Chrome, Safari, and Edge samples in quiet speech,
   background music, nearby conversation, and speaker-echo conditions with the
   p50/p95 metrics required by `bean-voice-rules.md`.

Until those live-provider and physical deployed-development gates pass, the
accurate status is: semantic/lifecycle results are recorded, the frozen proposal
run and intermediate wake experiment failed, the final v2 wake artifact and
evidence are pending, the previous deployment and its latency samples are
historical, and current release certification is false. The owner may still
push an otherwise operational build to `origin/main` and the current live
single-user development environment for testing; that deployment neither
changes these results nor satisfies any commercial gate.
