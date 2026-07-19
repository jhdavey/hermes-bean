<?php

namespace Tests\Feature;

use App\Models\BeanActivityEvent;
use App\Models\BeanRun;
use App\Models\BeanSession;
use App\Models\BeanToolCall;
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
            $this->assertSame('bean_agent_step', data_get($payload, 'response_format.json_schema.name'));
            $actionsEnum = data_get($payload, 'response_format.json_schema.schema.properties.action.enum');
            $argumentsSchema = data_get($payload, 'response_format.json_schema.schema.properties.arguments');
            $this->assertContains('resource.query', $actionsEnum);
            $this->assertContains('resource.relationships', $actionsEnum);
            $this->assertContains('task.create', $actionsEnum);
            $this->assertContains('calendar_event.update', $actionsEnum);
            $this->assertContains('external.lookup', $actionsEnum);
            $this->assertNotContains('recipe.lookup', $actionsEnum);
            $this->assertNotContains('weather.lookup', $actionsEnum);
            $this->assertFalse((bool) ($argumentsSchema['additionalProperties'] ?? true));
            $this->assertContains('title', $argumentsSchema['required'] ?? []);
            $this->assertArrayHasKey('filters', $argumentsSchema['properties'] ?? []);
            $this->assertArrayHasKey('sort', $argumentsSchema['properties'] ?? []);
            $this->assertArrayHasKey('workspace_scope', $argumentsSchema['properties'] ?? []);
            $this->assertArrayNotHasKey('date_scope', $argumentsSchema['properties'] ?? []);

            return Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'final_response' => '',
                            'action' => 'task.create',
                            'arguments' => ['title' => 'from structured model', 'type' => 'todo'],
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
        Http::assertSentCount(2);
    }

    public function test_openai_planner_no_longer_uses_fragment_guard(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.bean_text_model' => 'gpt-4.1-mini',
        ]);
        $token = $this->apiToken('bean-fragment-guard-removed@example.com');

        Http::fake(fn () => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'final_response' => 'I heard you.',
                        'action' => null,
                        'arguments' => [],
                    ]),
                ],
            ]],
        ], 200));

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'spec',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'I heard you.']);

        Http::assertSentCount(1);
    }

    public function test_openai_planner_answers_voice_check_without_clarifying(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.bean_text_model' => 'gpt-4.1-mini',
        ]);
        Http::fake();

        $response = $this->withToken($this->apiToken('bean-voice-check-openai@example.com'))->postJson('/api/bean/messages', [
            'content' => 'can you hear me',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'Yes — I can hear you.']);
        $this->assertDatabaseMissing('bean_tool_calls', ['action' => 'time.now']);
        Http::assertNothingSent();
    }

    public function test_local_parser_answers_voice_check_without_clarifying(): void
    {
        config(['services.openai.api_key' => null]);

        $this->withToken($this->apiToken('bean-voice-check-local@example.com'))->postJson('/api/bean/messages', [
            'content' => 'can you hear me',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'Yes — I can hear you.']);
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

    public function test_limited_note_list_count_question_uses_total_count_not_returned_sample(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.bean_text_model' => 'gpt-4.1-mini',
        ]);
        $token = $this->apiToken('bean-note-total-count@example.com');
        $user = User::where('email', 'bean-note-total-count@example.com')->firstOrFail();
        foreach (range(1, 11) as $number) {
            Note::create([
                'user_id' => $user->id,
                'workspace_id' => $user->default_workspace_id,
                'created_by_user_id' => $user->id,
                'title' => 'Note '.$number,
                'plain_text' => 'Saved note '.$number,
            ]);
        }

        Http::fakeSequence()->push([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'final_response' => '',
                        'action' => 'note.list',
                        'arguments' => ['limit' => 1, 'include_workspaces' => true],
                    ]),
                ],
            ]],
        ], 200);

        $response = $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'how many notes do I have',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'You have 11 notes: Note 1 and 10 more.']);

        $this->assertStringNotContainsString('You have 1 note', $response->getContent());
        $toolCall = BeanToolCall::query()->where('action', 'note.list')->latest('id')->firstOrFail();
        $this->assertSame(11, $toolCall->result['total_count'] ?? null);
        $this->assertSame(1, $toolCall->result['returned_count'] ?? null);
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
            ->assertJsonFragment(['content' => "Pay the travel card is on today's list because it is overdue and open."]);

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
                            'final_response' => '',
                            'action' => 'resource.query',
                            'arguments' => ['resource' => 'tasks', 'query' => 'Pay the travel card', 'include_workspaces' => true],
                        ]),
                    ],
                ]],
            ], 200)
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'final_response' => 'Pay the travel card is in your personal workspace.',
                            'action' => null,
                            'arguments' => [],
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
                        'final_response' => 'Pay the travel card is in your personal workspace.',
                        'action' => null,
                        'arguments' => [],
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

    public function test_evergreen_generation_uses_model_knowledge_without_external_lookup(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.bean_text_model' => 'gpt-4.1-mini',
        ]);
        $token = $this->apiToken('bean-evergreen-generation@example.com');

        Http::fake(fn () => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'final_response' => 'Here’s a simple evergreen answer from model knowledge.',
                        'action' => null,
                        'arguments' => [],
                    ]),
                ],
            ]],
        ], 200));

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Give me a simple chicken quesadilla recipe.',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'Here’s a simple evergreen answer from model knowledge.']);

        $this->assertDatabaseMissing('bean_tool_calls', ['action' => 'external.lookup']);
        $this->assertDatabaseMissing('bean_tool_calls', ['action' => 'recipe.lookup']);
        $this->assertDatabaseMissing('bean_tool_calls', ['action' => 'weather.lookup']);
    }

    public function test_source_backed_public_request_uses_general_external_lookup(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-external-lookup@example.com');
        $user = User::where('email', 'bean-external-lookup@example.com')->firstOrFail();
        Note::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Private maintenance note',
            'plain_text' => 'Old private note should not be treated as a web result.',
        ]);

        Http::fake([
            'api.duckduckgo.com/*' => Http::response([
                'Heading' => 'Fire extinguisher maintenance',
                'AbstractText' => 'Fire extinguishers should be inspected monthly and serviced according to the manufacturer instructions.',
                'AbstractURL' => 'https://example.test/fire-extinguisher',
                'RelatedTopics' => [[
                    'Text' => 'Check the pressure gauge and instructions before relying on an extinguisher.',
                    'FirstURL' => 'https://example.test/extinguisher-check',
                ]],
            ], 200),
            'https://example.test/*' => Http::response('<html><title>Fire Extinguisher Maintenance</title><body>Fire extinguishers should be inspected monthly. Check the pressure gauge.</body></html>', 200, ['content-type' => 'text/html']),
        ]);

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Can you go online and find sources for home fire extinguisher maintenance?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'I found 2 sources: Fire extinguishers should be inspected monthly and serviced according to the manufacturer instructions. Check the pressure gauge and instructions before relying on an extinguisher. Source: https://example.test/fire-extinguisher']);

        $this->assertDatabaseHas('bean_tool_calls', ['action' => 'external.lookup', 'status' => 'completed']);
        $this->assertDatabaseMissing('bean_tool_calls', ['action' => 'recipe.lookup']);
        $this->assertDatabaseMissing('bean_tool_calls', ['action' => 'weather.lookup']);
        $this->assertDatabaseMissing('bean_tool_calls', ['action' => 'note.search']);

        $toolCall = BeanToolCall::query()->where('action', 'external.lookup')->latest('id')->firstOrFail();
        $this->assertSame('medium', data_get($toolCall->result, 'confidence'));
        $this->assertSame(2, data_get($toolCall->result, 'evidence.source_count'));
    }

    public function test_source_backed_lookup_can_create_grounded_note_without_domain_specific_tool(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-grounded-note@example.com');
        $user = User::where('email', 'bean-grounded-note@example.com')->firstOrFail();

        Http::fake([
            'api.duckduckgo.com/*' => Http::response([
                'Heading' => 'Emergency kit supplies',
                'AbstractText' => 'Emergency kits commonly include water, food, first aid supplies, flashlight, radio, and batteries.',
                'AbstractURL' => 'https://example.test/emergency-kit',
                'RelatedTopics' => [[
                    'Text' => 'Emergency preparedness source recommends supplies for at least several days.',
                    'FirstURL' => 'https://example.test/preparedness',
                ]],
            ], 200),
            'https://example.test/*' => Http::response('<html><title>Emergency Kit Supplies</title><body>Emergency kits commonly include water, food, first aid, flashlight, radio, and batteries.</body></html>', 200, ['content-type' => 'text/html']),
        ]);

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Go online, find sources for emergency kit supplies, and save a note.',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'I created a source-grounded note: Emergency Kit Supplies.']);

        $note = Note::where('user_id', $user->id)->where('title', 'Emergency Kit Supplies')->firstOrFail();
        $this->assertStringContainsString('Summary:', $note->plain_text);
        $this->assertStringContainsString('Sources:', $note->plain_text);
        $this->assertSame('external.lookup', data_get($note->metadata, 'grounded_from'));
        $this->assertDatabaseHas('bean_tool_calls', ['action' => 'external.lookup', 'status' => 'completed']);
        $this->assertDatabaseHas('bean_tool_calls', ['action' => 'note.create', 'status' => 'completed']);
        $this->assertDatabaseMissing('bean_tool_calls', ['action' => 'recipe.lookup']);
    }

    public function test_substantive_recipe_note_creation_uses_grounded_lookup_and_saves_portioned_source_content(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-grounded-recipe-note@example.com');
        $user = User::where('email', 'bean-grounded-recipe-note@example.com')->firstOrFail();

        Http::fake([
            'api.duckduckgo.com/*' => Http::response(['Heading' => '', 'AbstractText' => '', 'RelatedTopics' => []], 200),
            'search.brave.com/*' => Http::response($this->duckDuckGoLiteHtml('Smoked Trout Dip - Real Source', 'https://example.test/smoked-trout-dip', 'Smoked trout dip with servings, ingredients, and instructions.'), 200),
            'www.bing.com/*' => Http::response($this->duckDuckGoLiteHtml('Smoked Trout Dip - Real Source', 'https://example.test/smoked-trout-dip', 'Smoked trout dip with servings, ingredients, and instructions.'), 200),
            'lite.duckduckgo.com/*' => Http::response($this->duckDuckGoLiteHtml('Smoked Trout Dip - Real Source', 'https://example.test/smoked-trout-dip', 'Smoked trout dip with servings, ingredients, and instructions.'), 200),
            'https://example.test/smoked-trout-dip' => Http::response($this->recipeJsonLdHtml(), 200, ['content-type' => 'text/html']),
        ]);

        $response = $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Create a note with a recipe for smoked trout dip.',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'I created a source-grounded note: Smoked Trout Dip - Real Source.']);

        $note = Note::where('user_id', $user->id)->where('title', 'Smoked Trout Dip - Real Source')->firstOrFail();
        $this->assertStringContainsString('Summary:', $note->plain_text);
        $this->assertStringContainsString('Smoked Trout Dip', $note->plain_text);
        $this->assertStringContainsString('Sources:', $note->plain_text);
        $this->assertStringContainsString('https://example.test/smoked-trout-dip', $note->plain_text);
        $this->assertSame('external.lookup', data_get($note->metadata, 'grounded_from'));
        $this->assertDatabaseHas('bean_tool_calls', ['action' => 'external.lookup', 'status' => 'completed']);
        $this->assertDatabaseHas('bean_tool_calls', ['action' => 'note.create', 'status' => 'completed']);
        $this->assertDatabaseMissing('bean_tool_calls', ['action' => 'recipe.lookup']);

        $events = BeanActivityEvent::query()
            ->where('user_id', $user->id)
            ->whereIn('type', ['tool_started', 'tool_completed'])
            ->orderBy('id')
            ->get();
        $this->assertSame('Working: external.lookup', $events->firstWhere('type', 'tool_started')?->label);
        $this->assertTrue($events->contains(fn (BeanActivityEvent $event): bool => $event->label === 'Working: note.create'));
        $externalCompleted = $events->first(fn (BeanActivityEvent $event): bool => $event->type === 'tool_completed' && data_get($event->payload, 'action') === 'external.lookup');
        $this->assertNotNull($externalCompleted);
        $this->assertStringContainsString('Done: external.lookup', (string) $externalCompleted->label);
        $this->assertSame('Working: external.lookup', data_get($events->firstWhere('type', 'tool_started')?->payload, 'progress.status_text'));
        $this->assertSame('brave_html', data_get($externalCompleted?->payload, 'progress.details.provider'));
        $this->assertSame(1, data_get($externalCompleted?->payload, 'progress.details.source_count'));

        $run = BeanRun::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame('note.create', data_get($run->metadata, 'progress.action'));
        $this->assertSame('completed', data_get($run->metadata, 'progress.status'));
        $this->assertSame(['external.lookup', 'external.lookup', 'note.create', 'note.create'], collect(data_get($run->metadata, 'progress_history'))->pluck('action')->all());

        $this->withToken($token)->getJson('/api/bean/runs/'.data_get($response->json(), 'data.run.id'))
            ->assertOk()
            ->assertJsonPath('data.progress.action', 'note.create')
            ->assertJsonPath('data.progress.status', 'completed')
            ->assertJsonPath('data.progress_history.0.status_text', 'Working: external.lookup');
    }

    public function test_openai_model_can_create_note_content_without_backend_artifact_rewrite(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.bean_text_model' => 'gpt-4.1-mini',
        ]);
        $token = $this->apiToken('bean-openai-direct-note@example.com');
        $user = User::where('email', 'bean-openai-direct-note@example.com')->firstOrFail();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'final_response' => '',
                            'action' => 'note.create',
                            'arguments' => [
                                'title' => 'Smoked Trout Dip Recipe',
                                'plain_text' => 'Ingredients: smoked trout, cream cheese. Instructions: mix and serve.',
                            ],
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Create a note with a recipe for smoked trout dip from your own knowledge.',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed');

        $note = Note::where('user_id', $user->id)->where('title', 'Smoked Trout Dip Recipe')->firstOrFail();
        $this->assertStringContainsString('Ingredients: smoked trout, cream cheese.', $note->plain_text);
        $this->assertNull(data_get($note->metadata, 'grounded_from'));

        $actions = BeanToolCall::query()->whereHas('run', fn ($query) => $query->where('user_id', $user->id))->pluck('action')->all();
        $this->assertSame(['note.create'], $actions);
    }

    private function duckDuckGoLiteHtml(string $title, string $url, string $snippet): string
    {
        $encodedUrl = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5);
        $encodedTitle = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5);
        $encodedSnippet = htmlspecialchars($snippet, ENT_QUOTES | ENT_HTML5);

        return <<<HTML
