# Bean voice conversation quality benchmarks

Bean is voice-first: speech is the primary interaction, while chat is the durable record and the silent fallback. A release is "world-class" only when it meets every safety invariant and the latency, continuity, and correctness targets below on supported browsers and representative devices.

## Release gates

| Dimension | World-class target | Evidence |
| --- | --- | --- |
| Wake and stop safety | After an explicit stop, 0 of 1,000 non-wake transcripts produce UI text, persisted chat, tool work, or speech. An explicit `Hey Bean` reactivates on the first complete transcript in at least 99.5% of clean-audio trials. | Deterministic state-machine tests plus a noisy-audio replay suite. |
| Dormant-audio privacy | Wake-only room audio is not retained in model context. The wake phrase is detected locally before conversational audio reaches a transcription provider. | Fail-closed graph tests now; representative-device network captures must still prove that dormant audio remains local. |
| False activation | Fewer than 1 false activation per 24 hours of household/background audio, with no tool or write action from an unactivated transcript. | Long-running prerecorded background-audio test and production opt-in telemetry. |
| Response latency | Direct-answer audio starts at p50 <= 700 ms and p95 <= 1,500 ms after the completed transcript. Tool requests acknowledge at p95 <= 700 ms; final tool-backed speech starts at p50 <= 3 s and p95 <= 8 s, excluding a clearly surfaced provider outage. | Client event timestamps split by direct and tool-backed routes. |
| Barge-in | Playback stops within 200 ms of user speech at p95. The accepted user turn remains durable, interrupted Realtime assistant output is not persisted as complete or replayed, and the new utterance is handled exactly once. A backend result that completed before playback was interrupted may remain available as silent text. | Realtime event-order tests and browser audio-loop tests. |
| Turn continuity | At least 98% of contextual follow-ups during an active conversation work without repeating the wake word. Corrections, confirmations, and short references stay on the prior app/tool context. | Scripted multi-turn task suite scored for route and answer. |
| Tool truthfulness | 100% of mutation claims have a successful Laravel tool result. At least 99% of live-data answers use the intended provider and requested place/date/time; provider failure never becomes an unrelated answer. | Backend feature tests, sampled trace review, and provider-specific routing metrics. |
| Exactly-once behavior | Every accepted user turn and final answer appears once in chat. Working acknowledgements, rejected background speech, stale responses, and duplicate Realtime events appear zero times. | Deduplication/event-order tests plus conversation-record assertions. |
| Recovery | Network, permission, provider, and cancellation failures yield at most one concise actionable message, never strand the UI in a busy state, and return to the correct wake/sleep state. | Fault-injection tests for every external boundary. |
| Speech quality | Responses are normally one or two speakable sentences, avoid system jargon, pronounce dates/times naturally, and do not read URLs or raw schemas. At least 4.5/5 average on blinded human ratings for clarity, brevity, and naturalness. | Prompt conformance checks and recurring human evaluation. |
| Silent and accessible use | Typed chat never produces audio unless the user explicitly requests playback. Voice state is visibly and accessibly labeled, keyboard controls remain available, and the complete accepted conversation remains readable. | Browser accessibility checks and typed-chat regression tests. |

Safety, truthfulness, exactly-once behavior, and silent-mode behavior are hard gates; percentile averages cannot compensate for a failure in those categories.

Accepted voice turns are persisted before response playback with a stable client turn id, versioned `voice_quality` metadata, and a monotonic lifecycle outcome. Direct/status turns record transcript-to-response-request, OpenAI's `output_audio_buffer.started` signal, response duration, and completed/interrupted/failed/timed-out outcomes; only completed playback attaches assistant text. Tool-backed turns record transcript-to-request-start and retain their separate backend-run outcomes. These timing fields are diagnostic proxies, not proof that a person heard audio. The admin report at `GET /api/admin/voice-quality` deduplicates turn copies, labels low-volume data explicitly, exposes the direct/status outcome denominator, and never promotes proxies into benchmark passes.

Benchmark-equivalent latency gates require per-response client fields for user-audible direct audio, tool acknowledgement audio, and final tool-answer audio. The report understands those fields but marks their gates `insufficient_data` until the client emits enough valid samples. It reports direct/status lifecycle outcomes plus completed/cancelled/failed/hung outcomes for tool turns that link to backend runs, while clearly separating backend completion from voice playback. Accepted direct/status turns that never receive a terminal lifecycle update are reconciled server-side as `abandoned` after the configured deadline. The report separates fresh in-flight turns, stale unreconciled acceptances, and reconciled abandonments; an abandonment proves a missing terminal update, not a specific browser-crash cause.

## Canonical conversation states

The client must behave as one explicit state machine:

