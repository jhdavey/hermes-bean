# Hey Bean local wake-word assets

This directory is a self-contained, same-origin wake-word boundary. The raw microphone enters the `hey-bean-gate` AudioWorklet first. The worklet's audio output is always bit-exact zero; its 16 kHz mono analysis PCM stays in a bounded, memory-only local ring and is sent only to the dedicated keyword worker until local activation succeeds.

## Runtime contract

- Load `/voice/wake/gate-processor.js` with `audioContext.audioWorklet.addModule()`, then construct an `AudioWorkletNode` with processor name `hey-bean-gate`.
- Construct a classic worker from the versioned manifest URL and append `generation=<non-negative integer>` as an additional query parameter. The worker initializes automatically. Its `ready` message explicitly proves model load, warm decode, and recognition-stream creation; do not expose wake readiness until the matching AudioWorklet `processor_ready`, silent analysis sink, and accepted live-decode barriers have also completed.
- The worklet accepts `{type: "activate"|"close"|"destroy", generation}`. It posts `processor_ready` after a matching closed-generation control, transferable `{type: "audio", samples: Float32Array, sequence, generation}` chunks, normalized `{type: "activity", level, rms, generation}` messages, and fail-closed errors. Its rendered audio output remains zero in every state.
- `LocalWakeGate` retains at most eight seconds of ordered 16 kHz PCM in memory. It copies bounded chunks to the worker as `{type: "audio", samples, sequence, sourceSequence, generation}`. The worker posts `ready`, `ack`, sanitized `classification_decision`, `utterance_started`, `address_candidate`, `address_rejected`, `wake_confirmed`, `dormant_discard`, and `error`. A confirmed wake contains an exact source-sequence/sample-offset release boundary, never transcript text or audio.
- On strict `HEY BEAN`, only a 120 ms post-address tail and later command audio are released. This permits a strict wake inside continuous speech without sending the earlier utterance. Confirmed missed-Hey recovery releases from the locally established utterance onset. Third-person and ambiguous continuations are rejected without transcript or application events. Every rejection rotates the generation and erases retained PCM before a later wake.
- Provider WebRTC audio is receive-only. After confirmation the browser first sends `input_audio_buffer.clear`, deterministically resamples only the safe-boundary audio to mono 24 kHz PCM16 little-endian, then sends ordered `input_audio_buffer.append` events. There is no provider-facing microphone `MediaStream`, dormant zero stream, or real-time delay line.
- Treat every worklet or worker `error`, generation mismatch, missing acknowledgement, queue overflow, or unexpected termination as fail-closed: close the gate synchronously and fall back to an explicit user gesture.
- Bound the main-thread bridge as well. Keep no more than a small fixed number of unacknowledged worker chunks; never build an unbounded audio queue.
- Increment the generation and reset the worker at each activation boundary. Ignore messages from older generations. On teardown, close the gate before asynchronous cleanup, send `destroy` to the worklet and `close` to the worker, terminate the worker, stop the raw microphone track, and close the audio context.

The worker's repository-trained `bean-first-party-wake-v1` temporal classifier owns strict-wake acceptance, missed-Hey address acceptance, and rejection. A bundled open-source KWS model supplies only strict `Hey Bean` candidate timestamps and the safe post-address release boundary; it has no final decision authority and no address grammar. The worker never sends decoded candidate or command text to the main thread. Confirmed activation includes only the matched alias, activation kind, source sequence, and safe release boundary. Representative physical-microphone/browser evidence remains required before global production sign-off, so `manifest.json` records `releaseCertified: false` even though `wakeModelQaCertified` is true.

## Privacy and deployment properties

The Wasm runtime is single-threaded. Its module declares unshared memory and does not use `SharedArrayBuffer`, so this directory does not require COOP/COEP or cross-origin isolation. It does require same-origin Worker, AudioWorklet, and Wasm permissions in the application's Content Security Policy. Do not attach the raw microphone track—or any derived microphone track—to the provider peer connection. WebRTC remains receive-only; activated input crosses only the bounded data-channel PCM adapter.

`kws-model.data` preloads the int8 encoder, FP32 decoder, int8 joiner, and token table used only for strict timing candidates. `bean-wake-model-v1.json` contains Bean's first-party temporal classifier, normalization values, and thresholds. This mixed arrangement preserves a timestamped release boundary while keeping acceptance under Bean-owned weights. The superseded general ASR runtime/model and text diagnostic were deleted after the first-party gate passed.

## Provenance and build recipe

- Inference source: [k2-fsa/sherpa-onnx](https://github.com/k2-fsa/sherpa-onnx) commit `d7526c835a5a70b9a936100dfc39e527a49893b6` (2026-03-18), the last selected revision before the later browser pthread runtime.
- Timing-candidate model: `sherpa-onnx-kws-zipformer-zh-en-3M-2025-12-20`, published by `pkufool` for sherpa-onnx and marked Apache-2.0.
- Bean classifier: generated by repository script `scripts/voice/train_bean_wake_model.py` from local macOS voices, candidate-aligned prefix/trailing-window variants, gain/speed changes, procedural noise, music-like interference, and echo. Training audio remains under ignored local storage and is never uploaded.
- Toolchain: Emscripten `3.1.53`, CMake Release build, single-thread keyword-spotting target.
- Linker customization: `INITIAL_MEMORY=256MB`, `ALLOW_MEMORY_GROWTH=1`, `ENVIRONMENT=web,worker`, `MODULARIZE=1`, and `EXPORT_NAME=createSherpaKwsModule`. No pthread flag is present.
- Model packaging: int8 encoder, FP32 decoder, and int8 joiner.
- The final post-cleanup local Chromium replay passed 101/102 Bean Voice QA journeys (99.02% against a 95% requirement), with 23/24 isolated strict wakes, 100% ongoing-speech strict wake, 100% missed-Hey address recall, 0/42 false activations, 6/6 reset recoveries, wake p95 482.4 ms over 23 successful timing samples, zero pre-confirmation PCM, complete activated-PCM handoff, and no runtime error. This is deterministic prerecorded evidence, not representative physical-microphone release certification.
- Stable package names are `kws-runtime.js`, `kws-runtime.wasm`, `kws-model.data`, and `kws-api.js`. The worker's `locateFile` callback maps those same-origin assets at runtime.

The four vendor timing-candidate files are byte-for-byte copies of the validated build outputs. Their source and destination SHA-256 values matched when packaged. `manifest.json` records their byte counts and hashes; `SHA256SUMS` covers every distributed file in this directory. Verify from this directory with:

```sh
shasum -a 256 -c SHA256SUMS
```

Third-party terms and source links are in `THIRD_PARTY_NOTICES.md`; complete shared license texts are under `licenses/`.
