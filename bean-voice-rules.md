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
- Prefer deterministic local or application handlers for bounded requests. Use an agent only when interpretation or complex reasoning is actually needed.

No implementation is considered correct merely because its happy path works. Stop, interruption, follow-up, timeout, reload, duplicate-event, provider-failure, and concurrent-work behavior are part of the primary contract.

## Development deployment

- Bean is currently a private development product used only by its owner; there is no user allowlist or staged customer cohort.
- `browser_voice_v2` remains the operational kill switch for new browser-voice admissions.
- Release-certification state records whether the evidence gates in this document passed; it is not an access-control mechanism and must not prevent the owner from testing an uncertified development build.
- A deployed development build must still pass the read-only deployment preflight before authenticated voice testing so stale assets or missing routes are caught explicitly.
- Broader-user rollout controls are out of scope until the product actually has outside users.

## Terms

- **Wake-only:** The microphone feature is enabled, but Bean has not been addressed. Room speech remains private and invisible.
- **Active conversation:** Bean has accepted a wake and may accept contextual follow-ups without another wake phrase.
- **Utterance:** One continuous segment of user speech bounded by end-of-speech silence.
- **Logical request:** The complete user intent, including any continuation or clarification. Several utterances may form one logical request.
- **Instant request:** A deterministic device-local answer such as current time or date.
- **Direct request:** A bounded read or write handled by a typed application or provider service without general agent reasoning.
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

- At the end of an utterance, Bean determines whether the logical request is actionable.
- A complete request proceeds immediately.
- A clearly incomplete request receives one short, specific clarification question.
- If Bean is uncertain whether the user merely paused, it continues listening silently rather than inventing work.
- The user does not need another wake phrase to continue the request.
- Bean waits five seconds for the clarification answer.
- The original utterance and clarification answer form one logical request, one durable user turn, and one final response.
- A continuation must never become a second queued task merely because the recognizer split the speech.
- If no answer arrives within five seconds, Bean ends the clarification gracefully and returns to the appropriate follow-up or wake-only state.

## Request lanes

Routing is decided once per logical request. Later components must not independently reinterpret or silently move the request between lanes.

### Instant lane

Examples:

- Current local time
- Current local date
- Basic voice-state questions

Rules:

- Resolve locally when possible.
- No acknowledgement.
- No background job.
- Speak the final answer immediately.

### Direct application read lane

Examples:

- Calendar for today or tomorrow
- Next calendar event
- Task, reminder, or note lookup
- Other bounded application reads

Rules:

- Use typed application services and authoritative stored data.
- Do not invoke a general model or background agent for supported reads.
- Normally skip acknowledgement and speak the result directly.
- If the read unexpectedly exceeds the fast-result grace period, a natural `Let me check` acknowledgement is allowed.

### Direct application write lane

Examples:

- Create a fully specified reminder
- Create or complete a fully specified task
- Create a straightforward note

Rules:

- Use typed, idempotent application services.
- Normally no separate acknowledgement when the result will be fast.
- The request and its dock item appear immediately.
- Never announce success before the authoritative write succeeds.
- Missing required parameters trigger clarification rather than a speculative write.

### Weather and bounded external lane

- Local weather normally answers without acknowledgement.
- Local means the stored default/home location, an explicitly authorized current device location, or a location established as local in the active conversation.
- A different location, such as Universal Studios when it is not the current/default area, may receive a short acknowledgement if the final result is not already ready.
- Weather uses the dedicated weather provider directly, not a general web-search or agent fallback.
- Place, date, time, and day-part references must be preserved across contextual follow-ups.
- Provider failure returns a scoped weather failure; it never falls through to unrelated information.

### Complex agent lane

Examples:

- Multi-day planning
- Drafting or creative generation
- Ambiguous multi-step work
- Requests spanning several application domains

Rules:

- Give a natural acknowledgement promptly.
- Create visible work items for meaningful subtasks.
- Produce one combined final response after all required subtasks reach terminal states.
- Use the agent only for reasoning; deterministic reads and writes remain typed operations.

## Acknowledgement policy

- If the final response is ready during a short grace period, skip the acknowledgement and speak the final response.
- The grace period is 250–500 ms and should be tuned using audible production latency, not request-start proxies.
- If work will take longer, acknowledge naturally and specifically.
- Queued follow-up work always receives an immediate acknowledgement such as `Got it—I added that.`
- A read acknowledgement describes checking: `Let me check your calendar.`
- A work acknowledgement describes intent: `I’ll put that together.`
- An acknowledgement may not ask an unrelated question, imply completion, or improvise missing requirements.
- Once acknowledgement playback starts, the final response waits for it to finish.
- Bean never speaks two responses simultaneously.

## Performance and deadlines

These are user-audible service-level objectives. They are initial release targets and must be measured on representative browsers, devices, networks, and background-noise conditions.

