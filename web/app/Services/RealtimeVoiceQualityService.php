<?php

namespace App\Services;

use App\Models\AiUsageLog;
use Illuminate\Support\Collection;

class RealtimeVoiceQualityService
{
    public const TARGETS = [
        'p50_transcript_to_first_assistant_ms' => 700,
        'p95_transcript_to_first_assistant_ms' => 1200,
        'p50_response_create_to_first_assistant_ms' => 500,
        'p95_response_create_to_first_assistant_ms' => 900,
        'p50_turn_completed_ms' => 2500,
        'p95_turn_completed_ms' => 5000,
        'spoken_brevity_violation_rate' => 0.05,
        'minimum_background_queued_count' => 1,
        'background_fallback_queue_count' => 0,
        'background_unacknowledged_count' => 0,
        'p95_background_queue_elapsed_ms' => 1800,
        'background_cancel_failure_count' => 0,
        'minimum_background_completed_count' => 1,
        'minimum_background_progress_prompt_count' => 1,
        'p95_background_first_progress_elapsed_ms' => 8500,
        'background_progress_duplicate_count' => 0,
        'background_progress_brevity_violation_count' => 0,
        'background_progress_max_spoken_character_count' => 160,
        'background_progress_max_spoken_sentence_count' => 1,
        'background_completion_duplicate_count' => 0,
        'background_completion_brevity_violation_count' => 0,
        'background_completion_spoken_telemetry_incomplete_count' => 0,
        'background_completion_max_spoken_character_count' => 260,
        'background_completion_max_spoken_sentence_count' => 2,
        'background_failure_count' => 0,
        'background_watch_failure_count' => 0,
        'background_failure_misleading_count' => 0,
        'background_failure_truthfulness_incomplete_count' => 0,
        'background_silent_completion_count' => 0,
        'background_cancelled_after_voice_closed_count' => 0,
        'realtime_error_count' => 0,
        'response_failure_count' => 0,
        'unanswered_response_count' => 0,
        'tool_call_failure_count' => 0,
        'tool_fallback_failure_count' => 0,
        'transport_failure_count' => 0,
        'context_freshness_failure_count' => 0,
        'context_freshness_unrecovered_count' => 0,
        'context_refresh_recovery_completion_missing_count' => 0,
        'minimum_context_refresh_success_count' => 1,
        'p95_context_refresh_success_ms' => 220,
        'premature_completion_claim_count' => 0,
        'minimum_barge_in_count' => 1,
        'minimum_internal_prompt_barge_in_count' => 1,
        'p95_barge_in_cancel_dispatch_ms' => 80,
        'barge_in_incomplete_count' => 0,
        'minimum_barge_in_recovery_count' => 1,
        'p95_barge_in_recovery_elapsed_ms' => 3000,
        'barge_in_recovery_incomplete_count' => 0,
        'barge_in_recovery_missing_response_id_count' => 0,
        'in_flight_cancel_failure_count' => 0,
        'interrupt_signal_failure_count' => 0,
        'minimum_follow_up_turn_count' => 2,
        'minimum_contextual_follow_up_turn_count' => 1,
        'minimum_micro_follow_up_kind_count' => 5,
        'minimum_micro_follow_up_ready_kind_count' => 5,
        'untyped_contextual_follow_up_count' => 0,
        'untyped_contextual_follow_up_ready_count' => 0,
        'minimum_contextual_follow_up_resolution_count' => 1,
        'contextual_follow_up_unresolved_count' => 0,
        'minimum_follow_up_ready_count' => 1,
        'p95_follow_up_ready_elapsed_ms' => 120,
        'follow_up_ready_incomplete_count' => 0,
        'minimum_pending_response_recovery_count' => 1,
        'p95_pending_response_recovery_elapsed_ms' => 700,
        'pending_response_unrecovered_count' => 0,
        'minimum_audio_done_ready_count' => 1,
        'p95_audio_done_ready_elapsed_ms' => 80,
        'audio_done_ready_incomplete_count' => 0,
        'minimum_spoken_naturalness_sample_count' => 3,
        'spoken_naturalness_violation_count' => 0,
        'spoken_duplicate_response_count' => 0,
        'unsupported_direct_answer_count' => 0,
        'stale_context_direct_answer_count' => 0,
        'background_required_direct_answer_count' => 0,
    ];

    public function benchmarkSummary(
        Collection $turns,
        Collection $events,
        int $days,
        ?string $since = null,
        ?int $minimumTurns = null,
        bool $includeRecentSlowTurns = true,
    ): array {
        $metrics = [
            'transcript_to_first_assistant_ms' => $this->metricSummary(
                $turns,
                'transcript_to_first_assistant_ms',
                self::TARGETS['p50_transcript_to_first_assistant_ms'],
                self::TARGETS['p95_transcript_to_first_assistant_ms'],
            ),
            'response_create_to_first_assistant_ms' => $this->metricSummary(
                $turns,
                'response_create_to_first_assistant_ms',
                self::TARGETS['p50_response_create_to_first_assistant_ms'],
                self::TARGETS['p95_response_create_to_first_assistant_ms'],
            ),
            'turn_completed_ms' => $this->metricSummary(
                $turns,
                'turn_completed_ms',
                self::TARGETS['p50_turn_completed_ms'],
                self::TARGETS['p95_turn_completed_ms'],
            ),
            'transcript_to_response_create_ms' => $this->metricSummary($turns, 'transcript_to_response_create_ms', 250, 450),
        ];

        $eventCounts = $this->eventCounts($events);
        $brevity = $this->brevitySummary($turns);
        $conversation = $this->conversationSummary($turns);
        $telemetry = $this->telemetrySummary($turns);
        $contextualFollowUpResolution = $this->contextualFollowUpResolutionSummary($events);
        $bargeIn = $this->bargeInSummary($events);
        $bargeInRecovery = $this->bargeInRecoverySummary($events);
        $followUpReadiness = $this->followUpReadinessSummary($events);
        $pendingResponseRecovery = $this->pendingResponseRecoverySummary($events);
        $audioDoneReadiness = $this->audioDoneReadinessSummary($events);
        $contextRefresh = $this->contextRefreshSummary($events);
        $backgroundQueue = $this->backgroundQueueSummary($events);
        $backgroundProgress = $this->backgroundProgressSummary($events);
        $backgroundFailureTruthfulness = $this->backgroundFailureTruthfulnessSummary($events);
        $spokenNaturalness = $this->spokenNaturalnessSummary($events);
        $unsupportedDirectAnswer = $this->unsupportedDirectAnswerSummary($events);
        $spokenNaturalnessSample = (int) ($spokenNaturalness['sample_size'] ?? 0);
        $bargeInSample = (int) ($bargeIn['sample_size'] ?? 0);
        $bargeInRecoverySample = (int) ($bargeInRecovery['sample_size'] ?? 0);
        $followUpReadinessSample = (int) ($followUpReadiness['sample_size'] ?? 0);
        $pendingResponseRecoverySample = (int) ($pendingResponseRecovery['recovered_count'] ?? 0);
        $pendingResponseUnrecovered = (int) ($pendingResponseRecovery['unrecovered_count'] ?? 0);
        $audioDoneReadinessSample = (int) ($audioDoneReadiness['sample_size'] ?? 0);
        $contextualFollowUpResolutionSample = (int) ($contextualFollowUpResolution['sample_size'] ?? 0);
        $backgroundQueuedCount = (int) ($backgroundQueue['sample_size'] ?? 0);
        $backgroundUnacknowledged = (int) ($backgroundQueue['unacknowledged_count'] ?? 0);
        $backgroundCompletedCount = (int) ($eventCounts['realtime_background_completed'] ?? 0);
        $backgroundCompletion = $this->backgroundCompletionSummary($events);
        $backgroundCompletionDuplicates = (int) ($backgroundCompletion['duplicate_count'] ?? 0);
        $backgroundCompletionBrevityViolations = (int) ($backgroundCompletion['brevity_violation_count'] ?? 0);
        $backgroundCompletionSpokenIncomplete = (int) ($backgroundCompletion['spoken_text_incomplete_count'] ?? 0);
        $backgroundProgressPromptCount = (int) ($backgroundProgress['sample_size'] ?? 0);
        $backgroundProgressDuplicates = (int) ($backgroundProgress['duplicate_count'] ?? 0);
        $backgroundProgressBrevityViolations = (int) ($backgroundProgress['brevity_violation_count'] ?? 0);
        $backgroundFailures = $this->backgroundFailureCount($eventCounts);
        $backgroundWatchFailures = (int) ($eventCounts['realtime_background_watch_failure'] ?? 0);
        $backgroundFailureMisleading = (int) ($backgroundFailureTruthfulness['misleading_count'] ?? 0);
        $backgroundFailureTruthfulnessIncomplete = (int) ($backgroundFailureTruthfulness['incomplete_count'] ?? 0);
        $backgroundSilentCompletions = (int) ($eventCounts['realtime_background_completed_without_voice'] ?? 0);
        $backgroundCompletionsAfterVoiceClosed = (int) ($eventCounts['realtime_background_completed_after_voice_closed'] ?? 0);
        $backgroundCancelsAfterVoiceClosed = (int) ($eventCounts['realtime_background_cancelled_after_voice_closed'] ?? 0);
        $backgroundCancelFailureCount = (int) ($eventCounts['realtime_background_cancel_failure'] ?? 0);
        $inFlightCancelFailures = (int) ($eventCounts['flutter_realtime_in_flight_cancel_failure'] ?? 0);
        $interruptSignalFailures = (int) ($eventCounts['flutter_realtime_interrupt_signal_failure'] ?? 0);
        $realtimeErrors = (int) ($eventCounts['realtime_error'] ?? 0);
        $responseFailures = (int) ($eventCounts['flutter_realtime_response_failed'] ?? 0);
        $unansweredResponses = $this->unansweredResponseSummary($events);
        $unansweredResponseCount = (int) ($unansweredResponses['unanswered_count'] ?? 0);
        $toolCallFailures = (int) ($eventCounts['realtime_tool_call_failure'] ?? 0);
        $toolFallbackFailures = (int) ($eventCounts['realtime_tool_fallback_failure'] ?? 0);
        $prematureCompletionClaims = (int) ($eventCounts['flutter_realtime_premature_completion_claim'] ?? 0);
        $unsupportedDirectAnswers = (int) ($eventCounts['flutter_realtime_unsupported_direct_answer'] ?? 0);
        $staleContextDirectAnswers = (int) ($unsupportedDirectAnswer['missing_fresh_context_count'] ?? 0);
        $backgroundRequiredDirectAnswers = (int) ($unsupportedDirectAnswer['background_required_count'] ?? 0);
        $contextRefreshSuccesses = (int) ($eventCounts['dashboard_context_pre_response_success'] ?? 0);
        $transportFailures = $this->transportFailureCount($eventCounts);
        $contextFreshnessFailures = $this->contextFreshnessFailureCount($eventCounts);
        $contextFreshnessUnrecovered = (int) ($contextRefresh['unrecovered_failure_count'] ?? 0);
        $contextRefreshRecoveryCompletionsMissing = (int) ($contextRefresh['recovery_completion_missing_count'] ?? 0);
        $failedMetric = collect($metrics)->contains(fn (array $metric): bool => ($metric['status'] ?? null) === 'fail');
        $missingMetric = collect($metrics)->contains(fn (array $metric): bool => ($metric['status'] ?? null) === 'missing');
        $microFollowUpKindCount = (int) ($conversation['micro_follow_up_kind_count'] ?? 0);
        $untypedContextualFollowUps = (int) ($conversation['untyped_contextual_follow_up_count'] ?? 0);
        $microFollowUpReadyKindCount = (int) ($followUpReadiness['micro_follow_up_ready_kind_count'] ?? 0);
        $untypedContextualFollowUpReadies = (int) ($followUpReadiness['untyped_contextual_follow_up_ready_count'] ?? 0);
        $status = $turns->isEmpty()
            ? 'no_data'
            : (($failedMetric || $missingMetric || in_array($brevity['status'], ['fail', 'missing'], true) || $spokenNaturalnessSample < self::TARGETS['minimum_spoken_naturalness_sample_count'] || ($spokenNaturalness['status'] ?? null) === 'fail' || in_array($conversation['status'] ?? null, ['fail', 'missing'], true) || $microFollowUpKindCount < self::TARGETS['minimum_micro_follow_up_kind_count'] || $untypedContextualFollowUps > self::TARGETS['untyped_contextual_follow_up_count'] || $contextualFollowUpResolutionSample < self::TARGETS['minimum_contextual_follow_up_resolution_count'] || ($contextualFollowUpResolution['status'] ?? null) === 'fail' || $followUpReadinessSample < self::TARGETS['minimum_follow_up_ready_count'] || ($followUpReadiness['status'] ?? null) === 'fail' || $microFollowUpReadyKindCount < self::TARGETS['minimum_micro_follow_up_ready_kind_count'] || $untypedContextualFollowUpReadies > self::TARGETS['untyped_contextual_follow_up_ready_count'] || $pendingResponseRecoverySample < self::TARGETS['minimum_pending_response_recovery_count'] || $pendingResponseUnrecovered > self::TARGETS['pending_response_unrecovered_count'] || ($pendingResponseRecovery['status'] ?? null) === 'fail' || $audioDoneReadinessSample < self::TARGETS['minimum_audio_done_ready_count'] || ($audioDoneReadiness['status'] ?? null) === 'fail' || $bargeInSample < self::TARGETS['minimum_barge_in_count'] || ($bargeIn['status'] ?? null) === 'fail' || $bargeInRecoverySample < self::TARGETS['minimum_barge_in_recovery_count'] || ($bargeInRecovery['status'] ?? null) === 'fail' || ($contextRefresh['status'] ?? null) === 'fail' || $contextFreshnessUnrecovered > self::TARGETS['context_freshness_unrecovered_count'] || $contextRefreshRecoveryCompletionsMissing > self::TARGETS['context_refresh_recovery_completion_missing_count'] || $backgroundQueuedCount < self::TARGETS['minimum_background_queued_count'] || ($backgroundQueue['status'] ?? null) === 'fail' || $backgroundUnacknowledged > self::TARGETS['background_unacknowledged_count'] || $backgroundCompletedCount < self::TARGETS['minimum_background_completed_count'] || $backgroundCompletionDuplicates > self::TARGETS['background_completion_duplicate_count'] || $backgroundCompletionBrevityViolations > self::TARGETS['background_completion_brevity_violation_count'] || $backgroundCompletionSpokenIncomplete > self::TARGETS['background_completion_spoken_telemetry_incomplete_count'] || $backgroundProgressPromptCount < self::TARGETS['minimum_background_progress_prompt_count'] || $backgroundProgressDuplicates > self::TARGETS['background_progress_duplicate_count'] || $backgroundProgressBrevityViolations > self::TARGETS['background_progress_brevity_violation_count'] || ($backgroundProgress['status'] ?? null) === 'fail' || $backgroundFailures > self::TARGETS['background_failure_count'] || $backgroundWatchFailures > self::TARGETS['background_watch_failure_count'] || $backgroundFailureMisleading > self::TARGETS['background_failure_misleading_count'] || $backgroundFailureTruthfulnessIncomplete > self::TARGETS['background_failure_truthfulness_incomplete_count'] || $backgroundSilentCompletions > self::TARGETS['background_silent_completion_count'] || $backgroundCancelsAfterVoiceClosed > self::TARGETS['background_cancelled_after_voice_closed_count'] || $backgroundCancelFailureCount > self::TARGETS['background_cancel_failure_count'] || $inFlightCancelFailures > self::TARGETS['in_flight_cancel_failure_count'] || $interruptSignalFailures > self::TARGETS['interrupt_signal_failure_count'] || $realtimeErrors > self::TARGETS['realtime_error_count'] || $responseFailures > self::TARGETS['response_failure_count'] || $unansweredResponseCount > self::TARGETS['unanswered_response_count'] || $toolCallFailures > self::TARGETS['tool_call_failure_count'] || $toolFallbackFailures > self::TARGETS['tool_fallback_failure_count'] || $prematureCompletionClaims > self::TARGETS['premature_completion_claim_count'] || $unsupportedDirectAnswers > self::TARGETS['unsupported_direct_answer_count'] || $staleContextDirectAnswers > self::TARGETS['stale_context_direct_answer_count'] || $backgroundRequiredDirectAnswers > self::TARGETS['background_required_direct_answer_count'] || $contextRefreshSuccesses < self::TARGETS['minimum_context_refresh_success_count'] || $transportFailures > self::TARGETS['transport_failure_count'] || $contextFreshnessFailures > self::TARGETS['context_freshness_failure_count']) ? 'needs_attention' : 'world_class');
        $window = [
            'days' => $days,
            'turn_sample_size' => $turns->count(),
            'event_sample_size' => $events->count(),
        ];
        if ($since !== null) {
            $window = ['days' => $days, 'since' => $since] + array_diff_key($window, ['days' => true]);
        }
        if ($minimumTurns !== null) {
            $window['minimum_turns'] = $minimumTurns;
        }

        $summary = [
            'status' => $status,
            'benchmark' => 'siri_alexa_voice_responsiveness',
            'window' => $window,
            'targets' => self::TARGETS,
            'metrics' => $metrics,
            'speech' => [
                'brevity' => $brevity,
                'naturalness' => $spokenNaturalness,
            ],
            'conversation' => $conversation,
            'contextual_follow_up_resolution' => $contextualFollowUpResolution,
            'telemetry' => $telemetry,
            'events' => [
                'counts' => $eventCounts,
                ...$this->eventHealth($eventCounts, $turns->count()),
                'barge_in_quality' => $bargeIn,
                'minimum_barge_in_count' => self::TARGETS['minimum_barge_in_count'],
                'barge_in_recovery_quality' => $bargeInRecovery,
                'minimum_barge_in_recovery_count' => self::TARGETS['minimum_barge_in_recovery_count'],
                'follow_up_readiness_quality' => $followUpReadiness,
                'minimum_follow_up_ready_count' => self::TARGETS['minimum_follow_up_ready_count'],
                'pending_response_recovery_quality' => $pendingResponseRecovery,
                'minimum_pending_response_recovery_count' => self::TARGETS['minimum_pending_response_recovery_count'],
                'audio_done_readiness_quality' => $audioDoneReadiness,
                'minimum_audio_done_ready_count' => self::TARGETS['minimum_audio_done_ready_count'],
                'background_queue_quality' => $backgroundQueue,
                'minimum_background_queued_count' => self::TARGETS['minimum_background_queued_count'],
                'background_completed_count' => $backgroundCompletedCount,
                'background_completed_rate' => $this->eventRate($backgroundCompletedCount, $turns->count()),
                'minimum_background_completed_count' => self::TARGETS['minimum_background_completed_count'],
                'background_completion_quality' => $backgroundCompletion,
                'background_progress_quality' => $backgroundProgress,
                'minimum_background_progress_prompt_count' => self::TARGETS['minimum_background_progress_prompt_count'],
                'background_failure_count' => $backgroundFailures,
                'background_failure_rate' => $this->eventRate($backgroundFailures, $turns->count()),
                'background_watch_failure_count' => $backgroundWatchFailures,
                'background_watch_failure_rate' => $this->eventRate($backgroundWatchFailures, $turns->count()),
                'background_watch_failure_status' => $backgroundWatchFailures === 0 ? 'pass' : 'fail',
                'background_failure_truthfulness' => $backgroundFailureTruthfulness,
                'background_silent_completion_count' => $backgroundSilentCompletions,
                'background_silent_completion_rate' => $this->eventRate($backgroundSilentCompletions, $turns->count()),
                'background_completed_after_voice_closed_count' => $backgroundCompletionsAfterVoiceClosed,
                'background_completed_after_voice_closed_rate' => $this->eventRate($backgroundCompletionsAfterVoiceClosed, $turns->count()),
                'background_completion_status' => $backgroundCompletedCount >= self::TARGETS['minimum_background_completed_count'] && $backgroundFailures <= self::TARGETS['background_failure_count'] && $backgroundSilentCompletions <= self::TARGETS['background_silent_completion_count'] ? 'pass' : 'fail',
                'background_cancelled_after_voice_closed_count' => $backgroundCancelsAfterVoiceClosed,
                'background_cancelled_after_voice_closed_rate' => $this->eventRate($backgroundCancelsAfterVoiceClosed, $turns->count()),
                'background_cancelled_after_voice_closed_status' => $backgroundCancelsAfterVoiceClosed === 0 ? 'pass' : 'fail',
                'background_cancel_failure_count' => $backgroundCancelFailureCount,
                'background_cancel_status' => $backgroundCancelFailureCount === 0 ? 'pass' : 'fail',
                'in_flight_cancel_failure_count' => $inFlightCancelFailures,
                'in_flight_cancel_failure_rate' => $this->eventRate($inFlightCancelFailures, $turns->count()),
                'in_flight_cancel_status' => $inFlightCancelFailures === 0 ? 'pass' : 'fail',
                'interrupt_signal_failure_count' => $interruptSignalFailures,
                'interrupt_signal_failure_rate' => $this->eventRate($interruptSignalFailures, $turns->count()),
                'interrupt_signal_status' => $interruptSignalFailures === 0 ? 'pass' : 'fail',
                'realtime_error_count' => $realtimeErrors,
                'realtime_error_rate' => $this->eventRate($realtimeErrors, $turns->count()),
                'realtime_error_status' => $realtimeErrors === 0 ? 'pass' : 'fail',
                'response_failure_count' => $responseFailures,
                'response_failure_rate' => $this->eventRate($responseFailures, $turns->count()),
                'response_failure_status' => $responseFailures === 0 ? 'pass' : 'fail',
                'unanswered_response_quality' => $unansweredResponses,
                'tool_call_failure_count' => $toolCallFailures,
                'tool_call_failure_rate' => $this->eventRate($toolCallFailures, $turns->count()),
                'tool_call_failure_status' => $toolCallFailures === 0 ? 'pass' : 'fail',
                'tool_fallback_failure_count' => $toolFallbackFailures,
                'tool_fallback_failure_rate' => $this->eventRate($toolFallbackFailures, $turns->count()),
                'tool_fallback_failure_status' => $toolFallbackFailures === 0 ? 'pass' : 'fail',
                'premature_completion_claim_count' => $prematureCompletionClaims,
                'premature_completion_claim_rate' => $this->eventRate($prematureCompletionClaims, $turns->count()),
                'premature_completion_claim_status' => $prematureCompletionClaims === 0 ? 'pass' : 'fail',
                'unsupported_direct_answer_count' => $unsupportedDirectAnswers,
                'unsupported_direct_answer_rate' => $this->eventRate($unsupportedDirectAnswers, $turns->count()),
                'unsupported_direct_answer_status' => $unsupportedDirectAnswers === 0 ? 'pass' : 'fail',
                'unsupported_direct_answer_quality' => $unsupportedDirectAnswer,
                'transport_failure_count' => $transportFailures,
                'transport_failure_rate' => $this->eventRate($transportFailures, $turns->count()),
                'transport_failure_status' => $transportFailures === 0 ? 'pass' : 'fail',
                'context_refresh_quality' => $contextRefresh,
                'context_refresh_success_count' => $contextRefreshSuccesses,
                'context_refresh_success_rate' => $this->eventRate($contextRefreshSuccesses, $turns->count()),
                'minimum_context_refresh_success_count' => self::TARGETS['minimum_context_refresh_success_count'],
                'context_freshness_failure_count' => $contextFreshnessFailures,
                'context_freshness_failure_rate' => $this->eventRate($contextFreshnessFailures, $turns->count()),
                'context_freshness_status' => $contextFreshnessFailures === 0 && $contextRefreshSuccesses >= self::TARGETS['minimum_context_refresh_success_count'] ? 'pass' : 'fail',
            ],
            ...($includeRecentSlowTurns ? ['recent_slow_turns' => $this->recentSlowTurns($turns)] : []),
        ];

        $summary['gate'] = $this->requirementGate($summary);

        return $summary;
    }

