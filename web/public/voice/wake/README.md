# Hey Bean local wake-word assets

This directory is a self-contained, same-origin wake-word boundary. The raw microphone enters the `hey-bean-gate` AudioWorklet first. Its provider-facing output is exact zero until the application opens the gate for the current generation; 16 kHz mono analysis audio stays local and is sent only to a dedicated streaming-ASR worker.

## Runtime contract

- Load `/voice/wake/gate-processor.js` with `audioContext.audioWorklet.addModule()`, then construct an `AudioWorkletNode` with processor name `hey-bean-gate`.
- Construct a classic worker from the versioned manifest URL and append `generation=<non-negative integer>` as an additional query parameter. The worker initializes automatically. Do not open the gate before its matching `ready` message.
- The worklet accepts `{type: "open"|"close"|"destroy", generation}`. It posts transferable `{type: "audio", samples: Float32Array, sequence, generation}` chunks, a normalized scalar `{type: "activity", level, rms, generation}` every 50 ms for immediate local UI feedback, and `{type: "error", ...}`. Activity messages never contain microphone samples or transcript text.
- The provider output has a 1,200 ms local delay. While closed, that delay line is filled but the output remains bit-exact zero; opening releases the buffered wake-and-command onset so streaming recognition latency cannot discard a short request. The delay is reset at each generation boundary.
- Forward bounded audio chunks to the worker without copying. The worker accepts `{type: "audio", samples, sequence, generation}`, `{type: "reset", generation}`, and `{type: "close", generation}`. It posts `ready`, `ack`, `detected`, and `error` messages with the current generation. A worker `reset` adopts the generation and replies `ready`; it deliberately does not reset the native stream a second time because detection already performed the one required in-place reset before its audio acknowledgement.
- Wake matching stays anchored to the beginning of an utterance, but a completed non-wake utterance is discarded after 700 ms of local silence. This prevents ambient recognition from permanently poisoning every later `Hey Bean` attempt while still rejecting embedded wake-phrase mentions inside ordinary speech.
- Treat every worklet or worker `error`, generation mismatch, missing acknowledgement, queue overflow, or unexpected termination as fail-closed: close the gate synchronously and fall back to an explicit user gesture.
- Bound the main-thread bridge as well. Keep no more than a small fixed number of unacknowledged worker chunks; never build an unbounded audio queue.
- Increment the generation and reset the worker at each activation boundary. Ignore messages from older generations. On teardown, close the gate before any asynchronous cleanup, send `destroy` to the worklet and `close` to the worker, terminate the worker, stop both raw and derived tracks, and close the audio context.

The worker uses unconstrained local streaming ASR, then accepts only an anchored wake prefix. The canonical `HEY BEAN` plus observed acoustic-decoding variants (`THEY BEAN`, `HEY BEING`, `THEY BEING`, `HE BEAN`, `HE BEING`, `HABEEN`, and `HABEING`) map to `HEY_BEAN`; near-miss transcripts such as `HEY BEN`, `HEY BEAM`, embedded `GREEN BEAN`, and ordinary `HAVE BEEN` do not. The worker sends only the matched prefix variant to the main thread, never the locally decoded command text. These variants still require representative device and acoustic-corpus evaluation before production sign-off.

## Privacy and deployment properties

The Wasm runtime is single-threaded. Its module declares unshared memory and does not use `SharedArrayBuffer`, so this directory does not require COOP/COEP or cross-origin isolation. It does require same-origin Worker, AudioWorklet, and Wasm permissions in the application's Content Security Policy. Do not attach the raw microphone track to the provider peer connection; attach only the `MediaStreamDestination` track derived from the gated AudioWorklet output.

`model.data` preloads the int8 encoder, FP32 decoder, int8 joiner, and token table into the Wasm virtual filesystem. This mixed quantization is intentional: it preserves detection behavior while avoiding the all-int8 decoder's severe single-thread browser slowdown. No microphone samples leave the browser through these assets.

## Provenance and build recipe

- Inference source: [k2-fsa/sherpa-onnx](https://github.com/k2-fsa/sherpa-onnx) commit `d7526c835a5a70b9a936100dfc39e527a49893b6` (2026-03-18), the last selected revision before the later browser pthread runtime.
- Model: `sherpa-onnx-kws-zipformer-gigaspeech-3.3M-2024-01-01`, published by `pkufool` for sherpa-onnx and marked Apache-2.0.
- Toolchain: Emscripten `3.1.53`, CMake Release build, `build-wasm-simd-asr.sh`.
- ASR linker customization: `INITIAL_MEMORY=256MB`, `ALLOW_MEMORY_GROWTH=1`, `ENVIRONMENT=web,worker`, `MODULARIZE=1`, and `EXPORT_NAME=createSherpaAsrModule`. No pthread flag is present.
- Model packaging: int8 encoder, FP32 decoder, and int8 joiner.
- In a local, non-isolated Chromium replay, the packaged streaming worker accepted six synthetic `Hey Bean` voices and rejected six near-miss/background phrases; all twelve decisions completed in about 1.5 seconds after cached asset load. This is a deterministic smoke suite, not the representative acoustic evidence required for release sign-off.
- Stable package names map upstream `sherpa-onnx-wasm-main-asr.js/.wasm/.data` to `runtime.js`, `runtime.wasm`, and `model.data`; `sherpa-onnx-asr.js` maps to `asr-api.js`. The worker's `locateFile` callback performs that mapping at runtime.

The four vendor files are byte-for-byte copies of the validated build outputs. Their source and destination SHA-256 values matched when packaged. `manifest.json` records their byte counts and hashes; `SHA256SUMS` covers every other distributed file in this directory. Verify from this directory with:

```sh
shasum -a 256 -c SHA256SUMS
```

Third-party terms and source links are in `THIRD_PARTY_NOTICES.md`; complete shared license texts are under `licenses/`.
