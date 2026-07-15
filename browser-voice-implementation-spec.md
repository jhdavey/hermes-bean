# Browser Voice Implementation Specification

Status: implemented local candidate; current verification is recorded in `browser-voice-v2-release-evidence.md`
Scope: authenticated browser experience only  
Out of scope: Flutter and other native clients  
Authoritative behavior: [`bean-voice-rules.md`](bean-voice-rules.md)

## Purpose

This document turns the Bean voice product contract into a replacement architecture, clean-cutover plan, performance plan, and release gate. It is deliberately based on complete user journeys rather than individual reported symptoms.

The implementation must replace conflicting ownership. It must not add another coordinator, fallback lane, browser queue, or lifecycle flag beside the existing ones.

Every activated spoken request follows one semantic path. Hermes owns meaning, completeness, conversational references, typed-operation selection and natural language. Deterministic code owns wake/privacy gates, admission, schemas, authorization, execution, lifecycle, deadlines, recovery and exact-once delivery. Time, date, voice-state, reads, writes, weather, conversational replies, spoken Stop and cancellation have no pre-Hermes shortcut.

The browser wake detector is a first-party, self-contained component. It may use bundled open-source runtime code, but it cannot require a proprietary wake provider, cloud inference, an external account, a license key, or an external runtime request. Ordinary same-origin loading of versioned static application/model assets is permitted. Its model, preprocessing, inference, and evaluation path must be reproducible locally from repository-owned scripts and packaged static assets.

## Original audit conclusion

This section records the diagnosis that motivated Browser Voice v2. It is
historical context, not a description of the current ownership graph. The
current implementation and its remaining external verification limits are
recorded in `browser-voice-v2-release-evidence.md`.

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
| Stop affects playback only | Spoken Stop resets the conversation, clears the browser queue, and calls the session cancel endpoint | Visible Stop stays local; spoken Stop crosses Hermes and selects typed playback control, never implicit cancellation |
| Background work does not keep follow-up active | Browser rearms the follow-up timer when queue/work is active | Conversation lifetime depends only on speech and clarification state |
| Up to three independent background jobs | Browser drains one FIFO queue; worker applies a session-wide overlap lock and earlier-sequence gate | Server scheduler with three slots and resource-scoped locks |
| Reads may bypass active work | Single active chat request and polling ownership blocks or supersedes other work | Independent durable turns/jobs plus a session event stream |
| Missed `Hey` recovery remains local | Wake worker recognizes anchored variants of `Hey Bean` only | Local, fail-closed address confirmation mode for initial `Bean …` |
| Confirmed interruption permanently stops audio | Existing paths conflate interruption, playback, conversation, and task cancellation | One playback adapter stops only the current speech item after meaningful confirmation |
| Meaning is decided once | Browser calls `realtimeNeedsAppRuntime`; Realtime can call a tool; Laravel routes again | One Hermes semantic path followed by schema-validated deterministic execution |
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
| Meaning, completeness, references, operation selection, and response language | Hermes semantic interpreter | Typed-operation validator, turn lifecycle |
| Operation schemas, authorization, and entitlements | Deterministic typed-operation validator | Hermes semantic path, typed handlers |
| Semantic model identity | Versioned server configuration | Hermes semantic interpreter, usage accounting |
| Durable turn lifecycle | Server turn transition service | Browser event projection, admin |
| Job state and concurrency | Server job scheduler | Working dock, admin |
| Typed read/write/work-control execution | Typed server handlers | Hermes semantic path, turn finalizer |
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
- semantic sequence and interpretation metadata needed to resume clarification; per-run execution routing is not duplicated on the turn
- state: `awaiting_clarification`, `accepted`, `running`, `completed`, `failed`, or `canceled`; pre-admission capture remains browser-private and is never a server turn
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

Add `voice_turn_id` to `assistant_runs`. Every accepted logical turn has one semantic-interpretation run and may add typed-operation and composition runs. Every voice run requires its own explicit, immutable execution category and handler; no turn-level fallback exists. Add the semantic-plan version, priority, resource lock key, idempotency key, hard deadline, and progress timestamp to runs where needed. An execution category is assigned only after Hermes interpretation; it is never a shortcut around interpretation.

### 5. Admission, semantic interpretation, and typed execution

Expose one idempotent endpoint:

`POST /api/assistant/voice/turns`

