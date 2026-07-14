<?php

namespace App\Services;

use App\Enums\VoiceTurnLane;
use App\Enums\VoiceTurnState;
use App\Models\ActivityEvent;
use App\Models\AssistantRun;
use App\Models\VoiceTurn;
use App\Models\VoiceTurnEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class BrowserVoiceV2DiagnosticsReportService
{
    public const MINIMUM_BENCHMARK_SAMPLES = 20;

    public function __construct(
        private readonly VoiceTurnPrivacyService $privacy,
    ) {}

    /** @return array<string, mixed> */
    public function report(CarbonInterface $from, CarbonInterface $to): array
    {
        $turns = VoiceTurn::query()
            ->where('source', 'browser_voice_v2')
            ->whereBetween('created_at', [$from, $to])
            ->with([
                'runs' => fn ($query) => $query->orderBy('id'),
                'events' => fn ($query) => $query->orderBy('sequence'),
            ])
            ->orderBy('id')
            ->get();
        $activityBySession = $this->activityBySession($turns, $from, $to);
        $rows = $turns->map(fn (VoiceTurn $turn): array => $this->turn(
            $turn,
            $activityBySession->get((int) $turn->conversation_session_id, collect()),
        ));
        $metrics = $this->latencyMetrics($rows);
        $gates = $this->benchmarkGates($metrics);
        $alerts = $this->alerts($rows, $gates);
        $clientFailures = $this->clientFailures($from, $to);

        return [
            'population' => [
                'turn_count' => $rows->count(),
                'run_count' => $rows->sum(fn (array $row): int => count($row['jobs'])),
                'event_count' => $rows->sum(fn (array $row): int => count($row['lifecycle_timeline'])),
                'state_counts' => $this->enumCounts($rows, 'state', VoiceTurnState::cases()),
                'lane_counts' => $this->enumCounts($rows, 'lane', VoiceTurnLane::cases()),
                'terminal_count' => $rows->whereIn('state', [
                    VoiceTurnState::Completed->value,
                    VoiceTurnState::Failed->value,
                    VoiceTurnState::Canceled->value,
                ])->count(),
                'nonterminal_count' => $rows->whereIn('state', [
                    VoiceTurnState::Capturing->value,
                    VoiceTurnState::AwaitingClarification->value,
                    VoiceTurnState::Accepted->value,
                    VoiceTurnState::Running->value,
                ])->count(),
            ],
            'latency_metrics' => $metrics,
            'benchmark_gates' => $gates,
            'alerts' => $alerts,
            'client_failures' => $clientFailures,
            'telemetry_coverage' => $this->telemetryCoverage($rows, $metrics),
            'privacy' => [
                'transcript_source' => 'voice_turns.sanitized_transcript',
                'raw_transcript_exposed' => false,
                'raw_audio_retention_allowed' => false,
                'raw_audio_persistence_detection_count' => $alerts['raw_audio_persistence_detected']['count'],
                'diagnostic_payloads_sanitized' => true,
            ],
            'turns' => $rows->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function clientFailures(CarbonInterface $from, CarbonInterface $to): array
    {
        $events = ActivityEvent::query()
            ->where('event_type', 'browser_voice_v2.client_failure')
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return [
            'count' => $events->count(),
            'stage_counts' => $events
                ->countBy(fn (ActivityEvent $event): string => (string) data_get($event->payload, 'stage', 'unknown'))
                ->all(),
            'events' => $events->map(function (ActivityEvent $event): array {
                $payload = is_array($event->payload) ? $event->payload : [];

                return [
                    'id' => $event->id,
                    'user_id' => $event->user_id,
                    'workspace_id' => $event->workspace_id,
                    'conversation_session_id' => $event->conversation_session_id,
                    'turn_id' => $this->privacy->sanitizeTranscript((string) data_get($payload, 'turn_id', '')) ?: null,
                    'stage' => $this->privacy->sanitizeTranscript((string) data_get($payload, 'stage', 'unknown')),
                    'code' => $this->privacy->sanitizeTranscript((string) data_get($payload, 'code', 'unknown')),
                    'message' => $this->privacy->sanitizeTranscript((string) data_get($payload, 'message', '')),
                    'cause_chain' => collect(data_get($payload, 'cause_chain', []))
                        ->take(4)
                        ->map(fn (mixed $cause): array => [
                            'code' => $this->privacy->sanitizeTranscript((string) data_get($cause, 'code', '')),
                            'message' => $this->privacy->sanitizeTranscript((string) data_get($cause, 'message', '')),
                        ])->values()->all(),
                    'created_at' => $this->iso($event->created_at),
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, VoiceTurn>  $turns
     * @return Collection<int, Collection<int, ActivityEvent>>
     */
    private function activityBySession(Collection $turns, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $sessionIds = $turns->pluck('conversation_session_id')->filter()->unique()->values();
        if ($sessionIds->isEmpty()) {
            return collect();
        }

        return ActivityEvent::query()
            ->whereIn('conversation_session_id', $sessionIds)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('id')
            ->get()
            ->groupBy(fn (ActivityEvent $event): int => (int) $event->conversation_session_id);
    }

    /**
     * @param  Collection<int, ActivityEvent>  $sessionActivity
     * @return array<string, mixed>
     */
    private function turn(VoiceTurn $turn, Collection $sessionActivity): array
    {
        $events = $turn->events instanceof Collection ? $turn->events : collect();
        $runs = $turn->runs instanceof Collection ? $turn->runs : collect();
        $activity = $sessionActivity
            ->filter(fn (ActivityEvent $event): bool => $this->activityBelongsToTurn($event, $turn, $runs))
            ->values();
        $latencies = $this->turnLatencies($turn, $events);
        $deadline = $this->deadlineStatus($turn);
        $eventMaxVersion = (int) ($events->max('version') ?? 0);
        $rawAudioDetected = $this->rawAudioDetected($turn, $events, $runs, $activity);

        return [
            'turn_id' => $turn->turn_id,
            'voice_turn_database_id' => $turn->id,
            'user_id' => $turn->user_id,
            'workspace_id' => $turn->workspace_id,
            'conversation_session_id' => $turn->conversation_session_id,
            'sanitized_transcript' => $this->privacy->sanitizeTranscript((string) $turn->sanitized_transcript),
            'state' => $turn->state->value,
            'version' => $turn->version,
            'lane' => $turn->lane->value,
            'handler' => $turn->handler,
            'retry_count' => $turn->retry_count,
            'retry_eligibility' => $this->retryEligibility($turn),
            'side_effect_status' => $turn->side_effect_status->value,
            'message_ids' => [
                'user' => $turn->user_message_id,
                'final_assistant' => $turn->final_assistant_message_id,
            ],
            'timestamps' => [
                'created_at' => $this->iso($turn->created_at),
                'accepted_at' => $this->iso($turn->accepted_at),
                'started_at' => $this->iso($turn->started_at),
                'first_progress_at' => $this->iso($turn->first_progress_at),
                'acknowledgement_started_at' => $this->iso($turn->acknowledged_at),
                'terminal_at' => $this->iso($turn->terminal_at),
                'final_text_delivered_at' => $this->iso($turn->final_delivered_at),
                'updated_at' => $this->iso($turn->updated_at),
            ],
            'latency_ms' => $latencies,
            'deadlines' => [
                'hard_at' => $this->iso($turn->hard_deadline_at),
                'no_progress_at' => $this->iso($turn->no_progress_deadline_at),
                ...$deadline,
            ],
            'benchmark_classification' => [
                'hard_deadline' => $deadline['hard_status'],
                'no_progress_deadline' => $deadline['no_progress_status'],
                'single_turn_percentile_claim_allowed' => false,
                'note' => 'Percentile pass/fail is reported only by aggregate gates with sufficient samples.',
            ],
            'acknowledgement' => [
                'required' => $turn->acknowledgement_required,
                'started' => $turn->acknowledged_at !== null,
                'speech_item_ids' => $this->speechItemIds($events, 'acknowledgement'),
            ],
            'delivery' => $this->delivery($turn, $events),
            'failure' => $this->failure($turn, $runs),
            'browser' => $this->browserDiagnostics($turn, $events),
            'jobs' => $runs->map(fn (AssistantRun $run): array => $this->job($run, $turn))->values()->all(),
            'provider_and_tool_calls' => $this->providerAndToolCalls($turn, $runs, $activity),
            'lifecycle_timeline' => $events->map(fn (VoiceTurnEvent $event): array => $this->event($event))->values()->all(),
            'diagnostic_integrity' => [
                'event_max_version' => $eventMaxVersion,
                'snapshot_event_divergence' => $events->isEmpty() || $eventMaxVersion !== (int) $turn->version,
                'raw_audio_persistence_detected' => $rawAudioDetected,
            ],
        ];
    }

    /**
     * @param  Collection<int, AssistantRun>  $runs
     */
    private function activityBelongsToTurn(ActivityEvent $event, VoiceTurn $turn, Collection $runs): bool
    {
        $payload = is_array($event->payload) ? $event->payload : [];
        $messageIds = array_filter([
            (int) $turn->user_message_id,
            (int) $turn->final_assistant_message_id,
        ]);
        foreach (['message_id', 'user_message_id', 'source_message_id'] as $key) {
            if (in_array((int) data_get($payload, $key, 0), $messageIds, true)) {
                return true;
            }
        }

        $runIds = $runs->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        if (in_array((int) data_get($payload, 'run_id', 0), $runIds, true)) {
            return true;
        }

        $payloadTurnId = data_get($payload, 'voice_turn_id');

        return (string) $payloadTurnId === (string) $turn->id
            || (string) $payloadTurnId === (string) $turn->turn_id;
    }

    /**
     * @param  Collection<int, VoiceTurnEvent>  $events
     * @return array<string, int|null>
     */
    private function turnLatencies(VoiceTurn $turn, Collection $events): array
    {
        $metadata = is_array($turn->metadata) ? $turn->metadata : [];
        $transcriptFinal = $this->epochMilliseconds(data_get($metadata, 'transcript_timing.final_at_ms'));
        $ackEvent = $events->firstWhere('event_type', 'acknowledgement_started');
        $finalTextEvent = $events->firstWhere('event_type', 'final_text_delivered');
        $finalAudioEvent = $events->first(fn (VoiceTurnEvent $event): bool => $this->eventMatchesPlaybackPurpose(
            $event,
            ['final_audio_started', 'playback_started'],
            'final',
        ));
        $bargeStopEvent = $events->first(fn (VoiceTurnEvent $event): bool => in_array($event->event_type, [
            'confirmed_barge_in',
            'playback_stopped_for_interruption',
        ], true));

        return [
            'wake_recognition' => $this->numericMetadata($metadata, [
                'telemetry.wake_recognition_ms',
                'voice_quality.wake_recognition_ms',
                'wake_recognition_ms',
            ]),
            'live_transcript_update' => $this->numericMetadata($metadata, [
                'telemetry.live_transcript_update_ms',
                'voice_quality.live_transcript_update_ms',
                'live_transcript_update_ms',
            ]),
            'transcription_duration' => $this->numericMetadata($metadata, ['transcript_timing.duration_ms']),
            'transcript_to_durable_admission' => $this->numericMetadata($metadata, [
                'telemetry.durable_admission_ms',
                'voice_quality.durable_admission_ms',
            ]) ?? $this->elapsed($transcriptFinal, $turn->accepted_at),
            'transcript_to_acknowledgement_audio_start' => $this->eventLatency($ackEvent, $transcriptFinal),
            'accepted_to_first_progress' => $this->elapsed($turn->accepted_at, $turn->first_progress_at),
            'transcript_to_first_progress' => $this->elapsed($transcriptFinal, $turn->first_progress_at),
            'transcript_to_server_terminal' => $this->elapsed($transcriptFinal, $turn->terminal_at),
            'transcript_to_final_text_delivery' => $this->eventLatency($finalTextEvent, $transcriptFinal)
                ?? $this->elapsed($transcriptFinal, $turn->final_delivered_at),
            'transcript_to_final_audio_start' => $this->eventLatency($finalAudioEvent, $transcriptFinal),
            'confirmed_barge_in_to_playback_stop' => $this->eventPayloadMilliseconds($bargeStopEvent),
        ];
    }

    /** @return array<string, string|bool> */
    private function deadlineStatus(VoiceTurn $turn): array
    {
        $now = now();
        $terminalOrNow = $turn->terminal_at ?? $now;
        $hardMissed = $turn->hard_deadline_at !== null && $terminalOrNow->gt($turn->hard_deadline_at);
        $noProgressMissed = $turn->no_progress_deadline_at !== null
            && $terminalOrNow->gt($turn->no_progress_deadline_at);

        return [
            'hard_status' => $turn->hard_deadline_at === null
                ? 'not_measured'
                : ($hardMissed ? 'failing' : ($turn->state->isTerminal() ? 'passing' : 'pending')),
            'hard_overdue' => $hardMissed && ! $turn->state->isTerminal(),
            'no_progress_status' => $turn->no_progress_deadline_at === null
                ? 'not_applicable'
                : ($noProgressMissed ? 'failing' : ($turn->state->isTerminal() ? 'passing' : 'pending')),
            'no_progress_overdue' => $noProgressMissed && ! $turn->state->isTerminal(),
        ];
    }

    /** @return array<string, mixed> */
    private function retryEligibility(VoiceTurn $turn): array
    {
        if ($turn->retry_count >= 1) {
            return ['eligible' => false, 'reason' => 'retry_limit_reached'];
        }
        if ($turn->lane === VoiceTurnLane::Instant) {
            return ['eligible' => false, 'reason' => 'instant_operations_do_not_retry'];
        }
        if (in_array($turn->lane, [VoiceTurnLane::AppRead, VoiceTurnLane::External], true)) {
            return ['eligible' => true, 'reason' => 'one_read_only_retry_within_original_deadline'];
        }
        if ($turn->lane === VoiceTurnLane::AppWrite) {
            $eligible = $turn->side_effect_status->value === 'not_committed';

            return [
                'eligible' => $eligible,
                'reason' => $eligible
                    ? 'authoritative_state_reports_not_committed'
                    : 'write_retry_requires_authoritative_reconciliation',
            ];
        }

        $eligible = in_array($turn->side_effect_status->value, ['none', 'not_committed'], true);

        return [
            'eligible' => $eligible,
            'reason' => $eligible
                ? 'no_mutating_work_reported_committed'
                : 'mutating_work_may_have_committed',
        ];
    }

    /**
     * @param  Collection<int, VoiceTurnEvent>  $events
     * @return array<string, mixed>
     */
    private function delivery(VoiceTurn $turn, Collection $events): array
    {
        $finalAudioStarted = $events->contains(fn (VoiceTurnEvent $event): bool => $this->eventMatchesPlaybackPurpose(
            $event,
            ['final_audio_started', 'playback_started'],
            'final',
        ));
        $finalAudioStopped = $events->contains(fn (VoiceTurnEvent $event): bool => $this->eventMatchesPlaybackPurpose(
            $event,
            ['playback_stopped', 'playback_stopped_for_interruption'],
            'final',
        ));

        return [
            'final_text' => [
                'state' => $turn->final_delivered_at !== null
                    ? 'delivered'
                    : ($turn->final_assistant_message_id !== null ? 'ready_not_delivered' : 'not_ready'),
                'message_id' => $turn->final_assistant_message_id,
            ],
            'final_audio' => [
                'state' => $finalAudioStopped ? 'stopped' : ($finalAudioStarted ? 'started' : 'not_reported'),
                'speech_item_ids' => $this->speechItemIds($events, 'final'),
            ],
        ];
    }

    /**
     * @param  Collection<int, AssistantRun>  $runs
     * @return array<string, mixed>|null
     */
    private function failure(VoiceTurn $turn, Collection $runs): ?array
    {
        $runErrors = $runs
            ->pluck('error')
            ->filter(fn (mixed $error): bool => is_string($error) && trim($error) !== '')
            ->map(fn (string $error): string => $this->privacy->sanitizeTranscript($error))
            ->values()
            ->all();
        if ($turn->failure_category === null && $runErrors === []) {
            return null;
        }

        return [
            'category' => $turn->failure_category,
            'internal_detail' => $turn->internal_failure_detail === null
                ? null
                : $this->privacy->sanitizeTranscript($turn->internal_failure_detail),
            'user_facing_message' => $turn->user_facing_failure_text === null
                ? null
                : $this->privacy->sanitizeTranscript($turn->user_facing_failure_text),
            'run_errors' => $runErrors,
            'retry_eligibility' => $this->retryEligibility($turn),
            'side_effect_status' => $turn->side_effect_status->value,
        ];
    }

    /**
     * @param  Collection<int, VoiceTurnEvent>  $events
     * @return array<string, mixed>
     */
    private function browserDiagnostics(VoiceTurn $turn, Collection $events): array
    {
        $metadata = is_array($turn->metadata) ? $turn->metadata : [];
        $staleEvents = $events->filter(function (VoiceTurnEvent $event): bool {
            $reason = strtolower((string) data_get($event->payload, 'reason', ''));

            return str_contains($event->event_type, 'stale') || str_starts_with($reason, 'stale_');
        });
        $matching = fn (array $needles): array => $events
            ->filter(fn (VoiceTurnEvent $event): bool => collect($needles)->contains(
                fn (string $needle): bool => str_contains($event->event_type, $needle)
            ))
            ->map(fn (VoiceTurnEvent $event): array => [
                'event_id' => $event->id,
                'type' => $event->event_type,
                'created_at' => $this->iso($event->created_at),
                'payload' => $this->privacy->sanitizeDiagnosticPayload(is_array($event->payload) ? $event->payload : []),
            ])
            ->values()
            ->all();

        return [
            'controller_generation' => data_get($metadata, 'controller_generation'),
            'provider_connection_generation' => data_get($metadata, 'provider_connection_generation'),
            'rejected_stale_event_count' => $staleEvents->count(),
            'rejected_stale_event_ids' => $staleEvents->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'playback' => [
                'speech_item_ids' => $this->speechItemIds($events),
                'volume_transitions' => $matching(['playback_ducked', 'volume_changed', 'volume_restored']),
                'stop_events' => $matching(['playback_stopped', 'confirmed_barge_in', 'stop_playback']),
            ],
            'timer_transitions' => $matching(['clarification', 'conversation_timer', 'follow_up', 'endpoint']),
            'connection_and_reload_events' => $matching([
                'disconnect',
                'reconnect',
                'event_feed',
                'snapshot',
                'reconciliation',
                'reload',
            ]),
            'interruption_stop_and_cancellation_events' => $matching([
                'interruption',
                'barge',
                'playback_stopped',
                'turn_canceled',
                'job_canceled',
                'cancellation',
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function job(AssistantRun $run, VoiceTurn $turn): array
    {
        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $queueEnd = $run->started_at ?? $run->completed_at ?? $run->cancelled_at ?? now();
        $waitEnd = $run->started_at ?? $run->completed_at ?? $run->cancelled_at ?? now();

        return [
            'id' => $run->id,
            'label' => $run->label,
            'status' => $run->status,
            'lane' => $run->lane ?: $turn->lane->value,
            'handler' => $run->handler ?: $turn->handler,
            'priority' => $run->priority,
            'resource_lock_key' => $run->resource_lock_key,
            'idempotency_key' => $run->idempotency_key,
            'execution_call' => [
                'kind' => $this->executionKind($turn->lane),
                'name' => $run->handler ?: $turn->handler,
            ],
            'queue_wait_ms' => $this->elapsed($run->created_at, $queueEnd),
            'capacity_wait_ms' => $this->elapsed($this->parseTimestamp(data_get($metadata, 'capacity_wait_started_at')), $waitEnd),
            'resource_lock_wait_ms' => $this->elapsed($this->parseTimestamp(data_get($metadata, 'resource_wait_started_at')), $waitEnd),
            'hard_deadline_at' => $this->iso($run->hard_deadline_at),
            'last_progress_at' => $this->iso($run->last_progress_at),
            'dispatch_requested_at' => $this->iso($run->dispatch_requested_at),
            'started_at' => $this->iso($run->started_at),
            'completed_at' => $this->iso($run->completed_at),
            'canceled_at' => $this->iso($run->cancelled_at),
            'error' => $run->error === null ? null : $this->privacy->sanitizeTranscript($run->error),
        ];
    }

    private function executionKind(VoiceTurnLane $lane): string
    {
        return match ($lane) {
            VoiceTurnLane::Instant => 'local_handler',
            VoiceTurnLane::AppRead, VoiceTurnLane::AppWrite => 'typed_application_handler',
            VoiceTurnLane::External => 'typed_external_provider',
            VoiceTurnLane::ComplexAgent => 'agent_provider',
        };
    }

    /**
     * @param  Collection<int, AssistantRun>  $runs
     * @param  Collection<int, ActivityEvent>  $activity
     * @return array<int, array<string, mixed>>
     */
    private function providerAndToolCalls(VoiceTurn $turn, Collection $runs, Collection $activity): array
    {
        $calls = $runs->map(fn (AssistantRun $run): array => [
            'source' => 'assistant_run',
            'run_id' => $run->id,
            'kind' => $this->executionKind($turn->lane),
            'name' => $run->handler ?: $turn->handler,
            'status' => $run->status,
            'started_at' => $this->iso($run->started_at),
            'completed_at' => $this->iso($run->completed_at),
        ]);

        return $calls->concat($activity
            ->filter(function (ActivityEvent $event): bool {
                $type = strtolower($event->event_type);

                return filled($event->tool_name)
                    || data_get($event->payload, 'provider') !== null
                    || str_contains($type, 'tool')
                    || str_contains($type, 'model')
                    || str_contains($type, 'lookup');
            })
            ->map(fn (ActivityEvent $event): array => [
                'source' => 'activity_event',
                'activity_event_id' => $event->id,
                'kind' => data_get($event->payload, 'provider') !== null ? 'provider' : 'tool',
                'name' => $event->tool_name ?: $event->event_type,
                'event_type' => $event->event_type,
                'status' => $event->status,
                'created_at' => $this->iso($event->created_at),
                'payload' => $this->privacy->sanitizeDiagnosticPayload(is_array($event->payload) ? $event->payload : []),
            ]))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function event(VoiceTurnEvent $event): array
    {
        return [
            'event_id' => $event->id,
            'sequence' => $event->sequence,
            'type' => $event->event_type,
            'from_state' => $event->from_state,
            'to_state' => $event->to_state,
            'version' => $event->version,
            'source' => $event->source,
            'payload' => $this->privacy->sanitizeDiagnosticPayload(is_array($event->payload) ? $event->payload : []),
            'created_at' => $this->iso($event->created_at),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function latencyMetrics(Collection $rows): array
    {
        $metric = fn (string $field, ?string $lane = null): array => $this->metric(
            $rows
                ->when($lane !== null, fn (Collection $collection) => $collection->where('lane', $lane))
                ->pluck('latency_ms.'.$field)
                ->filter(fn (mixed $value): bool => is_numeric($value) && $value >= 0)
                ->map(fn (mixed $value): float => (float) $value)
                ->values()
                ->all(),
        );

        return [
            'wake_recognition_ms' => [...$metric('wake_recognition'), 'measurement_type' => 'client_audible_milestone'],
            'live_transcript_update_ms' => [...$metric('live_transcript_update'), 'measurement_type' => 'client_recognition_milestone'],
            'durable_admission_ms' => [...$metric('transcript_to_durable_admission'), 'measurement_type' => 'client_to_server_admission'],
            'acknowledgement_audio_start_ms' => [...$metric('transcript_to_acknowledgement_audio_start'), 'measurement_type' => 'client_audible_milestone'],
            'first_progress_ms' => [...$metric('transcript_to_first_progress'), 'measurement_type' => 'durable_server_progress'],
            'server_terminal_ms' => [...$metric('transcript_to_server_terminal'), 'measurement_type' => 'server_terminal_not_audio'],
            'final_text_delivery_ms' => [...$metric('transcript_to_final_text_delivery'), 'measurement_type' => 'client_text_delivery'],
            'final_audio_start_ms' => [...$metric('transcript_to_final_audio_start'), 'measurement_type' => 'client_audible_milestone'],
            'confirmed_barge_in_stop_ms' => [...$metric('confirmed_barge_in_to_playback_stop'), 'measurement_type' => 'client_playback_milestone'],
            'instant_final_audio_start_ms' => [...$metric('transcript_to_final_audio_start', VoiceTurnLane::Instant->value), 'measurement_type' => 'client_audible_milestone'],
            'app_read_final_audio_start_ms' => [...$metric('transcript_to_final_audio_start', VoiceTurnLane::AppRead->value), 'measurement_type' => 'client_audible_milestone'],
            'app_write_admission_ms' => [...$metric('transcript_to_durable_admission', VoiceTurnLane::AppWrite->value), 'measurement_type' => 'client_to_server_admission'],
            'app_write_final_audio_start_ms' => [...$metric('transcript_to_final_audio_start', VoiceTurnLane::AppWrite->value), 'measurement_type' => 'client_audible_milestone'],
            'external_final_audio_start_ms' => [...$metric('transcript_to_final_audio_start', VoiceTurnLane::External->value), 'measurement_type' => 'client_audible_milestone'],
            'complex_acknowledgement_audio_start_ms' => [...$metric('transcript_to_acknowledgement_audio_start', VoiceTurnLane::ComplexAgent->value), 'measurement_type' => 'client_audible_milestone'],
            'complex_first_progress_ms' => [...$metric('transcript_to_first_progress', VoiceTurnLane::ComplexAgent->value), 'measurement_type' => 'durable_server_progress'],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $metrics
     * @return array<string, array<string, mixed>>
     */
    private function benchmarkGates(array $metrics): array
    {
        return [
            'wake_recognition' => $this->gate('Wake recognition', $metrics['wake_recognition_ms'], ['p95_ms_lte' => 500]),
            'live_transcript_update' => $this->gate('Live transcript update', $metrics['live_transcript_update_ms'], ['p95_ms_lte' => 150]),
            'instant_final_audio_start' => $this->gate('Instant final audio start', $metrics['instant_final_audio_start_ms'], [
                'p50_ms_lte' => 500,
                'p95_ms_lte' => 1_000,
            ]),
            'app_read_final_audio_start' => $this->gate('Application read final audio start', $metrics['app_read_final_audio_start_ms'], [
                'p50_ms_lte' => 800,
                'p95_ms_lte' => 1_500,
            ]),
            'app_write_admission' => $this->gate('Application write acceptance and dock', $metrics['app_write_admission_ms'], ['p95_ms_lte' => 800]),
            'app_write_final_audio_start' => $this->gate('Simple application write final audio start', $metrics['app_write_final_audio_start_ms'], ['p95_ms_lte' => 3_000]),
            'acknowledgement_audio_start' => $this->gate('Acknowledgement audio start', $metrics['acknowledgement_audio_start_ms'], [
                'p50_ms_lte' => 500,
                'p95_ms_lte' => 800,
            ]),
            'external_final_audio_start' => $this->gate('Bounded external final audio start', $metrics['external_final_audio_start_ms'], [
                'p50_ms_lte' => 2_000,
                'p95_ms_lte' => 4_000,
            ]),
            'complex_acknowledgement_audio_start' => $this->gate('Complex-work acknowledgement audio start', $metrics['complex_acknowledgement_audio_start_ms'], ['p95_ms_lte' => 800]),
            'complex_first_progress' => $this->gate('Complex-work first meaningful progress', $metrics['complex_first_progress_ms'], ['p95_ms_lte' => 2_000]),
            'confirmed_barge_in_stop' => $this->gate('Confirmed barge-in playback stop', $metrics['confirmed_barge_in_stop_ms'], ['p95_ms_lte' => 200]),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, array<string, mixed>>  $gates
     * @return array<string, mixed>
     */
    private function alerts(Collection $rows, array $gates): array
    {
        $alert = fn (callable $predicate): array => [
            'count' => $rows->filter($predicate)->count(),
            'turn_ids' => $rows->filter($predicate)->pluck('turn_id')->values()->all(),
        ];

        return [
            'accepted_nonterminal_beyond_deadline' => $alert(fn (array $row): bool => $row['deadlines']['hard_overdue'] === true),
            'no_progress_deadline_exceeded' => $alert(fn (array $row): bool => $row['deadlines']['no_progress_overdue'] === true),
            'duplicate_finalization_attempt' => $alert(fn (array $row): bool => collect($row['lifecycle_timeline'])->contains(
                fn (array $event): bool => $event['type'] === 'finalization_deduplicated'
            )),
            'lifecycle_regression_attempt' => $alert(fn (array $row): bool => collect($row['lifecycle_timeline'])->contains(
                fn (array $event): bool => in_array($event['type'], ['transition_rejected', 'finalization_rejected'], true)
            )),
            'uncertain_side_effect_state' => $alert(fn (array $row): bool => $row['side_effect_status'] === 'uncertain'),
            'snapshot_event_divergence' => $alert(fn (array $row): bool => $row['diagnostic_integrity']['snapshot_event_divergence'] === true),
            'raw_audio_persistence_detected' => $alert(fn (array $row): bool => $row['diagnostic_integrity']['raw_audio_persistence_detected'] === true),
            'unusually_slow_turn' => $alert(fn (array $row): bool => in_array('failing', [
                $row['deadlines']['hard_status'],
                $row['deadlines']['no_progress_status'],
            ], true)),
            'benchmark_p95_regression' => [
                'count' => collect($gates)->where('status', 'failing')->count(),
                'gate_ids' => collect($gates)->filter(
                    fn (array $gate): bool => $gate['status'] === 'failing'
                )->keys()->values()->all(),
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, array<string, mixed>>  $metrics
     * @return array<string, mixed>
     */
    private function telemetryCoverage(Collection $rows, array $metrics): array
    {
        $turnCount = $rows->count();
        $coverage = [];
        foreach ($metrics as $key => $metric) {
            $samples = (int) $metric['sample_count'];
            $coverage[$key] = [
                'sample_count' => $samples,
                'turn_count' => $turnCount,
                'coverage_percent' => $turnCount > 0 ? round(($samples / $turnCount) * 100, 1) : null,
                'sufficient_for_percentile_gate' => $samples >= self::MINIMUM_BENCHMARK_SAMPLES,
            ];
        }

        return [
            'fields' => $coverage,
            'known_gaps' => [
                'wake_startup_failures_before_turn_admission' => 'A failed wake startup creates no durable turn, so this report cannot infer its failure rate from voice_turns.',
                'browser_only_events' => 'Playback, timer, reload, event-feed, and rejected-stale-event details appear only when the browser persists matching diagnostic events.',
                'final_audio_start' => 'Final text delivery is not treated as final audible playback; an explicit final-audio-start event is required.',
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<int, VoiceTurnState|VoiceTurnLane>  $cases
     * @return array<string, int>
     */
    private function enumCounts(Collection $rows, string $key, array $cases): array
    {
        $counts = [];
        foreach ($cases as $case) {
            $counts[$case->value] = $rows->where($key, $case->value)->count();
        }

        return $counts;
    }

    /**
     * @param  array<int, float>  $values
     * @return array<string, int|float|string|null>
     */
    private function metric(array $values): array
    {
        sort($values, SORT_NUMERIC);

        return [
            'sample_count' => count($values),
            'p50_ms' => $this->displayMilliseconds($this->nearestRank($values, 0.50)),
            'p95_ms' => $this->displayMilliseconds($this->nearestRank($values, 0.95)),
            'percentile_method' => 'nearest_rank',
        ];
    }

    /**
     * @param  array<string, mixed>  $metric
     * @param  array<string, int>  $targets
     * @return array<string, mixed>
     */
    private function gate(string $name, array $metric, array $targets): array
    {
        $sampleCount = (int) $metric['sample_count'];
        $passing = true;
        foreach ($targets as $targetName => $target) {
            $observedName = str_starts_with($targetName, 'p50_') ? 'p50_ms' : 'p95_ms';
            $passing = $passing && is_numeric($metric[$observedName]) && $metric[$observedName] <= $target;
        }
        $status = $sampleCount < self::MINIMUM_BENCHMARK_SAMPLES
            ? 'insufficient_data'
            : ($passing ? 'passing' : 'failing');

        return [
            'name' => $name,
            'status' => $status,
            'status_label' => match ($status) {
                'passing' => 'Passing',
                'failing' => 'Failing',
                default => 'Insufficient data',
            },
            'sample_count' => $sampleCount,
            'minimum_sample_count' => self::MINIMUM_BENCHMARK_SAMPLES,
            'sufficient_sample' => $sampleCount >= self::MINIMUM_BENCHMARK_SAMPLES,
            'observed' => [
                'p50_ms' => $metric['p50_ms'],
                'p95_ms' => $metric['p95_ms'],
            ],
            'targets' => $targets,
        ];
    }

    /**
     * @param  array<int, float>  $values
     */
    private function nearestRank(array $values, float $percentile): ?float
    {
        if ($values === []) {
            return null;
        }

        return $values[max(1, (int) ceil($percentile * count($values))) - 1];
    }

    private function displayMilliseconds(?float $value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        $rounded = round($value, 2);

        return floor($rounded) === $rounded ? (int) $rounded : $rounded;
    }

    /**
     * @param  Collection<int, VoiceTurnEvent>  $events
     * @return array<int, string>
     */
    private function speechItemIds(Collection $events, ?string $purpose = null): array
    {
        return $events
            ->filter(function (VoiceTurnEvent $event) use ($purpose): bool {
                if ($purpose === null) {
                    return true;
                }

                $eventPurpose = strtolower((string) data_get($event->payload, 'purpose', ''));
                if ($eventPurpose !== '') {
                    return $eventPurpose === $purpose;
                }

                return $purpose === 'acknowledgement' && $event->event_type === 'acknowledgement_started';
            })
            ->map(fn (VoiceTurnEvent $event): string => trim((string) (
                data_get($event->payload, 'speech_item_id')
                ?: data_get($event->payload, 'item_id')
            )))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function eventMatchesPlaybackPurpose(VoiceTurnEvent $event, array $eventTypes, string $purpose): bool
    {
        if (! in_array($event->event_type, $eventTypes, true)) {
            return false;
        }

        $eventPurpose = strtolower((string) data_get($event->payload, 'purpose', ''));

        return $eventPurpose === '' || $eventPurpose === $purpose;
    }

    private function eventLatency(?VoiceTurnEvent $event, ?CarbonInterface $transcriptFinal): ?int
    {
        if (! $event instanceof VoiceTurnEvent) {
            return null;
        }

        $recorded = $this->eventPayloadMilliseconds($event);
        if ($recorded !== null) {
            return $recorded;
        }
        $occurredAt = $this->epochMilliseconds(data_get($event->payload, 'occurred_at_ms'));

        return $this->elapsed($transcriptFinal, $occurredAt ?? $event->created_at);
    }

    private function eventPayloadMilliseconds(?VoiceTurnEvent $event): ?int
    {
        if (! $event instanceof VoiceTurnEvent) {
            return null;
        }

        $value = data_get($event->payload, 'latency_ms');

        return is_numeric($value) && (float) $value >= 0 ? (int) round((float) $value) : null;
    }

    /** @param array<string, mixed> $metadata */
    private function numericMetadata(array $metadata, array $paths): ?int
    {
        foreach ($paths as $path) {
            $value = data_get($metadata, $path);
            if (is_numeric($value) && (float) $value >= 0) {
                return (int) round((float) $value);
            }
        }

        return null;
    }

    private function epochMilliseconds(mixed $value): ?CarbonImmutable
    {
        if (! is_numeric($value) || (float) $value < 946_684_800_000) {
            return null;
        }

        try {
            return CarbonImmutable::createFromTimestampMs((int) round((float) $value), 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function elapsed(?CarbonInterface $from, ?CarbonInterface $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        $milliseconds = (int) round((((float) $to->format('U.u')) - ((float) $from->format('U.u'))) * 1_000);

        return $milliseconds >= 0 ? $milliseconds : null;
    }

    private function iso(?CarbonInterface $timestamp): ?string
    {
        return $timestamp?->toIso8601String();
    }

    /**
     * @param  Collection<int, VoiceTurnEvent>  $events
     * @param  Collection<int, AssistantRun>  $runs
     * @param  Collection<int, ActivityEvent>  $activity
     */
    private function rawAudioDetected(VoiceTurn $turn, Collection $events, Collection $runs, Collection $activity): bool
    {
        $metadata = is_array($turn->metadata) ? $turn->metadata : [];
        if (data_get($metadata, 'raw_audio_retained') === true || $this->containsRawAudio($metadata)) {
            return true;
        }

        foreach ($events->pluck('payload')->concat($runs->pluck('metadata'))->concat($runs->pluck('result'))->concat($activity->pluck('payload')) as $payload) {
            if (is_array($payload) && $this->containsRawAudio($payload)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    private function containsRawAudio(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            $normalized = strtolower((string) $key);
            if ($normalized === 'raw_audio_retained') {
                if ($value === true) {
                    return true;
                }

                continue;
            }
            if ($this->privacy->isRawAudioKey($normalized)) {
                return true;
            }
            if (is_array($value) && $this->containsRawAudio($value)) {
                return true;
            }
        }

        return false;
    }
}
