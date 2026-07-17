# Bean Local Wake Detection Evaluation

## Decision

Bean now exposes an explicit **Off / On** privacy control instead of a tap-to-talk control:

- **Off** = privacy mode. No wake detector, no microphone session, no OpenAI Realtime stream.
- **On** = local wake-listening mode. The browser listens locally for “Hey Bean”; only after wake detection does Bean open the OpenAI Realtime voice session.

Do not stream idle microphone audio to OpenAI Realtime or any remote speech recognizer just to detect `Hey Bean`.

## Current web implementation

`web/resources/js/heybean/webApp.js` supports two local wake detector paths, in priority order:

1. `window.HeyBeanLocalWakeDetector` — preferred production seam for a bundled local/WASM wake-word engine.
2. Browser local speech recognition **only when verified local processing is available**:
   - Uses `window.SpeechRecognition` only when `SpeechRecognition.available({ processLocally: true })` reports `available`.
   - Sets `recognition.processLocally = true`.
   - Does **not** use `webkitSpeechRecognition`, because that path does not provide the same local-processing check.

If no verified local detector is available, the UI stays honest with an “On — local wake unavailable” state instead of offering tap-to-talk.

On local wake detection, Bean opens the Realtime voice path; Laravel still mints the Realtime client secret and remains the source of truth for actions. Privacy mode stops the wake detector and any active voice session.

## Candidate engines

- A bundled WASM wake-word detector with local model assets is preferred.
- Picovoice/Porcupine-style browser wake detection may be acceptable only if the detector runs locally and any access-key/licensing requirement is handled explicitly.
- Browser local speech recognition is acceptable only when the browser exposes and confirms `processLocally: true` support.

## Acceptance criteria

1. The Bean UI has only Off/On privacy controls; no tap-to-talk option.
2. Wake model/listener makes no remote network request during idle wake listening.
3. Privacy mode releases microphone resources and stops the detector.
4. Wake detection opens the same Realtime voice path after “Hey Bean”.
5. Realtime client-secret responses are normalized for both top-level `value` and nested `client_secret.value` response shapes.
