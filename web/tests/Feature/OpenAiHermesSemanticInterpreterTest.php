<?php

namespace Tests\Feature;

use App\Data\HermesSemanticCompositionRequest;
use App\Data\HermesSemanticInterpretation;
use App\Data\HermesSemanticInterpretationRequest;
use App\Data\HermesSemanticOperation;
use App\Data\HermesSemanticOperationResult;
use App\Exceptions\HermesSemanticProviderException;
use App\Exceptions\HermesSemanticUsageLimitException;
use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\AiUsageService;
use App\Services\HermesSemanticInterpreter;
use App\Services\HermesSemanticProtocol;
use App\Services\OpenAiHermesSemanticInterpreter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class OpenAiHermesSemanticInterpreterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.hermes_runtime.api_key', 'semantic-test-key');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
        config()->set('services.hermes_runtime.semantic_interpretation_model', 'gpt-test-semantic');
        config()->set('services.hermes_runtime.semantic_reasoning_effort', 'none');
        config()->set('services.hermes_runtime.semantic_interpretation_timeout', 2);
        config()->set('services.hermes_runtime.semantic_interpretation_connect_timeout', 0.5);
        config()->set('services.hermes_runtime.semantic_interpretation_reserved_output_tokens', 800);
        config()->set('services.hermes_runtime.semantic_composition_reserved_output_tokens', 300);
        config()->set('services.ai_usage.pricing_per_million.gpt-test-semantic', [
            'input' => 0.05,
            'output' => 0.40,
        ]);

        Http::preventStrayRequests();
    }

    public function test_container_resolves_the_openai_semantic_interpreter(): void
    {
        $this->assertInstanceOf(
            OpenAiHermesSemanticInterpreter::class,
            $this->app->make(HermesSemanticInterpreter::class),
        );
    }

    public function test_interpret_uses_strict_schema_and_returns_ordered_typed_operations(): void
    {
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response($this->providerResponse([
                'outcome' => 'execute',
                'response_text' => null,
                'clarification_question' => null,
                'acknowledgement_text' => 'I’ll move that task.',
                'close_after_response' => false,
                'response_expected' => false,
                'operations' => [
                    [
                        'id' => 'find_task',
                        'tool' => 'app.task.search',
                        'arguments_json' => json_encode([
                            'query' => 'Plan the launch',
                            'match_mode' => 'exact_title',
                            'require_unique' => true,
                        ]),
                        'dependencies' => [],
                    ],
                    [
                        'id' => 'move_task',
                        'tool' => 'app.task.update',
                        'arguments_json' => json_encode([
                            'result_ref' => ['operation_id' => 'find_task', 'path' => 'unique_id'],
                            'due_at' => '2026-07-15T15:00:00-04:00',
                        ]),
                        'dependencies' => ['find_task'],
                    ],
                ],
            ]), 200),
        ]);

        $request = $this->interpretationRequest();
        $service = $this->app->make(HermesSemanticInterpreter::class);

        $first = $service->interpret($request);
        $second = $service->interpret($request);

        $this->assertSame(HermesSemanticInterpretation::OUTCOME_EXECUTE, $first->outcome);
        $this->assertFalse($first->closeAfterResponse);
        $this->assertFalse($first->responseExpected);
        $this->assertCount(2, $first->operations);
        $this->assertSame('app.task.update', $first->operations[1]->tool);
        $this->assertSame('unique_id', $first->operations[1]->arguments['result_ref']['path']);
        $this->assertSame(['find_task'], $first->operations[1]->dependencies);
        $this->assertEquals($first, $second);

        Http::assertSentCount(2);
        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $schema = data_get($payload, 'response_format.json_schema.schema');
            $systemMessage = collect($payload['messages'] ?? [])->firstWhere('role', 'system');
            $userMessage = collect($payload['messages'] ?? [])->firstWhere('role', 'user');
            $input = json_decode((string) ($userMessage['content'] ?? ''), true, flags: JSON_THROW_ON_ERROR);

            return $request->url() === 'https://api.openai.test/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer semantic-test-key')
                && ($payload['model'] ?? null) === 'gpt-test-semantic'
                && ($payload['reasoning_effort'] ?? null) === 'none'
                && ($payload['max_completion_tokens'] ?? null) === 800
                && data_get($payload, 'response_format.type') === 'json_schema'
                && data_get($payload, 'response_format.json_schema.name') === 'bean_semantic_interpretation_v2'
                && data_get($payload, 'response_format.json_schema.strict') === true
                && data_get($schema, 'additionalProperties') === false
                && data_get($schema, 'properties.operations.items.additionalProperties') === false
                && data_get($schema, 'properties.operations.items.properties.tool.enum') === HermesSemanticOperation::TOOLS
                && str_contains(
                    (string) data_get($schema, 'properties.operations.items.properties.arguments_json.description'),
                    'documented incidental application defaults may be omitted',
                )
                && data_get($schema, 'properties.close_after_response.type') === 'boolean'
                && data_get($schema, 'properties.response_expected.type') === 'boolean'
                && str_contains((string) ($systemMessage['content'] ?? ''), 'never both')
                && str_contains((string) ($systemMessage['content'] ?? ''), 'match_mode "exact_title"')
                && str_contains((string) ($systemMessage['content'] ?? ''), 'Never select items.N')
                && str_contains((string) ($systemMessage['content'] ?? ''), 'trusted_location is semantic input only')
                && str_contains((string) ($systemMessage['content'] ?? ''), 'explicitly set topic to general, news, or finance')
                && str_contains((string) ($systemMessage['content'] ?? ''), 'never use domain, intent, weather_location')
                && str_contains((string) ($systemMessage['content'] ?? ''), 'Supply only fields grounded in the user\'s request and trusted context')
                && str_contains((string) ($systemMessage['content'] ?? ''), 'changes only the profile display_name')
                && str_contains((string) ($systemMessage['content'] ?? ''), 'Do not ask about an incidental default')
                && ($input['current_time'] ?? null) === '2026-07-14T10:30:00-04:00'
                && ($input['timezone'] ?? null) === 'America/New_York'
                && data_get($input, 'context.recent_resources.tasks.0.id') === 42;
        });

        $this->assertDatabaseCount('ai_usage_logs', 1);
        $usage = AiUsageLog::query()->firstOrFail();
        $this->assertSame('semantic_interpretation', $usage->request_type);
        $this->assertSame('completed', $usage->status);
        $this->assertSame(125, $usage->total_tokens);
        $this->assertSame('turn-semantic-1', $usage->metadata['stable_turn_id']);
        $this->assertSame('chatcmpl-semantic-1', $usage->metadata['provider_response_id']);
        $this->assertNotNull($usage->provider_event_id);
    }

    public function test_compose_uses_only_terminal_receipts_and_returns_conversation_directives(): void
    {
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response($this->providerResponse([
                'response_text' => 'I moved “Plan the launch” to tomorrow at 3:00 PM.',
                'close_after_response' => false,
                'response_expected' => false,
            ], id: 'chatcmpl-composition-1'), 200),
        ]);

        $user = User::factory()->create(['is_admin' => true]);
        $interpretation = new HermesSemanticInterpretation(
            outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
            responseText: null,
            clarificationQuestion: null,
            acknowledgementText: 'I’ll move that task.',
            closeAfterResponse: false,
            responseExpected: false,
            operations: [
                new HermesSemanticOperation(
                    id: 'move_task',
                    tool: 'app.task.update',
                    arguments: [
                        'id' => 42,
                        'due_at' => '2026-07-15T15:00:00-04:00',
                    ],
                ),
            ],
        );
        $request = new HermesSemanticCompositionRequest(
            user: $user,
            workspaceId: null,
            stableTurnId: 'turn-composition-1',
            transcript: 'Move that task to tomorrow at three.',
            currentTime: '2026-07-14T10:30:00-04:00',
            timezone: 'America/New_York',
            interpretation: $interpretation,
            results: [
                new HermesSemanticOperationResult(
                    operationId: 'move_task',
                    tool: 'app.task.update',
                    status: 'completed',
                    data: [
                        'receipt' => [
                            'id' => 42,
                            'title' => 'Plan the launch',
                            'due_at' => '2026-07-15T15:00:00-04:00',
                        ],
                    ],
                ),
            ],
        );

        $composition = $this->app->make(HermesSemanticInterpreter::class)->compose($request);

        $this->assertSame('I moved “Plan the launch” to tomorrow at 3:00 PM.', $composition->responseText);
        $this->assertFalse($composition->closeAfterResponse);
        $this->assertFalse($composition->responseExpected);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $userMessage = collect($payload['messages'] ?? [])->firstWhere('role', 'user');
            $input = json_decode((string) ($userMessage['content'] ?? ''), true, flags: JSON_THROW_ON_ERROR);

            return ($input['task'] ?? null) === 'compose_grounded_final_response'
                && data_get($input, 'interpretation.operations.0.arguments.id') === 42
                && data_get($input, 'results.0.status') === 'completed'
                && data_get($input, 'results.0.data.receipt.id') === 42
                && data_get($payload, 'response_format.json_schema.schema.required') === [
                    'response_text',
                    'close_after_response',
                    'response_expected',
                ];
        });

        $this->assertDatabaseHas('ai_usage_logs', [
            'user_id' => $user->id,
            'request_type' => 'semantic_response_composition',
            'status' => 'completed',
        ]);
    }

    public function test_preflight_block_prevents_the_provider_call_and_throws_a_typed_limit_exception(): void
    {
        $user = User::factory()->create();
        $usage = Mockery::mock(AiUsageService::class);
        $usage->shouldReceive('estimateTokens')->once()->andReturn(100);
        $usage->shouldReceive('estimatedCost')->once()->andReturn(0.01);
        $usage->shouldReceive('preflightDirect')->once()->andReturn([
            'allowed' => false,
            'reason' => 'This account has reached today’s AI usage limit.',
            'input_tokens' => 100,
            'reserved_output_tokens' => 800,
            'estimated_cost_usd' => 0.01,
            'budget' => ['tier' => 'base', 'daily_cost_limit' => 1.0],
        ]);
        $usage->shouldReceive('recordDirectCall')
            ->once()
            ->withArgs(fn (
                User $recordedUser,
                ?int $workspaceId,
                string $requestType,
                string $model,
                array $tokens,
                array $metadata,
                array $actions,
                string $status,
                ?string $providerEventId,
            ): bool => $recordedUser->is($user)
                && $workspaceId === null
                && $requestType === 'semantic_interpretation'
                && $model === 'gpt-test-semantic'
                && $tokens['tool_call_count'] === 0
                && $metadata['failure_category'] === 'usage_limit'
                && $actions === ['semantic_interpretation']
                && $status === 'blocked'
                && is_string($providerEventId))
            ->andReturn(new AiUsageLog);
        $service = new OpenAiHermesSemanticInterpreter($usage, new HermesSemanticProtocol);

        try {
            $service->interpret($this->interpretationRequest($user));
            $this->fail('Expected semantic usage preflight to block the provider call.');
        } catch (HermesSemanticUsageLimitException $exception) {
            $this->assertSame('usage_limit', $exception->category);
            $this->assertSame('base', $exception->preflight['budget']['tier']);
        }

        Http::assertNothingSent();
    }

    public function test_execute_output_that_claims_success_is_rejected_and_metered_as_failed(): void
    {
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response($this->providerResponse([
                'outcome' => 'execute',
                'response_text' => 'Done — I deleted it.',
                'clarification_question' => null,
                'acknowledgement_text' => null,
                'close_after_response' => false,
                'response_expected' => false,
                'operations' => [[
                    'id' => 'delete_task',
                    'tool' => 'app.task.delete',
                    'arguments_json' => json_encode(['id' => 42]),
                    'dependencies' => [],
                ]],
            ], id: 'chatcmpl-invalid-1'), 200),
        ]);

        try {
            $this->app->make(HermesSemanticInterpreter::class)->interpret($this->interpretationRequest());
            $this->fail('Expected an execute outcome with a success claim to be rejected.');
        } catch (HermesSemanticProviderException $exception) {
            $this->assertSame('invalid_structured_output', $exception->category);
            $this->assertTrue($exception->retriable);
        }

        $this->assertDatabaseHas('ai_usage_logs', [
            'request_type' => 'semantic_interpretation',
            'status' => 'failed',
        ]);
    }

    public function test_provider_refusal_is_typed_and_its_usage_is_recorded(): void
    {
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-refusal-1',
                'model' => 'gpt-test-semantic',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'refusal' => 'I cannot assist with that.',
                    ],
                ]],
                'usage' => [
                    'prompt_tokens' => 90,
                    'completion_tokens' => 8,
                    'total_tokens' => 98,
                ],
            ], 200),
        ]);

        try {
            $this->app->make(HermesSemanticInterpreter::class)->interpret($this->interpretationRequest());
            $this->fail('Expected a typed provider refusal.');
        } catch (HermesSemanticProviderException $exception) {
            $this->assertSame('refusal', $exception->category);
            $this->assertFalse($exception->retriable);
        }

        $usage = AiUsageLog::query()->firstOrFail();
        $this->assertSame('failed', $usage->status);
        $this->assertSame(98, $usage->total_tokens);
        $this->assertSame('refusal', $usage->metadata['failure_category']);
    }

    public function test_transport_failure_has_no_fallback_and_records_a_failed_attempt(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts): never {
            $attempts++;

            throw new ConnectionException('The semantic provider timed out.');
        });

        try {
            $this->app->make(HermesSemanticInterpreter::class)->interpret($this->interpretationRequest());
            $this->fail('Expected a typed semantic transport failure.');
        } catch (HermesSemanticProviderException $exception) {
            $this->assertSame('transport', $exception->category);
            $this->assertTrue($exception->retriable);
        }

        $this->assertSame(1, $attempts);
        $usage = AiUsageLog::query()->firstOrFail();
        $this->assertSame('failed', $usage->status);
        $this->assertSame(0, $usage->total_tokens);
        $this->assertSame('transport', $usage->metadata['failure_category']);
    }

    private function interpretationRequest(?User $user = null): HermesSemanticInterpretationRequest
    {
        $user ??= User::factory()->create(['is_admin' => true]);

        return new HermesSemanticInterpretationRequest(
            user: $user,
            workspaceId: null,
            stableTurnId: 'turn-semantic-1',
            transcript: 'Move that task to tomorrow at three.',
            currentTime: '2026-07-14T10:30:00-04:00',
            timezone: 'America/New_York',
            context: [
                'recent_resources' => [
                    'tasks' => [[
                        'id' => 42,
                        'title' => 'Plan the launch',
                    ]],
                ],
            ],
        );
    }

    /** @param array<string, mixed> $structured
     * @return array<string, mixed>
     */
    private function providerResponse(array $structured, string $id = 'chatcmpl-semantic-1'): array
    {
        return [
            'id' => $id,
            'model' => 'gpt-test-semantic',
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode($structured),
                    'refusal' => null,
                ],
            ]],
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 25,
                'total_tokens' => 125,
            ],
        ];
    }
}