Input includes stable turn ID, complete logical transcript, session/workspace, timezone/location context, transcript timing, and browser connection generation. Durable admission atomically:

1. deduplicates by stable turn ID;
2. creates the accepted user chat message exactly once;
3. records the lifecycle and interpretation deadline.

After commit, the server idempotently starts or resumes one Hermes semantic path for that turn.

Hermes receives the complete transcript, durable conversation and work context, and trusted current time, date, timezone and location. A configurable low-latency backend model is selected by versioned server configuration; its identity and per-request usage are recorded for accounting and diagnostics. The model may request clarification, produce a no-tool response, or select one or more typed operations with structured arguments. Conversational references, corrections, multiple clauses, temporal interpretation, natural closings, spoken Stop and cancellation are resolved here. The durable projection carries semantic conversation dispositions such as `response_expected` and `close_after_response`; the browser never infers them from punctuation or phrase lists.

Deterministic code validates every selected operation against its schema, authorization, subscription and resource entitlements before execution. It may reject a missing schema requirement or contradictory tool payload, but it never determines conversational completeness, repairs meaning, authors a clarification, or selects among possible targets; the structured rejection returns to Hermes for a corrected plan or one specific Hermes-authored question. Voice uses one canonical argument form: exact supplied domain statuses and recurrence values, direct update fields, absolute temporal values, explicit provider kind/location/topic, concrete IDs, or an exact-title unique reference that is sealed to one authorized ID before jobs exist. Memory is the sole exception to title-only sealing because its title is optional: `app.memory.search` may explicitly request `exact_title` or `exact_content`, and a unique result may be sealed to one authorized memory ID. Metadata, aliases, `items.N` selection, fuzzy mutation targets, and post-Hermes prose interpretation are rejected. Execution adapters may translate canonical values into provider or database calls and apply documented incidental create defaults, but may not infer user meaning. Semantic values that Hermes does supply—including `all_day`, `starts_at`, and `ends_at`—are stored literally without changing their timezone or end-boundary convention.

The canonical durable-memory surface is `app.memory.search`, `app.memory.create`, `app.memory.update`, and `app.memory.delete`. Create requires an explicit canonical type and non-empty content; update exposes only explicit mutable memory fields; update/delete require a trusted memory ID or a sealed exact-title/exact-content unique search reference. Search and execution remain scoped to the authenticated user and workspace and active, unexpired items. The executor may validate, authorize, lock, deduplicate, and perform the CRUD transaction, but it may not infer a memory type, extract content from transcript prose, translate aliases, fill a missing field, or select an ambiguous item. Ordinary identity or preference disclosures do not persist unless the user explicitly requests durable memory and Hermes selects `app.memory.create`.

The application owns idempotency, ordering, locks, deadlines, database/provider calls and side-effect reconciliation. A Hermes acknowledgement is made durable only in the same lifecycle transaction that stages a fully validated plan, so an invalid plan cannot speak before Hermes repairs it or asks a clarification. Tool results—including expected negative outcomes discovered only during execution, such as a duplicate-prevention or stale-target race—return as machine-readable receipts to the same turn's Hermes composition. Deterministic exceptions and lifecycle state contain no conversational question or natural operation-failure copy; only Hermes authors that language, and only the turn finalizer may persist the final Bean message.

After that final is durable, every projection and client treats its content as opaque text. Storage accessors, browser adapters, Flutter adapters, and TTS may neither phrase-match, unwrap JSON-looking content, substitute fallback prose, nor suppress a valid Hermes final. Speech synthesis verifies the exact durable text before playback.

There is no browser intent classifier, deterministic phrase router, Realtime tool route, regex fallback, or second model runtime. Invalid or ambiguous structured output returns to the same semantic path for one specific clarification. A semantically selected no-tool response or read-only operation may bypass unrelated background capacity after interpretation, but no activated request bypasses interpretation.

The configured semantic model may retry once with the same stable turn ID only before a typed side effect begins and within the original deadline. That semantic retry has one owner: a terminal provider, validation, or composition failure is never requeued as a new whole semantic attempt. Lifecycle recovery may replace only a stale or crashed worker generation behind the same durable identity and receipts. Generic-versus-voice lifecycle ownership is determined solely by the server-owned voice-turn relationship; client source labels and metadata are diagnostic input and cannot select a lifecycle. A failure terminalizes naturally; it never falls through to a heuristic or another model. OpenAI Realtime remains transcription-only and does not call `send_bean_request` or own application tools.

