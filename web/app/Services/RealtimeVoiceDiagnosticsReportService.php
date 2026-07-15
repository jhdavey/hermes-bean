<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\AssistantRun;
use App\Models\VoiceRealtimeCommand;
use App\Models\VoiceTurn;
use App\Models\VoiceTurnEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Read-only, transcript-free diagnostics for the audio-native Realtime path.
 * Every row is derived from server-owned durable lifecycle records.
 */
final class RealtimeVoiceDiagnosticsReportService
{
    public const DEFAULT_WINDOW_DAYS = 7;

    public const MAX_WINDOW_DAYS = 90;

    /** @return array<string, mixed> */
    public function report(CarbonInterface $from, CarbonInterface $to): array
    {
        $turns = VoiceTurn::query()
            ->where('source', 'browser_voice_realtime')
            ->whereBetween('created_at', [$from, $to])
            ->with(['realtimeSession', 'runs', 'events'])
            ->orderBy('id')
            ->get();
        $rows = $turns->map(fn (VoiceTurn $turn): array => $this->turn($turn))->values();

        return [
            'window' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'browser_voice_realtime' => [
                'summary' => [
                    'turn_count' => $rows->count(),
                    'completed' => $rows->where('state', 'completed')->count(),
                    'failed' => $rows->where('state', 'failed')->count(),
                    'canceled' => $rows->where('state', 'canceled')->count(),
                    'active' => $rows->whereNotIn('state', ['completed', 'failed', 'canceled'])->count(),
                    'raw_audio_retained_count' => $rows->where('raw_audio_retained', true)->count(),
                ],
                'latency_metrics' => $this->latencyMetrics($rows),
                'client_failures' => $this->clientFailures($from, $to),
                'turns' => $rows->all(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function turn(VoiceTurn $turn): array
    {
        /** @var Collection<int, VoiceTurnEvent> $events */
        $events = $turn->events->sortBy('id')->values();
        /** @var Collection<int, AssistantRun> $runs */
        $runs = $turn->runs->sortBy('id')->values();
        $eventAt = fn (string $type) => $events->firstWhere('event_type', $type)?->created_at;
        $finalAudio = $events->first(fn (VoiceTurnEvent $event): bool => $event->event_type === 'final_audio_started'
            || ($event->event_type === 'playback_started'
                && data_get($event->payload, 'purpose') === 'final'))?->created_at;
        $ackAudio = $events->first(fn (VoiceTurnEvent $event): bool => $event->event_type === 'acknowledgement_started'
            || ($event->event_type === 'playback_started'
                && data_get($event->payload, 'purpose') === 'acknowledgement'))?->created_at;

        $commands = VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->orderBy('id')
            ->get();
        $operationRuns = $runs->where('handler', HermesSemanticOperationExecutor::OPERATION_HANDLER);
        $firstOperation = $operationRuns->sortBy('created_at')->first();
        $lastOperation = $operationRuns->sortByDesc('completed_at')->first();
        $metadata = is_array($turn->metadata) ? $turn->metadata : [];

        return [
            'turn_id' => $turn->turn_id,
            'voice_turn_id' => $turn->id,
            'user_id' => $turn->user_id,
            'workspace_id' => $turn->workspace_id,
            'conversation_session_id' => $turn->conversation_session_id,
            'realtime_session_id' => $turn->realtimeSession?->public_id,
            'provider_call_id' => $turn->realtimeSession?->provider_call_id,
            'provider_input_item_id' => $turn->provider_input_item_id,
            'provider_response_id' => data_get($metadata, 'latest_provider_response_id'),
            'state' => $turn->state->value,
            'side_effect_status' => $turn->side_effect_status->value,
            'retry_count' => $turn->retry_count,
            'display_mode' => $turn->display_mode,
            'raw_audio_retained' => data_get($metadata, 'raw_audio_retained') === true,
            'timestamps' => [
                'accepted_at' => $this->iso($turn->accepted_at),
                'provider_input_bound_at' => $this->iso($eventAt('provider_input_item_bound')),
                'interpretation_requested_at' => $this->iso($eventAt('realtime_interpretation_requested')),
                'interpretation_completed_at' => $this->iso($eventAt('semantic_interpretation_completed'))
                    ?? $this->iso($eventAt('realtime_semantic_plan_received')),
                'acknowledgement_authorized_at' => $this->iso($commands->firstWhere('purpose', 'acknowledgement')?->created_at),
                'acknowledgement_first_audio_at' => $this->iso($ackAudio),
                'operation_queued_at' => $this->iso($firstOperation?->created_at),
                'operation_started_at' => $this->iso($firstOperation?->started_at),
                'operation_committed_at' => $this->iso($lastOperation?->completed_at),
                'final_ready_at' => $this->iso($turn->terminal_at),
                'final_authorized_at' => $this->iso($commands->firstWhere('purpose', 'final')?->created_at),
                'final_first_audio_at' => $this->iso($finalAudio),
                'terminal_at' => $this->iso($turn->terminal_at),
            ],
            'latency_ms' => [
                'pre_admission_to_input_binding' => $this->elapsed($turn->accepted_at, $eventAt('provider_input_item_bound')),
                'input_binding_to_interpretation_request' => $this->elapsed(
                    $eventAt('provider_input_item_bound'),
                    $eventAt('realtime_interpretation_requested'),
                ),
                'input_binding_to_semantic_plan' => $this->elapsed(
                    $eventAt('provider_input_item_bound'),
                    $eventAt('realtime_semantic_plan_received'),
                ),
                'operation_queue_wait' => $this->elapsed($firstOperation?->created_at, $firstOperation?->started_at),
                'operation_execution' => $this->elapsed($firstOperation?->started_at, $lastOperation?->completed_at),
                'acknowledgement_authorization_to_first_audio' => $this->elapsed(
                    $commands->firstWhere('purpose', 'acknowledgement')?->created_at,
                    $ackAudio,
                ),
                'final_ready_to_authorization' => $this->elapsed(
                    $turn->terminal_at,
                    $commands->firstWhere('purpose', 'final')?->created_at,
                ),
                'final_ready_to_first_audio' => $this->elapsed($turn->terminal_at, $finalAudio),
                'accepted_to_terminal' => $this->elapsed($turn->accepted_at, $turn->terminal_at),
            ],
            'client_milestones_ms' => collect((array) data_get($metadata, 'client_milestones'))
                ->filter(fn (mixed $value): bool => is_int($value) || is_float($value))
                ->map(fn (int|float $value): int|float => $value)
                ->all(),
            'runs' => $runs->map(fn (AssistantRun $run): array => [
                'id' => $run->id,
                'lane' => $run->lane,
                'handler' => $run->handler,
                'label' => $run->label,
                'status' => $run->status,
                'priority' => $run->priority,
                'resource_lock_key' => $run->resource_lock_key,
                'created_at' => $this->iso($run->created_at),
                'dispatch_requested_at' => $this->iso($run->dispatch_requested_at),
                'started_at' => $this->iso($run->started_at),
                'completed_at' => $this->iso($run->completed_at),
                'failure_category' => data_get($run->result, 'failure_category'),
                'side_effect_committed' => data_get($run->metadata, 'semantic_operation_receipt.side_effect_committed') === true,
            ])->all(),
            'sideband_commands' => $commands->map(fn (VoiceRealtimeCommand $command): array => [
                'command_id' => $command->command_id,
                'type' => $command->command_type->value,
                'purpose' => $command->purpose,
                'speech_item_id' => $command->speech_item_id,
                'provider_response_id' => $command->provider_response_id,
                'status' => $command->status->value,
                'attempts' => $command->attempts,
                'created_at' => $this->iso($command->created_at),
                'acknowledged_at' => $this->iso($command->acknowledged_at),
                'failed_at' => $this->iso($command->failed_at),
            ])->all(),
            'transitions' => $events->map(fn (VoiceTurnEvent $event): array => [
                'id' => $event->id,
                'sequence' => $event->sequence,
                'event_type' => $event->event_type,
                'from_state' => $event->from_state,
                'to_state' => $event->to_state,
                'source' => $event->source,
                'created_at' => $this->iso($event->created_at),
            ])->all(),
            'failure' => $turn->state->value === 'failed' ? [
                'category' => $turn->failure_category,
                'internal_detail' => $turn->internal_failure_detail,
                'user_facing_text' => $turn->user_facing_failure_text,
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function clientFailures(CarbonInterface $from, CarbonInterface $to): array
    {
        $events = ActivityEvent::query()
            ->where('event_type', 'like', '%client_failure')
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('id')
            ->get();

        return [
            'count' => $events->count(),
            'events' => $events->map(fn (ActivityEvent $event): array => [
                'id' => $event->id,
                'user_id' => $event->user_id,
                'workspace_id' => $event->workspace_id,
                'conversation_session_id' => $event->conversation_session_id,
                'stage' => data_get($event->payload, 'stage'),
                'code' => data_get($event->payload, 'code'),
                'turn_id' => data_get($event->payload, 'turn_id'),
                'created_at' => $this->iso($event->created_at),
            ])->all(),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function latencyMetrics(Collection $rows): array
    {
        $keys = [
            'pre_admission_to_input_binding',
            'input_binding_to_interpretation_request',
            'input_binding_to_semantic_plan',
            'operation_queue_wait',
            'operation_execution',
            'acknowledgement_authorization_to_first_audio',
            'final_ready_to_authorization',
            'final_ready_to_first_audio',
            'accepted_to_terminal',
        ];

        return collect($keys)->mapWithKeys(function (string $key) use ($rows): array {
            $values = $rows->pluck("latency_ms.{$key}")
                ->filter(fn (mixed $value): bool => is_int($value) || is_float($value))
                ->map(fn (int|float $value): float => (float) $value)
                ->sort()
                ->values();

            return [$key => [
                'sample_count' => $values->count(),
                'p50_ms' => $this->percentile($values, 0.50),
                'p95_ms' => $this->percentile($values, 0.95),
                'sufficient_sample' => $values->count() >= 20,
            ]];
        })->all();
    }

    /** @param Collection<int, float> $values */
    private function percentile(Collection $values, float $percentile): ?float
    {
        if ($values->isEmpty()) {
            return null;
        }

        $index = max(0, (int) ceil($percentile * $values->count()) - 1);

        return round((float) $values->get($index), 2);
    }

    private function elapsed(?CarbonInterface $from, ?CarbonInterface $to): ?int
    {
        if (! $from instanceof CarbonInterface || ! $to instanceof CarbonInterface || $to->lt($from)) {
            return null;
        }

        return (int) $from->diffInMilliseconds($to);
    }

    private function iso(?CarbonInterface $timestamp): ?string
    {
        return $timestamp?->toIso8601String();
    }
}
