<?php

namespace Tests\Feature;

use App\Models\BeanQualityTrace;
use App\Models\BeanRun;
use App\Models\BeanSession;
use App\Models\BeanToolCall;
use App\Models\BeanVoiceEvent;
use App\Models\User;
use App\Services\Bean\BeanRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BeanUxBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_voice_lifecycle_events_are_recorded_for_authenticated_user(): void
    {
        $token = $this->apiToken('bean-voice-events@example.com');
        $user = User::where('email', 'bean-voice-events@example.com')->firstOrFail();
        $session = app(BeanRuntimeService::class)->createSession($user);
        $run = BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'status' => 'completed',
            'mode' => 'voice',
            'input' => 'can you hear me',
            'output' => 'Yes.',
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);

        $this->withToken($token)->postJson('/api/bean/voice-events', [
            'event_type' => 'wake_detected',
            'session_id' => $session->id,
            'run_id' => $run->id,
            'mode' => 'listening',
            'source' => 'test',
            'label' => 'Hey Bean',
            'payload' => ['sample' => true],
            'occurred_at' => now()->toIso8601String(),
            'occurred_at_ms' => (now()->getTimestamp() * 1000) + intdiv((int) now()->micro, 1000),
        ])->assertCreated()
            ->assertJsonStructure(['data' => ['id']]);

        $this->assertDatabaseHas('bean_voice_events', [
            'user_id' => $user->id,
            'bean_session_id' => $session->id,
            'bean_run_id' => $run->id,
            'event_type' => 'wake_detected',
            'source' => 'test',
        ]);
        $this->assertSame(1, BeanVoiceEvent::count());
    }

    public function test_ux_benchmark_command_reports_targets_and_updates_progress_file(): void
    {
        $token = $this->apiToken('bean-ux-benchmark@example.com');
        $user = User::where('email', 'bean-ux-benchmark@example.com')->firstOrFail();
        $session = app(BeanRuntimeService::class)->createSession($user);
        $completed = $this->runWithTool($user, $session, 'do I have overdue tasks', 'You have one overdue task.', 'completed', 4200);
        $failed = $this->runWithTool($user, $session, 'list tasks', 'I could not complete that request.', 'failed', 13000);
        BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'status' => 'completed',
            'mode' => 'text',
            'input' => 'Martinez had 11 saves today',
            'output' => 'Martinez had a strong match.',
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);
        $voiceStart = now()->startOfSecond()->subSeconds(20);
        $voiceSessionId = 'voice-session-test';
        $firstTurnId = 'voice-turn-test-1';
        $followupTurnId = 'voice-turn-test-2';
        $this->voiceEvent($user, $session, 'wake_detected', $voiceStart, ['voice_client_session_id' => $voiceSessionId]);
        $this->voiceEvent($user, $session, 'voice_session_started', $voiceStart->copy()->addMilliseconds(500), ['voice_client_session_id' => $voiceSessionId]);
        $this->voiceEvent($user, $session, 'user_transcript_received', $voiceStart->copy()->addMilliseconds(2000), ['voice_client_session_id' => $voiceSessionId, 'voice_client_turn_id' => $firstTurnId]);
        $this->voiceEvent($user, $session, 'thinking_visible', $voiceStart->copy()->addMilliseconds(2200), ['voice_client_session_id' => $voiceSessionId, 'voice_client_turn_id' => $firstTurnId]);
        $this->voiceEvent($user, $session, 'bean_request_sent', $voiceStart->copy()->addMilliseconds(2250), ['voice_client_session_id' => $voiceSessionId, 'voice_client_turn_id' => $firstTurnId]);
        $this->voiceEvent($user, $session, 'bean_response_received', $voiceStart->copy()->addMilliseconds(5000), ['voice_client_session_id' => $voiceSessionId, 'voice_client_turn_id' => $firstTurnId]);
        $this->voiceEvent($user, $session, 'assistant_speech_started', $voiceStart->copy()->addMilliseconds(5600), ['voice_client_session_id' => $voiceSessionId, 'voice_client_turn_id' => $firstTurnId]);
        $this->voiceEvent($user, $session, 'assistant_speech_finished', $voiceStart->copy()->addMilliseconds(8000), ['voice_client_session_id' => $voiceSessionId, 'voice_client_turn_id' => $firstTurnId]);
        $this->voiceEvent($user, $session, 'followup_window_opened', $voiceStart->copy()->addMilliseconds(8400), ['voice_client_session_id' => $voiceSessionId, 'voice_client_turn_id' => $firstTurnId]);
        $this->voiceEvent($user, $session, 'followup_transcript_received', $voiceStart->copy()->addMilliseconds(9000), ['voice_client_session_id' => $voiceSessionId, 'voice_client_turn_id' => $followupTurnId]);
        $this->voiceEvent($user, $session, 'thinking_visible', $voiceStart->copy()->addMilliseconds(9300), ['voice_client_session_id' => $voiceSessionId, 'voice_client_turn_id' => $followupTurnId]);
        $this->voiceEvent($user, $session, 'bean_response_received', $voiceStart->copy()->addMilliseconds(10000), ['failed' => true, 'voice_client_session_id' => $voiceSessionId, 'voice_client_turn_id' => $followupTurnId], $failed);
        $this->voiceEvent($user, $session, 'failure_wake_reset', $voiceStart->copy()->addMilliseconds(10400), ['voice_client_session_id' => $voiceSessionId, 'voice_client_turn_id' => $followupTurnId]);

        $jsonPath = storage_path('framework/testing/bean-ux-benchmark.json');
        $markdownPath = storage_path('framework/testing/bean-ux-benchmark.md');
        $progressPath = storage_path('framework/testing/bean-ux-progress.json');
        File::delete([$jsonPath, $markdownPath, $progressPath]);

        $exit = Artisan::call('bean:ux-benchmark', [
            '--days' => 7,
            '--json' => $jsonPath,
            '--markdown' => $markdownPath,
            '--progress' => $progressPath,
        ]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($jsonPath);
        $this->assertFileExists($markdownPath);
        $this->assertFileExists($progressPath);
        $report = json_decode(File::get($jsonPath), true);
        $this->assertSame('bean-world-class-ux-benchmark', $report['mode']);
        $this->assertSame(3, data_get($report, 'counts.runs'));
        $this->assertSame(2, data_get($report, 'counts.dashboard_runs'));
        $this->assertSame(0, data_get($report, 'counts.dashboard_ungrounded_runs'));
        $this->assertSame(1, data_get($report, 'counts.dashboard_completed_tool_runs'));
        $this->assertSame(1, data_get($report, 'counts.dashboard_failed_tool_runs'));
        $this->assertSame(0.5, data_get($report, 'metrics.task_success_rate'));
        $this->assertSame(0.3333, data_get($report, 'metrics.generic_failure_rate'));
        $this->assertEquals(1.0, data_get($report, 'metrics.dashboard_grounded_rate'));
        $this->assertEquals(1.0, data_get($report, 'metrics.voice.first_command_capture_rate'));
        $this->assertEquals(1.0, data_get($report, 'metrics.voice.followup_capture_rate'));
        $this->assertEquals(1.0, data_get($report, 'metrics.voice.failure_wake_reset_rate'));
        $this->assertSame(500, data_get($report, 'metrics.voice.latency_ms.wake_to_listening_p95'));
        $this->assertSame(300, data_get($report, 'metrics.voice.latency_ms.speech_to_thinking_p95'));
        $this->assertSame(3600, data_get($report, 'metrics.voice.latency_ms.speech_to_answer_started_p95'));
        $this->assertSame(400, data_get($report, 'metrics.voice.latency_ms.speech_finished_to_followup_opened_p95'));
        $this->assertSame('pass', data_get($report, 'target_status.voice_speech_to_answer_started_p95_ms.status'));
        $this->assertSame('fail', data_get($report, 'target_status.task_success_rate.status'));
        $progress = json_decode(File::get($progressPath), true);
        $this->assertSame('Make Bean meet world-class user-experience benchmarks.', $progress['goal']);
        $this->assertSame('bean-world-class-ux-benchmark', data_get($progress, 'latest_report.mode'));
    }

    public function test_ux_scenario_catalog_command_writes_repeatable_scenarios(): void
    {
        $jsonPath = storage_path('framework/testing/bean-ux-scenarios.json');
        $markdownPath = storage_path('framework/testing/bean-ux-scenarios.md');
        File::delete([$jsonPath, $markdownPath]);

        $exit = Artisan::call('bean:ux-scenarios', [
            '--json' => $jsonPath,
            '--markdown' => $markdownPath,
        ]);

        $this->assertSame(0, $exit);
        $catalog = json_decode(File::get($jsonPath), true);
        $this->assertSame('bean-ux-scenario-catalog', $catalog['mode']);
        $this->assertGreaterThanOrEqual(10, count($catalog['scenarios']));
        $this->assertContains('read_overdue_tasks', collect($catalog['scenarios'])->pluck('id')->all());
        $this->assertStringContainsString('Bean UX Evaluation Scenario Catalog', File::get($markdownPath));
    }

    public function test_ux_benchmark_includes_semantic_continuity_targets_from_quality_traces(): void
    {
        $this->apiToken('bean-ux-semantic@example.com');
        $user = User::where('email', 'bean-ux-semantic@example.com')->firstOrFail();
        $session = app(BeanRuntimeService::class)->createSession($user);
        $pass = $this->runWithTool(
            $user,
            $session,
            'Complete that task.',
            'Completed Benchmark clean kitchen.',
            'completed',
            1800,
            'task.complete',
            ['id' => 123],
            ['ok' => true, 'item' => ['title' => 'Benchmark clean kitchen', 'status' => 'completed']],
        );
        $fail = BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'status' => 'completed',
            'mode' => 'hermes',
            'input' => 'Add ingredient portions to that note.',
            'output' => 'Which note do you mean?',
            'started_at' => now()->subSeconds(2),
            'completed_at' => now(),
        ]);
        BeanQualityTrace::create([
            'bean_run_id' => $pass->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'mode' => 'hermes',
            'intent' => 'task.complete',
            'actions' => ['task.complete'],
            'tool_results_count' => 1,
            'user_message' => $pass->input,
            'assistant_answer' => $pass->output,
            'quality_flags' => [],
            'latency_ms' => 1800,
        ]);
        BeanQualityTrace::create([
            'bean_run_id' => $fail->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'mode' => 'hermes',
            'intent' => null,
            'actions' => [],
            'tool_results_count' => 0,
            'user_message' => $fail->input,
            'assistant_answer' => $fail->output,
            'quality_flags' => ['unnecessary_clarification', 'immediate_context_loss'],
            'latency_ms' => 2000,
        ]);

        $jsonPath = storage_path('framework/testing/bean-ux-semantic-benchmark.json');
        File::delete($jsonPath);

        $exit = Artisan::call('bean:ux-benchmark', ['--days' => 7, '--json' => $jsonPath]);

        $this->assertSame(0, $exit);
        $report = json_decode(File::get($jsonPath), true);
        $this->assertSame(0.5, data_get($report, 'metrics.semantic.followup_reference_resolution_rate'));
        $this->assertSame(0.5, data_get($report, 'metrics.semantic.unnecessary_clarification_rate'));
        $this->assertSame(0.5, data_get($report, 'metrics.semantic.immediate_context_loss_rate'));
        $this->assertSame('fail', data_get($report, 'target_status.followup_reference_resolution_rate.status'));
        $this->assertSame('fail', data_get($report, 'target_status.unnecessary_clarification_rate.status'));
        $this->assertSame('fail', data_get($report, 'target_status.immediate_context_loss_rate.status'));
    }

    public function test_ux_scenario_evaluator_scores_recorded_traces_and_semantic_metrics(): void
    {
        $this->apiToken('bean-ux-evaluator@example.com');
        $user = User::where('email', 'bean-ux-evaluator@example.com')->firstOrFail();
        $session = app(BeanRuntimeService::class)->createSession($user);

        $this->runWithTool(
            $user,
            $session,
            'What tasks do I have today?',
            'You have 1 task today: Benchmark clean kitchen.',
            'completed',
            3200,
            'task.list',
            ['time_label' => 'today'],
            ['ok' => true, 'items' => [['title' => 'Benchmark clean kitchen', 'due_at' => now()->addHour()->toIso8601String()]], 'total_count' => 1, 'time_label' => 'today'],
        );
        $this->runWithTool(
            $user,
            $session,
            'Complete that task.',
            'Completed Benchmark clean kitchen.',
            'completed',
            2500,
            'task.complete',
            ['id' => 123],
            ['ok' => true, 'item' => ['title' => 'Benchmark clean kitchen', 'status' => 'completed']],
        );
        $this->runWithTool(
            $user,
            $session,
            'What workspace is that task in?',
            'It is in Household.',
            'completed',
            2400,
            'resource.query',
            ['resource' => 'tasks'],
            ['ok' => true, 'items' => [['title' => 'Benchmark clean kitchen', 'workspace_names' => ['Household']]], 'total_count' => 1],
        );
        BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'status' => 'completed',
            'mode' => 'hermes',
            'input' => 'Add ingredient portions to that note.',
            'output' => 'Which note do you mean?',
            'started_at' => now()->subSeconds(2),
            'completed_at' => now(),
        ]);

        $jsonPath = storage_path('framework/testing/bean-ux-evaluation.json');
        $markdownPath = storage_path('framework/testing/bean-ux-evaluation.md');
        File::delete([$jsonPath, $markdownPath]);

        $exit = Artisan::call('bean:ux-evaluate-scenarios', [
            '--recent' => 50,
            '--json' => $jsonPath,
            '--markdown' => $markdownPath,
        ]);

        $this->assertSame(0, $exit);
        $report = json_decode(File::get($jsonPath), true);
        $this->assertSame('bean-ux-scenario-evaluation', $report['mode']);
        $this->assertSame('pass', data_get($report, 'scenarios.read_today_tasks.status'));
        $this->assertSame('pass', data_get($report, 'scenarios.complete_that_task_followup.status'));
        $this->assertSame('pass', data_get($report, 'scenarios.shared_workspace_query.status'));
        $this->assertSame('fail', data_get($report, 'scenarios.followup_note_edit.status'));
        $this->assertSame(0.5, data_get($report, 'semantic_metrics.followup_reference_resolution_rate'));
        $this->assertSame(0.25, data_get($report, 'semantic_metrics.unnecessary_clarification_rate'));
        $this->assertSame(0.25, data_get($report, 'semantic_metrics.immediate_context_loss_rate'));
        $this->assertStringContainsString('Bean UX Scenario Evaluation Report', File::get($markdownPath));
    }

    private function voiceEvent(User $user, BeanSession $session, string $type, Carbon $occurredAt, array $payload = [], ?BeanRun $run = null): BeanVoiceEvent
    {
        $occurredAtMs = ($occurredAt->getTimestamp() * 1000) + intdiv((int) $occurredAt->micro, 1000);

        return BeanVoiceEvent::create([
            'user_id' => $user->id,
            'bean_session_id' => $session->id,
            'bean_run_id' => $run?->id,
            'event_type' => $type,
            'payload' => $payload + ['event_client_ms' => $occurredAtMs],
            'occurred_at' => $occurredAt,
            'occurred_at_ms' => $occurredAtMs,
        ]);
    }

    private function runWithTool(
        User $user,
        BeanSession $session,
        string $input,
        string $output,
        string $status,
        int $latencyMs,
        string $action = 'task.list',
        array $arguments = ['time_label' => 'overdue'],
        ?array $result = null,
    ): BeanRun {
        $started = Carbon::now()->subMilliseconds($latencyMs);
        $run = BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'status' => $status,
            'mode' => 'hermes',
            'input' => $input,
            'output' => $output,
            'started_at' => $started,
            'completed_at' => $started->copy()->addMilliseconds($latencyMs),
        ]);
        BeanToolCall::create([
            'bean_run_id' => $run->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'action' => $action,
            'arguments' => $arguments,
            'status' => $status === 'completed' ? 'completed' : 'failed',
            'result' => $result ?? ($status === 'completed' ? ['ok' => true, 'items' => []] : ['ok' => false]),
            'started_at' => $started,
            'completed_at' => $started->copy()->addMilliseconds($latencyMs),
        ]);

        return $run->refresh();
    }
}
