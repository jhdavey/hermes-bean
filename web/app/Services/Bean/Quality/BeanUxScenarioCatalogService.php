<?php

namespace App\Services\Bean\Quality;

use Illuminate\Support\Facades\File;

class BeanUxScenarioCatalogService
{
    public function catalog(): array
    {
        return [
            'mode' => 'bean-ux-scenario-catalog',
            'generated_at' => now()->toIso8601String(),
            'principle' => 'Scenarios exercise the real Hermes Bean runtime and bean_dashboard tool bridge; no local deterministic router is allowed.',
            'scenarios' => [
                $this->scenario('read_overdue_tasks', 'task_success', 'Do I have any overdue tasks?', 'safe_read_only', ['task.list'], ['answer_grounded_in_tool_result', 'mentions_count_or_no_items', 'latency_dashboard_p95_under_12s']),
                $this->scenario('read_today_tasks', 'task_success', 'What tasks do I have today?', 'safe_read_only', ['task.list'], ['date_scope_uses_client_timezone', 'answer_grounded_in_tool_result']),
                $this->scenario('create_task', 'task_success', 'Add a task called benchmark clean kitchen for tomorrow.', 'mutation_requires_cleanup', ['task.create'], ['created_item_visible', 'confirmation_not_required']),
                $this->scenario('complete_that_task_followup', 'continuity', 'Complete that task.', 'mutation_requires_seed_or_prior_turn', ['task.complete'], ['resolves_that_task', 'mutation_verified_by_tool_result']),
                $this->scenario('shared_workspace_query', 'task_success', 'What is in our shared workspace?', 'safe_read_only', ['resource.query'], ['workspace_scope_respected', 'workspace_name_mentioned']),
                $this->scenario('destructive_confirmation', 'task_success', 'Delete the benchmark test task.', 'mutation_requires_confirmation_and_cleanup', ['task.delete'], ['requires_confirmation', 'no_delete_before_approval']),
                $this->scenario('source_lookup_save_note', 'multi_step', 'Find a simple smoked trout dip recipe and save it to a note.', 'mutation_requires_cleanup', ['external.lookup', 'note.create'], ['source_backed_content', 'note_not_empty', 'tool_count_at_least_2']),
                $this->scenario('followup_note_edit', 'continuity', 'Add ingredient portions to that note.', 'mutation_requires_seed_or_prior_turn', ['note.update'], ['resolves_that_note', 'no_unnecessary_clarification']),
                $this->scenario('voice_wake_first_command', 'voice', 'Wake word, then ask: can you hear me?', 'manual_or_browser_harness', [], ['wake_detected', 'user_transcript_received', 'bean_request_sent', 'bean_response_received']),
                $this->scenario('voice_followup_dashboard', 'voice', 'After Bean answers, ask: do I have any overdue tasks?', 'manual_or_browser_harness', ['task.list'], ['followup_window_opened', 'followup_transcript_received', 'tool_completed']),
                $this->scenario('voice_background_ignored', 'voice', 'Play background speech during/after Bean answer.', 'manual_or_browser_harness', [], ['background_audio_ignored', 'no_background_audio_submitted']),
                $this->scenario('voice_failure_recovery', 'voice', 'Force a runtime failure, speak generic failure, return to wake-only.', 'harness_required', [], ['bean_response_received_failed', 'failure_wake_reset', 'voice_session_closed']),
            ],
        ];
    }

    public function write(array $catalog, string $jsonPath, ?string $markdownPath = null): void
    {
        File::ensureDirectoryExists(dirname($jsonPath));
        File::put($jsonPath, json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($markdownPath) {
            File::ensureDirectoryExists(dirname($markdownPath));
            File::put($markdownPath, $this->markdown($catalog));
        }
    }

    public function markdown(array $catalog): string
    {
        $lines = [
            '# Bean UX Evaluation Scenario Catalog',
            '',
            '- Generated: '.($catalog['generated_at'] ?? now()->toIso8601String()),
            '- Principle: '.($catalog['principle'] ?? ''),
            '',
        ];
        foreach ($catalog['scenarios'] ?? [] as $scenario) {
            $lines[] = '## '.$scenario['id'];
            $lines[] = '';
            $lines[] = '- Benchmark: '.$scenario['benchmark'];
            $lines[] = '- Prompt: `'.$scenario['prompt'].'`';
            $lines[] = '- Execution mode: '.$scenario['execution_mode'];
            $lines[] = '- Expected tools: '.implode(', ', $scenario['expected_tools']);
            $lines[] = '- Success signals: '.implode(', ', $scenario['success_signals']);
            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    private function scenario(string $id, string $benchmark, string $prompt, string $executionMode, array $expectedTools, array $successSignals): array
    {
        return [
            'id' => $id,
            'benchmark' => $benchmark,
            'prompt' => $prompt,
            'execution_mode' => $executionMode,
            'expected_tools' => $expectedTools,
            'success_signals' => $successSignals,
        ];
    }
}
