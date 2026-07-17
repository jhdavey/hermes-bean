<?php

namespace Tests\Feature;

use App\Models\BeanRun;
use App\Models\BeanSession;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use App\Services\Bean\BeanActionExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BeanRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_text_message_creates_task_and_activity(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-create@example.com');

        $response = $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Add a task to call mom',
        ])->assertOk();

        $response->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['role' => 'assistant']);

        $this->assertDatabaseHas('tasks', ['title' => 'call mom']);
        $this->assertDatabaseHas('bean_activity_events', ['type' => 'tool_completed']);
        $this->assertDatabaseHas('dashboard_changes', ['resource_type' => 'task', 'action' => 'created']);
    }

    public function test_destructive_action_requires_confirmation_then_can_be_approved(): void
    {
        $token = $this->apiToken('bean-delete@example.com');
        $user = User::where('email', 'bean-delete@example.com')->firstOrFail();
        $workspaceId = $user->default_workspace_id;
        $task = Task::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $user->id,
            'title' => 'Delete me',
            'type' => 'todo',
            'status' => 'open',
        ]);
        $session = BeanSession::create(['user_id' => $user->id, 'workspace_id' => $workspaceId, 'title' => 'Test', 'status' => 'active']);
        $run = BeanRun::create(['bean_session_id' => $session->id, 'user_id' => $user->id, 'workspace_id' => $workspaceId, 'status' => 'running', 'mode' => 'text']);

        $result = app(BeanActionExecutor::class)->execute($session, $run, 'task.delete', ['id' => $task->id]);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['requires_confirmation']);
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);

        $this->withToken($token)->postJson('/api/bean/confirmations/'.$result['confirmation_id'].'/approve')
            ->assertOk()
            ->assertJsonPath('data.result.ok', true);

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    public function test_bean_events_stream_activity(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-events@example.com');

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'What time is it today?',
        ])->assertOk();

        $response = $this->withToken($token)->get('/api/bean/events?after=0&wait=0');
        $response->assertOk();
        $this->assertStringContainsString('event:', $response->streamedContent());
        $this->assertStringContainsString('Thinking', $response->streamedContent());
    }

    public function test_bean_note_creation_respects_plan_limits(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-note-limit@example.com');
        $user = User::where('email', 'bean-note-limit@example.com')->firstOrFail();
        $workspaceId = $user->default_workspace_id;

        for ($i = 1; $i <= 10; $i++) {
            Note::create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'created_by_user_id' => $user->id,
                'title' => "Existing note {$i}",
                'plain_text' => 'Already at the base plan limit.',
            ]);
        }

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Write down one more note',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'Your current plan includes up to 10 notes.']);

        $this->assertSame(10, Note::where('user_id', $user->id)->count());
    }

    public function test_bean_task_creation_uses_domain_plan_limits_for_recurrence(): void
    {
        $user = User::factory()->create(['email' => 'bean-task-limit@example.com']);
        app(\App\Services\WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $workspaceId = $user->default_workspace_id;
        $session = BeanSession::create(['user_id' => $user->id, 'workspace_id' => $workspaceId, 'title' => 'Test', 'status' => 'active']);
        $run = BeanRun::create(['bean_session_id' => $session->id, 'user_id' => $user->id, 'workspace_id' => $workspaceId, 'status' => 'running', 'mode' => 'text']);

        $result = app(BeanActionExecutor::class)->execute($session, $run, 'task.create', [
            'title' => 'Recurring base-plan task',
            'type' => 'todo',
            'metadata' => ['recurrence' => 'daily'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Recurring tasks', $result['error'] ?? '');
        $this->assertDatabaseMissing('tasks', ['title' => 'Recurring base-plan task']);
    }

    public function test_bean_completing_recurring_task_uses_domain_completion_behavior(): void
    {
        $user = User::factory()->create(['email' => 'bean-recurring-complete@example.com']);
        app(\App\Services\WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $workspaceId = $user->default_workspace_id;
        $task = Task::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $user->id,
            'title' => 'Recurring chore',
            'type' => 'chore',
            'status' => 'open',
            'due_at' => now()->subDay()->startOfHour(),
            'metadata' => ['recurrence' => 'daily'],
        ]);
        $session = BeanSession::create(['user_id' => $user->id, 'workspace_id' => $workspaceId, 'title' => 'Test', 'status' => 'active']);
        $run = BeanRun::create(['bean_session_id' => $session->id, 'user_id' => $user->id, 'workspace_id' => $workspaceId, 'status' => 'running', 'mode' => 'text']);

        $result = app(BeanActionExecutor::class)->execute($session, $run, 'task.complete', ['id' => $task->id]);

        $this->assertTrue($result['ok']);
        $task->refresh();
        $this->assertSame('open', $task->status);
        $this->assertNull($task->completed_at);
        $this->assertTrue($task->due_at->isFuture());
        $this->assertSame(1, $task->metadata['completion_count'] ?? null);
    }
}
