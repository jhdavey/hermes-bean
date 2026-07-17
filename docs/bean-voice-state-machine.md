# Bean Voice State Machine

`privacy -> wake_listening -> listening -> thinking -> working -> speaking -> followup_window -> wake_listening`

## Privacy rule

Privacy mode must stop microphone capture and network audio streaming.

## Wake rule

Wake detection should be local in web/native clients. Realtime streaming begins only after local detection of `Hey Bean` or explicit user action. This avoids paying input tokens for idle wake listening and is a clearer privacy model.

For the browser MVP, do not treat the Web Speech API / `webkitSpeechRecognition` as a strict local wake detector, because browsers may route recognition through vendor services. Use a true local/WASM wake-word engine, or start with tap-to-talk/click-to-wake until local wake is verified.

## Follow-up

After Bean speaks, keep a short follow-up window where the user can continue without repeating the wake phrase. When it expires, return to local wake-listening mode if enabled.

## Cancellation

Local stop/cancel phrases or UI controls should immediately end the current turn and return to privacy or wake-listening mode.
