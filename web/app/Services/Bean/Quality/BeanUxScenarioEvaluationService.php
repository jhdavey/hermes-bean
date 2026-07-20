<?php

namespace App\Services\Bean\Quality;

use App\Models\BeanRun;
use App\Models\BeanToolCall;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class BeanUxScenarioEvaluationService
{
    public function __construct(
        private readonly BeanUxScenarioCatalogService $catalog,
        private readonly BeanQualityAuditService $audit,
    ) {}

    public function report(int $recent = 500): array
    {
        $recent = max(1, min($recent, 2000));
        $runs = BeanRun::query()
            ->with('toolCalls')
            ->whereNotNull('completed_at')
            ->latest('id')
            ->limit($recent)
            ->get()
            ->sortBy('id')
            ->values();
        $traces = $runs->map(fn (BeanRun $run) => $this->audit->traceRun($run)->setRelation('run', $run->refresh()->load('toolCalls')));
        $scenarioReports = collect($this->catalog->catalog()['scenarios'] ?? [])
            ->mapWithKeys(fn (array $scenario): array => [$scenario['id'] => $this->evaluateScenario($scenario, $traces)])
            ->all();
        $semanticMetrics = $this->semanticMetrics($scenarioReports);

        return [
            'mode' => 'bean-ux-scenario-evaluation',
            'generated_at' => now()->toIso8601String(),
            'recent_runs' => $recent,
            'trace_count' => $traces->count(),
            'scenarios' => $scenarioReports,
            'semantic_metrics' => $semanticMetrics,
            'target_status' => $this->targetStatus($semanticMetrics),
            'voice_harness_status' => $this->voiceHarnessStatus($scenarioReports),
            'guidance' => 'Recorded trace evaluator: pass/fail is based on real Bean/Hermes run traces. Voice scenarios remain unknown until live/browser samples create voice telemetry.',
        ];
    }

    public function writeReport(array $report, string $jsonPath, ?string $markdownPath = null): void
    {
        File::ensureDirectoryExists(dirname($jsonPath));
        File::put($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($markdownPath) {
            File::ensureDirectoryExists(dirname($markdownPath));
            File::put($markdownPath, $this->markdown($report));
        }
    }

    public function markdown(array $report): string
    {
        $lines = [
            '# Bean UX Scenario Evaluation Report',
            '',
            '- Generated: '.($report['generated_at'] ?? now()->toIso8601String()),
            '- Recent runs scanned: '.($report['recent_runs'] ?? 0),
            '- Traces evaluated: '.($report['trace_count'] ?? 0),
            '',
            '## Semantic metrics',
            '- Follow-up/reference resolution: '.$this->percentString(data_get($report, 'semantic_metrics.followup_reference_resolution_rate')),
            '- Unnecessary clarification rate: '.$this->percentString(data_get($report, 'semantic_metrics.unnecessary_clarification_rate')),
            '- Immediate context-loss rate: '.$this->percentString(data_get($report, 'semantic_metrics.immediate_context_loss_rate')),
            '',
            '## Scenario status',
        ];
        foreach (($report['scenarios'] ?? []) as $id => $scenario) {
            $lines[] = '- '.$id.': '.($scenario['status'] ?? 'unknown').' (samples '.($scenario['sample_count'] ?? 0).', pass rate '.$this->percentString($scenario['pass_rate'] ?? null).')';
            foreach (($scenario['failing_samples'] ?? []) as $sample) {
                $lines[] = '  - failed run #'.($sample['bean_run_id'] ?? '?').': '.implode(', ', $sample['failed_signals'] ?? []);
            }
        }
        $lines[] = '';
        $lines[] = '## Voice harness status';
        foreach ((array) ($report['voice_harness_status'] ?? []) as $key => $value) {
            $lines[] = '- '.$key.': '.(is_array($value) ? implode(', ', $value) : (string) $value);
        }

        return implode("\n", $lines)."\n";
    }

    private function evaluateScenario(array $scenario, Collection $traces): array
    {
        $candidates = $traces
            ->filter(fn ($trace): bool => $this->matchesScenario($scenario, $trace))
            ->values();
        if ($candidates->isEmpty()) {
            return [
                'status' => 'unknown',
                'sample_count' => 0,
                'pass_count' => 0,
                'pass_rate' => null,
                'expected_tools' => $scenario['expected_tools'] ?? [],
                'success_signals' => $scenario['success_signals'] ?? [],
                'signal_pass_rates' => [],
                'failing_samples' => [],
            ];
        }

        $evaluated = $candidates->map(fn ($trace): array => $this->evaluateTrace($scenario, $trace));
        $passCount = $evaluated->where('passed', true)->count();
        $signalPassRates = collect($scenario['success_signals'] ?? [])
            ->mapWithKeys(function (string $signal) use ($evaluated): array {
                $applicable = $evaluated->filter(fn (array $sample): bool => array_key_exists($signal, $sample['signals']));
                if ($applicable->isEmpty()) {
                    return [$signal => null];
                }

                return [$signal => round($applicable->filter(fn (array $sample): bool => (bool) $sample['signals'][$signal])->count() / $applicable->count(), 4)];
            })
            ->all();
        $passRate = round($passCount / $evaluated->count(), 4);

        return [
            'status' => $passRate >= 0.95 ? 'pass' : 'fail',
            'sample_count' => $evaluated->count(),
            'pass_count' => $passCount,
            'pass_rate' => $passRate,
            'expected_tools' => $scenario['expected_tools'] ?? [],
            'success_signals' => $scenario['success_signals'] ?? [],
            'signal_pass_rates' => $signalPassRates,
            'failing_samples' => $evaluated
                ->filter(fn (array $sample): bool => ! $sample['passed'])
                ->take(10)
                ->values()
                ->all(),
        ];
    }

    private function evaluateTrace(array $scenario, $trace): array
    {
        $signals = collect($scenario['success_signals'] ?? [])
            ->mapWithKeys(fn (string $signal): array => [$signal => $this->signalPasses($signal, $scenario, $trace)])
            ->all();
        $failedSignals = collect($signals)
            ->filter(fn (bool $passes): bool => ! $passes)
            ->keys()
            ->values()
            ->all();

        return [
            'bean_run_id' => $trace->bean_run_id,
            'passed' => $failedSignals === [],
            'signals' => $signals,
            'failed_signals' => $failedSignals,
            'input' => mb_substr((string) $trace->user_message, 0, 180),
            'answer' => mb_substr((string) $trace->assistant_answer, 0, 180),
        ];
    }

    private function signalPasses(string $signal, array $scenario, $trace): bool
    {
        $run = $trace->run;
        $toolCalls = $run?->toolCalls instanceof Collection ? $run->toolCalls : collect();
        $answer = mb_strtolower((string) $trace->assistant_answer);
        $flags = $trace->quality_flags ?? [];

        return match ($signal) {
            'answer_grounded_in_tool_result' => $this->hasExpectedTools($scenario, $toolCalls),
            'mentions_count_or_no_items' => $this->mentionsCountOrNoItems($answer, $toolCalls),
            'latency_dashboard_p95_under_12s' => ($trace->latency_ms ?? PHP_INT_MAX) <= 12000,
            'date_scope_uses_client_timezone' => $trace->time_label === 'today' || $this->toolResultHasTimeLabel($toolCalls, 'today'),
            'created_item_visible' => $this->createdItemVisible($toolCalls),
            'confirmation_not_required' => ! $toolCalls->contains(fn (BeanToolCall $call): bool => (bool) $call->requires_confirmation || $call->status === 'waiting_confirmation'),
            'resolves_that_task', 'resolves_that_note' => $this->hasExpectedTools($scenario, $toolCalls) && ! $this->isClarifyingAnswer($answer),
            'mutation_verified_by_tool_result' => $toolCalls->where('status', 'completed')->contains(fn (BeanToolCall $call): bool => (bool) data_get($call->result, 'ok', true)),
            'workspace_scope_respected' => $this->workspaceNamesFromResults($toolCalls) !== [] || $this->hasExpectedTools($scenario, $toolCalls),
            'workspace_name_mentioned' => $this->workspaceNameMentioned($answer, $toolCalls),
            'requires_confirmation' => $toolCalls->contains(fn (BeanToolCall $call): bool => (bool) $call->requires_confirmation || $call->status === 'waiting_confirmation'),
            'no_delete_before_approval' => ! $toolCalls->contains(fn (BeanToolCall $call): bool => str_contains((string) $call->action, 'delete') && $call->status === 'completed' && ! $call->requires_confirmation),
            'source_backed_content' => $this->externalLookupHasSources($toolCalls),
            'note_not_empty' => $this->noteCreateHasBody($toolCalls),
            'tool_count_at_least_2' => $toolCalls->count() >= 2,
            'no_unnecessary_clarification' => ! $this->isClarifyingAnswer($answer) && ! in_array('unnecessary_clarification', $flags, true),
            default => true,
        };
    }

    private function matchesScenario(array $scenario, $trace): bool
    {
        $id = (string) ($scenario['id'] ?? '');
        $input = mb_strtolower((string) $trace->user_message);
        $actions = collect($trace->actions ?? [])->map(fn ($action): string => (string) $action)->all();

        return match ($id) {
            'read_overdue_tasks' => str_contains($input, 'overdue') && in_array('task.list', $actions, true),
            'read_today_tasks' => str_contains($input, 'today') && str_contains($input, 'task') && in_array('task.list', $actions, true),
            'create_task' => str_contains($input, 'add') && str_contains($input, 'task') && in_array('task.create', $actions, true),
            'complete_that_task_followup' => str_contains($input, 'that task') && in_array('task.complete', $actions, true),
            'shared_workspace_query' => str_contains($input, 'workspace') && in_array('resource.query', $actions, true),
            'destructive_confirmation' => str_contains($input, 'delete') && collect($actions)->contains(fn (string $action): bool => str_contains($action, 'delete')),
            'source_lookup_save_note' => in_array('external.lookup', $actions, true) && in_array('note.create', $actions, true),
            'followup_note_edit' => str_contains($input, 'that note') || in_array('note.update', $actions, true),
            default => false,
        };
    }

    private function semanticMetrics(array $scenarioReports): array
    {
        $continuityIds = ['complete_that_task_followup', 'followup_note_edit'];
        $continuitySamples = collect($continuityIds)->sum(fn (string $id): int => (int) data_get($scenarioReports, $id.'.sample_count', 0));
        $continuityPasses = collect($continuityIds)->sum(fn (string $id): int => (int) data_get($scenarioReports, $id.'.pass_count', 0));
        $assessedSamples = collect($scenarioReports)->sum(fn (array $scenario): int => (int) ($scenario['sample_count'] ?? 0));
        $clarifications = collect($scenarioReports)
            ->flatMap(fn (array $scenario): array => $scenario['failing_samples'] ?? [])
            ->filter(fn (array $sample): bool => preg_match('/\b(which|what).*\b(note|task|one)\b|clarify|mean\?/iu', (string) ($sample['answer'] ?? '')) === 1)
            ->count();
        $contextLosses = collect($scenarioReports)
            ->flatMap(fn (array $scenario): array => $scenario['failing_samples'] ?? [])
            ->filter(fn (array $sample): bool => preg_match('/\b(that|it|those|them)\b/iu', (string) ($sample['input'] ?? '')) === 1)
            ->count();

        return [
            'followup_reference_resolution_rate' => $continuitySamples > 0 ? round($continuityPasses / $continuitySamples, 4) : null,
            'unnecessary_clarification_rate' => $assessedSamples > 0 ? round($clarifications / $assessedSamples, 4) : null,
            'immediate_context_loss_rate' => $assessedSamples > 0 ? round($contextLosses / $assessedSamples, 4) : null,
            'assessed_scenario_samples' => $assessedSamples,
            'continuity_samples' => $continuitySamples,
        ];
    }

    private function targetStatus(array $metrics): array
    {
        $targets = [
            'followup_reference_resolution_rate' => ['operator' => '>=', 'value' => 0.95],
            'unnecessary_clarification_rate' => ['operator' => '<=', 'value' => 0.03],
            'immediate_context_loss_rate' => ['operator' => '<=', 'value' => 0.02],
        ];
        $status = [];
        foreach ($targets as $key => $target) {
            $actual = $metrics[$key] ?? null;
            $ok = $actual === null ? null : (($target['operator'] === '>=') ? $actual >= $target['value'] : $actual <= $target['value']);
            $status[$key] = [
                'status' => $ok === null ? 'unknown' : ($ok ? 'pass' : 'fail'),
                'actual' => $actual,
                'target' => $target['operator'].' '.$target['value'],
            ];
        }

        return $status;
    }

    private function voiceHarnessStatus(array $scenarioReports): array
    {
        $voice = collect($scenarioReports)
            ->filter(fn (array $scenario, string $id): bool => str_starts_with($id, 'voice_'));

        return [
            'status' => $voice->every(fn (array $scenario): bool => ($scenario['status'] ?? 'unknown') === 'unknown') ? 'awaiting_live_samples' : 'has_samples',
            'voice_scenarios' => $voice->keys()->values()->all(),
            'next_step' => 'Run the manual/browser voice sample checklist, then rerun php artisan bean:ux-benchmark --days=7 and php artisan bean:ux-evaluate-scenarios --recent=500.',
        ];
    }

    private function hasExpectedTools(array $scenario, Collection $toolCalls): bool
    {
        $actions = $toolCalls->pluck('action')->all();

        return collect($scenario['expected_tools'] ?? [])->every(fn (string $expected): bool => in_array($expected, $actions, true));
    }

    private function mentionsCountOrNoItems(string $answer, Collection $toolCalls): bool
    {
        if (preg_match('/\b\d+\b/', $answer) === 1 || preg_match('/\b(no|none|nothing|don[’\']?t have any)\b/u', $answer) === 1) {
            return true;
        }

        return $toolCalls->contains(function (BeanToolCall $call): bool {
            $result = is_array($call->result) ? $call->result : [];

            return (int) ($result['total_count'] ?? $result['returned_count'] ?? count($result['items'] ?? [])) === 0;
        });
    }

    private function toolResultHasTimeLabel(Collection $toolCalls, string $timeLabel): bool
    {
        return $toolCalls->contains(function (BeanToolCall $call) use ($timeLabel): bool {
            $arguments = is_array($call->arguments) ? $call->arguments : [];
            $result = is_array($call->result) ? $call->result : [];

            return strtolower((string) ($arguments['time_label'] ?? $result['time_label'] ?? '')) === $timeLabel;
        });
    }

    private function createdItemVisible(Collection $toolCalls): bool
    {
        return $toolCalls->contains(function (BeanToolCall $call): bool {
            if ($call->status !== 'completed') {
                return false;
            }
            $result = is_array($call->result) ? $call->result : [];
            $item = is_array($result['item'] ?? null) ? $result['item'] : [];
            $items = is_array($result['items'] ?? null) ? $result['items'] : [];

            return trim((string) ($item['title'] ?? $item['name'] ?? '')) !== '' || $items !== [] || (bool) ($result['ok'] ?? false);
        });
    }

    private function isGenericNoFactAnswer(string $answer): bool
    {
        return preg_match('/^\s*(done|all done|i[’\']?ll .* done)\.?\s*$/iu', $answer) === 1;
    }

    private function isClarifyingAnswer(string $answer): bool
    {
        return preg_match('/\b(which one|which .*\?|what .*\?|can you clarify|please clarify|did you mean|what do you mean|which note|which task)\b/iu', $answer) === 1;
    }

    private function workspaceNameMentioned(string $answer, Collection $toolCalls): bool
    {
        $workspaceNames = $this->workspaceNamesFromResults($toolCalls);
        if ($workspaceNames === []) {
            return true;
        }

        return collect($workspaceNames)->contains(fn (string $name): bool => str_contains($answer, mb_strtolower($name)));
    }

    private function workspaceNamesFromResults(Collection $toolCalls): array
    {
        return $toolCalls
            ->flatMap(function (BeanToolCall $toolCall): array {
                $result = is_array($toolCall->result) ? $toolCall->result : [];
                $items = is_array($result['items'] ?? null) ? $result['items'] : (is_array($result['item'] ?? null) ? [$result['item']] : []);

                return collect($items)
                    ->filter(fn ($item): bool => is_array($item))
                    ->flatMap(fn (array $item): array => is_array($item['workspace_names'] ?? null) ? $item['workspace_names'] : [])
                    ->all();
            })
            ->map(fn ($name): string => trim((string) $name))
            ->filter()
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->values()
            ->all();
    }

    private function externalLookupHasSources(Collection $toolCalls): bool
    {
        return $toolCalls->contains(function (BeanToolCall $toolCall): bool {
            if ($toolCall->action !== 'external.lookup' || $toolCall->status !== 'completed') {
                return false;
            }
            $sources = is_array(data_get($toolCall->result, 'sources')) ? data_get($toolCall->result, 'sources') : [];

            return collect($sources)->contains(fn ($source): bool => is_array($source) && trim((string) ($source['url'] ?? $source['title'] ?? $source['snippet'] ?? '')) !== '');
        });
    }

    private function noteCreateHasBody(Collection $toolCalls): bool
    {
        return $toolCalls->contains(function (BeanToolCall $toolCall): bool {
            if ($toolCall->action !== 'note.create' || $toolCall->status !== 'completed') {
                return false;
            }
            $arguments = is_array($toolCall->arguments) ? $toolCall->arguments : [];
            $result = is_array($toolCall->result) ? $toolCall->result : [];
            $body = collect([
                $arguments['body'] ?? null,
                $arguments['content'] ?? null,
                $arguments['plain_text'] ?? null,
                data_get($result, 'item.body'),
                data_get($result, 'item.content'),
                data_get($result, 'item.plain_text'),
            ])
                ->map(fn ($value): string => trim((string) $value))
                ->first(fn (string $value): bool => $value !== '') ?? '';

            return mb_strlen($body) >= 40;
        });
    }

    private function percentString(?float $value): string
    {
        return $value === null ? 'unknown' : round($value * 100, 2).'%';
    }
}
