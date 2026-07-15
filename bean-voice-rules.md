# Bean Voice Rules

This is the authoritative product contract for Bean voice. It records the expected user-visible behavior agreed during the July 11, 2026 voice interview. Implementation details may change; these rules may not be silently changed to fit an implementation.

Every future voice change must begin by reading this document. If the intended product behavior changes, update this document in the same change before modifying code. Every regression must add an acceptance scenario that maps back to a rule here.

## Product standard

Bean voice should feel like a fast, attentive human assistant:

- Wake reliably when addressed.
- Ignore room conversation when not addressed.
- Show the user's words live only after activation.
- Respond at Alexa-like conversational speed.
- Never overlap, truncate, duplicate, or lose spoken turns.
- Keep background work independent from speech playback.
- Make accepted work durable and recoverable across reloads.
- Never remain indefinitely in a thinking, listening, queued, running, or speaking state.
- Send every activated spoken request through one Hermes semantic interpretation path. Hermes owns meaning, completeness, conversational reference resolution, operation selection, work decomposition, and natural response language; deterministic application code owns validation, execution, lifecycle, and safety.

No implementation is considered correct merely because its happy path works. Stop, interruption, follow-up, timeout, reload, duplicate-event, provider-failure, and concurrent-work behavior are part of the primary contract.

## Development deployment

- Bean is currently a private development product used only by its owner; there is no user allowlist or staged customer cohort.
- Voice architecture changes use a clean cutover: superseded voice routes, compatibility bridges, parsers, state owners, commands, and contradictory tests are removed in the same change. Development data does not require a legacy runtime.
- `browser_voice_v2` remains the operational kill switch for new browser-voice admissions.
- Release-certification state records whether the evidence gates in this document passed; it is not an access-control mechanism and must not prevent the owner from testing an uncertified development build.
- A deployed development build must still pass the read-only deployment preflight before authenticated voice testing so stale assets or missing routes are caught explicitly.
- Broader-user rollout controls are out of scope until the product actually has outside users.

## Subscription and usage enforcement

- Browser voice uses the same per-user subscription limits as every other application surface. Voice may not bypass a plan limit through semantic interpretation or any typed or external operation.
- Admin accounts are unlimited and bypass subscription usage and feature limits.
- Every activated spoken request incurs the configured Hermes semantic-interpretation model usage and is metered to the authenticated user that incurred it. Typed reads or writes do not add AI cost by themselves, while provider-backed transcription, speech, additional model reasoning, and external calls remain metered.
- Realtime transcription and speech usage is recorded idempotently from provider usage events. A duplicate, late, or replayed provider event may not be charged twice.
- The server checks the authenticated user's remaining budget before issuing a Realtime voice session and after recording each provider usage event. A client may display usage state, but it is never the quota owner.
- Resource and feature entitlements, including note access and note-count limits, apply identically to direct voice writes and model-generated voice writes.
- When a non-admin user reaches a plan limit, Bean stops admitting additional affected work, explains the limit in plain language, preserves already accepted work, and presents an explicit `View plans` upgrade action. A limit response is not described as a provider or technical failure.
- Usage-limit failures and their user, workspace, plan, usage total, limit, lane, and provider-session identifiers must be retained in sanitized admin diagnostics.

## Terms

- **Wake-only:** The microphone feature is enabled, but Bean has not been addressed. Room speech remains private and invisible.
- **Active conversation:** Bean has accepted a wake and may accept contextual follow-ups without another wake phrase.
- **Utterance:** One continuous segment of user speech bounded by end-of-speech silence.
- **Logical request:** The complete user intent, including any continuation or clarification. Several utterances may form one logical request.
- **Semantic interpretation:** The single Hermes-owned step that determines what an activated utterance means, whether information is missing, which typed operations are required, how conversational references resolve, and what Bean should say.
- **Typed operation:** A schema-validated application, work-control, or provider operation executed by deterministic code after Hermes selects it and supplies structured arguments.
- **Background job:** Work that continues independently of speech playback.
- **Acknowledgement:** A short spoken confirmation that Bean heard or queued a request. It is not a success claim.
- **Final response:** The one durable Bean message that reports the outcome of a logical request.
- **Stop:** A playback command. Stop is not task cancellation.
- **Cancel:** An explicit request to terminate identified background work.

## Non-negotiable invariants

