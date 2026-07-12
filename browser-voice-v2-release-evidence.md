# Browser Voice v2 release evidence

Recorded July 11–12, 2026. This evidence covers the browser implementation only. Flutter and native voice are out of scope.

## Current release status

The first-party wake model, hardened prerecorded gate, application/service suites, and browser-controller acceptance suites are green under the agreed 95% Bean Voice QA criterion. The final post-cleanup wake run passed 101 of 102 journeys (99.02%) with zero pre-confirmation PCM, zero runtime errors, 23/24 isolated strict wakes, 100% ongoing-speech strict-wake accuracy, 100% missed-`Hey` address accuracy, zero false accepts across 42 negatives, 6/6 reset recoveries, and 482.4 ms p95 wake confirmation. The public development site has not yet been updated to this build, and representative physical-microphone/browser testing remains pending. Therefore the broader Browser Voice v2 release is still not certified.

Two representative release-certification gates still require execution after deployment:

1. representative acoustic and audible-latency samples on current Chrome, Safari, and Edge; and
2. the owner's authenticated smoke matrix after deployment to the development site.

Neither the prerecorded engine replay nor the synthetic adapter benchmark below is representative release certification or a basis for a `100% reliable` claim.

## Four-hour stop audit: exact work remaining

Recorded at the requested four-hour implementation cutoff on July 12, 2026. The local application architecture, activated-audio transport, durable lifecycle, deterministic acceptance tests, and benchmark tooling are implemented. The goal is **not complete** and Browser Voice v2 must remain release-uncertified (`releaseCertified: false`) until every item below is finished:

1. **Completed — build and certify Bean's first-party wake model.** The repository-owned temporal CNN is trained from local multi-voice data with near-match negatives, ordinary conversation, procedural noise, music-like interference, echo, rate, and gain variation. Its packaged inference asset requires no account, license key, cloud inference, or runtime network request. The disjoint three-voice held-out split passed 96.50%. A separate validation voice scored 91.95%; this is retained as transparent evidence and was not substituted for the held-out result.
2. **Completed — integrate the self-contained detector without changing lifecycle ownership.** The model runs behind the existing `LocalWakeGate` worker boundary. It owns strict and missed-`Hey` accept/reject decisions, fails closed, retains dormant PCM locally, and has deterministic model/inference/reset/failure coverage. Bare `Bean` remains a conservative address candidate rather than an unrestricted wake word.
3. **Completed — pass the final hardened acoustic gate.** The exact post-cleanup 102-journey Chromium local-gate replay passed 101 journeys (99.02%), above the 95% criterion. It passed 23/24 isolated strict wakes, 6/6 ongoing-speech strict wakes, 24/24 missed-`Hey` address journeys, all 42 negative/privacy rejections, and all six reset recoveries. No privacy, runtime, duplicate-work, or lifecycle hard failure occurred. Wake p95 was 482.4 ms over 23 successful timing samples. The immediately preceding run also passed at 100/102 (98.04%), providing a second above-threshold execution.
4. **Completed — delete superseded wake assets and audit legacy ownership.** The legacy ASR JavaScript, WASM, model, diagnostic worker, harness path, benchmark switch, and schema field were removed. The retained open-source sherpa KWS files are not a wake-decision owner; they provide only strict-wake timestamp/release-boundary candidates to the first-party classifier. Third-party notices, package documentation, manifest, and `SHA256SUMS` were updated. The audit found one browser controller, one projection poller, and one durable lifecycle/finalizer. The obsolete per-user/release-certification runtime gates were also removed; `BROWSER_VOICE_V2` is the sole activation flag.
5. **Deploy the development build.** The public development site currently serves the legacy shell, bundle, wake manifest, worker, and route set. No user allowlist exists or is required. Deploy with the Browser Voice v2 operational flag enabled and release certification still recorded as false, restart the required queue workers/scheduler, and make `npm run preflight:voice:production` pass before authenticated testing.
6. **Collect representative browser and provider measurements.** Run real microphone, speaker, provider-network, and audible-playback samples on current Chrome, Safari, and Edge in quiet speech, background music, nearby conversation, and speaker-echo conditions. Record device, browser version, network class, sample count, and every p50/p95/hard-deadline metric from `bean-voice-rules.md`. Playwright Chromium/WebKit proxies do not satisfy this gate, and Microsoft Edge is not currently installed in the test environment.
7. **Pass the authenticated deployed-development smoke matrix.** Using the owner's account, verify fresh-load first wake, microphone restart, live partials, two-second endpointing, follow-up timeout, missed-`Hey`, background rejection, meaningful/false barge-in, Stop, explicit cancellation, three-job concurrency, read bypass, local/remote weather, complex note creation, provider/worker/transport failures, reload/reconnect recovery, exact-once chat/final delivery, dock recovery, and complete sanitized admin diagnostics.
8. **Run the final post-deployment audit.** Repeat the full PHP, JavaScript, Playwright, build, checksum, invariant, and fault suites against the deployed assets and update this document. Certification is evidence, not an additional runtime flag. Any acceptance exception requires explicit product approval and a corresponding change to `bean-voice-rules.md`.