When the semantic model itself is unavailable or cannot produce a valid final after that retry, the lifecycle owner emits one fixed, content-neutral operational fallback to preserve terminalization and exactly-once delivery. This is not a semantic fallback path: it cannot inspect transcript meaning, choose an operation, or claim success. Ordinary typed-operation failures and partial successes still return to Hermes for grounded final language.

### 6. Completeness and clarification

The browser closes an utterance after two seconds of silence and durably admits every non-empty activated transcript immediately at that endpoint. It performs no phrase, grammar, mutation, temporal, or completeness classification and never extends capture because of transcript shape. Hermes alone decides whether the admitted request is complete or needs one specific clarification.

The browser must not classify application intent, conversational relevance, natural closing, spoken Stop, cancellation, or semantic completeness. Every otherwise finalized activated transcript is admitted exactly once. Hermes is the sole semantic completeness authority because it receives application and conversation context.

A semantically incomplete admitted request remains the same durable turn in `awaiting_clarification`, creates no executable job, and receives one Hermes-authored specific clarification question. Its five-second answer is appended through the dedicated clarification endpoint using the same stable turn ID and returns to the same semantic path. Partial utterances never become separate durable jobs, and no browser/server completeness decision can conflict.

### 7. Server job scheduler

Replace session-wide FIFO execution with server-owned capacity and resource rules:

- maximum three running background jobs per user/session voice context;
- independent resource keys may run together;
- matching or conflicting resource keys serialize;
- explicit corrections, deletions, and cancellations receive priority;
- semantically selected no-tool responses and read-only operations bypass background capacity;
- a multi-operation turn can create multiple runs but has one finalizer barrier and one final response;
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
- playback Stop changes playback state only;
- final text remains visible even when its audio is stopped;
- background work state is unaffected.

Use the simplest provider streaming path that meets the acknowledgement and final-audio latency targets. The playback adapter must identify the current speech item, control its volume, and stop it without affecting any server turn or job. It does not need to retain a seekable buffer or support pause-and-resume.

Potential user speech may briefly lower playback volume while it is evaluated. If the speech is rejected as noise, restore normal volume and allow the same audio stream to continue. If meaningful speech is confirmed, permanently stop and discard the current speech item. Its complete text remains visible in chat and may be repeated only if the user asks.

### 10. Barge-in

Potential speech may duck playback without admitting a turn. Transport/VAD evidence can reject empty input, transcription failure, noise, or verified speaker echo; it cannot classify intent or conversational relevance. A non-empty activated transcript confirms the interruption and is admitted for Hermes interpretation. Outcomes:

- meaningful address/request: stop the current speech item, retain its full chat text, accept the new utterance once;
- empty input, noise, failed transcription, verified echo, or independent wake/privacy rejection: discard the candidate, restore normal volume, and let the current audio continue;
- wake phrase: always confirm immediately, even while speaking or working.

Provider echo and the controller's own output fingerprint are rejected. Barge-in never changes server job state.

### 11. Physical Stop, semantic spoken Stop, and cancellation

Pressing the visible Stop control is purely local deterministic playback control. It creates no spoken turn, invokes no model, makes no server cancel call, and does not clear conversation jobs. It returns to wake-only unless an explicit active clarification remains open.

Saying `Stop` is different: the transcript is admitted through Hermes like every other activated utterance. During barge-in the interruption controller has already stopped current audio promptly; Hermes then determines whether the utterance means playback Stop, cancellation, correction, or something else. An unqualified spoken Stop selects the typed playback-Stop operation, which deterministic code executes without changing background work.

Semantically selected cancellation uses a dedicated deterministic endpoint targeted to a job, a turn, or all eligible active work:

`POST /api/assistant/voice/cancellations`

Hermes resolves the conversational reference and supplies a target; deterministic code validates that target, atomically marks cancellable work, stops uncommitted execution, reconciles possible writes, terminalizes the turn when appropriate, and emits events. The UI briefly projects canceled then removes the normal chat request and dock item while retaining audit history.

The existing session cancel endpoint remains available for explicit non-voice lifecycle needs but is never called by the browser playback Stop path.

## Deadline and retry enforcement

Use the exact targets in `bean-voice-rules.md`. Each admitted turn stores its hard and no-progress deadlines. Deadline enforcement occurs outside the request/worker that may be stuck, so a timed-out provider cannot prevent terminalization.