<html><body>
<a rel="nofollow" href="{$encodedUrl}" class="result-link">{$encodedTitle}</a>
<table><tr><td class="result-snippet">{$encodedSnippet}</td></tr></table>
</body></html>
HTML;
    }

    private function recipeJsonLdHtml(): string
    {
        $json = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Recipe',
            'name' => 'Smoked Trout Dip',
            'recipeYield' => ['6'],
            'prepTime' => 'PT5M',
            'totalTime' => 'PT5M',
            'recipeIngredient' => [
                '8 oz smoked trout fillets',
                '8 oz cream cheese',
                '1 tbsp capers',
                '1 tsp dried dill weed',
                '1 lemon, zested and juiced',
            ],
            'recipeInstructions' => [
                ['@type' => 'HowToStep', 'text' => 'Bring the cream cheese to room temperature.'],
                ['@type' => 'HowToStep', 'text' => 'Pulse cream cheese and smoked trout until combined.'],
                ['@type' => 'HowToStep', 'text' => 'Fold in capers, dill, lemon zest, and lemon juice.'],
            ],
        ], JSON_UNESCAPED_SLASHES);

        return <<<HTML
<html><head><title>Smoked Trout Dip</title><script type="application/ld+json">{$json}</script></head><body>Smoked trout dip recipe.</body></html>
HTML;
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
                        'final_response' => '',
                        'action' => 'task.list',
                        'arguments' => [
                            'filters' => [[
                                'field' => 'due_at',
                                'operator' => '<=',
                                'value' => now()->endOfDay()->toIso8601String(),
                            ]],
                            'time_label' => 'today',
                            'workspace_scope' => 'accessible',
                        ],
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

    public function test_date_only_task_filter_matches_tasks_due_any_time_that_day(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.bean_text_model' => 'gpt-4.1-mini',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-19 18:42:00', config('app.timezone')));
        $token = $this->apiToken('bean-tuesday-date-filter@example.com');
        $user = User::where('email', 'bean-tuesday-date-filter@example.com')->firstOrFail();

        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Take out trash',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => Carbon::parse('2026-07-21 23:45:00', config('app.timezone')),
            'metadata' => ['recurrence' => 'weekly'],
        ]);

        Http::fakeSequence()->push([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'final_response' => '',
                        'action' => 'task.list',
                        'arguments' => [
                            'filters' => [[
                                'field' => 'due_at',
                                'operator' => '=',
                                'value' => '2026-07-21',
                            ]],
                            'workspace_scope' => 'accessible',
                            'include_workspaces' => true,
                        ],
                    ]),
                ],
            ]],
        ], 200);

        $response = $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => "what's on my to-do list for Tuesday",
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed');

        $this->assertStringContainsString('Take out trash', $response->getContent());
        $this->assertDatabaseHas('bean_tool_calls', ['action' => 'task.list', 'status' => 'completed']);
        $this->assertSame(0, Reminder::where('user_id', $user->id)->count());
        Carbon::setTestNow();
    }

    public function test_date_only_task_filter_uses_client_timezone_day_boundaries(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.bean_text_model' => 'gpt-4.1-mini',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-19 18:42:00', 'UTC'));
        $token = $this->apiToken('bean-tuesday-client-timezone@example.com');
        $user = User::where('email', 'bean-tuesday-client-timezone@example.com')->firstOrFail();

        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Payroll',
            'type' => 'todo',
            'status' => 'open',
            // Monday 8:30 PM in America/Chicago, despite being Tuesday in UTC.
            'due_at' => Carbon::parse('2026-07-21 01:30:00', 'UTC'),
            'metadata' => ['recurrence' => 'weekly'],
        ]);
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Take out trash',
            'type' => 'todo',
            'status' => 'open',
            // Tuesday 6:45 PM in America/Chicago.
            'due_at' => Carbon::parse('2026-07-21 23:45:00', 'UTC'),
            'metadata' => ['recurrence' => 'weekly'],
        ]);

        Http::fakeSequence()->push([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'final_response' => '',
                        'action' => 'task.list',
                        'arguments' => [
                            'filters' => [[
                                'field' => 'due_at',
                                'operator' => '=',
                                'value' => '2026-07-21',
                            ]],
                            'workspace_scope' => 'accessible',
                            'include_workspaces' => true,
                        ],
                    ]),
                ],
            ]],
        ], 200);

        $response = $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => "what's on my to-do list for Tuesday",
            'client_timezone' => 'America/Chicago',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed');

        $this->assertStringContainsString('Take out trash', $response->getContent());
        $this->assertStringNotContainsString('Payroll', $response->getContent());
        $response->assertJsonPath('data.run.metadata.time_context.timezone', 'America/Chicago')
            ->assertJsonPath('data.run.metadata.time_context.local_date', '2026-07-19');

        $toolCall = BeanToolCall::query()->where('user_id', $user->id)->where('action', 'task.list')->latest('id')->firstOrFail();
        $this->assertSame('America/Chicago', data_get($toolCall->result, 'time_context.timezone'));
        $this->assertSame([
            '2026-07-21T05:00:00+00:00',
            '2026-07-22T04:59:59+00:00',
        ], data_get($toolCall->arguments, 'filters.0.value'));
        Carbon::setTestNow();
    }

    public function test_correction_after_task_list_question_does_not_create_reminder(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.bean_text_model' => 'gpt-4.1-mini',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-19 18:42:00', config('app.timezone')));
        $token = $this->apiToken('bean-tuesday-correction-no-reminder@example.com');
        $user = User::where('email', 'bean-tuesday-correction-no-reminder@example.com')->firstOrFail();
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Take out trash',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => Carbon::parse('2026-07-21 23:45:00', config('app.timezone')),
            'metadata' => ['recurrence' => 'weekly'],
        ]);

        Http::fakeSequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'final_response' => '',
                            'action' => 'task.list',
                            'arguments' => [
                                'filters' => [[
                                    'field' => 'due_at',
                                    'operator' => '=',
                                    'value' => '2026-07-21',
                                ]],
                                'workspace_scope' => 'accessible',
                            ],
                        ]),
                    ],
                ]],
            ], 200)
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'final_response' => 'You have 1 open task Tuesday: Take out trash.',
                            'action' => null,
                            'arguments' => [],
                        ]),
                    ],
                ]],
            ], 200)
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'final_response' => '',
                            'action' => 'reminder.create',
                            'arguments' => ['title' => 'Take out the trash'],
                        ]),
                    ],
                ]],
            ], 200);

        $first = $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => "what's on my to-do list for Tuesday",
        ])->assertOk();
        $sessionId = data_get($first->json(), 'data.session.id');

        $response = $this->withToken($token)->postJson('/api/bean/messages', [
            'session_id' => $sessionId,
            'content' => 'Yes, I do. I have to take out the trash.',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed');

        $this->assertStringContainsString('Take out trash', $response->getContent());
        $this->assertDatabaseMissing('bean_tool_calls', ['action' => 'reminder.create']);
        $this->assertDatabaseHas('bean_tool_calls', ['action' => 'resource.query', 'status' => 'completed']);
        $this->assertSame(0, Reminder::where('user_id', $user->id)->count());
        Carbon::setTestNow();
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

    public function test_tomorrow_calendar_question_filters_to_only_tomorrow_events(): void
    {
        config(['services.openai.api_key' => null]);
        Carbon::setTestNow(Carbon::parse('2026-07-17 18:42:00', config('app.timezone')));
        $token = $this->apiToken('bean-calendar-tomorrow@example.com');
        $user = User::where('email', 'bean-calendar-tomorrow@example.com')->firstOrFail();

        foreach ([
            ['Yesterday demo', now()->subDay()->setTime(14, 0)],
            ['Today standup', now()->setTime(10, 0)],
            ['Tomorrow dentist', now()->addDay()->setTime(9, 0)],
            ['Tomorrow dinner', now()->addDay()->setTime(19, 0)],
            ['Next week planning', now()->addWeek()->setTime(12, 0)],
        ] as [$title, $startsAt]) {
            CalendarEvent::create([
                'user_id' => $user->id,
                'workspace_id' => $user->default_workspace_id,
                'created_by_user_id' => $user->id,
                'title' => $title,
                'status' => 'scheduled',
                'starts_at' => $startsAt,
                'ends_at' => (clone $startsAt)->addHour(),
                'recurrence' => 'none',
            ]);
        }

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Do I have anything on my calendar for tomorrow?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'You have 2 calendar events tomorrow: Tomorrow dentist and Tomorrow dinner.'])
            ->assertJsonMissing(['content' => 'Today standup'])
            ->assertJsonMissing(['content' => 'Next week planning']);

        $this->assertDatabaseHas('bean_tool_calls', [
            'action' => 'calendar_event.list',
            'status' => 'completed',
        ]);
        $toolCall = BeanToolCall::query()->where('action', 'calendar_event.list')->latest('id')->firstOrFail();
        $filters = collect($toolCall->arguments['filters'] ?? []);
        $this->assertTrue($filters->contains(fn (array $filter): bool => ($filter['field'] ?? null) === 'starts_at' && ($filter['operator'] ?? null) === 'between'));
        $this->assertArrayNotHasKey('date_scope', $toolCall->arguments ?? []);
    }

    public function test_openai_planner_calendar_query_uses_generic_filters_not_date_scope(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.bean_text_model' => 'gpt-4.1-mini',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-17 18:42:00', config('app.timezone')));
        $token = $this->apiToken('bean-calendar-generic-filter@example.com');
        $user = User::where('email', 'bean-calendar-generic-filter@example.com')->firstOrFail();

        foreach ([
            ['Today shared event', now()->setTime(10, 0)],
            ['Tomorrow only event', now()->addDay()->setTime(9, 0)],
            ['Future shared event', now()->addDays(5)->setTime(14, 0)],
        ] as [$title, $startsAt]) {
            CalendarEvent::create([
                'user_id' => $user->id,
                'workspace_id' => $user->default_workspace_id,
                'created_by_user_id' => $user->id,
                'title' => $title,
                'status' => 'scheduled',
                'starts_at' => $startsAt,
                'ends_at' => (clone $startsAt)->addHour(),
                'recurrence' => 'none',
            ]);
        }

        Http::fakeSequence()->push([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'final_response' => '',
                        'action' => 'calendar_event.list',
                        'arguments' => [
                            'filters' => [[
                                'field' => 'starts_at',
                                'operator' => 'between',
                                'value' => [now()->addDay()->startOfDay()->toIso8601String(), now()->addDay()->endOfDay()->toIso8601String()],
                            ]],
                            'time_label' => 'tomorrow',
                            'workspace_scope' => 'accessible',
                            'sort' => [['field' => 'starts_at', 'direction' => 'asc']],
                        ],
                    ]),
                ],
            ]],
        ], 200);

        $response = $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Do I have anything on my calendar for tomorrow?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'You have 1 calendar event tomorrow: Tomorrow only event.']);

        $this->assertStringNotContainsString('Today shared event', $response->getContent());
        $this->assertStringNotContainsString('Future shared event', $response->getContent());
        $this->assertDatabaseHas('bean_tool_calls', ['action' => 'calendar_event.list', 'status' => 'completed']);
        Carbon::setTestNow();
    }

    public function test_openai_calendar_list_normalizes_top_level_time_bounds_to_filters(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.bean_text_model' => 'gpt-4.1-mini',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-18 19:59:41', config('app.timezone')));
        $token = $this->apiToken('bean-calendar-top-level-bounds@example.com');
        $user = User::where('email', 'bean-calendar-top-level-bounds@example.com')->firstOrFail();

        foreach ([
            ['Past LACMA event', Carbon::parse('2026-05-22 12:00:00', config('app.timezone'))],
            ['Tomorrow team call', Carbon::parse('2026-07-19 09:00:00', config('app.timezone'))],
            ['Future dentist cleaning', Carbon::parse('2026-10-13 18:00:00', config('app.timezone'))],
        ] as [$title, $startsAt]) {
            CalendarEvent::create([
                'user_id' => $user->id,
                'workspace_id' => $user->default_workspace_id,
                'created_by_user_id' => $user->id,
                'title' => $title,
                'status' => 'scheduled',
                'starts_at' => $startsAt,
                'ends_at' => (clone $startsAt)->addHour(),
                'recurrence' => 'none',
            ]);
        }

        Http::fakeSequence()->push([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'final_response' => '',
                        'action' => 'calendar_event.list',
                        'arguments' => [
                            'starts_at' => '2026-07-19T00:00:00+00:00',
                            'ends_at' => '2026-07-19T23:59:59+00:00',
                            'workspace_id' => $user->default_workspace_id,
                        ],
                    ]),
                ],
            ]],
        ], 200);

        $response = $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Do I have any events for tomorrow on the calendar?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'You have 1 calendar event tomorrow: Tomorrow team call.']);

        $this->assertStringNotContainsString('Past LACMA event', $response->getContent());
        $this->assertStringNotContainsString('Future dentist cleaning', $response->getContent());
        $toolCall = BeanToolCall::query()->where('action', 'calendar_event.list')->latest('id')->firstOrFail();
        $this->assertSame('tomorrow', $toolCall->arguments['time_label'] ?? null);
        $this->assertTrue(collect($toolCall->arguments['filters'] ?? [])->contains(
            fn (array $filter): bool => ($filter['field'] ?? null) === 'starts_at' && ($filter['operator'] ?? null) === 'between'
        ));
        Carbon::setTestNow();
    }

    public function test_follow_up_temporal_calendar_question_reuses_previous_calendar_context(): void
    {
        config(['services.openai.api_key' => null]);
        Carbon::setTestNow(Carbon::parse('2026-07-17 18:42:00', config('app.timezone')));
        $token = $this->apiToken('bean-calendar-follow-up@example.com');
        $user = User::where('email', 'bean-calendar-follow-up@example.com')->firstOrFail();

        foreach ([
            ['Today standup', now()->setTime(10, 0)],
            ['Tomorrow dentist', now()->addDay()->setTime(9, 0)],
        ] as [$title, $startsAt]) {
            CalendarEvent::create([
                'user_id' => $user->id,
                'workspace_id' => $user->default_workspace_id,
                'created_by_user_id' => $user->id,
                'title' => $title,
                'status' => 'scheduled',
                'starts_at' => $startsAt,
                'ends_at' => (clone $startsAt)->addHour(),
                'recurrence' => 'none',
            ]);
        }

        $first = $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => "what's on my calendar for today",
        ])->assertOk()
            ->assertJsonFragment(['content' => 'You have 1 calendar event for today: Today standup.']);

        $sessionId = $first->json('data.session.id');
        $this->withToken($token)->postJson('/api/bean/messages', [
            'session_id' => $sessionId,
            'content' => 'What about for tomorrow?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'You have 1 calendar event tomorrow: Tomorrow dentist.']);

        $lastToolCall = BeanToolCall::query()->where('action', 'calendar_event.list')->latest('id')->firstOrFail();
        $this->assertSame('tomorrow', $lastToolCall->arguments['time_label'] ?? null);
        $this->assertArrayNotHasKey('date_scope', $lastToolCall->arguments ?? []);
        Carbon::setTestNow();
    }

    public function test_planner_time_tool_does_not_override_app_data_results_and_temporal_filters_are_canonicalized(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.bean_text_model' => 'gpt-4.1-mini',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-17 18:42:00', config('app.timezone')));
        $token = $this->apiToken('bean-calendar-time-tool-noise@example.com');
        $user = User::where('email', 'bean-calendar-time-tool-noise@example.com')->firstOrFail();

        CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Tomorrow dentist',
            'status' => 'scheduled',
            'starts_at' => now()->addDay()->setTime(9, 0),
            'ends_at' => now()->addDay()->setTime(10, 0),
            'recurrence' => 'none',
        ]);

        Http::fakeSequence()->push([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'final_response' => '',
                        'action' => 'time.now',
                        'arguments' => [],
                    ]),
                ],
            ]],
        ], 200);

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Do I have any events for tomorrow?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'You have 1 calendar event tomorrow: Tomorrow dentist.']);

        $this->assertDatabaseMissing('bean_tool_calls', ['action' => 'time.now']);
        $toolCall = BeanToolCall::query()->where('action', 'calendar_event.list')->latest('id')->firstOrFail();
        $range = collect($toolCall->arguments['filters'] ?? [])->firstWhere('field', 'starts_at')['value'] ?? [];
        $this->assertStringStartsWith('2026-07-18T00:00:00', $range[0] ?? '');
        $this->assertStringStartsWith('2026-07-18T23:59:59', $range[1] ?? '');
        Carbon::setTestNow();
    }

    public function test_weather_question_uses_generic_external_lookup_not_weather_specific_action(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-weather-answer@example.com');

        Http::fake([
            'api.duckduckgo.com/*' => Http::response([
                'Heading' => 'Orlando weather',
                'AbstractText' => 'The current weather in Orlando is 82°F and partly cloudy with a forecast high near 91°F.',
                'AbstractURL' => 'https://example.test/orlando-weather',
                'RelatedTopics' => [[
                    'Text' => 'Orlando forecast source reports warm conditions and a chance of rain.',
                    'FirstURL' => 'https://example.test/orlando-forecast',
                ]],
            ], 200),
            'https://example.test/*' => Http::response('<html><title>Orlando weather</title><body>The current weather in Orlando is 82°F and partly cloudy.</body></html>', 200, ['content-type' => 'text/html']),
        ]);

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Can you tell me what the weather is like in Orlando right now?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'I found 2 sources: The current weather in Orlando is 82°F and partly cloudy with a forecast high near 91°F. Orlando forecast source reports warm conditions and a chance of rain. Source: https://example.test/orlando-weather']);

        $this->assertDatabaseHas('bean_tool_calls', ['action' => 'external.lookup', 'status' => 'completed']);
        $this->assertDatabaseMissing('bean_tool_calls', ['action' => 'weather.lookup']);
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

    public function test_openai_overdue_task_query_normalizes_incomplete_alias_to_canonical_open_status(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.bean_text_model' => 'gpt-4.1-mini',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-18 14:17:24', config('app.timezone')));
        $token = $this->apiToken('bean-openai-overdue-invalid-status@example.com');
        $user = User::where('email', 'bean-openai-overdue-invalid-status@example.com')->firstOrFail();

        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Clean outdoor grout',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now()->subDays(7)->setTime(14, 45),
        ]);
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Fix leak above grill',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now()->subDays(3)->setTime(21, 45),
        ]);
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Completed old payroll',
            'type' => 'todo',
            'status' => 'completed',
            'due_at' => now()->subMonth()->setTime(13, 0),
        ]);

        Http::fake(fn () => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'final_response' => '',
                        'action' => 'task.list',
                        'arguments' => [
                            'status' => 'incomplete',
                            'filters' => [[
                                'field' => 'due_at',
                                'operator' => '<',
                                'value' => '2026-07-18T14:17:24+00:00',
                            ]],
                            'workspace_id' => $user->default_workspace_id,
                            'include_workspaces' => true,
                        ],
                    ]),
                ],
            ]],
        ], 200));

        $response = $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Do I have any overdue tasks?',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonFragment(['content' => 'You have 2 overdue open tasks: Clean outdoor grout and Fix leak above grill.']);

        $this->assertStringNotContainsString('Completed old payroll', $response->getContent());
        $toolCall = BeanToolCall::query()->where('action', 'task.list')->latest('id')->firstOrFail();
        $this->assertSame('overdue', $toolCall->arguments['time_label'] ?? null);
        $this->assertSame('open', $toolCall->arguments['status'] ?? null);
        $this->assertFalse(collect($toolCall->arguments['filters'] ?? [])->contains(fn (array $filter): bool => ($filter['field'] ?? null) === 'completed_at'));
        Carbon::setTestNow();
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

    public function test_yes_message_approves_pending_confirmation_in_same_session(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-yes-confirmation@example.com');
        $user = User::where('email', 'bean-yes-confirmation@example.com')->firstOrFail();
        $task = Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Delete by voice yes',
            'type' => 'todo',
            'status' => 'open',
        ]);

        $delete = $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'Delete task Delete by voice yes',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'waiting_confirmation');

        $sessionId = $delete->json('data.session.id');
        $this->withToken($token)->postJson('/api/bean/messages', [
            'session_id' => $sessionId,
            'content' => 'yes',
        ])->assertOk()
            ->assertJsonPath('data.result.ok', true)
            ->assertJsonPath('data.confirmation.status', 'approved');

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        $this->assertDatabaseMissing('bean_confirmation_requests', ['action' => 'task.delete', 'status' => 'pending']);
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
