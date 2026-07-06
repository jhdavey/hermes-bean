<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\User;
use App\Services\HermesToolRuntime\CrudPlannerRuntime;
use App\Services\HermesToolRuntime\NativeToolRuntime;
use App\Services\HermesToolRuntime\RuntimeSupport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HermesToolRuntimeService implements HermesRuntimeService
{
    use CrudPlannerRuntime;
    use NativeToolRuntime;
    use RuntimeSupport;

    public function __construct(
        private readonly StructuredHermesActionService $actionService,
        private readonly AgentProfileService $agentProfileService,
        private readonly WorkspaceService $workspaceService,
        private readonly AiUsageService $usageService,
        private readonly AdminSettingsService $adminSettings,
        private readonly BeanMemoryService $memoryService,
        private readonly LiveLookupService $liveLookup,
        private readonly PlanLimitService $planLimits,
    ) {}

    public function startSession(array $attributes = []): ConversationSession
    {
        return DB::transaction(function () use ($attributes): ConversationSession {
            $user = User::findOrFail($attributes['user_id'] ?? auth()->id());
            $workspace = $this->workspaceService->resolveWorkspace($user, $attributes['workspace_id'] ?? null);
            $this->agentProfileService->ensureForWorkspace($workspace, $user);

            $session = ConversationSession::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'created_by_user_id' => $user->id,
                'title' => $attributes['title'] ?? null,
                'status' => 'active',
                'runtime_mode' => $attributes['runtime_mode'] ?? 'tools',
                'metadata' => $attributes['metadata'] ?? null,
                'last_activity_at' => now(),
            ]);

            $this->recordEvent($session, 'runtime.session_started', [
                'runtime_mode' => $session->runtime_mode,
                'workspace_id' => $workspace->id,
            ]);

            return $session->refresh();
        });
    }

    public function resumeSession(ConversationSession $session): ConversationSession
    {
        $session->update(['last_activity_at' => now()]);

        $this->recordEvent($session, 'runtime.session_resumed');

        return $session->refresh();
    }

    public function cancelSession(ConversationSession $session): ConversationSession
    {
        if (in_array($session->status, ['running', 'cancelling'], true)) {
            $session->update(['status' => 'cancelling', 'last_activity_at' => now()]);

            $this->recordEvent($session, 'runtime.cancel_requested');
        }

        return $session->refresh();
    }

    public function progressEvents(ConversationSession $session): Collection
    {
        return $session->activityEvents()->orderBy('id')->get();
    }

    public function sendMessage(ConversationSession $session, string $content, array $metadata = []): array
    {
        $userMessage = DB::transaction(function () use ($session, $content, $metadata): ConversationMessage {
            return ConversationMessage::create([
                'user_id' => $session->user_id,
                'conversation_session_id' => $session->id,
                'role' => 'user',
                'content' => $content,
                'metadata' => $metadata ?: null,
            ]);
        });

        return $this->sendExistingMessage($session, $userMessage);
    }

    public function sendExistingMessage(ConversationSession $session, ConversationMessage $userMessage): array
    {
        $received = $this->recordEvent($session, 'runtime.message_received', [
            'message_id' => $userMessage->id,
        ]);

        $modelRoute = $this->modelRouteFor($session);
        $prompt = $this->toolPromptFor($session, $userMessage, $modelRoute);
        $preflight = $this->usageService->preflight($session, $userMessage, $modelRoute, $prompt);
        if (! $preflight['allowed']) {
            $this->usageService->recordBlocked($session, $userMessage, $modelRoute, $preflight, (string) $preflight['reason']);

            return $this->toolRuntimeBlocked($session, $userMessage, collect([$received]), (string) $preflight['reason'], [
                'failure_type' => 'usage_limit',
                'model_route' => $modelRoute,
            ]);
        }

        return $this->sendMessageWithTools($session, $userMessage, $received, $modelRoute, $prompt);
    }

    private function sendMessageWithTools(
        ConversationSession $session,
        ConversationMessage $userMessage,
        ActivityEvent $received,
        array $modelRoute,
        string $prompt
    ): array {
        $runtimeStartedAt = microtime(true);
        $apiKey = $this->providerApiKey();
        if ($apiKey === '') {
            return $this->toolRuntimeFailed($session, $userMessage, collect([$received]), 'Bean is not configured to contact the agent model yet.', [
                'failure_type' => 'missing_api_key',
                'provider' => config('services.hermes_runtime.default_provider'),
                'key_source' => config('services.hermes_runtime.api_key_source'),
            ]);
        }

        if (! filled($modelRoute['model'] ?? null)) {
            return $this->toolRuntimeFailed($session, $userMessage, collect([$received]), 'Bean is missing an agent model configuration.', [
                'failure_type' => 'missing_model',
            ]);
        }

        $toolMode = $this->toolRoutingMode($userMessage);
        $contextStartedAt = microtime(true);
        $contextPayload = $this->toolContextPayload($session, $userMessage, $toolMode);
        $conversationMessages = $this->modelConversationMessages($session, $userMessage);
        $contextBuildMs = $this->elapsedMs($contextStartedAt);
        $toolCount = count($this->nativeToolDefinitions($toolMode));

        $started = $this->recordEvent($session, 'runtime.tool_model_started', [
            'message_id' => $userMessage->id,
            'provider' => config('services.hermes_runtime.default_provider'),
            'model' => $modelRoute['model'],
            'model_route' => $modelRoute,
            'tool_mode' => $toolMode,
            'tool_count' => $toolCount,
            'history_message_count' => count($conversationMessages),
            'context_build_ms' => $contextBuildMs,
        ], 'hermes.tools', 'started');
        $session->update(['status' => 'running', 'last_activity_at' => now()]);

        if ($this->messageIsRequestHistoryRecall($userMessage)) {
            $historyResult = $this->tryRunRequestHistoryFastPath(
                $session,
                $userMessage,
                $received,
                $started,
                $modelRoute,
                $prompt,
                $runtimeStartedAt,
                $contextBuildMs,
                $toolMode
            );
            if ($historyResult !== null) {
                return $historyResult;
            }
        }

        $directLookupArguments = $this->directExternalLookupArguments($session, $userMessage);
        if ($directLookupArguments !== null) {
            $lookupResult = $this->tryRunExternalLookupFastPath(
                $session,
                $userMessage,
                $received,
                $started,
                $modelRoute,
                $prompt,
                $runtimeStartedAt,
                $contextBuildMs,
                $toolMode,
                $directLookupArguments
            );
            if ($lookupResult !== null) {
                return $lookupResult;
            }
        }

        $messages = [
            ['role' => 'system', 'content' => $this->toolSystemInstructions()],
            ['role' => 'system', 'content' => "Runtime context:\n".json_encode($contextPayload, JSON_THROW_ON_ERROR)],
            ...$conversationMessages,
        ];

        if ($toolMode === 'app_crud' && $this->canUseCrudPlanner($userMessage)) {
            try {
                $plannedResult = $this->tryRunCrudPlanner(
                    $session,
                    $userMessage,
                    $received,
                    $started,
                    $modelRoute,
                    $contextPayload,
                    $conversationMessages,
                    $runtimeStartedAt,
                    $contextBuildMs
                );
                if ($plannedResult !== null) {
                    return $plannedResult;
                }
            } catch (\Throwable $exception) {
                Log::warning('Hermes CRUD planner fell back to native tool loop.', [
                    'session_id' => $session->id,
                    'message_id' => $userMessage->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        $responses = [];
        $domainEvents = collect();
        $actions = [];
        $toolOutputs = [];
        $assistantContent = '';
        $finalResponse = null;
        $modelCallDurationsMs = [];
        $toolExecutionDurationsMs = [];
        $finalResponseDurationMs = null;
        $expectedWriteActionCount = $this->expectedWriteActionCount($userMessage);
        $nextNativeWorkOrder = 0;

        try {
            for ($turn = 0; $turn < 3; $turn++) {
                if ($this->isCancellationRequested($session)) {
                    return $this->toolRuntimeCancelled($session, $userMessage, collect([$received, $started]));
                }

                $modelCallStartedAt = microtime(true);
                $response = $this->chatCompletion($modelRoute, $messages, true, $toolMode);
                $modelCallDurationsMs[] = $this->elapsedMs($modelCallStartedAt);
                $responses[] = $response;
                $finalResponse = $response;
                $modelRoute['model'] = (string) data_get($response, 'model', $modelRoute['model']);
                $message = data_get($response, 'choices.0.message', []);
                $toolCalls = is_array($message) && is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];

                if ($toolCalls !== [] && $this->messageIsCapabilityQuestion($userMessage)) {
                    if ($turn >= 1) {
                        $assistantContent = $this->capabilityQuestionFallbackContent($userMessage);
                        $finalResponseDurationMs ??= 0;
                        break;
                    }

                    $messages[] = [
                        'role' => 'system',
                        'content' => 'The user asked whether Bean can do something, not to do it. Answer the capability question directly in one concise sentence and do not call tools.',
                    ];

                    continue;
                }

                if ($toolCalls === []) {
                    $candidateContent = $this->normalizedAssistantContent(data_get($message, 'content', ''));
                    if ($this->messageIsCapabilityQuestion($userMessage) && str_word_count($candidateContent) > 28) {
                        $assistantContent = $this->capabilityQuestionFallbackContent($userMessage);
                        break;
                    }

                    if ($actions !== [] && $this->toolOutputsAllSuccessfulWrites($toolOutputs)) {
                        $assistantContent = $this->nativeActionFallbackContent($actions);
                        $finalResponseDurationMs ??= 0;
                        break;
                    }

                    if ($this->shouldContinueAfterUnverifiedCompletionClaim($userMessage, $candidateContent, $actions, $toolOutputs, $turn)) {
                        $messages[] = [
                            'role' => 'assistant',
                            'content' => $candidateContent,
                        ];
                        $messages[] = [
                            'role' => 'system',
                            'content' => $this->unverifiedCompletionCorrectionPrompt(),
                        ];

                        continue;
                    }

                    if ($this->shouldContinueAfterReadOnlyTerminal($userMessage, $candidateContent, $actions, $toolOutputs, $turn)) {
                        $messages[] = [
                            'role' => 'assistant',
                            'content' => $candidateContent,
                        ];
                        $messages[] = [
                            'role' => 'system',
                            'content' => $this->readOnlyTerminalCorrectionPrompt(),
                        ];

                        continue;
                    }

                    $assistantContent = $candidateContent;
                    break;
                }

                $messages[] = [
                    'role' => 'assistant',
                    'content' => data_get($message, 'content'),
                    'tool_calls' => $toolCalls,
                ];

                $plannedWorkByToolCall = $this->recordPlannedNativeWorkItems($session, $userMessage, $toolCalls, $nextNativeWorkOrder);
                $nextNativeWorkOrder += count($plannedWorkByToolCall);
                $domainEvents = $domainEvents->concat(
                    collect($plannedWorkByToolCall)
                        ->pluck('event')
                        ->filter(fn (mixed $event): bool => $event instanceof ActivityEvent)
                        ->values()
                );

                foreach ($toolCalls as $toolCall) {
                    if ($this->isCancellationRequested($session)) {
                        return $this->toolRuntimeCancelled($session, $userMessage, collect([$received, $started])->concat($domainEvents));
                    }

                    if (! is_array($toolCall)) {
                        continue;
                    }
                    $toolCallId = (string) ($toolCall['id'] ?? '');
                    $toolStartedAt = microtime(true);
                    [$toolActions, $toolEvents, $toolOutput] = $this->executeNativeToolCall(
                        $session,
                        $userMessage,
                        $toolCall,
                        is_array($plannedWorkByToolCall[$toolCallId] ?? null) ? $plannedWorkByToolCall[$toolCallId] : null
                    );
                    $toolExecutionDurationsMs[] = $this->elapsedMs($toolStartedAt);
                    $actions = array_merge($actions, $toolActions);
                    $toolOutputs[] = $toolOutput;
                    $domainEvents = $domainEvents->concat($toolEvents);
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) ($toolCall['id'] ?? ''),
                        'content' => json_encode($toolOutput, JSON_THROW_ON_ERROR),
                    ];
                }

                if ($actions === [] && $this->canUseNativeReadFallback($toolOutputs)) {
                    $assistantContent = $this->nativeReadFallbackContent($toolOutputs);
                    $finalResponseDurationMs = 0;
                    break;
                }

                if (
                    $actions !== []
                    && count($actions) >= $expectedWriteActionCount
                    && $this->toolOutputsAllSuccessfulWrites($toolOutputs)
                ) {
                    $assistantContent = $this->nativeActionFallbackContent($actions);
                    $finalResponseDurationMs = 0;
                    break;
                }
            }

            if ($assistantContent === '' && $actions !== []) {
                if ($this->toolOutputsAllSuccessfulWrites($toolOutputs)) {
                    $assistantContent = $this->nativeActionFallbackContent($actions);
                    $finalResponseDurationMs = 0;
                } else {
                    try {
                        if ($this->isCancellationRequested($session)) {
                            return $this->toolRuntimeCancelled($session, $userMessage, collect([$received, $started])->concat($domainEvents));
                        }

                        $finalResponseStartedAt = microtime(true);
                        $response = $this->chatCompletion($modelRoute, $messages, false);
                        $finalResponseDurationMs = $this->elapsedMs($finalResponseStartedAt);
                        $modelCallDurationsMs[] = $finalResponseDurationMs;
                        $responses[] = $response;
                        $finalResponse = $response;
                        $modelRoute['model'] = (string) data_get($response, 'model', $modelRoute['model']);
                        $assistantContent = $this->normalizedAssistantContent(data_get($response, 'choices.0.message.content', ''));
                    } catch (\Throwable $exception) {
                        Log::warning('Hermes final response call failed after successful tool execution.', [
                            'session_id' => $session->id,
                            'message_id' => $userMessage->id,
                            'exception' => $exception->getMessage(),
                        ]);
                    }
                }
            }
        } catch (\Throwable $exception) {
            if ($actions !== [] || $toolOutputs !== []) {
                Log::warning('Hermes model response failed after successful tool execution.', [
                    'session_id' => $session->id,
                    'message_id' => $userMessage->id,
                    'provider' => config('services.hermes_runtime.default_provider'),
                    'model' => $modelRoute['model'] ?? null,
                    'exception' => $exception->getMessage(),
                ]);
            } else {
                Log::error('Hermes tool runtime failed.', [
                    'session_id' => $session->id,
                    'message_id' => $userMessage->id,
                    'provider' => config('services.hermes_runtime.default_provider'),
                    'model' => $modelRoute['model'] ?? null,
                    'exception' => $exception->getMessage(),
                ]);

                return $this->toolRuntimeFailed($session, $userMessage, collect([$received, $started]), 'I’m on it. I’m syncing against the latest app state now, and I’ll ask for one detail if I need it.', [
                    'failure_type' => 'tool_runtime_failed',
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        if ($assistantContent === '') {
            $assistantContent = $actions !== []
                ? $this->nativeActionFallbackContent($actions)
                : $this->nativeReadFallbackContent($toolOutputs);
        }

        $assistantContent = $this->assistantSafeResponseContent($assistantContent);

        $result = DB::transaction(function () use ($session, $userMessage, $received, $started, $modelRoute, $prompt, $responses, $domainEvents, $assistantContent, $finalResponse, $toolMode, $runtimeStartedAt, $contextBuildMs, $modelCallDurationsMs, $toolExecutionDurationsMs, $finalResponseDurationMs, $actions): array {
            $completed = $this->recordEvent($session, 'runtime.tool_model_completed', [
                'message_id' => $userMessage->id,
                'response_count' => count($responses),
                'finish_reason' => data_get($finalResponse, 'choices.0.finish_reason'),
                'tool_mode' => $toolMode,
                'duration_ms' => $this->elapsedMs($runtimeStartedAt),
                'context_build_ms' => $contextBuildMs,
                'model_call_count' => count($modelCallDurationsMs),
                'model_call_ms' => array_sum($modelCallDurationsMs),
                'model_call_durations_ms' => $modelCallDurationsMs,
                'tool_execution_count' => count($toolExecutionDurationsMs),
                'tool_execution_ms' => array_sum($toolExecutionDurationsMs),
                'tool_execution_durations_ms' => $toolExecutionDurationsMs,
                'final_response_ms' => $finalResponseDurationMs,
                'action_count' => count($actions),
            ], 'hermes.tools', 'succeeded');

            $assistantMessage = ConversationMessage::create([
                'user_id' => $session->user_id,
                'conversation_session_id' => $session->id,
                'role' => 'assistant',
                'content' => $assistantContent,
                'metadata' => [
                    'runtime' => 'tools',
                    'provider' => config('services.hermes_runtime.default_provider'),
                    'model' => $modelRoute['model'],
                    'model_route' => $modelRoute,
                ],
            ]);

            $messageCompleted = $this->recordEvent($session, 'runtime.message_completed', [
                'message_id' => $assistantMessage->id,
            ]);

            $usageLog = $this->usageService->recordCompletion(
                $session,
                $userMessage,
                $assistantMessage,
                $modelRoute,
                $prompt,
                json_encode($responses, JSON_THROW_ON_ERROR),
                $domainEvents
            );

            $session->update(['status' => 'active', 'last_activity_at' => now()]);

            return [
                'status' => 'completed',
                'session' => $session->refresh(),
                'user_message' => $userMessage,
                'assistant_message' => $assistantMessage,
                'events' => collect([$received, $started, $completed])->concat($domainEvents)->push($messageCompleted),
                'usage' => $usageLog,
                'blocker' => null,
            ];
        });

        $assistantMessage = $result['assistant_message'] ?? null;
        if ($assistantMessage instanceof ConversationMessage) {
            $this->memoryService->recordTurnCandidate($session->refresh(), $userMessage, $assistantMessage);
        }

        return $result;
    }

    private function tryRunRequestHistoryFastPath(
        ConversationSession $session,
        ConversationMessage $userMessage,
        ActivityEvent $received,
        ActivityEvent $started,
        array $modelRoute,
        string $prompt,
        float $runtimeStartedAt,
        int $contextBuildMs,
        string $toolMode
    ): ?array {
        $query = $this->requestHistoryRecallQuery($userMessage);
        if ($query === '') {
            return null;
        }

        $toolStartedAt = microtime(true);
        $toolOutput = $this->requestHistoryForTool($session, [
            'query' => $query,
            'workspace_id' => $session->workspace_id,
            'limit' => 8,
            'strict_query' => true,
        ], $userMessage);
        $toolExecutionMs = $this->elapsedMs($toolStartedAt);
        $assistantContent = $this->assistantSafeResponseContent($this->requestHistoryFallbackContent($toolOutput));
        $responses = [[
            'id' => 'local-request-history',
            'model' => 'local-request-history',
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => $assistantContent],
            ]],
        ]];

        $result = DB::transaction(function () use ($session, $userMessage, $received, $started, $modelRoute, $prompt, $responses, $assistantContent, $runtimeStartedAt, $contextBuildMs, $toolExecutionMs, $toolMode): array {
            $completed = $this->recordEvent($session, 'runtime.tool_model_completed', [
                'message_id' => $userMessage->id,
                'response_count' => 1,
                'finish_reason' => 'stop',
                'tool_mode' => $toolMode,
                'read_fast_path' => 'request_history',
                'duration_ms' => $this->elapsedMs($runtimeStartedAt),
                'context_build_ms' => $contextBuildMs,
                'model_call_count' => 0,
                'model_call_ms' => 0,
                'model_call_durations_ms' => [],
                'tool_execution_count' => 1,
                'tool_execution_ms' => $toolExecutionMs,
                'tool_execution_durations_ms' => [$toolExecutionMs],
                'final_response_ms' => 0,
                'action_count' => 0,
            ], 'hermes.tools', 'succeeded');

            $assistantMessage = ConversationMessage::create([
                'user_id' => $session->user_id,
                'conversation_session_id' => $session->id,
                'role' => 'assistant',
                'content' => $assistantContent,
                'metadata' => [
                    'runtime' => 'tools',
                    'provider' => config('services.hermes_runtime.default_provider'),
                    'model' => $modelRoute['model'],
                    'model_route' => $modelRoute,
                    'read_fast_path' => 'request_history',
                ],
            ]);

            $messageCompleted = $this->recordEvent($session, 'runtime.message_completed', [
                'message_id' => $assistantMessage->id,
            ]);

            $usageLog = $this->usageService->recordCompletion(
                $session,
                $userMessage,
                $assistantMessage,
                $modelRoute,
                $prompt,
                json_encode($responses, JSON_THROW_ON_ERROR),
                collect()
            );

            $session->update(['status' => 'active', 'last_activity_at' => now()]);

            return [
                'status' => 'completed',
                'session' => $session->refresh(),
                'user_message' => $userMessage,
                'assistant_message' => $assistantMessage,
                'events' => collect([$received, $started, $completed, $messageCompleted]),
                'usage' => $usageLog,
                'blocker' => null,
            ];
        });

        $assistantMessage = $result['assistant_message'] ?? null;
        if ($assistantMessage instanceof ConversationMessage) {
            $this->memoryService->recordTurnCandidate($session->refresh(), $userMessage, $assistantMessage);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function tryRunExternalLookupFastPath(
        ConversationSession $session,
        ConversationMessage $userMessage,
        ActivityEvent $received,
        ActivityEvent $started,
        array $modelRoute,
        string $prompt,
        float $runtimeStartedAt,
        int $contextBuildMs,
        string $toolMode,
        array $arguments
    ): ?array {
        $toolStartedAt = microtime(true);
        $toolOutput = $this->externalLookupForTool($session, $arguments);
        $toolExecutionMs = $this->elapsedMs($toolStartedAt);

        if (! filled($toolOutput['text'] ?? null)) {
            return null;
        }

        $assistantContent = $this->assistantSafeResponseContent($this->nativeReadFallbackContent([$toolOutput]));
        $provider = (string) ($toolOutput['provider'] ?? 'external_lookup');
        $responses = [[
            'id' => 'local-external-lookup',
            'model' => 'local-external-lookup',
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => $assistantContent],
            ]],
        ]];

        $result = DB::transaction(function () use ($session, $userMessage, $received, $started, $modelRoute, $prompt, $responses, $assistantContent, $runtimeStartedAt, $contextBuildMs, $toolExecutionMs, $toolMode, $provider, $toolOutput): array {
            $completed = $this->recordEvent($session, 'runtime.tool_model_completed', [
                'message_id' => $userMessage->id,
                'response_count' => 1,
                'finish_reason' => 'stop',
                'tool_mode' => $toolMode,
                'read_fast_path' => 'external_lookup',
                'lookup_provider' => $provider,
                'lookup_kind' => $toolOutput['kind'] ?? null,
                'duration_ms' => $this->elapsedMs($runtimeStartedAt),
                'context_build_ms' => $contextBuildMs,
                'model_call_count' => 0,
                'model_call_ms' => 0,
                'model_call_durations_ms' => [],
                'tool_execution_count' => 1,
                'tool_execution_ms' => $toolExecutionMs,
                'tool_execution_durations_ms' => [$toolExecutionMs],
                'final_response_ms' => 0,
                'action_count' => 0,
            ], 'hermes.tools', 'succeeded');

            $assistantMessage = ConversationMessage::create([
                'user_id' => $session->user_id,
                'conversation_session_id' => $session->id,
                'role' => 'assistant',
                'content' => $assistantContent,
                'metadata' => [
                    'runtime' => 'tools',
                    'provider' => config('services.hermes_runtime.default_provider'),
                    'model' => $modelRoute['model'],
                    'model_route' => $modelRoute,
                    'read_fast_path' => 'external_lookup',
                    'lookup_provider' => $provider,
                ],
            ]);

            $messageCompleted = $this->recordEvent($session, 'runtime.message_completed', [
                'message_id' => $assistantMessage->id,
            ]);

            $usageLog = $this->usageService->recordCompletion(
                $session,
                $userMessage,
                $assistantMessage,
                $modelRoute,
                $prompt,
                json_encode($responses, JSON_THROW_ON_ERROR),
                collect()
            );

            $session->update(['status' => 'active', 'last_activity_at' => now()]);

            return [
                'status' => 'completed',
                'session' => $session->refresh(),
                'user_message' => $userMessage,
                'assistant_message' => $assistantMessage,
                'events' => collect([$received, $started, $completed, $messageCompleted]),
                'usage' => $usageLog,
                'blocker' => null,
            ];
        });

        $assistantMessage = $result['assistant_message'] ?? null;
        if ($assistantMessage instanceof ConversationMessage) {
            $this->memoryService->recordTurnCandidate($session->refresh(), $userMessage, $assistantMessage);
        }

        return $result;
    }

    private function chatCompletion(array $modelRoute, array $messages, bool $allowTools, string $toolMode = 'full'): array
    {
        $payload = [
            'model' => (string) $modelRoute['model'],
            'messages' => $messages,
        ];
        if ($allowTools) {
            $payload['tools'] = $this->nativeToolDefinitions($toolMode);
            $payload['tool_choice'] = 'auto';
        }

        $response = Http::withToken($this->providerApiKey())
            ->acceptJson()
            ->asJson()
            ->timeout((float) config('services.hermes_runtime.timeout', 30))
            ->post(rtrim((string) config('services.hermes_runtime.api_base'), '/').'/chat/completions', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('Model API returned HTTP '.$response->status().': '.mb_substr($response->body(), 0, 1000));
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            throw new \RuntimeException('Model API returned a non-JSON response.');
        }

        return $decoded;
    }

    private function recordEvent(ConversationSession $session, string $type, array $payload = [], ?string $toolName = null, string $status = 'recorded'): ActivityEvent
    {
        return ActivityEvent::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'event_type' => $type,
            'tool_name' => $toolName,
            'status' => $status,
            'payload' => $payload ?: null,
        ]);
    }
}