1. One logical user request has one stable turn ID from first accepted speech through its terminal outcome.
2. Every accepted request appears in chat immediately and exactly once.
3. Every accepted request reaches exactly one terminal state: completed, failed, or canceled.
4. Every non-canceled request receives exactly one final Bean message.
5. Acknowledgements and provisional speech are never stored as final responses.
6. Background room speech produces no visible text, chat message, provider transcript, tool call, or persisted work.
7. Stop never cancels background work or clears queued work.
8. Explicit cancellation never leaves the canceled item executing.
9. A final response never cuts off its acknowledgement, and an acknowledgement never cuts off another Bean response.
10. A meaningful user interruption never cancels background work unless the user explicitly asks to cancel it.
11. Duplicate, late, stale, or out-of-order events never create duplicate work, duplicate chat messages, or lifecycle regression.
12. A reload reconstructs active work from server-owned state without losing or duplicating a request.
13. A provider or worker failure always becomes a terminal user-visible result and an admin diagnostic.
14. Bean never claims a write succeeded until the authoritative application operation succeeded.
15. Raw microphone audio is not retained by default.
16. A durable Hermes final is preserved literally through storage, reload projection, visible chat, TTS verification, and playback; neither the model layer nor a client rewrites, unwraps, or suppresses its content.

## Wake behavior

### Initial activation

- Turning on the microphone puts Bean in wake-only mode.
- The first request requires `Hey Bean`.
- `Hey Bean` must work while Bean is idle, speaking, or working.
- A valid wake while Bean is speaking interrupts playback and begins a new user turn.
- A valid wake while Bean is working does not stop or cancel background work.

### Missed `Hey`

The system must tolerate the recognizer missing the word `Hey` without making `Bean` an unrestricted wake word.

- `Bean` at the beginning of an apparent address opens a short local confirmation window.
- During confirmation, speech remains invisible and is not sent to a remote transcription or reasoning provider.
- Phrases such as `Bean, can you help me?` may activate Bean when the continuation clearly addresses it.
- Mentions such as `I told Sarah about Bean yesterday` or other third-person discussion must be discarded.
- If intent to address Bean cannot be established within a few seconds, return silently to wake-only mode.
- The purpose of this path is missed-wake recovery, not general ambient listening.

### Background privacy

While wake-only:

- No room speech appears in the input.
- No room speech appears in chat.
- No background transcript is retained.
- No background speech is sent to the conversational provider.
- Wake detection and missed-wake confirmation remain local and fail closed.

### Wake detector ownership

- Bean's browser build owns and ships its wake detector and acoustic model.
- Wake detection may use bundled open-source runtime components, but it must not depend on a proprietary wake service, external account, license key, remote inference endpoint, or external runtime network call. Ordinary same-origin loading of versioned static application/model assets is permitted.
- Wake-model training and evaluation must be reproducible from repository-owned scripts and documented local inputs. No third-party training service may be required.
- Wake-only audio is processed in memory on the user's device and is not uploaded, persisted, or exposed to application routing.
- Production wake decisions must use general acoustic/address evidence and may not embed incident-specific negative-phrase aliases, deny lists, or hard-coded phrase exceptions. Named phrases may remain in training or held-out QA corpora as examples, but they may not directly control runtime rejection.
- A candidate detector may not be released merely because it performs well on its training voices. It must pass the cross-voice, near-match, background-noise, reset/re-arm, and representative-browser gates in this contract.
- For the current development update, the quantitative acceptance threshold is at least 95% of executed Bean Voice QA journeys passing. The numerator and denominator must be reported; skipped or unexecuted journeys do not count as passes.
- Recognition misses, near-match activations, missed-address decisions, and failed reset/re-arm journeys count as failed QA journeys. Raw-audio escape before confirmation, persisted wake-only speech, worker/runtime errors, duplicate work, and lifecycle-integrity violations remain hard failures regardless of the aggregate percentage.

## Conversation lifetime

- After Bean accepts a request, contextual follow-ups do not require `Hey Bean`.
- The follow-up window begins after Bean finishes speaking.
- The window lasts 15 seconds.
- Meaningful user speech resets the window.
- If Bean asks a question or clarification, the conversation remains active while waiting for the answer.
- Natural closings such as `thanks`, `that's all`, `goodbye`, or `take care` end the conversation after Bean's brief closing response.
- Fifteen seconds of silence ends the active conversation and returns to wake-only mode.
- Background work does not keep the conversational window open.
- `Hey Bean` always begins a new turn regardless of the current conversation or work state.

