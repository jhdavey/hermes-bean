# Bean Voice Live Sample Harness

This is the repeatable manual/browser harness for collecting the real voice samples required by the Bean world-class UX scorecard. It does not require test credentials or destructive data; it records normal authenticated Bean voice lifecycle telemetry through `/api/bean/voice-events`.

## Preconditions

- Use production `https://heybean.org` unless explicitly testing staging/local.
- Sign in with the real account you want to evaluate.
- Use a quiet room for the first two passes; use deliberate background speech only in the background-audio scenario.
- Keep Bean's privacy/listening control enabled for wake-word testing.
- After the samples, run:

```bash
cd /home/forge/heybean.org/current/web
php artisan bean:ux-benchmark --days=7
php artisan bean:ux-evaluate-scenarios --recent=500
```

## Sample set

Collect at least **10 full voice sessions**. The minimum useful set is below; more is better.

### 1. Wake → first command capture

Repeat 3 times:

1. Say: `Hey Bean, can you hear me?`
2. Wait for Bean to answer.
3. Expected telemetry:
   - `wake_detected`
   - `voice_session_started`
   - `user_transcript_received`
   - `thinking_visible`
   - `bean_request_sent`
   - `bean_response_received`
   - `assistant_speech_started`
   - `assistant_speech_finished`

### 2. Dashboard query after wake

Repeat 2 times:

1. Say: `Hey Bean, do I have any overdue tasks?`
2. Expected UX:
   - Bean answers from dashboard/task results.
   - No generic internal failure.
   - If there are no overdue tasks, Bean says that directly.
3. Expected telemetry adds dashboard run/tool completion and speech latency.

### 3. Follow-up capture after Bean speaks

Repeat 3 times:

1. Ask a dashboard question, e.g. `Hey Bean, what tasks do I have today?`
2. After Bean finishes speaking and the follow-up window opens, say: `What about tomorrow?`
3. Expected UX:
   - Bean treats this as a task/date follow-up, not as a generic time/date question.
   - Bean sends a second request.
4. Expected telemetry:
   - `assistant_speech_finished`
   - `followup_window_opened`
   - `followup_transcript_received`
   - second `bean_request_sent`
   - second `bean_response_received`

### 4. Background speech ignored

Repeat 1–2 times:

1. Trigger Bean with a harmless query.
2. While Bean is speaking or immediately after, play or say unrelated background speech that does **not** start with the wake phrase.
3. Expected UX:
   - Background speech is not submitted as a Bean message.
4. Expected telemetry:
   - `background_audio_ignored`
   - no `background_audio_submitted`

### 5. Assistant echo ignored

Repeat 1–2 times:

1. Let Bean answer out loud near the microphone.
2. Do not speak over it.
3. Expected UX:
   - Bean does not submit its own spoken answer as a new user command.
4. Expected telemetry:
   - `assistant_echo_ignored`
   - no `assistant_echo_submitted` or `duplicate_submission`

### 6. Stop/dismiss handling

Repeat 1–2 times:

1. Wake Bean.
2. Say: `stop` or `dismiss`.
3. Expected UX:
   - Bean closes the active voice turn and returns to wake-word-only mode.
4. Expected telemetry:
   - `dismiss_command_detected`
   - `dismiss_closed`
   - `voice_session_closed`

## Pass criteria after samples

The scorecard should move voice targets from `unknown` to measured values:

- first-command capture ≥ 98%
- follow-up capture ≥ 95%
- background false submissions ≤ 1%
- echo/duplicate submissions ≤ 1%
- wake → listening p95 ≤ 1000ms
- speech → visible thinking p95 ≤ 500ms
- speech → spoken answer p95 ≤ 7000ms
- speech finished → follow-up open p95 ≤ 700ms

Do not claim Bean is world-class until these are measured from live samples and pass.
