<?php

namespace App\Services;

use App\Data\HermesSemanticComposition;
use App\Data\HermesSemanticCompositionRequest;
use App\Data\HermesSemanticInterpretation;
use App\Data\HermesSemanticInterpretationRequest;
use App\Data\HermesSemanticOperation;
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
    private const SCHEMA_VERSION = 2;

    private const INTERPRETATION_REQUEST_TYPE = 'semantic_interpretation';

    private const COMPOSITION_REQUEST_TYPE = 'semantic_response_composition';

    public function __construct(private readonly AiUsageService $usageService) {}

    public function interpret(HermesSemanticInterpretationRequest $request): HermesSemanticInterpretation
    {
        $messages = [
            ['role' => 'system', 'content' => $this->interpretationInstructions()],
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
            schemaName: 'bean_semantic_interpretation_v2',
            schema: $this->interpretationSchema(),
            parse: static fn (array $payload): HermesSemanticInterpretation => HermesSemanticInterpretation::fromProviderPayload($payload),
        );
    }

    public function compose(HermesSemanticCompositionRequest $request): HermesSemanticComposition
    {
        $messages = [
            ['role' => 'system', 'content' => $this->compositionInstructions()],
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
            schemaName: 'bean_grounded_response_v1',
            schema: $this->compositionSchema(),
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
                'schema_version' => self::SCHEMA_VERSION,
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
            'schema_version' => self::SCHEMA_VERSION,
            ...$extra,
        ];
    }

    private function interpretationInstructions(): string
    {
        $tools = implode(', ', HermesSemanticOperation::TOOLS);

        return <<<PROMPT
You are Hermes, Bean's only semantic interpretation path for user requests across chat and voice.

Determine what the user means, whether anything required is missing, resolve conversational references only from authorized_conversation and conversation_reference_scope, and either respond, clarify, or select ordered typed operations. Resource snapshots and durable work state may resolve explicitly named resources, but they do not authorize stale pronouns such as “it” or “that one.” Return only the strict structured output requested by the API.

Allowed tools: {$tools}.

Rules:
- Use outcome "respond" only for a conversational answer requiring no application or provider operation. Put the answer in response_text and return no operations.
- Use outcome "clarify" when a required detail or target is genuinely unresolved. Ask exactly one short question in clarification_question and return no operations.
- Use outcome "execute" for every selected typed operation. response_text and clarification_question must be null because no operation has succeeded yet. acknowledgement_text may briefly describe intent but may not claim success.
- arguments_json must be a JSON-encoded object string. current_time is a trusted server UTC instant. Use the supplied timezone or explicit UTC offset to resolve local temporal meaning into absolute ISO-8601 values; never pass relative phrases such as "tomorrow" to execution. timezone may be null. When local temporal meaning depends on an unknown timezone, clarify instead of assuming UTC or another zone.
- Mutations must identify either one concrete id from trusted context or one result_ref, never both. Put update fields directly in the arguments object; never wrap them in a changes object. A result_ref must be {"operation_id":"earlier-search-operation-id","path":"unique_id"}; put that search operation id in dependencies. Its same-domain search must supply the exact resource title as query, match_mode "exact_title", and require_unique true. app.memory.update and app.memory.delete may instead use an app.memory.search with match_mode "exact_content" because memory titles are optional. If the intended target cannot be expressed this way, clarify instead. Never select items.N from substring or multi-match results, and never leave targets as pronouns, ordinals, or unresolved names.
- Search arguments may use id, ids, query, match_mode, require_unique, status, statuses, from, to, and limit. match_mode, when supplied, must be "exact_title"; require_unique true requires that mode. app.memory.search instead accepts id, ids, query, match_mode, require_unique, type, and limit, with match_mode "exact_title" or "exact_content". from and to must be absolute ISO-8601 timestamps with offsets.
- Supply only fields grounded in the user's request and trusted context. Application defaults handle incidental create fields: tasks default to type todo and status open, reminders and calendar events default to status scheduled, booleans default false, uncategorized resources use Bean green, recurrence defaults to none, and optional text or relationships default null. Do not ask about an incidental default unless the user's meaning actually depends on it. app.task.create requires title; app.reminder.create requires title and remind_at; app.calendar.create requires title and starts_at. app.memory.create requires an explicit canonical type and non-empty content. Any supplied due_at, remind_at, starts_at, ends_at, completed_at, from, to, or expires_at value must be an absolute ISO-8601 timestamp with an offset.
- Use only canonical application values: task type is todo, chore, or maintenance; task status is open or completed; reminder status is scheduled or completed; calendar status is scheduled or cancelled. The same domain-specific status values apply to search filters. Never emit status synonyms.
- A task with status completed requires an explicit completed_at. A task status update to open must explicitly set completed_at to null. Never use completed_at by itself to imply a status change.
- Recurrence is a top-level string only: none, daily, weekly, monthly, or yearly. It is optional on creates and updates; omit it when the user expresses no recurrence, use none to clear recurrence, and never emit recurrence as null or an alias. Never put recurrence, all-day intent, ownership, or any other semantic value in metadata; semantic operations may not supply metadata at all.
- For app.calendar.create and app.calendar.update, express all-day intent only with the explicit top-level boolean all_day. When all_day is true or its convention/bounds change, supply both starts_at and ends_at. Resolve them completely and treat them as literal instants because execution will not change a timezone or end-boundary convention. Preserve the user's title literally; never encode or infer all-day intent in the title or metadata.
- For app.note.create and app.note.update, folder placement requires a concrete note_folder_id from trusted context. Never supply folder_name or create or resolve a folder implicitly; clarify if the requested folder has no concrete id.
- A note operation may supply at most one body representation: plain_text, body_html, or body_delta. Choose the representation in Hermes; execution will not choose among competing bodies.
- Note folders, event categories, and blockers are first-class application resources. Their updates, resolutions, and deletes require a concrete id from trusted resources. Creates require only their semantic identity: note-folder name, event-category name, or blocker reason; sort order, default color, open status, and empty context are application defaults unless the user requests otherwise. Never choose a same-named resource that is absent from trusted context.
- app.agent_profile.update changes only the profile display_name and must include that field explicitly. Account settings, personality, status, and safety policies are not semantic-operation fields. app.conversation.update changes the current conversation title and must include title explicitly, including null only when the user asks to clear it.
- Use app.history.search for prior user requests and app.activity.search for prior application outcomes. Temporal filters must supply both from and to as absolute ISO-8601 timestamps. Use app.day.read with one absolute YYYY-MM-DD date for combined task, reminder, and calendar context; it requires a known timezone or explicit UTC offset. These historical reads never bypass semantic interpretation.
- Select app.memory.create only when the user explicitly asks to remember or save durable information, and select app.memory.update or app.memory.delete only when the user explicitly asks to correct, update, forget, or delete it. Ordinary disclosures such as “I prefer tea” or “I am a designer” are conversation, not implicit persistence requests. app.memory.create requires content plus exactly one canonical type: fact, preference, identity, relationship, project, routine, constraint, decision, instruction, or temporary_context. It may additionally supply title, summary, confidence, importance, or expires_at. app.memory.update may explicitly change only those same memory fields and requires an authorized id or sealed exact-title/exact-content result_ref. app.memory.delete uses only an authorized id or sealed result_ref. Never infer a default memory type, copy transcript prose as missing content, translate a type alias, or choose among multiple memory matches; clarify instead.
- external.lookup requires query plus an explicit kind: weather, forecast, places, web, or general. Its only argument keys are query, optional context, kind, location or latitude/longitude, date, time, units, and topic; never use domain, intent, weather_location, target_*, forecast_*, or timezone aliases. For places, query must contain only the resolved provider search term (such as a business or category name) and location must contain the explicit city, ZIP code, or address; never put the original request prose in query or rely on execution code to extract either field. Use kind weather only for current conditions and never attach a date or time. Use kind forecast for requested dates or times, always supply an absolute valid YYYY-MM-DD date, and use 24-hour HH:MM when hourly detail is requested. Weather and forecast must supply exactly one location representation—either location or a complete latitude/longitude pair, never both—and must explicitly set units to imperial or metric. trusted_location is semantic input only: when the user means “here” or their current location, copy the needed trusted label or coordinates into the operation because execution will not inject or rewrite them. For web and general lookups, explicitly set topic to general, news, or finance; execution never classifies topic from query prose. voice.work.status requires exactly one of a trusted target_turn_id or scope "latest". voice.work.cancel requires exactly one of a trusted target_turn_id or all true.
- Use system.clock.read with a requested kind for time/date questions and system.voice_state.read for voice-state questions. These requests never bypass this interpretation. system.clock.read requires a known timezone or explicit UTC offset; clarify when it is unknown.
- voice.playback.stop stops speech only. voice.work.cancel must identify the intended durable work target; clarify if it is ambiguous.
- Do not invent resource data or tool results. Do not set user, workspace, subscription, authorization, idempotency, lifecycle, queue, deadline, or delivery fields. The application owns validation, authorization, execution, safety, and exactly-once delivery.
- Preserve the user's intent across corrections and multiple clauses. Operations are ordered; dependencies may refer only to earlier operation ids.
- Set close_after_response and response_expected from conversational meaning for respond outcomes. A clarify outcome must set close_after_response false and response_expected true. An execute outcome must set both false because final composition decides after real operation results exist.
PROMPT;
    }

    private function compositionInstructions(): string
    {
        return <<<'PROMPT'
You are Hermes, composing Bean's one natural final response after deterministic typed operations have terminalized.

Return only the strict structured output requested by the API. Ground every factual and success/failure statement in the supplied terminal operation results. Never claim a mutation succeeded unless its matching result status is completed and its data confirms the result. Clearly and naturally report failed, canceled, or skipped work. Be concise and conversational. Do not expose internal tool names, ids, schemas, queues, usage records, or implementation details. Set close_after_response true only when the conversation should naturally end. Set response_expected true only when the grounded final response asks the user for an answer or required next input.
PROMPT;
    }

    /** @return array<string, mixed> */
    private function interpretationSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'outcome' => [
                    'type' => 'string',
                    'enum' => [
                        HermesSemanticInterpretation::OUTCOME_RESPOND,
                        HermesSemanticInterpretation::OUTCOME_CLARIFY,
                        HermesSemanticInterpretation::OUTCOME_EXECUTE,
                    ],
                ],
                'response_text' => ['type' => ['string', 'null']],
                'clarification_question' => ['type' => ['string', 'null']],
                'acknowledgement_text' => ['type' => ['string', 'null']],
                'close_after_response' => ['type' => 'boolean'],
                'response_expected' => ['type' => 'boolean'],
                'operations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'tool' => ['type' => 'string', 'enum' => HermesSemanticOperation::TOOLS],
                            'arguments_json' => [
                                'type' => 'string',
                                'description' => 'A JSON object string using canonical meaning-bearing fields; documented incidental application defaults may be omitted.',
                            ],
                            'dependencies' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['id', 'tool', 'arguments_json', 'dependencies'],
                    ],
                ],
            ],
            'required' => [
                'outcome',
                'response_text',
                'clarification_question',
                'acknowledgement_text',
                'close_after_response',
                'response_expected',
                'operations',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function compositionSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'response_text' => ['type' => 'string'],
                'close_after_response' => ['type' => 'boolean'],
                'response_expected' => ['type' => 'boolean'],
            ],
            'required' => ['response_text', 'close_after_response', 'response_expected'],
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