- Semantic interpretation: terminal interpretation failure by 2 seconds.
- Semantic no-tool response: final audio or terminal failure by 3 seconds.
- Typed read: final audio or terminal failure by 4 seconds.
- Typed write: background state or terminal failure by 2 seconds; simple final by 6 seconds.
- External lookup: terminal by 8 seconds.
- Long-running typed work: first progress by 2 seconds, no-progress action at 10 seconds, terminal by 120 seconds unless explicitly extended.

Semantic interpretation may retry once using the same configured model and stable turn ID before any typed side effect starts. Read-only work may retry once within the original deadline. Writes reuse the same idempotency key and retry only after authoritative reconciliation. There is no heuristic, local-answer, or second-model fallback after a semantic or typed-operation timeout.

## Replaced code

Bean is still in development. Removal is part of this cutover, not deferred cleanup, and no compatibility owner or legacy execution path is retained.

The clean cutover deletes:

- browser voice queue ownership in `VoiceOrchestrator`;
- the separate queued-follow-up persistence/drain path in `webApp.js`;
- voice-specific use of the generic chat queue and single active request token;
- duplicate `backendActive`, `responseActive`, guard, and follow-up flags outside the new controller;
- `realtimeNeedsAppRuntime` as a voice router;
- Realtime `send_bean_request` tool routing for browser voice;
- browser and server phrase classifiers for time, date, voice state, reads, writes, weather, conversational replies, natural closings, spoken Stop, and cancellation;
- spoken Stop interception, its call to `cancelBeanTurn`, and all queue-clearing Stop behavior;
- session-wide voice FIFO enforcement and `voice_turn_sequence` scheduling;
- lifecycle writes to `voice_turn_state`/`voice_turn_outcome` metadata from controllers, services, and workers;
- recovery bridges that create provisional or final assistant messages outside the turn finalizer;
- single-request work polling once the session event projection is live;
- tests that assert Stop cancels work or FIFO is the universal voice scheduling rule.

Explicit non-voice task cancellation and direct resource APIs remain. Deterministic chat intent routing, local time/date answers, fast calendar/weather resolvers, regex CRUD planning, voice-only compatibility reads, bridges, schemas, commands, schedules, and contradictory tests do not. Development rows do not justify keeping a second semantic or lifecycle owner.

## Clean cutover sequence

1. Add schema, models, transition service, semantic-result schema, typed-operation registry, event log, and read-only admin projection behind `browser_voice_v2`.
2. Add deterministic lifecycle, deadline, idempotency, concurrency, and reconciliation tests.
3. Implement the new browser activation/controller/transcript/playback adapters behind the flag.
4. Implement turn admission, the single Hermes semantic path, typed-operation validation/execution, scheduler, event feed, finalizer, and cancellation endpoint.
5. Implement snapshot hydration and reload recovery.
6. Add complete browser journey tests and representative-device latency collection.
7. Remove the superseded browser/server voice entry points, metadata bridges, commands, schedules, and contradictory tests. Do not retain shadow or dual execution.
8. Enable v2 in the owner's development environment with the operational flag; no user allowlist exists.
9. Pass the release gate with only the replacement architecture reachable for new voice work.
10. Keep the flag as an operational kill switch. Disabling it stops new admissions while already admitted jobs continue to their deterministic terminal states.