1. **Wake-only**: the provider-bound track is locally generated exact silence. Raw microphone PCM reaches only the same-origin AudioWorklet and local streaming-ASR worker; no provider transcript exists to reject. A current-generation local wake decision activates the state machine before the gate opens.
2. **Active/listening**: natural follow-ups are accepted without another wake word.
3. **Working/speaking**: app work or audio is in flight; one genuine follow-up may be queued, duplicate events are ignored, and user speech interrupts playback.
4. **Stopping**: current work/playback is cancelled, queued and partial input is cleared, and one optional pause acknowledgement may finish.
5. **Wake-only again**: acknowledgement audio cannot reactivate the conversation; only a new explicit wake word can.

State transitions, not model judgment, enforce the wake/stop boundary.

## Implemented local wake gate

The credential-free implementation uses the Apache-licensed [sherpa-onnx](https://k2-fsa.github.io/sherpa/onnx/kws/index.html) streaming English model in a dedicated single-threaded Web Worker. Unconstrained local ASR distinguishes the wake prefix from near-misses before an anchored prefix matcher admits it. An AudioWorklet keeps the provider-bound WebRTC track on locally generated exact silence while wake-only, sends 16 kHz analysis PCM only to the local worker, and retains a 320 ms provider-output delay line. When the worker detects `Hey Bean`, the conversation state machine activates before the gate opens; buffered command onset then reaches the provider without clipping. Stop closes and rearms the gate synchronously before asynchronous response/run cancellation.

The raw microphone track never attaches directly to the peer connection; WebRTC receives only the `MediaStreamDestination` track derived from the gate. Worker, worklet, timeout, backpressure, or browser initialization failure closes and tears down both audio paths instead of falling back to server-backed wake recognition. The packaged mixed-precision model/runtime is about 17.7 MB uncompressed and loads only after an intentional voice gesture. Release still requires clean-wake, false-activation, detection-latency, network-silence, cross-browser, CPU, memory, battery, device-change, and sleep/resume gates on representative hardware.

## Required scripted journeys

The release suite should cover at least these journeys on desktop Chrome/Safari and a mobile browser:

- `Hey Bean` -> direct question -> natural follow-up -> `Bean stop` -> background speech -> `can you still hear me` -> silence -> `Hey Bean, can you still hear me?` -> one answer.
- Tool-backed request -> immediate acknowledgement -> user correction while working -> one corrected final result and one persisted user/final pair.
- Assistant speaking -> user barges in -> old audio stops -> new turn completes once.
- Weather at a specific city/date/time -> correct hourly provider result; malformed place, outage, and out-of-range time -> concise scoped failure, never unrelated web content.
- Numeric and ASR-style weather times (`5 p.m.`, `five thirty p.m.`, `half past five p.m.`), weekdays/month dates, timezone boundaries, and a non-hour request -> the requested instant or an explicitly labeled nearest hourly forecast.
- Repeated and out-of-order transcription, function-call, response-done, and audio-stopped events -> one accepted turn.
- Logout, account deletion, workspace switch, New Chat, page hide, mic-track failure, and Realtime reconnect -> old transport/context is torn down and cannot mutate the new context.
- Typed context followed by a voice reference, and voice context followed by typed chat -> one shared durable conversation meaning with no unexpected audio in typed mode.
- Speaker echo/background recordings that mention or quote “Hey Bean” -> no activation unless the utterance is a deliberate invocation at the start.
- Typed question while voice is off or wake-only -> readable answer with no audio.

## Iteration rule

Each voice regression must add a deterministic replay or state-machine test before release. Improvements should be evaluated against the targets above; subjective prompt changes alone are not evidence of better conversational quality.

## Current evidence and blockers

The deterministic suite proves the ordered stop journey, 1,000 dormant rejections, strict wake negatives, delayed/out-of-order dormant items, response correlation/status handling, bounded response watchdog behavior, buffered-audio clearing, natural stop variants, serialized accepted-to-terminal persistence, and idempotent direct-turn lifecycle updates. The local audio tests prove bit-exact closed output, derived-track-only WebRTC wiring, current-generation activation before opening, bounded PCM backpressure, command-onset pre-roll, and fail-closed teardown. A non-isolated Chromium replay of the packaged local worker accepted six synthetic `Hey Bean` voices and rejected six near-miss/background phrases, including `Hey Ben`, `Hey beam`, embedded `green bean`, and ordinary `have been`. Backend feature tests prove the requested Orlando/date/time reaches Open-Meteo hourly data and recognized weather failures cannot become unrelated web answers. The admin report exposes instrumented sample counts, lifecycle outcomes, and percentiles without promoting request-start proxies into audio gates.

This branch is not yet entitled to the “world-class” label. A 1,000-trial clean-wake corpus, 24-hour false-activation corpus, representative device/noise/browser network captures, CPU/memory/battery measurements, explicit undo or compensating-action semantics for corrections after an irreversible external side effect, audible latency and barge-in percentiles, cross-modal continuity scoring, and blinded speech ratings remain required evidence. Local database mutations are serialized against cancellation and supersession; that guarantee cannot retroactively undo an external system that already accepted a request.
