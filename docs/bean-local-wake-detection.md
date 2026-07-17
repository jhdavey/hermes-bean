# Bean Local Wake Detection Evaluation

## Decision

Keep the browser MVP on explicit `Tap to talk` until a verified local/WASM wake-word engine is bundled. Do not use the Web Speech API / `webkitSpeechRecognition` for wake detection because browser speech recognition may use vendor cloud services and is not a reliable privacy boundary.

## Current web implementation

`web/resources/js/heybean/webApp.js` now exposes a safe local wake integration seam:

- The app checks for `window.HeyBeanLocalWakeDetector` only.
- If a local detector is not present, wake-listening mode stays honest: `Tap to talk ready — local wake pending`.
- If a detector is present, it must provide `create({ phrase, onWake })` and return an object with optional `start()` / `stop()` methods.
- On local wake detection, Bean opens the existing Realtime tap-to-talk path; Laravel still mints the Realtime client secret and remains the source of truth for actions.
- Privacy mode stops the wake detector and does not open Realtime.

## Candidate engines

- A bundled WASM wake-word detector with local model assets is preferred.
- Picovoice/Porcupine-style browser wake detection may be acceptable only if the detector runs locally and any access-key/licensing requirement is handled explicitly.
- Do not stream idle microphone audio to OpenAI Realtime or any remote speech recognizer just to detect `Hey Bean`.

## Acceptance criteria before enabling by default

1. Wake model assets load from HeyBean-controlled static assets or an approved package.
2. No remote network request is made during idle wake listening.
3. Privacy mode releases microphone resources and stops the detector.
4. Wake detection opens the same voice path as tap-to-talk.
5. Browser tests verify no `SpeechRecognition`/`webkitSpeechRecognition` wake path is used.
