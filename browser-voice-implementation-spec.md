# Browser Voice Implementation Specification

Status: ready to become the implementation goal after product approval  
Scope: authenticated browser experience only  
Out of scope: Flutter and other native clients  
Authoritative behavior: [`bean-voice-rules.md`](bean-voice-rules.md)

## Purpose

This document turns the Bean voice product contract into a replacement architecture, migration plan, performance plan, and release gate. It is deliberately based on complete user journeys rather than individual reported symptoms.

The implementation must replace conflicting ownership. It must not add another coordinator, fallback lane, browser queue, or lifecycle flag beside the existing ones.

The browser wake detector is a first-party, self-contained component. It may use bundled open-source runtime code, but it cannot require a proprietary wake provider, cloud inference, an external account, a license key, or an external runtime request. Ordinary same-origin loading of versioned static application/model assets is permitted. Its model, preprocessing, inference, and evaluation path must be reproducible locally from repository-owned scripts and packaged static assets.

## Audit conclusion

The recurring bugs are not independent transcription, weather, calendar, or wake-word defects. They are different outcomes of the same structural problem: the same voice turn is controlled by several partially independent systems.

Today:

- `localWakeGate.js` owns local wake privacy and audio release.
- `VoiceOrchestrator` owns one version of session, turn, response, and queue state.
- `webApp.js` independently owns additional session flags, turn guards, timers, provider state, playback state, a chat queue, a voice queue, work-dock state, and polling.
- OpenAI Realtime owns transcription, VAD, and provider-generated audio state.
- `AssistantVoiceController`, `AssistantRunService`, and `ProcessAssistantRun` each write turn lifecycle data.
- A conversation message's JSON metadata acts as an implicit turn record while `assistant_runs` separately acts as the execution record.
- The browser and Laravel both decide whether a request is direct or requires application/agent work.
- Multiple recovery and fallback paths can each manufacture or complete a response.

That makes valid events race one another. A stale provider event can reopen a closed turn, Stop can be interpreted as task cancellation, work can keep a conversation awake, a new request can replace instead of queue, and one polling token can hide another job. More conditional patches cannot make those owners atomic.

## Direct conflicts with the product contract

| Contract expectation | Current implementation evidence | Required replacement |
| --- | --- | --- |
| Follow-up window is 15 seconds | Browser constant is 10 seconds | One conversation timer owned by the browser controller, set to 15 seconds |
| Utterance ends after 2 seconds of silence | Realtime session VAD is configured for 650 ms | One 2-second endpointing policy; incomplete-intent handling occurs after the endpoint |
| Stop affects playback only | Spoken Stop resets the conversation, clears the browser queue, and calls the session cancel endpoint | Separate playback Stop from explicit job cancellation |
| Background work does not keep follow-up active | Browser rearms the follow-up timer when queue/work is active | Conversation lifetime depends only on speech and clarification state |
| Up to three independent background jobs | Browser drains one FIFO queue; worker applies a session-wide overlap lock and earlier-sequence gate | Server scheduler with three slots and resource-scoped locks |
| Reads may bypass active work | Single active chat request and polling ownership blocks or supersedes other work | Independent durable turns/jobs plus a session event stream |
| Missed `Hey` recovery remains local | Wake worker recognizes anchored variants of `Hey Bean` only | Local, fail-closed address confirmation mode for initial `Bean …` |
| Confirmed interruption permanently stops audio | Existing paths conflate interruption, playback, conversation, and task cancellation | One playback adapter stops only the current speech item after meaningful confirmation |
| Routing is decided once | Browser calls `realtimeNeedsAppRuntime`; Realtime can call a tool; Laravel routes again | One admission router and a persisted immutable lane/handler |
| Server owns queued/running/completed state | Browser maintains non-durable and durable queues plus local dock items | Server turn/job snapshot is the only durable work state |
| Every accepted turn reaches one terminal state | State is independently updated in controller, service, worker, and browser | One server lifecycle transition service with compare-and-set/versioning |
| Exactly one final response | Provider direct speech, run completion, fallback bridges, and reconciliation can all produce output | One final-message writer and one browser delivery scheduler |
| Reload restores all active work | Browser owns queue and one active poll token | Server rehydration endpoint/event cursor restores all active turns/jobs |

## Canonical ownership

Every concern has exactly one authority.

