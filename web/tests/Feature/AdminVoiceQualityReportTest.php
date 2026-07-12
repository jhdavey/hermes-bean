<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\User;
use App\Models\VoiceTurn;
use App\Models\VoiceTurnEvent;
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

    public function test_browser_voice_v2_admin_diagnostic_is_complete_sanitized_and_flags_actionable_failures(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-11 18:00:00', 'UTC'));
        $adminToken = $this->apiToken('voice-quality-v2-diagnostics@example.com');
        $admin = User::where('email', 'voice-quality-v2-diagnostics@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true])->save();
        $session = ConversationSession::where('user_id', $admin->id)->firstOrFail();
        $transcriptFinalAt = now()->subSecond();
        $userMessage = ConversationMessage::create([
            'user_id' => $admin->id,
            'conversation_session_id' => $session->id,
            'client_turn_id' => 'v2-diagnostic-failed-0001',
            'role' => 'user',
            'content' => 'What is the weather?',
            'metadata' => ['source' => 'browser_voice_v2'],
        ]);
        $finalMessage = ConversationMessage::create([
            'user_id' => $admin->id,
            'conversation_session_id' => $session->id,
            'client_turn_id' => 'v2-diagnostic-failed-0001',
            'role' => 'assistant',
            'content' => 'I could not reach the weather service. Would you like me to try again?',
            'metadata' => ['source' => 'browser_voice_v2'],
        ]);
        $failedTurn = VoiceTurn::create([
            'turn_id' => 'v2-diagnostic-failed-0001',
            'user_id' => $admin->id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'user_message_id' => $userMessage->id,
            'final_assistant_message_id' => $finalMessage->id,
            'source' => 'browser_voice_v2',
            'client_kind' => 'browser_voice',
            'transcript' => 'RAW transcript must not be projected',
            'sanitized_transcript' => 'Weather for Orlando Bearer abcdefghijklmnopqrstuvwxyz',
            'lane' => 'external',
            'handler' => 'external.weather',
            'state' => 'failed',
            'version' => 5,
            'idempotency_key' => 'v2-diagnostic-failed-0001',
            'acknowledgement_required' => true,
            'acknowledgement_text' => 'Let me check Orlando.',
            'acknowledged_at' => now()->subMilliseconds(580),
            'accepted_at' => now()->subMilliseconds(950),
            'started_at' => now()->subMilliseconds(850),
            'first_progress_at' => now()->subMilliseconds(700),
            'terminal_at' => now(),
            'final_delivered_at' => now()->addMilliseconds(50),
            'hard_deadline_at' => now()->subMilliseconds(50),
            'no_progress_deadline_at' => now()->subMilliseconds(750),
            'failure_category' => 'weather_provider_timeout',
            'internal_failure_detail' => 'Provider timeout Bearer abcdefghijklmnopqrstuvwxyz',
            'user_facing_failure_text' => 'I could not reach the weather service. Would you like me to try again?',
            'side_effect_status' => 'uncertain',
            'retry_count' => 0,
            'metadata' => [
                'controller_generation' => 7,
                'provider_connection_generation' => 11,
                'transcript_timing' => [
                    'final_at_ms' => $transcriptFinalAt->getTimestampMs(),
                    'duration_ms' => 800,
                ],
                'telemetry' => ['durable_admission_ms' => 50],
                'raw_audio_retained' => false,
                'raw_audio' => 'RAW-AUDIO-BYTES-DO-NOT-EXPOSE',
            ],
        ]);
        $run = AssistantRun::create([
            'voice_turn_id' => $failedTurn->id,
            'user_id' => $admin->id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'user_message_id' => $userMessage->id,
            'source' => 'browser_voice_v2',
            'lane' => 'external',
            'handler' => 'external.weather',
            'label' => 'Check weather',
            'priority' => 0,
            'idempotency_key' => 'v2-diagnostic-failed-0001:primary',
            'hard_deadline_at' => now()->subMilliseconds(50),
            'last_progress_at' => now()->subMilliseconds(700),
            'dispatch_requested_at' => now()->subMilliseconds(920),
            'status' => 'failed',
            'input' => 'RAW run input must not be projected',
            'metadata' => [
                'resource_wait_started_at' => now()->subSeconds(2)->toIso8601String(),
                'pcm_data' => 'RUN-RAW-AUDIO-DO-NOT-EXPOSE',
            ],
            'error' => 'Weather provider timed out Bearer abcdefghijklmnopqrstuvwxyz',
            'started_at' => now()->subMilliseconds(850),
            'completed_at' => now(),
        ]);

        $events = [
            ['turn_admitted', null, 'accepted', 1, 'admission', ['handler' => 'external.weather', 'audio_blob' => 'EVENT-RAW-AUDIO-DO-NOT-EXPOSE']],
            ['state_transitioned', 'accepted', 'running', 2, 'worker', ['run_id' => $run->id]],
            ['acknowledgement_started', 'running', 'running', 3, 'browser', ['latency_ms' => 420, 'speech_item_id' => 'speech-ack-1']],
            ['stale_event_rejected', 'running', 'running', 3, 'browser', ['reason' => 'stale_generation']],
            ['transition_rejected', 'running', 'accepted', 3, 'server', ['reason' => 'non_monotonic_transition']],
            ['playback_started', 'running', 'running', 3, 'browser', ['purpose' => 'final', 'latency_ms' => 800, 'speech_item_id' => 'speech-final-1']],
            ['playback_stopped_for_interruption', 'running', 'running', 3, 'browser', ['purpose' => 'final', 'latency_ms' => 160, 'speech_item_id' => 'speech-final-1', 'reason' => 'meaningful_user_speech']],
            ['turn_failed', 'running', 'failed', 5, 'finalizer', ['failure_category' => 'weather_provider_timeout', 'side_effect_status' => 'uncertain']],
            ['finalization_deduplicated', 'failed', 'failed', 5, 'finalizer', ['final_assistant_message_id' => $finalMessage->id]],
            ['final_text_delivered', 'failed', 'failed', 5, 'browser', ['latency_ms' => 1_050]],
        ];
        foreach ($events as $index => [$type, $from, $to, $version, $source, $payload]) {
            VoiceTurnEvent::create([
                'voice_turn_id' => $failedTurn->id,
                'user_id' => $admin->id,
                'workspace_id' => $session->workspace_id,
                'conversation_session_id' => $session->id,
                'sequence' => $index + 1,
                'event_type' => $type,
                'from_state' => $from,
                'to_state' => $to,
                'version' => $version,
                'source' => $source,
                'payload' => $payload,
            ]);
        }
        ActivityEvent::create([
            'user_id' => $admin->id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'event_type' => 'runtime.tool_model_started',
            'tool_name' => 'hermes.tools',
            'status' => 'started',
            'payload' => [
                'message_id' => $userMessage->id,
                'provider' => 'openai',
                'microphone_audio' => 'ACTIVITY-RAW-AUDIO-DO-NOT-EXPOSE',
            ],
        ]);

        $overdueTurn = VoiceTurn::create([
            'turn_id' => 'v2-diagnostic-overdue-0002',
            'user_id' => $admin->id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'source' => 'browser_voice_v2',
            'client_kind' => 'browser_voice',
            'transcript' => 'Create a note.',
            'sanitized_transcript' => 'Create a note.',
            'lane' => 'app_write',
            'handler' => 'app.note.create',
            'state' => 'accepted',
            'version' => 1,
            'idempotency_key' => 'v2-diagnostic-overdue-0002',
            'acknowledgement_required' => false,
            'accepted_at' => now()->subSeconds(10),
            'hard_deadline_at' => now()->subSecond(),
            'no_progress_deadline_at' => now()->subSeconds(2),
            'side_effect_status' => 'none',
            'retry_count' => 0,
            'metadata' => ['raw_audio_retained' => false],
        ]);
        VoiceTurnEvent::create([
            'voice_turn_id' => $overdueTurn->id,
            'user_id' => $admin->id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'sequence' => 1,
            'event_type' => 'turn_admitted',
            'from_state' => null,
            'to_state' => 'accepted',
            'version' => 1,
            'source' => 'admission',
            'payload' => ['handler' => 'app.note.create'],
        ]);

        $response = $this->withToken($adminToken)->getJson('/api/admin/voice-quality?days=1')
            ->assertOk()
            ->assertJsonPath('data.browser_voice_v2.population.turn_count', 2)
            ->assertJsonPath('data.browser_voice_v2.population.run_count', 1)
            ->assertJsonPath('data.browser_voice_v2.population.state_counts.failed', 1)
            ->assertJsonPath('data.browser_voice_v2.population.state_counts.accepted', 1)
            ->assertJsonPath('data.browser_voice_v2.turns.0.user_id', $admin->id)
            ->assertJsonPath('data.browser_voice_v2.turns.0.workspace_id', $session->workspace_id)
            ->assertJsonPath('data.browser_voice_v2.turns.0.sanitized_transcript', 'Weather for Orlando Bearer [redacted]')
            ->assertJsonPath('data.browser_voice_v2.turns.0.lane', 'external')
            ->assertJsonPath('data.browser_voice_v2.turns.0.handler', 'external.weather')
            ->assertJsonPath('data.browser_voice_v2.turns.0.latency_ms.transcript_to_durable_admission', 50)
            ->assertJsonPath('data.browser_voice_v2.turns.0.latency_ms.transcript_to_acknowledgement_audio_start', 420)
            ->assertJsonPath('data.browser_voice_v2.turns.0.latency_ms.transcript_to_final_audio_start', 800)
            ->assertJsonPath('data.browser_voice_v2.turns.0.latency_ms.confirmed_barge_in_to_playback_stop', 160)
            ->assertJsonPath('data.browser_voice_v2.turns.0.deadlines.hard_status', 'failing')
            ->assertJsonPath('data.browser_voice_v2.turns.0.deadlines.no_progress_status', 'failing')
            ->assertJsonPath('data.browser_voice_v2.turns.0.failure.category', 'weather_provider_timeout')
            ->assertJsonPath('data.browser_voice_v2.turns.0.failure.side_effect_status', 'uncertain')
            ->assertJsonPath('data.browser_voice_v2.turns.0.browser.controller_generation', 7)
            ->assertJsonPath('data.browser_voice_v2.turns.0.browser.provider_connection_generation', 11)
            ->assertJsonPath('data.browser_voice_v2.turns.0.browser.rejected_stale_event_count', 1)
            ->assertJsonPath('data.browser_voice_v2.turns.0.delivery.final_text.state', 'delivered')
            ->assertJsonPath('data.browser_voice_v2.turns.0.delivery.final_audio.state', 'stopped')
            ->assertJsonPath('data.browser_voice_v2.turns.0.jobs.0.execution_call.kind', 'typed_external_provider')
            ->assertJsonPath('data.browser_voice_v2.turns.0.jobs.0.resource_lock_wait_ms', 1_000)
            ->assertJsonPath('data.browser_voice_v2.turns.0.provider_and_tool_calls.1.name', 'hermes.tools')
            ->assertJsonCount(10, 'data.browser_voice_v2.turns.0.lifecycle_timeline')
            ->assertJsonPath('data.browser_voice_v2.alerts.accepted_nonterminal_beyond_deadline.count', 1)
            ->assertJsonPath('data.browser_voice_v2.alerts.no_progress_deadline_exceeded.count', 1)
            ->assertJsonPath('data.browser_voice_v2.alerts.duplicate_finalization_attempt.count', 1)
            ->assertJsonPath('data.browser_voice_v2.alerts.lifecycle_regression_attempt.count', 1)
            ->assertJsonPath('data.browser_voice_v2.alerts.uncertain_side_effect_state.count', 1)
            ->assertJsonPath('data.browser_voice_v2.alerts.raw_audio_persistence_detected.count', 1)
            ->assertJsonPath('data.browser_voice_v2.benchmark_gates.external_final_audio_start.status', 'insufficient_data')
            ->assertJsonPath('data.browser_voice_v2.benchmark_gates.external_final_audio_start.sufficient_sample', false)
            ->assertJsonPath('data.browser_voice_v2.privacy.raw_transcript_exposed', false)
            ->assertJsonPath('data.browser_voice_v2.privacy.raw_audio_retention_allowed', false)
            ->assertJsonPath('data.browser_voice_v2.privacy.raw_audio_persistence_detection_count', 1);

        $content = $response->getContent();
        $this->assertStringNotContainsString('RAW transcript must not be projected', $content);
        $this->assertStringNotContainsString('RAW-AUDIO-BYTES-DO-NOT-EXPOSE', $content);
        $this->assertStringNotContainsString('RUN-RAW-AUDIO-DO-NOT-EXPOSE', $content);
        $this->assertStringNotContainsString('EVENT-RAW-AUDIO-DO-NOT-EXPOSE', $content);
        $this->assertStringNotContainsString('ACTIVITY-RAW-AUDIO-DO-NOT-EXPOSE', $content);
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz', $content);
    }

    public function test_browser_voice_v2_benchmark_gate_requires_enough_samples_and_alerts_on_p95_regression(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-11 19:00:00', 'UTC'));
        $adminToken = $this->apiToken('voice-quality-v2-benchmark@example.com');
        $admin = User::where('email', 'voice-quality-v2-benchmark@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true])->save();
        $session = ConversationSession::where('user_id', $admin->id)->firstOrFail();

        for ($index = 1; $index <= 20; $index++) {
            $turn = VoiceTurn::create([
                'turn_id' => 'v2-benchmark-external-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'user_id' => $admin->id,
                'workspace_id' => $session->workspace_id,
                'conversation_session_id' => $session->id,
                'source' => 'browser_voice_v2',
                'client_kind' => 'browser_voice',
                'transcript' => 'Weather elsewhere.',
                'sanitized_transcript' => 'Weather elsewhere.',
                'lane' => 'external',
                'handler' => 'external.weather',
                'state' => 'completed',
                'version' => 1,
                'idempotency_key' => 'v2-benchmark-external-'.$index,
                'acknowledgement_required' => false,
                'accepted_at' => now(),
                'terminal_at' => now(),
                'hard_deadline_at' => now()->addSeconds(8),
                'side_effect_status' => 'none',
                'retry_count' => 0,
                'metadata' => ['raw_audio_retained' => false],
            ]);
            VoiceTurnEvent::create([
                'voice_turn_id' => $turn->id,
                'user_id' => $admin->id,
                'workspace_id' => $session->workspace_id,
                'conversation_session_id' => $session->id,
                'sequence' => 1,
                'event_type' => 'playback_started',
                'from_state' => 'completed',
                'to_state' => 'completed',
                'version' => 1,
                'source' => 'browser',
                'payload' => [
                    'purpose' => 'final',
                    'latency_ms' => $index <= 18 ? 2_000 : 4_500,
                    'speech_item_id' => 'final-'.$index,
                ],
            ]);
        }

        $this->withToken($adminToken)->getJson('/api/admin/voice-quality?days=1')
            ->assertOk()
            ->assertJsonPath('data.browser_voice_v2.latency_metrics.external_final_audio_start_ms.sample_count', 20)
            ->assertJsonPath('data.browser_voice_v2.latency_metrics.external_final_audio_start_ms.p50_ms', 2_000)
            ->assertJsonPath('data.browser_voice_v2.latency_metrics.external_final_audio_start_ms.p95_ms', 4_500)
            ->assertJsonPath('data.browser_voice_v2.benchmark_gates.external_final_audio_start.status', 'failing')
            ->assertJsonPath('data.browser_voice_v2.benchmark_gates.external_final_audio_start.status_label', 'Failing')
            ->assertJsonPath('data.browser_voice_v2.benchmark_gates.external_final_audio_start.sufficient_sample', true)
            ->assertJsonPath('data.browser_voice_v2.alerts.benchmark_p95_regression.count', 1)
            ->assertJsonPath('data.browser_voice_v2.alerts.benchmark_p95_regression.gate_ids.0', 'external_final_audio_start');
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
