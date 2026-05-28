<?php

namespace Tests\Feature;

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
}