| Concern | Sole authority | Consumers |
| --- | --- | --- |
| Dormant microphone privacy and wake detection | Local wake gate | Browser voice controller |
| Activated microphone audio release and provider input | Browser provider-input adapter, gated by the local wake decision | Realtime transcription provider |
| Browser conversation state | Browser voice controller | UI renderer, provider adapter |
| Live activated transcript draft | Browser voice controller | Input renderer |
| End-of-utterance and clarification timers | Browser voice controller | Transcript/provider adapter |
| Request lane and handler | Admission router | Turn lifecycle, job dispatcher |
| Durable turn lifecycle | Server turn transition service | Browser event projection, admin |
| Job state and concurrency | Server job scheduler | Working dock, admin |
| Typed read/write execution | Typed server handlers | Turn finalizer |
| Agent reasoning | Complex-work handler only | Typed operations, turn finalizer |
| Acknowledgement/final speech order | Browser speech scheduler | Playback adapter |
| Playback start, volume, interruption, and Stop | Browser playback adapter | Speech scheduler |
| Final Bean chat message | Server turn finalizer | Browser projection |
| Timeout/retry policy | Server deadline service for work; browser deadline service for capture/playback | Admin diagnostics |
| Reload recovery | Server snapshot plus event cursor | Browser voice controller |

`webApp.js` becomes an adapter: it renders snapshots and forwards UI commands. It must no longer decide voice lifecycle, route requests, own a voice work queue, or infer durable state.

## Target component design

### 1. Local activation gate

Retain the fail-closed raw-microphone boundary and worker warm-up. Extend the local worker with two outputs:

- `wake_confirmed` for a strict `Hey Bean` acoustic match, including when it occurs after other room speech.
- `address_candidate` for `Bean` at the start of an utterance.

An address candidate opens a short local-only confirmation state. A small local grammar/classifier may confirm obvious second-person address patterns such as `Bean, can you …` and reject third-person patterns. No candidate audio or text crosses the provider boundary before confirmation. Ambiguous candidates expire silently within three seconds. The model and remote transcription service never decide dormant activation.

Every confirmed decision carries a monotonic local-audio boundary. Strict wake releases no audio from before the addressed wake phrase; missed-`Hey` confirmation releases from the locally established address onset. An implementation may retain a bounded memory-only PCM ring, but it may not release an arbitrary fixed duration of history or persist that ring. Rejected and stale generations erase their rings.

The gate reports readiness only after the worklet, model, recognizer, local audio flow, and warm decode are ready. The mic UI must not show wake-ready before this barrier. Failure is visible and fail-closed.

### 2. Activated provider audio transport

The provider connection may be prepared in parallel with local wake startup, but it receives no microphone audio while the conversation is wake-only. After the current local generation confirms activation, the provider-input adapter:

1. clears any uncommitted provider input for the new capture;
2. releases only PCM at or after the detector's accepted boundary, in original order;
3. catches up the bounded local ring without imposing a permanent real-time delay; and
4. continues streaming live activated PCM, including the silence needed for the exact two-second endpoint.

For the OpenAI Realtime adapter, input is mono little-endian PCM16 at 24 kHz. WebRTC remains the browser output transport; ordered input-audio client events use the same Realtime session's data channel. The transport queue is bounded and owns only byte delivery, never conversation state or durable work. A closed channel, queue overflow, invalid boundary, resampling failure, or provider error fails the capture closed and follows the normal terminal diagnostic path.

The previous fixed 1.2/3.2-second AudioWorklet delay line is prohibited. It delayed every transcript in real time and could release unrelated audio immediately before an address, so it cannot satisfy either wake-only privacy or the latency contract.

### 3. Browser voice controller

Create one controller with a reducer/statechart and serialized event dispatch. It owns no durable work queue.

Conversation states:

- `off`
- `starting`
- `wake_only`
- `activating`
- `capturing`
- `awaiting_clarification`
- `follow_up`
- `recovering_connection`
- `failed`

Playback is orthogonal:

- `idle`
- `buffering_ack`
- `playing_ack`
- `buffering_final`
- `playing_final`
- `potentially_interrupted`
- `stopped`

Background work is not a browser state. It is projected from server turn/job events.

All asynchronous events carry:

- controller generation
- provider connection generation
- stable turn ID when applicable
- monotonically increasing event sequence
- provider response/item ID when applicable

Events with a stale generation, stale sequence, or terminal turn are ignored and recorded. Reducer transitions are pure and covered with a fake clock.

### 4. Durable turn model

