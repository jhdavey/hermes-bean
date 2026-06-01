<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\Task;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HermesToolRuntimeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.hermes_runtime.default_provider', 'openai');
        config()->set('services.hermes_runtime.default_model', 'gpt-test-tools');
        config()->set('services.hermes_runtime.api_key', 'test-key');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
    }

    public function test_tool_runtime_sends_user_prompt_directly_and_exposes_native_tools(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-direct',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I can help with that.',
                    ],
                ]],
            ], 200);

        $token = $this->apiToken('tool-direct@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->assertJsonPath('data.runtime_mode', 'tools')
            ->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'hello bean',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'I can help with that.')
            ->assertJsonFragment(['event_type' => 'runtime.tool_model_started'])
            ->assertJsonFragment(['event_type' => 'runtime.tool_model_completed']);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $context = json_decode(str_replace("Runtime context:\n", '', (string) data_get($payload, 'messages.1.content')), true, flags: JSON_THROW_ON_ERROR);
            $toolNames = collect($payload['tools'] ?? [])->map(fn (array $tool): ?string => data_get($tool, 'function.name'));

            return $request->url() === 'https://api.openai.test/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && data_get($payload, 'messages.0.role') === 'system'
                && data_get($payload, 'messages.1.role') === 'system'
                && data_get($payload, 'messages.2.role') === 'user'
                && data_get($payload, 'messages.2.content') === 'hello bean'
                && ! array_key_exists('dashboard_state', $context)
                && isset($context['temporal_context'], $context['workspace'], $context['agent_profile'])
                && $toolNames->contains('search_tasks')
                && $toolNames->contains('update_task')
                && ! $toolNames->contains('create_approval');
        });
    }

    public function test_tool_runtime_receives_voice_quick_reply_context_for_continuation(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-voice-context',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I would add a protein and something fresh, depending on what you have.',
                    ],
                ]],
            ], 200);

        $token = $this->apiToken('tool-voice-context@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'what should we have for dinner tonight?',
            'metadata' => [
                'client_context' => ['timezone' => 'America/New_York'],
                'voice_context' => [
                    'mode' => 'live_voice',
                    'quick_reply' => 'Got it, a quick easy dinner could be tacos or pasta.',
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $context = json_decode(str_replace("Runtime context:\n", '', (string) data_get($payload, 'messages.1.content')), true, flags: JSON_THROW_ON_ERROR);

            return $request->url() === 'https://api.openai.test/v1/chat/completions'
                && str_contains((string) data_get($payload, 'messages.0.content'), 'already said that sentence aloud')
                && data_get($context, 'voice_context.mode') === 'live_voice'
                && data_get($context, 'voice_context.quick_reply') === 'Got it, a quick easy dinner could be tacos or pasta.'
                && data_get($context, 'temporal_context.client_context.timezone') === 'America/New_York';
        });
    }

    public function test_tool_runtime_sends_recent_conversation_history_for_followups(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-history',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I will check tasks for take out the trash.',
                    ],
                ]],
            ], 200);

        $token = $this->apiToken('tool-history@example.com');
        $user = User::where('email', 'tool-history@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'user',
            'content' => 'what am I supposed to take out the trash',
        ]);
        ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'I do not see a trash reminder.',
        ]);

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'it should be a task not a reminder',
        ])->assertCreated()
            ->assertJsonPath('data.assistant_message.content', 'I will check tasks for take out the trash.');

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $prompt = (string) data_get($payload, 'messages.0.content');

            return str_contains($prompt, 'recent conversation turns')
                && data_get($payload, 'messages.2.role') === 'user'
                && data_get($payload, 'messages.2.content') === 'what am I supposed to take out the trash'
                && data_get($payload, 'messages.3.role') === 'assistant'
                && data_get($payload, 'messages.3.content') === 'I do not see a trash reminder.'
                && data_get($payload, 'messages.4.role') === 'user'
                && data_get($payload, 'messages.4.content') === 'it should be a task not a reminder';
        });
    }

    public function test_tool_runtime_executes_native_task_update_tool_call(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-tool-call',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'update_task',
                                'arguments' => json_encode([
                                    'match_title' => 'Litter Box',
                                    'status' => 'completed',
                                ], JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-final',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Marked Litter Box complete.',
                    ],
                ]],
            ], 200);

        $token = $this->apiToken('tool-update@example.com');
        $user = User::where('email', 'tool-update@example.com')->firstOrFail();
        $workspace = app(WorkspaceService::class)->resolveWorkspace($user);
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $user->id,
            'title' => 'Litter Box',
            'type' => 'todo',
            'status' => 'open',
        ]);

        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'mark litter box complete',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'Marked Litter Box complete.')
            ->assertJsonFragment(['event_type' => 'assistant.task.updated']);

        $this->assertDatabaseHas('tasks', [
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'title' => 'Litter Box',
            'status' => 'completed',
        ]);

        Http::assertSentCount(2);
    }

    public function test_budget_alerts_do_not_block_tool_runtime_request(): void
    {
        config()->set('services.ai_usage.budgets.free.daily_hard_tokens', 1);

        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-direct',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Still handled.',
                    ],
                ]],
            ], 200);

        $token = $this->apiToken('tool-budget@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'hello bean',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'Still handled.');

        Http::assertSentCount(1);
    }

    public function test_tool_runtime_normalizes_object_recurrence_from_agent_tool_calls(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-tool-call',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'call_task',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'create_task',
                                    'arguments' => json_encode([
                                        'title' => 'Take out the trash',
                                        'due_at' => '2026-06-02T09:00:00-04:00',
                                        'recurrence' => [
                                            'type' => 'interval',
                                            'interval' => 3,
                                            'unit' => 'days',
                                        ],
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ],
                            [
                                'id' => 'call_event',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'create_calendar_event',
                                    'arguments' => json_encode([
                                        'title' => 'Family standup',
                                        'starts_at' => '2026-06-02T17:00:00-04:00',
                                        'recurrence' => [
                                            'frequency' => 'weekly',
                                        ],
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ],
                        ],
                    ],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-final',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Added the recurring items.',
                    ],
                ]],
            ], 200);

        $token = $this->apiToken('tool-recurrence@example.com');
        $user = User::where('email', 'tool-recurrence@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'add trash every 3 days and family standup weekly',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonFragment(['event_type' => 'assistant.task.created'])
            ->assertJsonFragment(['event_type' => 'assistant.calendar_event.created']);

        $task = Task::where('user_id', $user->id)->where('title', 'Take out the trash')->firstOrFail();
        $this->assertSame('interval', $task->metadata['recurrence'] ?? null);
        $this->assertSame(3, $task->metadata['interval'] ?? null);
        $this->assertSame('days', $task->metadata['interval_unit'] ?? null);

        $event = CalendarEvent::where('user_id', $user->id)->where('title', 'Family standup')->firstOrFail();
        $this->assertSame('weekly', $event->recurrence);
        $this->assertSame('weekly', $event->metadata['recurrence'] ?? null);
    }

    public function test_successful_tool_execution_returns_fallback_if_final_narration_call_fails(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-tool-call',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'create_task',
                                'arguments' => json_encode([
                                    'title' => 'Buy milk',
                                    'type' => 'todo',
                                ], JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                ]],
            ], 200)
            ->push(['error' => ['message' => 'tool_choice is invalid without tools']], 400);

        $token = $this->apiToken('tool-final-fallback@example.com');
        $user = User::where('email', 'tool-final-fallback@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'add buy milk to my tasks',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'I added Buy milk to your tasks.')
            ->assertJsonFragment(['event_type' => 'assistant.task.created']);

        $this->assertDatabaseHas('tasks', [
            'user_id' => $user->id,
            'title' => 'Buy milk',
        ]);
    }
}
