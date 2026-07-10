<?php

namespace Tests\Unit;

use App\Models\ConversationMessage;
use App\Services\VoiceQualityReportService;
use Tests\TestCase;

class VoiceQualityReportServiceTest extends TestCase
{
    private int $messageId = 1;

    public function test_it_deduplicates_turn_copies_and_calculates_nearest_rank_percentiles(): void
    {
        $messages = [
            $this->message(1, 'user', 'direct-one', null, $this->directQuality(100, 1_100)),
            $this->message(1, 'assistant', 'direct-one', null, $this->directQuality(100, 1_100)),
            $this->message(1, 'user', 'direct-two', null, $this->directQuality(300, 1_300)),
            $this->message(1, 'assistant', 'direct-two', null, $this->directQuality(300, 1_300)),
            // Turn identifiers are scoped to a conversation session.
            $this->message(2, 'assistant', 'direct-one', null, $this->directQuality(500, 1_500)),
            // Older tool turns can have only metadata.client_request_id.
            $this->message(1, 'user', null, 'tool-one', $this->toolQuality(200)),
            $this->message(1, 'assistant', null, 'tool-one', $this->toolQuality(200)),
            $this->message(1, 'user', null, 'tool-two', $this->toolQuality(600)),
            $this->message(1, 'assistant', null, 'tool-two', $this->toolQuality(600)),
        ];

        $report = app(VoiceQualityReportService::class)->aggregate($messages);

        $this->assertSame(9, data_get($report, 'population.source_message_count'));
        $this->assertSame(5, data_get($report, 'population.unique_turn_count'));
        $this->assertSame(4, data_get($report, 'population.duplicate_message_count'));
        $this->assertSame(3, data_get($report, 'population.route_turn_counts.direct'));
        $this->assertSame(2, data_get($report, 'population.route_turn_counts.tool'));

        $this->assertSame(3, data_get($report, 'metrics.direct_transcript_to_audio_start_ms.sample_count'));
        $this->assertSame(300, data_get($report, 'metrics.direct_transcript_to_audio_start_ms.p50_ms'));
        $this->assertSame(500, data_get($report, 'metrics.direct_transcript_to_audio_start_ms.p95_ms'));
        $this->assertSame(1_300, data_get($report, 'metrics.direct_response_duration_ms.p50_ms'));
        $this->assertSame(1_500, data_get($report, 'metrics.direct_response_duration_ms.p95_ms'));
        $this->assertSame(2, data_get($report, 'metrics.tool_transcript_to_request_start_ms.sample_count'));
        $this->assertSame(200, data_get($report, 'metrics.tool_transcript_to_request_start_ms.p50_ms'));
        $this->assertSame(600, data_get($report, 'metrics.tool_transcript_to_request_start_ms.p95_ms'));
        $this->assertSame('provider_playback_signal_proxy', data_get($report, 'metrics.direct_transcript_to_audio_start_ms.measurement_type'));
        $this->assertSame(0, data_get($report, 'metrics.direct_transcript_to_audible_audio_start_ms.sample_count'));
        $this->assertSame(0, data_get($report, 'metrics.tool_transcript_to_acknowledgement_audio_start_ms.sample_count'));
    }

    public function test_proxy_metrics_never_pass_a_benchmark_gate(): void
    {
        $messages = [];

        for ($index = 1; $index <= VoiceQualityReportService::MINIMUM_GATE_SAMPLES; $index++) {
            $messages[] = $this->message(1, 'assistant', 'direct-'.$index, null, $this->directQuality(100, 900));
            $messages[] = $this->message(1, 'user', null, 'tool-'.$index, $this->toolQuality(100));
        }

        $report = app(VoiceQualityReportService::class)->aggregate($messages);

        $this->assertSame('insufficient_data', data_get($report, 'gates.direct_audio_start_latency.status'));
        $this->assertNull(data_get($report, 'gates.direct_audio_start_latency.observed.p50_ms'));
        $this->assertSame('insufficient_data', data_get($report, 'gates.tool_acknowledgement_audio_start_latency.status'));
        $this->assertNull(data_get($report, 'gates.tool_acknowledgement_audio_start_latency.observed.p95_ms'));
        $this->assertSame(700, data_get($report, 'gates.tool_acknowledgement_audio_start_latency.targets.p95_ms_lte'));
        $this->assertFalse(data_get($report, 'metrics.direct_transcript_to_audio_start_ms.documented_target_available'));
        $this->assertFalse(data_get($report, 'metrics.tool_transcript_to_request_start_ms.documented_target_available'));
        $this->assertFalse(data_get($report, 'metrics.direct_response_duration_ms.documented_target_available'));
    }