Add an explicit `voice_turns` table instead of treating message JSON as the lifecycle database.

Minimum fields:

- `id` and globally unique `turn_id`
- user, workspace, and conversation session foreign keys
- user and final assistant message foreign keys
- source/client kind (`browser_voice` for this release)
- transcript and sanitized transcript
- immutable lane and handler after admission
- state: `capturing`, `awaiting_clarification`, `accepted`, `running`, `completed`, `failed`, or `canceled`
- version for compare-and-set transitions
- idempotency key
- acknowledgement requirement and acknowledgement timestamp
- accepted, started, first-progress, terminal, and final-delivered timestamps
- hard deadline and no-progress deadline
- failure category, internal failure detail, and user-facing failure text
- side-effect status: none, committed, not committed, or uncertain
- retry count
- metadata for quality measurements, never raw audio

Conversation messages remain the chat transcript. They do not own lifecycle. A user message is created atomically when the turn becomes accepted. A final assistant message is created atomically once, only while terminalizing the turn.

Add `voice_turn_id` to `assistant_runs`. One logical turn may have zero, one, or several runs. Add immutable lane/handler, priority, resource lock key, idempotency key, hard deadline, and progress timestamp to runs where needed.

### 5. Admission and routing

Expose one idempotent endpoint:

`POST /api/assistant/voice/turns`

Input includes stable turn ID, complete logical transcript, session/workspace, timezone/location context, transcript timing, and browser connection generation. It atomically:

1. deduplicates by stable turn ID;
2. routes once;
3. stores the immutable lane and handler;
4. creates the accepted user chat message;
5. executes an eligible fast handler or creates server jobs;
6. returns the complete turn/job snapshot and event cursor.

The browser may use a closed, deterministic exact-intent classifier for time/date/voice-state requests, but it still obtains durable admission before final delivery. Laravel validates and executes that declared local handler; it does not reinterpret the request through a model. All other requests are routed once on the server.

Supported routing order:

1. exact instant handlers;
2. typed app read handlers;
3. typed app write handlers with completeness validation;
4. typed weather/external handler;
5. complex agent handler.

No lane silently falls through to another runtime. A typed handler failure is a scoped failure. The OpenAI Realtime model does not call `send_bean_request`; the browser does not send an approved tool request through a second `/runs` routing pass.

### 6. Completeness and clarification

The browser closes an utterance after two seconds of silence. It sends the final transcript to a bounded completeness decision that returns only:

- complete;
- clearly incomplete with a specific missing field/question; or
- uncertain pause.

An uncertain pause listens silently. A clearly incomplete request receives one clarification, and the five-second answer is appended to the same stable turn draft. Only the complete logical request is admitted. Partial utterances never become separate durable jobs.

### 7. Server job scheduler

Replace session-wide FIFO execution with server-owned capacity and resource rules:

- maximum three running background jobs per user/session voice context;
- independent resource keys may run together;
- matching or conflicting resource keys serialize;
- explicit corrections, deletions, and cancellations receive priority;
- instant and supported read-only handlers bypass background capacity;
- a complex turn can create multiple runs but has one finalizer barrier and one final response;
- jobs may complete out of spoken order.

Remove the session-wide `WithoutOverlapping` key and `voice_turn_sequence` earlier-run gate. Use a scheduler transaction/advisory lock only while claiming capacity, plus resource-scoped execution locks. Queue release must not consume failure attempts.

### 8. Server events and reload recovery

Expose a session-scoped snapshot and event feed that includes every non-expired turn and job relevant to the browser:

- initial `GET /api/assistant/voice/state?session_id=…` snapshot;
- one resumable SSE feed or bounded long-poll feed using a monotonic event cursor;
- idempotent event application by event ID and turn/job version;
- reconnect with `Last-Event-ID`/cursor;
- periodic snapshot reconciliation as a safety net.

The browser renders chat and the working dock from this projection. It does not create a second durable queue. A reload during accepted, queued, running, failed, or completed work reconstructs the same turn IDs and messages.

### 9. Speech scheduler and playback

Acknowledgements and finals are text artifacts tied to a stable turn. The browser speech scheduler is a single non-overlapping queue with priority for user-facing interruption prompts and explicit cancellation confirmations.

Rules enforced mechanically:

- wait 250–500 ms for a fast final before scheduling an acknowledgement;
- cancel an unstarted acknowledgement if the final is ready;
- once acknowledgement audio starts, its final waits;
- never play two speech items together;
- Stop changes playback state only;
- final text remains visible even when its audio is stopped;
- background work state is unaffected.