## Live transcription and end of speech

- Live transcription appears in the input as the activated user speaks.
- The transcript must update continuously rather than appearing only after speech ends.
- Two seconds of silence ends the current utterance.
- Speech that resumes before two seconds remains part of the same utterance.
- Wake-only or rejected speech never appears temporarily in the input.
- Final recognized text replaces the draft without duplication.

## Incomplete requests and clarification

- At the end of an utterance, the Hermes semantic interpreter determines whether the logical request is actionable.
- A complete request proceeds immediately.
- A clearly incomplete request receives one short, specific clarification question.
- Two seconds of silence always closes the current utterance and durably admits every non-empty activated transcript to Hermes. The browser does not classify grammar, extend capture from phrase shape, decide completeness, or enter semantic clarification on its own.
- If Hermes is uncertain whether required information is missing, it asks one specific clarification rather than inventing work.
- The user does not need another wake phrase to continue the request.
- Bean waits five seconds for the clarification answer.
- The original utterance and clarification answer form one logical request, one durable user turn, and one final response.
- A continuation must never become a second queued task merely because the recognizer split the speech.
- If no answer arrives within five seconds, Bean ends the clarification gracefully and returns to the appropriate follow-up or wake-only state.

## Single semantic path and typed execution

Every activated spoken logical request uses the same Hermes semantic interpretation path. There is no local or deterministic intent shortcut for time, date, voice-state questions, application reads, application writes, weather, conversational replies, Stop, or cancellation.

Hermes owns:

- The meaning of the user's complete request.
- Whether a required detail is missing and the exact clarification to ask.
- Resolving references such as `that task`, `move it`, `the first one`, or a correction to prior work.
- Selecting one or more typed operations and producing their schema-valid arguments.
- Decomposing multi-clause and multi-domain requests into meaningful work items.
- Producing acknowledgement, clarification, failure, and final-response language grounded in actual typed-operation results.

Deterministic application code owns:

- Wake and dormant-privacy gating, stable turn admission, lifecycle state, execution order, deadlines, and response delivery.
- Tool schemas, argument validation, authorization, subscription enforcement, and resource entitlements.
- Authoritative calendar, task, reminder, note, memory, weather/provider, Stop, cancellation, and work-status operations.
- Idempotency, resource locking, duplicate suppression, write reconciliation, reload recovery, and exactly one durable final response.

Additional rules:

