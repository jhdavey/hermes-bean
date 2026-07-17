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
use App\Models\Workspace;
use App\Services\Bean\BeanActionExecutor;
use App\Services\Domain\DomainResourceService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
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
            $this->assertContains('resource.query', $actionsEnum);
            $this->assertContains('resource.relationships', $actionsEnum);
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

    public function test_task_workspace_question_returns_the_actual_workspace_names(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-task-workspace-response@example.com');
        $user = User::where('email', 'bean-task-workspace-response@example.com')->firstOrFail();
        $personalWorkspace = Workspace::findOrFail($user->default_workspace_id);
        $family = app(WorkspaceService::class)->createHousehold($user, 'Family');

        app(DomainResourceService::class)->createTask($user, [
            'workspace_id' => $personalWorkspace->id,
            'title' => 'Pay the travel card',
            'type' => 'todo',
            'status' => 'open',
            'sync_to_workspace_ids' => [$family->id],
        ]);

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'What workspaces pay the travel card in?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'Pay the travel card is in these workspaces: '.$personalWorkspace->name.' and Family.']);
        $this->assertDatabaseHas('bean_tool_calls', [
            'action' => 'resource.query',
            'status' => 'completed',
        ]);
    }

    public function test_generic_resource_query_explains_why_task_is_on_today_list(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-task-why-today@example.com');
        $user = User::where('email', 'bean-task-why-today@example.com')->firstOrFail();
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Pay the travel card',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now()->subDay()->setTime(9, 0),
        ]);

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Why is pay the travel card showing on today list?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => "Pay the travel card is on today's list because it is overdue and still open."]);

        $this->assertDatabaseHas('bean_tool_calls', [
            'action' => 'resource.query',
            'status' => 'completed',
        ]);
    }

    public function test_final_answer_synthesis_uses_tool_results_for_resource_questions(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.bean_text_model' => 'gpt-4.1-mini',
        ]);
        $token = $this->apiToken('bean-resource-synthesis@example.com');
        $user = User::where('email', 'bean-resource-synthesis@example.com')->firstOrFail();
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Pay the travel card',
            'type' => 'todo',
            'status' => 'open',
        ]);

        Http::fakeSequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'response' => 'I’ll check that.',
                            'actions' => [[
                                'action' => 'resource.query',
                                'arguments' => ['resource' => 'tasks', 'query' => 'Pay the travel card', 'include_workspaces' => true],
                            ]],
                        ]),
                    ],
                ]],
            ], 200)
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'answer' => 'Pay the travel card is in your personal workspace.',
                        ]),
                    ],
                ]],
            ], 200);

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'What workspace is Pay the travel card in?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'Pay the travel card is in your personal workspace.']);

        Http::assertSentCount(2);
    }

    public function test_follow_up_workspace_question_resolves_first_recent_task(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-follow-up-reference@example.com');
        $user = User::where('email', 'bean-follow-up-reference@example.com')->firstOrFail();
        $workspace = Workspace::findOrFail($user->default_workspace_id);
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $user->id,
            'title' => 'Pay the travel card',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now()->setTime(9, 0),
        ]);
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $user->id,
            'title' => 'Clean outdoor grout',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now()->setTime(10, 0),
        ]);

        $first = $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'what is on my todo list for today?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed');

        $sessionId = data_get($first->json(), 'data.session.id');
        $this->withToken($token)->postJson('/api/bean/messages', [
            'session_id' => $sessionId,
            'content' => 'what workspace is the first one in?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'Pay the travel card is in the '.$workspace->name.' workspace.']);
    }

    public function test_correction_after_misheard_workspace_query_recovers_recent_task_entity(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-correction-recovery@example.com');
        $user = User::where('email', 'bean-correction-recovery@example.com')->firstOrFail();
        $workspace = Workspace::findOrFail($user->default_workspace_id);
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $user->id,
            'title' => 'Pay the travel card',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now()->setTime(9, 0),
        ]);

        $first = $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'what is on my todo list for today?',
        ])->assertOk();
        $sessionId = data_get($first->json(), 'data.session.id');

        $this->withToken($token)->postJson('/api/bean/messages', [
            'session_id' => $sessionId,
            'content' => 'Which workspace is the page avocado in?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'I heard “page avocado,” but I think you may mean Pay the travel card. Pay the travel card is in the '.$workspace->name.' workspace.']);

        $this->withToken($token)->postJson('/api/bean/messages', [
            'session_id' => $sessionId,
            'content' => "That's not what I said. I said pay the card.",
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'Got it — you meant Pay the travel card. Pay the travel card is in the '.$workspace->name.' workspace.']);
    }

    public function test_openai_planner_cannot_answer_app_data_facts_without_a_tool_call(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.bean_text_model' => 'gpt-4.1-mini',
        ]);
        $token = $this->apiToken('bean-tool-required-facts@example.com');
        $user = User::where('email', 'bean-tool-required-facts@example.com')->firstOrFail();
        $workspace = Workspace::findOrFail($user->default_workspace_id);
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $user->id,
            'title' => 'Pay the travel card',
            'type' => 'todo',
            'status' => 'open',
        ]);

        Http::fakeSequence()->push([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'response' => 'Pay the travel card is in your personal workspace.',
                        'actions' => [],
                    ]),
                ],
            ]],
        ], 200);

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Which workspace is Pay the travel card in?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'Pay the travel card is in the '.$workspace->name.' workspace.']);

        $this->assertDatabaseHas('bean_tool_calls', [
            'action' => 'resource.query',
            'status' => 'completed',
        ]);
        Http::assertSentCount(1);
    }

    public function test_recipe_note_request_generates_useful_recipe_content(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-recipe-note@example.com');
        $user = User::where('email', 'bean-recipe-note@example.com')->firstOrFail();

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Can you create a recipe note for quesadillas?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'I created a recipe note for quesadillas with ingredients and quick steps.']);

        $note = Note::where('user_id', $user->id)->where('title', 'Quesadillas Recipe')->firstOrFail();
        $this->assertStringContainsString('Ingredients', $note->plain_text);
        $this->assertStringContainsString('Instructions', $note->plain_text);
        $this->assertStringContainsString('tortillas', strtolower($note->plain_text));
        $this->assertDatabaseHas('bean_tool_calls', ['action' => 'note.create', 'status' => 'completed']);
    }

    public function test_online_recipe_request_uses_lookup_instead_of_existing_notes_as_fake_web(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-recipe-lookup@example.com');
        $user = User::where('email', 'bean-recipe-lookup@example.com')->firstOrFail();
        Note::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Quesadillas Recipe',
            'plain_text' => 'Old private note should not be treated as a web result.',
        ]);

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Can you go online and find a recipe for quesadillas?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'I found a simple quesadillas recipe: fill flour tortillas with cheese, cook until crisp and melted, then serve with salsa or sour cream.']);

        $this->assertDatabaseHas('bean_tool_calls', ['action' => 'recipe.lookup', 'status' => 'completed']);
        $this->assertDatabaseMissing('bean_tool_calls', ['action' => 'note.search']);
    }

    public function test_follow_up_add_recipes_to_recent_meal_note_preserves_existing_meals(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-meal-recipes@example.com');
        $user = User::where('email', 'bean-meal-recipes@example.com')->firstOrFail();

        $first = $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Okay, now, can you create a note with five simple dinner meals for this coming week?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed');
        $sessionId = data_get($first->json(), 'data.session.id');

        $this->withToken($token)->postJson('/api/bean/messages', [
            'session_id' => $sessionId,
            'content' => 'For each of those meals, can you add a recipe?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'I added simple recipes under each of the five meals in Simple Dinner Meals for This Coming Week.']);

        $note = Note::where('user_id', $user->id)->where('title', 'Simple Dinner Meals for This Coming Week')->firstOrFail();
        foreach (['Grilled chicken with steamed vegetables', 'Spaghetti with marinara sauce', 'Baked salmon with rice and broccoli', 'Tacos with ground beef and salad', 'Vegetable stir-fry with tofu'] as $meal) {
            $this->assertStringContainsString($meal, $note->plain_text);
        }
        $this->assertStringContainsString('Recipe:', $note->plain_text);
        $this->assertDatabaseHas('bean_tool_calls', ['action' => 'note.update', 'status' => 'completed']);
    }

    public function test_today_task_list_response_filters_to_open_tasks_due_today(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-today-task-list-response@example.com');
        $user = User::where('email', 'bean-today-task-list-response@example.com')->firstOrFail();

        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Overdue grout cleanup',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now()->subDay()->setTime(10, 0),
        ]);
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Today launch call',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now()->setTime(10, 0),
        ]);
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Tomorrow prep',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now()->addDay()->setTime(10, 0),
        ]);
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Completed today task',
            'type' => 'todo',
            'status' => 'completed',
            'due_at' => now()->setTime(11, 0),
        ]);

        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'No date task',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => null,
        ]);

        $response = $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'what is on my todo list for today?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'You have 2 open tasks due by today: 1 overdue — Overdue grout cleanup; 1 due today — Today launch call.']);

        $this->assertStringNotContainsString('Tomorrow prep', $response->getContent());
        $this->assertStringNotContainsString('No date task', $response->getContent());

        $this->assertDatabaseHas('bean_tool_calls', [
            'action' => 'task.list',
            'status' => 'completed',
        ]);
    }

    public function test_today_task_list_response_labels_overdue_and_due_today_items_even_with_openai_planner(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.bean_text_model' => 'gpt-4.1-mini',
        ]);
        $token = $this->apiToken('bean-openai-today-overdue-labels@example.com');
        $user = User::where('email', 'bean-openai-today-overdue-labels@example.com')->firstOrFail();
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Overdue personal item',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now()->subDay()->setTime(10, 0),
        ]);
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Today personal item',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now()->setTime(10, 0),
        ]);

        Http::fakeSequence()->push([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'response' => 'I’ll check today’s tasks.',
                        'actions' => [[
                            'action' => 'task.list',
                            'arguments' => ['date_scope' => 'today'],
                        ]],
                    ]),
                ],
            ]],
        ], 200);

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'what tasks do I have on my to do list for today?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'You have 2 open tasks due by today: 1 overdue — Overdue personal item; 1 due today — Today personal item.']);

        Http::assertSentCount(1);
    }

    public function test_time_question_answers_with_current_time_not_generic_done(): void
    {
        config(['services.openai.api_key' => null]);
        Carbon::setTestNow(Carbon::parse('2026-07-17 18:42:00', config('app.timezone')));
        $token = $this->apiToken('bean-time-answer@example.com');

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'what time is it?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'The current time is 6:42 PM UTC.']);

        Carbon::setTestNow();
    }

    public function test_date_question_answers_with_date_not_only_time(): void
    {
        config(['services.openai.api_key' => null]);
        Carbon::setTestNow(Carbon::parse('2026-07-17 18:42:00', config('app.timezone')));
        $token = $this->apiToken('bean-date-answer@example.com');

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => "What is today's date?",
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => "Today's date is July 17, 2026."]);

        Carbon::setTestNow();
    }

    public function test_weather_question_answers_with_forecast_facts_not_generic_done(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-weather-answer@example.com');

        Http::fakeSequence()
            ->push(['results' => [[
                'name' => 'Orlando',
                'admin1' => 'Florida',
                'country' => 'United States',
                'latitude' => 28.5383,
                'longitude' => -81.3792,
            ]]], 200)
            ->push([
                'current' => [
                    'temperature_2m' => 82,
                    'apparent_temperature' => 86,
                    'wind_speed_10m' => 5,
                    'precipitation' => 0,
                    'weather_code' => 1,
                ],
                'current_units' => [
                    'temperature_2m' => '°F',
                    'apparent_temperature' => '°F',
                    'wind_speed_10m' => 'mph',
                    'precipitation' => 'inch',
                ],
                'daily' => [
                    'temperature_2m_max' => [91],
                    'temperature_2m_min' => [75],
                    'precipitation_probability_max' => [20],
                ],
                'daily_units' => [
                    'temperature_2m_max' => '°F',
                    'temperature_2m_min' => '°F',
                    'precipitation_probability_max' => '%',
                ],
            ], 200);

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Can you tell me what the weather is like in Orlando right now?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'Right now in Orlando, it’s 82°F and feels like 86°F. Today’s forecast is 91°F high / 75°F low with a 20% precipitation chance.']);
    }

    public function test_today_task_list_collapses_linked_workspace_copies(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-linked-today-list@example.com');
        $user = User::where('email', 'bean-linked-today-list@example.com')->firstOrFail();
        $personal = Workspace::findOrFail($user->default_workspace_id);
        $family = app(WorkspaceService::class)->createHousehold($user, 'Family');
        app(DomainResourceService::class)->createTask($user, [
            'workspace_id' => $personal->id,
            'title' => 'Pay the travel card',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now()->setTime(9, 0)->toIso8601String(),
            'sync_to_workspace_ids' => [$family->id],
        ]);

        $response = $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'what is on my todo list for today?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'You have 1 open task due by today: Pay the travel card.']);

        $assistantContents = collect(data_get($response->json(), 'data.messages', []))
            ->where('role', 'assistant')
            ->pluck('content')
            ->implode(' ');
        $this->assertSame(1, substr_count($assistantContents, 'Pay the travel card'));
    }

    public function test_empty_today_task_list_response_uses_natural_for_today_copy(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-empty-today-task-list-response@example.com');
        $user = User::where('email', 'bean-empty-today-task-list-response@example.com')->firstOrFail();

        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Tomorrow prep',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now()->addDay()->setTime(10, 0),
        ]);

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'what is on my todo list for today?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'You don’t have any open tasks due by today.']);
    }

    public function test_overdue_items_response_lists_overdue_tasks_and_reminders(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-overdue-items-response@example.com');
        $user = User::where('email', 'bean-overdue-items-response@example.com')->firstOrFail();
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Clean outdoor grout',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now()->subDay()->setTime(10, 45),
        ]);
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Tomorrow task',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now()->addDay()->setTime(10, 0),
        ]);
        Reminder::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Fix leak above grill',
            'status' => 'scheduled',
            'remind_at' => now()->subDay()->setTime(17, 45),
        ]);

        $response = $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Do I have any overdue items?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'You have 1 overdue open task: Clean outdoor grout. You have 1 overdue scheduled reminder: Fix leak above grill.']);

        $this->assertStringNotContainsString('Tomorrow task', $response->getContent());
    }

    public function test_ambiguous_task_completion_names_the_possible_matches_without_completing_any(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-ambiguous-task-response@example.com');
        $user = User::where('email', 'bean-ambiguous-task-response@example.com')->firstOrFail();

        $first = Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Call mom',
            'type' => 'todo',
            'status' => 'open',
        ]);
        $second = Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Call dentist',
            'type' => 'todo',
            'status' => 'open',
        ]);

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'complete task call',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'I found multiple matching tasks: Call mom and Call dentist. Which one should I use?']);

        $this->assertDatabaseHas('tasks', ['id' => $first->id, 'status' => 'open']);
        $this->assertDatabaseHas('tasks', ['id' => $second->id, 'status' => 'open']);
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
            $this->assertSame('en', data_get($payload, 'session.audio.input.transcription.language'));
            $this->assertFalse((bool) data_get($payload, 'session.audio.input.turn_detection.create_response'));
            $this->assertStringContainsString('Always speak English', data_get($payload, 'session.instructions'));
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