Use the simplest provider streaming path that meets the acknowledgement and final-audio latency targets. The playback adapter must identify the current speech item, control its volume, and stop it without affecting any server turn or job. It does not need to retain a seekable buffer or support pause-and-resume.

Potential user speech may briefly lower playback volume while it is evaluated. If the speech is rejected as noise, restore normal volume and allow the same audio stream to continue. If meaningful speech is confirmed, permanently stop and discard the current speech item. Its complete text remains visible in chat and may be repeated only if the user asks.

### 10. Barge-in

Potential speech may duck playback without admitting a turn. The activated transcript/classifier confirms meaningful speech. Outcomes:

- meaningful address/request: stop the current speech item, retain its full chat text, accept the new utterance once;
- background noise or unrelated speech: discard the candidate, restore normal volume, and let the current audio continue;
- wake phrase: always confirm immediately, even while speaking or working.

Provider echo and the controller's own output fingerprint are rejected. Barge-in never changes server job state.

### 11. Stop and cancel APIs

Stop is purely local playback control. It has no server cancel call and does not clear conversation jobs. It returns to wake-only except when an active clarification explicitly remains open.

Explicit cancellation uses a dedicated endpoint targeted to a job, a turn, or all eligible active work:

`POST /api/assistant/voice/cancellations`

The server resolves context, atomically marks cancellable work, stops uncommitted execution, reconciles possible writes, terminalizes the turn when appropriate, and emits events. The UI briefly projects canceled then removes the normal chat request and dock item while retaining audit history.

The existing session cancel endpoint remains available for explicit non-voice lifecycle needs but is never called by the browser playback Stop path.

## Deadline and retry enforcement

Use the exact targets in `bean-voice-rules.md`. Each admitted turn stores its hard and no-progress deadlines. Deadline enforcement occurs outside the request/worker that may be stuck, so a timed-out provider cannot prevent terminalization.

- Instant handler: terminal by 2 seconds.
- App read: terminal by 3 seconds.
- Direct write: background state by 2 seconds; terminal by 5 seconds when classified simple.
- External lookup: terminal by 8 seconds.
- Complex job: first progress by 2 seconds, no-progress action at 10 seconds, terminal by 120 seconds unless explicitly extended.

Read-only work may retry once within the original deadline. Writes reuse the same idempotency key and retry only after authoritative reconciliation. There is no silent general-agent fallback after a typed handler or fast-model timeout.

## Legacy removal plan

Removal is part of the migration, not deferred cleanup.

After replacement coverage passes, delete:

- browser voice queue ownership in `VoiceOrchestrator`;
- the separate queued-follow-up persistence/drain path in `webApp.js`;
- voice-specific use of the generic chat queue and single active request token;
- duplicate `backendActive`, `responseActive`, guard, and follow-up flags outside the new controller;
- `realtimeNeedsAppRuntime` as a voice router;
- Realtime `send_bean_request` tool routing for browser voice;
- spoken Stop's call to `cancelBeanTurn` and all queue-clearing Stop behavior;
- session-wide voice FIFO enforcement and `voice_turn_sequence` scheduling;
- lifecycle writes to `voice_turn_state`/`voice_turn_outcome` metadata from controllers, services, and workers after data migration;
- recovery bridges that create provisional or final assistant messages outside the turn finalizer;
- single-request work polling once the session event projection is live;
- tests that assert Stop cancels work or FIFO is the universal voice scheduling rule.

Preserve unrelated typed chat behavior and explicit task cancellation. Do not delete metadata compatibility reads until existing production rows are migrated or safely aged out.

## Migration sequence

1. Add schema, models, transition service, immutable router contract, event log, and read-only admin projection behind `browser_voice_v2`.
2. Add deterministic lifecycle, deadline, idempotency, concurrency, and reconciliation tests.
3. Implement the new browser activation/controller/transcript/playback adapters behind the flag.
4. Implement turn admission, typed lanes, scheduler, event feed, finalizer, and cancellation endpoint.
5. Implement snapshot hydration and reload recovery.
6. Add complete browser journey tests and representative-device latency collection.
7. Run shadow classification only: compare old and new lane decisions without executing v2 side effects.
8. Enable v2 in the owner's development environment with the operational flag; no user allowlist exists. Keep old and v2 execution mutually exclusive per stable turn ID.
9. Pass the release gate, remove old browser voice entry points, migrate compatible metadata, and delete legacy code/tests.
10. Keep the flag as a kill switch for one observation window; rollback disables new admissions but continues processing already admitted v2 jobs to terminal states.