    /**
     * @return list<string>
     */
    public function benchmarkFailures(array $summary): array
    {
        $failures = [];
        $turnSampleSize = (int) data_get($summary, 'window.turn_sample_size', 0);
        $minimumTurns = data_get($summary, 'window.minimum_turns');
        if ($minimumTurns !== null && $turnSampleSize < (int) $minimumTurns) {
            $failures[] = "voice_insufficient_sample:{$turnSampleSize}/{$minimumTurns}";
        }

        foreach ((array) data_get($summary, 'metrics', []) as $name => $metric) {
            $status = (string) data_get($metric, 'status', 'missing');
            if ($status !== 'pass') {
                $failures[] = "voice_metric_{$status}:{$name}";
            }
        }

        $brevityStatus = (string) data_get($summary, 'speech.brevity.status', 'missing');
        if ($brevityStatus !== 'pass') {
            $failures[] = "voice_brevity_{$brevityStatus}";
        }

        $spokenNaturalnessViolations = (int) data_get($summary, 'speech.naturalness.violation_count', 0);
        $spokenNaturalnessSample = (int) data_get($summary, 'speech.naturalness.sample_size', 0);
        $minimumSpokenNaturalnessSample = (int) data_get($summary, 'speech.naturalness.target_min_sample_size', self::TARGETS['minimum_spoken_naturalness_sample_count']);
        if ($turnSampleSize > 0 && $spokenNaturalnessSample < $minimumSpokenNaturalnessSample) {
            $failures[] = "voice_spoken_naturalness_missing:{$spokenNaturalnessSample}/{$minimumSpokenNaturalnessSample}";
        }
        if ($spokenNaturalnessViolations > self::TARGETS['spoken_naturalness_violation_count']) {
            $failures[] = "voice_spoken_naturalness_violation:{$spokenNaturalnessViolations}";
        }
        $spokenDuplicateResponses = (int) data_get($summary, 'speech.naturalness.duplicate_response_count', 0);
        if ($spokenDuplicateResponses > self::TARGETS['spoken_duplicate_response_count']) {
            $failures[] = "voice_spoken_duplicate_response:{$spokenDuplicateResponses}";
        }

        $trackedTelemetry = (int) data_get($summary, 'telemetry.usage_sample_size', 0);
        if ($trackedTelemetry < $turnSampleSize) {
            $failures[] = "voice_usage_telemetry_incomplete:{$trackedTelemetry}/{$turnSampleSize}";
        }

        $followUpSample = (int) data_get($summary, 'conversation.follow_up_sample_size', 0);
        if ($followUpSample < $turnSampleSize) {
            $failures[] = "voice_follow_up_telemetry_incomplete:{$followUpSample}/{$turnSampleSize}";
        }

        $minimumFollowUps = (int) data_get($summary, 'conversation.target_min_follow_up_turn_count', self::TARGETS['minimum_follow_up_turn_count']);
        $followUpTurns = (int) data_get($summary, 'conversation.follow_up_turn_count', 0);
        if ($turnSampleSize > 0 && $followUpTurns < $minimumFollowUps) {
            $failures[] = "voice_follow_up_missing:{$followUpTurns}/{$minimumFollowUps}";
        }

        $minimumContextualFollowUps = (int) data_get($summary, 'conversation.target_min_contextual_follow_up_turn_count', self::TARGETS['minimum_contextual_follow_up_turn_count']);
        $contextualFollowUpTurns = (int) data_get($summary, 'conversation.contextual_follow_up_turn_count', 0);
        if ($turnSampleSize > 0 && $contextualFollowUpTurns < $minimumContextualFollowUps) {
            $failures[] = "voice_contextual_follow_up_missing:{$contextualFollowUpTurns}/{$minimumContextualFollowUps}";
        }
        $minimumMicroFollowUpKinds = (int) data_get($summary, 'conversation.target_min_micro_follow_up_kind_count', self::TARGETS['minimum_micro_follow_up_kind_count']);
        $microFollowUpKindCount = (int) data_get($summary, 'conversation.micro_follow_up_kind_count', 0);
        if ($turnSampleSize > 0 && $microFollowUpKindCount < $minimumMicroFollowUpKinds) {
            $failures[] = "voice_micro_follow_up_kinds_missing:{$microFollowUpKindCount}/{$minimumMicroFollowUpKinds}";
        }
        $untypedContextualFollowUps = (int) data_get($summary, 'conversation.untyped_contextual_follow_up_count', 0);
        if ($untypedContextualFollowUps > self::TARGETS['untyped_contextual_follow_up_count']) {
            $failures[] = "voice_untyped_contextual_follow_up:{$untypedContextualFollowUps}";
        }

        $contextualFollowUpResolutionSample = (int) data_get($summary, 'contextual_follow_up_resolution.sample_size', 0);
        $minimumContextualFollowUpResolutions = (int) data_get($summary, 'contextual_follow_up_resolution.target_min_resolution_count', self::TARGETS['minimum_contextual_follow_up_resolution_count']);
        if ($turnSampleSize > 0 && $contextualFollowUpResolutionSample < $minimumContextualFollowUpResolutions) {
            $failures[] = "voice_contextual_follow_up_resolution_missing:{$contextualFollowUpResolutionSample}/{$minimumContextualFollowUpResolutions}";
        }
        if ($contextualFollowUpResolutionSample > 0) {
            $unresolvedContextualFollowUps = (int) data_get($summary, 'contextual_follow_up_resolution.unresolved_count', 0);
            if ($unresolvedContextualFollowUps > self::TARGETS['contextual_follow_up_unresolved_count']) {
                $failures[] = "voice_contextual_follow_up_unresolved:{$unresolvedContextualFollowUps}";
            }
        }

        $followUpReadySample = (int) data_get($summary, 'events.follow_up_readiness_quality.sample_size', 0);
        $minimumFollowUpReady = (int) data_get($summary, 'events.minimum_follow_up_ready_count', self::TARGETS['minimum_follow_up_ready_count']);
        if ($turnSampleSize > 0 && $followUpReadySample < $minimumFollowUpReady) {
            $failures[] = "voice_follow_up_ready_missing:{$followUpReadySample}/{$minimumFollowUpReady}";
        }
        if ($followUpReadySample > 0) {
            $readyIncomplete = (int) data_get($summary, 'events.follow_up_readiness_quality.incomplete_count', 0);
            if ($readyIncomplete > self::TARGETS['follow_up_ready_incomplete_count']) {
                $failures[] = "voice_follow_up_ready_incomplete:{$readyIncomplete}";
            }

            $readyElapsedSample = (int) data_get($summary, 'events.follow_up_readiness_quality.elapsed_sample_size', 0);
            if ($readyElapsedSample < $followUpReadySample) {
                $failures[] = "voice_follow_up_ready_telemetry_incomplete:{$readyElapsedSample}/{$followUpReadySample}";
            }

            $readyP95 = data_get($summary, 'events.follow_up_readiness_quality.p95_ready_elapsed_ms');
            if (is_numeric($readyP95) && (int) $readyP95 > self::TARGETS['p95_follow_up_ready_elapsed_ms']) {
                $failures[] = 'voice_follow_up_ready_latency:'.(int) $readyP95;
            }
            $minimumMicroFollowUpReadyKinds = (int) data_get($summary, 'events.follow_up_readiness_quality.target_min_micro_follow_up_ready_kind_count', self::TARGETS['minimum_micro_follow_up_ready_kind_count']);
            $microFollowUpReadyKindCount = (int) data_get($summary, 'events.follow_up_readiness_quality.micro_follow_up_ready_kind_count', 0);
            if ($microFollowUpReadyKindCount < $minimumMicroFollowUpReadyKinds) {
                $failures[] = "voice_micro_follow_up_ready_kinds_missing:{$microFollowUpReadyKindCount}/{$minimumMicroFollowUpReadyKinds}";
            }
            $untypedContextualFollowUpReadyCount = (int) data_get($summary, 'events.follow_up_readiness_quality.untyped_contextual_follow_up_ready_count', 0);
            if ($untypedContextualFollowUpReadyCount > self::TARGETS['untyped_contextual_follow_up_ready_count']) {
                $failures[] = "voice_untyped_contextual_follow_up_ready:{$untypedContextualFollowUpReadyCount}";
            }
        }

        $pendingResponseRecoverySample = (int) data_get($summary, 'events.pending_response_recovery_quality.recovered_count', 0);
        $minimumPendingResponseRecovery = (int) data_get($summary, 'events.minimum_pending_response_recovery_count', self::TARGETS['minimum_pending_response_recovery_count']);
        if ($turnSampleSize > 0 && $pendingResponseRecoverySample < $minimumPendingResponseRecovery) {
            $failures[] = "voice_pending_response_recovery_missing:{$pendingResponseRecoverySample}/{$minimumPendingResponseRecovery}";
        }
        $pendingResponseDeferred = (int) data_get($summary, 'events.pending_response_recovery_quality.sample_size', 0);
        if ($pendingResponseDeferred > 0) {
            $pendingResponseUnrecovered = (int) data_get($summary, 'events.pending_response_recovery_quality.unrecovered_count', 0);
            if ($pendingResponseUnrecovered > self::TARGETS['pending_response_unrecovered_count']) {
                $failures[] = "voice_pending_response_unrecovered:{$pendingResponseUnrecovered}";
            }

            $pendingResponseRecoveryElapsedSample = (int) data_get($summary, 'events.pending_response_recovery_quality.elapsed_sample_size', 0);
            if ($pendingResponseRecoveryElapsedSample < $pendingResponseRecoverySample) {
                $failures[] = "voice_pending_response_recovery_telemetry_incomplete:{$pendingResponseRecoveryElapsedSample}/{$pendingResponseRecoverySample}";
            }

            $pendingResponseRecoveryP95 = data_get($summary, 'events.pending_response_recovery_quality.p95_recovery_elapsed_ms');
            if (is_numeric($pendingResponseRecoveryP95) && (int) $pendingResponseRecoveryP95 > self::TARGETS['p95_pending_response_recovery_elapsed_ms']) {
                $failures[] = 'voice_pending_response_recovery_latency:'.(int) $pendingResponseRecoveryP95;
            }
        }

        $audioDoneReadySample = (int) data_get($summary, 'events.audio_done_readiness_quality.sample_size', 0);
        $minimumAudioDoneReady = (int) data_get($summary, 'events.minimum_audio_done_ready_count', self::TARGETS['minimum_audio_done_ready_count']);
        if ($turnSampleSize > 0 && $audioDoneReadySample < $minimumAudioDoneReady) {
            $failures[] = "voice_audio_done_ready_missing:{$audioDoneReadySample}/{$minimumAudioDoneReady}";
        }
        if ($audioDoneReadySample > 0) {
            $audioDoneIncomplete = (int) data_get($summary, 'events.audio_done_readiness_quality.incomplete_count', 0);
            if ($audioDoneIncomplete > self::TARGETS['audio_done_ready_incomplete_count']) {
                $failures[] = "voice_audio_done_ready_incomplete:{$audioDoneIncomplete}";
            }

            $audioDoneElapsedSample = (int) data_get($summary, 'events.audio_done_readiness_quality.elapsed_sample_size', 0);
            if ($audioDoneElapsedSample < $audioDoneReadySample) {
                $failures[] = "voice_audio_done_ready_telemetry_incomplete:{$audioDoneElapsedSample}/{$audioDoneReadySample}";
            }

            $audioDoneP95 = data_get($summary, 'events.audio_done_readiness_quality.p95_ready_elapsed_ms');
            if (is_numeric($audioDoneP95) && (int) $audioDoneP95 > self::TARGETS['p95_audio_done_ready_elapsed_ms']) {
                $failures[] = 'voice_audio_done_ready_latency:'.(int) $audioDoneP95;
            }
        }

        $backgroundCancelFailures = (int) data_get($summary, 'events.background_cancel_failure_count', 0);
        if ($backgroundCancelFailures > self::TARGETS['background_cancel_failure_count']) {
            $failures[] = "voice_background_cancel_failure:{$backgroundCancelFailures}";
        }

        $backgroundQueued = (int) data_get($summary, 'events.background_queue_quality.sample_size', 0);
        $minimumBackgroundQueued = (int) data_get($summary, 'events.minimum_background_queued_count', self::TARGETS['minimum_background_queued_count']);
        if ($turnSampleSize > 0 && $backgroundQueued < $minimumBackgroundQueued) {
            $failures[] = "voice_background_queue_missing:{$backgroundQueued}/{$minimumBackgroundQueued}";
        }
        if ($backgroundQueued > 0) {
            $backgroundQueueElapsedSample = (int) data_get($summary, 'events.background_queue_quality.elapsed_sample_size', 0);
            if ($backgroundQueueElapsedSample < $backgroundQueued) {
                $failures[] = "voice_background_queue_telemetry_incomplete:{$backgroundQueueElapsedSample}/{$backgroundQueued}";
            }

            $backgroundUnacknowledged = (int) data_get($summary, 'events.background_queue_quality.unacknowledged_count', 0);
            if ($backgroundUnacknowledged > self::TARGETS['background_unacknowledged_count']) {
                $failures[] = "voice_background_unacknowledged:{$backgroundUnacknowledged}";
            }

            $backgroundFallbacks = (int) data_get($summary, 'events.background_queue_quality.fallback_count', 0);
            if ($backgroundFallbacks > self::TARGETS['background_fallback_queue_count']) {
                $failures[] = "voice_background_fallback_queue:{$backgroundFallbacks}";
            }

            $backgroundQueueP95 = data_get($summary, 'events.background_queue_quality.p95_queue_elapsed_ms');
            if (is_numeric($backgroundQueueP95) && (int) $backgroundQueueP95 > self::TARGETS['p95_background_queue_elapsed_ms']) {
                $failures[] = 'voice_background_queue_latency:'.(int) $backgroundQueueP95;
            }
        }

        $backgroundCompleted = (int) data_get($summary, 'events.background_completed_count', 0);
        $minimumBackgroundCompleted = (int) data_get($summary, 'events.minimum_background_completed_count', self::TARGETS['minimum_background_completed_count']);
        if ($turnSampleSize > 0 && $backgroundCompleted < $minimumBackgroundCompleted) {
            $failures[] = "voice_background_completion_missing:{$backgroundCompleted}/{$minimumBackgroundCompleted}";
        }

        $backgroundProgress = (int) data_get($summary, 'events.background_progress_quality.sample_size', 0);
        $minimumBackgroundProgress = (int) data_get($summary, 'events.minimum_background_progress_prompt_count', self::TARGETS['minimum_background_progress_prompt_count']);
        if ($turnSampleSize > 0 && $backgroundProgress < $minimumBackgroundProgress) {
            $failures[] = "voice_background_progress_missing:{$backgroundProgress}/{$minimumBackgroundProgress}";
        }
        if ($backgroundProgress > 0) {
            $backgroundProgressElapsedSample = (int) data_get($summary, 'events.background_progress_quality.elapsed_sample_size', 0);
            if ($backgroundProgressElapsedSample < $backgroundProgress) {
                $failures[] = "voice_background_progress_telemetry_incomplete:{$backgroundProgressElapsedSample}/{$backgroundProgress}";
            }

            $backgroundProgressSpokenSample = (int) data_get($summary, 'events.background_progress_quality.spoken_sample_size', 0);
            if ($backgroundProgressSpokenSample < $backgroundProgress) {
                $failures[] = "voice_background_progress_spoken_telemetry_incomplete:{$backgroundProgressSpokenSample}/{$backgroundProgress}";
            }

            $firstProgressSample = (int) data_get($summary, 'events.background_progress_quality.first_progress_sample_size', 0);
            if ($firstProgressSample < 1) {
                $failures[] = 'voice_background_first_progress_missing';
            }

            $firstProgressP95 = data_get($summary, 'events.background_progress_quality.p95_first_progress_elapsed_ms');
            if (is_numeric($firstProgressP95) && (int) $firstProgressP95 > self::TARGETS['p95_background_first_progress_elapsed_ms']) {
                $failures[] = 'voice_background_progress_latency:'.(int) $firstProgressP95;
            }
            $backgroundProgressDuplicates = (int) data_get($summary, 'events.background_progress_quality.duplicate_count', 0);
            if ($backgroundProgressDuplicates > self::TARGETS['background_progress_duplicate_count']) {
                $failures[] = "voice_background_progress_duplicate:{$backgroundProgressDuplicates}";
            }
            $backgroundProgressBrevityViolations = (int) data_get($summary, 'events.background_progress_quality.brevity_violation_count', 0);
            if ($backgroundProgressBrevityViolations > self::TARGETS['background_progress_brevity_violation_count']) {
                $failures[] = "voice_background_progress_brevity:{$backgroundProgressBrevityViolations}";
            }
        }

        $backgroundFailures = (int) data_get($summary, 'events.background_failure_count', 0);
        if ($backgroundFailures > self::TARGETS['background_failure_count']) {
            $failures[] = "voice_background_failure:{$backgroundFailures}";
        }

        $backgroundCompletionDuplicates = (int) data_get($summary, 'events.background_completion_quality.duplicate_count', 0);
        if ($backgroundCompletionDuplicates > self::TARGETS['background_completion_duplicate_count']) {
            $failures[] = "voice_background_completion_duplicate:{$backgroundCompletionDuplicates}";
        }
        $backgroundCompletionBrevityViolations = (int) data_get($summary, 'events.background_completion_quality.brevity_violation_count', 0);
        if ($backgroundCompletionBrevityViolations > self::TARGETS['background_completion_brevity_violation_count']) {
            $failures[] = "voice_background_completion_brevity:{$backgroundCompletionBrevityViolations}";
        }
        $backgroundCompletionSpokenIncomplete = (int) data_get($summary, 'events.background_completion_quality.spoken_text_incomplete_count', 0);
        if ($backgroundCompletionSpokenIncomplete > self::TARGETS['background_completion_spoken_telemetry_incomplete_count']) {
            $failures[] = "voice_background_completion_spoken_telemetry_incomplete:{$backgroundCompletionSpokenIncomplete}";
        }

        $backgroundWatchFailures = (int) data_get($summary, 'events.background_watch_failure_count', 0);
        if ($backgroundWatchFailures > self::TARGETS['background_watch_failure_count']) {
            $failures[] = "voice_background_watch_failure:{$backgroundWatchFailures}";
        }

        $backgroundFailureMisleading = (int) data_get($summary, 'events.background_failure_truthfulness.misleading_count', 0);
        if ($backgroundFailureMisleading > self::TARGETS['background_failure_misleading_count']) {
            $failures[] = "voice_background_failure_misleading:{$backgroundFailureMisleading}";
        }

        $backgroundFailureTruthfulnessIncomplete = (int) data_get($summary, 'events.background_failure_truthfulness.incomplete_count', 0);
        if ($backgroundFailureTruthfulnessIncomplete > self::TARGETS['background_failure_truthfulness_incomplete_count']) {
            $failures[] = "voice_background_failure_truthfulness_incomplete:{$backgroundFailureTruthfulnessIncomplete}";
        }

        $backgroundSilentCompletions = (int) data_get($summary, 'events.background_silent_completion_count', 0);
        if ($backgroundSilentCompletions > self::TARGETS['background_silent_completion_count']) {
            $failures[] = "voice_background_silent_completion:{$backgroundSilentCompletions}";
        }

        $backgroundCancelsAfterVoiceClosed = (int) data_get($summary, 'events.background_cancelled_after_voice_closed_count', 0);
        if ($backgroundCancelsAfterVoiceClosed > self::TARGETS['background_cancelled_after_voice_closed_count']) {
            $failures[] = "voice_background_cancelled_after_voice_closed:{$backgroundCancelsAfterVoiceClosed}";
        }

        $inFlightCancelFailures = (int) data_get($summary, 'events.in_flight_cancel_failure_count', 0);
        if ($inFlightCancelFailures > self::TARGETS['in_flight_cancel_failure_count']) {
            $failures[] = "voice_in_flight_cancel_failure:{$inFlightCancelFailures}";
        }
        $interruptSignalFailures = (int) data_get($summary, 'events.interrupt_signal_failure_count', 0);
        if ($interruptSignalFailures > self::TARGETS['interrupt_signal_failure_count']) {
            $failures[] = "voice_interrupt_signal_failure:{$interruptSignalFailures}";
        }

        $realtimeErrors = (int) data_get($summary, 'events.realtime_error_count', 0);
        if ($realtimeErrors > self::TARGETS['realtime_error_count']) {
            $failures[] = "voice_realtime_error:{$realtimeErrors}";
        }

        $responseFailures = (int) data_get($summary, 'events.response_failure_count', 0);
        if ($responseFailures > self::TARGETS['response_failure_count']) {
            $failures[] = "voice_response_failure:{$responseFailures}";
        }
        $unansweredResponses = (int) data_get($summary, 'events.unanswered_response_quality.unanswered_count', 0);
        if ($unansweredResponses > self::TARGETS['unanswered_response_count']) {
            $failures[] = "voice_unanswered_response:{$unansweredResponses}";
        }

        $toolCallFailures = (int) data_get($summary, 'events.tool_call_failure_count', 0);
        if ($toolCallFailures > self::TARGETS['tool_call_failure_count']) {
            $failures[] = "voice_tool_call_failure:{$toolCallFailures}";
        }

        $toolFallbackFailures = (int) data_get($summary, 'events.tool_fallback_failure_count', 0);
        if ($toolFallbackFailures > self::TARGETS['tool_fallback_failure_count']) {
            $failures[] = "voice_tool_fallback_failure:{$toolFallbackFailures}";
        }

        $prematureCompletionClaims = (int) data_get($summary, 'events.premature_completion_claim_count', 0);
        if ($prematureCompletionClaims > self::TARGETS['premature_completion_claim_count']) {
            $failures[] = "voice_premature_completion_claim:{$prematureCompletionClaims}";
        }

        $unsupportedDirectAnswers = (int) data_get($summary, 'events.unsupported_direct_answer_count', 0);
        if ($unsupportedDirectAnswers > self::TARGETS['unsupported_direct_answer_count']) {
            $failures[] = "voice_unsupported_direct_answer:{$unsupportedDirectAnswers}";
        }
        $staleContextDirectAnswers = (int) data_get($summary, 'events.unsupported_direct_answer_quality.missing_fresh_context_count', 0);
        if ($staleContextDirectAnswers > self::TARGETS['stale_context_direct_answer_count']) {
            $failures[] = "voice_stale_context_direct_answer:{$staleContextDirectAnswers}";
        }
        $backgroundRequiredDirectAnswers = (int) data_get($summary, 'events.unsupported_direct_answer_quality.background_required_count', 0);
        if ($backgroundRequiredDirectAnswers > self::TARGETS['background_required_direct_answer_count']) {
            $failures[] = "voice_background_required_direct_answer:{$backgroundRequiredDirectAnswers}";
        }

        $transportFailures = (int) data_get($summary, 'events.transport_failure_count', 0);
        if ($transportFailures > self::TARGETS['transport_failure_count']) {
            $failures[] = "voice_transport_failure:{$transportFailures}";
        }

        $contextFreshnessFailures = (int) data_get($summary, 'events.context_freshness_failure_count', 0);
        if ($contextFreshnessFailures > self::TARGETS['context_freshness_failure_count']) {
            $failures[] = "voice_context_freshness_failure:{$contextFreshnessFailures}";
        }
        $contextFreshnessUnrecovered = (int) data_get($summary, 'events.context_refresh_quality.unrecovered_failure_count', 0);
        if ($contextFreshnessUnrecovered > self::TARGETS['context_freshness_unrecovered_count']) {
            $failures[] = "voice_context_freshness_unrecovered:{$contextFreshnessUnrecovered}";
        }
        $contextRefreshRecoveryCompletionsMissing = (int) data_get($summary, 'events.context_refresh_quality.recovery_completion_missing_count', 0);
        if ($contextRefreshRecoveryCompletionsMissing > self::TARGETS['context_refresh_recovery_completion_missing_count']) {
            $failures[] = "voice_context_refresh_recovery_completion_missing:{$contextRefreshRecoveryCompletionsMissing}";
        }

        $contextRefreshSuccesses = (int) data_get($summary, 'events.context_refresh_success_count', 0);
        $minimumContextRefreshSuccesses = (int) data_get($summary, 'events.minimum_context_refresh_success_count', self::TARGETS['minimum_context_refresh_success_count']);
        if ($turnSampleSize > 0 && $contextRefreshSuccesses < $minimumContextRefreshSuccesses) {
            $failures[] = "voice_context_refresh_missing:{$contextRefreshSuccesses}/{$minimumContextRefreshSuccesses}";
        }
        $contextRefreshSample = (int) data_get($summary, 'events.context_refresh_quality.sample_size', 0);
        if ($contextRefreshSample > 0) {
            $elapsedSample = (int) data_get($summary, 'events.context_refresh_quality.elapsed_sample_size', 0);
            if ($elapsedSample < $contextRefreshSample) {
                $failures[] = "voice_context_refresh_telemetry_incomplete:{$elapsedSample}/{$contextRefreshSample}";
            }

            $elapsedP95 = data_get($summary, 'events.context_refresh_quality.p95_elapsed_ms');
            if (is_numeric($elapsedP95) && (int) $elapsedP95 > self::TARGETS['p95_context_refresh_success_ms']) {
                $failures[] = 'voice_context_refresh_latency:'.(int) $elapsedP95;
            }
        }

        $bargeInSample = (int) data_get($summary, 'events.barge_in_quality.sample_size', 0);
        $minimumBargeIns = (int) data_get($summary, 'events.minimum_barge_in_count', self::TARGETS['minimum_barge_in_count']);
        if ($turnSampleSize > 0 && $bargeInSample < $minimumBargeIns) {
            $failures[] = "voice_barge_in_missing:{$bargeInSample}/{$minimumBargeIns}";
        }

        if ($bargeInSample > 0) {
            $internalPromptBargeIns = (int) data_get($summary, 'events.barge_in_quality.internal_prompt_count', 0);
            $minimumInternalPromptBargeIns = (int) data_get($summary, 'events.barge_in_quality.target_min_internal_prompt_count', self::TARGETS['minimum_internal_prompt_barge_in_count']);
            if ($internalPromptBargeIns < $minimumInternalPromptBargeIns) {
                $failures[] = "voice_internal_prompt_barge_in_missing:{$internalPromptBargeIns}/{$minimumInternalPromptBargeIns}";
            }

            $bargeInIncomplete = (int) data_get($summary, 'events.barge_in_quality.incomplete_count', 0);
            if ($bargeInIncomplete > self::TARGETS['barge_in_incomplete_count']) {
                $failures[] = "voice_barge_in_incomplete:{$bargeInIncomplete}";
            }

            $dispatchSample = (int) data_get($summary, 'events.barge_in_quality.dispatch_sample_size', 0);
            if ($dispatchSample < $bargeInSample) {
                $failures[] = "voice_barge_in_telemetry_incomplete:{$dispatchSample}/{$bargeInSample}";
            }

            $dispatchP95 = data_get($summary, 'events.barge_in_quality.p95_cancel_dispatch_ms');
            if (is_numeric($dispatchP95) && (int) $dispatchP95 > self::TARGETS['p95_barge_in_cancel_dispatch_ms']) {
                $failures[] = 'voice_barge_in_latency:'.(int) $dispatchP95;
            }
        }

        $bargeInRecoverySample = (int) data_get($summary, 'events.barge_in_recovery_quality.sample_size', 0);
        $minimumBargeInRecoveries = (int) data_get($summary, 'events.minimum_barge_in_recovery_count', self::TARGETS['minimum_barge_in_recovery_count']);
        if ($turnSampleSize > 0 && $bargeInRecoverySample < $minimumBargeInRecoveries) {
            $failures[] = "voice_barge_in_recovery_missing:{$bargeInRecoverySample}/{$minimumBargeInRecoveries}";
        }

        if ($bargeInRecoverySample > 0) {
            $recoveryIncomplete = (int) data_get($summary, 'events.barge_in_recovery_quality.incomplete_count', 0);
            if ($recoveryIncomplete > self::TARGETS['barge_in_recovery_incomplete_count']) {
                $failures[] = "voice_barge_in_recovery_incomplete:{$recoveryIncomplete}";
            }
            $missingResponseId = (int) data_get($summary, 'events.barge_in_recovery_quality.missing_response_id_count', 0);
            if ($missingResponseId > self::TARGETS['barge_in_recovery_missing_response_id_count']) {
                $failures[] = "voice_barge_in_recovery_missing_response_id:{$missingResponseId}";
            }

            $elapsedSample = (int) data_get($summary, 'events.barge_in_recovery_quality.elapsed_sample_size', 0);
            if ($elapsedSample < $bargeInRecoverySample) {
                $failures[] = "voice_barge_in_recovery_telemetry_incomplete:{$elapsedSample}/{$bargeInRecoverySample}";
            }

            $recoveryP95 = data_get($summary, 'events.barge_in_recovery_quality.p95_recovery_elapsed_ms');
            if (is_numeric($recoveryP95) && (int) $recoveryP95 > self::TARGETS['p95_barge_in_recovery_elapsed_ms']) {
                $failures[] = 'voice_barge_in_recovery_latency:'.(int) $recoveryP95;
            }
        }

        return array_values(array_unique($failures));
    }

