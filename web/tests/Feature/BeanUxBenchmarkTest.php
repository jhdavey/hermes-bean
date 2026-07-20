<?php

namespace Tests\Feature;

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
        BeanVoiceEvent::create([
            'user_id' => $user->id,
            'bean_session_id' => $session->id,
            'event_type' => 'wake_detected',
            'occurred_at' => now(),
        ]);
        BeanVoiceEvent::create([
            'user_id' => $user->id,
            'bean_session_id' => $session->id,
            'event_type' => 'user_transcript_received',
            'occurred_at' => now(),
        ]);
        BeanVoiceEvent::create([
            'user_id' => $user->id,
            'bean_session_id' => $session->id,
            'bean_run_id' => $failed->id,
            'event_type' => 'bean_response_received',
            'payload' => ['failed' => true],
            'occurred_at' => now(),
        ]);
        BeanVoiceEvent::create([
            'user_id' => $user->id,
            'bean_session_id' => $session->id,
            'event_type' => 'failure_wake_reset',
            'occurred_at' => now(),
        ]);

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
        $this->assertSame(2, data_get($report, 'counts.runs'));
        $this->assertSame(0.5, data_get($report, 'metrics.task_success_rate'));
        $this->assertSame(0.5, data_get($report, 'metrics.generic_failure_rate'));
        $this->assertEquals(1.0, data_get($report, 'metrics.voice.first_command_capture_rate'));
        $this->assertEquals(1.0, data_get($report, 'metrics.voice.failure_wake_reset_rate'));
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

    private function runWithTool(User $user, BeanSession $session, string $input, string $output, string $status, int $latencyMs): BeanRun
    {
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
            'action' => 'task.list',
            'arguments' => ['time_label' => 'overdue'],
            'status' => $status === 'completed' ? 'completed' : 'failed',
            'result' => $status === 'completed' ? ['ok' => true, 'items' => []] : ['ok' => false],
            'started_at' => $started,
            'completed_at' => $started->copy()->addMilliseconds($latencyMs),
        ]);

        return $run->refresh();
    }
}