Only the replacement architecture may execute a browser voice request. A stable turn ID and unique constraints enforce this at the server boundary.

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
- Semantic-path fixtures proving every activated request reaches Hermes, plus structured-plan validation for every typed operation.
- Complete explicit-memory journeys for remember/create, search/read, correction/update, forget/delete, ambiguous clarification, non-persistence of ordinary disclosure, and duplicate/reload exactly-once delivery.
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
| Incomplete request and five-second clarification | Hermes interpreter/browser controller | One stable turn, one specific semantic clarification, no premature job, one final |
| Fifteen-second follow-up and natural closing | Hermes interpreter/browser controller | Fake-clock window plus Hermes closing disposition and response |
| Every activated request crosses one semantic path | Hermes semantic interpreter | Time, date, voice state, conversation, read, write, weather, Stop, cancellation, correction, temporal, and multi-clause fixtures all record interpretation before action |
| Configurable fast semantic model | Versioned server configuration/deadline service | Selected model, usage, timeout, same-model retry, and terminal failure evidence with no heuristic or second-model fallback |
| No-tool semantic response | Hermes interpreter/turn finalizer | Grounded final, acknowledgement grace, latency and terminal deadline |
| Typed app read after interpretation | Typed read handler | Hermes-selected schema, authoritative result, read bypass, no shortcut |
| Typed app write after interpretation | Typed write handler | Hermes-selected schema, stable idempotency, authoritative success, reconciliation |
| Local and remote weather after interpretation | Typed weather handler | Hermes selection, location/context preservation, and scoped provider failure |
| Multi-operation request | Hermes interpreter/job finalizer | Structured decomposition, visible jobs, dependency order, one combined final |
| Fast acknowledgement skip | Speech scheduler | Final wins grace race and no acknowledgement item starts |
| Slow acknowledgement then final | Speech scheduler | Non-overlap and measured start latency |
| Meaningful barge-in | Controller/playback adapter | Duck if needed, confirm, permanently stop old audio, accept new turn once |
| False barge-in | Controller/playback adapter | Reject noise, restore normal volume, and do not restart audio |
| Visible Stop in every speech/work phase | Playback adapter | Audio stops without a semantic turn; server turn/jobs and queue remain unchanged |
| Spoken Stop in every speech/work phase | Hermes interpreter/playback operation | One admitted semantic turn; current audio stops promptly, background work remains unchanged, and the literal Hermes final is projected and delivered without suppression |
| Explicit single/all cancellation | Hermes interpreter/cancellation service | Contextual target resolution, validated authoritative cancellation, reconciliation, transient dock state, hidden normal chat |
| Three independent concurrent jobs | Job scheduler | Three simultaneous leases and independent completion |
| Same-resource serialization | Job scheduler | Conflict matrix proves no overlapping mutations |
| Read-only bypass after interpretation | Hermes plan/typed read handler | Read crosses Hermes, then completes while three background slots are occupied |
| Out-of-order completion | Event projection/speech scheduler | Named finals match turns and never overlap |
| Reload in every lifecycle phase | Snapshot/event projection | Stable IDs, state, messages, dock, and final delivery without duplication |
| Duplicate/out-of-order events | Reducers/transition service | Property/permutation tests prove monotonic lifecycle and exact-once effects |
| Provider/transport/worker failures | Deadline and transition services | Terminal user failure, retry offer, cleared UI, complete diagnostic |
| Exactly one user and final message | Database/finalizer | Unique constraints plus concurrent finalization tests |
| Admin diagnostic completeness | Lifecycle event log/admin projection | Field-by-field assertion and raw-audio absence |

Every row must link to named automated test IDs in the final completion evidence. Representative-device performance results supplement these deterministic proofs; they do not replace them.

## Implementation goal draft

Implement and release the browser-only Bean Voice v2 architecture defined in `browser-voice-implementation-spec.md`, satisfying every invariant and acceptance journey in `bean-voice-rules.md`. Replace duplicated browser/server voice state with one browser conversation/playback controller and one server-owned durable turn/job lifecycle; implement local fail-closed wake and missed-Hey recovery, live transcription, two-second endpointing, five-second clarification, 15-second follow-up, one configurable low-latency Hermes semantic path for every activated request, schema-validated deterministic typed execution, meaningful barge-in with permanent playback stop, local physical Stop, semantic spoken Stop, explicit cancellation, three-job resource-aware concurrency, exact-once finalization, reload recovery, deadlines, retries, and complete admin telemetry. Prove correctness with deterministic journey tests and representative-browser latency benchmarks, cut over cleanly behind the admissions kill switch, and remove all superseded voice code and contradictory tests before declaring the goal complete. Flutter is explicitly excluded.

## Goal completion evidence

The implementation goal must not be marked complete with a code summary alone. Its final evidence must include:

- contract-to-test traceability table;
- cutover and replaced-code deletion diff;
- database exact-once and terminal-state audit;
- browser journey suite results;
- fault-injection results;
- representative-device p50/p95 benchmark report;
- admin diagnostic screenshots or assertions;
- deployed-development authenticated smoke results;
- known limitations and explicit product-approved exceptions, if any.

Current implementation evidence and the still-open external certification gates are tracked in [`browser-voice-v2-release-evidence.md`](browser-voice-v2-release-evidence.md).