    public function test_it_evaluates_targets_only_from_benchmark_equivalent_fields(): void
    {
        $messages = [];

        for ($index = 1; $index <= VoiceQualityReportService::MINIMUM_GATE_SAMPLES; $index++) {
            $directAudible = $index <= 10 ? 700 : 1_500;
            $toolAcknowledgement = $index <= 18 ? 400 : 701;
            $toolFinal = $index <= 10 ? 2_500 : 7_000;
            $messages[] = $this->message(
                1,
                'assistant',
                'direct-exact-'.$index,
                null,
                $this->directQuality(100, 900, $directAudible),
            );
            $messages[] = $this->message(
                1,
                'user',
                null,
                'tool-exact-'.$index,
                $this->toolQuality(100, $toolAcknowledgement, $toolFinal),
            );
        }

        $report = app(VoiceQualityReportService::class)->aggregate($messages);

        $this->assertSame('passing', data_get($report, 'gates.direct_audio_start_latency.status'));
        $this->assertSame(700, data_get($report, 'gates.direct_audio_start_latency.observed.p50_ms'));
        $this->assertSame(1_500, data_get($report, 'gates.direct_audio_start_latency.observed.p95_ms'));
        $this->assertSame('failing', data_get($report, 'gates.tool_acknowledgement_audio_start_latency.status'));
        $this->assertSame(701, data_get($report, 'gates.tool_acknowledgement_audio_start_latency.observed.p95_ms'));
        $this->assertSame('passing', data_get($report, 'gates.tool_final_audio_start_latency.status'));
        $this->assertSame(2_500, data_get($report, 'gates.tool_final_audio_start_latency.observed.p50_ms'));
        $this->assertSame(7_000, data_get($report, 'gates.tool_final_audio_start_latency.observed.p95_ms'));
    }

    public function test_it_counts_accepted_and_terminal_direct_voice_outcomes_once_per_turn(): void
    {
        $messages = [
            $this->message(1, 'user', 'accepted-turn', null, $this->directQuality(100, 500), 'accepted'),
            $this->message(1, 'user', 'completed-turn', null, $this->directQuality(120, 600), 'completed'),
            $this->message(1, 'assistant', 'completed-turn', null, $this->directQuality(120, 600), 'completed'),
            $this->message(1, 'user', 'interrupted-turn', null, $this->directQuality(140, 300), 'interrupted'),
            $this->message(1, 'user', 'timeout-turn', null, $this->directQuality(160, 20_000), 'timed_out'),
        ];

        $report = app(VoiceQualityReportService::class)->aggregate($messages);

        $this->assertSame(4, data_get($report, 'population.route_turn_counts.direct'));
        $this->assertSame(1, data_get($report, 'population.route_outcome_counts.direct.accepted'));
        $this->assertSame(1, data_get($report, 'population.route_outcome_counts.direct.completed'));
        $this->assertSame(1, data_get($report, 'population.route_outcome_counts.direct.interrupted'));
        $this->assertSame(1, data_get($report, 'population.route_outcome_counts.direct.timed_out'));
    }

    /**
     * @param  array<string, int|string>  $quality
     */
    private function message(
        int $sessionId,
        string $role,
        ?string $clientTurnId,
        ?string $clientRequestId,
        array $quality,
        ?string $outcome = null,
    ): ConversationMessage {
        $metadata = ['voice_quality' => $quality];
        if ($clientRequestId !== null) {
            $metadata['client_request_id'] = $clientRequestId;
        }
        if ($outcome !== null) {
            $metadata['voice_turn_outcome'] = ['status' => $outcome];
        }

        $message = new ConversationMessage;
        $message->forceFill([
            'id' => $this->messageId++,
            'conversation_session_id' => $sessionId,
            'client_turn_id' => $clientTurnId,
            'role' => $role,
            'metadata' => $metadata,
        ]);

        return $message;
    }

    /**
     * @return array<string, int|string>
     */
    private function directQuality(int $audioStart, int $duration, ?int $audibleAudioStart = null): array
    {
        return array_filter([
            'schema_version' => 1,
            'route' => 'direct',
            'transcript_to_audio_start_ms' => $audioStart,
            'transcript_to_audible_audio_start_ms' => $audibleAudioStart,
            'response_duration_ms' => $duration,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, int|string>
     */
    private function toolQuality(int $requestStart, ?int $acknowledgementAudioStart = null, ?int $finalAudioStart = null): array
    {
        return array_filter([
            'schema_version' => 1,
            'route' => 'tool',
            'transcript_to_request_start_ms' => $requestStart,
            'transcript_to_acknowledgement_audio_start_ms' => $acknowledgementAudioStart,
            'transcript_to_final_audio_start_ms' => $finalAudioStart,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
