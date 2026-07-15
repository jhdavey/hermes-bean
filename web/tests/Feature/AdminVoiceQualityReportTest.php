<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\User;
use App\Models\VoiceTurn;
use App\Models\VoiceTurnEvent;
use App\Services\BrowserVoiceV2DiagnosticsReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminVoiceQualityReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_voice_quality_report_is_admin_only_and_labels_insufficient_v2_data(): void
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
            ->assertJsonPath('data.window.default_days', BrowserVoiceV2DiagnosticsReportService::DEFAULT_WINDOW_DAYS)
            ->assertJsonPath('data.window.max_days', BrowserVoiceV2DiagnosticsReportService::MAX_WINDOW_DAYS)
            ->assertJsonPath('data.browser_voice_v2.population.turn_count', 0)
            ->assertJsonPath('data.browser_voice_v2.latency_metrics.wake_recognition_ms.sample_count', 0)
            ->assertJsonPath('data.browser_voice_v2.latency_metrics.wake_recognition_ms.p50_ms', null)
            ->assertJsonPath('data.browser_voice_v2.benchmark_gates.wake_recognition.status', 'insufficient_data')
            ->assertJsonPath('data.browser_voice_v2.benchmark_gates.wake_recognition.status_label', 'Insufficient data')
            ->assertJsonPath('data.browser_voice_v2.benchmark_gates.semantic_interpretation.status', 'insufficient_data')
            ->assertJsonPath('data.browser_voice_v2.latency_metrics.semantic_interpretation_ms.sample_count', 0)
            ->assertJsonPath('data.browser_voice_v2.latency_metrics.typed_external_final_audio_start_ms.sample_count', 0)
            ->assertJsonPath('data.browser_voice_v2.semantic_pipeline.receipts.expected_count', 0)
            ->assertJsonPath('data.browser_voice_v2.privacy.raw_transcript_exposed', false)
            ->assertJsonPath('data.browser_voice_v2.privacy.raw_audio_retention_allowed', false);
    }

    public function test_report_supports_an_explicit_date_window_and_rejects_oversized_windows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-10 12:00:00', 'UTC'));
        $adminToken = $this->apiToken('voice-quality-window@example.com');
        $admin = User::where('email', 'voice-quality-window@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true])->save();
        $session = ConversationSession::where('user_id', $admin->id)->firstOrFail();

        $inside = $this->voiceTurn($admin, $session, 'inside-window');
        $inside->forceFill([
            'created_at' => CarbonImmutable::parse('2026-07-03 08:00:00', 'UTC'),
            'updated_at' => CarbonImmutable::parse('2026-07-03 08:00:00', 'UTC'),
        ])->save();
        $outside = $this->voiceTurn($admin, $session, 'outside-window');
        $outside->forceFill([
            'created_at' => CarbonImmutable::parse('2026-06-30 08:00:00', 'UTC'),
            'updated_at' => CarbonImmutable::parse('2026-06-30 08:00:00', 'UTC'),
        ])->save();

        $this->withToken($adminToken)->getJson('/api/admin/voice-quality?from=2026-07-03&to=2026-07-03')
            ->assertOk()
            ->assertJsonPath('data.window.from', '2026-07-03T00:00:00+00:00')
            ->assertJsonPath('data.window.to', '2026-07-03T23:59:59+00:00')
            ->assertJsonPath('data.browser_voice_v2.population.turn_count', 1)
            ->assertJsonPath('data.browser_voice_v2.turns.0.turn_id', 'inside-window');

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

    public function test_client_failure_report_does_not_truncate_more_than_two_hundred_events(): void
    {
        $adminToken = $this->apiToken('voice-quality-client-failures@example.com');
        $admin = User::where('email', 'voice-quality-client-failures@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true])->save();
        $session = ConversationSession::where('user_id', $admin->id)->firstOrFail();

        for ($index = 1; $index <= 201; $index++) {
            ActivityEvent::create([
                'user_id' => $admin->id,
                'workspace_id' => $session->workspace_id,
                'conversation_session_id' => $session->id,
                'client_event_id' => 'browser_voice_v2:connection:'.hash('sha256', "client-failure-{$index}"),
                'event_type' => 'browser_voice_v2.client_failure',
                'tool_name' => 'browser.voice.client',
                'status' => 'failed',
                'payload' => [
                    'stage' => 'connection',
                    'code' => 'voice_connection_failure',
                    'message' => 'Browser voice connection failed.',
                    'cause_chain' => [],
                    'turn_id' => null,
                ],
            ]);
        }

        $this->withToken($adminToken)->getJson('/api/admin/voice-quality?days=1')
            ->assertOk()
            ->assertJsonPath('data.browser_voice_v2.client_failures.count', 201)
            ->assertJsonPath('data.browser_voice_v2.client_failures.stage_counts.connection', 201)
            ->assertJsonCount(201, 'data.browser_voice_v2.client_failures.events');
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
            'content' => 'I could not reach the weather service.',
            'metadata' => ['source' => 'browser_voice_v2'],
        ]);
        $failedTurn = $this->voiceTurn($admin, $session, 'v2-diagnostic-failed-0001', [
            'user_message_id' => $userMessage->id,
            'final_assistant_message_id' => $finalMessage->id,
            'transcript' => 'RAW transcript must not be projected',
            'sanitized_transcript' => 'Weather for Orlando Bearer abcdefghijklmnopqrstuvwxyz',
            'state' => 'failed',
            'version' => 5,
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
            'user_facing_failure_text' => 'I could not reach the weather service.',
            'side_effect_status' => 'uncertain',
            'metadata' => [
                'controller_generation' => 7,
                'provider_connection_generation' => 11,
                'transcript_timing' => ['final_at_ms' => $transcriptFinalAt->getTimestampMs()],
                'telemetry' => ['durable_admission_ms' => 50],
                'raw_audio' => 'RAW-AUDIO-BYTES-DO-NOT-EXPOSE',
            ],
        ]);
        $interpretationRun = $this->semanticRun($failedTurn, $admin, $session, 'interpretation', [
            'lane' => 'semantic',
            'handler' => 'agent.semantic',
            'label' => 'Interpret request',
            'status' => 'completed',
            'metadata' => [
                'role' => 'semantic_interpretation',
                'semantic_sequence' => 1,
                'semantic_outcome' => 'execute',
            ],
            'started_at' => now()->subMilliseconds(900),
            'completed_at' => now()->subMilliseconds(550),
        ]);
        $operationRun = $this->semanticRun($failedTurn, $admin, $session, 'weather', [
            'lane' => 'external',
            'handler' => 'semantic.operation',
            'label' => 'Check weather',
            'status' => 'failed',
            'input' => 'RAW run input must not be projected',
            'metadata' => [
                'role' => 'semantic_operation',
                'semantic_sequence' => 1,
                'semantic_operation_id' => 'weather',
                'semantic_tool' => 'external.lookup',
                'semantic_operation_receipt' => [
                    'operation_id' => 'weather',
                    'tool' => 'external.lookup',
                    'status' => 'failed',
                    'data' => ['category' => 'weather_provider_timeout'],
                    'side_effect_committed' => false,
                    'completed_at' => now()->toIso8601String(),
                ],
                'pcm_data' => 'RUN-RAW-AUDIO-DO-NOT-EXPOSE',
            ],
            'error' => 'Weather provider timed out Bearer abcdefghijklmnopqrstuvwxyz',
            'started_at' => now()->subMilliseconds(500),
            'completed_at' => now(),
        ]);
        $missingReceiptRun = $this->semanticRun($failedTurn, $admin, $session, 'forecast', [
            'lane' => 'external',
            'handler' => 'semantic.operation',
            'label' => 'Check forecast',
            'status' => 'failed',
            'metadata' => [
                'role' => 'semantic_operation',
                'semantic_sequence' => 1,
                'semantic_operation_id' => 'forecast',
                'semantic_tool' => 'external.lookup',
            ],
            'error' => 'Worker stopped before recording its receipt.',
            'started_at' => now()->subMilliseconds(450),
            'completed_at' => now(),
        ]);
        $this->semanticRun($failedTurn, $admin, $session, 'composition', [
            'lane' => 'semantic',
            'handler' => 'semantic.compose',
            'label' => 'Prepare response',
            'status' => 'failed',
            'metadata' => [
                'role' => 'semantic_composition',
                'semantic_sequence' => 1,
                'operation_run_ids' => [$operationRun->id, $missingReceiptRun->id],
            ],
            'error' => 'Could not compose the weather failure.',
            'started_at' => now()->subMilliseconds(400),
            'completed_at' => now(),
        ]);

        $events = [
            ['turn_admitted', null, 'accepted', 1, 'admission', ['audio_blob' => 'EVENT-RAW-AUDIO-DO-NOT-EXPOSE']],
            ['semantic_interpretation_started', 'running', 'running', 2, 'semantic_interpreter', [
                'run_id' => $interpretationRun->id,
                'occurred_at_ms' => $transcriptFinalAt->copy()->addMilliseconds(100)->getTimestampMs(),
            ]],
            ['semantic_interpretation_completed', 'running', 'running', 2, 'semantic_interpreter', [
                'run_id' => $interpretationRun->id,
                'outcome' => 'execute',
                'occurred_at_ms' => $transcriptFinalAt->copy()->addMilliseconds(450)->getTimestampMs(),
            ]],
            ['acknowledgement_started', 'running', 'running', 3, 'browser', ['latency_ms' => 420, 'speech_item_id' => 'speech-ack-1']],
            ['stale_event_rejected', 'running', 'running', 3, 'browser', ['reason' => 'stale_generation']],
            ['finalization_rejected', 'failed', 'completed', 5, 'finalizer', ['reason' => 'terminal_state_already_recorded']],
            ['playback_started', 'running', 'running', 3, 'browser', ['purpose' => 'final', 'latency_ms' => 800, 'speech_item_id' => 'speech-final-1']],
            ['playback_stopped_for_interruption', 'running', 'running', 3, 'browser', ['purpose' => 'final', 'latency_ms' => 160, 'speech_item_id' => 'speech-final-1']],
            ['turn_failed', 'running', 'failed', 5, 'finalizer', ['failure_category' => 'weather_provider_timeout']],
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
            'event_type' => 'runtime.semantic_interpretation_started',
            'tool_name' => 'hermes.semantic',
            'status' => 'started',
            'payload' => [
                'message_id' => $userMessage->id,
                'provider' => 'openai',
                'microphone_audio' => 'ACTIVITY-RAW-AUDIO-DO-NOT-EXPOSE',
            ],
        ]);
        ActivityEvent::create([
            'user_id' => $admin->id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'client_event_id' => 'browser_voice_v2:clarification:diagnostic-test',
            'event_type' => 'browser_voice_v2.client_failure',
            'tool_name' => 'browser.voice.client',
            'status' => 'failed',
            'payload' => [
                'stage' => 'clarification',
                'code' => 'AbortError',
                'message' => 'Bearer abcdefghijklmnopqrstuvwxyz did not recover.',
                'turn_id' => $failedTurn->turn_id,
            ],
        ]);
        $overdueTurn = $this->voiceTurn($admin, $session, 'v2-diagnostic-overdue-0002', [
            'state' => 'running',
            'version' => 3,
            'accepted_at' => now()->subSeconds(10),
            'hard_deadline_at' => now()->subSecond(),
            'no_progress_deadline_at' => now()->subSeconds(2),
            'side_effect_status' => 'committed',
            'metadata' => [
                'transcript_timing' => ['final_at_ms' => now()->subMilliseconds(10_100)->getTimestampMs()],
                'telemetry' => ['durable_admission_ms' => 100],
            ],
        ]);
        $overdueInterpretation = $this->semanticRun($overdueTurn, $admin, $session, 'interpretation', [
            'lane' => 'semantic',
            'handler' => 'agent.semantic',
            'status' => 'completed',
            'metadata' => [
                'role' => 'semantic_interpretation',
                'semantic_sequence' => 1,
                'semantic_outcome' => 'execute',
            ],
            'started_at' => now()->subSeconds(9),
            'completed_at' => now()->subSeconds(8),
        ]);
        $writeRun = $this->semanticRun($overdueTurn, $admin, $session, 'note', [
            'lane' => 'app_write',
            'handler' => 'semantic.operation',
            'label' => 'Create note',
            'status' => 'completed',
            'metadata' => [
                'role' => 'semantic_operation',
                'semantic_sequence' => 1,
                'semantic_operation_id' => 'note',
                'semantic_tool' => 'app.note.create',
                'semantic_operation_receipt' => [
                    'operation_id' => 'note',
                    'tool' => 'app.note.create',
                    'status' => 'completed',
                    'data' => ['note_id' => 42],
                    'side_effect_committed' => true,
                    'completed_at' => now()->subSeconds(7)->toIso8601String(),
                ],
            ],
            'started_at' => now()->subSeconds(8),
            'completed_at' => now()->subSeconds(7),
        ]);
        $this->semanticRun($overdueTurn, $admin, $session, 'composition', [
            'lane' => 'semantic',
            'handler' => 'semantic.compose',
            'label' => 'Prepare response',
            'status' => 'queued',
            'metadata' => [
                'role' => 'semantic_composition',
                'semantic_sequence' => 1,
                'interpretation_run_id' => $overdueInterpretation->id,
                'operation_run_ids' => [$writeRun->id],
            ],
        ]);

        $response = $this->withToken($adminToken)->getJson('/api/admin/voice-quality?days=1');
        $response->assertOk()
            ->assertJsonPath('data.browser_voice_v2.population.turn_count', 2)
            ->assertJsonPath('data.browser_voice_v2.population.run_count', 7)
            ->assertJsonPath('data.browser_voice_v2.semantic_pipeline.run_role_counts.semantic_interpretation', 2)
            ->assertJsonPath('data.browser_voice_v2.semantic_pipeline.run_role_counts.semantic_operation', 3)
            ->assertJsonPath('data.browser_voice_v2.semantic_pipeline.run_role_counts.semantic_composition', 2)
            ->assertJsonPath('data.browser_voice_v2.semantic_pipeline.typed_operation_lane_counts.external', 2)
            ->assertJsonPath('data.browser_voice_v2.semantic_pipeline.typed_operation_lane_counts.app_write', 1)
            ->assertJsonPath('data.browser_voice_v2.semantic_pipeline.receipts.expected_count', 3)
            ->assertJsonPath('data.browser_voice_v2.semantic_pipeline.receipts.recorded_count', 2)
            ->assertJsonPath('data.browser_voice_v2.semantic_pipeline.receipts.missing_count', 1)
            ->assertJsonPath('data.browser_voice_v2.semantic_pipeline.receipts.committed_count', 1)
            ->assertJsonPath('data.browser_voice_v2.semantic_pipeline.receipts.identity_mismatch_count', 0)
            ->assertJsonPath('data.browser_voice_v2.turns.0.sanitized_transcript', 'Weather for Orlando Bearer [redacted]')
            ->assertJsonPath('data.browser_voice_v2.turns.0.execution_profile.0', 'semantic_interpretation')
            ->assertJsonPath('data.browser_voice_v2.turns.0.execution_profile.1', 'typed_external')
            ->assertJsonPath('data.browser_voice_v2.turns.0.latency_ms.transcript_to_durable_admission', 50)
            ->assertJsonPath('data.browser_voice_v2.turns.0.latency_ms.transcript_to_acknowledgement_audio_start', 420)
            ->assertJsonPath('data.browser_voice_v2.turns.0.latency_ms.transcript_to_final_audio_start', 800)
            ->assertJsonPath('data.browser_voice_v2.turns.0.latency_ms.confirmed_barge_in_to_playback_stop', 160)
            ->assertJsonPath('data.browser_voice_v2.turns.0.failure.category', 'weather_provider_timeout')
            ->assertJsonPath('data.browser_voice_v2.turns.0.browser.controller_generation', 7)
            ->assertJsonPath('data.browser_voice_v2.turns.0.browser.provider_connection_generation', 11)
            ->assertJsonPath('data.browser_voice_v2.turns.0.browser.rejected_stale_event_count', 1)
            ->assertJsonPath('data.browser_voice_v2.turns.0.delivery.final_text.state', 'delivered')
            ->assertJsonPath('data.browser_voice_v2.turns.0.delivery.final_audio.state', 'stopped')
            ->assertJsonPath('data.browser_voice_v2.turns.0.jobs.0.role', 'semantic_interpretation')
            ->assertJsonPath('data.browser_voice_v2.turns.0.jobs.0.execution_call.kind', 'semantic_interpreter')
            ->assertJsonPath('data.browser_voice_v2.turns.0.jobs.0.latency_ms.transcript_to_semantic_terminal', 450)
            ->assertJsonPath('data.browser_voice_v2.turns.0.jobs.1.role', 'semantic_operation')
            ->assertJsonPath('data.browser_voice_v2.turns.0.jobs.1.execution_call.kind', 'typed_external_provider')
            ->assertJsonPath('data.browser_voice_v2.turns.0.jobs.1.semantic_operation.tool', 'external.lookup')
            ->assertJsonPath('data.browser_voice_v2.turns.0.jobs.1.receipt.status', 'failed')
            ->assertJsonPath('data.browser_voice_v2.turns.0.jobs.1.receipt_integrity.operation_and_tool_match', true)
            ->assertJsonPath('data.browser_voice_v2.turns.0.jobs.2.receipt_integrity.recorded', false)
            ->assertJsonPath('data.browser_voice_v2.turns.0.jobs.3.execution_call.kind', 'semantic_response_composer')
            ->assertJsonPath('data.browser_voice_v2.turns.0.semantic_pipeline.receipts.status_counts.failed', 1)
            ->assertJsonPath('data.browser_voice_v2.turns.0.semantic_pipeline.receipts.missing_run_ids.0', $missingReceiptRun->id)
            ->assertJsonPath('data.browser_voice_v2.turns.0.retry_eligibility.typed_operations.0.reason', 'turn_terminal')
            ->assertJsonPath('data.browser_voice_v2.turns.0.retry_eligibility.typed_operations.1.reason', 'missing_receipt_requires_reconciliation')
            ->assertJsonPath('data.browser_voice_v2.turns.1.retry_eligibility.typed_operations.0.reason', 'committed_receipt_prevents_automatic_retry')
            ->assertJsonPath('data.browser_voice_v2.turns.0.provider_and_tool_calls.4.name', 'hermes.semantic')
            ->assertJsonCount(11, 'data.browser_voice_v2.turns.0.lifecycle_timeline')
            ->assertJsonPath('data.browser_voice_v2.latency_metrics.semantic_interpretation_ms.sample_count', 1)
            ->assertJsonPath('data.browser_voice_v2.latency_metrics.semantic_interpretation_ms.p50_ms', 450)
            ->assertJsonPath('data.browser_voice_v2.latency_metrics.semantic_interpretation_ms.population_count', 2)
            ->assertJsonPath('data.browser_voice_v2.telemetry_coverage.fields.semantic_interpretation_ms.coverage_percent', 50)
            ->assertJsonPath('data.browser_voice_v2.alerts.accepted_nonterminal_beyond_deadline.count', 1)
            ->assertJsonPath('data.browser_voice_v2.alerts.no_progress_deadline_exceeded.count', 1)
            ->assertJsonPath('data.browser_voice_v2.alerts.duplicate_finalization_attempt.count', 1)
            ->assertJsonPath('data.browser_voice_v2.alerts.lifecycle_regression_attempt.count', 1)
            ->assertJsonPath('data.browser_voice_v2.alerts.uncertain_side_effect_state.count', 1)
            ->assertJsonPath('data.browser_voice_v2.alerts.missing_semantic_interpretation_run.count', 0)
            ->assertJsonPath('data.browser_voice_v2.alerts.terminal_typed_operation_missing_receipt.count', 1)
            ->assertJsonPath('data.browser_voice_v2.alerts.semantic_receipt_identity_mismatch.count', 0)
            ->assertJsonPath('data.browser_voice_v2.alerts.raw_audio_persistence_detected.count', 1)
            ->assertJsonPath('data.browser_voice_v2.client_failures.count', 1)
            ->assertJsonPath('data.browser_voice_v2.client_failures.events.0.failure_id', 'browser_voice_v2:clarification:diagnostic-test')
            ->assertJsonPath('data.browser_voice_v2.client_failures.events.0.message', 'Bearer [redacted] did not recover.')
            ->assertJsonPath('data.browser_voice_v2.privacy.raw_transcript_exposed', false)
            ->assertJsonPath('data.browser_voice_v2.privacy.raw_audio_retention_allowed', false);

        $this->assertArrayNotHasKey('lane_counts', $response->json('data.browser_voice_v2.population'));
        $this->assertArrayNotHasKey('lane', $response->json('data.browser_voice_v2.turns.0'));
        $this->assertArrayNotHasKey('handler', $response->json('data.browser_voice_v2.turns.0'));

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

        for ($index = 1; $index <= BrowserVoiceV2DiagnosticsReportService::MINIMUM_BENCHMARK_SAMPLES; $index++) {
            $turn = $this->voiceTurn($admin, $session, 'v2-benchmark-external-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT), [
                'state' => 'completed',
                'terminal_at' => now(),
                'hard_deadline_at' => now()->addSeconds(8),
            ]);
            $this->semanticRun($turn, $admin, $session, 'interpretation', [
                'lane' => 'semantic',
                'handler' => 'agent.semantic',
                'status' => 'completed',
                'metadata' => [
                    'role' => 'semantic_interpretation',
                    'semantic_sequence' => 1,
                    'semantic_outcome' => 'execute',
                ],
                'started_at' => now()->subMilliseconds(600),
                'completed_at' => now()->subMilliseconds(300),
            ]);
            $operation = $this->semanticRun($turn, $admin, $session, 'weather', [
                'lane' => 'external',
                'handler' => 'semantic.operation',
                'status' => 'completed',
                'metadata' => [
                    'role' => 'semantic_operation',
                    'semantic_sequence' => 1,
                    'semantic_operation_id' => 'weather',
                    'semantic_tool' => 'external.lookup',
                    'semantic_operation_receipt' => [
                        'operation_id' => 'weather',
                        'tool' => 'external.lookup',
                        'status' => 'completed',
                        'data' => ['summary' => 'Clear'],
                        'side_effect_committed' => false,
                        'completed_at' => now()->toIso8601String(),
                    ],
                ],
                'started_at' => now()->subMilliseconds(300),
                'completed_at' => now()->subMilliseconds(100),
            ]);
            $this->semanticRun($turn, $admin, $session, 'composition', [
                'lane' => 'semantic',
                'handler' => 'semantic.compose',
                'status' => 'completed',
                'metadata' => [
                    'role' => 'semantic_composition',
                    'semantic_sequence' => 1,
                    'operation_run_ids' => [$operation->id],
                ],
                'started_at' => now()->subMilliseconds(100),
                'completed_at' => now(),
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
            ->assertJsonPath('data.browser_voice_v2.latency_metrics.typed_external_final_audio_start_ms.sample_count', 20)
            ->assertJsonPath('data.browser_voice_v2.latency_metrics.typed_external_final_audio_start_ms.p50_ms', 2_000)
            ->assertJsonPath('data.browser_voice_v2.latency_metrics.typed_external_final_audio_start_ms.p95_ms', 4_500)
            ->assertJsonPath('data.browser_voice_v2.benchmark_gates.typed_external_final_audio_start.status', 'failing')
            ->assertJsonPath('data.browser_voice_v2.benchmark_gates.typed_external_final_audio_start.status_label', 'Failing')
            ->assertJsonPath('data.browser_voice_v2.benchmark_gates.typed_external_final_audio_start.sufficient_sample', true)
            ->assertJsonPath('data.browser_voice_v2.alerts.benchmark_p95_regression.count', 1)
            ->assertJsonPath('data.browser_voice_v2.alerts.benchmark_p95_regression.gate_ids.0', 'typed_external_final_audio_start');
    }

    /** @param array<string, mixed> $overrides */
    private function voiceTurn(
        User $user,
        ConversationSession $session,
        string $turnId,
        array $overrides = [],
    ): VoiceTurn {
        return VoiceTurn::create([
            'turn_id' => $turnId,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'source' => 'browser_voice_v2',
            'client_kind' => 'browser_voice',
            'transcript' => 'Voice request.',
            'sanitized_transcript' => 'Voice request.',
            'state' => 'accepted',
            'version' => 1,
            'idempotency_key' => $turnId,
            'acknowledgement_required' => false,
            'accepted_at' => now(),
            'hard_deadline_at' => now()->addSeconds(8),
            'side_effect_status' => 'none',
            'retry_count' => 0,
            'metadata' => ['raw_audio_retained' => false],
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function semanticRun(
        VoiceTurn $turn,
        User $user,
        ConversationSession $session,
        string $key,
        array $overrides = [],
    ): AssistantRun {
        return AssistantRun::create([
            'voice_turn_id' => $turn->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'user_message_id' => $turn->user_message_id,
            'source' => 'browser_voice_v2',
            'lane' => 'semantic',
            'handler' => 'agent.semantic',
            'label' => ucfirst($key),
            'priority' => 0,
            'idempotency_key' => $turn->turn_id.':'.$key,
            'hard_deadline_at' => $turn->hard_deadline_at,
            'status' => 'queued',
            'input' => '{}',
            'metadata' => [
                'role' => 'semantic_interpretation',
                'semantic_sequence' => 1,
            ],
            ...$overrides,
        ]);
    }
}