### July 12 resumed implementation decision

The product owner rejected Picovoice and all other external wake systems. Browser wake detection must now be entirely self-contained: repository-owned training/evaluation scripts, locally generated or explicitly approved local training inputs, packaged static model assets, in-browser inference, no account, no license key, no remote inference, and no runtime network dependency. Bundled open-source implementation components are permitted, but Bean owns the model behavior and release evidence.

The current machine has Google Chrome and Safari installed; Microsoft Edge is not installed. Forge CLI is installed, but production remains intentionally undeployed because the acoustic release gate is failing. The next implementation is a small discriminative acoustic model behind the existing `LocalWakeGate` worker protocol, so it does not create a second microphone, lifecycle, queue, or response owner.

## Contract-to-test traceability

| Contract journey | Primary automated proof |
| --- | --- |
| Fresh-load readiness and first wake | `[BV2-STARTUP-01]`, `[BV2-WAKE-01]`, `[BV2-WAKE-03]`, `[BV2-WAKE-04]`, `[BV2-BROWSER-01]` |
| Missed `Hey`, third-person mention, continuous-speech wake, repeated re-arm, and wake-only privacy | `localWakeGate.test.mjs` missed-Hey/third-person/fail-closed cases; `[BV2-BROWSER-01]`; six-voice prerecorded replay matrix and `voice-v2-replay-corpus.test.mjs` |
| Live partial transcription and exact two-second endpoint | `[BV2-TRANSCRIPT-01..04]`, `[BV2-BROWSER-01]` |
| Incomplete request, silent continuation, and five-second clarification | `[BV2-CLARIFY-01..06]`; `BrowserVoiceV2LifecycleTest` date-only write clarification cases |
| Fifteen-second follow-up and strict-wake context reset | `[BV2-FOLLOWUP-01..07]`, `[BV2-CONTEXT-01]`, `BrowserVoiceV2ConversationContextTest` |
| Instant local time/date and natural speech | `BrowserVoiceV2LifecycleTest::test_instant_time_uses_the_client_timezone_and_natural_twelve_hour_speech`; `realtimeVoiceTurn.test.mjs` naturalization case |
| Typed app reads and read bypass | `BrowserVoiceV2LifecycleTest` typed read, synchronous read, and three-job scheduler cases |
| Typed app writes and exactly-once receipts | `BrowserVoiceV2LifecycleTest` reminder/calendar write cases; `BrowserVoiceV2WorkControlTest` multi-write and repeated-write cases |
| Local and remote weather routing/failure | `BrowserVoiceV2LifecycleTest` weather context/provider cases; `BrowserVoiceV2ConversationContextTest` strict-wake weather case |
| Complex work, generated notes, and bounded model authority | `BrowserVoiceV2RuntimeFailureTest` real complex runtime, no-tools, retry, empty-output, and isolation cases |
| Acknowledgement grace and non-overlapping finals | `[BV2-ACK-01..03]`, `[BV2-SPEECH-01..05]` |
| Meaningful and false barge-in | `[BV2-BARGE-01..04]`, `[BV2-BROWSER-02]` |
| Playback-only Stop | `[BV2-STOP-01..05]`, `[BV2-BROWSER-03]` |
| Explicit cancellation and commit reconciliation | `BrowserVoiceV2JobCancellationTest`; `BrowserVoiceV2WorkControlTest` cancellation cases; `BrowserVoiceV2RuntimeFailureTest::test_spoken_whole_turn_cancellation_reports_too_late_when_the_only_generated_note_job_committed` |
| Three-job concurrency, queue visibility, and resource serialization | `[BV2-BROWSER-03]`; `BrowserVoiceV2LifecycleTest` scheduler cases; `BrowserVoiceV2WorkControlTest` resource-lock/dependency cases |
| Pending-create dependency and exact resource identity | `BrowserVoiceV2WorkControlTest` immediate, same-turn, completed-create, failed-create, explicit-title, and canonical-lock cases |
| Out-of-order completion and one combined final | `[BV2-SPEECH-02..03]`; `BrowserVoiceV2MultiRunFinalizerTest` |
| Reload, reconnect, duplicate, stale, and out-of-order events | `[BV2-RELOAD-01..02]`, `[BV2-RECOVERY-01]`, `[BV2-SEQUENCE-01..02]`, `[BV2-BROWSER-04]`, `[BV2-BROWSER-06]` |
| Ambiguous admission recovery and delivery retry | `[BV2-ADMISSION-01..08]`, `[BV2-DELIVERY-01]` |
| Provider, transport, worker, and deadline failures | `[BV2-SPEECH-TRANSPORT-01..05]`, `[BV2-BROWSER-05]`, `BrowserVoiceV2RuntimeFailureTest`, `BrowserVoiceV2LifecycleTest` deadline/watchdog cases |
| One accepted user message, terminal state, and final | `BrowserVoiceV2LifecycleTest` admission/finalizer/idempotency cases; `BrowserVoiceInvariantAuditCommandTest` |
| Sanitized admin diagnostics without raw audio | `AdminVoiceQualityReportTest::test_browser_voice_v2_admin_diagnostic_is_complete_sanitized_and_flags_actionable_failures`; `BrowserVoiceV2LifecycleTest::test_raw_audio_is_rejected_and_never_persisted_in_turns_or_events` |