- One configurable, low-latency backend model may be selected specifically for voice semantic interpretation. Selecting a faster model changes neither the tool boundary nor lifecycle ownership.
- The model may not write application state directly, claim an unverified side effect, invent a tool result, or become the owner of a queue, turn, job, or delivery state.
- A typed operation executes only after its arguments pass deterministic schema, authorization, entitlement, and safety checks. Deterministic code may reject a missing or contradictory tool payload, but it never decides what the user meant or whether conversational information is sufficient; that rejection returns to Hermes for repair or one specific clarification.
- Voice operations have one canonical argument shape. Execution adapters may translate validated canonical values into an application or provider API, but they may not accept semantic aliases, parse transcript prose, infer omitted meaning, silently select a named resource, or reinterpret a temporal value after Hermes.
- Hermes supplies every meaning-bearing value expressed by the user, while typed application services apply only documented incidental defaults: tasks are `todo` and `open`, reminders and calendar events are `scheduled`, optional text and relationships are `null`, booleans are `false`, recurrence is absent, uncategorized resources use Bean green `#34C759`, note-folder sort order is `0`, and blockers are `open` with no context. Hermes alone decides whether omitted user information is genuinely required and asks the clarification; deterministic validation rejects only missing schema requirements, contradictions, unauthorized targets, or unsafe values and returns that structured constraint to Hermes without authoring the question.
- Task search uses only the canonical `status` or `statuses` fields to filter completion state. A task search with no status filter returns both open and completed tasks; a day-context operation that intends only active tasks must explicitly request `status: "open"`.
- For semantic calendar operations, `all_day`, `starts_at`, and `ends_at` are literal canonical values from Hermes. Execution stores their resolved instants and never changes an end-boundary convention or timezone offset.
- Invalid or ambiguous structured arguments return to the same semantic path for one specific clarification; they do not fall through to a regex parser, second model runtime, or speculative write.
- Deterministic turn completion records only non-semantic activity identifiers and counters. It never infers or creates durable memory from transcript phrases; conversational meaning may mutate memory only when Hermes selects an explicit typed memory operation.
- Durable memory has exactly four voice operations: explicit search/read, create/remember, update/correct, and delete/forget. A create requires Hermes to provide both one canonical memory type (`fact`, `preference`, `identity`, `relationship`, `project`, `routine`, `constraint`, `decision`, `instruction`, or `temporary_context`) and non-empty canonical content. Update and delete require either one authorized concrete memory ID or one exact-title/exact-content unique search reference that deterministic code seals to an authorized ID before staging.
- Ordinary conversational disclosure, including `I prefer ...`, `I am ...`, and similar prose, is never an implicit request to persist memory. Hermes selects a memory mutation only when the user explicitly asks to remember, save, correct, update, forget, or delete durable memory. Missing content, an unresolved type, or an ambiguous target produces one Hermes clarification on the original stable turn; deterministic code never supplies a default type, extracts content from the transcript, interprets aliases, or chooses among matches.
- An acknowledgement becomes eligible for delivery only after the complete executable plan passes deterministic validation and is durably staged. A rejected plan may not publish or speak its provisional acknowledgement.
- The current UTC instant and any available timezone and location are supplied to Hermes as trusted runtime context. An absent timezone or location remains explicitly unknown; deterministic code never substitutes UTC as the user's local zone. Hermes interprets the question, asks one focused clarification when the missing context affects meaning, and produces the answer. The browser or server does not recognize those phrases as local shortcuts.
- Weather remains an authoritative typed provider operation selected by Hermes. A provider failure remains scoped to weather, is returned to Hermes as a terminal typed receipt for grounded language or a follow-up, and may not fall through to unrelated information.
- An expected typed-operation rejection discovered after staging—such as duplicate prevention or a target that changed concurrently—terminalizes as a machine-readable negative receipt and still reaches Hermes composition. The operation exception and lifecycle contain no clarification or conversational failure copy; Hermes alone explains the result or asks the follow-up.
- A semantically identified read-only operation may bypass unrelated background execution after interpretation. No request bypasses semantic interpretation.
- A multi-step logical request may create several typed jobs but receives one combined final response after all required jobs terminalize.
- If Hermes itself is unavailable or produces no valid final after the one allowed same-model retry, deterministic lifecycle code may persist one fixed, content-neutral operational failure fallback solely to satisfy the terminal-state and exactly-one-final invariants. That last-resort fallback never interprets the transcript, selects work, invents a result, or claims an unverified side effect; all ordinary validation, operation, and partial-success failures return to Hermes for grounded language.
- Hermes owns the sole semantic retry budget. A terminal provider, validation, or composition failure is never requeued as a fresh whole semantic run; lifecycle recovery may replace only a stale/crashed worker generation behind the same durable identity and idempotency receipts.
- Generic-versus-voice lifecycle ownership is determined only by the server-owned durable voice-turn relationship. Client metadata and diagnostic source labels can never create, impersonate, route, or mutate a voice run.

## Acknowledgement policy

- If semantic interpretation and the final response finish during a short grace period, skip the acknowledgement and speak the final response.
- The grace period is 250–500 ms and should be tuned using audible production latency, not request-start proxies.
- If work will take longer, acknowledge naturally and specifically.
- Queued follow-up work always receives a prompt acknowledgement grounded in the semantic plan.
- A read acknowledgement describes checking, and a work acknowledgement describes intent, without claiming an operation succeeded.
- An acknowledgement may not ask an unrelated question, imply completion, or improvise missing requirements.
- Once acknowledgement playback starts, the final response waits for it to finish.
- Bean never speaks two responses simultaneously.

## Performance and deadlines

These are user-audible service-level objectives. They are initial release targets and must be measured on representative browsers, devices, networks, and background-noise conditions.

