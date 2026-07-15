# Hey Bean local wake assets

This directory is Bean's self-contained, same-origin wake boundary. Raw microphone audio enters the `hey-bean-gate` AudioWorklet first. The worklet's rendered output is always bit-exact zero; its 16 kHz mono analysis PCM stays in a bounded, memory-only local ring and is sent only to the dedicated wake worker until local activation succeeds.

## Runtime contract

- Load `/voice/wake/gate-processor.js` with `audioContext.audioWorklet.addModule()`, then construct an `AudioWorkletNode` with processor name `hey-bean-gate`.
- Construct a classic worker from the versioned manifest URL and append `generation=<non-negative integer>`. The worker's `ready` message proves that the keyword spotter, the Bean classifier, a warm decode, and the recognition stream are ready. The UI may report wake readiness only after that worker barrier, the matching AudioWorklet `processor_ready`, the silent analysis sink, and accepted live audio flow.
- The worklet accepts `{type: "activate"|"close"|"destroy", generation}`. It posts `processor_ready`, transferable 16 kHz `audio` chunks, normalized `activity` messages, and fail-closed errors. Its rendered audio output remains zero in every state.
- `LocalWakeGate` retains at most 6.4 seconds of ordered PCM in memory and keeps a bounded number of unacknowledged worker chunks. It increments the generation at each activation boundary and ignores older-generation messages.
- The bundled local keyword spotter emits only `HEY_BEAN` or `BEAN` proposals and timestamps. A proposal never activates Bean and never releases PCM.
- Exactly one Bean-authored classifier, `/voice/wake/bean-wake-model-v2.json`, evaluates each coalesced proposal with fixed local context and exactly 2,560 samples (160 ms) of local tail. Its schema is `2.0.0`, model ID is `bean-first-party-wake-v2`, input has 21,760 samples, and its classes are exactly `reject`, `strict_wake`, and `missed_hey_confirmation`.
- An accepted class must be compatible with the proposal type. Deterministic code then uses that proposal's timestamp to establish the corresponding safe release boundary. A rejected or stale proposal returns silently to wake-only and erases retained PCM.
- `Hey Bean` and the approved acoustically close pronunciation `Hey beam` cross the same proposal and three-class decision. `Hey beam` is QA pronunciation tolerance, not a second keyword alias or phrase-specific exception.
- A strict wake releases only the configured post-address tail and command audio. A confirmed missed-`Hey` wake releases from the locally established utterance onset. Candidate audio and text remain local while the classifier decides.
- Provider WebRTC audio is receive-only. After confirmation the browser clears uncommitted provider input, resamples only safe-boundary audio to mono 24 kHz PCM16 little-endian, and sends ordered `input_audio_buffer.append` events. The browser never attaches a microphone-derived `MediaStream` to the provider connection.
- Any worklet or worker error, generation mismatch, missing acknowledgement, queue overflow, invalid model, or unexpected termination closes the gate synchronously and requires an explicit user gesture to recover.
- On teardown, close the gate before asynchronous cleanup, send `destroy` to the worklet and `close` to the worker, terminate the worker, stop the raw microphone track, and close the audio context.

The worker's sanitized decision protocol is fixed. `wake_proposal` reports `proposalType`, `timestampCount`, and `requiredTailSamples: 2560`. `classification_decision` reports `proposalType`, `winningClass`, `accepted`, `probability`, `threshold`, `sampleCount: 21760`, and `tailSamples: 2560`. `wake_confirmed` contains only the fixed product alias, activation kind, source sequence, and safe release boundary. The worker never sends decoded candidate text, command text, or audio to the main thread.

`manifest.json` keeps `wakeModelQaCertified` and `releaseCertified` false until the final three-class artifact, its repository replay, package integrity, and representative physical-microphone/browser evidence exist. Those flags record evidence status; they do not prevent the owner from loading an uncertified development build. The read-only deployment preflight still fails if the manifest's required model or asset integrity metadata is missing.

## Development owner-test status

During the current single-owner development phase, `origin/main` deploys to the live production environment for owner testing. That environment is not a commercially certified release. The frozen proposal run recorded fit strict coverage of 794/1,100 (72.18%) against 95%, fit missed-`Hey` address coverage of 946/1,300 (72.77%) against 95%, Kathy strict coverage of 4/44 (9.09%) against 80%, and Kathy address coverage of 0/52 (0%) against 80%. The run stopped before classifier training and produced no v2 classifier artifact.

Those failures remain visible and keep `wakeModelQaCertified` and `releaseCertified` false. They block commercial certification but do not by themselves block an otherwise operational owner-test push. Owner testing does not weaken dormant privacy, duplicate/stale-event safety, terminal diagnostics, or raw-audio-retention requirements. `Hey beam` remains pronunciation tolerance through the same acoustic proposal and three-class decision as `Hey Bean`; it is never a text alias or runtime exception.

## Privacy and deployment properties

The Wasm runtime is single-threaded, declares unshared memory, and does not use `SharedArrayBuffer`, so this package does not require COOP/COEP or cross-origin isolation. It does require same-origin Worker, AudioWorklet, and Wasm permissions in the application's Content Security Policy.

`kws-model.data` contains the pinned upstream proposal model. `bean-wake-model-v2.json` contains Bean's sole acceptance classifier and normalization values. Both run locally; wake-only audio is not uploaded or persisted.

## Provenance and build recipe

- Inference source: [k2-fsa/sherpa-onnx](https://github.com/k2-fsa/sherpa-onnx) commit `d7526c835a5a70b9a936100dfc39e527a49893b6` (2026-03-18), the selected revision before the later browser pthread runtime.
- Proposal model: `sherpa-onnx-kws-zipformer-zh-en-3M-2025-12-20`, published by `pkufool` for sherpa-onnx and marked Apache-2.0.
- Bean wake classifier: generated by the repository-owned voice training pipeline from documented local inputs. Training audio remains under ignored local storage and is never uploaded.
- Toolchain: Emscripten `3.1.53`, CMake Release build, single-thread keyword-spotting target.
- Linker configuration: `INITIAL_MEMORY=256MB`, `ALLOW_MEMORY_GROWTH=1`, `ENVIRONMENT=web,worker`, `MODULARIZE=1`, and `EXPORT_NAME=createSherpaKwsModule`. No pthread flag is present.
- Local wrapper integration patch: `kws-api.js` preserves an explicit `numTrailingBlanks: 0` value with nullish/default semantics so the proposal configuration is not silently changed to `1`.
- Model packaging: int8 encoder, FP32 decoder, and int8 joiner.
- Stable vendor filenames are `kws-runtime.js`, `kws-runtime.wasm`, `kws-model.data`, and `kws-api.js`. The worker's `locateFile` callback maps those same-origin assets at runtime.

Runtime v16 requires fresh repository replay evidence for exact and pronunciation-tolerant strict wakes, continuous-speech wake, missed-`Hey` recovery, privacy negatives, safe-boundary PCM release, and reset/re-arm in each representative browser. At least 95% of all executed Bean Voice QA journeys must pass; raw-audio escape before confirmation, persistence of wake-only speech, runtime errors, duplicate work, and lifecycle-integrity failures remain hard failures. Prerecorded evidence is not representative physical-microphone release certification.

The vendor files are byte-for-byte copies of the validated build outputs. `manifest.json` records byte counts and hashes for every required deployed asset once available. `SHA256SUMS` is the repository package inventory and is refreshed whenever an approved package member changes. Verify it from this directory with:

```sh
shasum -a 256 -c SHA256SUMS
```

Third-party terms and source links are in `THIRD_PARTY_NOTICES.md`; complete shared license texts are under `licenses/`.