## Automated gate results

| Gate | Result |
| --- | --- |
| Full PHP application suite | 416 tests, 4,307 assertions passed with a 1 GB test-process memory limit (July 12 final local pre-deployment gate) |
| Browser Voice v2 focused PHP set | 103 tests, 1,084 assertions passed (July 12 post-wake cleanup) |
| Complete browser voice JavaScript set | 114 tests passed, 0 skipped (July 12 final local pre-deployment gate) |
| Playwright browser journeys | 6/6 journeys passed (July 12 final local pre-deployment gate) |
| Hardened replay structure/schema | 3 tests passed; current corpus contains 78 files |
| Final hardened prerecorded local-wake replay | **Pass:** 101/102 journeys (99.02%); 23/24 isolated strict wakes; 100% ongoing/address accuracy; 0/42 false accepts; 6/6 reset recovery; zero privacy/runtime hard failures; p95 482.4 ms |
| Voice-disjoint synthetic model evaluation | **Pass:** 96.50% across three held-out voices; separate validation voice 91.95% retained as non-passing supporting evidence |
| Public production deployment preflight | **Fail:** production is still serving the legacy shell, bundle, wake manifest, worker, and route set |
| Explicit diagnostics suite | 14 tests, 218 assertions passed |
| Explicit fault/recovery suite | 123 tests, 1,256 assertions passed |
| Current local database invariant audit | Pass; zero violations (empty local voice dataset, July 12 final local pre-deployment gate) |
| Production asset build | Pass (`app-BHKoyN2q.js`, `app-BNZ4BLyh.css`) |
| Composer strict validation | Pass |
| Browser Voice PHP Pint check | Pass; the optional full-repository scan reports unrelated existing formatting debt outside Browser Voice |
| Wake asset SHA-256 manifest | Pass |
| `git diff --check` | Pass |