| Behavior | Target | Hard behavior |
| --- | --- | --- |
| Wake recognition | At least 95% Bean Voice QA journey pass rate and p95 within 500 ms after the wake phrase completes | Never expose dormant speech while deciding |
| Live transcript update | p95 within 150 ms of recognized partial text | Never wait until utterance end to show all text |
| Semantic interpretation | p50 ≤ 500 ms; p95 ≤ 1,000 ms after final transcript | Terminal interpretation failure by 2 seconds; never fall back to heuristic routing |
| Semantic no-tool final audio | p50 ≤ 800 ms; p95 ≤ 1,500 ms after final transcript | Terminal failure by 3 seconds |
| Typed read final audio | p50 ≤ 1,000 ms; p95 ≤ 2,000 ms | Terminal failure by 4 seconds |
| Typed write acceptance/dock | p95 ≤ 1,000 ms | Terminal failure or explicit background state by 2 seconds |
| Simple typed write final | p95 ≤ 4 seconds | Terminal failure by 6 seconds |
| Acknowledgement audio start | p50 ≤ 500 ms; p95 ≤ 800 ms | Skip it if the final answer is already ready |
| Bounded external lookup final | p50 ≤ 2 seconds; p95 ≤ 4 seconds | Terminal result or scoped failure by 8 seconds |
| Complex work acknowledgement | p95 ≤ 800 ms | Visible dock state within 1 second |
| Complex work progress | First meaningful progress within 2 seconds | No-progress watchdog at 10 seconds |
| Complex work total | Task-specific, normally ≤ 30 seconds | Hard terminal deadline of 120 seconds unless the user explicitly authorized longer work |
| Confirmed barge-in | Stop playback p95 within 200 ms of meaningful confirmation | Never cancel background work implicitly |

No request may display an unchanged `Thinking` or `Working` state beyond its no-progress deadline. A long task must show real state changes or terminate with a failure.

## Retry policy

- Semantic interpretation may retry once with the same stable turn ID and configured model only when no typed side effect has begun and the retry fits within the same hard deadline.
- There is no heuristic router, local-answer path, or second model fallback after semantic interpretation fails.
- Read-only application and external typed operations may retry once within the same hard deadline.
- Writes may retry only with the same stable idempotency key and only when reconciliation proves this cannot create a duplicate side effect.
- A failed background worker may retry once only when no mutating work committed.
- Never stack fallback runtimes silently.
- When the deadline is reached, Bean explains at a high level what failed and asks whether the user wants it to try again.
- A retry is a continuation of the same logical request, not a duplicate chat turn.

## Speaking and interruption

- Potential user speech may briefly lower Bean's playback while the system determines whether it is meaningful. Playback continues; it is not paused for later resumption.
- Background noise alone does not confirm an interruption.
- Meaningful speech confirms the interruption without requiring `Hey Bean` during an active conversation.
- On confirmed interruption, Bean permanently stops the current playback and handles the new utterance exactly once.
- The interrupted answer remains fully visible in chat.
- Associated background work continues unless explicitly canceled.
- If potential speech is rejected as noise or unrelated conversation, Bean restores normal playback volume and continues the current audio. It does not restart or replay speech.
- Bean never automatically resumes or restarts audio after a confirmed meaningful interruption. The user may ask Bean to repeat the answer.
- Speaker echo from Bean's own voice must not wake or interrupt Bean.

## Background work and concurrency

- Up to three background jobs may run concurrently.
- Semantically interpreted no-tool answers and read-only typed operations may bypass active background execution.
- Independent writes may run concurrently.
- Work targeting the same resource or dependent resources is serialized.
- Corrections, deletions, and cancellations targeting active work take priority over later unrelated writes.
- Work does not need to complete in spoken order.
- Every job appears separately in the working dock with queued, running, completed, failed, or canceled status.
- Out-of-order final messages identify the completed work by name; Bean never says only `Done` when several jobs are active.
- A multi-step logical request may contain several dock items but receives one combined final response.
- Adding a request while Bean is working does not interrupt or replace existing work unless the user explicitly corrects or cancels it.

## Stop behavior

Pressing the visible Stop control:

- Stops Bean's current speech immediately through the deterministic playback controller.
- Does not create a spoken logical request or invoke semantic interpretation.
- Creates no assistant final or spoken confirmation because there is no logical request.

Saying `Stop`:

- Is admitted and interpreted through the same Hermes semantic path as every other activated utterance; there is no local phrase shortcut.
- On meaningful barge-in, the current playback has already stopped under the interruption rule while semantic interpretation determines whether the user meant playback Stop, task cancellation, or something else.
- Hermes must select the typed playback-Stop operation for an unqualified request to stop Bean speaking. Deterministic code executes that operation.
- Receives exactly one durable literal Hermes final like every other non-canceled semantic turn. That final remains visible and is eligible for normal non-overlapping speech delivery after the prior speech item stops; the client may not suppress it.

