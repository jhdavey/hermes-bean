<?php

namespace App\Services\Bean\Quality;

use App\Models\BeanQualityTrace;
use App\Models\BeanRun;
use App\Models\BeanVoiceEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class BeanUxBenchmarkService
{
    private const FAILURE_TEXT = 'I could not complete that request.';

    public function report(int $days = 7): array
    {
        $days = max(1, min($days, 90));
        $since = now()->subDays($days);
        $runs = BeanRun::query()
            ->with(['toolCalls', 'session'])
            ->whereNotNull('completed_at')
            ->where('created_at', '>=', $since)
            ->get();
        $traces = BeanQualityTrace::query()
            ->where('created_at', '>=', $since)
            ->get();
        $voiceEvents = Schema::hasTable('bean_voice_events')
            ? BeanVoiceEvent::query()
                ->where('occurred_at', '>=', $since)
                ->orWhere(fn ($query) => $query->whereNull('occurred_at')->where('created_at', '>=', $since))
                ->get()
            : collect();

        $dashboardRuns = $runs->filter(fn (BeanRun $run): bool => $this->isDashboardRun($run))->values();
        $multiStepRuns = $runs->filter(fn (BeanRun $run): bool => $run->toolCalls->count() >= 2)->values();
        $voiceRuns = $runs->filter(fn (BeanRun $run): bool => $this->isVoiceRun($run))->values();
        $latencies = $runs->map(fn (BeanRun $run): ?int => $this->latencyMs($run))->filter(fn ($value): bool => is_int($value))->values();
        $dashboardLatencies = $dashboardRuns->map(fn (BeanRun $run): ?int => $this->latencyMs($run))->filter(fn ($value): bool => is_int($value))->values();
        $multiStepLatencies = $multiStepRuns->map(fn (BeanRun $run): ?int => $this->latencyMs($run))->filter(fn ($value): bool => is_int($value))->values();

        $failureRuns = $runs->filter(fn (BeanRun $run): bool => $this->isFailedRun($run));
        $genericFailures = $runs->filter(fn (BeanRun $run): bool => trim((string) $run->output) === self::FAILURE_TEXT);
        $internalLeaks = $runs->filter(fn (BeanRun $run): bool => $this->containsInternalLeak((string) $run->output));
        $dashboardUngrounded = $dashboardRuns->filter(fn (BeanRun $run): bool => $run->toolCalls->isEmpty());
        $dashboardCompletedToolRuns = $dashboardRuns->filter(fn (BeanRun $run): bool => $run->toolCalls->where('status', 'completed')->isNotEmpty());
        $dashboardConfirmationRuns = $dashboardRuns->filter(fn (BeanRun $run): bool => $run->toolCalls->where('status', 'waiting_confirmation')->isNotEmpty());
        $dashboardFailedToolRuns = $dashboardRuns->filter(fn (BeanRun $run): bool => $run->toolCalls->where('status', 'failed')->isNotEmpty());

        $voice = $this->voiceMetrics($voiceEvents, $voiceRuns);
        $semantic = $this->semanticMetrics($traces);
        $taskSuccessRate = $this->rate($dashboardRuns->count() - $dashboardRuns->filter(fn (BeanRun $run): bool => $this->isFailedRun($run))->count(), $dashboardRuns->count());
        $multiStepSuccessRate = $this->rate($multiStepRuns->count() - $multiStepRuns->filter(fn (BeanRun $run): bool => $this->isFailedRun($run))->count(), $multiStepRuns->count());

        $targets = $this->targets();
        $metrics = [
            'task_success_rate' => $taskSuccessRate,
            'multi_step_success_rate' => $multiStepSuccessRate,
            'generic_failure_rate' => $this->rate($genericFailures->count(), $runs->count()),
            'internal_error_leak_count' => $internalLeaks->count(),
            'dashboard_grounded_rate' => $this->rate($dashboardRuns->count() - $dashboardUngrounded->count(), $dashboardRuns->count()),
            'latency_ms' => [
                'all_p50' => $this->percentile($latencies, 50),
                'all_p95' => $this->percentile($latencies, 95),
                'dashboard_p50' => $this->percentile($dashboardLatencies, 50),
                'dashboard_p95' => $this->percentile($dashboardLatencies, 95),
                'multi_step_p95' => $this->percentile($multiStepLatencies, 95),
            ],
            'voice' => $voice,
            'semantic' => $semantic,
        ];

        return [
            'mode' => 'bean-world-class-ux-benchmark',
            'generated_at' => now()->toIso8601String(),
            'window_days' => $days,
            'counts' => [
                'runs' => $runs->count(),
                'dashboard_runs' => $dashboardRuns->count(),
                'multi_step_runs' => $multiStepRuns->count(),
                'voice_runs' => $voiceRuns->count(),
                'quality_traces' => $traces->count(),
                'voice_events' => $voiceEvents->count(),
                'failed_runs' => $failureRuns->count(),
                'generic_failures' => $genericFailures->count(),
                'internal_error_leaks' => $internalLeaks->count(),
                'dashboard_ungrounded_runs' => $dashboardUngrounded->count(),
                'dashboard_completed_tool_runs' => $dashboardCompletedToolRuns->count(),
                'dashboard_confirmation_runs' => $dashboardConfirmationRuns->count(),
                'dashboard_failed_tool_runs' => $dashboardFailedToolRuns->count(),
            ],
            'targets' => $targets,
            'metrics' => $metrics,
            'target_status' => $this->targetStatus($metrics, $targets),
            'failure_clusters' => $this->failureClusters($runs),
            'quality_flag_counts' => $traces->flatMap(fn (BeanQualityTrace $trace): array => $trace->quality_flags ?? [])->countBy()->sortDesc()->all(),
            'recent_problem_runs' => $this->recentProblemRuns($runs, $traces),
            'guidance' => 'Use this as the durable Bean UX scorecard. Treat missing samples as unknown, not success.',
        ];
    }

    public function writeReport(array $report, string $jsonPath, ?string $markdownPath = null, ?string $progressPath = null): void
    {
        File::ensureDirectoryExists(dirname($jsonPath));
        File::put($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($markdownPath) {
            File::ensureDirectoryExists(dirname($markdownPath));
            File::put($markdownPath, $this->markdown($report));
        }
        if ($progressPath) {
            File::ensureDirectoryExists(dirname($progressPath));
            File::put($progressPath, json_encode($this->progressSnapshot($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    public function markdown(array $report): string
    {
        $metrics = $report['metrics'] ?? [];
        $latency = $metrics['latency_ms'] ?? [];
        $voice = $metrics['voice'] ?? [];
        $lines = [
            '# Bean World-Class UX Benchmark Report',
            '',
            '- Generated: '.($report['generated_at'] ?? now()->toIso8601String()),
            '- Window: '.($report['window_days'] ?? '?').' days',
            '- Runs: '.data_get($report, 'counts.runs', 0),
            '- Dashboard runs: '.data_get($report, 'counts.dashboard_runs', 0),
            '- Voice events: '.data_get($report, 'counts.voice_events', 0),
            '',
            '## Core metrics',
            '- Task success rate: '.$this->percentString($metrics['task_success_rate'] ?? null),
            '- Multi-step success rate: '.$this->percentString($metrics['multi_step_success_rate'] ?? null),
            '- Generic failure rate: '.$this->percentString($metrics['generic_failure_rate'] ?? null),
            '- Internal error leaks: '.($metrics['internal_error_leak_count'] ?? 0),
            '- Dashboard grounded rate: '.$this->percentString($metrics['dashboard_grounded_rate'] ?? null),
            '- Dashboard tool outcomes: completed '.data_get($report, 'counts.dashboard_completed_tool_runs', 0).', confirmations '.data_get($report, 'counts.dashboard_confirmation_runs', 0).', failed/clarifying '.data_get($report, 'counts.dashboard_failed_tool_runs', 0).', no tool '.data_get($report, 'counts.dashboard_ungrounded_runs', 0),
            '- Dashboard latency p50/p95: '.($latency['dashboard_p50'] ?? 'n/a').'ms / '.($latency['dashboard_p95'] ?? 'n/a').'ms',
            '- Multi-step latency p95: '.($latency['multi_step_p95'] ?? 'n/a').'ms',
            '',
            '## Voice funnel',
            '- Wake detected: '.($voice['wake_detected'] ?? 0),
            '- First-command capture rate: '.$this->percentString($voice['first_command_capture_rate'] ?? null),
            '- Follow-up capture rate: '.$this->percentString($voice['followup_capture_rate'] ?? null),
            '- Background false submission rate: '.$this->percentString($voice['background_false_submission_rate'] ?? null),
            '- Echo/duplicate rate: '.$this->percentString($voice['echo_duplicate_rate'] ?? null),
            '- Failure wake reset rate: '.$this->percentString($voice['failure_wake_reset_rate'] ?? null),
            '- Dismiss success rate: '.$this->percentString($voice['dismiss_success_rate'] ?? null),
            '- Wake → listening p95: '.($voice['latency_ms']['wake_to_listening_p95'] ?? 'unknown').'ms',
            '- Speech → visible thinking p95: '.($voice['latency_ms']['speech_to_thinking_p95'] ?? 'unknown').'ms',
            '- Speech → spoken answer p95: '.($voice['latency_ms']['speech_to_answer_started_p95'] ?? 'unknown').'ms',
            '- Assistant speech finished → follow-up open p95: '.($voice['latency_ms']['speech_finished_to_followup_opened_p95'] ?? 'unknown').'ms',
            '',
            '## Continuity semantics',
            '- Follow-up/reference resolution: '.$this->percentString(data_get($metrics, 'semantic.followup_reference_resolution_rate')),
            '- Unnecessary clarification rate: '.$this->percentString(data_get($metrics, 'semantic.unnecessary_clarification_rate')),
            '- Immediate context-loss rate: '.$this->percentString(data_get($metrics, 'semantic.immediate_context_loss_rate')),
            '',
            '## Target status',
        ];
        foreach (($report['target_status'] ?? []) as $name => $status) {
            $lines[] = '- '.$name.': '.($status['status'] ?? 'unknown').' (actual '.($status['actual'] ?? 'n/a').', target '.($status['target'] ?? 'n/a').')';
        }
        $lines[] = '';
        $lines[] = '## Recent problem runs';
        foreach (($report['recent_problem_runs'] ?? []) as $run) {
            $lines[] = '- #'.($run['bean_run_id'] ?? '?').' '.($run['status'] ?? 'unknown').' '.($run['intent'] ?? 'unknown').' flags='.implode(',', $run['quality_flags'] ?? []).' output="'.mb_substr((string) ($run['assistant_answer'] ?? ''), 0, 140).'"';
        }

        return implode("\n", $lines)."\n";
    }

    private function progressSnapshot(array $report): array
    {
        return [
            'goal' => 'Make Bean meet measured user-experience benchmarks.',
            'updated_at' => now()->toIso8601String(),
            'latest_report' => $report,
            'next_recommended_action' => 'Use php artisan bean:ux-benchmark locally or on production after each runtime/voice change; address the largest failing target cluster first.',
            'resume_instructions' => [
                'Read docs/agent-project-state.md.',
                'Read AGENTS.md.',
                'Run php artisan bean:ux-benchmark --days=7.',
                'Do not rely on chat history as the source of truth.',
            ],
        ];
    }

    private function voiceMetrics(Collection $events, Collection $voiceRuns): array
    {
        $events = $events->sortBy(fn (BeanVoiceEvent $event): int => $this->eventTimestampMs($event))->values();
        $count = fn (string $type): int => $events->where('event_type', $type)->count();
        $wakeDetected = $count('wake_detected');
        $sessionStarted = $count('voice_session_started');
        $transcripts = $count('user_transcript_received');
        $requests = $count('bean_request_sent');
        $responses = $count('bean_response_received');
        $followupsOpened = $count('followup_window_opened');
        $followupTranscripts = $count('followup_transcript_received');
        $backgroundIgnored = $count('background_audio_ignored');
        $backgroundSubmitted = $count('background_audio_submitted');
        $echoIgnored = $count('assistant_echo_ignored');
        $echoSubmitted = $count('assistant_echo_submitted') + $count('duplicate_submission');
        $dismissDetected = $count('dismiss_command_detected');
        $dismissClosed = $count('dismiss_closed');
        $failureResponses = $events->where('event_type', 'bean_response_received')->filter(fn (BeanVoiceEvent $event): bool => (bool) data_get($event->payload, 'failed'))->count();
        $failureWakeResets = $count('failure_wake_reset');
        $wakeToListening = $this->voiceTransitionLatencies($events, ['wake_detected'], 'voice_session_started', 'voice_client_session_id');
        $speechToThinking = $this->voiceTransitionLatencies($events, ['user_transcript_received', 'followup_transcript_received'], 'thinking_visible', 'voice_client_turn_id');
        $speechToAnswerStarted = $this->voiceTransitionLatencies($events, ['user_transcript_received', 'followup_transcript_received'], 'assistant_speech_started', 'voice_client_turn_id');
        $speechFinishedToFollowup = $this->voiceTransitionLatencies($events, ['assistant_speech_finished'], 'followup_window_opened', 'voice_client_turn_id');

        return [
            'wake_detected' => $wakeDetected,
            'voice_session_started' => $sessionStarted,
            'user_transcript_received' => $transcripts,
            'bean_request_sent' => $requests,
            'bean_response_received' => $responses,
            'thinking_visible' => $count('thinking_visible'),
            'assistant_speech_started' => $count('assistant_speech_started'),
            'assistant_speech_finished' => $count('assistant_speech_finished'),
            'followup_window_opened' => $followupsOpened,
            'followup_transcript_received' => $followupTranscripts,
            'followup_window_expired' => $count('followup_window_expired'),
            'voice_session_closed' => $count('voice_session_closed'),
            'background_audio_ignored' => $backgroundIgnored,
            'background_audio_submitted' => $backgroundSubmitted,
            'assistant_echo_ignored' => $echoIgnored,
            'assistant_echo_submitted_or_duplicate' => $echoSubmitted,
            'dismiss_command_detected' => $dismissDetected,
            'dismiss_closed' => $dismissClosed,
            'voice_runs' => $voiceRuns->count(),
            'first_command_capture_rate' => $this->rate($transcripts, max($wakeDetected, $sessionStarted)),
            'followup_capture_rate' => $this->rate($followupTranscripts, $followupsOpened),
            'background_false_submission_rate' => $this->rate($backgroundSubmitted, $backgroundSubmitted + $backgroundIgnored),
            'echo_duplicate_rate' => $this->rate($echoSubmitted, $echoSubmitted + $echoIgnored),
            'failure_wake_reset_rate' => $this->rate($failureWakeResets, $failureResponses),
            'dismiss_success_rate' => $this->rate($dismissClosed, $dismissDetected),
            'latency_ms' => [
                'wake_to_listening_p50' => $this->percentile($wakeToListening, 50),
                'wake_to_listening_p95' => $this->percentile($wakeToListening, 95),
                'speech_to_thinking_p50' => $this->percentile($speechToThinking, 50),
                'speech_to_thinking_p95' => $this->percentile($speechToThinking, 95),
                'speech_to_answer_started_p50' => $this->percentile($speechToAnswerStarted, 50),
                'speech_to_answer_started_p95' => $this->percentile($speechToAnswerStarted, 95),
                'speech_finished_to_followup_opened_p50' => $this->percentile($speechFinishedToFollowup, 50),
                'speech_finished_to_followup_opened_p95' => $this->percentile($speechFinishedToFollowup, 95),
            ],
        ];
    }

    private function voiceTransitionLatencies(Collection $events, array $fromTypes, string $toType, string $payloadKey): Collection
    {
        return $events
            ->groupBy(fn (BeanVoiceEvent $event): string => $this->voiceCorrelationKey($event, $payloadKey))
            ->flatMap(function (Collection $group) use ($fromTypes, $toType): array {
                $latencies = [];
                $fromMs = null;
                foreach ($group->sortBy(fn (BeanVoiceEvent $event): int => $this->eventTimestampMs($event)) as $event) {
                    $eventMs = $this->eventTimestampMs($event);
                    if ($eventMs <= 0) {
                        continue;
                    }
                    if (in_array((string) $event->event_type, $fromTypes, true)) {
                        $fromMs = $eventMs;

                        continue;
                    }
                    if ((string) $event->event_type === $toType && $fromMs !== null) {
                        $latencies[] = max(0, $eventMs - $fromMs);
                        $fromMs = null;
                    }
                }

                return $latencies;
            })
            ->filter(fn ($value): bool => is_int($value))
            ->values();
    }

    private function voiceCorrelationKey(BeanVoiceEvent $event, string $payloadKey): string
    {
        $payloadValue = data_get($event->payload, $payloadKey);
        if (is_string($payloadValue) && $payloadValue !== '') {
            return $payloadKey.':'.$payloadValue;
        }
        if ($payloadKey === 'voice_client_turn_id' && $event->bean_run_id) {
            return 'run:'.$event->bean_run_id;
        }
        if ($event->bean_session_id) {
            return 'session:'.$event->bean_session_id;
        }

        return 'user:'.$event->user_id;
    }

    private function semanticMetrics(Collection $traces): array
    {
        $followups = $traces
            ->filter(fn (BeanQualityTrace $trace): bool => $this->looksLikeReferenceFollowup((string) $trace->user_message))
            ->values();
        $resolved = $followups->filter(fn (BeanQualityTrace $trace): bool => $this->referenceFollowupResolved($trace))->count();
        $clarifications = $followups->filter(fn (BeanQualityTrace $trace): bool => in_array('unnecessary_clarification', $trace->quality_flags ?? [], true) || $this->isClarifyingAnswer((string) $trace->assistant_answer))->count();
        $contextLosses = $followups->filter(fn (BeanQualityTrace $trace): bool => in_array('immediate_context_loss', $trace->quality_flags ?? [], true) || (! $this->referenceFollowupResolved($trace) && ($trace->tool_results_count ?? 0) === 0))->count();

        return [
            'followup_reference_resolution_rate' => $this->rate($resolved, $followups->count()),
            'unnecessary_clarification_rate' => $this->rate($clarifications, $followups->count()),
            'immediate_context_loss_rate' => $this->rate($contextLosses, $followups->count()),
            'followup_reference_samples' => $followups->count(),
            'unnecessary_clarifications' => $clarifications,
            'immediate_context_losses' => $contextLosses,
        ];
    }

    private function looksLikeReferenceFollowup(string $input): bool
    {
        $lower = mb_strtolower($input);
        if (preg_match('/\b(that[’\']?s|that is)?\s*doesn[’\']?t make sense\b/u', $lower) === 1) {
            return false;
        }

        return preg_match('/\b(that task|that note|that event|that reminder|that workspace|the previous (task|note|event|reminder)|complete that|delete that|edit that|update that|move that|reschedule that|add .* to that (note|task|event|reminder))\b/u', $lower) === 1;
    }

    private function referenceFollowupResolved(BeanQualityTrace $trace): bool
    {
        $actions = collect($trace->actions ?? [])->filter()->values();
        $flags = $trace->quality_flags ?? [];

        return $actions->isNotEmpty()
            && ($trace->tool_results_count ?? 0) > 0
            && ! in_array('immediate_context_loss', $flags, true)
            && ! in_array('unnecessary_clarification', $flags, true)
            && ! $this->isClarifyingAnswer((string) $trace->assistant_answer);
    }

    private function isClarifyingAnswer(string $answer): bool
    {
        return preg_match('/\b(which one|which .*\?|what .*\?|can you clarify|please clarify|did you mean|what do you mean|which note|which task)\b/iu', $answer) === 1;
    }

    private function eventOccurredAt(BeanVoiceEvent $event): ?Carbon
    {
        $value = $event->occurred_at ?: $event->created_at;

        return $value ? Carbon::parse($value) : null;
    }

    private function eventTimestampMs(BeanVoiceEvent $event): int
    {
        if ($event->occurred_at_ms) {
            return (int) $event->occurred_at_ms;
        }
        $payloadMs = data_get($event->payload, 'event_client_ms');
        if (is_numeric($payloadMs)) {
            return (int) $payloadMs;
        }
        $time = $this->eventOccurredAt($event);
        if (! $time) {
            return 0;
        }

        return ($time->getTimestamp() * 1000) + intdiv((int) $time->micro, 1000);
    }

    private function targets(): array
    {
        return [
            'task_success_rate' => ['operator' => '>=', 'value' => 0.95],
            'multi_step_success_rate' => ['operator' => '>=', 'value' => 0.90],
            'generic_failure_rate' => ['operator' => '<=', 'value' => 0.02],
            'internal_error_leak_count' => ['operator' => '<=', 'value' => 0],
            'dashboard_grounded_rate' => ['operator' => '>=', 'value' => 0.95],
            'dashboard_latency_p50_ms' => ['operator' => '<=', 'value' => 5000],
            'dashboard_latency_p95_ms' => ['operator' => '<=', 'value' => 12000],
            'multi_step_latency_p95_ms' => ['operator' => '<=', 'value' => 20000],
            'voice_first_command_capture_rate' => ['operator' => '>=', 'value' => 0.98],
            'voice_followup_capture_rate' => ['operator' => '>=', 'value' => 0.95],
            'voice_background_false_submission_rate' => ['operator' => '<=', 'value' => 0.01],
            'voice_echo_duplicate_rate' => ['operator' => '<=', 'value' => 0.01],
            'voice_failure_wake_reset_rate' => ['operator' => '>=', 'value' => 1.0],
            'voice_dismiss_success_rate' => ['operator' => '>=', 'value' => 1.0],
            'voice_wake_to_listening_p95_ms' => ['operator' => '<=', 'value' => 1000],
            'voice_speech_to_thinking_p95_ms' => ['operator' => '<=', 'value' => 500],
            'voice_speech_to_answer_started_p95_ms' => ['operator' => '<=', 'value' => 7000],
            'voice_followup_open_after_speech_p95_ms' => ['operator' => '<=', 'value' => 700],
            'followup_reference_resolution_rate' => ['operator' => '>=', 'value' => 0.95],
            'unnecessary_clarification_rate' => ['operator' => '<=', 'value' => 0.03],
            'immediate_context_loss_rate' => ['operator' => '<=', 'value' => 0.02],
        ];
    }

    private function targetStatus(array $metrics, array $targets): array
    {
        $actuals = [
            'task_success_rate' => $metrics['task_success_rate'] ?? null,
            'multi_step_success_rate' => $metrics['multi_step_success_rate'] ?? null,
            'generic_failure_rate' => $metrics['generic_failure_rate'] ?? null,
            'internal_error_leak_count' => $metrics['internal_error_leak_count'] ?? null,
            'dashboard_grounded_rate' => $metrics['dashboard_grounded_rate'] ?? null,
            'dashboard_latency_p50_ms' => data_get($metrics, 'latency_ms.dashboard_p50'),
            'dashboard_latency_p95_ms' => data_get($metrics, 'latency_ms.dashboard_p95'),
            'multi_step_latency_p95_ms' => data_get($metrics, 'latency_ms.multi_step_p95'),
            'voice_first_command_capture_rate' => data_get($metrics, 'voice.first_command_capture_rate'),
            'voice_followup_capture_rate' => data_get($metrics, 'voice.followup_capture_rate'),
            'voice_background_false_submission_rate' => data_get($metrics, 'voice.background_false_submission_rate'),
            'voice_echo_duplicate_rate' => data_get($metrics, 'voice.echo_duplicate_rate'),
            'voice_failure_wake_reset_rate' => data_get($metrics, 'voice.failure_wake_reset_rate'),
            'voice_dismiss_success_rate' => data_get($metrics, 'voice.dismiss_success_rate'),
            'voice_wake_to_listening_p95_ms' => data_get($metrics, 'voice.latency_ms.wake_to_listening_p95'),
            'voice_speech_to_thinking_p95_ms' => data_get($metrics, 'voice.latency_ms.speech_to_thinking_p95'),
            'voice_speech_to_answer_started_p95_ms' => data_get($metrics, 'voice.latency_ms.speech_to_answer_started_p95'),
            'voice_followup_open_after_speech_p95_ms' => data_get($metrics, 'voice.latency_ms.speech_finished_to_followup_opened_p95'),
            'followup_reference_resolution_rate' => data_get($metrics, 'semantic.followup_reference_resolution_rate'),
            'unnecessary_clarification_rate' => data_get($metrics, 'semantic.unnecessary_clarification_rate'),
            'immediate_context_loss_rate' => data_get($metrics, 'semantic.immediate_context_loss_rate'),
        ];
        $status = [];
        foreach ($targets as $key => $target) {
            $actual = $actuals[$key] ?? null;
            $ok = $actual === null ? null : (($target['operator'] === '>=') ? $actual >= $target['value'] : $actual <= $target['value']);
            $status[$key] = [
                'status' => $ok === null ? 'unknown' : ($ok ? 'pass' : 'fail'),
                'actual' => $actual,
                'target' => $target['operator'].' '.$target['value'],
            ];
        }

        return $status;
    }

    private function isDashboardRun(BeanRun $run): bool
    {
        if ($run->toolCalls->isNotEmpty()) {
            return true;
        }

        return preg_match('/\b(task|tasks|todo|reminder|reminders|calendar|event|events|note|notes|workspace|dashboard|overdue)\b/iu', (string) $run->input) === 1;
    }

    private function isVoiceRun(BeanRun $run): bool
    {
        return (string) $run->mode === 'voice' || (bool) data_get($run->metadata, 'voice') || (bool) data_get($run->metadata, 'voice_input');
    }

    private function isFailedRun(BeanRun $run): bool
    {
        return (string) $run->status === 'failed' || trim((string) $run->output) === self::FAILURE_TEXT;
    }

    private function containsInternalLeak(string $text): bool
    {
        return preg_match('/\b(sqlstate|exception|stack trace|traceback|php-fpm|artisan|database|mysql|postgres|redis|api key|openai|hermes_home|bean_tool_context|permission denied|no such file)\b/iu', $text) === 1;
    }

    private function latencyMs(BeanRun $run): ?int
    {
        if (! $run->started_at || ! $run->completed_at) {
            return null;
        }

        return max(0, (int) Carbon::parse($run->started_at)->diffInMilliseconds(Carbon::parse($run->completed_at)));
    }

    private function percentile(Collection $values, int $percentile): ?int
    {
        $sorted = $values->filter(fn ($value): bool => is_numeric($value))->sort()->values();
        if ($sorted->isEmpty()) {
            return null;
        }
        $index = (int) ceil(($percentile / 100) * $sorted->count()) - 1;

        return (int) $sorted->get(max(0, min($index, $sorted->count() - 1)));
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        if ($denominator <= 0) {
            return null;
        }

        return round($numerator / $denominator, 4);
    }

    private function percentString(?float $value): string
    {
        return $value === null ? 'unknown' : round($value * 100, 2).'%';
    }

    private function failureClusters(Collection $runs): array
    {
        return $runs
            ->filter(fn (BeanRun $run): bool => $this->isFailedRun($run))
            ->groupBy(function (BeanRun $run): string {
                $firstAction = $run->toolCalls->first()?->action ?? 'no_tool';

                return ($run->mode ?: 'text').'|'.$firstAction.'|'.$run->status;
            })
            ->map(fn (Collection $cluster): int => $cluster->count())
            ->sortDesc()
            ->all();
    }

    private function recentProblemRuns(Collection $runs, Collection $traces): array
    {
        $tracesByRun = $traces->keyBy('bean_run_id');

        return $runs
            ->filter(fn (BeanRun $run): bool => $this->isFailedRun($run) || $this->containsInternalLeak((string) $run->output) || count($tracesByRun->get($run->id)?->quality_flags ?? []) > 0)
            ->sortByDesc('id')
            ->take(20)
            ->map(function (BeanRun $run) use ($tracesByRun): array {
                $trace = $tracesByRun->get($run->id);

                return [
                    'bean_run_id' => $run->id,
                    'status' => $run->status,
                    'mode' => $run->mode,
                    'intent' => $trace?->intent,
                    'actions' => $run->toolCalls->pluck('action')->values()->all(),
                    'quality_flags' => $trace?->quality_flags ?? [],
                    'latency_ms' => $this->latencyMs($run),
                    'user_message' => $run->input,
                    'assistant_answer' => $run->output,
                ];
            })
            ->values()
            ->all();
    }
}