    private function requirementGate(array $summary): array
    {
        $failures = $this->benchmarkFailures($summary);
        $summaryStatus = (string) data_get($summary, 'status', 'no_data');
        $gateStatus = $summaryStatus === 'no_data'
            ? 'no_data'
            : ($failures === [] && $summaryStatus === 'world_class' ? 'pass' : 'fail');

        return [
            'status' => $gateStatus,
            'benchmark' => 'siri_alexa_voice_responsiveness',
            'requirements' => [
                'latency' => [
                    'status' => $this->requirementStatus($summary, $failures, ['voice_metric_']),
                    'targets' => [
                        'p50_transcript_to_first_assistant_ms' => self::TARGETS['p50_transcript_to_first_assistant_ms'],
                        'p95_transcript_to_first_assistant_ms' => self::TARGETS['p95_transcript_to_first_assistant_ms'],
                        'p95_full_turn_ms' => self::TARGETS['p95_turn_completed_ms'],
                    ],
                    'observed' => [
                        'p50_transcript_to_first_assistant_ms' => data_get($summary, 'metrics.transcript_to_first_assistant_ms.p50_ms'),
                        'p95_transcript_to_first_assistant_ms' => data_get($summary, 'metrics.transcript_to_first_assistant_ms.p95_ms'),
                        'p95_full_turn_ms' => data_get($summary, 'metrics.turn_completed_ms.p95_ms'),
                        'turn_sample_size' => data_get($summary, 'window.turn_sample_size'),
                    ],
                    'failures' => $this->matchingFailures($failures, ['voice_metric_']),
                ],
                'barge_in_interruption_recovery' => [
                    'status' => $this->requirementStatus($summary, $failures, [
                        'voice_barge_in',
                        'voice_internal_prompt_barge_in',
                        'voice_in_flight_cancel',
                        'voice_interrupt_signal',
                        'voice_pending_response',
                        'voice_audio_done',
                    ]),
                    'targets' => [
                        'minimum_barge_in_count' => self::TARGETS['minimum_barge_in_count'],
                        'minimum_barge_in_recovery_count' => self::TARGETS['minimum_barge_in_recovery_count'],
                        'p95_barge_in_cancel_dispatch_ms' => self::TARGETS['p95_barge_in_cancel_dispatch_ms'],
                        'p95_barge_in_recovery_elapsed_ms' => self::TARGETS['p95_barge_in_recovery_elapsed_ms'],
                        'p95_pending_response_recovery_elapsed_ms' => self::TARGETS['p95_pending_response_recovery_elapsed_ms'],
                        'p95_audio_done_ready_elapsed_ms' => self::TARGETS['p95_audio_done_ready_elapsed_ms'],
                    ],
                    'observed' => [
                        'barge_in_sample_size' => data_get($summary, 'events.barge_in_quality.sample_size'),
                        'barge_in_recovery_sample_size' => data_get($summary, 'events.barge_in_recovery_quality.sample_size'),
                        'p95_barge_in_cancel_dispatch_ms' => data_get($summary, 'events.barge_in_quality.p95_cancel_dispatch_ms'),
                        'p95_barge_in_recovery_elapsed_ms' => data_get($summary, 'events.barge_in_recovery_quality.p95_recovery_elapsed_ms'),
                        'pending_response_recovered_count' => data_get($summary, 'events.pending_response_recovery_quality.recovered_count'),
                        'p95_pending_response_recovery_elapsed_ms' => data_get($summary, 'events.pending_response_recovery_quality.p95_recovery_elapsed_ms'),
                        'audio_done_ready_sample_size' => data_get($summary, 'events.audio_done_readiness_quality.sample_size'),
                        'p95_audio_done_ready_elapsed_ms' => data_get($summary, 'events.audio_done_readiness_quality.p95_ready_elapsed_ms'),
                    ],
                    'failures' => $this->matchingFailures($failures, [
                        'voice_barge_in',
                        'voice_internal_prompt_barge_in',
                        'voice_in_flight_cancel',
                        'voice_interrupt_signal',
                        'voice_pending_response',
                        'voice_audio_done',
                    ]),
                ],
                'fresh_context_accuracy' => [
                    'status' => $this->requirementStatus($summary, $failures, [
                        'voice_context_',
                        'voice_unsupported_direct_answer',
                        'voice_stale_context_direct_answer',
                        'voice_background_required_direct_answer',
                        'voice_premature_completion_claim',
                        'voice_unanswered_response',
                        'voice_tool_call_failure',
                        'voice_tool_fallback_failure',
                    ]),
                    'targets' => [
                        'minimum_context_refresh_success_count' => self::TARGETS['minimum_context_refresh_success_count'],
                        'p95_context_refresh_success_ms' => self::TARGETS['p95_context_refresh_success_ms'],
                        'unsupported_direct_answer_count' => self::TARGETS['unsupported_direct_answer_count'],
                        'stale_context_direct_answer_count' => self::TARGETS['stale_context_direct_answer_count'],
                        'background_required_direct_answer_count' => self::TARGETS['background_required_direct_answer_count'],
                        'unanswered_response_count' => self::TARGETS['unanswered_response_count'],
                    ],
                    'observed' => [
                        'context_refresh_success_count' => data_get($summary, 'events.context_refresh_success_count'),
                        'p95_context_refresh_success_ms' => data_get($summary, 'events.context_refresh_quality.p95_elapsed_ms'),
                        'context_freshness_failure_count' => data_get($summary, 'events.context_freshness_failure_count'),
                        'unsupported_direct_answer_count' => data_get($summary, 'events.unsupported_direct_answer_count'),
                        'stale_context_direct_answer_count' => data_get($summary, 'events.unsupported_direct_answer_quality.missing_fresh_context_count'),
                        'background_required_direct_answer_count' => data_get($summary, 'events.unsupported_direct_answer_quality.background_required_count'),
                        'unanswered_response_count' => data_get($summary, 'events.unanswered_response_quality.unanswered_count'),
                    ],
                    'failures' => $this->matchingFailures($failures, [
                        'voice_context_',
                        'voice_unsupported_direct_answer',
                        'voice_stale_context_direct_answer',
                        'voice_background_required_direct_answer',
                        'voice_premature_completion_claim',
                        'voice_unanswered_response',
                        'voice_tool_call_failure',
                        'voice_tool_fallback_failure',
                    ]),
                ],
                'contextual_followups' => [
                    'status' => $this->requirementStatus($summary, $failures, [
                        'voice_follow_up',
                        'voice_contextual_follow_up',
                        'voice_micro_follow_up',
                        'voice_untyped_contextual_follow_up',
                    ]),
                    'targets' => [
                        'minimum_follow_up_turn_count' => self::TARGETS['minimum_follow_up_turn_count'],
                        'minimum_contextual_follow_up_turn_count' => self::TARGETS['minimum_contextual_follow_up_turn_count'],
                        'minimum_micro_follow_up_kind_count' => self::TARGETS['minimum_micro_follow_up_kind_count'],
                        'minimum_micro_follow_up_ready_kind_count' => self::TARGETS['minimum_micro_follow_up_ready_kind_count'],
                        'p95_follow_up_ready_elapsed_ms' => self::TARGETS['p95_follow_up_ready_elapsed_ms'],
                    ],
                    'observed' => [
                        'follow_up_turn_count' => data_get($summary, 'conversation.follow_up_turn_count'),
                        'contextual_follow_up_turn_count' => data_get($summary, 'conversation.contextual_follow_up_turn_count'),
                        'micro_follow_up_kind_counts' => data_get($summary, 'conversation.micro_follow_up_kind_counts'),
                        'contextual_follow_up_resolution_sample_size' => data_get($summary, 'contextual_follow_up_resolution.sample_size'),
                        'follow_up_ready_sample_size' => data_get($summary, 'events.follow_up_readiness_quality.sample_size'),
                        'micro_follow_up_ready_kind_counts' => data_get($summary, 'events.follow_up_readiness_quality.micro_follow_up_ready_kind_counts'),
                        'p95_follow_up_ready_elapsed_ms' => data_get($summary, 'events.follow_up_readiness_quality.p95_ready_elapsed_ms'),
                    ],
                    'failures' => $this->matchingFailures($failures, [
                        'voice_follow_up',
                        'voice_contextual_follow_up',
                        'voice_micro_follow_up',
                        'voice_untyped_contextual_follow_up',
                    ]),
                ],
                'natural_voice' => [
                    'status' => $this->requirementStatus($summary, $failures, [
                        'voice_brevity',
                        'voice_spoken_',
                    ]),
                    'targets' => [
                        'spoken_brevity_violation_rate' => self::TARGETS['spoken_brevity_violation_rate'],
                        'minimum_spoken_naturalness_sample_count' => self::TARGETS['minimum_spoken_naturalness_sample_count'],
                        'spoken_naturalness_violation_count' => self::TARGETS['spoken_naturalness_violation_count'],
                        'spoken_duplicate_response_count' => self::TARGETS['spoken_duplicate_response_count'],
                    ],
                    'observed' => [
                        'brevity_status' => data_get($summary, 'speech.brevity.status'),
                        'brevity_violation_rate' => data_get($summary, 'speech.brevity.violation_rate'),
                        'naturalness_sample_size' => data_get($summary, 'speech.naturalness.sample_size'),
                        'naturalness_violation_count' => data_get($summary, 'speech.naturalness.violation_count'),
                        'duplicate_response_count' => data_get($summary, 'speech.naturalness.duplicate_response_count'),
                    ],
                    'failures' => $this->matchingFailures($failures, [
                        'voice_brevity',
                        'voice_spoken_',
                    ]),
                ],
                'live_session_reliability' => [
                    'status' => $this->requirementStatus($summary, $failures, [
                        'voice_insufficient_sample',
                        'voice_usage_telemetry_incomplete',
                        'voice_realtime_error',
                        'voice_response_failure',
                        'voice_transport_failure',
                        'voice_background_',
                    ]),
                    'targets' => [
                        'minimum_turns' => data_get($summary, 'window.minimum_turns'),
                        'realtime_error_count' => self::TARGETS['realtime_error_count'],
                        'response_failure_count' => self::TARGETS['response_failure_count'],
                        'transport_failure_count' => self::TARGETS['transport_failure_count'],
                        'minimum_background_queued_count' => self::TARGETS['minimum_background_queued_count'],
                        'minimum_background_completed_count' => self::TARGETS['minimum_background_completed_count'],
                        'minimum_background_progress_prompt_count' => self::TARGETS['minimum_background_progress_prompt_count'],
                    ],
                    'observed' => [
                        'turn_sample_size' => data_get($summary, 'window.turn_sample_size'),
                        'event_sample_size' => data_get($summary, 'window.event_sample_size'),
                        'usage_sample_size' => data_get($summary, 'telemetry.usage_sample_size'),
                        'realtime_error_count' => data_get($summary, 'events.realtime_error_count'),
                        'response_failure_count' => data_get($summary, 'events.response_failure_count'),
                        'transport_failure_count' => data_get($summary, 'events.transport_failure_count'),
                        'background_queue_sample_size' => data_get($summary, 'events.background_queue_quality.sample_size'),
                        'background_completed_count' => data_get($summary, 'events.background_completed_count'),
                        'background_progress_sample_size' => data_get($summary, 'events.background_progress_quality.sample_size'),
                    ],
                    'failures' => $this->matchingFailures($failures, [
                        'voice_insufficient_sample',
                        'voice_usage_telemetry_incomplete',
                        'voice_realtime_error',
                        'voice_response_failure',
                        'voice_transport_failure',
                        'voice_background_',
                    ]),
                ],
            ],
            'failures' => $failures,
        ];
    }