At no point may both architectures execute a voice request. A stable turn ID and unique constraints enforce this at the server boundary.

## Admin and operational telemetry

Create one lifecycle timeline per stable turn, containing the fields required by `bean-voice-rules.md`. Add:

- controller/provider generations and rejected stale-event counts;
- playback speech item IDs, volume changes, and stop reasons;
- clarification and conversation timer transitions;
- job capacity/resource-lock wait time;
- event-feed disconnect/reconnect and snapshot reconciliation;
- delivery state for final text and final audio separately;
- a human-readable failure category and retry eligibility.

Dashboard alerts:

- any accepted nonterminal turn beyond deadline;
- duplicate finalization attempt;
- lifecycle regression attempt;
- write with uncertain side-effect state;
- wake startup failure rate above threshold;
- p95 regression for any published latency target;
- snapshot/event divergence;
- raw-audio persistence detection.

## Test architecture

### Deterministic unit and service tests

- Pure browser reducer with fake clock and generated event permutations.
- Local wake/address matcher with noise, accents, missed-Hey, and third-person corpora.
- Local-audio boundary and provider-input tests proving no pre-address samples cross the gate, ordered catch-up adds no permanent delay, and overflow or transport loss fails closed.
- Turn transition service with optimistic version conflicts and terminal idempotency.
- Immutable router fixtures for every supported typed intent.
- Resource conflict matrix and three-slot scheduler tests.
- Deadline/retry/reconciliation tests with fake providers and workers.
- Speech scheduler ordering, volume ducking, and permanent-stop tests.

### Browser integration tests

Use Playwright with synthetic microphone tracks and controllable fake provider/server adapters. Cover every required acceptance journey from `bean-voice-rules.md`, including reload and duplicate/out-of-order events. Assert the UI, accepted provider-audio boundary, persisted database rows, spoken-item order, and admin timeline together.

### Representative-device performance tests

Functional fakes cannot certify latency. Test current supported Chrome, Safari, and Edge versions on representative developer and lower-powered hardware, with quiet speech, background music, nearby conversation, speaker echo, and network shaping. Measure from audible/recognized milestones, not HTTP request-start proxies.

Publish p50, p95, sample count, device/browser/network class, and pass/fail for every product target. No `100% reliable` claim is permitted; release requires zero deterministic journey failures plus the agreed statistical latency/error thresholds over a sufficient sample.

## Release gate

Browser voice may replace the current path only when all are true:

- Every acceptance scenario in `bean-voice-rules.md` has a deterministic test mapping.
- A dedicated wake model reaches at least a 95% pass rate across the executed Bean Voice QA journeys covering cross-voice strict, continuous-speech, missed-`Hey`, near-miss, third-person, music, echo, cold-start, and repeated reset; a general ASR prefix or permissive phonetic alias is not accepted as release evidence. Raw-audio/privacy-boundary, runtime-error, duplicate-work, and lifecycle-integrity checks remain hard gates.
- Provider-input evidence proves exact wake-only silence, an utterance-bounded release, no permanent pre-roll delay, and the published end-to-end transcript latency on each representative browser.
- Stop, cancellation, interruption, clarification, three-job concurrency, resource serialization, reload, and provider failure pass end to end.
- No accepted turn remains nonterminal after its deadline in fault-injection tests.
- Database constraints prove one accepted user message and at most one final assistant message per stable turn.
- Old and new architectures never execute the same turn.
- All latency targets pass on the defined representative sample, or a product-approved exception is recorded in `bean-voice-rules.md`.
- Admin diagnostics are complete and retain no raw audio.
- Legacy browser voice owners and contradictory tests are removed.
- A manual authenticated smoke matrix passes in the owner's deployed development environment across fresh load, mic restart, follow-up, background work, Stop, cancellation, reload, and browser reconnect.

## Contract-to-test traceability