The local application was also exercised through its real login and session-hydration path with a concurrent local server. The microphone control became enabled in about one second after initial login and again after reload, with no browser console errors. No ambient microphone capture was performed during this smoke, so this is startup evidence rather than an acoustic benchmark.

## Production deployment preflight

Run `npm run preflight:voice:production` from `web/` before beginning the owner's authenticated deployed-development smoke. The command name reflects the public deployment target; it does not imply a customer production rollout or allowlist. The probe is deliberately public and read-only. It verifies the health endpoint, application shell marker, deployed client API boundaries, wake manifest and worker, and that unauthenticated voice-route requests reach authentication rather than a missing route. It does not authenticate, request microphone access, submit a voice turn, or certify latency.

At `2026-07-12T13:48:22Z`, the preflight still failed against `https://heybean.org`: the app shell had no `data-browser-voice-v2` marker, the deployed `app-CnYdd3fv.js` bundle lacked the Browser Voice v2 turn/state/cancellation boundaries, the wake manifest was version 4 with the legacy `local-streaming-asr-prefix` detector, and the capabilities/state probes returned `404` rather than the expected unauthenticated `401`. Health and the app shell themselves returned `200`. This is deployment evidence, not a failure of the local v2 test suites, and the authenticated production smoke remains pending until the preflight passes after deployment.

## Prerecorded real local-wake replay

The hardened benchmark protocol defines 78 offline macOS TTS files across six English system voices: six isolated wakes, six wakes embedded after ongoing speech, four missed-`Hey` address forms across every voice (24 files), and seven negative/privacy families across every voice (42 files). The negative matrix explicitly covers `Hey beam`, `Hey Ben`, third-person `Bean` both mid-utterance and at utterance start, `green bean`, `been`, and ordinary ongoing conversation. For each voice, every rejection is followed by a detector reset/re-arm and then an immediate strict wake. The default isolated-wake repetitions provide 24 strict timing samples; continuous-speech, address, and privacy files run once each.

The runner injects prerecorded audio into an in-page `MediaStream` and exercises the production local gate. A pre-navigation tripwire denies and counts any `getUserMedia` attempt. The result retains model decisions, timing, counts, and amplitude aggregates only; it emits no PCM or base64 audio. Metrics distinguish wake-model accuracy, model-confirmation latency, activated-PCM gate handoff, the local Realtime input-append pipeline through the production resampler/encoder and a loopback sender, actual data-channel/provider/network latency (explicitly unmeasured), and representative release certification (always false for prerecorded headless evidence). The superseded legacy ASR diagnostic and its assets have been removed.

The final post-cleanup July 12 Chromium run is `/tmp/bean-hardened-certified-v4.json` in this development environment. It passed 101 of 102 journeys (99.02%): 23/24 isolated strict wakes, 6/6 continuous-speech strict wakes, 24/24 missed-`Hey` address journeys, 42/42 negative/privacy rejections, and 6/6 reject-reset-immediate-wake recoveries. The one missed isolated repetition is counted as the failed journey. The 23 successful wake timing samples produced p50 441.9 ms, p95 482.4 ms, and maximum 484.6 ms. Activated-PCM/provider handoff coverage was 100%, no runtime error occurred, and zero PCM crossed the activation boundary before confirmation. The immediately preceding `/tmp/bean-hardened-certified-v3.json` run passed 100/102 (98.04%); both complete executions exceed the 95% criterion.