    /**
     * @param list<string> $failures
     * @param list<string> $prefixes
     */
    private function requirementStatus(array $summary, array $failures, array $prefixes): string
    {
        if ((string) data_get($summary, 'status', 'no_data') === 'no_data') {
            return 'no_data';
        }

        return $this->matchingFailures($failures, $prefixes) === [] ? 'pass' : 'fail';
    }

    /**
     * @param list<string> $failures
     * @param list<string> $prefixes
     *
     * @return list<string>
     */
    private function matchingFailures(array $failures, array $prefixes): array
    {
        return array_values(array_filter(
            $failures,
            fn (string $failure): bool => collect($prefixes)->contains(
                fn (string $prefix): bool => str_starts_with($failure, $prefix),
            ),
        ));
    }

    private function metricSummary(Collection $logs, string $metadataKey, int $targetP50, int $targetP95): array
    {
        $values = $logs
            ->map(fn (AiUsageLog $log): mixed => data_get($log->metadata ?? [], $metadataKey))
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): int => (int) $value)
            ->sort()
            ->values();

        if ($values->isEmpty()) {
            return [
                'status' => 'missing',
                'sample_size' => 0,
                'target_p50_ms' => $targetP50,
                'target_p95_ms' => $targetP95,
            ];
        }

        $p50 = $this->nearestRankPercentile($values, 0.50);
        $p95 = $this->nearestRankPercentile($values, 0.95);

        return [
            'status' => $p50 <= $targetP50 && $p95 <= $targetP95 ? 'pass' : 'fail',
            'sample_size' => $values->count(),
            'avg_ms' => (int) round($values->avg()),
            'p50_ms' => $p50,
            'p95_ms' => $p95,
            'min_ms' => (int) $values->first(),
            'max_ms' => (int) $values->last(),
            'target_p50_ms' => $targetP50,
            'target_p95_ms' => $targetP95,
        ];
    }

    private function eventCounts(Collection $events): array
    {
        return $events
            ->map(fn (AiUsageLog $log): string => (string) data_get($log->metadata ?? [], 'event_type', 'unknown'))
            ->filter(fn (string $eventType): bool => $eventType !== '')
            ->countBy()
            ->sortKeys()
            ->all();
    }

    private function eventHealth(array $eventCounts, int $turnCount): array
    {
        $bargeIns = (int) ($eventCounts['flutter_realtime_barge_in'] ?? 0);
        $backgroundCancels = (int) ($eventCounts['realtime_background_cancelled_by_voice'] ?? 0);
        $backgroundProgressPrompts = (int) ($eventCounts['flutter_realtime_progress_prompt'] ?? 0);
        $backgroundProgressSpokenPrompts = (int) ($eventCounts['flutter_realtime_progress_prompt_spoken'] ?? 0);
        $backgroundProgressSkipped = (int) ($eventCounts['flutter_realtime_progress_prompt_skipped'] ?? 0);
        $backgroundCompletionDeferred = (int) ($eventCounts['realtime_background_completion_deferred'] ?? 0);
        $idleTimeouts = (int) ($eventCounts['flutter_realtime_followup_idle_timeout'] ?? 0);
        $followUpReadies = (int) ($eventCounts['flutter_realtime_followup_ready'] ?? 0);
        $pendingResponseDeferred = (int) ($eventCounts['flutter_realtime_pending_response_deferred_by_speech'] ?? 0);
        $pendingResponseRecovered = (int) ($eventCounts['flutter_realtime_pending_response_recovered_after_non_actionable_speech'] ?? 0);
        $audioDoneReadies = (int) ($eventCounts['flutter_realtime_audio_done_ready'] ?? 0);
        $preResponseRefreshSuccesses = (int) ($eventCounts['dashboard_context_pre_response_success'] ?? 0);
        $webrtcFailures = (int) ($eventCounts['webrtc_connection_failure'] ?? 0);
        $iceFailures = (int) ($eventCounts['ice_webrtc_connection_failure'] ?? 0);
        $dataChannelCloses = (int) ($eventCounts['data_channel_close'] ?? 0);
        $preResponseRefreshTimeouts = (int) ($eventCounts['dashboard_context_pre_response_timeout'] ?? 0);
        $preResponseRefreshFailures = (int) ($eventCounts['dashboard_context_pre_response_failure'] ?? 0);
        $preResponseRefreshAckTimeouts = (int) ($eventCounts['dashboard_context_pre_response_ack_timeout'] ?? 0);
        $preResponseRefreshRoutedToBackground = (int) ($eventCounts['dashboard_context_pre_response_routed_to_background'] ?? 0);
        $dashboardRefreshFailures = (int) ($eventCounts['dashboard_context_refresh_failure'] ?? 0);
        $unsupportedDirectAnswerQueued = (int) ($eventCounts['flutter_realtime_unsupported_direct_answer_queued'] ?? 0);

        return [
            'barge_in_count' => $bargeIns,
            'barge_in_rate' => $this->eventRate($bargeIns, $turnCount),
            'background_cancel_count' => $backgroundCancels,
            'background_cancel_rate' => $this->eventRate($backgroundCancels, $turnCount),
            'background_progress_prompt_count' => $backgroundProgressPrompts,
            'background_progress_prompt_rate' => $this->eventRate($backgroundProgressPrompts, $turnCount),
            'background_progress_prompt_spoken_count' => $backgroundProgressSpokenPrompts,
            'background_progress_prompt_spoken_rate' => $this->eventRate($backgroundProgressSpokenPrompts, $turnCount),
            'background_progress_prompt_skipped_count' => $backgroundProgressSkipped,
            'background_progress_prompt_skipped_rate' => $this->eventRate($backgroundProgressSkipped, $turnCount),
            'background_completion_deferred_count' => $backgroundCompletionDeferred,
            'background_completion_deferred_rate' => $this->eventRate($backgroundCompletionDeferred, $turnCount),
            'follow_up_idle_timeout_count' => $idleTimeouts,
            'follow_up_idle_timeout_rate' => $this->eventRate($idleTimeouts, $turnCount),
            'follow_up_ready_count' => $followUpReadies,
            'follow_up_ready_rate' => $this->eventRate($followUpReadies, $turnCount),
            'pending_response_deferred_count' => $pendingResponseDeferred,
            'pending_response_deferred_rate' => $this->eventRate($pendingResponseDeferred, $turnCount),
            'pending_response_recovered_count' => $pendingResponseRecovered,
            'pending_response_recovered_rate' => $this->eventRate($pendingResponseRecovered, $turnCount),
            'audio_done_ready_count' => $audioDoneReadies,
            'audio_done_ready_rate' => $this->eventRate($audioDoneReadies, $turnCount),
            'dashboard_context_pre_response_success_count' => $preResponseRefreshSuccesses,
            'dashboard_context_pre_response_success_rate' => $this->eventRate($preResponseRefreshSuccesses, $turnCount),
            'webrtc_failure_count' => $webrtcFailures,
            'webrtc_failure_rate' => $this->eventRate($webrtcFailures, $turnCount),
            'ice_failure_count' => $iceFailures,
            'ice_failure_rate' => $this->eventRate($iceFailures, $turnCount),
            'data_channel_close_count' => $dataChannelCloses,
            'data_channel_close_rate' => $this->eventRate($dataChannelCloses, $turnCount),
            'dashboard_context_pre_response_timeout_count' => $preResponseRefreshTimeouts,
            'dashboard_context_pre_response_timeout_rate' => $this->eventRate($preResponseRefreshTimeouts, $turnCount),
            'dashboard_context_pre_response_failure_count' => $preResponseRefreshFailures,
            'dashboard_context_pre_response_failure_rate' => $this->eventRate($preResponseRefreshFailures, $turnCount),
            'dashboard_context_pre_response_ack_timeout_count' => $preResponseRefreshAckTimeouts,
            'dashboard_context_pre_response_ack_timeout_rate' => $this->eventRate($preResponseRefreshAckTimeouts, $turnCount),
            'dashboard_context_pre_response_routed_to_background_count' => $preResponseRefreshRoutedToBackground,
            'dashboard_context_pre_response_routed_to_background_rate' => $this->eventRate($preResponseRefreshRoutedToBackground, $turnCount),
            'dashboard_context_refresh_failure_count' => $dashboardRefreshFailures,
            'dashboard_context_refresh_failure_rate' => $this->eventRate($dashboardRefreshFailures, $turnCount),
            'unsupported_direct_answer_queued_count' => $unsupportedDirectAnswerQueued,
            'unsupported_direct_answer_queued_rate' => $this->eventRate($unsupportedDirectAnswerQueued, $turnCount),
        ];
    }

    private function unsupportedDirectAnswerSummary(Collection $events): array
    {
        $answers = $events
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'event_type') === 'flutter_realtime_unsupported_direct_answer')
            ->values();
        $reasonCounts = $answers
            ->map(fn (AiUsageLog $log): string => (string) data_get($log->metadata ?? [], 'details.reason', 'unknown'))
            ->filter(fn (string $reason): bool => $reason !== '')
            ->countBy()
            ->sortKeys();
        $examples = $answers
            ->map(function (AiUsageLog $log): array {
                $details = (array) data_get($log->metadata ?? [], 'details', []);

                return [
                    'usage_log_id' => $log->id,
                    'reason' => (string) ($details['reason'] ?? 'unknown'),
                    'context_refresh_succeeded' => (bool) ($details['context_refresh_succeeded'] ?? false),
                    'concrete_answer' => (bool) ($details['concrete_answer'] ?? false),
                    'user_content' => substr(trim((string) preg_replace('/\s+/', ' ', (string) ($details['user_content'] ?? ''))), 0, 180),
                    'assistant_text' => substr(trim((string) preg_replace('/\s+/', ' ', (string) ($details['assistant_text'] ?? ''))), 0, 180),
                ];
            })
            ->values();

        return [
            'status' => $answers->isEmpty() ? 'pass' : 'fail',
            'sample_size' => $answers->count(),
            'missing_fresh_context_count' => (int) ($reasonCounts['missing_fresh_context'] ?? 0),
            'background_required_count' => (int) ($reasonCounts['background_required'] ?? 0),
            'unknown_reason_count' => (int) ($reasonCounts['unknown'] ?? 0),
            'target_missing_fresh_context_count' => self::TARGETS['stale_context_direct_answer_count'],
            'target_background_required_count' => self::TARGETS['background_required_direct_answer_count'],
            'reason_counts' => $reasonCounts->all(),
            'examples' => $examples->take(5)->all(),
        ];
    }

    private function unansweredResponseSummary(Collection $events): array
    {
        $responses = $events
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'event_type') === 'flutter_realtime_response_done')
            ->values();
        $unanswered = $responses
            ->filter(fn (AiUsageLog $log): bool => (bool) data_get($log->metadata ?? [], 'details.assistant_answered', false) === false)
            ->values();
        $examples = $unanswered
            ->map(function (AiUsageLog $log): array {
                $details = (array) data_get($log->metadata ?? [], 'details', []);

                return [
                    'usage_log_id' => $log->id,
                    'user_content' => substr(trim((string) preg_replace('/\s+/', ' ', (string) ($details['user_content'] ?? ''))), 0, 180),
                    'assistant_text' => substr(trim((string) preg_replace('/\s+/', ' ', (string) ($details['assistant_text'] ?? ''))), 0, 180),
                    'function_call_count' => count((array) ($details['function_calls'] ?? [])),
                    'is_follow_up_turn' => (bool) ($details['is_follow_up_turn'] ?? false),
                    'is_contextual_follow_up_turn' => (bool) ($details['is_contextual_follow_up_turn'] ?? false),
                ];
            })
            ->values();

        return [
            'status' => $unanswered->isEmpty() ? 'pass' : 'fail',
            'sample_size' => $responses->count(),
            'unanswered_count' => $unanswered->count(),
            'target_unanswered_count' => self::TARGETS['unanswered_response_count'],
            'examples' => $examples->take(5)->all(),
        ];
    }

    private function bargeInSummary(Collection $events): array
    {
        $bargeIns = $events
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'event_type') === 'flutter_realtime_barge_in')
            ->values();

        if ($bargeIns->isEmpty()) {
            return [
                'status' => 'no_data',
                'sample_size' => 0,
                'dispatch_sample_size' => 0,
                'incomplete_count' => 0,
                'dispatch_error_count' => 0,
                'internal_prompt_count' => 0,
                'dispatch_errors' => [],
                'target_p95_cancel_dispatch_ms' => self::TARGETS['p95_barge_in_cancel_dispatch_ms'],
                'target_incomplete_count' => self::TARGETS['barge_in_incomplete_count'],
                'target_min_internal_prompt_count' => self::TARGETS['minimum_internal_prompt_barge_in_count'],
            ];
        }

        $dispatchValues = $bargeIns
            ->map(fn (AiUsageLog $log): mixed => data_get($log->metadata ?? [], 'details.cancel_dispatch_ms'))
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): int => (int) $value)
            ->sort()
            ->values();
        $incomplete = $bargeIns
            ->filter(function (AiUsageLog $log): bool {
                $details = $log->metadata['details'] ?? [];
                $cancelSent = (bool) data_get($details, 'cancel_sent', false);
                $outputAudioCleared = (bool) data_get($details, 'output_audio_cleared', false);
                $truncateAttempted = (bool) data_get($details, 'truncate_attempted', false);
                $truncateSent = (bool) data_get($details, 'truncate_sent', false);

                return ! $cancelSent || ! $outputAudioCleared || ($truncateAttempted && ! $truncateSent);
            })
            ->count();
        $dispatchErrors = $bargeIns
            ->map(function (AiUsageLog $log): ?array {
                $details = $log->metadata['details'] ?? [];
                $dispatchError = trim((string) data_get($details, 'dispatch_error', ''));
                if ($dispatchError === '') {
                    return null;
                }

                return [
                    'usage_log_id' => $log->id,
                    'dispatch_error' => substr($dispatchError, 0, 160),
                    'cancel_sent' => (bool) data_get($details, 'cancel_sent', false),
                    'output_audio_cleared' => (bool) data_get($details, 'output_audio_cleared', false),
                    'truncate_attempted' => (bool) data_get($details, 'truncate_attempted', false),
                    'truncate_sent' => (bool) data_get($details, 'truncate_sent', false),
                ];
            })
            ->filter()
            ->values();
        $internalPromptCount = $bargeIns
            ->filter(fn (AiUsageLog $log): bool => (bool) data_get($log->metadata ?? [], 'details.interrupted_internal_prompt', false))
            ->count();
        $p95 = $dispatchValues->isEmpty() ? null : $this->nearestRankPercentile($dispatchValues, 0.95);
        $status = $incomplete === 0
            && $dispatchValues->count() === $bargeIns->count()
            && $internalPromptCount >= self::TARGETS['minimum_internal_prompt_barge_in_count']
            && $p95 !== null
            && $p95 <= self::TARGETS['p95_barge_in_cancel_dispatch_ms']
                ? 'pass'
                : 'fail';

        return [
            'status' => $status,
            'sample_size' => $bargeIns->count(),
            'dispatch_sample_size' => $dispatchValues->count(),
            'incomplete_count' => $incomplete,
            'dispatch_error_count' => $dispatchErrors->count(),
            'internal_prompt_count' => $internalPromptCount,
            'avg_cancel_dispatch_ms' => $dispatchValues->isEmpty() ? null : (int) round($dispatchValues->avg()),
            'p95_cancel_dispatch_ms' => $p95,
            'dispatch_errors' => $dispatchErrors->take(5)->values()->all(),
            'target_p95_cancel_dispatch_ms' => self::TARGETS['p95_barge_in_cancel_dispatch_ms'],
            'target_incomplete_count' => self::TARGETS['barge_in_incomplete_count'],
            'target_min_internal_prompt_count' => self::TARGETS['minimum_internal_prompt_barge_in_count'],
        ];
    }

    private function bargeInRecoverySummary(Collection $events): array
    {
        $recoveryEvents = [
            'flutter_realtime_barge_in_recovered',
            'flutter_realtime_barge_in_recovery_failed',
        ];
        $recoveries = $events
            ->filter(fn (AiUsageLog $log): bool => in_array(data_get($log->metadata ?? [], 'event_type'), $recoveryEvents, true))
            ->values();

        if ($recoveries->isEmpty()) {
            return [
                'status' => 'no_data',
                'sample_size' => 0,
                'elapsed_sample_size' => 0,
                'incomplete_count' => 0,
                'failed_count' => 0,
                'missing_response_id_count' => 0,
                'target_p95_recovery_elapsed_ms' => self::TARGETS['p95_barge_in_recovery_elapsed_ms'],
                'target_incomplete_count' => self::TARGETS['barge_in_recovery_incomplete_count'],
                'target_missing_response_id_count' => self::TARGETS['barge_in_recovery_missing_response_id_count'],
            ];
        }

        $elapsedValues = $recoveries
            ->map(fn (AiUsageLog $log): mixed => data_get($log->metadata ?? [], 'details.recovery_elapsed_ms'))
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): int => (int) $value)
            ->sort()
            ->values();
        $missingResponseId = $recoveries
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'event_type') === 'flutter_realtime_barge_in_recovered'
                && trim((string) data_get($log->metadata ?? [], 'details.response_id', '')) === '')
            ->count();
        $incomplete = $recoveries
            ->filter(function (AiUsageLog $log): bool {
                $metadata = $log->metadata ?? [];

                return data_get($metadata, 'event_type') === 'flutter_realtime_barge_in_recovery_failed'
                    || ! (bool) data_get($metadata, 'details.assistant_answered', false)
                    || ! (bool) data_get($metadata, 'details.has_user_content', false)
                    || (data_get($metadata, 'event_type') === 'flutter_realtime_barge_in_recovered'
                        && trim((string) data_get($metadata, 'details.response_id', '')) === '');
            })
            ->count();
        $failed = $recoveries
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'event_type') === 'flutter_realtime_barge_in_recovery_failed')
            ->count();
        $p95 = $elapsedValues->isEmpty() ? null : $this->nearestRankPercentile($elapsedValues, 0.95);
        $status = $incomplete === 0
            && $elapsedValues->count() === $recoveries->count()
            && $p95 !== null
            && $p95 <= self::TARGETS['p95_barge_in_recovery_elapsed_ms']
                ? 'pass'
                : 'fail';

        return [
            'status' => $status,
            'sample_size' => $recoveries->count(),
            'elapsed_sample_size' => $elapsedValues->count(),
            'incomplete_count' => $incomplete,
            'failed_count' => $failed,
            'missing_response_id_count' => $missingResponseId,
            'avg_recovery_elapsed_ms' => $elapsedValues->isEmpty() ? null : (int) round($elapsedValues->avg()),
            'p95_recovery_elapsed_ms' => $p95,
            'target_p95_recovery_elapsed_ms' => self::TARGETS['p95_barge_in_recovery_elapsed_ms'],
            'target_incomplete_count' => self::TARGETS['barge_in_recovery_incomplete_count'],
            'target_missing_response_id_count' => self::TARGETS['barge_in_recovery_missing_response_id_count'],
        ];
    }

    private function followUpReadinessSummary(Collection $events): array
    {
        $readies = $events
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'event_type') === 'flutter_realtime_followup_ready')
            ->values();

        if ($readies->isEmpty()) {
            return [
                'status' => 'no_data',
                'sample_size' => 0,
                'elapsed_sample_size' => 0,
                'incomplete_count' => 0,
                'micro_follow_up_ready_kind_count' => 0,
                'micro_follow_up_ready_kind_counts' => [],
                'untyped_contextual_follow_up_ready_count' => 0,
                'target_p95_ready_elapsed_ms' => self::TARGETS['p95_follow_up_ready_elapsed_ms'],
                'target_incomplete_count' => self::TARGETS['follow_up_ready_incomplete_count'],
                'target_min_micro_follow_up_ready_kind_count' => self::TARGETS['minimum_micro_follow_up_ready_kind_count'],
                'target_untyped_contextual_follow_up_ready_count' => self::TARGETS['untyped_contextual_follow_up_ready_count'],
            ];
        }

        $elapsedValues = $readies
            ->map(fn (AiUsageLog $log): mixed => data_get($log->metadata ?? [], 'details.ready_elapsed_ms'))
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): int => (int) $value)
            ->sort()
            ->values();
        $incomplete = $readies
            ->filter(function (AiUsageLog $log): bool {
                $details = $log->metadata['details'] ?? [];

                return ! (bool) data_get($details, 'conversation_active', false)
                    || ! (bool) data_get($details, 'mic_enabled', false)
                    || (int) data_get($details, 'microphone_track_count', 0) < 1;
            })
            ->count();
        $knownMicroKinds = ['confirmation', 'decline', 'correction', 'continuation', 'reference'];
        $microReadyKindCounts = $readies
            ->map(fn (AiUsageLog $log): string => strtolower(trim((string) data_get($log->metadata ?? [], 'details.contextual_follow_up_kind', ''))))
            ->filter(fn (string $kind): bool => in_array($kind, $knownMicroKinds, true))
            ->countBy()
            ->sortKeys()
            ->all();
        $microReadyKindCount = collect($microReadyKindCounts)->filter(fn (int $count): bool => $count > 0)->count();
        $contextualReadyCount = $readies
            ->filter(fn (AiUsageLog $log): bool => (bool) data_get($log->metadata ?? [], 'details.is_contextual_follow_up_turn', false))
            ->count();
        $untypedContextualReadyCount = max(0, $contextualReadyCount - array_sum($microReadyKindCounts));
        $p95 = $elapsedValues->isEmpty() ? null : $this->nearestRankPercentile($elapsedValues, 0.95);
        $status = $incomplete === 0
            && $elapsedValues->count() === $readies->count()
            && $p95 !== null
            && $p95 <= self::TARGETS['p95_follow_up_ready_elapsed_ms']
            && $microReadyKindCount >= self::TARGETS['minimum_micro_follow_up_ready_kind_count']
            && $untypedContextualReadyCount <= self::TARGETS['untyped_contextual_follow_up_ready_count']
                ? 'pass'
                : 'fail';

        return [
            'status' => $status,
            'sample_size' => $readies->count(),
            'elapsed_sample_size' => $elapsedValues->count(),
            'incomplete_count' => $incomplete,
            'micro_follow_up_ready_kind_count' => $microReadyKindCount,
            'micro_follow_up_ready_kind_counts' => $microReadyKindCounts,
            'untyped_contextual_follow_up_ready_count' => $untypedContextualReadyCount,
            'avg_ready_elapsed_ms' => $elapsedValues->isEmpty() ? null : (int) round($elapsedValues->avg()),
            'p95_ready_elapsed_ms' => $p95,
            'target_p95_ready_elapsed_ms' => self::TARGETS['p95_follow_up_ready_elapsed_ms'],
            'target_incomplete_count' => self::TARGETS['follow_up_ready_incomplete_count'],
            'target_min_micro_follow_up_ready_kind_count' => self::TARGETS['minimum_micro_follow_up_ready_kind_count'],
            'target_untyped_contextual_follow_up_ready_count' => self::TARGETS['untyped_contextual_follow_up_ready_count'],
        ];
    }

    private function pendingResponseRecoverySummary(Collection $events): array
    {
        $deferred = $events
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'event_type') === 'flutter_realtime_pending_response_deferred_by_speech')
            ->values();
        $recoveries = $events
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'event_type') === 'flutter_realtime_pending_response_recovered_after_non_actionable_speech')
            ->values();

        if ($deferred->isEmpty() && $recoveries->isEmpty()) {
            return [
                'status' => 'no_data',
                'sample_size' => 0,
                'recovered_count' => 0,
                'elapsed_sample_size' => 0,
                'unrecovered_count' => 0,
                'target_p95_recovery_elapsed_ms' => self::TARGETS['p95_pending_response_recovery_elapsed_ms'],
                'target_unrecovered_count' => self::TARGETS['pending_response_unrecovered_count'],
            ];
        }

        $elapsedValues = $recoveries
            ->map(fn (AiUsageLog $log): mixed => data_get($log->metadata ?? [], 'details.recovery_elapsed_ms'))
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): int => (int) $value)
            ->sort()
            ->values();
        $unrecovered = max(0, $deferred->count() - $recoveries->count());
        $p95 = $elapsedValues->isEmpty() ? null : $this->nearestRankPercentile($elapsedValues, 0.95);
        $status = $unrecovered === 0
            && $recoveries->count() >= $deferred->count()
            && $elapsedValues->count() === $recoveries->count()
            && $p95 !== null
            && $p95 <= self::TARGETS['p95_pending_response_recovery_elapsed_ms']
                ? 'pass'
                : 'fail';

        return [
            'status' => $status,
            'sample_size' => $deferred->count(),
            'recovered_count' => $recoveries->count(),
            'elapsed_sample_size' => $elapsedValues->count(),
            'unrecovered_count' => $unrecovered,
            'avg_recovery_elapsed_ms' => $elapsedValues->isEmpty() ? null : (int) round($elapsedValues->avg()),
            'p95_recovery_elapsed_ms' => $p95,
            'target_p95_recovery_elapsed_ms' => self::TARGETS['p95_pending_response_recovery_elapsed_ms'],
            'target_unrecovered_count' => self::TARGETS['pending_response_unrecovered_count'],
        ];
    }

    private function audioDoneReadinessSummary(Collection $events): array
    {
        $readies = $events
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'event_type') === 'flutter_realtime_audio_done_ready')
            ->values();

        if ($readies->isEmpty()) {
            return [
                'status' => 'no_data',
                'sample_size' => 0,
                'elapsed_sample_size' => 0,
                'incomplete_count' => 0,
                'target_p95_ready_elapsed_ms' => self::TARGETS['p95_audio_done_ready_elapsed_ms'],
                'target_incomplete_count' => self::TARGETS['audio_done_ready_incomplete_count'],
            ];
        }

        $elapsedValues = $readies
            ->map(fn (AiUsageLog $log): mixed => data_get($log->metadata ?? [], 'details.ready_elapsed_ms'))
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): int => (int) $value)
            ->sort()
            ->values();
        $incomplete = $readies
            ->filter(function (AiUsageLog $log): bool {
                $details = $log->metadata['details'] ?? [];
                $status = (string) data_get($details, 'status', '');

                return ! (bool) data_get($details, 'conversation_active', false)
                    || ! (bool) data_get($details, 'mic_enabled', false)
                    || (int) data_get($details, 'microphone_track_count', 0) < 1
                    || (bool) data_get($details, 'transcription_only_release_pending', false)
                    || ! in_array($status, ['listening', 'working...'], true);
            })
            ->count();
        $p95 = $elapsedValues->isEmpty() ? null : $this->nearestRankPercentile($elapsedValues, 0.95);
        $status = $incomplete === 0
            && $elapsedValues->count() === $readies->count()
            && $p95 !== null
            && $p95 <= self::TARGETS['p95_audio_done_ready_elapsed_ms']
                ? 'pass'
                : 'fail';

        return [
            'status' => $status,
            'sample_size' => $readies->count(),
            'elapsed_sample_size' => $elapsedValues->count(),
            'incomplete_count' => $incomplete,
            'avg_ready_elapsed_ms' => $elapsedValues->isEmpty() ? null : (int) round($elapsedValues->avg()),
            'p95_ready_elapsed_ms' => $p95,
            'target_p95_ready_elapsed_ms' => self::TARGETS['p95_audio_done_ready_elapsed_ms'],
            'target_incomplete_count' => self::TARGETS['audio_done_ready_incomplete_count'],
        ];
    }

    private function contextRefreshSummary(Collection $events): array
    {
        $attemptEvents = [
            'dashboard_context_pre_response_success',
            'dashboard_context_pre_response_timeout',
            'dashboard_context_pre_response_failure',
            'dashboard_context_pre_response_ack_timeout',
        ];
        $attempts = $events
            ->filter(fn (AiUsageLog $log): bool => in_array(data_get($log->metadata ?? [], 'event_type'), $attemptEvents, true))
            ->values();
        $successes = $events
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'event_type') === 'dashboard_context_pre_response_success')
            ->values();
        $timeoutCount = $events
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'event_type') === 'dashboard_context_pre_response_timeout')
            ->count();
        $failureCount = $events
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'event_type') === 'dashboard_context_pre_response_failure')
            ->count();
        $ackTimeoutCount = $events
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'event_type') === 'dashboard_context_pre_response_ack_timeout')
            ->count();
        $routedToBackgroundCount = $events
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'event_type') === 'dashboard_context_pre_response_routed_to_background')
            ->count();
        $backgroundQueuedRecoveryCount = $events
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'event_type') === 'realtime_background_queued'
                && (bool) data_get($log->metadata ?? [], 'details.context_refresh_recovery', false))
            ->count();
        $backgroundQueuedRecoveryRunIds = $events
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'event_type') === 'realtime_background_queued'
                && (bool) data_get($log->metadata ?? [], 'details.context_refresh_recovery', false)
                && is_numeric(data_get($log->metadata ?? [], 'details.run_id')))
            ->map(fn (AiUsageLog $log): int => (int) data_get($log->metadata ?? [], 'details.run_id'))
            ->unique()
            ->values();
        $backgroundCompletedRecoveryCount = $events
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'event_type') === 'realtime_background_completed'
                && $backgroundQueuedRecoveryRunIds->contains((int) data_get($log->metadata ?? [], 'details.run_id'))
                && trim((string) data_get($log->metadata ?? [], 'details.spoken_text', '')) !== '')
            ->count();
        $recoveryCompletionMissingCount = max(0, $backgroundQueuedRecoveryRunIds->count() - $backgroundCompletedRecoveryCount);
        $failedRefreshCount = $timeoutCount + $failureCount + $ackTimeoutCount;
        $unrecoveredFailureCount = max(0, $failedRefreshCount - $backgroundQueuedRecoveryCount);

        if ($attempts->isEmpty()) {
            return [
                'status' => 'no_data',
                'sample_size' => 0,
                'elapsed_sample_size' => 0,
                'success_count' => 0,
                'timeout_count' => 0,
                'failure_count' => 0,
                'ack_timeout_count' => 0,
                'routed_to_background_count' => 0,
                'background_queued_recovery_count' => 0,
                'background_completed_recovery_count' => 0,
                'recovery_completion_missing_count' => 0,
                'unrecovered_failure_count' => 0,
                'target_p95_elapsed_ms' => self::TARGETS['p95_context_refresh_success_ms'],
                'target_unrecovered_failure_count' => self::TARGETS['context_freshness_unrecovered_count'],
                'target_recovery_completion_missing_count' => self::TARGETS['context_refresh_recovery_completion_missing_count'],
            ];
        }

        $elapsedValues = $successes
            ->map(fn (AiUsageLog $log): mixed => data_get($log->metadata ?? [], 'details.elapsed_ms'))
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): int => (int) $value)
            ->sort()
            ->values();
        $p95 = $elapsedValues->isEmpty() ? null : $this->nearestRankPercentile($elapsedValues, 0.95);
        $status = $timeoutCount === 0
            && $failureCount === 0
            && $ackTimeoutCount === 0
            && $successes->isNotEmpty()
            && $elapsedValues->count() === $successes->count()
            && $p95 !== null
            && $p95 <= self::TARGETS['p95_context_refresh_success_ms']
                ? 'pass'
                : 'fail';

        return [
            'status' => $status,
            'sample_size' => $attempts->count(),
            'elapsed_sample_size' => $elapsedValues->count(),
            'success_count' => $successes->count(),
            'timeout_count' => $timeoutCount,
            'failure_count' => $failureCount,
            'ack_timeout_count' => $ackTimeoutCount,
            'routed_to_background_count' => $routedToBackgroundCount,
            'background_queued_recovery_count' => $backgroundQueuedRecoveryCount,
            'background_completed_recovery_count' => $backgroundCompletedRecoveryCount,
            'recovery_completion_missing_count' => $recoveryCompletionMissingCount,
            'unrecovered_failure_count' => $unrecoveredFailureCount,
            'avg_elapsed_ms' => $elapsedValues->isEmpty() ? null : (int) round($elapsedValues->avg()),
            'p95_elapsed_ms' => $p95,
            'target_p95_elapsed_ms' => self::TARGETS['p95_context_refresh_success_ms'],
            'target_unrecovered_failure_count' => self::TARGETS['context_freshness_unrecovered_count'],
            'target_recovery_completion_missing_count' => self::TARGETS['context_refresh_recovery_completion_missing_count'],
        ];
    }

    private function transportFailureCount(array $eventCounts): int
    {
        return (int) ($eventCounts['webrtc_connection_failure'] ?? 0)
            + (int) ($eventCounts['ice_webrtc_connection_failure'] ?? 0)
            + (int) ($eventCounts['data_channel_close'] ?? 0);
    }

    private function backgroundQueueSummary(Collection $events): array
    {
        $queued = $events
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'event_type') === 'realtime_background_queued')
            ->values();

        if ($queued->isEmpty()) {
            return [
                'status' => 'no_data',
                'sample_size' => 0,
                'elapsed_sample_size' => 0,
                'fallback_count' => 0,
                'unacknowledged_count' => 0,
                'target_p95_queue_elapsed_ms' => self::TARGETS['p95_background_queue_elapsed_ms'],
                'target_fallback_count' => self::TARGETS['background_fallback_queue_count'],
            ];
        }

        $elapsedValues = $queued
            ->map(fn (AiUsageLog $log): mixed => data_get($log->metadata ?? [], 'details.queue_elapsed_ms'))
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): int => (int) $value)
            ->sort()
            ->values();
        $unacknowledged = $queued
            ->filter(fn (AiUsageLog $log): bool => ! (bool) data_get($log->metadata ?? [], 'details.acknowledged', false))
            ->count();
        $fallbackCount = $queued
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'details.source') === 'fallback')
            ->count();
        $p95 = $elapsedValues->isEmpty() ? null : $this->nearestRankPercentile($elapsedValues, 0.95);
        $status = $elapsedValues->count() === $queued->count()
            && $unacknowledged === 0
            && $fallbackCount === 0
            && $p95 !== null
            && $p95 <= self::TARGETS['p95_background_queue_elapsed_ms']
                ? 'pass'
                : 'fail';

        return [
            'status' => $status,
            'sample_size' => $queued->count(),
            'elapsed_sample_size' => $elapsedValues->count(),
            'fallback_count' => $fallbackCount,
            'unacknowledged_count' => $unacknowledged,
            'avg_queue_elapsed_ms' => $elapsedValues->isEmpty() ? null : (int) round($elapsedValues->avg()),
            'p95_queue_elapsed_ms' => $p95,
            'target_p95_queue_elapsed_ms' => self::TARGETS['p95_background_queue_elapsed_ms'],
            'target_fallback_count' => self::TARGETS['background_fallback_queue_count'],
        ];
    }

    private function backgroundProgressSummary(Collection $events): array
    {
        $prompts = $events
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'event_type') === 'flutter_realtime_progress_prompt')
            ->values();

        if ($prompts->isEmpty()) {
            return [
                'status' => 'no_data',
                'sample_size' => 0,
                'elapsed_sample_size' => 0,
                'spoken_sample_size' => 0,
                'target_p95_first_progress_elapsed_ms' => self::TARGETS['p95_background_first_progress_elapsed_ms'],
                'brevity_violation_count' => 0,
                'max_spoken_character_count' => 0,
                'max_spoken_sentence_count' => 0,
                'target_brevity_violation_count' => self::TARGETS['background_progress_brevity_violation_count'],
                'target_max_spoken_character_count' => self::TARGETS['background_progress_max_spoken_character_count'],
                'target_max_spoken_sentence_count' => self::TARGETS['background_progress_max_spoken_sentence_count'],
                'brevity_violations' => [],
            ];
        }

        $elapsedValues = $prompts
            ->map(fn (AiUsageLog $log): mixed => data_get($log->metadata ?? [], 'details.elapsed_ms'))
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): int => (int) $value)
            ->sort()
            ->values();
        $normalizedPrompts = $prompts
            ->map(fn (AiUsageLog $log): string => $this->normalizedProgressPromptText($log))
            ->filter(fn (string $value): bool => $value !== '')
            ->values();
        $instructionDuplicateCount = $normalizedPrompts->count() - $normalizedPrompts->unique()->count();
        $spokenPromptEvents = $events
            ->filter(fn (AiUsageLog $log): bool => in_array(data_get($log->metadata ?? [], 'event_type'), [
                'flutter_realtime_progress_prompt',
                'flutter_realtime_progress_prompt_spoken',
            ], true))
            ->filter(fn (AiUsageLog $log): bool => $this->spokenProgressPromptText($log) !== '')
            ->values();
        $spokenPromptTexts = $spokenPromptEvents
            ->map(fn (AiUsageLog $log): string => $this->spokenProgressPromptText($log))
            ->filter(fn (string $value): bool => $value !== '')
            ->values();
        $spokenDuplicateCount = $spokenPromptTexts->count() - $spokenPromptTexts->unique()->count();
        $duplicateCount = $instructionDuplicateCount + $spokenDuplicateCount;
        $spokenCharacterCounts = $spokenPromptTexts
            ->map(fn (string $spokenText): int => mb_strlen($spokenText))
            ->values();
        $spokenSentenceCounts = $spokenPromptTexts
            ->map(fn (string $spokenText): int => $this->spokenSentenceCount($spokenText))
            ->values();
        $brevityViolations = $spokenPromptEvents
            ->map(function (AiUsageLog $log): ?array {
                $spokenText = $this->spokenProgressPromptText($log);
                if ($spokenText === '') {
                    return null;
                }
                $characterCount = mb_strlen($spokenText);
                $sentenceCount = $this->spokenSentenceCount($spokenText);
                if ($characterCount <= self::TARGETS['background_progress_max_spoken_character_count']
                    && $sentenceCount <= self::TARGETS['background_progress_max_spoken_sentence_count']) {
                    return null;
                }

                return [
                    'usage_log_id' => $log->id,
                    'elapsed_ms' => data_get($log->metadata ?? [], 'details.elapsed_ms'),
                    'spoken_character_count' => $characterCount,
                    'spoken_sentence_count' => $sentenceCount,
                    'spoken_text' => substr(trim((string) preg_replace('/\s+/', ' ', $spokenText)), 0, 180),
                ];
            })
            ->filter()
            ->values();
        $firstProgressValues = $elapsedValues
            ->filter(fn (int $value): bool => $value <= 12000)
            ->values();
        $p95 = $firstProgressValues->isEmpty() ? null : $this->nearestRankPercentile($firstProgressValues, 0.95);
        $status = $elapsedValues->count() === $prompts->count()
            && $p95 !== null
            && $duplicateCount === 0
            && $spokenPromptTexts->count() >= $prompts->count()
            && $brevityViolations->isEmpty()
            && $p95 <= self::TARGETS['p95_background_first_progress_elapsed_ms']
                ? 'pass'
                : 'fail';

        return [
            'status' => $status,
            'sample_size' => $prompts->count(),
            'elapsed_sample_size' => $elapsedValues->count(),
            'spoken_sample_size' => $spokenPromptTexts->count(),
            'first_progress_sample_size' => $firstProgressValues->count(),
            'duplicate_count' => $duplicateCount,
            'target_duplicate_count' => self::TARGETS['background_progress_duplicate_count'],
            'brevity_violation_count' => $brevityViolations->count(),
            'max_spoken_character_count' => $spokenCharacterCounts->max() ?? 0,
            'max_spoken_sentence_count' => $spokenSentenceCounts->max() ?? 0,
            'target_brevity_violation_count' => self::TARGETS['background_progress_brevity_violation_count'],
            'target_max_spoken_character_count' => self::TARGETS['background_progress_max_spoken_character_count'],
            'target_max_spoken_sentence_count' => self::TARGETS['background_progress_max_spoken_sentence_count'],
            'brevity_violations' => $brevityViolations->take(5)->all(),
            'avg_progress_elapsed_ms' => $elapsedValues->isEmpty() ? null : (int) round($elapsedValues->avg()),
            'p95_first_progress_elapsed_ms' => $p95,
            'target_p95_first_progress_elapsed_ms' => self::TARGETS['p95_background_first_progress_elapsed_ms'],
        ];
    }

    private function spokenProgressPromptText(AiUsageLog $log): string
    {
        return trim((string) data_get($log->metadata ?? [], 'details.spoken_text', data_get($log->metadata ?? [], 'details.assistant_text', '')));
    }

    private function backgroundCompletionSummary(Collection $events): array
    {
        $completions = $events
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'event_type') === 'realtime_background_completed')
            ->values();

        if ($completions->isEmpty()) {
            return [
                'status' => 'no_data',
                'sample_size' => 0,
                'spoken_text_sample_size' => 0,
                'spoken_text_incomplete_count' => 0,
                'duplicate_count' => 0,
                'brevity_violation_count' => 0,
                'max_spoken_character_count' => 0,
                'max_spoken_sentence_count' => 0,
                'target_duplicate_count' => self::TARGETS['background_completion_duplicate_count'],
                'target_brevity_violation_count' => self::TARGETS['background_completion_brevity_violation_count'],
                'target_spoken_text_incomplete_count' => self::TARGETS['background_completion_spoken_telemetry_incomplete_count'],
                'target_max_spoken_character_count' => self::TARGETS['background_completion_max_spoken_character_count'],
                'target_max_spoken_sentence_count' => self::TARGETS['background_completion_max_spoken_sentence_count'],
                'duplicates' => [],
                'brevity_violations' => [],
            ];
        }

        $seen = [];
        $duplicates = $completions
            ->map(function (AiUsageLog $log) use (&$seen): ?array {
                $spokenText = (string) data_get($log->metadata ?? [], 'details.spoken_text', '');
                $normalized = $this->normalizeSpokenResponse($spokenText);
                if ($normalized === '' || mb_strlen($normalized) < 12) {
                    return null;
                }
                if (! isset($seen[$normalized])) {
                    $seen[$normalized] = true;

                    return null;
                }

                return [
                    'usage_log_id' => $log->id,
                    'run_id' => data_get($log->metadata ?? [], 'details.run_id'),
                    'spoken_text' => substr(trim((string) preg_replace('/\s+/', ' ', $spokenText)), 0, 180),
                ];
            })
            ->filter()
            ->values();
        $spokenTextSampleSize = $completions
            ->filter(fn (AiUsageLog $log): bool => trim((string) data_get($log->metadata ?? [], 'details.spoken_text', '')) !== '')
            ->count();
        $spokenTextIncompleteCount = max(0, $completions->count() - $spokenTextSampleSize);
        $spokenCharacterCounts = $completions
            ->map(function (AiUsageLog $log): int {
                $spokenText = trim((string) data_get($log->metadata ?? [], 'details.spoken_text', ''));
                $recordedCount = data_get($log->metadata ?? [], 'details.spoken_character_count');

                return is_numeric($recordedCount) ? (int) $recordedCount : mb_strlen($spokenText);
            })
            ->values();
        $spokenSentenceCounts = $completions
            ->map(fn (AiUsageLog $log): int => $this->spokenSentenceCount((string) data_get($log->metadata ?? [], 'details.spoken_text', '')))
            ->values();
        $brevityViolations = $completions
            ->map(function (AiUsageLog $log): ?array {
                $spokenText = trim((string) data_get($log->metadata ?? [], 'details.spoken_text', ''));
                if ($spokenText === '') {
                    return null;
                }
                $recordedCount = data_get($log->metadata ?? [], 'details.spoken_character_count');
                $characterCount = is_numeric($recordedCount) ? (int) $recordedCount : mb_strlen($spokenText);
                $sentenceCount = $this->spokenSentenceCount($spokenText);
                if ($characterCount <= self::TARGETS['background_completion_max_spoken_character_count']
                    && $sentenceCount <= self::TARGETS['background_completion_max_spoken_sentence_count']) {
                    return null;
                }

                return [
                    'usage_log_id' => $log->id,
                    'run_id' => data_get($log->metadata ?? [], 'details.run_id'),
                    'spoken_character_count' => $characterCount,
                    'spoken_sentence_count' => $sentenceCount,
                    'spoken_text' => substr(trim((string) preg_replace('/\s+/', ' ', $spokenText)), 0, 180),
                ];
            })
            ->filter()
            ->values();

        return [
            'status' => $duplicates->isEmpty() && $brevityViolations->isEmpty() && $spokenTextIncompleteCount === 0 ? 'pass' : 'fail',
            'sample_size' => $completions->count(),
            'spoken_text_sample_size' => $spokenTextSampleSize,
            'spoken_text_incomplete_count' => $spokenTextIncompleteCount,
            'duplicate_count' => $duplicates->count(),
            'brevity_violation_count' => $brevityViolations->count(),
            'max_spoken_character_count' => $spokenCharacterCounts->max() ?? 0,
            'max_spoken_sentence_count' => $spokenSentenceCounts->max() ?? 0,
            'target_duplicate_count' => self::TARGETS['background_completion_duplicate_count'],
            'target_brevity_violation_count' => self::TARGETS['background_completion_brevity_violation_count'],
            'target_spoken_text_incomplete_count' => self::TARGETS['background_completion_spoken_telemetry_incomplete_count'],
            'target_max_spoken_character_count' => self::TARGETS['background_completion_max_spoken_character_count'],
            'target_max_spoken_sentence_count' => self::TARGETS['background_completion_max_spoken_sentence_count'],
            'duplicates' => $duplicates->take(5)->all(),
            'brevity_violations' => $brevityViolations->take(5)->all(),
        ];
    }

    private function spokenSentenceCount(string $spokenText): int
    {
        $spoken = trim($spokenText);
        if ($spoken === '') {
            return 0;
        }

        preg_match_all('/[.!?]+(?:\s+|$)/', $spoken, $matches);
        $count = count(array_filter($matches[0] ?? [], fn (string $match): bool => trim($match) !== ''));

        return max(1, $count);
    }

    private function normalizedProgressPromptText(AiUsageLog $log): string
    {
        $details = (array) data_get($log->metadata ?? [], 'details', []);
        $text = (string) ($details['instruction'] ?? '');
        $normalized = strtolower(trim((string) preg_replace('/\s+/', ' ', $text)));

        return $normalized;
    }

    private function backgroundFailureCount(array $eventCounts): int
    {
        return (int) ($eventCounts['realtime_background_failed'] ?? 0)
            + (int) ($eventCounts['realtime_background_complete_empty'] ?? 0)
            + (int) ($eventCounts['realtime_background_watch_failure'] ?? 0);
    }

    private function backgroundFailureTruthfulnessSummary(Collection $events): array
    {
        $failures = $events
            ->filter(fn (AiUsageLog $log): bool => in_array(data_get($log->metadata ?? [], 'event_type'), [
                'realtime_background_failed',
                'realtime_background_complete_empty',
                'realtime_background_watch_failure',
            ], true))
            ->values();

        if ($failures->isEmpty()) {
            return [
                'status' => 'pass',
                'sample_size' => 0,
                'misleading_count' => 0,
                'incomplete_count' => 0,
                'target_misleading_count' => self::TARGETS['background_failure_misleading_count'],
                'target_incomplete_count' => self::TARGETS['background_failure_truthfulness_incomplete_count'],
            ];
        }

        $misleading = $failures
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'details.failure_voice_acknowledged') === false)
            ->count();
        $incomplete = $failures
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'details.failure_voice_acknowledged') === null)
            ->count();
        $status = $misleading === 0 && $incomplete === 0 ? 'pass' : 'fail';

        return [
            'status' => $status,
            'sample_size' => $failures->count(),
            'misleading_count' => $misleading,
            'incomplete_count' => $incomplete,
            'target_misleading_count' => self::TARGETS['background_failure_misleading_count'],
            'target_incomplete_count' => self::TARGETS['background_failure_truthfulness_incomplete_count'],
        ];
    }

    private function contextFreshnessFailureCount(array $eventCounts): int
    {
        return (int) ($eventCounts['dashboard_context_pre_response_timeout'] ?? 0)
            + (int) ($eventCounts['dashboard_context_pre_response_failure'] ?? 0)
            + (int) ($eventCounts['dashboard_context_pre_response_ack_timeout'] ?? 0)
            + (int) ($eventCounts['dashboard_context_refresh_failure'] ?? 0);
    }

    private function eventRate(int $count, int $turnCount): ?float
    {
        if ($turnCount <= 0) {
            return null;
        }

        return round($count / $turnCount, 4);
    }

    private function brevitySummary(Collection $turns): array
    {
        $trackedTurns = $turns
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'spoken_brevity_violation') !== null)
            ->values();

        if ($trackedTurns->isEmpty()) {
            return [
                'status' => 'missing',
                'sample_size' => 0,
                'target_violation_rate' => self::TARGETS['spoken_brevity_violation_rate'],
            ];
        }

        $violations = $trackedTurns
            ->filter(fn (AiUsageLog $log): bool => (bool) data_get($log->metadata ?? [], 'spoken_brevity_violation', false))
            ->count();
        $violationRate = $violations / max(1, $trackedTurns->count());

        return [
            'status' => $violationRate <= self::TARGETS['spoken_brevity_violation_rate'] ? 'pass' : 'fail',
            'sample_size' => $trackedTurns->count(),
            'violation_count' => $violations,
            'violation_rate' => round($violationRate, 4),
            'avg_character_count' => (int) round($trackedTurns->avg(fn (AiUsageLog $log): int => (int) data_get($log->metadata ?? [], 'spoken_character_count', 0))),
            'max_character_count' => (int) $trackedTurns->max(fn (AiUsageLog $log): int => (int) data_get($log->metadata ?? [], 'spoken_character_count', 0)),
            'max_sentence_count' => (int) $trackedTurns->max(fn (AiUsageLog $log): int => (int) data_get($log->metadata ?? [], 'spoken_sentence_count', 0)),
            'target_violation_rate' => self::TARGETS['spoken_brevity_violation_rate'],
        ];
    }

    private function spokenNaturalnessSummary(Collection $events): array
    {
        $spokenEvents = $events
            ->filter(fn (AiUsageLog $log): bool => $this->spokenEventText($log) !== '')
            ->values();

        if ($spokenEvents->isEmpty()) {
            return [
                'status' => 'no_data',
                'sample_size' => 0,
                'violation_count' => 0,
                'target_min_sample_size' => self::TARGETS['minimum_spoken_naturalness_sample_count'],
                'target_violation_count' => self::TARGETS['spoken_naturalness_violation_count'],
                'violations' => [],
            ];
        }

        $violations = $spokenEvents
            ->map(function (AiUsageLog $log): ?array {
                $assistantText = $this->spokenEventText($log);
                $matchedPattern = $this->spokenNaturalnessViolation($assistantText);

                if ($matchedPattern === null) {
                    return null;
                }

                return [
                    'usage_log_id' => $log->id,
                    'event_type' => (string) data_get($log->metadata ?? [], 'event_type', 'unknown'),
                    'matched_pattern' => $matchedPattern,
                    'assistant_text' => substr(trim((string) preg_replace('/\s+/', ' ', $assistantText)), 0, 180),
                ];
            })
            ->filter()
            ->values();
        $duplicates = $this->duplicateSpokenResponses($spokenEvents);
        $status = $violations->count() <= self::TARGETS['spoken_naturalness_violation_count']
            && $duplicates->count() <= self::TARGETS['spoken_duplicate_response_count']
                ? 'pass'
                : 'fail';

        return [
            'status' => $status,
            'sample_size' => $spokenEvents->count(),
            'violation_count' => $violations->count(),
            'duplicate_response_count' => $duplicates->count(),
            'target_min_sample_size' => self::TARGETS['minimum_spoken_naturalness_sample_count'],
            'target_violation_count' => self::TARGETS['spoken_naturalness_violation_count'],
            'target_duplicate_response_count' => self::TARGETS['spoken_duplicate_response_count'],
            'violations' => $violations->take(5)->values()->all(),
            'duplicate_responses' => $duplicates->take(5)->values()->all(),
        ];
    }

    private function spokenEventText(AiUsageLog $log): string
    {
        $eventType = (string) data_get($log->metadata ?? [], 'event_type', '');

        return match ($eventType) {
            'flutter_realtime_response_done' => (bool) data_get($log->metadata ?? [], 'details.voice_only_assistant', false)
                ? ''
                : trim((string) data_get($log->metadata ?? [], 'details.assistant_text', '')),
            'flutter_realtime_progress_prompt_spoken' => trim((string) data_get($log->metadata ?? [], 'details.spoken_text', '')),
            'realtime_background_completed', 'realtime_background_cancelled' => trim((string) data_get($log->metadata ?? [], 'details.spoken_text', '')),
            default => '',
        };
    }

    private function duplicateSpokenResponses(Collection $responses): Collection
    {
        $seen = [];

        return $responses
            ->map(function (AiUsageLog $log) use (&$seen): ?array {
                $assistantText = $this->spokenEventText($log);
                $normalized = $this->normalizeSpokenResponse($assistantText);
                if ($normalized === '' || mb_strlen($normalized) < 12) {
                    return null;
                }

                if (! isset($seen[$normalized])) {
                    $seen[$normalized] = $log->id ?: true;

                    return null;
                }

                return [
                    'usage_log_id' => $log->id,
                    'first_usage_log_id' => $seen[$normalized] === true ? null : $seen[$normalized],
                    'event_type' => (string) data_get($log->metadata ?? [], 'event_type', 'unknown'),
                    'assistant_text' => substr(trim((string) preg_replace('/\s+/', ' ', $assistantText)), 0, 180),
                ];
            })
            ->filter()
            ->values();
    }

    private function normalizeSpokenResponse(string $assistantText): string
    {
        $normalized = strtolower(trim((string) preg_replace('/\s+/', ' ', $assistantText)));
        $normalized = (string) preg_replace('/[^\pL\pN\s]+/u', '', $normalized);

        return trim((string) preg_replace('/\s+/', ' ', $normalized));
    }

    private function spokenNaturalnessViolation(string $assistantText): ?string
    {
        $spoken = trim($assistantText);
        if ($spoken === '') {
            return null;
        }

        $patterns = [
            'ai_disclaimer' => '/\b(?:as an? (?:ai|language model|bot|virtual assistant|digital assistant)|I(?:\s+am|\'m) an? (?:ai|language model|bot|virtual assistant|digital assistant))\b/i',
            'false_app_capability_denial' => '/\bI (?:do not|don\'t|cannot|can\'t|am not able to|am unable to) (?:access|see|view|check|read|use|manage|update|create|change) (?:your )?(?:calendar|tasks?|to-?dos?|reminders?|notes?|workspace|HeyBean|app data|account)\b/i',
            'nonhuman_assistance_cliche' => '/\b(?:how can I assist you|how may I assist you|is there anything else I can assist you with|as your virtual assistant)\b/i',
            'bad_voice_mic_check' => '/\bI can read you\b/i',
            'internal_queue_tool' => '/\bqueue_bean_work\b/i',
            'function_call' => '/\b(?:function|tool) call(?:s|ed|ing)?\b/i',
            'calling_tool' => '/\b(?:call|calling|use|using|run|running|invoke|invoking|trigger|triggering) (?:a |the |my |that )?(?:tool|function)\b/i',
            'tool_result' => '/\b(?:tool|function) (?:result|output|response|returned|failed|error)\b/i',
            'prompt_internals' => '/\b(?:system|developer) (?:message|prompt|instruction|instructions)\b/i',
            'realtime_internals' => '/\b(?:realtime|real-time) (?:session|api|event|response|voice|model)\b/i',
            'transport_internals' => '/\b(?:webrtc|data channel|session\.updated|response\.create|conversation\.item)\b/i',
            'api_payload' => '/\b(?:json payload|api endpoint)\b/i',
        ];

        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $spoken) === 1) {
                return $name;
            }
        }

        return null;
    }

    private function contextualFollowUpResolutionSummary(Collection $events): array
    {
        $responses = $events
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'event_type') === 'flutter_realtime_response_done'
                && (bool) data_get($log->metadata ?? [], 'details.is_contextual_follow_up_turn', false))
            ->values();

        if ($responses->isEmpty()) {
            return [
                'status' => 'no_data',
                'sample_size' => 0,
                'resolved_count' => 0,
                'unresolved_count' => 0,
                'target_min_resolution_count' => self::TARGETS['minimum_contextual_follow_up_resolution_count'],
                'target_unresolved_count' => self::TARGETS['contextual_follow_up_unresolved_count'],
            ];
        }

        $unresolved = $responses
            ->filter(function (AiUsageLog $log): bool {
                $metadata = $log->metadata ?? [];
                $hasUserContent = trim((string) data_get($metadata, 'details.user_content', '')) !== '';
                $assistantAnswered = (bool) data_get($metadata, 'details.assistant_answered', false);
                $functionCalls = collect(data_get($metadata, 'details.function_calls', []))
                    ->filter(fn (mixed $call): bool => is_array($call) && trim((string) data_get($call, 'name', '')) !== '')
                    ->count();

                return ! $hasUserContent || (! $assistantAnswered && $functionCalls < 1);
            })
            ->count();
        $resolved = $responses->count() - $unresolved;

        return [
            'status' => $resolved >= self::TARGETS['minimum_contextual_follow_up_resolution_count']
                && $unresolved <= self::TARGETS['contextual_follow_up_unresolved_count']
                    ? 'pass'
                    : 'fail',
            'sample_size' => $responses->count(),
            'resolved_count' => $resolved,
            'unresolved_count' => $unresolved,
            'target_min_resolution_count' => self::TARGETS['minimum_contextual_follow_up_resolution_count'],
            'target_unresolved_count' => self::TARGETS['contextual_follow_up_unresolved_count'],
        ];
    }

    private function conversationSummary(Collection $turns): array
    {
        $trackedTurns = $turns
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'is_follow_up_turn') !== null)
            ->values();

        if ($trackedTurns->isEmpty()) {
            return [
                'status' => 'missing',
                'follow_up_sample_size' => 0,
                'follow_up_turn_count' => 0,
                'contextual_follow_up_turn_count' => 0,
                'micro_follow_up_kind_sample_size' => 0,
                'micro_follow_up_kind_count' => 0,
                'micro_follow_up_kind_counts' => [],
                'untyped_contextual_follow_up_count' => 0,
                'follow_up_turn_rate' => null,
                'contextual_follow_up_turn_rate' => null,
                'target_min_follow_up_turn_count' => self::TARGETS['minimum_follow_up_turn_count'],
                'target_min_contextual_follow_up_turn_count' => self::TARGETS['minimum_contextual_follow_up_turn_count'],
                'target_min_micro_follow_up_kind_count' => self::TARGETS['minimum_micro_follow_up_kind_count'],
                'target_untyped_contextual_follow_up_count' => self::TARGETS['untyped_contextual_follow_up_count'],
            ];
        }

        $followUps = $trackedTurns
            ->filter(fn (AiUsageLog $log): bool => (bool) data_get($log->metadata ?? [], 'is_follow_up_turn', false))
            ->count();
        $contextualFollowUps = $trackedTurns
            ->filter(fn (AiUsageLog $log): bool => (bool) data_get($log->metadata ?? [], 'is_contextual_follow_up_turn', false))
            ->count();
        $knownMicroKinds = ['confirmation', 'decline', 'correction', 'continuation', 'reference'];
        $microKindCounts = $trackedTurns
            ->map(fn (AiUsageLog $log): string => strtolower(trim((string) data_get($log->metadata ?? [], 'contextual_follow_up_kind', ''))))
            ->filter(fn (string $kind): bool => in_array($kind, $knownMicroKinds, true))
            ->countBy()
            ->sortKeys()
            ->all();
        $microKindSampleSize = array_sum($microKindCounts);
        $microKindCount = collect($microKindCounts)->filter(fn (int $count): bool => $count > 0)->count();
        $untypedContextualFollowUps = max(0, $contextualFollowUps - $microKindSampleSize);

        return [
            'status' => $followUps >= self::TARGETS['minimum_follow_up_turn_count']
                && $contextualFollowUps >= self::TARGETS['minimum_contextual_follow_up_turn_count']
                && $microKindCount >= self::TARGETS['minimum_micro_follow_up_kind_count']
                && $untypedContextualFollowUps <= self::TARGETS['untyped_contextual_follow_up_count']
                    ? 'pass'
                    : 'fail',
            'follow_up_sample_size' => $trackedTurns->count(),
            'follow_up_turn_count' => $followUps,
            'contextual_follow_up_turn_count' => $contextualFollowUps,
            'micro_follow_up_kind_sample_size' => $microKindSampleSize,
            'micro_follow_up_kind_count' => $microKindCount,
            'micro_follow_up_kind_counts' => $microKindCounts,
            'untyped_contextual_follow_up_count' => $untypedContextualFollowUps,
            'follow_up_turn_rate' => round($followUps / max(1, $trackedTurns->count()), 4),
            'contextual_follow_up_turn_rate' => round($contextualFollowUps / max(1, $trackedTurns->count()), 4),
            'target_min_follow_up_turn_count' => self::TARGETS['minimum_follow_up_turn_count'],
            'target_min_contextual_follow_up_turn_count' => self::TARGETS['minimum_contextual_follow_up_turn_count'],
            'target_min_micro_follow_up_kind_count' => self::TARGETS['minimum_micro_follow_up_kind_count'],
            'target_untyped_contextual_follow_up_count' => self::TARGETS['untyped_contextual_follow_up_count'],
        ];
    }

    private function telemetrySummary(Collection $turns): array
    {
        $trackedTurns = $turns
            ->filter(fn (AiUsageLog $log): bool => data_get($log->metadata ?? [], 'realtime_usage_missing') !== null)
            ->values();

        if ($trackedTurns->isEmpty()) {
            return [
                'usage_sample_size' => 0,
                'usage_missing_count' => 0,
                'usage_missing_rate' => null,
            ];
        }

        $missing = $trackedTurns
            ->filter(fn (AiUsageLog $log): bool => (bool) data_get($log->metadata ?? [], 'realtime_usage_missing', false))
            ->count();

        return [
            'usage_sample_size' => $trackedTurns->count(),
            'usage_missing_count' => $missing,
            'usage_missing_rate' => round($missing / max(1, $trackedTurns->count()), 4),
        ];
    }

    private function recentSlowTurns(Collection $turns): array
    {
        return $turns
            ->filter(function (AiUsageLog $log): bool {
                $metadata = $log->metadata ?? [];

                return (int) data_get($metadata, 'transcript_to_first_assistant_ms', 0) > self::TARGETS['p95_transcript_to_first_assistant_ms']
                    || (int) data_get($metadata, 'turn_completed_ms', 0) > self::TARGETS['p95_turn_completed_ms'];
            })
            ->sortByDesc(fn (AiUsageLog $log): int => max(
                (int) data_get($log->metadata ?? [], 'transcript_to_first_assistant_ms', 0),
                (int) data_get($log->metadata ?? [], 'turn_completed_ms', 0),
            ))
            ->take(10)
            ->map(fn (AiUsageLog $log): array => [
                'usage_log_id' => $log->id,
                'session_id' => $log->conversation_session_id,
                'model' => $log->model,
                'created_at' => $log->created_at?->toIso8601String(),
                'tool_call_count' => $log->tool_call_count,
                'transcript_to_response_create_ms' => data_get($log->metadata ?? [], 'transcript_to_response_create_ms'),
                'response_create_to_first_assistant_ms' => data_get($log->metadata ?? [], 'response_create_to_first_assistant_ms'),
                'transcript_to_first_assistant_ms' => data_get($log->metadata ?? [], 'transcript_to_first_assistant_ms'),
                'turn_completed_ms' => data_get($log->metadata ?? [], 'turn_completed_ms'),
            ])
            ->values()
            ->all();
    }

    private function nearestRankPercentile(Collection $sortedValues, float $percentile): int
    {
        $count = $sortedValues->count();
        if ($count === 0) {
            return 0;
        }

        $index = max(0, min($count - 1, (int) ceil($percentile * $count) - 1));

        return (int) $sortedValues->values()->get($index);
    }
}
