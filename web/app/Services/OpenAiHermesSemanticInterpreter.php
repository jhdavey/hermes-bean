<?php

namespace App\Services;

use App\Data\HermesSemanticComposition;
use App\Data\HermesSemanticCompositionRequest;
use App\Data\HermesSemanticInterpretation;
use App\Data\HermesSemanticInterpretationRequest;
use App\Exceptions\HermesSemanticProviderException;
use App\Exceptions\HermesSemanticUsageLimitException;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use Throwable;

class OpenAiHermesSemanticInterpreter implements HermesSemanticInterpreter
{
    private const INTERPRETATION_REQUEST_TYPE = 'semantic_interpretation';

    private const COMPOSITION_REQUEST_TYPE = 'semantic_response_composition';

    public function __construct(
        private readonly AiUsageService $usageService,
        private readonly HermesSemanticProtocol $protocol,
    ) {}

    public function interpret(HermesSemanticInterpretationRequest $request): HermesSemanticInterpretation
    {
        $messages = [
            ['role' => 'system', 'content' => $this->protocol->interpretationInstructions()],
            [
                'role' => 'user',
                'content' => $this->encodeJson([
                    'task' => 'interpret_user_request',
                    ...$request->toArray(),
                ]),
            ],
        ];

        return $this->completion(
            user: $request->user,
            workspaceId: $request->workspaceId,
            stableTurnId: $request->stableTurnId,
            conversationSessionId: $request->conversationSessionId,
            conversationMessageId: $request->conversationMessageId,
            requestType: self::INTERPRETATION_REQUEST_TYPE,
            phase: 'interpretation',
            messages: $messages,
            schemaName: HermesSemanticProtocol::INTERPRETATION_SCHEMA_NAME,
            schema: $this->protocol->interpretationSchema(),
            parse: static fn (array $payload): HermesSemanticInterpretation => HermesSemanticInterpretation::fromProviderPayload($payload),
        );
    }

    public function compose(HermesSemanticCompositionRequest $request): HermesSemanticComposition
    {
        $messages = [
            ['role' => 'system', 'content' => $this->protocol->compositionInstructions()],
            [
                'role' => 'user',
                'content' => $this->encodeJson([
                    'task' => 'compose_grounded_final_response',
                    ...$request->toArray(),
                ]),
            ],
        ];

        return $this->completion(
            user: $request->user,
            workspaceId: $request->workspaceId,
            stableTurnId: $request->stableTurnId,
            conversationSessionId: $request->conversationSessionId,
            conversationMessageId: $request->conversationMessageId,
            requestType: self::COMPOSITION_REQUEST_TYPE,
            phase: 'composition',
            messages: $messages,
            schemaName: HermesSemanticProtocol::COMPOSITION_SCHEMA_NAME,
            schema: $this->protocol->compositionSchema(),
            parse: static fn (array $payload): HermesSemanticComposition => HermesSemanticComposition::fromProviderPayload($payload),
        );
    }

    /**
     * @template TResult of object
     *
     * @param  list<array{role:string,content:string}>  $messages
     * @param  array<string, mixed>  $schema
     * @param  callable(array<string, mixed>): TResult  $parse
     * @return TResult
     */
    private function completion(
        User $user,
        ?int $workspaceId,
        string $stableTurnId,
        ?int $conversationSessionId,
        ?int $conversationMessageId,
        string $requestType,
        string $phase,
        array $messages,
        string $schemaName,
        array $schema,
        callable $parse,
    ): object {
        $model = trim((string) config('services.hermes_runtime.semantic_interpretation_model', 'gpt-5.6-luna'));
        $apiKey = trim((string) config('services.hermes_runtime.api_key'));
        $apiBase = rtrim((string) config('services.hermes_runtime.api_base', 'https://api.openai.com/v1'), '/');
        if ($model === '' || $apiKey === '' || $apiBase === '') {
            throw new HermesSemanticProviderException(
                category: 'configuration',
                internalDetail: 'Hermes semantic interpretation is not configured.',
            );
        }

        $reservedOutputTokens = max(1, (int) config(
            $phase === 'interpretation'
                ? 'services.hermes_runtime.semantic_interpretation_reserved_output_tokens'
                : 'services.hermes_runtime.semantic_composition_reserved_output_tokens',
            $phase === 'interpretation' ? 800 : 300,
        ));
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_completion_tokens' => $reservedOutputTokens,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schemaName,
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
        ];
        $reasoningEffort = trim((string) config('services.hermes_runtime.semantic_reasoning_effort', 'none'));
        if ($reasoningEffort !== '') {
            $payload['reasoning_effort'] = $reasoningEffort;
        }
        $serializedPrompt = $this->encodeJson($payload);
        $inputTokens = $this->usageService->estimateTokens($serializedPrompt);
        $estimatedCost = $this->usageService->estimatedCost($model, $inputTokens, $reservedOutputTokens);
        $preflight = $this->usageService->preflightDirect(
            $user,
            $workspaceId,
            $model,
            $inputTokens,
            $reservedOutputTokens,
            $estimatedCost,
            $requestType,
            [
                'stable_turn_id' => $stableTurnId,
                'conversation_session_id' => $conversationSessionId,
                'conversation_message_id' => $conversationMessageId,
                'phase' => $phase,
                'schema_version' => HermesSemanticProtocol::SCHEMA_VERSION,
            ],
        );

