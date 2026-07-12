<?php

namespace App\Services;

use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class VoiceQualityReportService
{
    public const DEFAULT_WINDOW_DAYS = 7;

    public const MAX_WINDOW_DAYS = 90;

    public const MINIMUM_GATE_SAMPLES = 20;

    private const DIRECT_AUDIO_P50_TARGET_MS = 700;

    private const DIRECT_AUDIO_P95_TARGET_MS = 1_500;

    private const TOOL_ACK_AUDIO_P95_TARGET_MS = 700;

    private const TOOL_FINAL_AUDIO_P50_TARGET_MS = 3_000;

    private const TOOL_FINAL_AUDIO_P95_TARGET_MS = 8_000;

    public function __construct(
        private readonly BrowserVoiceV2DiagnosticsReportService $browserVoiceV2Diagnostics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(CarbonInterface $from, CarbonInterface $to): array
    {
        $messages = ConversationMessage::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('metadata->voice_quality')
            ->orderBy('id')
            ->get([
                'id',
                'conversation_session_id',
                'client_turn_id',
                'role',
                'metadata',
                'created_at',
            ]);

        $aggregate = $this->aggregate($messages);

        return [
            'generated_at' => now()->toIso8601String(),
            'window' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'timezone' => 'UTC',
                'default_days' => self::DEFAULT_WINDOW_DAYS,
                'max_days' => self::MAX_WINDOW_DAYS,
            ],
            ...$aggregate,
            'browser_voice_v2' => $this->browserVoiceV2Diagnostics->report($from, $to),
            'outcome_coverage' => $this->outcomeCoverage(
                $messages,
                $aggregate['population']['route_turn_counts'],
                $aggregate['population']['route_outcome_counts'],
            ),
        ];
    }

    /**
     * Aggregate already-loaded messages in PHP so percentile behavior is identical across
     * SQLite, MySQL, and PostgreSQL.
     *
     * @param  iterable<int, ConversationMessage>  $messages
     * @return array<string, mixed>
     */
    public function aggregate(iterable $messages): array
    {
        $turns = [];
        $sourceMessageCount = 0;

        foreach ($messages as $message) {
            $metadata = is_array($message->metadata) ? $message->metadata : [];
            $quality = data_get($metadata, 'voice_quality');
            if (! is_array($quality)) {
                continue;
            }

            $sourceMessageCount++;
            $turnKey = $this->turnKey($message, $metadata);
            $turns[$turnKey] ??= [];
            $turns[$turnKey][] = [
                'role' => (string) $message->role,
                'quality' => $quality,
                'outcome' => strtolower(trim((string) data_get($metadata, 'voice_turn_outcome.status', ''))),
            ];
        }

        $values = [
            'direct_transcript_to_audio_start_ms' => [],
            'direct_transcript_to_audible_audio_start_ms' => [],
            'direct_response_duration_ms' => [],
            'tool_transcript_to_request_start_ms' => [],
            'tool_transcript_to_acknowledgement_audio_start_ms' => [],
            'tool_transcript_to_final_audio_start_ms' => [],
        ];
        $routeTurnCounts = [
            'direct' => 0,
            'status' => 0,
            'tool' => 0,
            'unknown' => 0,
        ];
        $outcomeTemplate = [
            'accepted' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'interrupted' => 0,
            'failed' => 0,
            'timed_out' => 0,
            'superseded' => 0,
            'abandoned' => 0,
            'unknown' => 0,
        ];
        $routeOutcomeCounts = [
            'direct' => $outcomeTemplate,
            'status' => $outcomeTemplate,
            'tool' => $outcomeTemplate,
            'unknown' => $outcomeTemplate,
        ];

        foreach ($turns as $records) {
            $route = $this->canonicalRoute($records);
            $routeTurnCounts[$route]++;
            $routeOutcomeCounts[$route][$this->canonicalOutcome($records)]++;

            if ($route === 'direct') {
                $this->appendTurnMetric(
                    $values['direct_transcript_to_audio_start_ms'],
                    $records,
                    $route,
                    'transcript_to_audio_start_ms',
                );
                $this->appendTurnMetric(
                    $values['direct_response_duration_ms'],
                    $records,
                    $route,
                    'response_duration_ms',
                );
                $this->appendTurnMetric(
                    $values['direct_transcript_to_audible_audio_start_ms'],
                    $records,
                    $route,
                    'transcript_to_audible_audio_start_ms',
                );
            }

            if ($route === 'tool') {
                $this->appendTurnMetric(
                    $values['tool_transcript_to_request_start_ms'],
                    $records,
                    $route,
                    'transcript_to_request_start_ms',
                );
                $this->appendTurnMetric(
                    $values['tool_transcript_to_acknowledgement_audio_start_ms'],
                    $records,
                    $route,
                    'transcript_to_acknowledgement_audio_start_ms',
                );
                $this->appendTurnMetric(
                    $values['tool_transcript_to_final_audio_start_ms'],
                    $records,
                    $route,
                    'transcript_to_final_audio_start_ms',
                );
            }
        }

        $metrics = [
            'direct_transcript_to_audio_start_ms' => [
                ...$this->metric($values['direct_transcript_to_audio_start_ms'], false),
                'measurement_type' => 'provider_playback_signal_proxy',
                'measurement_note' => 'Derived from OpenAI Realtime output_audio_buffer.started for any direct turn that reached that signal, including interrupted turns. It may precede user-audible playback and cannot pass the audible-audio benchmark gate.',
            ],
            'direct_transcript_to_audible_audio_start_ms' => [
                ...$this->metric($values['direct_transcript_to_audible_audio_start_ms'], true),
                'measurement_type' => 'benchmark_equivalent',
                'measurement_note' => 'Requires a client-observed, per-response user-audible playback milestone. The current client does not emit this field.',
            ],
            'direct_response_duration_ms' => [
                ...$this->metric($values['direct_response_duration_ms'], false),
                'measurement_type' => 'provider_response_lifecycle',
                'measurement_note' => 'Measures the tracked provider response lifecycle through completion, interruption, failure, or the client watchdog. Use outcome counts to interpret this mixed population.',
            ],
            'tool_transcript_to_request_start_ms' => [
                ...$this->metric($values['tool_transcript_to_request_start_ms'], false),
                'measurement_type' => 'client_request_dispatch',
                'measurement_note' => 'Measures Laravel request dispatch, not spoken acknowledgement or final speech, and has no benchmark gate.',
            ],
            'tool_transcript_to_acknowledgement_audio_start_ms' => [
                ...$this->metric($values['tool_transcript_to_acknowledgement_audio_start_ms'], true),
                'measurement_type' => 'benchmark_equivalent',
                'measurement_note' => 'Requires a client-observed, per-response user-audible acknowledgement milestone. The current client does not emit this field.',
            ],
            'tool_transcript_to_final_audio_start_ms' => [
                ...$this->metric($values['tool_transcript_to_final_audio_start_ms'], true),
                'measurement_type' => 'benchmark_equivalent',
                'measurement_note' => 'Requires a client-observed, per-response user-audible final-answer milestone. The current client does not emit this field.',
            ],
        ];

        return [
            'population' => [
                'source_message_count' => $sourceMessageCount,
                'unique_turn_count' => count($turns),
                'duplicate_message_count' => max(0, $sourceMessageCount - count($turns)),
                'route_turn_counts' => $routeTurnCounts,
                'route_outcome_counts' => $routeOutcomeCounts,
                'deduplication' => [
                    'scope' => 'conversation_session_id',
                    'identifier_precedence' => [
                        'client_turn_id',
                        'metadata.client_turn_id',
                        'metadata.client_request_id',
                    ],
                ],
            ],
            'metrics' => $metrics,
            'gates' => [
                'direct_audio_start_latency' => $this->gate(
                    'Direct-answer user-audible audio start latency',
                    $metrics['direct_transcript_to_audible_audio_start_ms'],
                    [
                        'p50_ms_lte' => self::DIRECT_AUDIO_P50_TARGET_MS,
                        'p95_ms_lte' => self::DIRECT_AUDIO_P95_TARGET_MS,
                    ],
                ),
                'tool_acknowledgement_audio_start_latency' => $this->gate(
                    'Tool acknowledgement user-audible audio start latency',
                    $metrics['tool_transcript_to_acknowledgement_audio_start_ms'],
                    ['p95_ms_lte' => self::TOOL_ACK_AUDIO_P95_TARGET_MS],
                ),
                'tool_final_audio_start_latency' => $this->gate(
                    'Tool final-answer user-audible audio start latency',
                    $metrics['tool_transcript_to_final_audio_start_ms'],
                    [
                        'p50_ms_lte' => self::TOOL_FINAL_AUDIO_P50_TARGET_MS,
                        'p95_ms_lte' => self::TOOL_FINAL_AUDIO_P95_TARGET_MS,
                    ],
                ),
            ],
            'coverage' => [
                'benchmark_equivalent_gate_fields' => [
                    'direct_audio_start_latency',
                    'tool_acknowledgement_audio_start_latency',
                    'tool_final_audio_start_latency',
                ],
                'proxy_only_fields' => [
                    'direct_transcript_to_audio_start_ms',
                    'tool_transcript_to_request_start_ms',
                ],
                'observed_without_documented_target' => [
                    'direct_response_duration_ms',
                ],
                'not_evaluated' => [
                    'wake_and_stop_safety' => 'Requires deterministic and noisy-audio replay evidence; latency telemetry cannot establish this gate.',
                    'false_activation_noise' => 'Requires long-running background-audio evidence; this report makes no activation-safety claim.',
                    'speech_quality_human_rating' => 'Requires blinded human ratings; response duration is not a speech-quality score.',
                ],
                'lifecycle_reconciliation' => [
                    'abrupt_client_exit_classification' => 'Accepted direct/status turns that remain non-terminal beyond the configured server deadline are classified as abandoned. This proves that the terminal update was missing, not that a browser crash specifically caused it.',
                    'abandon_after_seconds' => max(60, (int) config('services.openai.realtime_turn_abandon_after_seconds', 120)),
                ],
            ],
        ];
    }

    /**
     * @param  iterable<int, ConversationMessage>  $messages
     * @param  array{direct:int,status:int,tool:int,unknown:int}  $routeTurnCounts
     * @param  array<string, array<string, int>>  $routeOutcomeCounts
     * @return array<string, mixed>
     */
    private function outcomeCoverage(iterable $messages, array $routeTurnCounts, array $routeOutcomeCounts): array
    {
        $toolTurns = [];
        $directAndStatusTurns = [];

        foreach ($messages as $message) {
            $metadata = is_array($message->metadata) ? $message->metadata : [];
            $quality = data_get($metadata, 'voice_quality');
            if (! is_array($quality)) {
                continue;
            }

            $turnKey = $this->turnKey($message, $metadata);
            $route = strtolower(trim((string) ($quality['route'] ?? '')));
            if (in_array($route, ['direct', 'status'], true)) {
                $directAndStatusTurns[$turnKey] ??= [];
                $directAndStatusTurns[$turnKey][] = [
                    'role' => (string) $message->role,
                    'quality' => $quality,
                    'outcome' => strtolower(trim((string) data_get($metadata, 'voice_turn_outcome.status', ''))),
                    'accepted_at' => data_get($metadata, 'voice_turn_outcome.accepted_at'),
                    'created_at' => $message->created_at,
                ];
            }
            if ($route !== 'tool') {
                continue;
            }

            $toolTurns[$turnKey] ??= ['user_message_id' => null, 'client_request_id' => null];
            if ($message->role === 'user') {
                $toolTurns[$turnKey]['user_message_id'] = (int) $message->id;
                $toolTurns[$turnKey]['client_request_id'] = trim((string) data_get($metadata, 'client_request_id', '')) ?: null;
            }
        }

        $userMessageIds = array_values(array_unique(array_filter(array_column($toolTurns, 'user_message_id'))));
        $runsByUserMessageId = [];

        foreach (array_chunk($userMessageIds, 500) as $chunk) {
            AssistantRun::query()
                ->whereIn('user_message_id', $chunk)
                ->orderBy('id')
                ->get([
                    'id',
                    'user_message_id',
                    'status',
                    'error',
                    'created_at',
                    'started_at',
                    'completed_at',
                    'cancelled_at',
                    'metadata',
                ])
                ->each(function (AssistantRun $run) use (&$runsByUserMessageId): void {
                    $runsByUserMessageId[(int) $run->user_message_id][] = $run;
                });
        }

        $outcomes = [
            'completed' => 0,
            'cancelled' => 0,
            'failed' => 0,
            'hung' => 0,
            'in_flight' => 0,
            'unlinked' => 0,
            'unknown' => 0,
        ];
        $hungAfterSeconds = max(1, (int) config('services.hermes_runtime.assistant_run_stale_seconds', 210));

        foreach ($toolTurns as $turn) {
            $userMessageId = (int) ($turn['user_message_id'] ?? 0);
            $run = $this->runForToolTurn(
                $runsByUserMessageId[$userMessageId] ?? [],
                (string) ($turn['client_request_id'] ?? ''),
            );
            if (! $run instanceof AssistantRun) {
                $outcomes['unlinked']++;

                continue;
            }

            $outcomes[$this->runOutcome($run, $hungAfterSeconds)]++;
        }

        $linkedCount = count($toolTurns) - $outcomes['unlinked'];

        $directAndStatusOutcomes = [];
        foreach (['direct', 'status'] as $route) {
            foreach (($routeOutcomeCounts[$route] ?? []) as $outcome => $count) {
                $directAndStatusOutcomes[$outcome] = ($directAndStatusOutcomes[$outcome] ?? 0) + (int) $count;
            }
        }
        $directAndStatusTotal = $routeTurnCounts['direct'] + $routeTurnCounts['status'];
        $acceptedCount = $directAndStatusOutcomes['accepted'] ?? 0;
        $abandonAfterSeconds = max(60, (int) config('services.openai.realtime_turn_abandon_after_seconds', 120));
        $staleAcceptedCount = min(
            $acceptedCount,
            $this->staleAcceptedTurnCount($directAndStatusTurns, $abandonAfterSeconds),
        );
        $terminalCount = $directAndStatusTotal
            - $acceptedCount
            - ($directAndStatusOutcomes['unknown'] ?? 0);

        return [
            'direct_and_status_voice_turns' => [
                'denominator_available' => true,
                'total_accepted_turns' => $directAndStatusTotal,
                'terminal_outcome_count' => max(0, $terminalCount),
                'terminal_coverage_percent' => $directAndStatusTotal > 0
                    ? round((max(0, $terminalCount) / $directAndStatusTotal) * 100, 1)
                    : null,
                'persisted_success_sample_count' => $directAndStatusOutcomes['completed'] ?? 0,
                'completed_count' => $directAndStatusOutcomes['completed'] ?? 0,
                'cancelled_count' => ($directAndStatusOutcomes['cancelled'] ?? 0) + ($directAndStatusOutcomes['superseded'] ?? 0),
                'interrupted_count' => $directAndStatusOutcomes['interrupted'] ?? 0,
                'failed_count' => $directAndStatusOutcomes['failed'] ?? 0,
                'timed_out_count' => $directAndStatusOutcomes['timed_out'] ?? 0,
                'abandoned_count' => $directAndStatusOutcomes['abandoned'] ?? 0,
                'accepted_non_terminal_count' => $acceptedCount,
                'in_flight_count' => max(0, $acceptedCount - $staleAcceptedCount),
                'stale_accepted_unreconciled_count' => $staleAcceptedCount,
                'abandon_after_seconds' => $abandonAfterSeconds,
                'unknown_count' => $directAndStatusOutcomes['unknown'] ?? 0,
                'counts' => $directAndStatusOutcomes,
                'scope' => 'Accepted direct and status turns are persisted before response playback. Only a completed playback stores assistant text.',
            ],
            'tool_backend_runs' => [
                'denominator_scope' => 'Server-persisted tool turns carrying voice_quality metadata; these are backend run outcomes, not proof of acknowledgement or final audio playback.',
                'total_persisted_tool_turns' => count($toolTurns),
                'run_linked_turn_count' => $linkedCount,
                'run_link_coverage_percent' => count($toolTurns) > 0
                    ? round(($linkedCount / count($toolTurns)) * 100, 1)
                    : null,
                'hung_after_seconds' => $hungAfterSeconds,
                'counts' => $outcomes,
                'limitations' => [
                    'Unlinked turns include synchronous fast paths and requests that did not create an assistant_run.',
                    'Completed means backend run completion; final voice playback may still fail or be cancelled.',
                    'A queued or running run older than hung_after_seconds, or a timeout-class failure, is classified as hung.',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $turns
     */
    private function staleAcceptedTurnCount(array $turns, int $abandonAfterSeconds): int
    {
        $cutoff = CarbonImmutable::now()->subSeconds($abandonAfterSeconds);
        $stale = 0;

        foreach ($turns as $records) {
            if ($this->canonicalOutcome($records) !== 'accepted') {
                continue;
            }

            $acceptedAt = null;
            foreach ($records as $record) {
                if (($record['role'] ?? null) !== 'user') {
                    continue;
                }

                $createdAt = $record['created_at'] ?? null;
                $acceptedAt = $createdAt instanceof CarbonInterface
                    ? CarbonImmutable::instance($createdAt)
                    : null;
                $recordedAcceptedAt = $record['accepted_at'] ?? null;
                if (is_string($recordedAcceptedAt) && trim($recordedAcceptedAt) !== '') {
                    try {
                        $acceptedAt = CarbonImmutable::parse($recordedAcceptedAt);
                    } catch (\Throwable) {
                        // Fall back to the durable message timestamp for malformed legacy metadata.
                    }
                }

                break;
            }

            if ($acceptedAt?->lte($cutoff)) {
                $stale++;
            }
        }

        return $stale;
    }

    /**
     * @param  array<int, AssistantRun>  $runs
     */
    private function runForToolTurn(array $runs, string $clientRequestId): ?AssistantRun
    {
        $eligible = collect($runs)
            ->reject(fn (AssistantRun $run): bool => (bool) data_get($run->metadata, 'late_superseded_request_coalesced', false));
        $clientRequestId = trim($clientRequestId);
        if ($clientRequestId !== '') {
            $matched = $eligible->last(
                fn (AssistantRun $run): bool => trim((string) data_get($run->metadata, 'client_request_id', '')) === $clientRequestId
            );
            if ($matched instanceof AssistantRun) {
                return $matched;
            }
        }

        $latest = $eligible->last();

        return $latest instanceof AssistantRun ? $latest : null;
    }

    private function runOutcome(AssistantRun $run, int $hungAfterSeconds): string
    {
        $status = strtolower(trim((string) $run->status));
        if ($status === 'failed' && $this->looksHung($run->error)) {
            return 'hung';
        }

        if (in_array($status, ['completed', 'cancelled', 'failed'], true)) {
            return $status;
        }

        if (in_array($status, ['queued', 'running'], true)) {
            $startedAt = $run->started_at ?: $run->created_at;
            if ($startedAt?->lte(now()->subSeconds($hungAfterSeconds))) {
                return 'hung';
            }

            return 'in_flight';
        }

        return 'unknown';
    }

    private function looksHung(?string $error): bool
    {
        $normalized = strtolower((string) $error);

        return str_contains($normalized, 'timeout')
            || str_contains($normalized, 'timed out')
            || str_contains($normalized, 'did not complete within')
            || str_contains($normalized, 'expired before it could be safely recovered');
    }

    /**
     * @param  array<int, array{role:string, quality:array<string, mixed>, outcome:string}>  $records
     */
    private function canonicalRoute(array $records): string
    {
        usort($records, static function (array $left, array $right): int {
            return ($left['role'] === 'assistant' ? 0 : 1) <=> ($right['role'] === 'assistant' ? 0 : 1);
        });

        foreach ($records as $record) {
            $route = strtolower(trim((string) data_get($record, 'quality.route', '')));
            if (in_array($route, ['direct', 'status', 'tool'], true)) {
                return $route;
            }
        }

        return 'unknown';
    }

    /**
     * @param  array<int, array{role:string, quality:array<string, mixed>, outcome:string}>  $records
     */
    private function canonicalOutcome(array $records): string
    {
        $outcomes = collect($records)
            ->pluck('outcome')
            ->filter()
            ->unique();

        foreach (['completed', 'timed_out', 'abandoned', 'failed', 'interrupted', 'cancelled', 'superseded', 'accepted'] as $outcome) {
            if ($outcomes->contains($outcome)) {
                return $outcome;
            }
        }

        // Voice turns recorded before lifecycle outcomes shipped were persisted only after
        // success. An assistant copy therefore remains valid evidence of completion.
        return collect($records)->contains(fn (array $record): bool => $record['role'] === 'assistant')
            ? 'completed'
            : 'unknown';
    }

    /**
     * @param  array<int, float>  $values
     * @param  array<int, array{role:string, quality:array<string, mixed>, outcome:string}>  $records
     */
    private function appendTurnMetric(array &$values, array $records, string $route, string $field): void
    {
        foreach ($records as $record) {
            if (strtolower(trim((string) data_get($record, 'quality.route', ''))) !== $route) {
                continue;
            }

            $value = data_get($record, 'quality.'.$field);
            if (! is_int($value) && ! is_float($value)) {
                continue;
            }

            $value = (float) $value;
            if (! is_finite($value) || $value < 0) {
                continue;
            }

            $values[] = $value;

            return;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function turnKey(ConversationMessage $message, array $metadata): string
    {
        $turnId = trim((string) (
            $message->client_turn_id
            ?: data_get($metadata, 'client_turn_id')
            ?: data_get($metadata, 'client_request_id')
        ));
        $sessionId = (string) ($message->conversation_session_id ?? 'unknown');

        if ($turnId !== '') {
            return $sessionId.'|turn|'.$turnId;
        }

        return $sessionId.'|message|'.(string) ($message->id ?? spl_object_id($message));
    }

    /**
     * @param  array<int, float>  $values
     * @return array{sample_count:int,p50_ms:int|float|null,p95_ms:int|float|null,percentile_method:string,documented_target_available:bool}
     */
    private function metric(array $values, bool $hasDocumentedTarget): array
    {
        sort($values, SORT_NUMERIC);

        return [
            'sample_count' => count($values),
            'p50_ms' => $this->displayMilliseconds($this->nearestRank($values, 0.50)),
            'p95_ms' => $this->displayMilliseconds($this->nearestRank($values, 0.95)),
            'percentile_method' => 'nearest_rank',
            'documented_target_available' => $hasDocumentedTarget,
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
        if ($sampleCount < self::MINIMUM_GATE_SAMPLES) {
            $status = 'insufficient_data';
        } else {
            $passes = true;
            foreach ($targets as $key => $target) {
                $percentile = str_starts_with($key, 'p50_') ? 'p50_ms' : 'p95_ms';
                $passes = $passes && is_numeric($metric[$percentile]) && $metric[$percentile] <= $target;
            }
            $status = $passes ? 'passing' : 'failing';
        }

        return [
            'name' => $name,
            'status' => $status,
            'status_label' => match ($status) {
                'passing' => 'Passing',
                'failing' => 'Failing',
                default => 'Insufficient data',
            },
            'sample_count' => $sampleCount,
            'minimum_sample_count' => self::MINIMUM_GATE_SAMPLES,
            'observed' => [
                'p50_ms' => $metric['p50_ms'],
                'p95_ms' => $metric['p95_ms'],
            ],
            'targets' => $targets,
        ];
    }

    /**
     * @param  array<int, float>  $sortedValues
     */
    private function nearestRank(array $sortedValues, float $percentile): ?float
    {
        if ($sortedValues === []) {
            return null;
        }

        $rank = max(1, (int) ceil($percentile * count($sortedValues)));

        return $sortedValues[$rank - 1];
    }

    private function displayMilliseconds(?float $value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        $rounded = round($value, 2);

        return floor($rounded) === $rounded ? (int) $rounded : $rounded;
    }
}