In either form, playback Stop:

- Stops Bean's current speech only.
- Does not cancel active backend work.
- Does not clear queued requests.
- Does not undo completed side effects.
- Keeps microphone wake detection enabled.
- Returns voice interaction to wake-only mode unless an explicit active clarification requires otherwise.
- Does not delete the accepted request or its eventual final text.
- Keeps the stopped speech item's complete final text visible. A physical Stop adds no new final; a semantic spoken Stop delivers its separate Hermes-produced final normally.

If the user later asks `Did you finish that?`, Bean reports the actual task state: still working, completed, failed, or explicitly canceled. It must not claim Stop canceled background work.

## Explicit cancellation

Supported examples include:

- `Cancel that reminder request.`
- `Don't create the note.`
- `Cancel everything you're working on.`

Rules:

- Resolve the intended active or queued job from conversation context.
- Cancel it authoritatively on the server.
- Briefly show `Canceled` in the dock, then remove the dock item.
- Hide the canceled request and provisional Bean messages from normal chat.
- Retain an internal audit record for diagnostics.
- Preserve side effects that completed before cancellation; Bean must explain when something could not be undone.
- Cancellation itself receives one concise confirmation.

## Chat and working dock

- Live activated speech appears in the input during recognition.
- Every accepted request appears in chat immediately.
- Every job appears in the dock immediately with its real state.
- Queued, running, completed, failed, and canceled states are server-owned.
- Completed dock items may clear after a short visible confirmation.
- Canceled items briefly show canceled, then disappear along with their normal-chat request.
- Interrupted answers remain visible.
- Exactly one final Bean message is stored per logical request.
- Reload restores chat, active jobs, order, state, and eventual delivery without duplication or loss.
- The browser never becomes the sole owner of a request or queue.

## Failure experience

- A timeout, provider outage, permission failure, transport loss, worker crash, or reconciliation failure must terminalize the affected request.
- Bean gives one natural response that explains the failure at a high level and asks whether it should try again.
- Bean does not expose stack traces, provider jargon, HTTP codes, Laravel, OpenAI internals, or raw schemas to the user.
- Failure clears indefinite thinking/speaking state and returns to the correct follow-up or wake-only mode.
- If a write may have committed, Bean reconciles authoritative application state before offering a retry.

## Admin diagnostics

Every failed, timed-out, abandoned, canceled, or unusually slow turn must be visible in the admin dashboard with:

- User and workspace identifiers
- Sanitized transcript
- Stable turn ID and run/job IDs
- Selected lane and handler for every durable run/job
- Wake, transcription, durable-admission, acknowledgement, first-progress, final-response, and playback latency
- Provider and typed tool calls
- Retry attempts
- Complete lifecycle transition history
- Final internal error and user-facing message
- Whether any side effect completed or may have completed
- Browser connection, reload, interruption, Stop, and cancellation events
- Benchmark pass/fail classification and sufficient-sample labeling

Raw microphone audio is not retained by default. Diagnostic audio collection would require a separate explicit opt-in product decision and privacy review.

## Ideal reference interaction

1. The user turns on the microphone. Bean shows wake-only readiness. Room conversation remains invisible.
2. The user says, `Hey Bean, what's on my calendar tomorrow?` The words appear live after wake activation. Two seconds of silence closes the utterance, and the request is durably admitted before interpretation.
3. Hermes interprets the request, selects the typed calendar read, and supplies structured arguments. The authoritative read finishes quickly, so Bean skips acknowledgement and Hermes produces the grounded final answer.
4. After Bean finishes, the 15-second follow-up window begins. The user asks, `What time is the first one?` without a wake phrase. Hermes resolves `the first one` from prior durable context, selects any required typed read, and answers directly.
5. While Bean is speaking, the user begins a meaningful new request. Playback may briefly lower while speech is evaluated; after speech is confirmed, the old audio stops permanently, its full text remains visible, and the new utterance is accepted once.
6. The user asks for a meal plan note. Bean acknowledges within 800 ms, creates visible dock items, and begins background work.
7. While that work runs, the user asks for the current time. The request still crosses the Hermes semantic path, receives trusted current-time context, and bypasses unrelated background execution only after interpretation.
8. The user adds a reminder request. Hermes selects the typed reminder operation; Bean acknowledges without claiming success, shows a second dock item, and runs it when one of the three background slots is available.
9. The user says `Stop` while Bean is speaking. Barge-in stops playback immediately; Hermes interprets the utterance as playback Stop, deterministic work control leaves both background jobs running, and the literal Hermes final for the Stop turn remains eligible for normal speech. The visible Stop control would stop playback without creating a spoken turn or final.
10. After the conversation returns to wake-only, the user says, `Hey Bean, did you finish the note?` Bean reports the real job state and does not claim Stop canceled it.
11. If a provider fails, Bean terminalizes that job, gives one natural failure response, offers a retry, and records the full diagnostic in admin.

