<?php

namespace Tests\Feature;

use App\Models\BeanRun;
use App\Models\BeanSession;
use App\Models\BeanToolCall;
use App\Models\User;
use App\Services\Bean\Quality\BeanQualityAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BeanQualityLabTest extends TestCase
{
    use RefreshDatabase;

    public function test_bean_evaluate_runs_seeded_scenarios_and_writes_score_reports(): void
    {
        config(['services.openai.api_key' => null]);
        Carbon::setTestNow(Carbon::parse('2026-07-17 09:15:00', config('app.timezone')));
        $jsonPath = storage_path('app/bean-quality/test-report.json');
        $markdownPath = storage_path('app/bean-quality/test-report.md');
        @unlink($jsonPath);
        @unlink($markdownPath);

        $exit = Artisan::call('bean:evaluate', [
            '--json' => $jsonPath,
            '--markdown' => $markdownPath,
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertFileExists($jsonPath);
        $this->assertFileExists($markdownPath);

        $report = json_decode((string) file_get_contents($jsonPath), true);
        $this->assertIsArray($report);
        $this->assertGreaterThanOrEqual(100, $report['scenario_count']);
        $this->assertArrayHasKey('overall_score', $report);
        $this->assertArrayHasKey('factual_correctness', $report['categories']);
        $this->assertArrayHasKey('safety', $report['categories']);
        $this->assertContains('today task list separates overdue and due today', array_column($report['results'], 'name'));
        $this->assertContains('current time answers directly', array_column($report['results'], 'name'));
        $this->assertContains('misheard transcript recovers recent task entity', array_column($report['results'], 'name'));
        $this->assertContains('online recipe request uses external lookup path', array_column($report['results'], 'name'));
        $this->assertContains('tomorrow calendar filters tomorrow only', array_column($report['results'], 'name'));
        $this->assertStringContainsString('Bean Quality Report', (string) file_get_contents($markdownPath));
        $this->assertStringContainsString('CI gate', (string) file_get_contents($markdownPath));

        Carbon::setTestNow();
    }

    public function test_quality_audit_flags_bad_factual_answers_and_persists_trace(): void
    {
        $user = User::factory()->create(['email' => 'bean-quality-trace@example.com']);
        app(\App\Services\WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $session = BeanSession::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'title' => 'Trace test',
            'status' => 'active',
        ]);
        $run = BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'status' => 'completed',
            'mode' => 'text',
            'input' => 'What time is it?',
            'output' => 'I’ll check the current time. Done.',
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);
        BeanToolCall::create([
            'bean_run_id' => $run->id,
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'action' => 'time.now',
            'arguments' => [],
            'status' => 'completed',
            'result' => ['ok' => true, 'now' => now()->toIso8601String(), 'timezone' => 'UTC'],
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);

        $trace = app(BeanQualityAuditService::class)->traceRun($run->fresh());

        $this->assertContains('generic_done_after_factual_question', $trace->quality_flags);
        $this->assertContains('missing_time_after_time_tool', $trace->quality_flags);
        $this->assertDatabaseHas('bean_quality_traces', [
            'bean_run_id' => $run->id,
            'intent' => 'time.now',
        ]);

        $dateRun = BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'status' => 'completed',
            'mode' => 'text',
            'input' => "What is today's date?",
            'output' => 'The current time is 10:44 PM UTC.',
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);
        BeanToolCall::create([
            'bean_run_id' => $dateRun->id,
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'action' => 'time.now',
            'arguments' => [],
            'status' => 'completed',
            'result' => ['ok' => true, 'now' => now()->toIso8601String(), 'timezone' => 'UTC'],
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);

        $dateTrace = app(BeanQualityAuditService::class)->traceRun($dateRun->fresh());
        $this->assertContains('missing_date_after_time_tool', $dateTrace->quality_flags);
    }

    public function test_quality_audit_flags_missing_tool_recipe_and_correction_failures(): void
    {
        $user = User::factory()->create(['email' => 'bean-quality-new-flags@example.com']);
        app(\App\Services\WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $session = BeanSession::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'title' => 'Trace test',
            'status' => 'active',
        ]);

        $workspaceRun = BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'status' => 'completed',
            'mode' => 'text',
            'input' => 'Which workspace is Pay the travel card in?',
            'output' => 'Pay the travel card is in Personal.',
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);
        $recipeRun = BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'status' => 'completed',
            'mode' => 'text',
            'input' => 'Can you create a recipe note for quesadillas?',
            'output' => 'Please provide the recipe details if you want them included.',
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);
        $correctionRun = BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'status' => 'completed',
            'mode' => 'voice',
            'input' => "That's not what I said. I said pay the card.",
            'output' => 'I can help with that.',
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);

        $calendarRun = BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'status' => 'completed',
            'mode' => 'text',
            'input' => 'Do I have any events for tomorrow on the calendar?',
            'output' => 'You have 18 upcoming calendar events: Old event and many more.',
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);
        BeanToolCall::create([
            'bean_run_id' => $calendarRun->id,
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'action' => 'calendar_event.list',
            'arguments' => ['starts_at' => now()->addDay()->startOfDay()->toIso8601String(), 'ends_at' => now()->addDay()->endOfDay()->toIso8601String()],
            'status' => 'completed',
            'result' => ['ok' => true, 'items' => [['title' => 'Old event', 'starts_at' => now()->subMonth()->toIso8601String()]]],
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);

        $workspaceTrace = app(BeanQualityAuditService::class)->traceRun($workspaceRun->fresh());
        $recipeTrace = app(BeanQualityAuditService::class)->traceRun($recipeRun->fresh());
        $correctionTrace = app(BeanQualityAuditService::class)->traceRun($correctionRun->fresh());
        $calendarTrace = app(BeanQualityAuditService::class)->traceRun($calendarRun->fresh());

        $this->assertContains('factual_app_data_answer_without_tool_call', $workspaceTrace->quality_flags);
        $this->assertContains('recipe_request_missing_generated_content', $recipeTrace->quality_flags);
        $this->assertContains('correction_turn_without_recovery_action', $correctionTrace->quality_flags);
        $this->assertContains('calendar_tomorrow_missing_time_scope', $calendarTrace->quality_flags);
    }

    public function test_production_smoke_mode_audits_recent_traces_without_seeding_or_mutating_resources(): void
    {
        $user = User::factory()->create(['email' => 'bean-production-smoke@example.com']);
        app(\App\Services\WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $session = BeanSession::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'title' => 'Production smoke',
            'status' => 'active',
        ]);
        $run = BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'status' => 'completed',
            'mode' => 'voice',
            'input' => 'What tasks do I have today?',
            'output' => 'Done.',
            'started_at' => now()->subSeconds(2),
            'completed_at' => now(),
        ]);
        BeanToolCall::create([
            'bean_run_id' => $run->id,
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'action' => 'task.list',
            'arguments' => ['time_label' => 'today'],
            'status' => 'completed',
            'result' => ['ok' => true, 'time_label' => 'today', 'items' => [[
                'title' => 'Pay the travel card',
                'due_at' => now()->subDay()->toIso8601String(),
            ]]],
            'started_at' => now()->subSeconds(2),
            'completed_at' => now()->subSecond(),
        ]);
        $jsonPath = storage_path('app/bean-quality/production-smoke-test.json');
        @unlink($jsonPath);

        $exit = Artisan::call('bean:evaluate', [
            '--production-smoke' => true,
            '--recent' => 20,
            '--json' => $jsonPath,
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $report = json_decode((string) file_get_contents($jsonPath), true);
        $this->assertSame('production-smoke', $report['mode']);
        $this->assertSame(1, $report['trace_count']);
        $this->assertContains('generic_done_after_factual_question', $report['top_quality_flags']);
        $this->assertContains('today_task_list_missing_overdue_label', $report['top_quality_flags']);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('reminders', 0);
    }
}
