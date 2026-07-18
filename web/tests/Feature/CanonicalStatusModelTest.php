<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\PlanHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CanonicalStatusModelTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_task_and_reminder_history_recognizes_only_exact_canonical_completed_status(): void
    {
        $this->apiToken('canonical-model-status@example.com');
        $user = User::where('email', 'canonical-model-status@example.com')->firstOrFail();
        $workspaceId = (int) $user->default_workspace_id;
        $old = Carbon::parse('2026-06-01T12:00:00Z');
        $cutoff = Carbon::parse('2026-07-01T12:00:00Z');
        $history = app(PlanHistoryService::class);

        foreach (['completed', 'complete', 'done', 'COMPLETED'] as $status) {
            $task = Task::create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'created_by_user_id' => $user->id,
                'title' => "Task {$status}",
                'type' => 'todo',
                'status' => $status,
                'completed_at' => $old,
            ]);

            $this->assertSame($status === 'completed', $task->isCompleted());
            $this->assertSame($status === 'completed', $history->taskIsPrunable($task, $cutoff));
        }

        foreach (['completed', 'complete', 'done', 'dismissed', 'canceled', 'cancelled', 'skipped', 'archived', 'COMPLETED'] as $status) {
            $reminder = Reminder::create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'created_by_user_id' => $user->id,
                'title' => "Reminder {$status}",
                'status' => $status,
                'remind_at' => $old,
            ]);

            $this->assertSame($status === 'completed', $history->reminderIsPrunable($reminder, $cutoff));
        }
    }

    public function test_active_task_projection_recognizes_only_canonical_open_status(): void
    {
        Carbon::setTestNow('2026-07-14T12:00:00Z');
        $this->apiToken('canonical-task-active-status@example.com');
        $user = User::where('email', 'canonical-task-active-status@example.com')->firstOrFail();
        $workspaceId = (int) $user->default_workspace_id;

        foreach ([
            'open task' => 'open',
            'completed task' => 'completed',
            'semantic-incomplete task' => 'incomplete',
        ] as $title => $status) {
            Task::create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'created_by_user_id' => $user->id,
                'title' => $title,
                'type' => 'todo',
                'status' => $status,
                'due_at' => now()->subDays(2),
                'completed_at' => $status === 'completed' ? now()->subDay() : null,
                'metadata' => ['recurrence' => 'daily'],
            ]);
        }

        $visible = Task::query()->visibleInActiveViews()->pluck('title')->all();

        $this->assertContains('open task', $visible);
        $this->assertNotContains('completed task', $visible);
        $this->assertNotContains('semantic-incomplete task', $visible);
    }
}