        if (! ($preflight['allowed'] ?? false)) {
            $reason = trim((string) ($preflight['reason'] ?? 'This account cannot use AI right now.'));
            $this->recordUsage(
                user: $user,
                workspaceId: $workspaceId,
                requestType: $requestType,
                model: $model,
                usage: [],
                metadata: $this->usageMetadata(
                    stableTurnId: $stableTurnId,
                    conversationSessionId: $conversationSessionId,
                    conversationMessageId: $conversationMessageId,
                    phase: $phase,
                    extra: ['failure_category' => 'usage_limit', 'reason' => $reason],
                ),
                status: 'blocked',
            );

            throw new HermesSemanticUsageLimitException($reason, $preflight);
        }

        $startedAt = microtime(true);
        try {
            $connectTimeout = (float) config(
                $phase === 'interpretation'
                    ? 'services.hermes_runtime.semantic_interpretation_connect_timeout'
                    : 'services.hermes_runtime.semantic_composition_connect_timeout',
                $phase === 'interpretation' ? 0.3 : 0.5,
            );
            $timeout = (float) config(
                $phase === 'interpretation'
                    ? 'services.hermes_runtime.semantic_interpretation_timeout'
                    : 'services.hermes_runtime.semantic_composition_timeout',
                $phase === 'interpretation' ? 0.9 : 2,
            );
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->post($apiBase.'/chat/completions', $payload);
        } catch (ConnectionException $exception) {
            $this->recordUsage(
                user: $user,
                workspaceId: $workspaceId,
                requestType: $requestType,
                model: $model,
                usage: [],
                metadata: $this->usageMetadata(
                    stableTurnId: $stableTurnId,
                    conversationSessionId: $conversationSessionId,
                    conversationMessageId: $conversationMessageId,
                    phase: $phase,
                    extra: [
                        'failure_category' => 'transport',
                        'latency_ms' => $this->latencyMilliseconds($startedAt),
                    ],
                ),
                status: 'failed',
            );

            throw new HermesSemanticProviderException(
                category: 'transport',
                internalDetail: 'Hermes semantic provider connection failed.',
                retriable: true,
            );
        }

        $latencyMs = $this->latencyMilliseconds($startedAt);
        $decoded = $response->json();
        $decoded = is_array($decoded) ? $decoded : [];
        $actualModel = is_string($decoded['model'] ?? null) ? $decoded['model'] : $model;
        $usage = $this->usageService->usageFromOpenAiResponse($decoded);
        $providerResponseId = is_string($decoded['id'] ?? null) ? $decoded['id'] : null;
        $baseMetadata = $this->usageMetadata(
            stableTurnId: $stableTurnId,
            conversationSessionId: $conversationSessionId,
            conversationMessageId: $conversationMessageId,
            phase: $phase,
            extra: [
                'latency_ms' => $latencyMs,
                'requested_model' => $model,
                'provider_response_id' => $providerResponseId,
            ],
        );

        if (! $response->successful()) {
            $this->recordUsage($user, $workspaceId, $requestType, $actualModel, $usage, [
                ...$baseMetadata,
                'failure_category' => 'provider_http',
                'provider_status' => $response->status(),
            ], 'failed');

            throw new HermesSemanticProviderException(
                category: 'provider_http',
                internalDetail: 'Hermes semantic provider returned HTTP '.$response->status().'.',
                retriable: $response->status() === 429 || $response->serverError(),
            );
        }