## Required acceptance coverage

Before a voice release, deterministic and representative-device tests must cover:

- First wake immediately after microphone startup
- Missed `Hey` recovery and third-person `Bean` mentions
- Wake-only privacy and invisible background speech
- Live partial transcription and two-second utterance closure
- Incomplete request, five-second clarification, and one logical turn
- Syntactically incomplete fragments reaching durable Hermes admission at the normal two-second endpoint without any browser phrase rule
- Fifteen-second follow-up timeout and natural closing phrases
- Time, date, voice-state, app read, app write, local weather, remote weather, conversational, and complex requests all crossing the same Hermes semantic path
- No client, admission, runtime, or typed-service heuristic shortcut that can answer or route an activated spoken request before Hermes interpretation
- Explicit remember, memory search/read, correction/update, and forget/delete journeys using canonical memory fields and exact authorized targets
- Ambiguous or incomplete memory requests producing one Hermes clarification on the original stable turn with zero speculative memory writes
- Duplicate delivery and reload during a memory mutation producing one memory side effect, one accepted user message, and one durable final Bean response
- A duplicate-memory or stale-target race discovered during execution producing zero speculative writes, one structured negative receipt with no application-authored question, and one literal Hermes-composed response or follow-up
- `I prefer`, `I am`, and similar conversational transcript prose producing idempotent activity accounting but no durable memory unless the user explicitly asks to persist it and Hermes selects the corresponding typed memory operation
- Configurable fast semantic model selection, usage enforcement, timeout, same-model retry, and terminal failure without heuristic or second-model fallback
- Structured tool selection and argument validation for reads, writes, weather, work status, playback Stop, single-job cancellation, and all-job cancellation
- Ambiguous named-location provider results returning bounded candidates to Hermes for a natural follow-up, with no first-result geocoding guess or unrelated provider fallback
- Incidental task, reminder, calendar, folder, category, and blocker create fields using documented application defaults without a semantic retry, while genuinely missing meaning such as a reminder time or calendar start returns to Hermes for one clarification before any write
- Completed-task search using an explicit canonical status, no-status task search returning all statuses, and day context explicitly selecting open tasks
- Ambiguous and incomplete model output returning through Hermes for one Hermes-authored clarification journey on the original stable turn, with deterministic complete-journey coverage
- Fast-result acknowledgement skipping
- Slow-result acknowledgement followed by non-overlapping final speech
- Meaningful barge-in, false barge-in, and permanent playback stop after confirmation
- Spoken semantic Stop and visible-control Stop during acknowledgement, final speech, and background work
- Explicit single-job and all-job cancellation
- Three concurrent independent jobs and same-resource serialization
- Read-only bypass while background work is active
- Out-of-order job completion with clearly identified results
- Reload during capture, queued work, running work, acknowledgement, and final delivery
- Literal Hermes final preservation through durable storage, reload, visible text, verified TTS, and delivery
- Duplicate and out-of-order provider/browser events
- Provider timeout, transport failure, worker crash, and ambiguous write reconciliation
- Exactly one accepted user message and one final Bean message
- Admin diagnostic completeness without raw audio
- Base, Premium, and Pro voice usage enforcement; unlimited admin behavior; idempotent Realtime usage reporting; direct and generated note-limit enforcement; and a visible upgrade action

## Change-control rule

For every future Bean voice task:

1. Read this document before inspecting or changing voice code.
2. Identify the exact rules and acceptance scenarios affected.
3. If expected behavior is missing or ambiguous, interview the product owner before coding.
4. Update this document first when product expectations change.
5. Create or update deterministic tests before or alongside implementation.
6. Do not fix a symptom by adding another independent state owner, fallback lane, queue, or acknowledgement path.
7. Verify the complete affected journey, including failure, interruption, Stop, cancellation, reload, and duplicate-event behavior.
8. Report measured performance separately from functional test success.