| Behavior | Target | Hard behavior |
| --- | --- | --- |
| Wake recognition | At least 95% Bean Voice QA journey pass rate and p95 within 500 ms after the wake phrase completes | Never expose dormant speech while deciding |
| Live transcript update | p95 within 150 ms of recognized partial text | Never wait until utterance end to show all text |
| Instant final audio start | p50 ≤ 500 ms; p95 ≤ 1,000 ms after final transcript | Terminal failure by 2 seconds |
| Direct app read final audio | p50 ≤ 800 ms; p95 ≤ 1,500 ms | Terminal failure by 3 seconds |
| Direct app write acceptance/dock | p95 ≤ 800 ms | Terminal failure or explicit background state by 2 seconds |
| Simple direct write final | p95 ≤ 3 seconds | Terminal failure by 5 seconds |
| Acknowledgement audio start | p50 ≤ 500 ms; p95 ≤ 800 ms | Skip it if the final answer is already ready |
| Bounded external lookup final | p50 ≤ 2 seconds; p95 ≤ 4 seconds | Terminal result or scoped failure by 8 seconds |
| Complex work acknowledgement | p95 ≤ 800 ms | Visible dock state within 1 second |
| Complex work progress | First meaningful progress within 2 seconds | No-progress watchdog at 10 seconds |
| Complex work total | Task-specific, normally ≤ 30 seconds | Hard terminal deadline of 120 seconds unless the user explicitly authorized longer work |
| Confirmed barge-in | Stop playback p95 within 200 ms of meaningful confirmation | Never cancel background work implicitly |

No request may display an unchanged `Thinking` or `Working` state beyond its no-progress deadline. A long task must show real state changes or terminate with a failure.

## Retry policy

- Instant/local operations do not retry through a model.
- Read-only application and external operations may retry once within the same hard deadline.
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
- Instant answers and read-only requests may bypass active background work.
- Independent writes may run concurrently.
- Work targeting the same resource or dependent resources is serialized.
- Corrections, deletions, and cancellations targeting active work take priority over later unrelated writes.
- Work does not need to complete in spoken order.
- Every job appears separately in the working dock with queued, running, completed, failed, or canceled status.
- Out-of-order final messages identify the completed work by name; Bean never says only `Done` when several jobs are active.
- A multi-step logical request may contain several dock items but receives one combined final response.
- Adding a request while Bean is working does not interrupt or replace existing work unless the user explicitly corrects or cancels it.

## Stop behavior

Pressing Stop or saying `Stop`:

- Stops Bean's current speech only.
- Does not cancel active backend work.
- Does not clear queued requests.
- Does not undo completed side effects.
- Keeps microphone wake detection enabled.
- Returns voice interaction to wake-only mode unless an explicit active clarification requires otherwise.
- Does not delete the accepted request or its eventual final text.

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
- Selected lane and handler
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
2. The user says, `Hey Bean, what's on my calendar tomorrow?` The words appear live after wake activation. Two seconds of silence closes the utterance.
3. The calendar read finishes quickly, so Bean skips acknowledgement and answers directly within the app-read target.
4. After Bean finishes, the 15-second follow-up window begins. The user asks, `What time is the first one?` without a wake phrase. Bean uses the prior calendar context and answers directly.
5. While Bean is speaking, the user begins a meaningful new request. Playback may briefly lower while speech is evaluated; after speech is confirmed, the old audio stops permanently, its full text remains visible, and the new utterance is accepted once.
6. The user asks for a meal plan note. Bean acknowledges within 800 ms, creates visible dock items, and begins background work.
7. While that work runs, the user asks for the current time. Bean answers immediately without delaying or canceling the meal plan.
8. The user adds a reminder request. Bean says it added the request, shows a second dock item, and runs it when one of the three background slots is available.
9. The user presses Stop while Bean is speaking. Only playback stops. Both background jobs continue.
10. After the conversation returns to wake-only, the user says, `Hey Bean, did you finish the note?` Bean reports the real job state and does not claim Stop canceled it.
11. If a provider fails, Bean terminalizes that job, gives one natural failure response, offers a retry, and records the full diagnostic in admin.

## Required acceptance coverage

Before a voice release, deterministic and representative-device tests must cover:

- First wake immediately after microphone startup
- Missed `Hey` recovery and third-person `Bean` mentions
- Wake-only privacy and invisible background speech
- Live partial transcription and two-second utterance closure
- Incomplete request, five-second clarification, and one logical turn
- Fifteen-second follow-up timeout and natural closing phrases
- Instant, app read, app write, local weather, remote weather, and complex lanes
- Fast-result acknowledgement skipping
- Slow-result acknowledgement followed by non-overlapping final speech
- Meaningful barge-in, false barge-in, and permanent playback stop after confirmation
- Stop during acknowledgement, final speech, and background work
- Explicit single-job and all-job cancellation
- Three concurrent independent jobs and same-resource serialization
- Read-only bypass while background work is active
- Out-of-order job completion with clearly identified results
- Reload during capture, queued work, running work, acknowledgement, and final delivery
- Duplicate and out-of-order provider/browser events
- Provider timeout, transport failure, worker crash, and ambiguous write reconciliation
- Exactly one accepted user message and one final Bean message
- Admin diagnostic completeness without raw audio

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