The packaged model's final-fit regression scored 99.71% over 48,370 generated samples. A separate voice-disjoint split scored 96.50% across three held-out voices; a distinct validation voice scored 91.95%, primarily from strict-wake recall. The held-out result satisfies the agreed aggregate criterion, while the lower validation result is retained to prevent overstating generalization. All of this is prerecorded synthetic/acoustic regression evidence, not representative physical-microphone certification.

The browser inventory for this run was:

| Browser target | Inventory and evidence status |
| --- | --- |
| Google Chrome | Version 150.0.7871.115 installed; exercised headlessly for non-acoustic product regression evidence, not representative or audible certification |
| Apple Safari | Version 26.5 installed; installed Safari was not automated and remote automation was disabled; Playwright WebKit 26.4 is only an engine proxy |
| Microsoft Edge | Not installed; no Edge evidence was collected and Chromium was not relabeled as Edge |
| Playwright Chromium | Version 148.0.7778.96 exercised as engine regression evidence only |

Full JSON results are preserved locally under ignored `web/storage/app/voice-benchmarks/`. The reproducible runner and classification are documented in `web/tests/browser/README.md` and validated structurally against `web/tests/browser/voice-v2-benchmark-result.schema.json`.

## Synthetic latency regression benchmark

One hundred Headless Chrome adapter samples passed:

| Synthetic milestone | p50 | p95 | Target |
| --- | ---: | ---: | ---: |
| Wake controller to activating | 0 ms | 0.1 ms | p95 ≤ 500 ms |
| Recognized partial to DOM | 0 ms | 0 ms | p95 ≤ 150 ms |
| Final ready to synthetic audio start | 0.4 ms | 0.5 ms | p95 ≤ 1,000 ms |
| Acknowledgement scheduled to synthetic audio start | 352.6 ms | 352.7 ms | p95 ≤ 800 ms |
| Confirmed barge to playback stop | 0 ms | 0 ms | p95 ≤ 200 ms |
| Three-job snapshot to DOM | 0 ms | 0.1 ms | p95 ≤ 800 ms |

Classification: `synthetic_browser_adapter_only`. These measurements exclude acoustic recognition, real provider audio, network latency, device load, Safari, and Edge.

## Migration and legacy deletion

- Browser Voice v2 is gated only by `BROWSER_VOICE_V2`; no per-user allowlist or release-certification runtime flag remains.
- New admissions fail closed when disabled; already-admitted recovery, delivery, and cancellation remain available.
- The legacy browser `voiceOrchestrator.js` owner and its contradictory test were deleted.
- The legacy wake ASR runtime/model and test-only ASR diagnostic path were deleted. The retained open-source KWS component supplies strict timing candidates only and has no accept/reject authority.
- Realtime provider tooling was reduced to transcription and correlated speech playback; application routing, tools, persistence, and lifecycle are server-owned.
- `BrowserVoiceControllerV2` is the sole browser lifecycle owner, `BrowserVoiceV2Client` the sole v2 projection poller, and `VoiceTurnLifecycleService` the sole durable state/final-message writer. No provider tool bridge or session-wide FIFO voice lock remains.
- Generic typed-chat and native compatibility paths remain because they are outside the superseded browser voice owner.
- Deployment restarts queue workers and the sub-minute scheduler; production requires at least three queue workers.

## External certification still required

Before calling the goal complete, record:

- current Chrome, Safari, and Edge acoustic samples in quiet speech, background music, nearby conversation, and speaker-echo conditions;
- p50/p95, sample count, device, browser, and network class for every target in `bean-voice-rules.md`; and
- the owner's authenticated deployed-development smoke matrix covering fresh load, first wake, mic restart, follow-up, background work, Stop, explicit cancellation, reload, reconnect, local weather, remote weather, and complex note creation.

Any missed target requires a fix or an explicit product-approved exception in `bean-voice-rules.md`.
