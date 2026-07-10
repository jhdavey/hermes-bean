<?php

namespace Tests\Feature;

use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\User;
use App\Services\VoiceQualityReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminVoiceQualityReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_voice_quality_report_is_admin_only_and_labels_insufficient_data(): void
    {
        $adminToken = $this->apiToken('voice-quality-admin@example.com');
        $userToken = $this->apiToken('voice-quality-user@example.com');
        User::where('email', 'voice-quality-admin@example.com')->firstOrFail()
            ->forceFill(['is_admin' => true])
            ->save();

        $this->getJson('/api/admin/voice-quality')->assertUnauthorized();
        $this->withToken($userToken)->getJson('/api/admin/voice-quality')->assertForbidden();

        $this->withToken($adminToken)->getJson('/api/admin/voice-quality')
            ->assertOk()
            ->assertJsonPath('data.window.default_days', VoiceQualityReportService::DEFAULT_WINDOW_DAYS)
            ->assertJsonPath('data.window.max_days', VoiceQualityReportService::MAX_WINDOW_DAYS)
            ->assertJsonPath('data.metrics.direct_transcript_to_audio_start_ms.sample_count', 0)
            ->assertJsonPath('data.metrics.direct_transcript_to_audio_start_ms.p50_ms', null)
            ->assertJsonPath('data.gates.direct_audio_start_latency.status', 'insufficient_data')
            ->assertJsonPath('data.gates.direct_audio_start_latency.status_label', 'Insufficient data')
            ->assertJsonPath('data.gates.tool_acknowledgement_audio_start_latency.status', 'insufficient_data')
            ->assertJsonPath('data.gates.tool_final_audio_start_latency.status', 'insufficient_data')
            ->assertJsonPath(
                'data.coverage.not_evaluated.wake_and_stop_safety',
                'Requires deterministic and noisy-audio replay evidence; latency telemetry cannot establish this gate.',
            )
            ->assertJsonPath(
                'data.coverage.not_evaluated.speech_quality_human_rating',
                'Requires blinded human ratings; response duration is not a speech-quality score.',
            );
    }

    public function test_report_deduplicates_proxy_metrics_without_claiming_a_benchmark_pass(): void
    {
        $adminToken = $this->apiToken('voice-quality-metrics@example.com');
        $admin = User::where('email', 'voice-quality-metrics@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true])->save();
        $session = ConversationSession::where('user_id', $admin->id)->firstOrFail();

        for ($index = 1; $index <= VoiceQualityReportService::MINIMUM_GATE_SAMPLES; $index++) {
            $directValue = $index <= 10 ? 600 : 1_400;
            $duration = $index <= 10 ? 1_000 : 2_000;
            $directQuality = [
                'schema_version' => 1,
                'route' => 'direct',
                'transcript_to_audio_start_ms' => $directValue,
                'response_duration_ms' => $duration,
            ];
            $clientTurnId = 'direct-'.$index;
            $this->qualityMessage($admin, $session, 'user', $directQuality, $clientTurnId);
            $this->qualityMessage($admin, $session, 'assistant', $directQuality, $clientTurnId);

            $toolValue = $index <= 18 ? 400 : 900;
            $toolQuality = [
                'schema_version' => 1,
                'route' => 'tool',
                'transcript_to_request_start_ms' => $toolValue,
            ];
            $clientRequestId = 'tool-'.$index;
            $this->qualityMessage($admin, $session, 'user', $toolQuality, null, $clientRequestId);
            $this->qualityMessage($admin, $session, 'assistant', $toolQuality, null, $clientRequestId);
        }

        $this->withToken($adminToken)->getJson('/api/admin/voice-quality')
            ->assertOk()
            ->assertJsonPath('data.population.source_message_count', 80)
            ->assertJsonPath('data.population.unique_turn_count', 40)
            ->assertJsonPath('data.population.duplicate_message_count', 40)
            ->assertJsonPath('data.metrics.direct_transcript_to_audio_start_ms.sample_count', 20)
            ->assertJsonPath('data.metrics.direct_transcript_to_audio_start_ms.p50_ms', 600)
            ->assertJsonPath('data.metrics.direct_transcript_to_audio_start_ms.p95_ms', 1_400)
            ->assertJsonPath('data.metrics.direct_response_duration_ms.sample_count', 20)
            ->assertJsonPath('data.metrics.direct_response_duration_ms.p50_ms', 1_000)
            ->assertJsonPath('data.metrics.direct_response_duration_ms.p95_ms', 2_000)
            ->assertJsonPath('data.metrics.tool_transcript_to_request_start_ms.sample_count', 20)
            ->assertJsonPath('data.metrics.tool_transcript_to_request_start_ms.p50_ms', 400)
            ->assertJsonPath('data.metrics.tool_transcript_to_request_start_ms.p95_ms', 900)
            ->assertJsonPath('data.metrics.direct_transcript_to_audio_start_ms.measurement_type', 'provider_playback_signal_proxy')
            ->assertJsonPath('data.metrics.tool_transcript_to_request_start_ms.measurement_type', 'client_request_dispatch')
            ->assertJsonPath('data.gates.direct_audio_start_latency.status', 'insufficient_data')
            ->assertJsonPath('data.gates.tool_acknowledgement_audio_start_latency.status', 'insufficient_data')
            ->assertJsonPath('data.gates.tool_final_audio_start_latency.status', 'insufficient_data')
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.denominator_available', true)
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.total_accepted_turns', 20)
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.completed_count', 20)
            ->assertJsonPath('data.outcome_coverage.tool_backend_runs.total_persisted_tool_turns', 20)
            ->assertJsonPath('data.outcome_coverage.tool_backend_runs.counts.unlinked', 20);
    }

    public function test_report_exposes_backend_tool_outcome_coverage_without_calling_it_voice_playback(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-10 12:00:00', 'UTC'));
        $adminToken = $this->apiToken('voice-quality-outcomes@example.com');
        $admin = User::where('email', 'voice-quality-outcomes@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true])->save();
        $session = ConversationSession::where('user_id', $admin->id)->firstOrFail();
        $quality = [
            'schema_version' => 1,
            'route' => 'tool',
            'transcript_to_request_start_ms' => 100,
        ];

        $definitions = [
            'completed' => ['status' => 'completed', 'completed_at' => now()],
            'cancelled' => ['status' => 'cancelled', 'cancelled_at' => now(), 'completed_at' => now()],
            'failed' => ['status' => 'failed', 'error' => 'Provider rejected the request.', 'completed_at' => now()],
            'hung' => ['status' => 'failed', 'error' => 'Assistant run timed out.', 'completed_at' => now()],
            'in-flight' => ['status' => 'running', 'started_at' => now()->subSeconds(10)],
            'unlinked' => null,
        ];

        foreach ($definitions as $name => $runAttributes) {
            $message = $this->qualityMessage($admin, $session, 'user', $quality, null, 'outcome-'.$name);
            if ($runAttributes === null) {
                continue;
            }

            AssistantRun::create([
                'user_id' => $admin->id,
                'workspace_id' => $session->workspace_id,
                'conversation_session_id' => $session->id,
                'user_message_id' => $message->id,
                'source' => 'web_routed_chat',
                'input' => 'Tool outcome '.$name,
                ...$runAttributes,
            ]);
        }

        $this->withToken($adminToken)->getJson('/api/admin/voice-quality')
            ->assertOk()
            ->assertJsonPath('data.outcome_coverage.tool_backend_runs.total_persisted_tool_turns', 6)
            ->assertJsonPath('data.outcome_coverage.tool_backend_runs.run_linked_turn_count', 5)
            ->assertJsonPath('data.outcome_coverage.tool_backend_runs.run_link_coverage_percent', 83.3)
            ->assertJsonPath('data.outcome_coverage.tool_backend_runs.counts.completed', 1)
            ->assertJsonPath('data.outcome_coverage.tool_backend_runs.counts.cancelled', 1)
            ->assertJsonPath('data.outcome_coverage.tool_backend_runs.counts.failed', 1)
            ->assertJsonPath('data.outcome_coverage.tool_backend_runs.counts.hung', 1)
            ->assertJsonPath('data.outcome_coverage.tool_backend_runs.counts.in_flight', 1)
            ->assertJsonPath('data.outcome_coverage.tool_backend_runs.counts.unlinked', 1)
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.denominator_available', true)
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.total_accepted_turns', 0)
            ->assertJsonPath(
                'data.outcome_coverage.tool_backend_runs.limitations.1',
                'Completed means backend run completion; final voice playback may still fail or be cancelled.',
            );
    }

    public function test_report_uses_the_corrected_run_instead_of_a_late_coalesced_original(): void
    {
        $adminToken = $this->apiToken('voice-quality-coalesced@example.com');
        $admin = User::where('email', 'voice-quality-coalesced@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true])->save();
        $session = ConversationSession::where('user_id', $admin->id)->firstOrFail();
        $message = $this->qualityMessage($admin, $session, 'user', [
            'schema_version' => 1,
            'route' => 'tool',
            'transcript_to_request_start_ms' => 120,
        ], null, 'voice-corrected');

        AssistantRun::create([
            'user_id' => $admin->id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'user_message_id' => $message->id,
            'source' => 'web_routed_chat',
            'status' => 'completed',
            'input' => 'Corrected request.',
            'metadata' => [
                'client_request_id' => 'voice-corrected',
                'supersession_predecessor_missing' => true,
                'superseded_client_request_ids' => ['voice-original'],
            ],
            'completed_at' => now(),
        ]);
        AssistantRun::create([
            'user_id' => $admin->id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'user_message_id' => $message->id,
            'source' => 'web_routed_chat',
            'status' => 'cancelled',
            'input' => 'Original request.',
            'metadata' => [
                'client_request_id' => 'voice-original',
                'late_superseded_request_coalesced' => true,
            ],
            'cancelled_at' => now(),
            'completed_at' => now(),
        ]);

        $this->withToken($adminToken)->getJson('/api/admin/voice-quality')
            ->assertOk()
            ->assertJsonPath('data.outcome_coverage.tool_backend_runs.total_persisted_tool_turns', 1)
            ->assertJsonPath('data.outcome_coverage.tool_backend_runs.run_linked_turn_count', 1)
            ->assertJsonPath('data.outcome_coverage.tool_backend_runs.counts.completed', 1)
            ->assertJsonPath('data.outcome_coverage.tool_backend_runs.counts.cancelled', 0);
    }

    public function test_report_supports_an_explicit_date_window_and_rejects_oversized_windows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-10 12:00:00', 'UTC'));
        $adminToken = $this->apiToken('voice-quality-window@example.com');
        $admin = User::where('email', 'voice-quality-window@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true])->save();
        $session = ConversationSession::where('user_id', $admin->id)->firstOrFail();
        $quality = [
            'schema_version' => 1,
            'route' => 'direct',
            'transcript_to_audio_start_ms' => 500,
            'response_duration_ms' => 900,
        ];

        $inside = $this->qualityMessage($admin, $session, 'assistant', $quality, 'inside-window');
        $inside->forceFill([
            'created_at' => CarbonImmutable::parse('2026-07-03 08:00:00', 'UTC'),
            'updated_at' => CarbonImmutable::parse('2026-07-03 08:00:00', 'UTC'),
        ])->save();
        $outside = $this->qualityMessage($admin, $session, 'assistant', $quality, 'outside-window');
        $outside->forceFill([
            'created_at' => CarbonImmutable::parse('2026-06-30 08:00:00', 'UTC'),
            'updated_at' => CarbonImmutable::parse('2026-06-30 08:00:00', 'UTC'),
        ])->save();

        $this->withToken($adminToken)->getJson('/api/admin/voice-quality?from=2026-07-03&to=2026-07-03')
            ->assertOk()
            ->assertJsonPath('data.population.source_message_count', 1)
            ->assertJsonPath('data.population.unique_turn_count', 1)
            ->assertJsonPath('data.metrics.direct_transcript_to_audio_start_ms.sample_count', 1)
            ->assertJsonPath('data.metrics.direct_transcript_to_audio_start_ms.p50_ms', 500);

        $this->withToken($adminToken)->getJson('/api/admin/voice-quality?days=91')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('days');
        $this->withToken($adminToken)->getJson('/api/admin/voice-quality?from=2026-01-01&to=2026-07-10')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from');
        $this->withToken($adminToken)->getJson('/api/admin/voice-quality?days=7&from=2026-07-03&to=2026-07-03')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('days');
    }

    public function test_report_exposes_direct_and_status_terminal_outcome_denominator(): void
    {
        $adminToken = $this->apiToken('voice-quality-lifecycle@example.com');
        $admin = User::where('email', 'voice-quality-lifecycle@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true])->save();
        $session = ConversationSession::where('user_id', $admin->id)->firstOrFail();
        $quality = [
            'schema_version' => 1,
            'route' => 'direct',
            'response_duration_ms' => 500,
        ];

        foreach (['accepted', 'completed', 'interrupted', 'failed', 'timed_out', 'superseded'] as $outcome) {
            $this->qualityMessage(
                $admin,
                $session,
                'user',
                $quality,
                'lifecycle-'.$outcome,
                null,
                $outcome,
            );
        }

        $this->withToken($adminToken)->getJson('/api/admin/voice-quality')
            ->assertOk()
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.denominator_available', true)
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.total_accepted_turns', 6)
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.terminal_outcome_count', 5)
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.completed_count', 1)
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.cancelled_count', 1)
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.interrupted_count', 1)
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.failed_count', 1)
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.timed_out_count', 1)
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.in_flight_count', 1);
    }

    /**
     * @param  array<string, int|string>  $quality
     */
    private function qualityMessage(
        User $user,
        ConversationSession $session,
        string $role,
        array $quality,
        ?string $clientTurnId = null,
        ?string $clientRequestId = null,
        ?string $outcome = null,
    ): ConversationMessage {
        $metadata = ['voice_quality' => $quality];
        if ($clientRequestId !== null) {
            $metadata['client_request_id'] = $clientRequestId;
        }
        if ($outcome !== null) {
            $metadata['voice_turn_outcome'] = ['status' => $outcome];
        }

        return ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'client_turn_id' => $clientTurnId,
            'role' => $role,
            'content' => $role.' voice quality sample',
            'metadata' => $metadata,
        ]);
    }
}
