<?php

namespace Tests\Feature;

use App\Models\BeanRun;
use App\Models\BeanSession;
use App\Models\CalendarEvent;
use App\Models\Note;
use App\Models\NoteFolder;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\Bean\BeanActionExecutor;
use App\Services\Domain\DomainResourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
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

    public function test_openai_text_model_uses_strict_structured_action_schema(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.bean_text_model' => 'gpt-4.1-mini',
        ]);
        $token = $this->apiToken('bean-openai@example.com');

        Http::fake(function (HttpRequest $request) {
            $this->assertSame('https://api.openai.com/v1/chat/completions', $request->url());
            $payload = $request->data();
            $this->assertSame('gpt-4.1-mini', $payload['model'] ?? null);
            $this->assertSame('json_schema', data_get($payload, 'response_format.type'));
            $this->assertTrue((bool) data_get($payload, 'response_format.json_schema.strict'));
            $this->assertSame('bean_action_proposal', data_get($payload, 'response_format.json_schema.name'));
            $actionsEnum = data_get($payload, 'response_format.json_schema.schema.properties.actions.items.properties.action.enum');
            $argumentsSchema = data_get($payload, 'response_format.json_schema.schema.properties.actions.items.properties.arguments');
            $this->assertContains('task.create', $actionsEnum);
            $this->assertContains('calendar_event.update', $actionsEnum);
            $this->assertFalse((bool) ($argumentsSchema['additionalProperties'] ?? true));
            $this->assertContains('title', $argumentsSchema['required'] ?? []);

            return Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'response' => 'I’ll add that task.',
                            'actions' => [[
                                'action' => 'task.create',
                                'arguments' => ['title' => 'from structured model', 'type' => 'todo'],
                            ]],
                        ]),
                    ],
                ]],
            ], 200);
        });

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Add a task from the real model',
        ])->assertOk()
            ->assertJsonPath('data.run.model', 'gpt-4.1-mini');

        $this->assertDatabaseHas('tasks', ['title' => 'from structured model']);
        Http::assertSentCount(1);
    }

    public function test_local_parser_completes_unambiguous_task_without_creating_duplicate(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-complete-local@example.com');
        $user = User::where('email', 'bean-complete-local@example.com')->firstOrFail();
        $task = Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'call mom',
            'type' => 'todo',
            'status' => 'open',
        ]);

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Complete task call mom',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed');

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'completed']);
        $this->assertSame(1, Task::where('user_id', $user->id)->count());
    }

    public function test_local_parser_routes_delete_task_to_confirmation(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-delete-local@example.com');
        $user = User::where('email', 'bean-delete-local@example.com')->firstOrFail();
        $task = Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'old invoice',
            'type' => 'todo',
            'status' => 'open',
        ]);

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Delete task old invoice',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'waiting_confirmation');

        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
        $this->assertDatabaseHas('bean_confirmation_requests', ['action' => 'task.delete', 'status' => 'pending']);
    }

    public function test_local_parser_lists_notes_instead_of_creating_note_for_show_request(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-list-notes-local@example.com');
        $user = User::where('email', 'bean-list-notes-local@example.com')->firstOrFail();
        Note::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Existing note',
            'plain_text' => 'Do not duplicate me.',
        ]);

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Show my notes',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed');

        $this->assertSame(1, Note::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('bean_tool_calls', ['action' => 'note.list', 'status' => 'completed']);
    }

    public function test_local_parser_routes_remind_me_to_reminder_not_task(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-reminder-local@example.com');
        $user = User::where('email', 'bean-reminder-local@example.com')->firstOrFail();

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Remind me to call mom',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed');

        $this->assertDatabaseHas('reminders', ['user_id' => $user->id, 'title' => 'call mom']);
        $this->assertDatabaseHas('bean_tool_calls', ['action' => 'reminder.create', 'status' => 'completed']);
        $this->assertSame(0, Task::where('user_id', $user->id)->count());
    }

    public function test_local_parser_routes_schedule_call_to_calendar_not_task(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-calendar-local@example.com');
        $user = User::where('email', 'bean-calendar-local@example.com')->firstOrFail();

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Schedule call with Bob',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed');

        $this->assertDatabaseHas('calendar_events', ['user_id' => $user->id, 'title' => 'call with Bob']);
        $this->assertDatabaseHas('bean_tool_calls', ['action' => 'calendar_event.create', 'status' => 'completed']);
        $this->assertSame(0, Task::where('user_id', $user->id)->count());
    }

    public function test_task_list_response_says_the_actual_tasks(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-task-list-response@example.com');
        $user = User::where('email', 'bean-task-list-response@example.com')->firstOrFail();
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Review launch checklist',
            'type' => 'todo',
            'status' => 'open',
        ]);

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'check my to do list',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'You have 1 open task: Review launch checklist.']);
    }

    public function test_local_parser_completes_reminder_not_task_for_reminder_phrase(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-reminder-complete-local@example.com');
        $user = User::where('email', 'bean-reminder-complete-local@example.com')->firstOrFail();
        $reminder = Reminder::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'call mom',
            'status' => 'open',
            'remind_at' => now()->addHour(),
        ]);

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Complete reminder to call mom',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed');

        $this->assertDatabaseHas('reminders', ['id' => $reminder->id, 'status' => 'completed']);
        $this->assertDatabaseHas('bean_tool_calls', ['action' => 'reminder.complete', 'status' => 'completed']);
        $this->assertSame(0, Task::where('user_id', $user->id)->count());
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

    public function test_realtime_session_requires_openai_configuration(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-realtime-missing-key@example.com');
        Http::fake();

        $this->withToken($token)->postJson('/api/bean/realtime/session')
            ->assertStatus(503)
            ->assertJsonPath('message', 'OpenAI realtime is not configured.');

        Http::assertNothingSent();
    }

    public function test_realtime_session_mints_ga_client_secret_for_active_voice_turns(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.realtime_model' => 'gpt-realtime',
            'services.openai.realtime_voice' => 'alloy',
        ]);
        $token = $this->apiToken('bean-realtime-client-secret@example.com');

        Http::fake(function (HttpRequest $request) {
            $this->assertSame('https://api.openai.com/v1/realtime/client_secrets', $request->url());
            $payload = $request->data();
            $this->assertSame('realtime', data_get($payload, 'session.type'));
            $this->assertSame('gpt-realtime', data_get($payload, 'session.model'));
            $this->assertSame('alloy', data_get($payload, 'session.audio.output.voice'));
            $this->assertSame('gpt-4o-mini-transcribe', data_get($payload, 'session.audio.input.transcription.model'));
            $this->assertFalse((bool) data_get($payload, 'session.audio.input.turn_detection.create_response'));
            $this->assertStringContainsString('Laravel is the source of truth', data_get($payload, 'session.instructions'));

            return Http::response([
                'value' => 'ek_test_voice_secret',
                'expires_at' => 1234567890,
                'session' => ['id' => 'sess_test'],
            ], 200);
        });

        $this->withToken($token)->postJson('/api/bean/realtime/session')
            ->assertOk()
            ->assertJsonPath('client_secret.value', 'ek_test_voice_secret');

        Http::assertSentCount(1);
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

    public function test_bean_note_creation_rejects_note_folder_outside_current_workspace(): void
    {
        $owner = User::factory()->create(['email' => 'folder-owner@example.com']);
        app(\App\Services\WorkspaceService::class)->ensurePersonalWorkspaceForUser($owner);
        $ownerWorkspaceId = $owner->default_workspace_id;
        $foreignFolder = NoteFolder::create([
            'user_id' => $owner->id,
            'workspace_id' => $ownerWorkspaceId,
            'created_by_user_id' => $owner->id,
            'name' => 'Private folder',
        ]);

        $user = User::factory()->create(['email' => 'bean-folder-scope@example.com']);
        app(\App\Services\WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $workspaceId = $user->default_workspace_id;
        $session = BeanSession::create(['user_id' => $user->id, 'workspace_id' => $workspaceId, 'title' => 'Test', 'status' => 'active']);
        $run = BeanRun::create(['bean_session_id' => $session->id, 'user_id' => $user->id, 'workspace_id' => $workspaceId, 'status' => 'running', 'mode' => 'text']);

        $result = app(BeanActionExecutor::class)->execute($session, $run, 'note.create', [
            'title' => 'Scoped note',
            'plain_text' => 'Should not attach to a foreign folder.',
            'note_folder_id' => $foreignFolder->id,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('note folder', strtolower($result['error'] ?? ''));
        $this->assertDatabaseMissing('notes', [
            'title' => 'Scoped note',
            'note_folder_id' => $foreignFolder->id,
        ]);
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

    public function test_bean_calendar_move_preserves_duration_when_only_start_changes(): void
    {
        $user = User::factory()->create(['email' => 'bean-calendar-move@example.com']);
        app(\App\Services\WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $workspaceId = $user->default_workspace_id;
        $startsAt = now()->addDay()->setTime(12, 0, 0);
        $event = CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $user->id,
            'title' => 'Lunch with Sam',
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->addHour(),
            'status' => 'scheduled',
            'recurrence' => 'none',
        ]);
        $session = BeanSession::create(['user_id' => $user->id, 'workspace_id' => $workspaceId, 'title' => 'Test', 'status' => 'active']);
        $run = BeanRun::create(['bean_session_id' => $session->id, 'user_id' => $user->id, 'workspace_id' => $workspaceId, 'status' => 'running', 'mode' => 'text']);

        $newStart = now()->addDays(2)->setTime(15, 30, 0);
        $result = app(BeanActionExecutor::class)->execute($session, $run, 'calendar_event.update', [
            'id' => $event->id,
            'starts_at' => $newStart->toIso8601String(),
        ]);

        $this->assertTrue($result['ok']);
        $event->refresh();
        $this->assertTrue($event->starts_at->equalTo($newStart));
        $this->assertTrue($event->ends_at->equalTo((clone $newStart)->addHour()));
    }

    public function test_bean_delete_honors_selected_linked_workspace_scope(): void
    {
        $user = User::factory()->create(['email' => 'bean-linked-delete@example.com']);
        $personalWorkspaceId = app(\App\Services\WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $family = app(\App\Services\WorkspaceService::class)->createHousehold($user, 'Family');
        $task = app(DomainResourceService::class)->createTask($user, [
            'workspace_id' => $personalWorkspaceId,
            'title' => 'Shared grocery run',
            'type' => 'todo',
            'sync_to_workspace_ids' => [$family->id],
        ]);
        $familyCopyId = Task::where('workspace_id', $family->id)->value('id');
        $session = BeanSession::create(['user_id' => $user->id, 'workspace_id' => $personalWorkspaceId, 'title' => 'Test', 'status' => 'active']);
        $run = BeanRun::create(['bean_session_id' => $session->id, 'user_id' => $user->id, 'workspace_id' => $personalWorkspaceId, 'status' => 'running', 'mode' => 'confirmation']);

        $result = app(BeanActionExecutor::class)->execute($session, $run, 'task.delete', [
            'id' => $task->id,
            'delete_from_workspace_ids' => [$family->id],
        ], true);

        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'workspace_id' => $personalWorkspaceId]);
        $this->assertDatabaseMissing('tasks', ['id' => $familyCopyId]);
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
