# Bean Voice v2 browser evidence

`npm run test:voice:browser` runs the deterministic Playwright acceptance journeys.

`[BV2-FIRST-WAKE-01:C]` is the browser half of one paired, layered first-wake
regression journey. It generates the exact `Hey Bean, can you hear me?` fixture
with the temporary offline-TTS corpus builder, replays that PCM through the
production `LocalWakeGate`, AudioWorklet, worker, and packaged model, and
permits only the gate's real detection callback to activate the controller.
Simulated Realtime and durable projection events then prove safe PCM release,
admission, server-authorized audio delivery, reporting, and reload behavior.
The test asserts that the synthetic `wake()` helper was never called and that
no PCM escaped before confirmation.

The paired server journey sends that admitted audio through one leased OpenAI
Realtime sideband, one Hermes planning path, deterministic execution, one
receipt-grounded response authorization, and reload-safe durable projection.
Neither layer substitutes for the other. Actual provider/network behavior and
a physical microphone remain outside deterministic coverage.

`npm run preflight:voice:production` checks whether the publicly deployed development site contains the Browser Voice v2 shell marker, client API boundaries, current wake manifest, integrity-verified wake assets, proposal-only worker, one three-class model, and authenticated voice routes. Run it from `web/` before the owner's authenticated smoke test. No user allowlist is required:

```sh
npm run preflight:voice:production
VOICE_V2_PRODUCTION_URL=https://example.test npm run preflight:voice:production
```

The preflight exits nonzero when any public deployment check fails. It validates that `wakeModelQaCertified` and `releaseCertified` are booleans but does not require either to be true, so the owner may test an explicitly uncertified development build. Required asset availability, byte counts, and SHA-256 values still have to pass. The preflight proves deployment presence only: it does not authenticate, request microphone access, submit work, exercise reload recovery, or measure acoustic or audible latency. A passing preflight is therefore required before production smoke but is never release certification by itself.

## Prerecorded engine replay and synthetic adapter benchmark

`npm run benchmark:voice:browser` runs two explicitly different evidence tiers on every locally available target:

1. an offline TTS corpus is prerecorded in a temporary directory and injected through `AudioBufferSourceNode` → `MediaStream` → the real `LocalWakeGate` → AudioWorklet → worker → bundled proposal-only keyword spotter → the single `bean-wake-model-v2.json` three-class classifier; and
2. the browser adapter harness measures controller, DOM, speech scheduling, barge-in, and dock projection milestones.

The benchmark installs a `getUserMedia` tripwire before application code loads. Any attempted microphone call is denied, counted, and fails that target. It never requests microphone permission, captures ambient audio, or plays audible audio. Generated audio exists only in memory during the run, temporary files are removed before browser execution, activated PCM is consumed synchronously for count/timing aggregates, and no PCM or base64 audio is emitted in the JSON result.

The matrix covers six primary English system voices plus documented regression
voices and includes:

- six isolated `Hey Bean` wakes;
- twelve wake-plus-command utterances: `Hey Bean, what time is it?` and the first-wake regression phrase `Hey Bean, can you hear me?` across all six voices;
- six `Hey Bean` wakes embedded after ongoing speech;
- twelve contract-approved pronunciation-variant wakes: `Hey beam` alone and `Hey beam, can you hear me?` across all six voices. Every accepted variant must reach `strict_wake` through the same three-class model as `Hey Bean`; a recognition miss counts against the one aggregate QA rate, and `Hey beam` is never a second runtime alias;
- one transformed `Hey Bean, can you hear me?` proposal-and-classifier stress case;
- four safe missed-`Hey` address forms across all six voices (24 files); and
- 136 privacy files across 31 families: 21 broad families replayed across all six voices, nine transformed disallowed-near-match hard negatives, and one documented packaged-model regression variant. Named phrases are QA inputs only; they are not runtime aliases or hard-coded exceptions. The corpus generator rejects byte-identical audio files with conflicting expected decisions, preventing impossible homophone labels from inflating or invalidating QA.

For each voice, the runner presents every negative family, verifies a re-arm after every rejection, and then measures an immediate strict wake. Recognition misses, missed-address decisions, and failed reset/re-arm journeys each count as failures in the single required aggregate QA rate; they are not separate 100%-recall subgroup blockers. Every proposal is coalesced with fixed local context and exactly 2,560 samples (160 ms) of tail before the one model returns `reject`, `strict_wake`, or `missed_hey_confirmation`. Every accepted journey requires one compatible sanitized `classification_decision`, the proposal-timestamp safe release boundary, zero preconfirmation PCM, and a successful reset/re-arm. A `wake_proposal` alone may release nothing. Any activation or provider release from a privacy negative is a hard wake-only privacy failure, as are raw-audio escape before classification, persisted wake-only speech, runtime errors, duplicate work, and lifecycle-integrity failures regardless of the aggregate rate. The default four repetitions apply to isolated wakes. Wake-plus-command, ongoing-speech wake, approved pronunciation variants, missed-`Hey`, and unique cross-voice privacy files run once each. `VOICE_V2_WAKE_REPLAYS` increases only isolated-wake repetitions, preserving broad coverage without multiplying the cross-voice negative matrix.

Results deliberately separate:

- three-class wake-model accuracy and proposal-to-classification latency;
- local activated-PCM gate handoff timing;
- local Realtime input-append latency through the production resampler/encoder and an in-page loopback sender (with actual data-channel/provider/network latency kept explicitly unmeasured); and
- deterministic regression status versus representative release certification.

The prerecorded runner always reports `release_certified: false`. The current v2 development artifact is packaged, while its cross-engine acoustic replay report remains pending; results from an earlier wake architecture do not certify it. A future local pass cannot substitute for physical-microphone, human-speaker, room/noise, actual-provider, audible playback, Safari, Chrome, and Edge release evidence.

The result conforms to `voice-v2-benchmark-result.schema.json`. Useful controls are:

```sh
VOICE_V2_BENCHMARK_OUTPUT=/tmp/bean-voice-v2.json npm run benchmark:voice:browser
VOICE_V2_BENCHMARK_TARGETS=playwright-chromium,google-chrome npm run benchmark:voice:browser
VOICE_V2_WAKE_REPLAYS=6 VOICE_V2_BENCHMARK_SAMPLES=200 npm run benchmark:voice:browser
```

Each engine's prerecorded deadline is corpus-derived: 120 seconds plus four
seconds per unique file, with a seven-minute minimum (15 minutes 8 seconds for
the current 197-file matrix). Each synthetic adapter run has a three-minute
outer deadline. A stalled engine is recorded as failed and closed; it cannot
hang the benchmark command indefinitely.

Target meanings are deliberately narrow:

- `playwright-chromium`: Chromium engine regression evidence.
- `google-chrome`: installed Chrome product regression evidence, headless and non-acoustic.
- `playwright-webkit`: WebKit engine proxy evidence; it is not Apple Safari.
- `microsoft-edge`: installed Edge product evidence when Edge is actually installed; Chromium is never relabeled as Edge.

All output is classified as partial engine regression evidence. It cannot satisfy representative Chrome, Safari, or Edge acoustic/audible certification because it excludes a physical microphone, device driver, human speakers, rooms, background noise, provider/network latency, and actual audible playback.