| Required journey | Primary owner | Required proof |
| --- | --- | --- |
| First wake after fresh microphone startup | Local activation gate | Browser test waits for readiness barrier, speaks first wake once, and observes one accepted turn |
| Missed `Hey` and third-person mention | Local activation gate | Local corpus plus browser privacy assertions |
| Wake-only privacy | Local activation gate | Assert no UI draft, network audio/transcript, message, turn, or job |
| Activated provider audio boundary | Local activation gate/provider-input adapter | Exact PCM sentinel test proves no sample before the accepted wake/address boundary reaches the provider and ordered catch-up adds no fixed delay |
| Live partial transcript | Browser controller | Timed synthetic partials update input before final transcript |
| Two-second utterance closure | Browser controller | Fake-clock boundary tests at 1,999 and 2,000 ms |
| Incomplete request and five-second clarification | Browser controller/completeness adapter | One stable turn, no premature job, one final |
| Fifteen-second follow-up and natural closing | Browser controller | Fake-clock and closing-phrase journeys |
| Instant lane | Admission router/instant handler | Exact route, no acknowledgement, latency and terminal deadline |
| Direct app read | Typed read handler | Authoritative result, read bypass, no model fallback |
| Direct app write | Typed write handler | Stable idempotency, authoritative success, reconciliation |
| Local and remote weather | Typed weather handler | Location/context preservation and scoped provider failure |
| Complex lane | Complex handler/finalizer | Prompt acknowledgement, visible jobs, one combined final |
| Fast acknowledgement skip | Speech scheduler | Final wins grace race and no acknowledgement item starts |
| Slow acknowledgement then final | Speech scheduler | Non-overlap and measured start latency |
| Meaningful barge-in | Controller/playback adapter | Duck if needed, confirm, permanently stop old audio, accept new turn once |
| False barge-in | Controller/playback adapter | Reject noise, restore normal volume, and do not restart audio |
| Stop in every speech/work phase | Playback adapter | Audio stops; server turn/jobs and queue remain unchanged |
| Explicit single/all cancellation | Cancellation service | Authoritative cancellation, reconciliation, transient dock state, hidden normal chat |
| Three independent concurrent jobs | Job scheduler | Three simultaneous leases and independent completion |
| Same-resource serialization | Job scheduler | Conflict matrix proves no overlapping mutations |
| Read-only bypass | Router/typed read handler | Read completes while three background slots are occupied |
| Out-of-order completion | Event projection/speech scheduler | Named finals match turns and never overlap |
| Reload in every lifecycle phase | Snapshot/event projection | Stable IDs, state, messages, dock, and final delivery without duplication |
| Duplicate/out-of-order events | Reducers/transition service | Property/permutation tests prove monotonic lifecycle and exact-once effects |
| Provider/transport/worker failures | Deadline and transition services | Terminal user failure, retry offer, cleared UI, complete diagnostic |
| Exactly one user and final message | Database/finalizer | Unique constraints plus concurrent finalization tests |
| Admin diagnostic completeness | Lifecycle event log/admin projection | Field-by-field assertion and raw-audio absence |

Every row must link to named automated test IDs in the final completion evidence. Representative-device performance results supplement these deterministic proofs; they do not replace them.

## Implementation goal draft

Implement and release the browser-only Bean Voice v2 architecture defined in `browser-voice-implementation-spec.md`, satisfying every invariant and acceptance journey in `bean-voice-rules.md`. Replace duplicated browser/server voice state with one browser conversation/playback controller and one server-owned durable turn/job lifecycle; implement local fail-closed wake and missed-Hey recovery, live transcription, two-second endpointing, five-second clarification, 15-second follow-up, single-pass typed routing, meaningful barge-in with permanent playback stop, playback-only Stop, explicit cancellation, three-job resource-aware concurrency, exact-once finalization, reload recovery, deadlines, retries, and complete admin telemetry. Prove correctness with deterministic journey tests and representative-browser latency benchmarks, migrate safely behind a mutually exclusive feature flag, and remove all superseded legacy voice code and contradictory tests before declaring the goal complete. Flutter is explicitly excluded.

## Goal completion evidence

The implementation goal must not be marked complete with a code summary alone. Its final evidence must include:

- contract-to-test traceability table;
- migration and legacy-deletion diff;
- database exact-once and terminal-state audit;
- browser journey suite results;
- fault-injection results;
- representative-device p50/p95 benchmark report;
- admin diagnostic screenshots or assertions;
- deployed-development authenticated smoke results;
- known limitations and explicit product-approved exceptions, if any.

Current implementation evidence and the still-open external certification gates are tracked in [`browser-voice-v2-release-evidence.md`](browser-voice-v2-release-evidence.md).
