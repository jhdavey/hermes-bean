# Bean Voice v2 browser evidence

`npm run test:voice:browser` runs the deterministic Playwright acceptance journeys.

`npm run preflight:voice:production` checks whether the publicly deployed development site contains the Browser Voice v2 shell marker, client API boundaries, current wake manifest and worker, and authenticated voice routes. Run it from `web/` before the owner's authenticated smoke test. No user allowlist is required:

```sh
npm run preflight:voice:production
VOICE_V2_PRODUCTION_URL=https://example.test npm run preflight:voice:production
```

The preflight exits nonzero when any public deployment check fails. It proves deployment presence only: it does not authenticate, request microphone access, submit work, exercise reload recovery, or measure acoustic or audible latency. A passing preflight is therefore required before production smoke but is never release certification by itself.

## Prerecorded engine replay and synthetic adapter benchmark

`npm run benchmark:voice:browser` runs two explicitly different evidence tiers on every locally available target:

1. an offline TTS corpus is prerecorded in a temporary directory and injected through `AudioBufferSourceNode` → `MediaStream` → the real `LocalWakeGate` → AudioWorklet → worker → packaged local wake model; and
2. the browser adapter harness measures controller, DOM, speech scheduling, barge-in, and dock projection milestones.

The benchmark installs a `getUserMedia` tripwire before application code loads. Any attempted microphone call is denied, counted, and fails that target. It never requests microphone permission, captures ambient audio, or plays audible audio. Generated audio exists only in memory during the run, temporary files are removed before browser execution, activated PCM is consumed synchronously for count/timing aggregates, and no PCM or base64 audio is emitted in the JSON result.

The matrix has 78 unique files across six English system voices:

- six isolated `Hey Bean` wakes;
- six `Hey Bean` wakes embedded after ongoing speech;
- four safe missed-`Hey` address forms across all six voices (24 files); and
- seven privacy/near-miss families across all six voices (42 files): `Hey beam`, `Hey Ben`, third-person `Bean` both mid-utterance and at utterance start, `green bean`, the `been` homophone, and ongoing conversation without an address.

For each voice, the runner presents every negative family, verifies a re-arm after every rejection, and then requires an immediate strict wake. The default four repetitions apply to the six isolated wake files (24 strict-wake samples); continuous-speech wakes, missed-`Hey` forms, and unique cross-voice privacy files run once each. `VOICE_V2_WAKE_REPLAYS` increases only isolated-wake repetitions, preserving broad coverage without multiplying the already cross-voice negative matrix.

Results deliberately separate:

- wake-model accuracy and model-confirmation latency;
- local activated-PCM gate handoff timing;
- local Realtime input-append latency through the production resampler/encoder and an in-page loopback sender (with actual data-channel/provider/network latency kept explicitly unmeasured); and
- deterministic regression status versus representative release certification.

The prerecorded runner always reports `release_certified: false`. A local pass cannot substitute for physical-microphone, human-speaker, room/noise, actual-provider, audible playback, Safari, Chrome, and Edge release evidence.

The result conforms to `voice-v2-benchmark-result.schema.json`. Useful controls are:

```sh
VOICE_V2_BENCHMARK_OUTPUT=/tmp/bean-voice-v2.json npm run benchmark:voice:browser
VOICE_V2_BENCHMARK_TARGETS=playwright-chromium,google-chrome npm run benchmark:voice:browser
VOICE_V2_WAKE_REPLAYS=6 VOICE_V2_BENCHMARK_SAMPLES=200 npm run benchmark:voice:browser
```

Target meanings are deliberately narrow:

- `playwright-chromium`: Chromium engine regression evidence.
- `google-chrome`: installed Chrome product regression evidence, headless and non-acoustic.
- `playwright-webkit`: WebKit engine proxy evidence; it is not Apple Safari.
- `microsoft-edge`: installed Edge product evidence when Edge is actually installed; Chromium is never relabeled as Edge.

All output is classified as partial engine regression evidence. It cannot satisfy representative Chrome, Safari, or Edge acoustic/audible certification because it excludes a physical microphone, device driver, human speakers, rooms, background noise, provider/network latency, and actual audible playback.
