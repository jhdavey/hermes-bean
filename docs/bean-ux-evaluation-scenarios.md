# Bean UX Evaluation Scenario Catalog

- Generated: 2026-07-22T03:18:04+00:00
- Principle: Scenarios exercise the real Hermes Bean runtime and bean_dashboard tool bridge; no local deterministic router is allowed.

## read_overdue_tasks

- Benchmark: task_success
- Prompt: `Do I have any overdue tasks?`
- Execution mode: safe_read_only
- Expected tools: task.list
- Success signals: answer_grounded_in_tool_result, mentions_count_or_no_items, latency_dashboard_p95_under_12s

## read_today_tasks

- Benchmark: task_success
- Prompt: `What tasks do I have today?`
- Execution mode: safe_read_only
- Expected tools: task.list
- Success signals: date_scope_uses_client_timezone, answer_grounded_in_tool_result

## create_task

- Benchmark: task_success
- Prompt: `Add a task called benchmark clean kitchen for tomorrow.`
- Execution mode: mutation_requires_cleanup
- Expected tools: task.create
- Success signals: created_item_visible, confirmation_not_required

## complete_that_task_followup

- Benchmark: continuity
- Prompt: `Complete that task.`
- Execution mode: mutation_requires_seed_or_prior_turn
- Expected tools: task.complete
- Success signals: resolves_that_task, mutation_verified_by_tool_result

## shared_workspace_query

- Benchmark: task_success
- Prompt: `What is in our shared workspace?`
- Execution mode: safe_read_only
- Expected tools: resource.query
- Success signals: workspace_scope_respected, workspace_name_mentioned

## destructive_confirmation

- Benchmark: task_success
- Prompt: `Delete the benchmark test task.`
- Execution mode: mutation_requires_confirmation_and_cleanup
- Expected tools: task.delete
- Success signals: requires_confirmation, no_delete_before_approval

## source_lookup_save_note

- Benchmark: multi_step
- Prompt: `Find a simple smoked trout dip recipe and save it to a note.`
- Execution mode: mutation_requires_cleanup
- Expected tools: external.lookup, note.create
- Success signals: source_backed_content, note_not_empty, tool_count_at_least_2

## followup_note_edit

- Benchmark: continuity
- Prompt: `Add ingredient portions to that note.`
- Execution mode: mutation_requires_seed_or_prior_turn
- Expected tools: note.update
- Success signals: resolves_that_note, no_unnecessary_clarification

## voice_wake_first_command

- Benchmark: voice
- Prompt: `Wake word, then ask: can you hear me?`
- Execution mode: manual_or_browser_harness
- Expected tools: 
- Success signals: wake_detected, voice_session_started, user_transcript_received, thinking_visible, bean_request_sent, bean_response_received, wake_to_listening_p95_under_1s, speech_to_thinking_p95_under_500ms

## voice_followup_dashboard

- Benchmark: voice
- Prompt: `After Bean answers, ask: do I have any overdue tasks?`
- Execution mode: manual_or_browser_harness
- Expected tools: task.list
- Success signals: assistant_speech_finished, followup_window_opened, followup_transcript_received, tool_completed, followup_open_after_speech_p95_under_700ms

## voice_background_ignored

- Benchmark: voice
- Prompt: `Play background speech during/after Bean answer.`
- Execution mode: manual_or_browser_harness
- Expected tools: 
- Success signals: background_audio_ignored, no_background_audio_submitted

## voice_failure_recovery

- Benchmark: voice
- Prompt: `Force a runtime failure, speak generic failure, return to wake-only.`
- Execution mode: harness_required
- Expected tools: 
- Success signals: bean_response_received_failed, failure_wake_reset, voice_session_closed