        $refusal = data_get($decoded, 'choices.0.message.refusal');
        if (is_string($refusal) && trim($refusal) !== '') {
            $this->recordUsage($user, $workspaceId, $requestType, $actualModel, $usage, [
                ...$baseMetadata,
                'failure_category' => 'refusal',
            ], 'failed');

            throw new HermesSemanticProviderException(
                category: 'refusal',
                internalDetail: 'Hermes semantic provider refused the request.',
            );
        }

        $finishReason = data_get($decoded, 'choices.0.finish_reason');
        if ($finishReason !== 'stop') {
            $this->recordUsage($user, $workspaceId, $requestType, $actualModel, $usage, [
                ...$baseMetadata,
                'failure_category' => 'incomplete_provider_response',
                'finish_reason' => is_scalar($finishReason) ? (string) $finishReason : null,
            ], 'failed');

            throw new HermesSemanticProviderException(
                category: 'incomplete_provider_response',
                internalDetail: 'Hermes semantic provider did not finish its structured response.',
                retriable: true,
            );
        }

        $content = data_get($decoded, 'choices.0.message.content');
        try {
            if (! is_string($content) || trim($content) === '') {
                throw new InvalidArgumentException('Hermes semantic provider returned no structured content.');
            }

            $structured = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($structured)) {
                throw new InvalidArgumentException('Hermes semantic provider returned a non-object payload.');
            }

            $result = $parse($structured);
        } catch (JsonException|InvalidArgumentException $exception) {
            $this->recordUsage($user, $workspaceId, $requestType, $actualModel, $usage, [
                ...$baseMetadata,
                'failure_category' => 'invalid_structured_output',
            ], 'failed');

            throw new HermesSemanticProviderException(
                category: 'invalid_structured_output',
                internalDetail: 'Hermes semantic provider returned invalid structured output: '.$exception->getMessage(),
                retriable: true,
            );
        } catch (Throwable $exception) {
            $this->recordUsage($user, $workspaceId, $requestType, $actualModel, $usage, [
                ...$baseMetadata,
                'failure_category' => 'output_processing',
            ], 'failed');

            throw $exception;
        }

        $this->recordUsage($user, $workspaceId, $requestType, $actualModel, $usage, $baseMetadata, 'completed');

        return $result;
    }

    /**
     * @param  array{input_tokens?:int,output_tokens?:int}  $usage
     * @param  array<string, mixed>  $metadata
     */
    private function recordUsage(
        User $user,
        ?int $workspaceId,
        string $requestType,
        string $model,
        array $usage,
        array $metadata,
        string $status,
    ): void {
        $providerResponseId = is_string($metadata['provider_response_id'] ?? null)
            && trim($metadata['provider_response_id']) !== ''
                ? $metadata['provider_response_id']
                : null;
        $hasBillableUsage = (int) ($usage['input_tokens'] ?? 0) > 0
            || (int) ($usage['output_tokens'] ?? 0) > 0;
        $providerEventId = $providerResponseId !== null || ! $hasBillableUsage
            ? hash('sha256', implode('|', [
                'hermes_semantic',
                (string) $user->getKey(),
                (string) ($metadata['stable_turn_id'] ?? ''),
                (string) ($metadata['phase'] ?? ''),
                $providerResponseId ?? 'no-provider-response',
                $status,
            ]))
            : null;

        $this->usageService->recordDirectCall(
            $user,
            $workspaceId,
            $requestType,
            $model,
            [...$usage, 'tool_call_count' => 0],
            $metadata,
            [$requestType],
            $status,
            $providerEventId,
        );
    }

    /** @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function usageMetadata(
        string $stableTurnId,
        ?int $conversationSessionId,
        ?int $conversationMessageId,
        string $phase,
        array $extra = [],
    ): array {
        return [
            'provider' => 'openai',
            'route_tier' => $phase === 'interpretation'
                ? self::INTERPRETATION_REQUEST_TYPE
                : self::COMPOSITION_REQUEST_TYPE,
            'stable_turn_id' => $stableTurnId,
            'conversation_session_id' => $conversationSessionId,
            'conversation_message_id' => $conversationMessageId,
            'phase' => $phase,
            'schema_version' => HermesSemanticProtocol::SCHEMA_VERSION,
            ...$extra,
        ];
    }

    private function latencyMilliseconds(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    private function encodeJson(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Hermes semantic provider input must be JSON encodable.', previous: $exception);
        }
    }
}
