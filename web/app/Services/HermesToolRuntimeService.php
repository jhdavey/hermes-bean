<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\AgentProfile;
use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HermesToolRuntimeService implements HermesRuntimeService
{
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

                $plannedWorkByToolCall = $this->recordPlannedNativeWorkItems($session, $userMessage, $toolCalls);
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

    private function tryRunCrudPlanner(
        ConversationSession $session,
        ConversationMessage $userMessage,
        ActivityEvent $received,
        ActivityEvent $started,
        array $baseModelRoute,
        array $contextPayload,
        array $conversationMessages,
        float $runtimeStartedAt,
        int $contextBuildMs
    ): ?array {
        $expectedWriteActionCount = $this->expectedWriteActionCount($userMessage);
        $plannerRoute = $this->crudPlannerModelRoute($baseModelRoute);
        if (! filled($plannerRoute['model'] ?? null)) {
            return null;
        }

        $promptPayload = $this->crudPlannerPromptPayload($session, $userMessage, $contextPayload, $conversationMessages, $expectedWriteActionCount);
        $plannerSource = 'local';
        $actions = $this->deterministicCrudActions($session, $userMessage, $contextPayload);
        $response = $this->localPlannerResponse($plannerRoute, $actions);
        $modelCallDurationsMs = [];

        if (! $this->plannerActionsMeetExpected($actions, $expectedWriteActionCount)) {
            $plannerSource = 'model';
            $plannerMessages = [
                ['role' => 'system', 'content' => $this->crudPlannerSystemInstructions()],
                ['role' => 'user', 'content' => json_encode($promptPayload, JSON_THROW_ON_ERROR)],
            ];

            $modelStartedAt = microtime(true);
            $response = $this->crudPlannerCompletion($plannerRoute, $plannerMessages);
            $modelCallDurationsMs = [$this->elapsedMs($modelStartedAt)];
            $actions = $this->plannerActionsFromResponse($response);
        }

        if (! $this->plannerActionsMeetExpected($actions, $expectedWriteActionCount)) {
            return null;
        }

        $plannedWork = $this->recordPlannedCrudWorkItems($session, $userMessage, $actions);
        $domainEvents = collect($plannedWork)
            ->pluck('event')
            ->filter(fn (mixed $event): bool => $event instanceof ActivityEvent)
            ->values();
        $executedActions = [];
        $toolOutputs = [];
        $toolExecutionDurationsMs = [];
        $createdCalendarEventsByKey = [];
        $lastCreatedCalendarEventId = null;
        $successfulActions = [];
        $failedWorkItems = [];

        foreach ($actions as $index => $action) {
            if ($this->isCancellationRequested($session)) {
                return $this->toolRuntimeCancelled($session, $userMessage, collect([$received, $started])->concat($domainEvents));
            }

            $workItem = is_array($plannedWork[$index] ?? null) ? $plannedWork[$index] : null;
            $action = $this->correlatePlannerAction($action, $createdCalendarEventsByKey, $lastCreatedCalendarEventId);
            $toolStartedAt = microtime(true);
            [$executed, $events, $toolOutput] = $this->executePlannerAction($session, $action, $workItem);
            $toolExecutionDurationsMs[] = $this->elapsedMs($toolStartedAt);
            $domainEvents = $domainEvents->concat($events);
            $executedActions = array_merge($executedActions, $executed);
            $toolOutputs[] = $toolOutput;

            if (($toolOutput['ok'] ?? false) === true) {
                $successfulActions = array_merge($successfulActions, $executed);
            } else {
                $failedWorkItems[] = [
                    'label' => (string) ($workItem['label'] ?? $this->workItemLabelForAction((string) ($action['type'] ?? ''), is_array($action['parameters'] ?? null) ? $action['parameters'] : [])),
                    'action_type' => (string) ($toolOutput['action_type'] ?? $action['type'] ?? ''),
                    'message' => (string) ($toolOutput['message'] ?? ''),
                    'error_code' => (string) ($toolOutput['error_code'] ?? ''),
                ];
            }

            if (($toolOutput['ok'] ?? false) === true && in_array((string) ($action['type'] ?? ''), ['calendar_event.create', 'calendar.create'], true)) {
                $createdId = $this->createdCalendarEventIdFromEvents($events);
                if ($createdId !== null) {
                    $lastCreatedCalendarEventId = $createdId;
                    $clientKey = $this->plannerActionKey($action);
                    if ($clientKey !== '') {
                        $createdCalendarEventsByKey[$clientKey] = $createdId;
                    }
                }
            }
        }

        $allWritesSucceeded = $this->toolOutputsAllSuccessfulWrites($toolOutputs);
        $assistantContent = $allWritesSucceeded
            ? $this->nativeActionFallbackContent($executedActions)
            : $this->partialCrudPlannerContent($successfulActions, $failedWorkItems, count($actions));
        $assistantContent = $this->assistantSafeResponseContent($assistantContent);
        $prompt = json_encode($promptPayload, JSON_THROW_ON_ERROR);
        $responses = [$response];
        $finalResponse = $response;
        $finalResponseDurationMs = 0;
        $toolMode = 'app_crud';
        $plannerUsed = true;

        $result = DB::transaction(function () use ($session, $userMessage, $received, $started, $plannerRoute, $prompt, $responses, $domainEvents, $assistantContent, $finalResponse, $toolMode, $runtimeStartedAt, $contextBuildMs, $modelCallDurationsMs, $toolExecutionDurationsMs, $finalResponseDurationMs, $executedActions, $plannerUsed, $plannerSource, $allWritesSucceeded): array {
            $completed = $this->recordEvent($session, 'runtime.tool_model_completed', [
                'message_id' => $userMessage->id,
                'response_count' => count($responses),
                'finish_reason' => data_get($finalResponse, 'choices.0.finish_reason'),
                'tool_mode' => $toolMode,
                'planner_used' => $plannerUsed,
                'planner_source' => $plannerSource,
                'duration_ms' => $this->elapsedMs($runtimeStartedAt),
                'context_build_ms' => $contextBuildMs,
                'model_call_count' => count($modelCallDurationsMs),
                'model_call_ms' => array_sum($modelCallDurationsMs),
                'model_call_durations_ms' => $modelCallDurationsMs,
                'tool_execution_count' => count($toolExecutionDurationsMs),
                'tool_execution_ms' => array_sum($toolExecutionDurationsMs),
                'tool_execution_durations_ms' => $toolExecutionDurationsMs,
                'final_response_ms' => $finalResponseDurationMs,
                'action_count' => count($executedActions),
                'all_writes_succeeded' => $allWritesSucceeded,
            ], 'hermes.tools', $allWritesSucceeded ? 'succeeded' : 'partial');

            $assistantMessage = ConversationMessage::create([
                'user_id' => $session->user_id,
                'conversation_session_id' => $session->id,
                'role' => 'assistant',
                'content' => $assistantContent,
                'metadata' => [
                    'runtime' => 'tools',
                    'provider' => config('services.hermes_runtime.default_provider'),
                    'model' => $plannerRoute['model'],
                    'model_route' => $plannerRoute,
                    'planner_used' => $plannerUsed,
                    'planner_source' => $plannerSource,
                ],
            ]);

            $messageCompleted = $this->recordEvent($session, 'runtime.message_completed', [
                'message_id' => $assistantMessage->id,
            ]);

            $usageLog = $this->usageService->recordCompletion(
                $session,
                $userMessage,
                $assistantMessage,
                $plannerRoute,
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

    private function crudPlannerCompletion(array $modelRoute, array $messages): array
    {
        $response = Http::withToken($this->providerApiKey())
            ->acceptJson()
            ->asJson()
            ->timeout((float) config('services.hermes_runtime.crud_planner_timeout', 20))
            ->post(rtrim((string) config('services.hermes_runtime.api_base'), '/').'/chat/completions', [
                'model' => (string) $modelRoute['model'],
                'messages' => $messages,
                'response_format' => ['type' => 'json_object'],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('CRUD planner returned HTTP '.$response->status().': '.mb_substr($response->body(), 0, 1000));
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            throw new \RuntimeException('CRUD planner returned a non-JSON response.');
        }

        return $decoded;
    }

    private function crudPlannerModelRoute(array $baseModelRoute): array
    {
        $configuredModel = trim((string) config('services.hermes_runtime.crud_planner_model', ''));
        $quickModel = trim((string) config('services.hermes_runtime.quick_reply_model', ''));
        $baseModel = trim((string) ($baseModelRoute['model'] ?? ''));
        $model = $configuredModel !== ''
            ? $configuredModel
            : ($quickModel !== '' ? $quickModel : $baseModel);

        return array_merge($baseModelRoute, [
            'mode' => 'crud_planner',
            'tier' => 'quick',
            'model' => $model,
            'billing_model' => $model,
            'context_mode' => 'crud_planner',
            'reason' => 'Fast create-only app CRUD planner used before native tool execution.',
        ]);
    }

    private function canUseCrudPlanner(ConversationMessage $message): bool
    {
        if (! (bool) config('services.hermes_runtime.crud_planner_enabled', true)) {
            return false;
        }

        if ($this->messageIsCapabilityQuestion($message)) {
            return false;
        }

        if (! $this->messageAppearsToRequestAppWrite($message)) {
            return false;
        }

        $text = str((string) $message->content)
            ->lower()
            ->replaceMatches('/[^\pL\pN\s:.-]+/u', ' ')
            ->squish()
            ->toString();

        if ($text === '') {
            return false;
        }

        $hasPlannerWriteVerb = (bool) preg_match('/\b(add|create|make|schedule|book|set|put|block|remind|update|edit|change|move|reschedule|rename|delete|remove|cancel|clear|complete|finish|mark)\b/u', $text);
        if (! $hasPlannerWriteVerb && preg_match('/\b(what|when|where|who|which|find|search|show|list|look up|look for|did i|have i)\b/u', $text)) {
            return false;
        }

        return $hasPlannerWriteVerb;
    }

    private function crudPlannerPromptPayload(ConversationSession $session, ConversationMessage $message, array $contextPayload, array $conversationMessages, int $expectedWriteActionCount): array
    {
        $timezone = $this->sessionDisplayTimezone($session);
        $clientContext = data_get($contextPayload, 'temporal_context.client_context');

        return [
            'task' => 'Plan visible HeyBean create actions for one user request. Return JSON only.',
            'user_message' => (string) $message->content,
            'expected_min_actions' => $expectedWriteActionCount,
            'local_timezone' => $timezone,
            'now_local' => now()->setTimezone($timezone)->toIso8601String(),
            'client_context' => is_array($clientContext) ? $clientContext : null,
            'workspace' => data_get($contextPayload, 'workspace'),
            'accessible_workspaces' => data_get($contextPayload, 'accessible_workspaces', []),
            'recent_conversation' => collect($conversationMessages)->take(-4)->values()->all(),
            'schema' => [
                'actions' => [
                    [
                        'type' => 'calendar_event.create | calendar_event.update | calendar_event.delete | reminder.create | reminder.update | reminder.delete | task.create | task.update | task.delete | note.create | note.update | note.delete',
                        'client_action_key' => 'short unique key like event_1, reminder_1, task_1',
                        'related_action_key' => 'optional key of same-request event a reminder is for',
                        'parameters' => [
                            'id' => 'required for update/delete when known from context',
                            'title' => 'user-visible title',
                            'match_title' => 'title hint when id is unavailable',
                            'starts_at' => 'local ISO-8601 for calendar events',
                            'ends_at' => 'local ISO-8601 for calendar events when duration is known',
                            'remind_at' => 'local ISO-8601 for reminders',
                            'due_at' => 'local ISO-8601 for tasks when due date/time is known',
                            'plain_text' => 'note body when useful',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function crudPlannerSystemInstructions(): string
    {
        return <<<'PROMPT'
You are a strict JSON planner for HeyBean app CRUD requests.
Return one JSON object with an "actions" array and no prose.
Allowed actions: calendar_event.create, calendar_event.update, calendar_event.delete, reminder.create, reminder.update, reminder.delete, task.create, task.update, task.delete, note.create, note.update, note.delete.
Resolve relative dates/times using now_local, local_timezone, and client_context. Emit local ISO-8601 timestamps with offsets.
Plan every independent app change the user asked for, in the same order the user asked for it.
Calendar blocks, appointments, meetings, schedule items, and events use calendar_event.*.
Reminder alarms use reminder.*. Tasks or to-dos use task.*. Notes/lists/writing use note.*.
If a reminder is for a same-request calendar event, include related_action_key pointing to that event's client_action_key.
Use concise user-visible titles. Do not invent extra actions or optional reminders.
If the request is not a clear app CRUD request, return {"actions":[]}.
PROMPT;
    }

    private function deterministicCrudActions(ConversationSession $session, ConversationMessage $message, array $contextPayload): array
    {
        $content = str((string) $message->content)->squish()->toString();
        if ($content === '') {
            return [];
        }

        $normalized = str($content)
            ->lower()
            ->replaceMatches('/[^\pL\pN\s:.-]+/u', ' ')
            ->squish()
            ->toString();

        $moveAndDeleteActions = $this->deterministicMoveEventAndDeleteReminderActions($session, $content, $contextPayload);
        if ($moveAndDeleteActions !== []) {
            return $moveAndDeleteActions;
        }

        if ($this->textRequestsDelete($normalized)) {
            $deleteActions = $this->deterministicDeleteActions($session, $content, $contextPayload);
            if ($deleteActions !== []) {
                return $deleteActions;
            }
        }

        if ($this->textRequestsUpdate($normalized)) {
            $updateActions = $this->deterministicUpdateActions($session, $content, $contextPayload);
            if ($updateActions !== []) {
                return $updateActions;
            }
        }

        $timezone = $this->displayTimezoneFromClientContext((array) data_get($contextPayload, 'temporal_context.client_context', []))
            ?? $this->sessionDisplayTimezone($session);
        $matches = [];

        $sameDaySequenceActions = $this->deterministicSameDayCalendarSequenceActions($content, $contextPayload, $timezone);
        if ($sameDaySequenceActions !== []) {
            return $sameDaySequenceActions;
        }

        $calendarListActions = $this->deterministicDatedCalendarListActions($content, $contextPayload, $timezone);
        if ($calendarListActions !== []) {
            return $calendarListActions;
        }

        $afterWorkoutActions = $this->deterministicAfterWorkoutFollowUpActions($session, $content, $contextPayload, $timezone);
        if ($afterWorkoutActions !== []) {
            return $afterWorkoutActions;
        }

        $afternoonPlanActions = $this->deterministicAfternoonPlanActions($content, $contextPayload, $timezone);
        if ($afternoonPlanActions !== []) {
            return $afternoonPlanActions;
        }

        $taskReminderNoteActions = $this->deterministicTaskReminderNoteActions($content, $contextPayload, $timezone);
        if ($taskReminderNoteActions !== []) {
            return $taskReminderNoteActions;
        }

        $noteReminderActions = $this->deterministicNoteReminderActions($content, $contextPayload, $timezone);
        if ($noteReminderActions !== []) {
            return $noteReminderActions;
        }

        $projectFollowUpActions = $this->deterministicProjectFollowUpActions($content, $contextPayload, $timezone);
        if ($projectFollowUpActions !== []) {
            return $projectFollowUpActions;
        }

        $this->collectDeterministicCreateMatches(
            $matches,
            $content,
            '/\bcreate\s+(?:a\s+)?(?:calendar\s+)?(?:event|block)\s+titled\s+(.+?)\s+for\s+(.+?)\s+to\s+(.+?)(?=,\s*(?:and\s+)?(?:create|set)\b|\.\s*$|$)/iu',
            'calendar_event.create',
            $timezone
        );
        $this->collectDeterministicCreateMatches(
            $matches,
            $content,
            '/\b(?:create|set)\s+(?:a\s+)?reminder\s+titled\s+(.+?)\s+for\s+(.+?)(?=,\s*(?:and\s+)?(?:create|set)\b|\.\s*$|$)/iu',
            'reminder.create',
            $timezone
        );
        $this->collectDeterministicCreateMatches(
            $matches,
            $content,
            '/\bcreate\s+(?:a\s+)?task\s+titled\s+(.+?)\s+due\s+(.+?)(?=,\s*(?:and\s+)?(?:create|set)\b|\.\s*$|$)/iu',
            'task.create',
            $timezone
        );

        usort($matches, fn (array $left, array $right): int => ($left['offset'] ?? 0) <=> ($right['offset'] ?? 0));

        $actions = [];
        $lastCalendarKey = null;
        foreach ($matches as $index => $match) {
            $action = $match['action'];
            $key = match ($action['type']) {
                'calendar_event.create' => 'event_'.$index,
                'reminder.create' => 'reminder_'.$index,
                'task.create' => 'task_'.$index,
                default => 'action_'.$index,
            };
            $action['client_action_key'] = $key;

            if ($action['type'] === 'calendar_event.create') {
                $lastCalendarKey = $key;
            } elseif ($action['type'] === 'reminder.create' && $lastCalendarKey !== null) {
                $action['related_action_key'] = $lastCalendarKey;
            }

            if ($this->plannerActionHasRequiredFields($action)) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    private function deterministicMoveEventAndDeleteReminderActions(ConversationSession $session, string $content, array $contextPayload): array
    {
        $normalized = str($content)->lower()->squish()->toString();
        if (
            ! preg_match('/\b(move|reschedule|change)\b/u', $normalized)
            || ! preg_match('/\b(delete|remove|cancel)\b/u', $normalized)
            || ! preg_match('/\b(reminder|reminders)\b/u', $normalized)
        ) {
            return [];
        }

        $targetDate = $this->deterministicTargetDate($content, $contextPayload);
        $timezone = $this->displayTimezoneFromClientContext((array) data_get($contextPayload, 'temporal_context.client_context', []))
            ?? $this->sessionDisplayTimezone($session);
        $event = $this->resolveCalendarEventForMoveRequest($session, $content, $targetDate);
        if (! $event instanceof CalendarEvent || ! $event->starts_at) {
            return [];
        }

        if (! preg_match('/\b(?:to|at|for)\s+(\d{1,2}(?::\d{2})?\s*(?:am|pm))\b/iu', $content, $timeMatch)) {
            return [];
        }

        $baseDate = $targetDate instanceof Carbon
            ? $targetDate->copy()->setTimezone($timezone)
            : $event->starts_at->copy()->setTimezone($timezone);
        $startsAt = $this->deterministicDateWithMeridiemTime($baseDate, (string) ($timeMatch[1] ?? ''), $timezone);
        if (! $startsAt instanceof Carbon) {
            return [];
        }

        $durationSeconds = 3600;
        if ($event->ends_at instanceof Carbon) {
            $durationSeconds = max(900, $event->starts_at->diffInSeconds($event->ends_at));
        }

        $actions = [[
            'type' => 'calendar_event.update',
            'risk' => 'low',
            'client_action_key' => 'update_event_0',
            'parameters' => [
                'id' => $event->id,
                'title' => $event->title,
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $startsAt->copy()->addSeconds($durationSeconds)->toIso8601String(),
            ],
        ]];

        $reminder = $this->resolveReminderForText($session, $content, $targetDate, $event);
        if ($reminder instanceof Reminder) {
            $actions[] = [
                'type' => 'reminder.delete',
                'risk' => 'low',
                'client_action_key' => 'delete_reminder_0',
                'parameters' => [
                    'id' => $reminder->id,
                    'title' => $reminder->title,
                ],
            ];
        }

        return $actions;
    }

    private function deterministicAfterWorkoutFollowUpActions(ConversationSession $session, string $content, array $contextPayload, string $timezone): array
    {
        $normalized = str($content)->lower()->squish()->toString();
        if (
            ! str_contains($normalized, 'after the workout')
            || ! preg_match('/\b(add|create|schedule|put|yes please)\b/u', $normalized)
            || ! preg_match('/\b(grocery|groceries|store)\b/u', $normalized)
            || ! preg_match('/\b(cook|cooking|dinner)\b/u', $normalized)
        ) {
            return [];
        }

        $workout = $this->existingWorkoutEventForFollowUp($session, $contextPayload, $timezone);
        if (! $workout instanceof CalendarEvent || ! $workout->ends_at) {
            return [];
        }

        $groceryMinutes = $this->durationMinutesNearKeyword($content, 'grocery', 45);
        $cookMinutes = $this->durationMinutesNearKeyword($content, 'cook', 30);
        $reminderMinutes = 15;
        if (preg_match('/\b(\d{1,3})\s*-?\s*minutes?\s+reminders?\b/iu', $content, $reminderMatch)) {
            $reminderMinutes = max(1, min(1440, (int) ($reminderMatch[1] ?? 15)));
        } elseif (preg_match('/\b(\d{1,3})\s+minutes?\s+before\b/iu', $content, $reminderMatch)) {
            $reminderMinutes = max(1, min(1440, (int) ($reminderMatch[1] ?? 15)));
        }

        $groceryStart = $workout->ends_at->copy()->setTimezone($timezone);
        $groceryEnd = $groceryStart->copy()->addMinutes($groceryMinutes);
        $cookStart = $groceryEnd->copy();
        $cookEnd = $cookStart->copy()->addMinutes($cookMinutes);
        $createReminders = preg_match('/\breminders?\b|\bremind\b/iu', $content);

        $actions = [
            [
                'type' => 'calendar_event.create',
                'risk' => 'low',
                'client_action_key' => 'event_grocery',
                'parameters' => [
                    'title' => 'Grocery shopping',
                    'starts_at' => $groceryStart->toIso8601String(),
                    'ends_at' => $groceryEnd->toIso8601String(),
                ],
            ],
            [
                'type' => 'calendar_event.create',
                'risk' => 'low',
                'client_action_key' => 'event_cook',
                'parameters' => [
                    'title' => 'Cook dinner',
                    'starts_at' => $cookStart->toIso8601String(),
                    'ends_at' => $cookEnd->toIso8601String(),
                ],
            ],
        ];

        if ($createReminders) {
            $actions[] = [
                'type' => 'reminder.create',
                'risk' => 'low',
                'client_action_key' => 'reminder_grocery',
                'related_action_key' => 'event_grocery',
                'parameters' => [
                    'title' => 'Reminder: Grocery shopping',
                    'remind_at' => $groceryStart->copy()->subMinutes($reminderMinutes)->toIso8601String(),
                ],
            ];
            $actions[] = [
                'type' => 'reminder.create',
                'risk' => 'low',
                'client_action_key' => 'reminder_cook',
                'related_action_key' => 'event_cook',
                'parameters' => [
                    'title' => 'Reminder: Cook dinner',
                    'remind_at' => $cookStart->copy()->subMinutes($reminderMinutes)->toIso8601String(),
                ],
            ];
        }

        return $actions;
    }

    private function deterministicSameDayCalendarSequenceActions(string $content, array $contextPayload, string $timezone): array
    {
        $normalized = str($content)->lower()->squish()->toString();
        if (! preg_match('/\b(add|create|schedule|put)\b/u', $normalized)) {
            return [];
        }
        if (! preg_match('/\b(today|tomorrow|calendar|schedule|event|events|appointment|meeting|block)\b/u', $normalized)) {
            return [];
        }

        if (! preg_match_all(
            '/(?:^|,|\band\s+)\s*(?:add|create|schedule|put)?\s*(?:a\s+|an\s+)?(?<title>[\pL\pN][\pL\pN\s&\'-]{1,80}?)\s+(?<date>today|tomorrow)?\s*from\s+(?<start>\d{1,2}(?::\d{2})?\s*(?:am|pm)?)\s*(?:-|to|until)\s*(?<end>\d{1,2}(?::\d{2})?\s*(?:am|pm)?)/iu',
            $content,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            return [];
        }

        if (count($matches) < 2) {
            return [];
        }

        $now = $this->deterministicNow($contextPayload, $timezone)->setTimezone($timezone);
        $actions = [];
        $eventsByKey = [];
        foreach ($matches as $index => $match) {
            $title = $this->cleanDeterministicTitle((string) ($match['title'][0] ?? ''));
            $title = preg_replace('/\b(?:and|then)\s*$/iu', '', $title) ?: $title;
            $title = $this->cleanDeterministicTitle($title);
            if ($title === '' || preg_match('/\b(reminder|remind)\b/iu', $title)) {
                continue;
            }
            $title = str($title)->ucfirst()->toString();

            $dateWord = mb_strtolower(trim((string) ($match['date'][0] ?? '')));
            $date = $now->copy()->startOfDay();
            if ($dateWord === 'tomorrow') {
                $date->addDay();
            }

            $startText = trim((string) ($match['start'][0] ?? ''));
            $endText = trim((string) ($match['end'][0] ?? ''));
            $startMeridiem = $this->meridiemFromTimeText($startText);
            $endMeridiem = $this->meridiemFromTimeText($endText);
            if ($startMeridiem === null && $endMeridiem !== null) {
                $startText .= $endMeridiem;
            }
            if ($endMeridiem === null && $startMeridiem !== null) {
                $endText .= $startMeridiem;
            }

            $startsAt = $this->deterministicDateWithMeridiemTime($date, $startText, $timezone);
            $endsAt = $this->deterministicDateWithMeridiemTime($date, $endText, $timezone);
            if (! $startsAt instanceof Carbon || ! $endsAt instanceof Carbon) {
                continue;
            }
            if ($endsAt->lte($startsAt)) {
                $endsAt->addDay();
            }

            $key = 'event_'.$index;
            $actions[] = [
                'type' => 'calendar_event.create',
                'risk' => 'low',
                'client_action_key' => $key,
                'parameters' => [
                    'title' => $title,
                    'starts_at' => $startsAt->toIso8601String(),
                    'ends_at' => $endsAt->toIso8601String(),
                ],
            ];
            $eventsByKey[$key] = [
                'title' => $title,
                'starts_at' => $startsAt,
            ];
        }

        if (count($actions) < 2) {
            return [];
        }

        if (preg_match_all('/\b(?:a\s+)?reminder\s+(\d{1,3})\s+minutes?\s+before\s+([\pL\pN\s&\'-]+?)(?=,|\.|$)/iu', $content, $reminderMatches, PREG_SET_ORDER)) {
            foreach ($reminderMatches as $reminderIndex => $reminderMatch) {
                $minutes = max(1, min(1440, (int) ($reminderMatch[1] ?? 15)));
                $hint = mb_strtolower($this->cleanDeterministicTitle((string) ($reminderMatch[2] ?? '')));
                $relatedKey = null;
                foreach ($eventsByKey as $key => $event) {
                    $eventTitle = mb_strtolower((string) ($event['title'] ?? ''));
                    if ($hint !== '' && (str_contains($eventTitle, $hint) || str_contains($hint, $eventTitle))) {
                        $relatedKey = $key;
                        break;
                    }
                }
                if ($relatedKey === null && $eventsByKey !== []) {
                    $relatedKey = array_key_last($eventsByKey);
                }
                if (! is_string($relatedKey) || ! isset($eventsByKey[$relatedKey])) {
                    continue;
                }

                $event = $eventsByKey[$relatedKey];
                $actions[] = [
                    'type' => 'reminder.create',
                    'risk' => 'low',
                    'client_action_key' => 'reminder_'.$reminderIndex,
                    'related_action_key' => $relatedKey,
                    'parameters' => [
                        'title' => 'Reminder: '.$event['title'],
                        'remind_at' => $event['starts_at']->copy()->subMinutes($minutes)->toIso8601String(),
                    ],
                ];
            }
        }

        return $actions;
    }

    private function meridiemFromTimeText(string $timeText): ?string
    {
        if (preg_match('/\b(am|pm)\b/iu', $timeText, $match)) {
            return mb_strtolower((string) ($match[1] ?? ''));
        }

        return null;
    }

    private function existingWorkoutEventForFollowUp(ConversationSession $session, array $contextPayload, string $timezone): ?CalendarEvent
    {
        $now = $this->deterministicNow($contextPayload, $timezone);
        $startUtc = $now->copy()->setTimezone($timezone)->startOfDay()->utc();
        $endUtc = $now->copy()->setTimezone($timezone)->endOfDay()->utc();

        $query = CalendarEvent::query()
            ->where('user_id', $session->user_id)
            ->whereBetween('starts_at', [$startUtc, $endUtc])
            ->where(function ($query): void {
                $query->where('title', 'like', '%Workout%')
                    ->orWhere('title', 'like', '%Exercise%');
            });

        if ($session->workspace_id) {
            $query->where('workspace_id', $session->workspace_id);
        }

        return (clone $query)
            ->where('conversation_session_id', $session->id)
            ->orderByDesc('ends_at')
            ->first()
            ?: $query->orderByDesc('ends_at')->first();
    }

    private function durationMinutesNearKeyword(string $content, string $keyword, int $default): int
    {
        if (preg_match('/\b'.preg_quote($keyword, '/').'\w*\b.{0,80}?\bfor\s+(\d{1,3})\s+minutes?\b/iu', $content, $match)) {
            return max(1, min(1440, (int) ($match[1] ?? $default)));
        }

        if (preg_match('/\b(\d{1,3})\s+minutes?\b.{0,80}?\b'.preg_quote($keyword, '/').'\w*\b/iu', $content, $match)) {
            return max(1, min(1440, (int) ($match[1] ?? $default)));
        }

        return $default;
    }

    private function deterministicAfternoonPlanActions(string $content, array $contextPayload, string $timezone): array
    {
        $normalized = str($content)->lower()->squish()->toString();
        if (! preg_match('/\b(plan|schedule|add)\b/u', $normalized)) {
            return [];
        }
        if (! preg_match('/\b(workout|exercise)\b/u', $normalized) || ! preg_match('/\b(grocery|groceries|store)\b/u', $normalized) || ! preg_match('/\b(cook|dinner)\b/u', $normalized)) {
            return [];
        }

        $now = $this->deterministicNow($contextPayload, $timezone);
        $start = $now->copy()->setTime(15, 0);
        if ($start->lte($now)) {
            $start = $now->copy()->addMinutes(30);
            $minute = (int) ceil($start->minute / 15) * 15;
            if ($minute >= 60) {
                $start->addHour()->minute(0);
            } else {
                $start->minute($minute);
            }
            $start->second(0);
        }

        $actions = [];
        $blocks = [
            ['Workout', 45],
            ['Grocery shopping', 45],
            ['Cook dinner', 45],
        ];

        foreach ($blocks as $index => [$title, $minutes]) {
            $endsAt = $start->copy()->addMinutes($minutes);
            $actions[] = [
                'type' => 'calendar_event.create',
                'risk' => 'low',
                'client_action_key' => 'event_'.$index,
                'parameters' => [
                    'title' => $title,
                    'starts_at' => $start->toIso8601String(),
                    'ends_at' => $endsAt->toIso8601String(),
                ],
            ];
            $start = $endsAt->copy()->addMinutes(5);
        }

        if (preg_match('/\b(recipe|dinner recipe)\b/u', $normalized)) {
            $actions[] = [
                'type' => 'note.create',
                'risk' => 'low',
                'client_action_key' => 'note_recipe',
                'parameters' => [
                    'title' => 'Simple dinner recipe',
                    'plain_text' => "Simple dinner recipe\n\nGarlic tomato pasta\n- Pasta\n- Cherry tomatoes\n- Garlic\n- Olive oil\n- Parmesan\n\nCook pasta, saute garlic and tomatoes in olive oil, toss together, and finish with parmesan.",
                ],
            ];
        }

        if (preg_match('/\b(grocery checklist|grocery list|shopping list)\b/u', $normalized)) {
            $actions[] = [
                'type' => 'note.create',
                'risk' => 'low',
                'client_action_key' => 'note_grocery',
                'parameters' => [
                    'title' => 'Grocery checklist',
                    'plain_text' => "- Pasta\n- Cherry tomatoes\n- Garlic\n- Olive oil\n- Parmesan",
                ],
            ];
        }

        return $actions;
    }

    private function deterministicTaskReminderNoteActions(string $content, array $contextPayload, string $timezone): array
    {
        $normalized = str($content)->lower()->squish()->toString();
        if (! preg_match('/\btask\b/u', $normalized) || ! preg_match('/\bremind\b/u', $normalized) || ! preg_match('/\bnote\b/u', $normalized)) {
            return [];
        }

        if (! preg_match('/\btask\s+to\s+(.+?)(?:\s+tomorrow|\s+today|\s+on\s+\d|\s*,|\s+and\s+remind|\s+remind)/iu', $content, $titleMatch)) {
            return [];
        }

        $title = $this->cleanDeterministicTitle((string) ($titleMatch[1] ?? ''));
        if ($title === '') {
            return [];
        }

        $now = $this->deterministicNow($contextPayload, $timezone);
        $dueAt = $now->copy()->addDay()->setTime(9, 0);
        if (preg_match('/\btoday\b/iu', $content)) {
            $dueAt = $now->copy()->setTime(9, 0);
            if ($dueAt->lte($now)) {
                $dueAt = $now->copy()->addHour();
            }
        }

        $minutesBefore = 30;
        if (preg_match('/\b(\d{1,3})\s+minutes?\s+before\b/iu', $content, $minutesMatch)) {
            $minutesBefore = max(1, min(1440, (int) ($minutesMatch[1] ?? 30)));
        }

        $noteTitle = str($title)->headline()->toString().' note';
        if (preg_match('/\b(?:save|create)\s+(?:a\s+)?note\s+(?:called|titled)\s+(.+?)(?:,|\.|$)/iu', $content, $noteMatch)) {
            $noteTitle = $this->cleanDeterministicTitle((string) ($noteMatch[1] ?? $noteTitle));
        }

        return [
            [
                'type' => 'task.create',
                'risk' => 'low',
                'client_action_key' => 'task_0',
                'parameters' => [
                    'title' => $title,
                    'due_at' => $dueAt->toIso8601String(),
                ],
            ],
            [
                'type' => 'reminder.create',
                'risk' => 'low',
                'client_action_key' => 'reminder_0',
                'parameters' => [
                    'title' => $title,
                    'remind_at' => $dueAt->copy()->subMinutes($minutesBefore)->toIso8601String(),
                ],
            ],
            [
                'type' => 'note.create',
                'risk' => 'low',
                'client_action_key' => 'note_0',
                'parameters' => [
                    'title' => $noteTitle,
                    'plain_text' => "Documents to bring:\n- ID\n- Insurance card\n- Related paperwork\n- Any previous notes or reference numbers",
                ],
            ],
        ];
    }

    private function deterministicNoteReminderActions(string $content, array $contextPayload, string $timezone): array
    {
        $normalized = str($content)->lower()->squish()->toString();
        if (! preg_match('/\bnote\s+(?:called|titled)\s+(.+?)(?:\s+with\b|,|\band\b)/iu', $content, $noteMatch)) {
            return [];
        }
        if (! preg_match('/\breminder\b|\bremind\b/iu', $content)) {
            return [];
        }

        $noteTitle = $this->cleanDeterministicTitle((string) ($noteMatch[1] ?? ''));
        if ($noteTitle === '') {
            return [];
        }

        $now = $this->deterministicNow($contextPayload, $timezone);
        $remindAt = $now->copy()->addDay()->setTime(9, 0);
        if (preg_match('/\btomorrow\b/iu', $content)) {
            $remindAt = $now->copy()->addDay();
        }
        if (preg_match('/\b(?:at\s*)?(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/iu', $content, $timeMatch)) {
            $hour = (int) ($timeMatch[1] ?? 9);
            $minute = (int) ($timeMatch[2] ?? 0);
            $meridiem = strtolower((string) ($timeMatch[3] ?? 'am'));
            if ($meridiem === 'pm' && $hour !== 12) {
                $hour += 12;
            } elseif ($meridiem === 'am' && $hour === 12) {
                $hour = 0;
            }
            $remindAt->setTime($hour, $minute);
        }

        $plainText = "Quick dinner ideas:\n- Garlic tomato pasta\n- Sheet-pan chicken tacos\n- Rice bowls with vegetables and eggs";
        if (! str_contains($normalized, 'dinner')) {
            $plainText = $noteTitle;
        }

        $reminderTitle = 'Pick one from '.$noteTitle;
        if (preg_match('/\bupdate\s+it\b/u', $normalized)) {
            $reminderTitle = 'Update '.$noteTitle;
        } elseif (preg_match('/\breview\s+it\b/u', $normalized)) {
            $reminderTitle = 'Review '.$noteTitle;
        }

        return [
            [
                'type' => 'note.create',
                'risk' => 'low',
                'client_action_key' => 'note_0',
                'parameters' => [
                    'title' => $noteTitle,
                    'plain_text' => $plainText,
                    'is_pinned' => str_contains($normalized, 'pin it'),
                ],
            ],
            [
                'type' => 'reminder.create',
                'risk' => 'low',
                'client_action_key' => 'reminder_0',
                'parameters' => [
                    'title' => $reminderTitle,
                    'remind_at' => $remindAt->toIso8601String(),
                ],
            ],
        ];
    }

    private function deterministicProjectFollowUpActions(string $content, array $contextPayload, string $timezone): array
    {
        $normalized = str($content)->lower()->squish()->toString();
        if (! str_contains($normalized, 'project follow-up') || ! preg_match('/\b(calendar|focus block)\b/u', $normalized) || ! preg_match('/\btask\b/u', $normalized) || ! preg_match('/\breminder\b/u', $normalized)) {
            return [];
        }

        $now = $this->deterministicNow($contextPayload, $timezone);
        $friday = $this->nextWeekday($now, Carbon::FRIDAY)->setTime(9, 0);
        $thursday = $this->nextWeekday($now, Carbon::THURSDAY)->setTime(16, 0);
        if ($thursday->gte($friday)) {
            $thursday = $friday->copy()->subDay()->setTime(16, 0);
        }

        return [
            [
                'type' => 'calendar_event.create',
                'risk' => 'low',
                'client_action_key' => 'event_0',
                'parameters' => [
                    'title' => 'Project follow-up focus block',
                    'starts_at' => $friday->toIso8601String(),
                    'ends_at' => $friday->copy()->addHour()->toIso8601String(),
                ],
            ],
            [
                'type' => 'task.create',
                'risk' => 'low',
                'client_action_key' => 'task_0',
                'parameters' => [
                    'title' => 'Prepare notes for project follow-up',
                    'due_at' => $friday->copy()->subHour()->toIso8601String(),
                ],
            ],
            [
                'type' => 'reminder.create',
                'risk' => 'low',
                'client_action_key' => 'reminder_0',
                'parameters' => [
                    'title' => 'Prepare notes for project follow-up',
                    'remind_at' => $thursday->toIso8601String(),
                ],
            ],
        ];
    }

    private function nextWeekday(Carbon $now, int $weekday): Carbon
    {
        $date = $now->copy()->startOfDay();
        while ((int) $date->dayOfWeek !== $weekday || $date->lte($now->copy()->startOfDay())) {
            $date->addDay();
        }

        return $date;
    }

    private function deterministicDatedCalendarListActions(string $content, array $contextPayload, string $timezone): array
    {
        $normalized = str($content)->lower()->squish()->toString();
        if (! preg_match('/\b(add|create|schedule|put|block|book)\b/u', $normalized)) {
            return [];
        }
        if (! preg_match('/\b(calendar|schedule|event|events|appointment|appointments|meeting|meetings|block|items)\b/u', $normalized)) {
            return [];
        }

        if (! preg_match_all('/\b(?:\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[\/.-]\d{1,2}(?:[\/.-]\d{2,4})?)\b/u', $content, $dateMatches, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        if (count($dateMatches[0]) < 2) {
            return [];
        }

        $actions = [];
        $dateTokens = $dateMatches[0];
        foreach ($dateTokens as $index => $dateMatch) {
            $dateText = (string) ($dateMatch[0] ?? '');
            $segmentStart = (int) ($dateMatch[1] ?? 0) + strlen($dateText);
            $segmentEnd = isset($dateTokens[$index + 1])
                ? (int) ($dateTokens[$index + 1][1] ?? strlen($content))
                : strlen($content);
            $segment = substr($content, $segmentStart, max(0, $segmentEnd - $segmentStart));
            $parsed = $this->deterministicCalendarListSegment($dateText, $segment, $contextPayload, $timezone);
            if ($parsed === null) {
                continue;
            }

            $actions[] = [
                'type' => 'calendar_event.create',
                'risk' => 'low',
                'client_action_key' => 'event_'.$index,
                'parameters' => $parsed,
            ];
        }

        return count($actions) === count($dateTokens) ? $actions : [];
    }

    private function deterministicCalendarListSegment(string $dateText, string $segment, array $contextPayload, string $timezone): ?array
    {
        $cleaned = trim($segment);
        $cleaned = preg_replace('/^\s*(?:,|;|\band\b|\bthen\b)+\s*/iu', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s*(?:,|;|\band\b)+\s*$/iu', '', $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned);
        if ($cleaned === '') {
            return null;
        }

        if (! preg_match_all('/\b(?:at\s+)?(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/iu', $cleaned, $timeMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $timeMatch = $timeMatches[count($timeMatches) - 1];
        $timeText = (string) ($timeMatch[0][0] ?? '');
        $timeOffset = (int) ($timeMatch[0][1] ?? 0);
        $beforeTime = trim(substr($cleaned, 0, $timeOffset));
        $beforeTime = preg_replace('/\s*(?:,|;|\band\b)+\s*$/iu', '', $beforeTime) ?? $beforeTime;
        $beforeTime = preg_replace('/\s+\bat\s*$/iu', '', $beforeTime) ?? $beforeTime;
        $beforeTime = trim($beforeTime, " \t\n\r\0\x0B,;.");
        if ($beforeTime === '') {
            return null;
        }

        $title = $beforeTime;
        $location = null;
        if (preg_match('/^(.+?)\s+at\s+(.+)$/iu', $beforeTime, $locationMatch)) {
            $candidateTitle = trim((string) ($locationMatch[1] ?? ''));
            $candidateLocation = trim((string) ($locationMatch[2] ?? ''), " \t\n\r\0\x0B,;.");
            if ($candidateTitle !== '' && $this->looksLikeLocationText($candidateLocation)) {
                $title = $candidateTitle;
                $location = $candidateLocation;
            }
        }

        $startsAt = $this->deterministicLocalIsoFromDateAndTime($dateText, $timeText, $contextPayload, $timezone);
        if ($startsAt === null) {
            return null;
        }

        $parameters = [
            'title' => $this->cleanDeterministicTitle($title),
            'starts_at' => $startsAt,
        ];
        if ($parameters['title'] === '') {
            return null;
        }
        if ($location !== null && $location !== '') {
            $parameters['location'] = $location;
        }

        return $parameters;
    }

    private function looksLikeLocationText(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        return (bool) preg_match('/\d/u', $text)
            || (bool) preg_match('/\b(st|street|ave|avenue|rd|road|dr|drive|ln|lane|blvd|boulevard|ct|court|cir|circle|way|place|pl|pkwy|parkway|hwy|highway|suite|ste|unit|apt)\b\.?/iu', $text);
    }

    private function deterministicLocalIsoFromDateAndTime(string $dateText, string $timeText, array $contextPayload, string $timezone): ?string
    {
        $now = $this->deterministicNow($contextPayload, $timezone);
        $date = $this->deterministicDateFromToken($dateText, $now, $timezone);
        if ($date === null) {
            return null;
        }
        if (! preg_match('/(\d{1,2})(?::(\d{2}))?\s*(am|pm)/iu', $timeText, $timeMatch)) {
            return null;
        }

        $hour = (int) ($timeMatch[1] ?? 0);
        $minute = (int) ($timeMatch[2] ?? 0);
        $meridiem = mb_strtolower((string) ($timeMatch[3] ?? ''));
        if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59) {
            return null;
        }
        if ($meridiem === 'pm' && $hour !== 12) {
            $hour += 12;
        } elseif ($meridiem === 'am' && $hour === 12) {
            $hour = 0;
        }

        return $date->setTime($hour, $minute)->toIso8601String();
    }

    private function deterministicDateFromToken(string $dateText, Carbon $now, string $timezone): ?Carbon
    {
        $dateText = trim($dateText);
        try {
            if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/u', $dateText, $match)) {
                return Carbon::create((int) $match[1], (int) $match[2], (int) $match[3], 0, 0, 0, $timezone);
            }

            if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})(?:[\/.-](\d{2,4}))?$/u', $dateText, $match)) {
                $year = isset($match[3]) && $match[3] !== ''
                    ? (int) $match[3]
                    : (int) $now->year;
                if ($year < 100) {
                    $year += 2000;
                }
                $date = Carbon::create($year, (int) $match[1], (int) $match[2], 0, 0, 0, $timezone);
                if (! isset($match[3]) && $date->lt($now->copy()->startOfDay())) {
                    $date->addYear();
                }

                return $date;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function deterministicNow(array $contextPayload, string $timezone): Carbon
    {
        $clientNow = data_get($contextPayload, 'temporal_context.client_context.current_local_time');
        if (is_string($clientNow) && trim($clientNow) !== '') {
            try {
                return Carbon::parse($clientNow, $timezone)->setTimezone($timezone);
            } catch (\Throwable) {
                // Fall through to server time in the same display timezone.
            }
        }

        return now()->setTimezone($timezone);
    }

    private function textRequestsDelete(string $normalized): bool
    {
        return (bool) preg_match('/\b(delete|remove|cancel|clear)\b/u', $normalized);
    }

    private function textRequestsUpdate(string $normalized): bool
    {
        return (bool) preg_match('/\b(update|edit|change|move|reschedule|rename|complete|finish|mark)\b/u', $normalized);
    }

    private function deterministicDeleteActions(ConversationSession $session, string $content, array $contextPayload): array
    {
        $normalized = str($content)->lower()->squish()->toString();
        $wantsCalendar = (bool) preg_match('/\b(calendar|event|events|appointment|meeting|block|schedule)\b/u', $normalized);
        $wantsReminder = (bool) preg_match('/\b(reminder|reminders|remind)\b/u', $normalized);
        $wantsTask = (bool) preg_match('/\b(task|tasks|todo|to-do|to do)\b/u', $normalized);
        $wantsNote = (bool) preg_match('/\b(note|notes|list)\b/u', $normalized);
        $targetDate = $this->deterministicTargetDate($content, $contextPayload);
        $actions = [];
        $resolvedCalendar = null;

        if ($wantsCalendar) {
            $resolvedCalendar = $this->resolveCalendarEventForText($session, $content, $targetDate);
            if ($resolvedCalendar instanceof CalendarEvent) {
                $actions[] = [
                    'type' => 'calendar_event.delete',
                    'risk' => 'low',
                    'client_action_key' => 'delete_event_0',
                    'parameters' => [
                        'id' => $resolvedCalendar->id,
                        'title' => $resolvedCalendar->title,
                        'delete_linked_reminders' => ! $wantsReminder,
                    ],
                ];
            }
        }

        if ($wantsReminder) {
            $resolvedReminder = $this->resolveReminderForText($session, $content, $targetDate, $resolvedCalendar);
            if ($resolvedReminder instanceof Reminder) {
                $actions[] = [
                    'type' => 'reminder.delete',
                    'risk' => 'low',
                    'client_action_key' => 'delete_reminder_'.count($actions),
                    'parameters' => [
                        'id' => $resolvedReminder->id,
                        'title' => $resolvedReminder->title,
                    ],
                ];
            }
        }

        if ($wantsTask) {
            $resolvedTask = $this->resolveTaskForText($session, $content, $targetDate);
            if ($resolvedTask instanceof Task) {
                $actions[] = [
                    'type' => 'task.delete',
                    'risk' => 'low',
                    'client_action_key' => 'delete_task_'.count($actions),
                    'parameters' => [
                        'id' => $resolvedTask->id,
                        'title' => $resolvedTask->title,
                    ],
                ];
            }
        }

        if ($wantsNote) {
            $resolvedNote = $this->resolveNoteForText($session, $content);
            if ($resolvedNote instanceof Note) {
                $actions[] = [
                    'type' => 'note.delete',
                    'risk' => 'low',
                    'client_action_key' => 'delete_note_'.count($actions),
                    'parameters' => [
                        'id' => $resolvedNote->id,
                        'title' => $resolvedNote->title,
                    ],
                ];
            }
        }

        return $actions;
    }

    private function deterministicUpdateActions(ConversationSession $session, string $content, array $contextPayload): array
    {
        $normalized = str($content)->lower()->squish()->toString();
        $targetDate = $this->deterministicTargetDate($content, $contextPayload);
        $actions = [];

        $moveEventActions = $this->deterministicMoveEventWithOptionalReminderActions($session, $content, $contextPayload, $targetDate);
        if ($moveEventActions !== []) {
            return $moveEventActions;
        }

        if (preg_match('/\b(mark|complete|finish)\b/u', $normalized)) {
            if (preg_match('/\b(task|tasks|todo|to-do|to do)\b/u', $normalized)) {
                $task = $this->resolveTaskForText($session, $content, $targetDate);
                if ($task instanceof Task) {
                    return [[
                        'type' => 'task.update',
                        'risk' => 'low',
                        'client_action_key' => 'update_task_0',
                        'parameters' => [
                            'id' => $task->id,
                            'title' => $task->title,
                            'status' => 'completed',
                        ],
                    ]];
                }
            }

            if (preg_match('/\b(reminder|reminders)\b/u', $normalized)) {
                $reminder = $this->resolveReminderForText($session, $content, $targetDate);
                if ($reminder instanceof Reminder) {
                    return [[
                        'type' => 'reminder.update',
                        'risk' => 'low',
                        'client_action_key' => 'update_reminder_0',
                        'parameters' => [
                            'id' => $reminder->id,
                            'title' => $reminder->title,
                            'status' => 'completed',
                        ],
                    ]];
                }
            }
        }

        if (preg_match('/\brename\b.+?\bto\b\s+(.+)$/iu', $content, $match)) {
            $newTitle = $this->cleanDeterministicTitle((string) ($match[1] ?? ''));
            if ($newTitle !== '') {
                if (preg_match('/\b(calendar|event|appointment|meeting|block)\b/u', $normalized)) {
                    $event = $this->resolveCalendarEventForText($session, $content, $targetDate);
                    if ($event instanceof CalendarEvent) {
                        $actions[] = [
                            'type' => 'calendar_event.update',
                            'risk' => 'low',
                            'client_action_key' => 'update_event_0',
                            'parameters' => ['id' => $event->id, 'title' => $newTitle],
                        ];
                    }
                } elseif (preg_match('/\b(reminder|reminders)\b/u', $normalized)) {
                    $reminder = $this->resolveReminderForText($session, $content, $targetDate);
                    if ($reminder instanceof Reminder) {
                        $actions[] = [
                            'type' => 'reminder.update',
                            'risk' => 'low',
                            'client_action_key' => 'update_reminder_0',
                            'parameters' => ['id' => $reminder->id, 'title' => $newTitle],
                        ];
                    }
                } elseif (preg_match('/\b(task|tasks|todo|to-do|to do)\b/u', $normalized)) {
                    $task = $this->resolveTaskForText($session, $content, $targetDate);
                    if ($task instanceof Task) {
                        $actions[] = [
                            'type' => 'task.update',
                            'risk' => 'low',
                            'client_action_key' => 'update_task_0',
                            'parameters' => ['id' => $task->id, 'title' => $newTitle],
                        ];
                    }
                }
            }
        }

        return $actions;
    }

    private function deterministicMoveEventWithOptionalReminderActions(ConversationSession $session, string $content, array $contextPayload, ?Carbon $targetDate): array
    {
        $normalized = str($content)->lower()->squish()->toString();
        if (! preg_match('/\b(move|reschedule|change)\b/u', $normalized)) {
            return [];
        }
        if (! preg_match('/\b(calendar|event|events|appointment|meeting|block|workout|exercise)\b/u', $normalized)) {
            return [];
        }
        if (! preg_match('/\b(?:to|at|for)\s+(\d{1,2}(?::\d{2})?\s*(?:am|pm))\b/iu', $content, $timeMatch)) {
            return [];
        }

        $event = $this->resolveCalendarEventForMoveRequest($session, $content, $targetDate);
        if (! $event instanceof CalendarEvent || ! $event->starts_at) {
            return [];
        }

        $timezone = $this->sessionDisplayTimezone($session);
        $baseDate = $targetDate instanceof Carbon
            ? $targetDate->copy()->setTimezone($timezone)
            : $event->starts_at->copy()->setTimezone($timezone);
        $startsAt = $this->deterministicDateWithMeridiemTime($baseDate, (string) ($timeMatch[1] ?? ''), $timezone);
        if (! $startsAt instanceof Carbon) {
            return [];
        }

        $durationSeconds = 3600;
        if ($event->ends_at instanceof Carbon) {
            $durationSeconds = max(900, $event->starts_at->diffInSeconds($event->ends_at));
        }

        $actions[] = [
            'type' => 'calendar_event.update',
            'risk' => 'low',
            'client_action_key' => 'update_event_0',
            'parameters' => [
                'id' => $event->id,
                'title' => $event->title,
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $startsAt->copy()->addSeconds($durationSeconds)->toIso8601String(),
            ],
        ];

        if (preg_match('/\b(reminder|reminders|remind)\b/u', $normalized)) {
            $minutesBefore = 15;
            if (preg_match('/\b(\d{1,3})\s*(?:minute|minutes|min|mins)\s+before\b/iu', $content, $beforeMatch)) {
                $minutesBefore = max(1, min(1440, (int) ($beforeMatch[1] ?? 15)));
            }

            $actions[] = [
                'type' => 'reminder.create',
                'risk' => 'low',
                'client_action_key' => 'reminder_0',
                'parameters' => [
                    'title' => 'Reminder: '.$event->title,
                    'calendar_event_id' => $event->id,
                    'remind_at' => $startsAt->copy()->subMinutes($minutesBefore)->toIso8601String(),
                ],
            ];
        }

        return $actions;
    }

    private function collectDeterministicCreateMatches(array &$matches, string $content, string $pattern, string $type, string $timezone): void
    {
        if (! preg_match_all($pattern, $content, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($found as $match) {
            $title = $this->cleanDeterministicTitle((string) ($match[1][0] ?? ''));
            if ($title === '') {
                continue;
            }

            $parameters = ['title' => $title];
            if ($type === 'calendar_event.create') {
                $startsAt = $this->deterministicLocalIso((string) ($match[2][0] ?? ''), $timezone);
                $endsAt = $this->deterministicLocalIso((string) ($match[3][0] ?? ''), $timezone);
                if ($startsAt === null) {
                    continue;
                }
                $parameters['starts_at'] = $startsAt;
                if ($endsAt !== null) {
                    $parameters['ends_at'] = $endsAt;
                }
            } elseif ($type === 'reminder.create') {
                $remindAt = $this->deterministicLocalIso((string) ($match[2][0] ?? ''), $timezone);
                if ($remindAt === null) {
                    continue;
                }
                $parameters['remind_at'] = $remindAt;
            } elseif ($type === 'task.create') {
                $dueAt = $this->deterministicLocalIso((string) ($match[2][0] ?? ''), $timezone);
                if ($dueAt !== null) {
                    $parameters['due_at'] = $dueAt;
                }
            }

            $matches[] = [
                'offset' => (int) ($match[0][1] ?? 0),
                'action' => [
                    'type' => $type,
                    'risk' => 'low',
                    'parameters' => $parameters,
                ],
            ];
        }
    }

    private function deterministicTargetDate(string $content, array $contextPayload): ?Carbon
    {
        $timezone = $this->displayTimezoneFromClientContext((array) data_get($contextPayload, 'temporal_context.client_context', []))
            ?? (string) data_get($contextPayload, 'agent_profile.settings.timezone', config('app.timezone', 'UTC'));
        if (! $this->validTimezone($timezone)) {
            $timezone = (string) config('app.timezone', 'UTC');
        }

        $now = now()->setTimezone($timezone);
        $clientNow = data_get($contextPayload, 'temporal_context.client_context.current_local_time');
        if (is_string($clientNow) && trim($clientNow) !== '') {
            try {
                $now = Carbon::parse($clientNow, $timezone);
            } catch (\Throwable) {
                $now = now()->setTimezone($timezone);
            }
        }

        $lower = mb_strtolower($content);
        $phrase = null;
        if (preg_match('/\b(today|tomorrow|next\s+(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)|(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)|(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\s+\d{1,2}(?:,\s*\d{4})?)\b/iu', $lower, $match)) {
            $phrase = (string) $match[1];
        }

        if ($phrase === null) {
            return null;
        }

        try {
            if ($phrase === 'today') {
                return $now->copy()->startOfDay();
            }
            if ($phrase === 'tomorrow') {
                return $now->copy()->addDay()->startOfDay();
            }
            if (preg_match('/^next\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/iu', $phrase, $weekdayMatch)) {
                return $this->nextWeekdayFrom($now, (string) $weekdayMatch[1]);
            }
            if (preg_match('/^(monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/iu', $phrase, $weekdayMatch)) {
                return $this->nextWeekdayFrom($now, (string) $weekdayMatch[1], allowToday: true);
            }

            return Carbon::parse($phrase, $timezone)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function nextWeekdayFrom(Carbon $now, string $weekday, bool $allowToday = false): Carbon
    {
        $target = [
            'sunday' => Carbon::SUNDAY,
            'monday' => Carbon::MONDAY,
            'tuesday' => Carbon::TUESDAY,
            'wednesday' => Carbon::WEDNESDAY,
            'thursday' => Carbon::THURSDAY,
            'friday' => Carbon::FRIDAY,
            'saturday' => Carbon::SATURDAY,
        ][mb_strtolower($weekday)] ?? null;

        if ($target === null) {
            return $now->copy()->startOfDay();
        }

        $date = $now->copy()->startOfDay();
        if (! $allowToday || $date->dayOfWeek !== $target) {
            do {
                $date->addDay();
            } while ($date->dayOfWeek !== $target);
        }

        return $date;
    }

    private function resolveCalendarEventForText(ConversationSession $session, string $content, ?Carbon $targetDate): ?CalendarEvent
    {
        $query = CalendarEvent::query()
            ->where('user_id', $session->user_id);
        if ($session->workspace_id) {
            $query->where('workspace_id', $session->workspace_id);
        }
        if ($targetDate instanceof Carbon) {
            $query->whereBetween('starts_at', [
                $targetDate->copy()->startOfDay()->utc(),
                $targetDate->copy()->endOfDay()->utc(),
            ]);
        } else {
            $query->where('starts_at', '>=', now()->subDays(7));
        }

        $sessionResolved = $this->bestScoredModelForText(
            (clone $query)->where('conversation_session_id', $session->id)->latest('starts_at')->limit(20)->get(),
            $content,
            ['title']
        );
        if ($sessionResolved instanceof CalendarEvent) {
            return $sessionResolved;
        }

        return $this->bestScoredModelForText($query->latest('starts_at')->limit(20)->get(), $content, ['title']);
    }

    private function resolveCalendarEventForMoveRequest(ConversationSession $session, string $content, ?Carbon $targetDate): ?CalendarEvent
    {
        $resolved = $this->resolveCalendarEventForText($session, $content, $targetDate);
        if ($resolved instanceof CalendarEvent) {
            return $resolved;
        }

        $normalized = str($content)->lower()->squish()->toString();
        $titleHint = null;
        if (preg_match('/\b(workout|exercise)\b/u', $normalized, $match)) {
            $titleHint = (string) $match[1];
        }
        if ($titleHint === null || $titleHint === '') {
            return null;
        }

        $query = CalendarEvent::query()
            ->where('user_id', $session->user_id);
        if ($session->workspace_id) {
            $query->where('workspace_id', $session->workspace_id);
        }
        if ($targetDate instanceof Carbon) {
            $query->whereBetween('starts_at', [
                $targetDate->copy()->startOfDay()->utc(),
                $targetDate->copy()->endOfDay()->utc(),
            ]);
        } else {
            $query->where('starts_at', '>=', now()->subDays(1));
        }

        return $query
            ->where('title', 'like', '%'.$titleHint.'%')
            ->orderBy('starts_at')
            ->first();
    }

    private function resolveReminderForText(ConversationSession $session, string $content, ?Carbon $targetDate, ?CalendarEvent $calendarEvent = null): ?Reminder
    {
        if ($calendarEvent instanceof CalendarEvent) {
            $linked = Reminder::query()
                ->where('user_id', $session->user_id)
                ->where('workspace_id', $calendarEvent->workspace_id)
                ->where('calendar_event_id', $calendarEvent->id)
                ->orderBy('remind_at')
                ->first();
            if ($linked instanceof Reminder) {
                return $linked;
            }
        }

        $query = Reminder::query()
            ->where('user_id', $session->user_id);
        if ($session->workspace_id) {
            $query->where('workspace_id', $session->workspace_id);
        }
        if ($targetDate instanceof Carbon) {
            $query->whereBetween('remind_at', [
                $targetDate->copy()->subDay()->startOfDay()->utc(),
                $targetDate->copy()->endOfDay()->utc(),
            ]);
        } else {
            $query->where('remind_at', '>=', now()->subDays(7));
        }

        $sessionResolved = $this->bestScoredModelForText(
            (clone $query)->where('conversation_session_id', $session->id)->orderBy('remind_at')->limit(30)->get(),
            $content,
            ['title', 'notes']
        );
        if ($sessionResolved instanceof Reminder) {
            return $sessionResolved;
        }

        return $this->bestScoredModelForText($query->orderBy('remind_at')->limit(30)->get(), $content, ['title', 'notes']);
    }

    private function resolveTaskForText(ConversationSession $session, string $content, ?Carbon $targetDate): ?Task
    {
        $query = Task::query()
            ->where('user_id', $session->user_id)
            ->where('status', '!=', 'completed');
        if ($session->workspace_id) {
            $query->where('workspace_id', $session->workspace_id);
        }
        if ($targetDate instanceof Carbon) {
            $query->where(function ($query) use ($targetDate): void {
                $query->whereBetween('due_at', [
                    $targetDate->copy()->startOfDay()->utc(),
                    $targetDate->copy()->endOfDay()->utc(),
                ])->orWhereNull('due_at');
            });
        }

        return $this->bestScoredModelForText($query->latest('updated_at')->limit(30)->get(), $content, ['title', 'notes']);
    }

    private function resolveNoteForText(ConversationSession $session, string $content): ?Note
    {
        $query = Note::query()
            ->where('user_id', $session->user_id);
        if ($session->workspace_id) {
            $query->where('workspace_id', $session->workspace_id);
        }

        return $this->bestScoredModelForText($query->latest('updated_at')->limit(30)->get(), $content, ['title', 'plain_text']);
    }

    private function bestScoredModelForText(Collection $models, string $content, array $fields): mixed
    {
        $tokens = $this->significantTextTokens($content);
        if ($tokens === [] || $models->isEmpty()) {
            return null;
        }

        $scored = $models
            ->map(function (mixed $model) use ($tokens, $fields): array {
                $haystack = collect($fields)
                    ->map(fn (string $field): string => mb_strtolower((string) data_get($model, $field, '')))
                    ->implode(' ');
                $score = collect($tokens)
                    ->filter(fn (string $token): bool => str_contains($haystack, $token))
                    ->count();

                return ['model' => $model, 'score' => $score];
            })
            ->filter(fn (array $row): bool => $row['score'] >= min(2, count($tokens)))
            ->sortByDesc('score')
            ->values();

        if ($scored->isEmpty()) {
            return null;
        }

        $topScore = $scored->first()['score'];
        $top = $scored->filter(fn (array $row): bool => $row['score'] === $topScore)->values();

        return $top->count() === 1 ? $top->first()['model'] : null;
    }

    /**
     * @return array<int, string>
     */
    private function significantTextTokens(string $content): array
    {
        $stopWords = [
            'the', 'a', 'an', 'and', 'or', 'for', 'that', 'this', 'those', 'these', 'my', 'our',
            'please', 'can', 'you', 'to', 'from', 'on', 'in', 'at', 'of', 'with', 'next', 'today',
            'tomorrow', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
            'remove', 'delete', 'cancel', 'clear', 'update', 'edit', 'change', 'move', 'reschedule',
            'rename', 'mark', 'complete', 'finish', 'event', 'events', 'calendar', 'block', 'schedule',
            'appointment', 'meeting', 'reminder', 'reminders', 'remind', 'task', 'tasks', 'todo',
            'note', 'notes', 'list', 'due',
        ];

        return collect(preg_split('/[^\pL\pN]+/u', mb_strtolower($content)) ?: [])
            ->map(fn (string $token): string => trim($token))
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3 && ! in_array($token, $stopWords, true))
            ->unique()
            ->take(8)
            ->values()
            ->all();
    }

    private function cleanDeterministicTitle(string $title): string
    {
        return str($title)
            ->replaceMatches('/\s+/', ' ')
            ->trim(" \t\n\r\0\x0B,.;:")
            ->toString();
    }

    private function deterministicLocalIso(string $value, string $timezone): ?string
    {
        $value = trim($value, " \t\n\r\0\x0B,.;");
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, $timezone)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function deterministicDateWithMeridiemTime(Carbon $date, string $timeText, string $timezone): ?Carbon
    {
        if (! preg_match('/^\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)\s*$/iu', $timeText, $match)) {
            return null;
        }

        $hour = (int) ($match[1] ?? 0);
        $minute = (int) ($match[2] ?? 0);
        if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59) {
            return null;
        }

        $meridiem = mb_strtolower((string) ($match[3] ?? ''));
        if ($meridiem === 'pm' && $hour !== 12) {
            $hour += 12;
        } elseif ($meridiem === 'am' && $hour === 12) {
            $hour = 0;
        }

        return $date->copy()->setTimezone($timezone)->setTime($hour, $minute);
    }

    private function localPlannerResponse(array $plannerRoute, array $actions): array
    {
        return [
            'id' => 'local-crud-planner',
            'model' => (string) ($plannerRoute['model'] ?? 'local-crud-planner'),
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode(['actions' => $actions], JSON_THROW_ON_ERROR),
                ],
            ]],
            'usage' => [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ],
        ];
    }

    private function plannerActionsFromResponse(array $response): array
    {
        $content = $this->normalizedPlannerJson((string) data_get($response, 'choices.0.message.content', ''));
        if ($content === '') {
            return [];
        }

        try {
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        $rawActions = is_array($decoded) && is_array($decoded['actions'] ?? null) ? $decoded['actions'] : [];

        return collect($rawActions)
            ->map(fn (mixed $action, int $index): ?array => is_array($action) ? $this->normalizePlannerAction($action, $index) : null)
            ->filter()
            ->values()
            ->all();
    }

    private function normalizedPlannerJson(string $content): string
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $trimmed, $match)) {
            return trim($match[1]);
        }

        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');
        if ($start !== false && $end !== false && $end >= $start) {
            return substr($trimmed, $start, $end - $start + 1);
        }

        return $trimmed;
    }

    private function normalizePlannerAction(array $action, int $index): ?array
    {
        $type = $this->normalizePlannerActionType((string) ($action['type'] ?? $action['action_type'] ?? ''));
        if (! in_array($type, [
            'calendar_event.create',
            'calendar_event.update',
            'calendar_event.delete',
            'reminder.create',
            'reminder.update',
            'reminder.delete',
            'task.create',
            'task.update',
            'task.delete',
            'note.create',
            'note.update',
            'note.delete',
        ], true)) {
            return null;
        }

        $parameters = is_array($action['parameters'] ?? null) ? $action['parameters'] : [];
        if (! isset($parameters['title']) && isset($action['title'])) {
            $parameters['title'] = $action['title'];
        }

        $normalized = [
            'type' => $type,
            'risk' => 'low',
            'parameters' => $parameters,
            'client_action_key' => is_scalar($action['client_action_key'] ?? null) ? (string) $action['client_action_key'] : 'action_'.$index,
        ];

        if (is_scalar($action['related_action_key'] ?? null)) {
            $normalized['related_action_key'] = (string) $action['related_action_key'];
        }
        if (is_scalar($parameters['related_action_key'] ?? null)) {
            $normalized['related_action_key'] = (string) $parameters['related_action_key'];
            unset($normalized['parameters']['related_action_key']);
        }

        return $this->plannerActionHasRequiredFields($normalized) ? $normalized : null;
    }

    private function normalizePlannerActionType(string $type): string
    {
        $type = strtolower(trim($type));

        return match ($type) {
            'calendar.create', 'event.create', 'schedule.create', 'block.create', 'calendar_event' => 'calendar_event.create',
            'calendar.update', 'event.update', 'schedule.update', 'block.update' => 'calendar_event.update',
            'calendar.delete', 'event.delete', 'schedule.delete', 'block.delete' => 'calendar_event.delete',
            'reminder', 'reminder.add' => 'reminder.create',
            'reminder.edit' => 'reminder.update',
            'reminder.remove' => 'reminder.delete',
            'task', 'todo.create', 'to_do.create', 'task.add' => 'task.create',
            'todo.update', 'to_do.update', 'task.edit' => 'task.update',
            'todo.delete', 'to_do.delete', 'task.remove' => 'task.delete',
            'note', 'note.add' => 'note.create',
            'note.edit' => 'note.update',
            'note.remove' => 'note.delete',
            default => $type,
        };
    }

    private function plannerActionHasRequiredFields(array $action): bool
    {
        $type = (string) ($action['type'] ?? '');
        $parameters = is_array($action['parameters'] ?? null) ? $action['parameters'] : [];
        $hasTitle = filled($parameters['title'] ?? null);

        return match ($type) {
            'calendar_event.create' => $hasTitle && filled($parameters['starts_at'] ?? null),
            'calendar_event.update' => filled($parameters['id'] ?? null) && $this->plannerActionHasUpdateFields($parameters, ['id']),
            'calendar_event.delete' => filled($parameters['id'] ?? null),
            'reminder.create' => $hasTitle && filled($parameters['remind_at'] ?? null),
            'reminder.update' => filled($parameters['id'] ?? null) && $this->plannerActionHasUpdateFields($parameters, ['id']),
            'reminder.delete' => filled($parameters['id'] ?? null),
            'task.create', 'note.create' => $hasTitle,
            'task.update', 'note.update' => filled($parameters['id'] ?? null) && $this->plannerActionHasUpdateFields($parameters, ['id']),
            'task.delete', 'note.delete' => filled($parameters['id'] ?? null),
            default => false,
        };
    }

    private function plannerActionHasUpdateFields(array $parameters, array $excluded): bool
    {
        foreach ($parameters as $key => $value) {
            if (in_array((string) $key, $excluded, true)) {
                continue;
            }
            if (filled($value)) {
                return true;
            }
        }

        return false;
    }

    private function plannerActionsMeetExpected(array $actions, int $expectedWriteActionCount): bool
    {
        if ($actions === [] || count($actions) < $expectedWriteActionCount) {
            return false;
        }

        return ! collect($actions)->contains(fn (mixed $action): bool => ! is_array($action) || ! $this->plannerActionHasRequiredFields($action));
    }

    private function recordPlannedCrudWorkItems(ConversationSession $session, ConversationMessage $message, array $actions): array
    {
        $planned = [];

        foreach ($actions as $order => $action) {
            if (! is_array($action)) {
                continue;
            }

            $parameters = is_array($action['parameters'] ?? null) ? $action['parameters'] : [];
            $label = $this->workItemLabelForAction((string) ($action['type'] ?? ''), $parameters);
            if ($label === '') {
                continue;
            }

            $workItem = [
                'work_item_id' => 'crud-plan-'.$message->id.'-'.$order,
                'work_order' => $order,
                'action_type' => (string) ($action['type'] ?? ''),
                'label' => $label,
                'message_id' => $message->id,
                'user_message_id' => $message->id,
            ];
            $workItem['event'] = $this->recordEvent(
                $session,
                'assistant.work_item.planned',
                $workItem,
                'assistant.work',
                'planned'
            );

            $planned[$order] = $workItem;
        }

        return $planned;
    }

    private function correlatePlannerAction(array $action, array $createdCalendarEventsByKey, ?int $lastCreatedCalendarEventId): array
    {
        if (($action['type'] ?? null) !== 'reminder.create') {
            return $action;
        }

        $parameters = is_array($action['parameters'] ?? null) ? $action['parameters'] : [];
        if (! empty($parameters['calendar_event_id'])) {
            return $action;
        }

        $relatedKey = is_scalar($action['related_action_key'] ?? null) ? (string) $action['related_action_key'] : '';
        if ($relatedKey !== '' && isset($createdCalendarEventsByKey[$relatedKey])) {
            $parameters['calendar_event_id'] = $createdCalendarEventsByKey[$relatedKey];
        } elseif ($lastCreatedCalendarEventId !== null) {
            $parameters['calendar_event_id'] = $lastCreatedCalendarEventId;
        }

        $action['parameters'] = $parameters;

        return $action;
    }

    private function executePlannerAction(ConversationSession $session, array $action, ?array $workItem): array
    {
        $this->recordEvent($session, 'runtime.planner_action_started', $this->payloadWithWorkItem([
            'action_type' => (string) ($action['type'] ?? ''),
        ], $workItem), 'hermes.planner', 'started');

        try {
            $events = $this->executeActionEnvelopeAtomically($session, $action, $workItem);
        } catch (\Throwable $exception) {
            $event = $this->recordEvent($session, 'runtime.planner_action_failed', $this->payloadWithWorkItem([
                'action_type' => (string) ($action['type'] ?? ''),
                'reason' => $exception->getMessage(),
            ], $workItem), 'hermes.planner', 'failed');

            Log::error('Planner action failed.', [
                'session_id' => $session->id,
                'action_type' => (string) ($action['type'] ?? ''),
                'exception' => $exception->getMessage(),
            ]);

            return [[$action], collect([$event]), [
                'ok' => false,
                'action_type' => (string) ($action['type'] ?? ''),
                'message' => $exception->getMessage(),
                'events' => [[
                    'event_type' => $event->event_type,
                    'tool_name' => $event->tool_name,
                    'status' => $event->status,
                    'payload' => $event->payload,
                ]],
            ]];
        }

        $failed = $events->contains(fn (ActivityEvent $event): bool => $event->status === 'failed');

        $this->recordEvent($session, 'runtime.planner_action_completed', $this->payloadWithWorkItem([
            'action_type' => (string) ($action['type'] ?? ''),
            'event_count' => $events->count(),
        ], $workItem), 'hermes.planner', $failed ? 'failed' : 'succeeded');

        return [[$action], $events, [
            'ok' => ! $failed,
            'action_type' => (string) ($action['type'] ?? ''),
            'message' => $failed ? $this->failedEventMessage($events) : '',
            'events' => $events->map(fn (ActivityEvent $event): array => [
                'event_type' => $event->event_type,
                'tool_name' => $event->tool_name,
                'status' => $event->status,
                'payload' => $event->payload,
            ])->values()->all(),
        ]];
    }

    private function executeActionEnvelopeAtomically(ConversationSession $session, array $action, ?array $workItem): Collection
    {
        $events = $this->actionService->applyEnvelope($session, ['actions' => [$action]]);

        if ($workItem !== null) {
            $events = $events
                ->map(fn (ActivityEvent $event): ActivityEvent => $this->attachWorkItemToEvent($event, $workItem))
                ->values();
        }

        return $events;
    }

    private function createdCalendarEventIdFromEvents(Collection $events): ?int
    {
        foreach ($events as $event) {
            if (! $event instanceof ActivityEvent || $event->event_type !== 'assistant.calendar_event.created') {
                continue;
            }

            $id = data_get($event->payload ?? [], 'calendar_event_id');
            if (is_numeric($id)) {
                return (int) $id;
            }
        }

        return null;
    }

    private function plannerActionKey(array $action): string
    {
        return is_scalar($action['client_action_key'] ?? null) ? (string) $action['client_action_key'] : '';
    }

    private function recordPlannedNativeWorkItems(ConversationSession $session, ConversationMessage $userMessage, array $toolCalls): array
    {
        $planned = [];
        $order = 0;

        foreach ($toolCalls as $toolCall) {
            if (! is_array($toolCall)) {
                continue;
            }

            $toolCallId = (string) ($toolCall['id'] ?? '');
            if ($toolCallId === '') {
                $toolCallId = 'tool-'.count($planned);
            }

            $name = (string) data_get($toolCall, 'function.name', '');
            if ($name === '' || $this->isNativeReadTool($name)) {
                continue;
            }

            $actionType = $this->actionTypeForNativeTool($name);
            if ($actionType === null) {
                continue;
            }

            $arguments = $this->decodeToolArguments((string) data_get($toolCall, 'function.arguments', '{}'));
            $label = $this->workItemLabelForAction($actionType, $arguments);
            if ($label === '') {
                continue;
            }

            $workItem = [
                'work_item_id' => 'tool-call-'.$toolCallId,
                'work_order' => $order++,
                'action_type' => $actionType,
                'label' => $label,
                'message_id' => $userMessage->id,
                'user_message_id' => $userMessage->id,
            ];
            $workItem['event'] = $this->recordEvent(
                $session,
                'assistant.work_item.planned',
                $workItem,
                'assistant.work',
                'planned'
            );

            $planned[$toolCallId] = $workItem;
        }

        return $planned;
    }

    private function workItemLabelForAction(string $actionType, array $arguments): string
    {
        $verb = match (true) {
            $actionType === 'memory.create', $actionType === 'workspace_memory.note' => 'Save',
            $actionType === 'memory.update' => 'Update',
            $actionType === 'memory.delete' => 'Forget',
            str_ends_with($actionType, '.create') || $actionType === 'calendar.create' => 'Create',
            str_ends_with($actionType, '.update') || $actionType === 'calendar.update' => 'Update',
            str_ends_with($actionType, '.delete') || $actionType === 'calendar.delete' => 'Delete',
            default => 'Work on',
        };

        $target = match (true) {
            str_starts_with($actionType, 'task.') => 'task',
            str_starts_with($actionType, 'reminder.') => 'reminder',
            str_starts_with($actionType, 'calendar') => 'calendar event',
            str_starts_with($actionType, 'note_folder.') => 'folder',
            str_starts_with($actionType, 'note.') => 'note',
            str_starts_with($actionType, 'event_category.') => 'category',
            str_starts_with($actionType, 'memory.'), $actionType === 'workspace_memory.note' => 'knowledge',
            str_starts_with($actionType, 'blocker.') => 'blocker',
            str_starts_with($actionType, 'approval.') => 'approval',
            default => str_replace(['_', '.'], ' ', $actionType),
        };

        $subject = $this->workItemSubjectForAction($actionType, $arguments);
        $label = trim($verb.' '.$target);

        return $subject === '' ? $label : $label.': '.$subject;
    }

    private function workItemSubjectForAction(string $actionType, array $arguments): string
    {
        $value = $arguments['title']
            ?? $arguments['match_title']
            ?? $arguments['name']
            ?? $arguments['summary']
            ?? $arguments['reason']
            ?? $arguments['text']
            ?? null;

        if ($value === null && str_starts_with($actionType, 'calendar')) {
            $value = $arguments['event_title'] ?? $arguments['calendar_event_title'] ?? null;
        }

        if (! is_scalar($value)) {
            return '';
        }

        $subject = str((string) $value)
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        if ($subject === '') {
            return '';
        }

        if (str_starts_with($actionType, 'reminder.')) {
            $subject = preg_replace('/^reminder\s*:\s*/i', '', $subject) ?? $subject;
            $subject = preg_replace('/\s+reminder$/i', '', $subject) ?? $subject;
        }

        return str($subject)->limit(72, '...')->toString();
    }

    private function attachWorkItemToEvent(ActivityEvent $event, array $workItem): ActivityEvent
    {
        $event->update([
            'payload' => $this->payloadWithWorkItem($event->payload ?? [], $workItem),
        ]);

        return $event->refresh();
    }

    private function payloadWithWorkItem(array $payload, ?array $workItem): array
    {
        if ($workItem === null) {
            return $payload;
        }

        return array_merge($payload, [
            'work_item_id' => $workItem['work_item_id'] ?? null,
            'work_order' => $workItem['work_order'] ?? null,
            'work_label' => $workItem['label'] ?? null,
            'action_type' => $workItem['action_type'] ?? null,
            'message_id' => $workItem['message_id'] ?? null,
            'user_message_id' => $workItem['user_message_id'] ?? null,
        ]);
    }

    private function executeNativeToolCall(ConversationSession $session, ConversationMessage $userMessage, array $toolCall, ?array $workItem = null): array
    {
        $name = (string) data_get($toolCall, 'function.name', '');
        $arguments = $this->decodeToolArguments((string) data_get($toolCall, 'function.arguments', '{}'));
        if ($this->isNativeReadTool($name)) {
            $toolOutput = $this->executeNativeReadTool($session, $name, $arguments, $userMessage);
            $event = $this->recordEvent($session, 'assistant.read_tool.executed', [
                'tool_name' => $name,
                'ok' => (bool) ($toolOutput['ok'] ?? false),
                'provider' => $toolOutput['provider'] ?? null,
                'kind' => $toolOutput['kind'] ?? null,
            ], $name, ($toolOutput['ok'] ?? false) ? 'succeeded' : 'failed');

            return [[], collect([$event]), $toolOutput];
        }

        $actionType = $this->actionTypeForNativeTool($name);
        if ($actionType === null) {
            $event = $this->recordEvent($session, 'assistant.action.skipped', [
                'tool_name' => $name,
                'reason' => 'Unsupported native tool name.',
            ], 'native_tool', 'skipped');

            return [[], collect([$event]), [
                'ok' => false,
                'message' => 'Unsupported tool.',
                'events' => [['event_type' => $event->event_type, 'status' => $event->status]],
            ]];
        }

        if ($actionType === 'reminder.create' && $this->messageIsAffirmativeOnly($userMessage) && empty($arguments['calendar_event_id'])) {
            $linkedEvent = $this->recentCalendarEventForReminderConfirmation($session, $userMessage, $arguments);
            if ($linkedEvent instanceof CalendarEvent) {
                $arguments['calendar_event_id'] = $linkedEvent->id;
            }
        }

        $action = [
            'type' => $actionType,
            'risk' => 'low',
            'parameters' => $arguments,
        ];

        if ($actionType === 'calendar_event.create' && $this->messageIsAffirmativeOnly($userMessage)) {
            $duplicate = $this->duplicateCalendarCreateForConfirmation($session, $arguments);
            if ($duplicate instanceof CalendarEvent) {
                $event = $this->recordEvent($session, 'assistant.calendar_event.duplicate_skipped', $this->payloadWithWorkItem([
                    'calendar_event_id' => $duplicate->id,
                    'title' => $duplicate->title,
                    'starts_at' => $duplicate->starts_at?->toIso8601String(),
                    'ends_at' => $duplicate->ends_at?->toIso8601String(),
                    'reason' => 'matching event already created in this conversation',
                ], $workItem), 'calendar.create', 'skipped');

                return [[], collect([$event]), [
                    'ok' => true,
                    'skipped' => true,
                    'action_type' => $actionType,
                    'message' => 'Skipped duplicate calendar event create; the matching event already exists in this conversation.',
                    'existing_event' => [
                        'id' => $duplicate->id,
                        'title' => $duplicate->title,
                        'starts_at' => $duplicate->starts_at?->toIso8601String(),
                        'ends_at' => $duplicate->ends_at?->toIso8601String(),
                    ],
                    'events' => [[
                        'event_type' => $event->event_type,
                        'tool_name' => $event->tool_name,
                        'status' => $event->status,
                        'payload' => $event->payload,
                    ]],
                ]];
            }
        }

        try {
            $events = $this->executeActionEnvelopeAtomically($session, $action, $workItem);
        } catch (\Throwable $exception) {
            $event = $this->recordEvent($session, 'assistant.action.failed', $this->payloadWithWorkItem([
                'action_type' => $actionType,
                'tool_name' => $name,
                'reason' => $exception->getMessage(),
            ], $workItem), $name, 'failed');

            Log::error('Native tool action failed.', [
                'session_id' => $session->id,
                'tool_name' => $name,
                'action_type' => $actionType,
                'exception' => $exception->getMessage(),
            ]);

            return [[$action], collect([$event]), [
                'ok' => false,
                'action_type' => $actionType,
                'message' => $exception->getMessage(),
                'events' => [[
                    'event_type' => $event->event_type,
                    'tool_name' => $event->tool_name,
                    'status' => $event->status,
                    'payload' => $event->payload,
                ]],
            ]];
        }
        $failed = $events->contains(fn (ActivityEvent $event): bool => $event->status === 'failed');

        return [[$action], $events, [
            'ok' => ! $failed,
            'action_type' => $actionType,
            'message' => $failed ? $this->failedEventMessage($events) : '',
            'events' => $events->map(fn (ActivityEvent $event): array => [
                'event_type' => $event->event_type,
                'tool_name' => $event->tool_name,
                'status' => $event->status,
                'payload' => $event->payload,
            ])->values()->all(),
        ]];
    }

    private function failedEventMessage(Collection $events): string
    {
        foreach ($events as $event) {
            if (! $event instanceof ActivityEvent || $event->status !== 'failed') {
                continue;
            }

            $reason = data_get($event->payload ?? [], 'reason');
            if (is_string($reason) && trim($reason) !== '') {
                return trim($reason);
            }

            $message = data_get($event->payload ?? [], 'message');
            if (is_string($message) && trim($message) !== '') {
                return trim($message);
            }
        }

        return '';
    }

    private function decodeToolArguments(string $arguments): array
    {
        try {
            $decoded = json_decode($arguments !== '' ? $arguments : '{}', true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function actionTypeForNativeTool(string $name): ?string
    {
        return [
            'create_task' => 'task.create',
            'update_task' => 'task.update',
            'delete_task' => 'task.delete',
            'create_reminder' => 'reminder.create',
            'update_reminder' => 'reminder.update',
            'delete_reminder' => 'reminder.delete',
            'create_calendar_event' => 'calendar_event.create',
            'update_calendar_event' => 'calendar_event.update',
            'delete_calendar_event' => 'calendar_event.delete',
            'create_note' => 'note.create',
            'update_note' => 'note.update',
            'delete_note' => 'note.delete',
            'create_note_folder' => 'note_folder.create',
            'update_note_folder' => 'note_folder.update',
            'delete_note_folder' => 'note_folder.delete',
            'create_event_category' => 'event_category.create',
            'update_event_category' => 'event_category.update',
            'delete_event_category' => 'event_category.delete',
            'create_blocker' => 'blocker.create',
            'update_blocker' => 'blocker.update',
            'resolve_blocker' => 'blocker.resolve',
            'delete_blocker' => 'blocker.delete',
            'update_agent_profile' => 'agent_profile.update',
            'remember_memory' => 'memory.create',
            'update_memory' => 'memory.update',
            'forget_memory' => 'memory.delete',
            'note_workspace_memory' => 'workspace_memory.note',
            'update_conversation_session' => 'conversation_session.update',
            'create_activity_event' => 'activity_event.create',
        ][$name] ?? null;
    }

    private function isNativeReadTool(string $name): bool
    {
        return in_array($name, ['search_tasks', 'search_reminders', 'search_calendar_events', 'search_notes', 'search_memory', 'get_request_history', 'get_activity_timeline', 'get_day_context', 'external_lookup'], true);
    }

    private function messageIsAffirmativeOnly(ConversationMessage $message): bool
    {
        $content = str((string) $message->content)
            ->lower()
            ->replaceMatches('/[^\pL\pN\s]+/u', ' ')
            ->squish()
            ->toString();

        if ($content === '') {
            return false;
        }

        return (bool) preg_match('/^(yes|yeah|yep|yup|sure|ok|okay|please do|do it|go ahead|that works|sounds good|correct|right|exactly|affirmative|yes please|sure please)$/', $content);
    }

    private function shouldContinueAfterReadOnlyTerminal(ConversationMessage $userMessage, string $assistantContent, array $actions, array $toolOutputs, int $turn): bool
    {
        if ($turn >= 2 || $actions !== [] || trim($assistantContent) === '') {
            return false;
        }

        if (! $this->messageAppearsToRequestAppWrite($userMessage)) {
            return false;
        }

        return collect($toolOutputs)->contains(fn (mixed $output): bool => is_array($output)
            && in_array((string) ($output['tool'] ?? ''), [
                'search_tasks',
                'search_reminders',
                'search_calendar_events',
                'search_notes',
                'search_memory',
                'get_day_context',
            ], true));
    }

    private function shouldContinueAfterUnverifiedCompletionClaim(ConversationMessage $userMessage, string $assistantContent, array $actions, array $toolOutputs, int $turn): bool
    {
        if ($turn >= 2 || $actions !== [] || $toolOutputs !== [] || trim($assistantContent) === '') {
            return false;
        }

        if (! $this->messageAppearsToRequestAppWrite($userMessage)) {
            return false;
        }

        return $this->assistantClaimsPriorCompletion($assistantContent);
    }

    private function assistantClaimsPriorCompletion(string $content): bool
    {
        $normalized = str($content)
            ->lower()
            ->replaceMatches('/[^\pL\pN\s]+/u', ' ')
            ->squish()
            ->toString();

        if ($normalized === '' || ! preg_match('/\b(already|previously|earlier)\b/u', $normalized)) {
            return false;
        }

        return (bool) preg_match('/\b(already|previously|earlier)\b.{0,80}\b(created|added|scheduled|saved|made|set|moved|updated|changed|deleted|removed|cancelled|completed|did|done)\b/u', $normalized)
            || (bool) preg_match('/\b(created|added|scheduled|saved|made|set|moved|updated|changed|deleted|removed|cancelled|completed|did|done)\b.{0,80}\b(already|previously|earlier)\b/u', $normalized);
    }

    private function messageAppearsToRequestAppWrite(ConversationMessage $message): bool
    {
        $content = str((string) $message->content)
            ->lower()
            ->replaceMatches('/[^\pL\pN\s]+/u', ' ')
            ->squish()
            ->toString();

        if ($content === '') {
            return false;
        }

        $hasWriteVerb = (bool) preg_match('/\b(add|create|make|schedule|book|set|move|reschedule|change|update|edit|rename|delete|remove|cancel|clear|mark|complete|finish|pin|unpin|lock|unlock)\b/u', $content);
        $hasAppTarget = (bool) preg_match('/\b(event|events|calendar|calendars|appointment|appointments|meeting|meetings|task|tasks|todo|to\s+do|reminder|reminders|note|notes|folder|folders|list|lists)\b/u', $content);

        return $hasWriteVerb && $hasAppTarget;
    }

    private function readOnlyTerminalCorrectionPrompt(): string
    {
        return 'The user asked to change HeyBean app data, but only read tools have run so far. Use the read results already available in this conversation to call the necessary write tools now. If the target cannot be uniquely identified, ask one concise follow-up instead of ending with a lookup count. Do not say the work is complete until write tool results confirm it.';
    }

    private function unverifiedCompletionCorrectionPrompt(): string
    {
        return 'The user asked to change HeyBean app data, but the previous answer claimed prior completion without checking current app state or running write tools in this turn. Conversation history can be stale because the user may have deleted or changed those items. Do not rely on an earlier assistant message as proof. Use current read/write tools now: verify the requested records exist in current app state when needed, and create, update, or delete them if they are missing or still need changes. Only say the work is already complete if current tool results confirm the exact requested state.';
    }

    private function duplicateCalendarCreateForConfirmation(ConversationSession $session, array $arguments): ?CalendarEvent
    {
        $title = $this->normalizedComparisonText($arguments['title'] ?? null);
        $startsAt = $this->toolArgumentDateTime($arguments['starts_at'] ?? $arguments['start_at'] ?? null);
        if ($title === '' || ! $startsAt) {
            return null;
        }

        $endsAt = $this->toolArgumentDateTime($arguments['ends_at'] ?? $arguments['end_at'] ?? null);
        $recurrence = $this->normalizedComparisonText($arguments['recurrence'] ?? data_get($arguments, 'metadata.recurrence') ?? 'none') ?: 'none';

        return CalendarEvent::query()
            ->where('conversation_session_id', $session->id)
            ->where('user_id', $session->user_id)
            ->where('workspace_id', $session->workspace_id)
            ->where('created_at', '>=', now()->subHours(2))
            ->latest('id')
            ->limit(25)
            ->get()
            ->first(function (CalendarEvent $event) use ($title, $startsAt, $endsAt, $recurrence): bool {
                if ($this->normalizedComparisonText($event->title) !== $title) {
                    return false;
                }

                if (! $event->starts_at || ! $event->starts_at->copy()->utc()->equalTo($startsAt)) {
                    return false;
                }

                $eventEndsAt = $event->ends_at?->copy()->utc();
                if (($eventEndsAt === null) !== ($endsAt === null)) {
                    return false;
                }

                if ($eventEndsAt !== null && $endsAt !== null && ! $eventEndsAt->equalTo($endsAt)) {
                    return false;
                }

                $eventRecurrence = $this->normalizedComparisonText($event->recurrence ?: data_get($event->metadata ?? [], 'recurrence') ?: 'none') ?: 'none';

                return $eventRecurrence === $recurrence;
            });
    }

    private function recentCalendarEventForReminderConfirmation(ConversationSession $session, ConversationMessage $userMessage, array $arguments): ?CalendarEvent
    {
        $events = CalendarEvent::query()
            ->where('conversation_session_id', $session->id)
            ->where('user_id', $session->user_id)
            ->where('workspace_id', $session->workspace_id)
            ->where('created_at', '>=', now()->subHours(2))
            ->latest('id')
            ->limit(10)
            ->get();

        if ($events->isEmpty()) {
            return null;
        }

        $lastAssistantText = (string) $session->messages()
            ->where('id', '<', $userMessage->id)
            ->where('role', 'assistant')
            ->latest('id')
            ->value('content');

        $hint = $this->normalizedComparisonText(implode(' ', [
            $arguments['title'] ?? '',
            $arguments['notes'] ?? '',
            $lastAssistantText,
        ]));

        $matched = $events->first(function (CalendarEvent $event) use ($hint): bool {
            $title = $this->normalizedComparisonText($event->title);

            return $title !== '' && str_contains($hint, $title);
        });

        if ($matched instanceof CalendarEvent) {
            return $matched;
        }

        return $events->count() === 1 ? $events->first() : null;
    }

    private function toolArgumentDateTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizedComparisonText(mixed $value): string
    {
        if (is_array($value)) {
            $value = data_get($value, 'type') ?? data_get($value, 'frequency') ?? data_get($value, 'recurrence') ?? json_encode($value);
        }

        return str((string) $value)
            ->lower()
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }

    private function executeNativeReadTool(ConversationSession $session, string $name, array $arguments, ?ConversationMessage $userMessage = null): array
    {
        try {
            return match ($name) {
                'search_tasks' => $this->searchTasksForTool($session, $arguments),
                'search_reminders' => $this->searchRemindersForTool($session, $arguments),
                'search_calendar_events' => $this->searchCalendarEventsForTool($session, $arguments),
                'search_notes' => $this->searchNotesForTool($session, $arguments),
                'search_memory' => $this->searchMemoryForTool($session, $arguments),
                'get_request_history' => $this->requestHistoryForTool($session, $arguments, $userMessage),
                'get_activity_timeline' => $this->activityTimelineForTool($session, $arguments),
                'get_day_context' => $this->dayContextForTool($session, $arguments),
                'external_lookup' => $this->externalLookupForTool($session, $arguments),
                default => ['ok' => false, 'tool' => $name, 'error_code' => 'unsupported_read_tool'],
            };
        } catch (\Throwable $exception) {
            Log::warning('Hermes native read tool failed.', [
                'session_id' => $session->id,
                'tool' => $name,
                'exception' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'tool' => $name,
                'error_code' => 'read_tool_failed',
                'message' => 'I’m checking the latest app data now. I’ll ask for one more detail if I need it.',
            ];
        }
    }

    private function searchTasksForTool(ConversationSession $session, array $arguments): array
    {
        $workspaceId = $this->toolWorkspaceId($session, $arguments);
        $timezone = $this->sessionDisplayTimezone($session);
        $query = Task::query()->where('user_id', $session->user_id)->where('workspace_id', $workspaceId);
        if (! (bool) ($arguments['include_completed'] ?? false)) {
            $query->where('status', '!=', 'completed');
        }
        if (filled($arguments['status'] ?? null)) {
            $query->where('status', (string) $arguments['status']);
        }
        if (filled($arguments['query'] ?? null)) {
            $this->whereLooseTitle($query, (string) $arguments['query']);
        }
        [$from, $to] = $this->toolDateWindow($session, $arguments);
        if ($from && $to) {
            $query->where(function ($query) use ($from, $to): void {
                $query->whereBetween('due_at', [$from, $to])->orWhereNull('due_at');
            });
        }

        $items = $query->latest('is_critical')
            ->orderByRaw('case when due_at is null then 1 else 0 end')
            ->orderBy('due_at')
            ->latest('updated_at')
            ->limit($this->toolLimit($arguments))
            ->get(['id', 'title', 'type', 'status', 'category', 'color', 'is_critical', 'due_at', 'metadata'])
            ->map(fn (Task $task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'type' => $task->type,
                'status' => $task->status,
                'category' => $task->category,
                'color' => $task->color,
                'is_critical' => (bool) $task->is_critical,
                'due_at' => $this->localIso($task->due_at, $timezone),
                'due_at_utc' => $this->utcIso($task->due_at),
                'display_due_date' => $this->localDate($task->due_at, $timezone),
                'display_due_time' => $this->localTime($task->due_at, $timezone),
                'recurrence' => data_get($task->metadata ?? [], 'recurrence'),
            ])->values()->all();

        return $this->readToolResult('search_tasks', $items, $workspaceId, $timezone);
    }

    private function searchRemindersForTool(ConversationSession $session, array $arguments): array
    {
        $workspaceId = $this->toolWorkspaceId($session, $arguments);
        $timezone = $this->sessionDisplayTimezone($session);
        $query = Reminder::query()->where('user_id', $session->user_id)->where('workspace_id', $workspaceId);
        if (filled($arguments['status'] ?? null)) {
            $query->where('status', (string) $arguments['status']);
        }
        if (filled($arguments['query'] ?? null)) {
            $this->whereLooseTitle($query, (string) $arguments['query']);
        }
        [$from, $to] = $this->toolDateWindow($session, $arguments);
        if ($from && $to) {
            $query->whereBetween('remind_at', [$from, $to]);
        }

        $items = $query->latest('is_critical')
            ->orderBy('remind_at')
            ->limit($this->toolLimit($arguments))
            ->get(['id', 'title', 'status', 'category', 'color', 'is_critical', 'remind_at', 'metadata'])
            ->map(fn (Reminder $reminder): array => [
                'id' => $reminder->id,
                'title' => $reminder->title,
                'status' => $reminder->status,
                'category' => $reminder->category,
                'color' => $reminder->color,
                'is_critical' => (bool) $reminder->is_critical,
                'remind_at' => $this->localIso($reminder->remind_at, $timezone),
                'remind_at_utc' => $this->utcIso($reminder->remind_at),
                'display_remind_date' => $this->localDate($reminder->remind_at, $timezone),
                'display_remind_time' => $this->localTime($reminder->remind_at, $timezone),
                'recurrence' => data_get($reminder->metadata ?? [], 'recurrence'),
            ])->values()->all();

        return $this->readToolResult('search_reminders', $items, $workspaceId, $timezone);
    }

    private function searchCalendarEventsForTool(ConversationSession $session, array $arguments): array
    {
        $workspaceId = $this->toolWorkspaceId($session, $arguments);
        $timezone = $this->sessionDisplayTimezone($session);
        $query = CalendarEvent::query()->where('user_id', $session->user_id)->where('workspace_id', $workspaceId);
        if (filled($arguments['status'] ?? null)) {
            $query->where('status', (string) $arguments['status']);
        }
        if (filled($arguments['query'] ?? null)) {
            $this->whereLooseTitle($query, (string) $arguments['query']);
        }
        [$from, $to] = $this->toolDateWindow($session, $arguments);
        if ($from && $to) {
            $query->where(function ($query) use ($from, $to): void {
                $query->whereBetween('starts_at', [$from, $to])
                    ->orWhereBetween('ends_at', [$from, $to])
                    ->orWhere(function ($query) use ($from, $to): void {
                        $query->where('starts_at', '<=', $from)->where('ends_at', '>=', $to);
                    });
            });
        }

        $items = $query->orderBy('starts_at')
            ->limit($this->toolLimit($arguments))
            ->get(['id', 'title', 'category', 'color', 'is_critical', 'recurrence', 'status', 'starts_at', 'ends_at', 'metadata'])
            ->map(fn (CalendarEvent $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'category' => $event->category,
                'color' => $event->color,
                'is_critical' => (bool) $event->is_critical,
                'recurrence' => $event->recurrence,
                'status' => $event->status,
                'starts_at' => $this->calendarEventAllDay($event) ? $this->calendarEventDisplayStartDate($event, $timezone) : $this->localIso($event->starts_at, $timezone),
                'ends_at' => $this->calendarEventAllDay($event) ? $this->calendarEventDisplayEndDate($event, $timezone) : $this->localIso($event->ends_at, $timezone),
                'starts_at_utc' => $this->utcIso($event->starts_at),
                'ends_at_utc' => $this->utcIso($event->ends_at),
                'display_start_date' => $this->calendarEventDisplayStartDate($event, $timezone),
                'display_end_date' => $this->calendarEventDisplayEndDate($event, $timezone),
                'display_start_time' => $this->calendarEventAllDay($event) ? null : $this->localTime($event->starts_at, $timezone),
                'display_end_time' => $this->calendarEventAllDay($event) ? null : $this->localTime($event->ends_at, $timezone),
                'display_date_range' => $this->displayDateRange(
                    $this->calendarEventDisplayStartDate($event, $timezone),
                    $this->calendarEventDisplayEndDate($event, $timezone),
                ),
                'all_day' => $this->calendarEventAllDay($event),
            ])->values()->all();

        return $this->readToolResult('search_calendar_events', $items, $workspaceId, $timezone);
    }

    private function searchNotesForTool(ConversationSession $session, array $arguments): array
    {
        if (! $this->planLimits->canUseNotes(User::findOrFail($session->user_id))) {
            return [
                'ok' => false,
                'tool' => 'search_notes',
                'error_code' => 'subscription_limit_reached',
                'message' => 'Notes are available on Premium, Pro, and Enterprise plans.',
            ];
        }

        $workspaceId = $this->toolWorkspaceId($session, $arguments);
        $query = Note::query()->where('user_id', $session->user_id)->where('workspace_id', $workspaceId)->with('folder');
        if (filled($arguments['query'] ?? null)) {
            $this->whereLooseNote($query, (string) $arguments['query']);
        }
        if (filled($arguments['folder_id'] ?? null)) {
            $query->where('note_folder_id', (int) $arguments['folder_id']);
        }
        if (array_key_exists('pinned', $arguments)) {
            $query->where('is_pinned', (bool) $arguments['pinned']);
        }

        $items = $query->orderByDesc('is_pinned')
            ->latest('updated_at')
            ->limit($this->toolLimit($arguments))
            ->get(['id', 'note_folder_id', 'title', 'plain_text', 'is_pinned', 'updated_at'])
            ->map(fn (Note $note): array => [
                'id' => $note->id,
                'folder_id' => $note->note_folder_id,
                'folder' => $note->folder?->name,
                'title' => $note->title,
                'plain_text' => str((string) $note->plain_text)->squish()->limit(1600, '')->toString(),
                'is_pinned' => (bool) $note->is_pinned,
                'updated_at' => $note->updated_at?->toIso8601String(),
            ])->values()->all();

        return $this->readToolResult('search_notes', $items, $workspaceId);
    }

    private function searchMemoryForTool(ConversationSession $session, array $arguments): array
    {
        $user = User::findOrFail($session->user_id);
        $workspace = Workspace::findOrFail($this->toolWorkspaceId($session, $arguments));
        $this->workspaceService->authorizeMember($user, $workspace);
        $items = $this->memoryService->searchMemory($user, $workspace, $arguments);

        return $this->readToolResult('search_memory', $items, $workspace->id);
    }

    private function requestHistoryForTool(ConversationSession $session, array $arguments, ?ConversationMessage $userMessage = null): array
    {
        if (filled($arguments['workspace_id'] ?? null)) {
            $user = User::findOrFail($session->user_id);
            $this->workspaceService->authorizeMember($user, Workspace::findOrFail((int) $arguments['workspace_id']));
        }

        if ($userMessage instanceof ConversationMessage) {
            $arguments['exclude_message_id'] = $userMessage->id;
        }

        $items = $this->memoryService->requestHistory($session, $arguments);

        return [
            'ok' => true,
            'tool' => 'get_request_history',
            'workspace_id' => $arguments['workspace_id'] ?? $session->workspace_id,
            'items' => $items,
            'count' => count($items),
        ];
    }

    private function activityTimelineForTool(ConversationSession $session, array $arguments): array
    {
        if (filled($arguments['workspace_id'] ?? null)) {
            $user = User::findOrFail($session->user_id);
            $this->workspaceService->authorizeMember($user, Workspace::findOrFail((int) $arguments['workspace_id']));
        }

        $items = $this->memoryService->activityTimeline($session, $arguments);

        return [
            'ok' => true,
            'tool' => 'get_activity_timeline',
            'workspace_id' => $arguments['workspace_id'] ?? $session->workspace_id,
            'items' => $items,
            'count' => count($items),
        ];
    }

    private function dayContextForTool(ConversationSession $session, array $arguments): array
    {
        $date = trim((string) ($arguments['date'] ?? ''));
        if ($date === '') {
            return ['ok' => false, 'error_code' => 'missing_date', 'message' => 'A YYYY-MM-DD date is required.'];
        }

        $arguments['from_date'] = $date;
        $arguments['to_date'] = $date;
        $arguments['limit'] = 30;
        $workspaceId = $this->toolWorkspaceId($session, $arguments);

        return [
            'ok' => true,
            'tool' => 'get_day_context',
            'workspace_id' => $workspaceId,
            'date' => $date,
            'timezone' => $this->sessionDisplayTimezone($session),
            'tasks' => $this->searchTasksForTool($session, [...$arguments, 'include_completed' => false])['items'],
            'reminders' => $this->searchRemindersForTool($session, $arguments)['items'],
            'calendar_events' => $this->searchCalendarEventsForTool($session, $arguments)['items'],
        ];
    }

    private function externalLookupForTool(ConversationSession $session, array $arguments): array
    {
        return $this->liveLookup->lookup($session, $arguments);
    }

    private function readToolResult(string $tool, array $items, int $workspaceId, ?string $timezone = null): array
    {
        return [
            'ok' => true,
            'tool' => $tool,
            'workspace_id' => $workspaceId,
            'timezone' => $timezone,
            'count' => count($items),
            'items' => $items,
        ];
    }

    private function toolWorkspaceId(ConversationSession $session, array $arguments): int
    {
        $workspaceId = (int) ($arguments['workspace_id'] ?? $arguments['target_workspace_id'] ?? $session->workspace_id);
        $workspace = Workspace::findOrFail($workspaceId);
        $actor = User::findOrFail($session->user_id);
        $this->workspaceService->authorizeMember($actor, $workspace);

        return $workspace->id;
    }

    private function toolDateWindow(ConversationSession $session, array $arguments): array
    {
        $date = trim((string) ($arguments['date'] ?? ''));
        $fromDate = trim((string) ($arguments['from_date'] ?? ''));
        $toDate = trim((string) ($arguments['to_date'] ?? ''));
        if ($date !== '') {
            $fromDate = $date;
            $toDate = $date;
        }
        if ($fromDate === '' && $toDate === '') {
            return [null, null];
        }
        $fromDate = $fromDate !== '' ? $fromDate : $toDate;
        $toDate = $toDate !== '' ? $toDate : $fromDate;
        $timezone = $this->sessionDisplayTimezone($session);

        return [
            Carbon::parse($fromDate, $timezone)->startOfDay()->utc(),
            Carbon::parse($toDate, $timezone)->endOfDay()->utc(),
        ];
    }

    private function toolLimit(array $arguments): int
    {
        return max(1, min(50, (int) ($arguments['limit'] ?? 12)));
    }

    private function sessionDisplayTimezone(ConversationSession $session): string
    {
        $message = $session->messages()
            ->where('role', 'user')
            ->latest('id')
            ->first();
        $messageMetadata = is_array($message?->metadata) ? $message->metadata : [];
        $sessionMetadata = is_array($session->metadata) ? $session->metadata : [];

        foreach ([$messageMetadata, $sessionMetadata] as $metadata) {
            $context = data_get($metadata, 'client_context');
            if (! is_array($context)) {
                continue;
            }

            $timezone = $this->displayTimezoneFromClientContext($context);
            if ($timezone !== null) {
                return $timezone;
            }
        }

        $profileTimezone = data_get($this->profileForSession($session)?->settings ?? [], 'timezone');
        if (is_string($profileTimezone) && $this->validTimezone($profileTimezone)) {
            return $profileTimezone;
        }

        $fallback = (string) config('app.timezone', 'UTC');

        return $this->validTimezone($fallback) ? $fallback : 'UTC';
    }

    private function displayTimezoneFromClientContext(array $context): ?string
    {
        $timezone = data_get($context, 'timezone');
        if (is_string($timezone) && $this->validTimezone($timezone)) {
            return $timezone;
        }

        $offset = data_get($context, 'timezone_offset');
        if (is_string($offset) && preg_match('/^[+-]\d{2}:?\d{2}$/', $offset)) {
            return strlen($offset) === 5
                ? substr($offset, 0, 3).':'.substr($offset, 3, 2)
                : $offset;
        }

        $minutes = data_get($context, 'timezone_offset_minutes');
        if (is_numeric($minutes)) {
            $totalMinutes = (int) $minutes;
            $sign = $totalMinutes < 0 ? '-' : '+';
            $absolute = abs($totalMinutes);

            return sprintf('%s%02d:%02d', $sign, intdiv($absolute, 60), $absolute % 60);
        }

        return null;
    }

    private function validTimezone(string $timezone): bool
    {
        try {
            new \DateTimeZone($timezone);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function localIso(?Carbon $value, string $timezone): ?string
    {
        return $value?->copy()->setTimezone($timezone)->toIso8601String();
    }

    private function utcIso(?Carbon $value): ?string
    {
        return $value?->copy()->utc()->toIso8601String();
    }

    private function localDate(?Carbon $value, string $timezone): ?string
    {
        return $value?->copy()->setTimezone($timezone)->toDateString();
    }

    private function localTime(?Carbon $value, string $timezone): ?string
    {
        return $value?->copy()->setTimezone($timezone)->format('H:i');
    }

    private function calendarEventAllDay(CalendarEvent $event): bool
    {
        $value = data_get($event->metadata ?? [], 'all_day', data_get($event->metadata ?? [], 'allDay'));

        return $value === true
            || $value === 1
            || in_array(strtolower((string) $value), ['true', '1', 'yes'], true);
    }

    private function calendarEventDisplayEndDate(CalendarEvent $event, string $timezone): ?string
    {
        if (! $event->ends_at) {
            return $this->calendarEventDisplayStartDate($event, $timezone);
        }

        if ($this->calendarEventAllDay($event)) {
            return $this->calendarEventAllDayDisplayEndDate($event);
        }

        $end = $event->ends_at->copy()->setTimezone($timezone);

        return $end->toDateString();
    }

    private function calendarEventDisplayStartDate(CalendarEvent $event, string $timezone): ?string
    {
        if ($this->calendarEventAllDay($event)) {
            return $event->starts_at?->copy()->utc()->toDateString();
        }

        return $this->localDate($event->starts_at, $timezone);
    }

    private function calendarEventAllDayDisplayEndDate(CalendarEvent $event): ?string
    {
        if (! $event->ends_at) {
            return $this->calendarEventDisplayStartDate($event, 'UTC');
        }

        $end = $event->ends_at->copy()->utc();
        $start = $event->starts_at?->copy()->utc()->startOfDay();

        if ($start && $end->isStartOfDay() && $end->gt($start)) {
            return $end->copy()->subDay()->toDateString();
        }

        return $end->toDateString();
    }

    private function displayDateRange(?string $startDate, ?string $endDate): ?string
    {
        if ($startDate === null) {
            return $endDate;
        }

        if ($endDate === null || $endDate === $startDate) {
            return $startDate;
        }

        return "{$startDate} through {$endDate}";
    }

    private function whereLooseTitle(mixed $query, string $text): void
    {
        $terms = collect(preg_split('/\s+/u', mb_strtolower($text)) ?: [])
            ->map(fn (string $term): string => trim($term, " \t\n\r\0\x0B'\".,!?-"))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 3)
            ->unique()
            ->take(6)
            ->values();

        if ($terms->isEmpty()) {
            $query->where('title', 'like', '%'.addcslashes($text, '%_\\').'%');

            return;
        }

        $query->where(function ($query) use ($terms, $text): void {
            $query->where('title', 'like', '%'.addcslashes($text, '%_\\').'%');
            foreach ($terms as $term) {
                $query->orWhere('title', 'like', '%'.addcslashes($term, '%_\\').'%');
            }
        });
    }

    private function whereLooseNote(mixed $query, string $text): void
    {
        $terms = collect(preg_split('/\s+/u', mb_strtolower($text)) ?: [])
            ->map(fn (string $term): string => trim($term, " \t\n\r\0\x0B'\".,!?-"))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 2)
            ->unique()
            ->take(8)
            ->values();

        $query->where(function ($query) use ($terms, $text): void {
            $query->where('title', 'like', '%'.addcslashes($text, '%_\\').'%')
                ->orWhere('plain_text', 'like', '%'.addcslashes($text, '%_\\').'%')
                ->orWhereHas('folder', fn ($folderQuery) => $folderQuery->where('name', 'like', '%'.addcslashes($text, '%_\\').'%'));
            foreach ($terms as $term) {
                $escaped = addcslashes($term, '%_\\');
                $query->orWhere('title', 'like', '%'.$escaped.'%')
                    ->orWhere('plain_text', 'like', '%'.$escaped.'%');
            }
        });
    }

    private function providerApiKey(): string
    {
        return (string) config('services.hermes_runtime.api_key', '');
    }

    private function elapsedMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    private function toolRoutingMode(ConversationMessage $message): string
    {
        $text = mb_strtolower((string) $message->content);
        if ($text === '') {
            return 'full';
        }

        $externalTerms = [
            'weather', 'traffic', 'news', 'price', 'prices', 'stock', 'flight', 'hotel',
            'store hours', 'current', 'latest', 'near me', 'web', 'internet', 'look up',
        ];
        foreach ($externalTerms as $term) {
            if (str_contains($text, $term)) {
                return $this->messageAppearsToRequestAppWrite($message) ? 'full' : 'read_lookup';
            }
        }

        $profileOrMemoryTerms = [
            'remember that', 'forget that', 'forget my', 'bean preference', 'personality',
            'voice', 'model', 'workspace memory', 'what did i ask', 'what did i say',
        ];
        foreach ($profileOrMemoryTerms as $term) {
            if (str_contains($text, $term)) {
                return 'full';
            }
        }

        $appTerms = [
            'task', 'todo', 'to-do', 'reminder', 'remind', 'calendar', 'schedule',
            'event', 'block', 'appointment', 'meeting', 'note', 'list', 'due',
        ];
        foreach ($appTerms as $term) {
            if (str_contains($text, $term)) {
                return 'app_crud';
            }
        }

        $writeTerms = [
            'add ', 'create ', 'make ', 'set ', 'delete ', 'remove ', 'update ',
            'change ', 'move ', 'reschedule ', 'complete ', 'mark ',
        ];
        foreach ($writeTerms as $term) {
            if (str_contains($text, $term)) {
                return 'app_crud';
            }
        }

        return 'full';
    }

    private function toolPromptFor(ConversationSession $session, ConversationMessage $message, array $modelRoute): string
    {
        return json_encode([
            'runtime_context' => $this->toolContextPayload($session, $message),
            'conversation' => $this->modelConversationMessages($session, $message),
            'message' => $message->content,
            'message_metadata' => $message->metadata,
            'route' => $modelRoute,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<int, array{role:string, content:string}>
     */
    private function modelConversationMessages(ConversationSession $session, ConversationMessage $currentMessage): array
    {
        $history = $session->messages()
            ->where('id', '<', $currentMessage->id)
            ->whereIn('role', ['user', 'assistant'])
            ->latest('id')
            ->limit(12)
            ->get()
            ->sortBy('id')
            ->map(fn (ConversationMessage $message): array => [
                'role' => $message->role === 'assistant' ? 'assistant' : 'user',
                'content' => str($message->content)->squish()->limit(4000, '')->toString(),
            ])
            ->filter(fn (array $message): bool => $message['content'] !== '')
            ->values()
            ->all();

        $history[] = [
            'role' => 'user',
            'content' => str($currentMessage->content)->squish()->limit(4000, '')->toString(),
        ];

        return $history;
    }

    private function toolContextPayload(ConversationSession $session, ConversationMessage $message, string $toolMode = 'full'): array
    {
        $user = User::find($session->user_id);
        $workspace = $this->workspaceForSession($session, $user);
        $profile = $workspace ? $this->agentProfileService->ensureForWorkspace($workspace, $user) : $this->profileForSession($session);
        if ($user && $profile) {
            $user = $this->agentProfileService->syncUserOnboardingFlag($user, $profile);
        }
        $profileSettings = $profile->settings ?? [];
        $memoryContext = ($toolMode !== 'app_crud' && $user && $workspace)
            ? $this->memoryService->runtimeContext($user, $workspace, (string) $message->content, 8)
            : ['items' => [], 'summaries' => []];

        return [
            'session' => [
                'id' => $session->id,
                'workspace_id' => $workspace?->id,
                'title' => $session->title,
                'runtime_mode' => $session->runtime_mode,
            ],
            'user' => [
                'id' => $user?->id,
                'email' => $user?->email,
                'name' => $user?->name,
                'onboard_complete' => (bool) ($user?->onboard_complete ?? false),
            ],
            'workspace' => $workspace ? [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'type' => $workspace->type,
                'settings' => $workspace->settings,
            ] : null,
            'accessible_workspaces' => $user
                ? $this->workspaceService->accessibleWorkspaces($user)->map(fn (Workspace $workspace): array => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'type' => $workspace->type,
                    'role' => $workspace->getAttribute('role'),
                ])->values()->all()
                : [],
            'agent_profile' => $profile ? [
                'id' => $profile->id,
                'workspace_id' => $profile->workspace_id,
                'provider' => $profile->provider,
                'model' => $profile->model,
                'settings' => [
                    'timezone' => data_get($profileSettings, 'timezone'),
                    'personality_type' => data_get($profileSettings, 'personality_type'),
                    'personality_prompt' => data_get($profileSettings, 'personality_prompt'),
                    'onboarding' => data_get($profileSettings, 'onboarding'),
                    'memory' => [
                        'user_preferences' => data_get($profileSettings, 'memory.user_preferences'),
                    ],
                ],
            ] : null,
            'memory_context' => $memoryContext,
            'recent_assistant_actions' => $this->recentAssistantActionsForContext($session, $toolMode === 'app_crud' ? 5 : 10),
            'temporal_context' => [
                'server_now_utc' => now()->utc()->toIso8601String(),
                'server_today' => now()->toDateString(),
                'profile_timezone' => data_get($profileSettings, 'timezone'),
                'client_context' => is_array(data_get($message->metadata ?? [], 'client_context'))
                    ? data_get($message->metadata ?? [], 'client_context')
                    : null,
            ],
            'voice_context' => is_array(data_get($message->metadata ?? [], 'voice_context'))
                ? data_get($message->metadata ?? [], 'voice_context')
                : null,
        ];
    }

    private function recentAssistantActionsForContext(ConversationSession $session, int $limit = 10): array
    {
        return $session->activityEvents()
            ->where('event_type', 'like', 'assistant.%')
            ->whereIn('status', ['succeeded', 'recorded'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->map(fn (ActivityEvent $event): array => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'tool_name' => $event->tool_name,
                'status' => $event->status,
                'payload' => $event->payload,
                'created_at' => $event->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function toolSystemInstructions(): string
    {
        return <<<'PROMPT'
You are Bean, a capable human-like assistant inside the Hey Bean app.

You own intent and conversation. Interpret the user's message naturally, including messy wording, typos, shorthand, and voice transcription errors. Decide whether to answer directly, call read tools, call write tools, or ask one concise follow-up.

Use recent conversation turns to resolve follow-ups, corrections, and pronouns. If the user corrects the entity type, such as "task, not reminder", apply that correction to the prior request and search/use the corrected tool type.
Use runtime_context.recent_assistant_actions to resolve follow-up confirmations. If the user says only "yes", "sure", or another short confirmation after Bean asked about an additional related action, perform only the unresolved related action. Do not recreate tasks, reminders, notes, or events that recent_assistant_actions shows were already created or updated. When creating a reminder for an event that was just created, pass that event's calendar_event_id from recent_assistant_actions.
Conversation history and recent_assistant_actions can be stale because the user may delete, move, or edit items outside the chat. For any create, update, delete, schedule, move, or reminder request, current app state and tool results override earlier assistant claims. Never say an app change is already done solely because an earlier chat message said it was done; verify current state with tools when needed, and run the write tools if the requested records are missing or still need changes.
When a user asks what/when/where about an item and the type is ambiguous, search the relevant app records before saying it is not found. For task-like words or chores, search tasks; for reminder-like alarms, search reminders; for note, list, idea, writing, or saved text requests, search notes; if unclear, search the likely record types.

Use read tools when you need current app state. Use write tools when app state should change. Do not describe a dashboard change as complete unless a write tool result confirms it succeeded.
For clear create/update/delete requests with multiple independent app changes, emit every necessary write tool call in the same assistant tool response and in the user's requested order. Do not wait for one write result before planning the next unless the later write truly needs a database id that cannot be inferred. For a same-request reminder tied to a same-request calendar event, still plan both changes together with matching titles/times; Laravel can correlate the created records.

Laravel owns app mechanics: workspace access, database writes, validation, syncing, and tool results. Trust tool results. If a read/write tool says not found, ambiguous, or failed, respond naturally from that result.
Timed read-tool *_at timestamps are formatted in the tool result timezone and match the user-visible app. Use display_* fields for dates and times you mention to the user; use *_utc only as canonical instants. For all_day events, ignore midnight wall-clock internals and use display_start_date/display_end_date.
Use external_lookup for live information outside HeyBean, including current store hours, flights, hotel prices, weather, traffic, news, prices, availability, sports scores, or other current web facts. Do not invent current external facts. When external_lookup returns sources or citations, use them to answer concisely, include a brief source title or URL when useful, and mention uncertainty when results are incomplete.
If the user is only asking whether Bean can create, update, delete, schedule, remember, look up, or otherwise do something, answer the capability question directly. Do not call write tools unless the user includes concrete details that make it an actual request.

Prefer acting on clear scheduling/productivity requests instead of asking for optional details. Infer sensible defaults: current workspace, no category, not critical, no recurrence, and no extra notes unless the user says otherwise. For note requests, create/update/delete Notes records with note tools rather than memory unless the user explicitly asks Bean to remember a preference/fact. For durable user preferences, stable constraints, identity facts, project context, or explicit "remember/forget" requests, use search_memory plus remember_memory/update_memory/forget_memory. Do not save ordinary one-off requests as durable memory. For questions about what is scheduled, coming up, left, remaining, or still needs attention today or on a specific date, use get_day_context rather than request history. For "what did I ask/say/request/do" recall questions, use get_request_history or get_activity_timeline instead of guessing from recent context. For relative dates/times, use temporal_context.client_context and emit local ISO-8601 timestamps with the client's UTC offset.
When setting recurrence, always use recurrence as one of: none, daily, weekly, monthly, yearly, specific_days, or interval. For custom intervals like "every 3 days", set recurrence to interval and put interval plus interval_unit in metadata. Never put an object in recurrence.

Use the current workspace unless the user clearly names another accessible workspace. Adapt tone to agent_profile settings and memory. Do not run a first-login onboarding interview in normal chat; guided signup collects account and Bean preferences before the user reaches the dashboard. If the user explicitly asks to change Bean preferences later, use update_agent_profile with the requested settings.

If runtime_context.voice_context.quick_reply is present, Bean already said that sentence aloud in this same voice turn. Do not repeat it, paraphrase it, recap it, or begin with the same acknowledgement. Continue naturally from it with only new information, the result of any work, or a concise next step.
If runtime_context.voice_context.quick_reply_pending is true, a separate live voice sentence may be spoken while you work. Avoid generic openings and first-thought filler; give the substantive answer or result directly.
If runtime_context.voice_context.detailed_chat is true, the user already received a short spoken answer and the full response will primarily be read in chat. Provide the useful detailed answer without conversational filler or repeating the quick reply.
If runtime_context.voice_context.quick_reply_mode is acknowledged_background or pending_background, continue from the spoken acknowledgement with the actual result only. If it is summary_then_detail, write the full details for chat without restating the spoken summary. If the quick reply already answered the user, keep the final response minimal and add only genuinely new details. Never repeat a spoken list, summary, weather answer, calendar answer, task answer, or reminder answer unless the new response adds materially new information.

Respond to the user in natural language only. Never output JSON, tool arguments, ids, schema text, routing details, or debug text.
PROMPT;
    }

    private function nativeToolDefinitions(string $toolMode = 'full'): array
    {
        $tools = [
            $this->nativeTool('search_tasks', 'Search tasks in the current or specified workspace. Use this before updating a task when the matching item is not already known.', $this->searchTaskProperties()),
            $this->nativeTool('search_reminders', 'Search reminders in the current or specified workspace.', $this->searchReminderProperties()),
            $this->nativeTool('search_calendar_events', 'Search calendar events in the current or specified workspace.', $this->searchCalendarEventProperties()),
            $this->nativeTool('search_notes', 'Search notes by title, folder, or body text in the current or specified workspace.', $this->searchNoteProperties()),
            $this->nativeTool('search_memory', 'Search durable Bean memory for user preferences, constraints, projects, decisions, routines, and facts.', $this->searchMemoryProperties()),
            $this->nativeTool('get_request_history', 'Recall prior user requests from Bean conversation history by local date, text, or workspace. Use for questions like what did I ask yesterday; do not use for current schedule, remaining-today, or what-is-coming-up questions.', $this->historyProperties()),
            $this->nativeTool('get_activity_timeline', 'Recall Bean activity/tool outcomes by local date, event type, tool, or workspace.', $this->activityTimelineProperties()),
            $this->nativeTool('get_day_context', 'Get tasks, reminders, and calendar events for a specific local date.', $this->dayContextProperties(), ['date']),
            $this->nativeTool('external_lookup', 'Look up current external information outside HeyBean, such as live web facts, store hours, travel, weather, prices, traffic, news, sports, or current availability. Use this instead of guessing when the answer depends on current outside data. When the domain is clear, fill structured fields such as domain, intent, location, and date so fast providers can execute without reparsing the user sentence.', $this->externalLookupProperties(), ['query']),
            $this->nativeTool('create_task', 'Create a visible task in Hey Bean.', $this->taskProperties(), ['title']),
            $this->nativeTool('update_task', 'Update one existing task. Prefer id from search_tasks; otherwise use match_title and Laravel will require a unique match.', $this->taskProperties(requireId: false)),
            $this->nativeTool('delete_task', 'Delete one existing task by id.', $this->idProperties(), ['id']),
            $this->nativeTool('create_reminder', 'Create a visible reminder in Hey Bean.', $this->reminderProperties(), ['title', 'remind_at']),
            $this->nativeTool('update_reminder', 'Update one existing reminder by id.', $this->reminderProperties(requireId: true), ['id']),
            $this->nativeTool('delete_reminder', 'Delete one existing reminder by id.', $this->idProperties(), ['id']),
            $this->nativeTool('create_calendar_event', 'Create a visible calendar event in Hey Bean.', $this->calendarEventProperties(), ['title', 'starts_at']),
            $this->nativeTool('update_calendar_event', 'Update one existing calendar event. Prefer id; use match_title and from_date only when needed.', $this->calendarEventProperties(requireId: false)),
            $this->nativeTool('delete_calendar_event', 'Delete one existing calendar event by id.', $this->idProperties(), ['id']),
            $this->nativeTool('create_note', 'Create a note in Hey Bean Notes.', $this->noteProperties(), ['title']),
            $this->nativeTool('update_note', 'Update one existing note. Prefer id from search_notes; otherwise use match_title and Laravel will require a unique match.', $this->noteProperties(requireId: false)),
            $this->nativeTool('delete_note', 'Delete one existing note by id.', $this->idProperties(), ['id']),
            $this->nativeTool('create_note_folder', 'Create a Notes folder.', $this->noteFolderProperties(), ['name']),
            $this->nativeTool('update_note_folder', 'Update a Notes folder by id.', $this->noteFolderProperties(requireId: true), ['id']),
            $this->nativeTool('delete_note_folder', 'Delete a Notes folder by id. Notes inside it are kept and moved to All Notes.', $this->idProperties(), ['id']),
            $this->nativeTool('create_event_category', 'Create or save an event category.', $this->categoryProperties(), ['name']),
            $this->nativeTool('update_event_category', 'Update one event category by id.', $this->categoryProperties(requireId: true), ['id']),
            $this->nativeTool('delete_event_category', 'Delete one event category by id.', $this->idProperties(), ['id']),
            $this->nativeTool('create_blocker', 'Create a blocker or issue for the user.', $this->blockerProperties(), ['reason']),
            $this->nativeTool('update_blocker', 'Update a blocker by id.', $this->blockerProperties(requireId: true), ['id']),
            $this->nativeTool('resolve_blocker', 'Resolve a blocker by id.', $this->idProperties(), ['id']),
            $this->nativeTool('delete_blocker', 'Delete a blocker by id.', $this->idProperties(), ['id']),
            $this->nativeTool('update_agent_profile', 'Update Bean preferences, onboarding, model/profile settings, or memory settings.', $this->agentProfileProperties()),
            $this->nativeTool('remember_memory', 'Save a durable Bean memory only when the user explicitly asks Bean to remember something or the fact is clearly stable and useful.', $this->memoryProperties(), ['content']),
            $this->nativeTool('update_memory', 'Update one existing durable memory. Prefer id from search_memory; otherwise use query/title and Laravel will require a unique match.', $this->memoryProperties(requireId: false)),
            $this->nativeTool('forget_memory', 'Forget/archive one durable memory by id.', $this->idProperties(), ['id']),
            $this->nativeTool('note_workspace_memory', 'Save a durable workspace memory note when the user explicitly asks Bean to remember something.', [
                'note' => ['type' => 'string'],
                'workspace_id' => ['type' => 'integer'],
                'target_workspace_id' => ['type' => 'integer'],
            ], ['note']),
            $this->nativeTool('update_conversation_session', 'Update conversation session metadata.', [
                'title' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'runtime_mode' => ['type' => 'string'],
                'metadata' => ['type' => 'object', 'additionalProperties' => true],
            ]),
            $this->nativeTool('create_activity_event', 'Record a non-resource activity event.', [
                'event_type' => ['type' => 'string'],
                'tool_name' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'payload' => ['type' => 'object', 'additionalProperties' => true],
            ], ['event_type']),
        ];

        if ($toolMode === 'full') {
            return $tools;
        }

        $allowed = match ($toolMode) {
            'read_lookup' => [
                'search_tasks',
                'search_reminders',
                'search_calendar_events',
                'search_notes',
                'search_memory',
                'get_request_history',
                'get_activity_timeline',
                'get_day_context',
                'external_lookup',
            ],
            'app_crud' => [
                'search_tasks',
                'search_reminders',
                'search_calendar_events',
                'search_notes',
                'get_day_context',
                'create_task',
                'update_task',
                'delete_task',
                'create_reminder',
                'update_reminder',
                'delete_reminder',
                'create_calendar_event',
                'update_calendar_event',
                'delete_calendar_event',
                'create_note',
                'update_note',
                'delete_note',
            ],
            default => [],
        };

        return collect($tools)
            ->filter(fn (array $tool): bool => in_array((string) data_get($tool, 'function.name'), $allowed, true))
            ->values()
            ->all();
    }

    private function nativeTool(string $name, string $description, array $properties, array $required = []): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $required,
                    'additionalProperties' => true,
                ],
            ],
        ];
    }

    private function idProperties(): array
    {
        return ['id' => ['type' => 'integer']];
    }

    private function searchTaskProperties(): array
    {
        return [
            'query' => ['type' => 'string', 'description' => 'Title or natural words to search for.'],
            'status' => ['type' => 'string'],
            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD local date for due tasks.'],
            'from_date' => ['type' => 'string'],
            'to_date' => ['type' => 'string'],
            'include_completed' => ['type' => 'boolean'],
            'workspace_id' => ['type' => 'integer'],
            'limit' => ['type' => 'integer'],
        ];
    }

    private function searchReminderProperties(): array
    {
        return [
            'query' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD local date for reminders.'],
            'from_date' => ['type' => 'string'],
            'to_date' => ['type' => 'string'],
            'workspace_id' => ['type' => 'integer'],
            'limit' => ['type' => 'integer'],
        ];
    }

    private function searchCalendarEventProperties(): array
    {
        return [
            'query' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD local date for events overlapping that day.'],
            'from_date' => ['type' => 'string'],
            'to_date' => ['type' => 'string'],
            'workspace_id' => ['type' => 'integer'],
            'limit' => ['type' => 'integer'],
        ];
    }

    private function searchNoteProperties(): array
    {
        return [
            'query' => ['type' => 'string', 'description' => 'Title, folder, or body words to search for.'],
            'folder_id' => ['type' => 'integer'],
            'pinned' => ['type' => 'boolean'],
            'workspace_id' => ['type' => 'integer'],
            'limit' => ['type' => 'integer'],
        ];
    }

    private function searchMemoryProperties(): array
    {
        return [
            'query' => ['type' => 'string', 'description' => 'Words related to a preference, project, decision, routine, instruction, or fact.'],
            'type' => ['type' => 'string', 'description' => 'preference, identity, relationship, project, routine, constraint, decision, instruction, temporary_context, or fact.'],
            'status' => ['type' => 'string'],
            'include_archived' => ['type' => 'boolean'],
            'workspace_id' => ['type' => 'integer'],
            'limit' => ['type' => 'integer'],
        ];
    }

    private function historyProperties(): array
    {
        return [
            'query' => ['type' => 'string', 'description' => 'Optional words to search in prior user requests.'],
            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD local date.'],
            'from_date' => ['type' => 'string'],
            'to_date' => ['type' => 'string'],
            'workspace_id' => ['type' => 'integer'],
            'limit' => ['type' => 'integer'],
        ];
    }

    private function activityTimelineProperties(): array
    {
        return [
            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD local date.'],
            'from_date' => ['type' => 'string'],
            'to_date' => ['type' => 'string'],
            'event_type' => ['type' => 'string'],
            'tool_name' => ['type' => 'string'],
            'workspace_id' => ['type' => 'integer'],
            'limit' => ['type' => 'integer'],
        ];
    }

    private function dayContextProperties(): array
    {
        return [
            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD local date.'],
            'workspace_id' => ['type' => 'integer'],
        ];
    }

    private function externalLookupProperties(): array
    {
        return [
            'query' => ['type' => 'string', 'description' => 'Specific external lookup query. Include relevant names, dates, route, city, or domain details from the user request.'],
            'domain' => ['type' => 'string', 'description' => 'Structured lookup domain when clear, such as weather, places, web, news, finance, sports, travel, or general.'],
            'intent' => ['type' => 'string', 'description' => 'Structured lookup intent, such as current_weather, weather_forecast, nearby_place, business_hours, current_fact, or recent_news.'],
            'context' => ['type' => 'string', 'description' => 'Short reason or constraints for the lookup, such as one-way flights tomorrow or store closing time today.'],
            'location' => ['type' => 'string', 'description' => 'Optional user-provided or inferred location hint.'],
            'date' => ['type' => 'string', 'description' => 'Structured target date in YYYY-MM-DD local format when the request refers to a specific date, such as tomorrow or next Friday. Use runtime_context.temporal_context to resolve relative dates.'],
            'date_range' => ['type' => 'string', 'description' => 'Structured target date range when the request spans multiple days.'],
            'time' => ['type' => 'string', 'description' => 'Structured local time or part of day when relevant.'],
        ];
    }

    private function taskProperties(bool $requireId = false): array
    {
        return array_merge($requireId ? $this->idProperties() : ['id' => ['type' => 'integer'], 'match_title' => ['type' => 'string']], [
            'title' => ['type' => 'string'],
            'type' => ['type' => 'string'],
            'status' => ['type' => 'string', 'description' => 'Use completed when marking a task done; use open for active tasks.'],
            'notes' => ['type' => 'string'],
            'category' => ['type' => 'string'],
            'color' => ['type' => 'string'],
            'is_critical' => ['type' => 'boolean'],
            'due_at' => ['type' => 'string', 'description' => 'ISO-8601 local timestamp when applicable.'],
            'recurrence' => $this->recurrenceProperty(),
            'metadata' => ['type' => 'object', 'additionalProperties' => true],
        ]);
    }

    private function reminderProperties(bool $requireId = false): array
    {
        return array_merge($requireId ? $this->idProperties() : ['id' => ['type' => 'integer']], [
            'title' => ['type' => 'string'],
            'notes' => ['type' => 'string'],
            'category' => ['type' => 'string'],
            'color' => ['type' => 'string'],
            'is_critical' => ['type' => 'boolean'],
            'remind_at' => ['type' => 'string', 'description' => 'ISO-8601 local timestamp.'],
            'status' => ['type' => 'string'],
            'calendar_event_id' => ['type' => 'integer'],
            'recurrence' => $this->recurrenceProperty(),
            'metadata' => ['type' => 'object', 'additionalProperties' => true],
        ]);
    }

    private function calendarEventProperties(bool $requireId = false): array
    {
        return array_merge($requireId ? $this->idProperties() : ['id' => ['type' => 'integer'], 'match_title' => ['type' => 'string'], 'from_date' => ['type' => 'string']], [
            'title' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'location' => ['type' => 'string'],
            'category' => ['type' => 'string'],
            'color' => ['type' => 'string'],
            'is_critical' => ['type' => 'boolean'],
            'recurrence' => $this->recurrenceProperty(),
            'starts_at' => ['type' => 'string', 'description' => 'ISO-8601 local timestamp.'],
            'ends_at' => ['type' => 'string', 'description' => 'ISO-8601 local timestamp.'],
            'status' => ['type' => 'string'],
            'metadata' => ['type' => 'object', 'additionalProperties' => true],
            'workspace_id' => ['type' => 'integer'],
            'target_workspace_id' => ['type' => 'integer'],
        ]);
    }

    private function noteProperties(bool $requireId = false): array
    {
        return array_merge($requireId ? $this->idProperties() : ['id' => ['type' => 'integer'], 'match_title' => ['type' => 'string']], [
            'title' => ['type' => 'string'],
            'body_html' => ['type' => 'string'],
            'plain_text' => ['type' => 'string'],
            'folder_name' => ['type' => 'string'],
            'note_folder_id' => ['type' => 'integer'],
            'is_pinned' => ['type' => 'boolean'],
            'pinned' => ['type' => 'boolean'],
            'body_delta' => ['type' => 'object', 'additionalProperties' => true],
            'metadata' => ['type' => 'object', 'additionalProperties' => true],
            'workspace_id' => ['type' => 'integer'],
            'target_workspace_id' => ['type' => 'integer'],
        ]);
    }

    private function noteFolderProperties(bool $requireId = false): array
    {
        return array_merge($requireId ? $this->idProperties() : ['id' => ['type' => 'integer']], [
            'name' => ['type' => 'string'],
            'sort_order' => ['type' => 'integer'],
            'metadata' => ['type' => 'object', 'additionalProperties' => true],
            'workspace_id' => ['type' => 'integer'],
            'target_workspace_id' => ['type' => 'integer'],
        ]);
    }

    private function memoryProperties(bool $requireId = false): array
    {
        return array_merge($requireId ? $this->idProperties() : ['id' => ['type' => 'integer'], 'query' => ['type' => 'string'], 'match_title' => ['type' => 'string']], [
            'type' => ['type' => 'string', 'description' => 'preference, identity, relationship, project, routine, constraint, decision, instruction, temporary_context, or fact.'],
            'title' => ['type' => 'string'],
            'content' => ['type' => 'string'],
            'summary' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'visibility' => ['type' => 'string'],
            'confidence' => ['type' => 'integer'],
            'importance' => ['type' => 'integer'],
            'expires_at' => ['type' => 'string'],
            'metadata' => ['type' => 'object', 'additionalProperties' => true],
        ]);
    }

    private function recurrenceProperty(): array
    {
        return [
            'type' => ['string', 'null'],
            'enum' => ['none', 'daily', 'weekly', 'monthly', 'yearly', 'specific_days', 'interval', null],
            'description' => 'Use a string only. For "every N days/weeks/months/years", use interval and set metadata.interval plus metadata.interval_unit.',
        ];
    }

    private function categoryProperties(bool $requireId = false): array
    {
        return array_merge($requireId ? $this->idProperties() : ['id' => ['type' => 'integer']], [
            'name' => ['type' => 'string'],
            'color' => ['type' => 'string'],
            'metadata' => ['type' => 'object', 'additionalProperties' => true],
        ]);
    }

    private function blockerProperties(bool $requireId = false): array
    {
        return array_merge($requireId ? $this->idProperties() : ['id' => ['type' => 'integer']], [
            'reason' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'context' => ['type' => 'object', 'additionalProperties' => true],
        ]);
    }

    private function agentProfileProperties(): array
    {
        return [
            'slug' => ['type' => 'string'],
            'display_name' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'provider' => ['type' => 'string'],
            'model' => ['type' => 'string'],
            'router_mode' => ['type' => 'string'],
            'runtime_home' => ['type' => 'string'],
            'settings' => ['type' => 'object', 'additionalProperties' => true],
            'tool_policy' => ['type' => 'object', 'additionalProperties' => true],
            'approval_policy' => ['type' => 'object', 'additionalProperties' => true],
            'metadata' => ['type' => 'object', 'additionalProperties' => true],
        ];
    }

    private function toolRuntimeFailed(ConversationSession $session, ConversationMessage $userMessage, Collection $events, string $message, array $context): array
    {
        return DB::transaction(function () use ($session, $userMessage, $events, $message, $context): array {
            $failed = $this->recordEvent($session, 'runtime.tool_model_failed', [
                'message_id' => $userMessage->id,
                'reason' => $message,
                ...$context,
            ], 'hermes.tools', 'failed');

            $assistantMessage = ConversationMessage::create([
                'user_id' => $session->user_id,
                'conversation_session_id' => $session->id,
                'role' => 'assistant',
                'content' => $this->assistantSafeResponseContent($message),
                'metadata' => [
                    'runtime' => 'tools',
                    'provider' => config('services.hermes_runtime.default_provider'),
                    'failure' => $context,
                ],
            ]);

            $messageCompleted = $this->recordEvent($session, 'runtime.message_completed', [
                'message_id' => $assistantMessage->id,
            ]);

            $session->update(['status' => 'active', 'last_activity_at' => now()]);

            return [
                'status' => 'completed',
                'session' => $session->refresh(),
                'user_message' => $userMessage,
                'assistant_message' => $assistantMessage,
                'events' => $events->push($failed)->push($messageCompleted),
                'blocker' => null,
            ];
        });
    }

    private function toolRuntimeBlocked(ConversationSession $session, ConversationMessage $userMessage, Collection $events, string $message, array $context): array
    {
        return DB::transaction(function () use ($session, $userMessage, $events, $message, $context): array {
            $blocked = $this->recordEvent($session, 'runtime.usage_blocked', [
                'message_id' => $userMessage->id,
                'reason' => $message,
                ...$context,
            ], 'usage.guardrail', 'blocked');

            $assistantMessage = ConversationMessage::create([
                'user_id' => $session->user_id,
                'conversation_session_id' => $session->id,
                'role' => 'assistant',
                'content' => $message,
                'metadata' => [
                    'runtime' => 'tools',
                    'provider' => config('services.hermes_runtime.default_provider'),
                    'blocked' => $context,
                ],
            ]);

            $messageCompleted = $this->recordEvent($session, 'runtime.message_completed', [
                'message_id' => $assistantMessage->id,
            ]);

            $session->update(['status' => 'active', 'last_activity_at' => now()]);

            return [
                'status' => 'blocked',
                'session' => $session->refresh(),
                'user_message' => $userMessage,
                'assistant_message' => $assistantMessage,
                'events' => $events->push($blocked)->push($messageCompleted),
                'blocker' => null,
            ];
        });
    }

    private function toolRuntimeCancelled(ConversationSession $session, ConversationMessage $userMessage, Collection $events): array
    {
        return DB::transaction(function () use ($session, $userMessage, $events): array {
            $cancelled = $this->recordEvent($session, 'runtime.message_cancelled', [
                'message_id' => $userMessage->id,
            ], 'hermes.tools', 'cancelled');

            $session->update(['status' => 'active', 'last_activity_at' => now()]);

            return [
                'status' => 'cancelled',
                'session' => $session->refresh(),
                'user_message' => $userMessage,
                'assistant_message' => null,
                'events' => $events->push($cancelled),
                'blocker' => null,
            ];
        });
    }

    private function isCancellationRequested(ConversationSession $session): bool
    {
        return ConversationSession::query()
            ->whereKey($session->id)
            ->where('status', 'cancelling')
            ->exists();
    }

    /**
     * @return array{mode:string,tier:string,model:?string,billing_model:string,context_mode:string,reason:string}
     */
    private function modelRouteFor(ConversationSession $session): array
    {
        $defaultModel = $this->adminSettings->mainModel();
        $model = (string) ($this->adminSettings->mainModelOverride() ?: $this->profileForSession($session)?->model ?: $defaultModel);

        return [
            'mode' => 'agent',
            'tier' => 'agent',
            'model' => $model,
            'billing_model' => $model,
            'context_mode' => 'focused',
            'reason' => 'Laravel does not route by keyword; the configured agent model receives native app tools.',
        ];
    }

    private function profileForSession(ConversationSession $session): ?AgentProfile
    {
        $user = User::find($session->user_id);
        $workspace = $this->workspaceForSession($session, $user);

        if ($workspace) {
            return $this->agentProfileService->ensureForWorkspace($workspace, $user);
        }

        return AgentProfile::where('user_id', $session->user_id)->first();
    }

    private function workspaceForSession(ConversationSession $session, ?User $user = null): ?Workspace
    {
        $user ??= User::find($session->user_id);
        if (! $user) {
            return null;
        }

        return $this->workspaceService->resolveWorkspace($user, $session->workspace_id ?: null);
    }

    private function toolOutputsAllSuccessfulWrites(array $toolOutputs): bool
    {
        if ($toolOutputs === []) {
            return false;
        }

        foreach ($toolOutputs as $output) {
            if (! is_array($output) || ($output['ok'] ?? false) !== true || ! filled($output['action_type'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function expectedWriteActionCount(ConversationMessage $message): int
    {
        if ($this->messageIsCapabilityQuestion($message)) {
            return 0;
        }

        $rawText = str((string) $message->content)->lower()->squish()->toString();
        $text = str($rawText)
            ->lower()
            ->replaceMatches('/[^\pL\pN\s:.-]+/u', ' ')
            ->squish()
            ->toString();
        if ($text === '') {
            return 1;
        }

        $count = 0;
        if (preg_match('/\b(task|tasks|todo|to do|to-do)\b/', $text)) {
            $count++;
        }
        if (preg_match('/\b(reminder|reminders|remind me|remind)\b/', $text)) {
            $count++;
        }
        $hasExplicitCalendarTarget = (bool) preg_match('/\b(calendar|schedule|event|events|appointment|appointments|block)\b/', $text);
        $hasMeetingTarget = (bool) preg_match('/\b(meeting|meetings)\b/', $text)
            && ! (bool) preg_match('/\bmeeting\s+(agenda|prep|notes?)\b/', $text);
        if ($hasExplicitCalendarTarget || $hasMeetingTarget) {
            $count++;
        }
        if (
            preg_match('/\b(note|notes|folder|folders)\b/', $text)
            && (
                preg_match('/\b(create|make|add|write|save|pin|edit|update|delete|remove|move|lock)\s+(?:a\s+|an\s+|the\s+)?(?:note|notes|folder|folders)\b/', $text)
                || preg_match('/\b(?:note|notes|folder|folders)\s+(?:called|titled|named)\b/', $text)
                || preg_match('/\b(?:as|to)\s+(?:a\s+|the\s+)?(?:note|notes|folder|folders)\b/', $text)
            )
        ) {
            $count++;
        }

        if ($count === 0 && preg_match('/\b(add|create|make|set|update|change|move|delete|remove|complete|mark)\b/', $text)) {
            $count = 1;
        }

        $datedItemCount = preg_match_all('/\b(?:\d{1,2}[\/.-]\d{1,2}(?:[\/.-]\d{2,4})?|\d{4}-\d{2}-\d{2})\b/', $rawText);
        if ($datedItemCount > 1 && preg_match('/\b(calendar|schedule|event|events|appointment|meeting|block)\b/', $text)) {
            $count = max($count, $datedItemCount);
        }

        return max(1, min($count, 6));
    }

    private function messageIsCapabilityQuestion(ConversationMessage $message): bool
    {
        $text = str(str_replace('’', "'", (string) $message->content))
            ->lower()
            ->replaceMatches('/[^\pL\pN\s\'?.-]+/u', ' ')
            ->squish()
            ->toString();

        if ($text === '') {
            return false;
        }

        if (
            ! preg_match('/\?$/u', $text)
            && ! preg_match('/^(can|could|would|will|do|does|are|is)\b/u', $text)
        ) {
            return false;
        }

        if (preg_match('/\b(that says|saying|called|titled|named|at\s+\d|on\s+\d|tomorrow|today|tonight|this\s+(morning|afternoon|evening|week|month)|next\s+\w+|from\s+\d|to\s+\d|\d{1,2}:\d{2}|for\s+(tomorrow|today|tonight|next|monday|tuesday|wednesday|thursday|friday|saturday|sunday))\b/u', $text)) {
            return false;
        }

        return (bool) preg_match(
            '/^(can|could|would|will|do|does|are|is)\s+(you|bean)\b.{0,120}\b(create|add|make|schedule|book|set|update|change|move|reschedule|delete|remove|cancel|complete|mark|remember|forget|look up|find|search|sync|manage|handle|help)\b.{0,80}\??$/u',
            $text
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function directExternalLookupArguments(ConversationSession $session, ConversationMessage $message): ?array
    {
        if ($this->messageIsCapabilityQuestion($message) || $this->messageIsRequestHistoryRecall($message)) {
            return null;
        }

        $content = str((string) $message->content)->squish()->toString();
        if ($content === '') {
            return null;
        }

        $text = str(str_replace('’', "'", $content))
            ->lower()
            ->replaceMatches('/[^\pL\pN\s\'?.-]+/u', ' ')
            ->squish()
            ->toString();

        if ($this->directLookupTextLooksLikeAppWrite($text)) {
            return null;
        }

        $weatherArguments = $this->directWeatherLookupArguments($session, $content, $text);
        if ($weatherArguments !== null) {
            return $weatherArguments;
        }

        return $this->directPlacesLookupArguments($content, $text);
    }

    private function directLookupTextLooksLikeAppWrite(string $text): bool
    {
        return (bool) preg_match('/\b(add|create|make|schedule|book|set|move|update|delete|remove|cancel|complete|mark|save|pin|lock)\b/u', $text)
            && (bool) preg_match('/\b(task|tasks|reminder|reminders|note|notes|calendar|event|events|appointment|appointments|workspace|workspaces|folder|folders)\b/u', $text);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function directWeatherLookupArguments(ConversationSession $session, string $content, string $text): ?array
    {
        if (! (bool) config('services.hermes_runtime.weather_lookup_enabled', true)) {
            return null;
        }

        if (! preg_match('/\b(weather|forecast|temperature|temp|rain|raining|storm|storming|snow|snowing|humidity|wind|windy|cloudy|sunny)\b/u', $text)) {
            return null;
        }

        $location = $this->directWeatherLocation($content);
        if ($location === '') {
            return null;
        }

        $timezone = $this->sessionDisplayTimezone($session);
        $date = null;
        $intent = 'current_weather';
        if (preg_match('/\b(tomorrow|forecast)\b/u', $text)) {
            $intent = 'weather_forecast';
            $date = str_contains($text, 'tomorrow')
                ? now($this->validTimezone($timezone) ? $timezone : config('app.timezone'))->addDay()->toDateString()
                : null;
        }

        return array_filter([
            'query' => $content,
            'domain' => 'weather',
            'intent' => $intent,
            'location' => $location,
            'date' => $date,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function directWeatherLocation(string $content): string
    {
        $patterns = [
            '/\b(?:weather|forecast|temperature|temp|rain|raining|storm|storming|snow|snowing|humidity|wind|windy|cloudy|sunny)\b.*?\b(?:in|for|near|at)\s+(.+?)(?:\s+(?:right now|currently|now|today|tonight|tomorrow|this morning|this afternoon|this evening))?\s*[?.!]*$/iu',
            '/\b(?:in|for|near|at)\s+(.+?)(?:\s+(?:right now|currently|now|today|tonight|tomorrow|this morning|this afternoon|this evening))?\s*[?.!]*$/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $match)) {
                $candidate = trim((string) ($match[1] ?? ''));
                $candidate = preg_replace('/\b(right now|currently|now|today|tonight|tomorrow|this morning|this afternoon|this evening)\b/iu', '', $candidate) ?? $candidate;
                $candidate = preg_replace('/^\s*(?:in|for|near|at)\s+/iu', '', $candidate) ?? $candidate;
                $candidate = trim($candidate, " \t\n\r\0\x0B,.?!'\"");
                if ($candidate !== '' && ! preg_match('/\b(weather|forecast|temperature|temp)\b/iu', $candidate)) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function directPlacesLookupArguments(string $content, string $text): ?array
    {
        if (! (bool) config('services.hermes_runtime.google_places_enabled', true)) {
            return null;
        }

        if (! preg_match('/\b(nearest|closest|nearby|near me|near|around|local)\b/u', $text)) {
            return null;
        }

        if (! preg_match('/\b\d{5}(?:-\d{4})?\b/u', $text) && ! preg_match('/\b(?:near|around|in|to)\s+[a-z][a-z\s,.-]+$/iu', $content)) {
            return null;
        }

        if (preg_match('/\b(weather|forecast|temperature|temp|news|latest|stock|stocks|score|sports|price|prices)\b/u', $text)) {
            return null;
        }

        return [
            'query' => $content,
            'domain' => 'places',
            'intent' => 'nearby_place',
        ];
    }

    private function messageIsRequestHistoryRecall(ConversationMessage $message): bool
    {
        $text = str(str_replace('’', "'", (string) $message->content))
            ->lower()
            ->replaceMatches('/[^\pL\pN\s\'?.-]+/u', ' ')
            ->squish()
            ->toString();

        if ($text === '') {
            return false;
        }

        return (bool) preg_match('/\bwhat\s+(?:did|was)\s+(?:i|my)\b.{0,80}\b(?:ask|asked|request|requested)\b/u', $text)
            || (bool) preg_match('/\bwhat\s+request\s+did\s+i\s+make\b/u', $text)
            || (bool) preg_match('/\bwhich\s+request\s+did\s+i\s+make\b/u', $text)
            || (bool) preg_match('/\bwhat\s+was\s+my\s+earlier\s+request\b/u', $text);
    }

    private function requestHistoryRecallQuery(ConversationMessage $message): string
    {
        $content = str((string) $message->content)->squish()->toString();
        if ($content === '') {
            return '';
        }

        $content = preg_replace('/^\s*REQ-\d{3}\s*:\s*/iu', '', $content) ?: $content;

        if (preg_match_all('/\bREQ-\d{3}\b/iu', $content, $matches) && ! empty($matches[0])) {
            return strtoupper((string) end($matches[0]));
        }

        if (preg_match('/\babout\s+(.+?)(?:\s+earlier\b|\s+in\s+this\b|\s+if\s+any\b|\?|\.|$)/iu', $content, $match)) {
            $query = $this->cleanDeterministicTitle((string) ($match[1] ?? ''));
            if ($query !== '') {
                return $query;
            }
        }

        return $this->cleanDeterministicTitle(
            preg_replace('/\b(?:what|which|request|did|i|make|ask|asked|requested|earlier|smoke|run|include|the|req|number|if|any|there|was|none|say|so|clearly)\b/iu', ' ', $content) ?: $content
        );
    }

    private function capabilityQuestionFallbackContent(ConversationMessage $message): string
    {
        $text = str((string) $message->content)->lower()->toString();

        if (preg_match('/\b(calendar|event|events|schedule|appointment|appointments)\b/u', $text)) {
            return 'Yes - I can create calendar events when you give me the details.';
        }

        if (preg_match('/\b(note|notes)\b/u', $text)) {
            return 'Yes - I can create and manage notes when you give me the details.';
        }

        if (preg_match('/\b(reminder|reminders|remind)\b/u', $text)) {
            return 'Yes - I can create reminders when you give me the details.';
        }

        if (preg_match('/\b(task|tasks|todo|to do)\b/u', $text)) {
            return 'Yes - I can create and manage tasks when you give me the details.';
        }

        if (preg_match('/\b(look up|find|search|weather|store hours|current)\b/u', $text)) {
            return 'Yes - I can look that up when you tell me what you need.';
        }

        return 'Yes - I can help with that when you give me the details.';
    }

    private function nativeActionFallbackContent(array $actions): string
    {
        $combinedDelete = $this->combinedDeleteFallbackContent($actions);
        if ($combinedDelete !== null) {
            return $combinedDelete;
        }

        $phrases = collect($actions)
            ->filter(fn (mixed $action): bool => is_array($action))
            ->map(fn (array $action): ?string => $this->nativeActionSummaryPhrase($action))
            ->filter()
            ->values();

        if ($phrases->isEmpty()) {
            return 'Done.';
        }

        return 'Done - '.$this->joinSummaryPhrases($phrases->all()).'.';
    }

    private function partialCrudPlannerContent(array $successfulActions, array $failedWorkItems, int $totalActionCount): string
    {
        $completedCount = count($successfulActions);
        $failedItems = collect($failedWorkItems)
            ->map(fn (mixed $item): array => is_array($item) ? $item : ['label' => (string) $item])
            ->values();
        $failedLabels = $failedItems
            ->map(fn (array $item): string => trim((string) ($item['label'] ?? '')))
            ->filter()
            ->unique()
            ->values();

        $completed = $completedCount > 0
            ? 'I completed '.$completedCount.' of '.$totalActionCount.' requested change'.($totalActionCount === 1 ? '' : 's').'.'
            : 'I need one more thing before I can make the requested change'.($totalActionCount === 1 ? '' : 's').'.';

        if ($completedCount > 0) {
            $completed .= ' '.$this->nativeActionFallbackContent($successfulActions);
        }

        $upgradeMessage = $this->planUpgradeMessageForFailedWorkItems($failedItems->all());
        if ($upgradeMessage !== null) {
            return $completedCount > 0
                ? $completed.' '.$upgradeMessage
                : $upgradeMessage;
        }

        if ($failedLabels->isEmpty()) {
            return $completed.' I need a little more detail before I can handle the rest.';
        }

        return $completed.' I need a little more detail for '.$this->joinSummaryPhrases($failedLabels->all()).' before I can handle the rest.';
    }

    /**
     * @param  array<int, array<string, mixed>>  $failedWorkItems
     */
    private function planUpgradeMessageForFailedWorkItems(array $failedWorkItems): ?string
    {
        $entitlementFailures = collect($failedWorkItems)
            ->filter(function (array $item): bool {
                $message = (string) ($item['message'] ?? '');
                $errorCode = (string) ($item['error_code'] ?? '');

                return $errorCode === 'subscription_limit_reached'
                    || str_contains($message, 'available on Premium, Pro, and Enterprise plans');
            })
            ->values();

        if ($entitlementFailures->isEmpty()) {
            return null;
        }

        $allFailuresAreEntitlements = $entitlementFailures->count() === count($failedWorkItems);
        if (! $allFailuresAreEntitlements) {
            return null;
        }

        $hasNoteFailure = $entitlementFailures->contains(function (array $item): bool {
            $actionType = (string) ($item['action_type'] ?? '');
            $message = (string) ($item['message'] ?? '');
            $label = (string) ($item['label'] ?? '');

            return str_starts_with($actionType, 'note.')
                || str_starts_with($actionType, 'note_folder.')
                || str_contains($message, 'Notes are available')
                || str_contains(mb_strtolower($label), 'note');
        });

        if ($hasNoteFailure) {
            return 'Notes are available on Premium, Pro, and Enterprise plans. Upgrade your plan to create and manage notes.';
        }

        $message = trim((string) ($entitlementFailures->first()['message'] ?? ''));
        if ($message === '') {
            return 'That feature needs an upgraded plan. Upgrade your plan to keep going.';
        }

        return rtrim($message, '.').'. Upgrade your plan to use this feature.';
    }

    private function combinedDeleteFallbackContent(array $actions): ?string
    {
        $typed = collect($actions)
            ->filter(fn (mixed $action): bool => is_array($action))
            ->map(function (array $action): ?array {
                $type = (string) ($action['type'] ?? '');
                if (! str_ends_with($type, '.delete') && ! in_array($type, ['calendar.delete'], true)) {
                    return null;
                }

                $parameters = is_array($action['parameters'] ?? null) ? $action['parameters'] : [];
                $kind = match ($type) {
                    'calendar_event.delete', 'calendar.delete' => 'calendar event',
                    'reminder.delete' => 'reminder',
                    'task.delete' => 'task',
                    'note.delete' => 'note',
                    default => null,
                };

                return $kind === null ? null : [
                    'kind' => $kind,
                    'title' => $this->summaryTitle($parameters),
                ];
            })
            ->filter()
            ->values();

        if ($typed->count() < 2 || $typed->count() !== count($actions)) {
            return null;
        }

        $titles = $typed->pluck('title')->filter()->unique()->values();
        if ($titles->count() !== 1) {
            return null;
        }

        $kinds = $typed->pluck('kind')->unique()->values()->all();

        return 'Done - I deleted the '.$this->joinSummaryPhrases($kinds).' for '.$titles->first().'.';
    }

    private function nativeActionSummaryPhrase(array $action): ?string
    {
        $type = (string) ($action['type'] ?? '');
        $parameters = is_array($action['parameters'] ?? null) ? $action['parameters'] : [];
        $title = $this->summaryTitle($parameters);

        return match ($type) {
            'calendar_event.create', 'calendar.create' => 'I added '.$this->summaryObject($title, 'the event').' to your calendar'.$this->calendarTimeSummary($parameters),
            'calendar_event.update', 'calendar.update' => 'I updated '.$this->summaryObject($title, 'the calendar event').$this->calendarTimeSummary($parameters),
            'calendar_event.delete', 'calendar.delete' => 'I deleted '.$this->summaryObject($title, 'the calendar event'),
            'task.create' => 'I added '.$this->summaryObject($title, 'the task').' to your tasks'.$this->singleTimeSummary($parameters['due_at'] ?? null, ' due '),
            'task.update' => 'I updated '.$this->summaryObject($title, 'the task').$this->singleTimeSummary($parameters['due_at'] ?? null, ' due '),
            'task.delete' => 'I deleted '.$this->summaryObject($title, 'the task'),
            'reminder.create' => 'I set '.$this->summaryObject($title, 'the reminder').$this->singleTimeSummary($parameters['remind_at'] ?? null, ' for '),
            'reminder.update' => 'I updated '.$this->summaryObject($title, 'the reminder').$this->singleTimeSummary($parameters['remind_at'] ?? null, ' for '),
            'reminder.delete' => 'I deleted '.$this->summaryObject($title, 'the reminder'),
            'note.create' => 'I created '.$this->summaryObject($title, 'the note'),
            'note.update' => 'I updated '.$this->summaryObject($title, 'the note'),
            'note.delete' => 'I deleted '.$this->summaryObject($title, 'the note'),
            'note_folder.create' => 'I created '.$this->summaryObject($title, 'the folder'),
            'note_folder.update' => 'I updated '.$this->summaryObject($title, 'the folder'),
            'note_folder.delete' => 'I deleted '.$this->summaryObject($title, 'the folder'),
            'event_category.create' => 'I created '.$this->summaryObject($title, 'the event category'),
            'event_category.update' => 'I updated '.$this->summaryObject($title, 'the event category'),
            'event_category.delete' => 'I deleted '.$this->summaryObject($title, 'the event category'),
            'memory.create' => 'I saved that to Bean knowledge',
            'memory.update' => 'I updated Bean knowledge',
            'memory.delete' => 'I removed that from Bean knowledge',
            'agent_profile.update' => 'I updated Bean settings',
            'workspace_memory.note' => 'I saved that workspace knowledge',
            default => null,
        };
    }

    private function summaryTitle(array $parameters): string
    {
        foreach (['title', 'name', 'match_title', 'summary', 'reason', 'content'] as $key) {
            $value = trim((string) ($parameters[$key] ?? ''));
            if ($value !== '') {
                return str($value)->squish()->limit(80, '')->toString();
            }
        }

        return '';
    }

    private function summaryObject(string $title, string $fallback): string
    {
        return $title !== '' ? $title : $fallback;
    }

    private function calendarTimeSummary(array $parameters): string
    {
        $startsAt = $this->summaryDateTime($parameters['starts_at'] ?? $parameters['start_at'] ?? null);
        $endsAt = $this->summaryDateTime($parameters['ends_at'] ?? $parameters['end_at'] ?? null);
        if ($startsAt === null) {
            return '';
        }

        if ($endsAt === null) {
            return ' for '.$startsAt;
        }

        return ' from '.$startsAt.' to '.$endsAt;
    }

    private function singleTimeSummary(mixed $value, string $prefix): string
    {
        $dateTime = $this->summaryDateTime($value);

        return $dateTime === null ? '' : $prefix.$dateTime;
    }

    private function summaryDateTime(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->format('M j, g:i A');
        } catch (\Throwable) {
            return str($raw)->squish()->limit(60, '')->toString();
        }
    }

    /**
     * @param  array<int, string>  $phrases
     */
    private function joinSummaryPhrases(array $phrases): string
    {
        $phrases = array_values(array_filter($phrases, fn (string $phrase): bool => trim($phrase) !== ''));
        if (count($phrases) <= 1) {
            return $phrases[0] ?? 'Done';
        }

        if (count($phrases) === 2) {
            return $phrases[0].' and '.$phrases[1];
        }

        $last = array_pop($phrases);

        return implode(', ', $phrases).', and '.$last;
    }

    private function normalizedAssistantContent(mixed $content): string
    {
        $trimmed = trim((string) $content);
        if ($trimmed === '') {
            return '';
        }

        try {
            $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $trimmed;
        }

        if (! is_array($decoded)) {
            return $trimmed;
        }

        foreach (['message', 'content', 'assistant_message', 'response'] as $key) {
            $value = $decoded[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        if (array_key_exists('role', $decoded) || array_key_exists('content', $decoded)) {
            return '';
        }

        return $trimmed;
    }

    private function assistantSafeResponseContent(string $content): string
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return '';
        }

        $normalized = str($trimmed)->lower()->squish()->toString();
        $hardFailures = [
            'bean could not finish',
            'could not finish that request',
            'bean could not complete',
            'could not complete the requested change',
            'i could not complete',
            'i tried to check that live information, but the lookup did not return a usable result',
            'i tried to check that live information',
            'i tried to check live information',
            'i could not get live information',
            'i couldn\'t get live information',
            'i couldn’t get live information',
            'could not get live information',
            'couldn\'t get live information',
            'couldn’t get live information',
            'lookup did not return',
            'lookup didn\'t return',
            'lookup didn’t return',
            'could not get that live lookup back quickly enough',
            'couldn\'t get that live lookup back quickly enough',
            'couldn’t get that live lookup back quickly enough',
            'live lookup back quickly enough',
            'did not return a usable result',
            'no usable result',
        ];

        foreach ($hardFailures as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return 'I’m on it. I’m syncing against the latest app state now, and I’ll ask for one detail if I need it.';
            }
        }

        return $trimmed;
    }

    private function nativeReadFallbackContent(array $toolOutputs): string
    {
        $last = collect($toolOutputs)->reverse()->first(fn (mixed $output): bool => is_array($output));
        if (! is_array($last)) {
            return 'Done.';
        }

        $successfulDayContext = collect($toolOutputs)
            ->reverse()
            ->first(fn (mixed $output): bool => is_array($output)
                && ($output['tool'] ?? null) === 'get_day_context'
                && ($output['ok'] ?? false));

        if (is_array($successfulDayContext)) {
            return $this->dayContextFallbackContent($successfulDayContext);
        }

        if (($last['tool'] ?? null) === 'get_request_history' && ($last['ok'] ?? false)) {
            return $this->requestHistoryFallbackContent($last);
        }

        if (($last['tool'] ?? null) === 'external_lookup') {
            $successfulLookup = collect($toolOutputs)
                ->reverse()
                ->first(fn (mixed $output): bool => is_array($output)
                    && ($output['tool'] ?? null) === 'external_lookup'
                    && filled($output['text'] ?? null));

            if (is_array($successfulLookup)) {
                return (string) $successfulLookup['text'];
            }

            return 'I’m checking live sources now. Send me one more detail if you want me to narrow it down further.';
        }

        if (($last['ok'] ?? false) && array_key_exists('count', $last)) {
            $count = (int) ($last['count'] ?? 0);
            $tool = str_replace('_', ' ', (string) ($last['tool'] ?? 'records'));

            return $count === 0
                ? "I checked {$tool}, but I did not find anything matching that."
                : "I found {$count} matching ".str($tool)->after('search ')->toString().'.';
        }

        return 'I’m checking that now. I’ll ask for one more detail if I need it.';
    }

    private function canUseNativeReadFallback(array $toolOutputs): bool
    {
        if ($toolOutputs === []) {
            return false;
        }

        $last = collect($toolOutputs)->reverse()->first(fn (mixed $output): bool => is_array($output));
        if (! is_array($last) || ($last['ok'] ?? false) !== true) {
            return false;
        }

        if (($last['tool'] ?? null) === 'external_lookup') {
            return filled($last['text'] ?? null);
        }

        return in_array(($last['tool'] ?? null), ['get_day_context', 'get_request_history'], true);
    }

    private function requestHistoryFallbackContent(array $output): string
    {
        $items = collect((array) ($output['items'] ?? []))
            ->filter(fn (mixed $item): bool => is_array($item) && filled($item['content'] ?? null))
            ->values();

        if ($items->isEmpty()) {
            return 'I checked your request history, but I did not find anything matching that.';
        }

        $lines = $items
            ->take(5)
            ->map(function (array $item): string {
                $createdAt = trim((string) ($item['created_at'] ?? ''));
                $content = str((string) ($item['content'] ?? ''))->squish()->limit(180, '')->toString();

                return trim(($createdAt !== '' ? $createdAt.' - ' : '').$content);
            })
            ->filter()
            ->values()
            ->all();

        if (count($lines) === 1) {
            return 'You asked: '.$lines[0];
        }

        return "Here is what I found in your request history:\n- ".implode("\n- ", $lines);
    }

    private function dayContextFallbackContent(array $output): string
    {
        $date = (string) ($output['date'] ?? 'today');
        $items = [];

        foreach ((array) ($output['calendar_events'] ?? []) as $event) {
            if (! is_array($event)) {
                continue;
            }
            $time = trim((string) ($event['display_start_time'] ?? ''));
            $title = trim((string) ($event['title'] ?? 'Untitled event'));
            $items[] = trim(($time !== '' ? "{$time} - " : '')."Event: {$title}");
        }

        foreach ((array) ($output['tasks'] ?? []) as $task) {
            if (! is_array($task)) {
                continue;
            }
            $time = trim((string) ($task['display_due_time'] ?? ''));
            $title = trim((string) ($task['title'] ?? 'Untitled task'));
            $items[] = trim(($time !== '' ? "{$time} - " : '')."Task: {$title}");
        }

        foreach ((array) ($output['reminders'] ?? []) as $reminder) {
            if (! is_array($reminder)) {
                continue;
            }
            $time = trim((string) ($reminder['display_remind_time'] ?? ''));
            $title = trim((string) ($reminder['title'] ?? 'Untitled reminder'));
            $items[] = trim(($time !== '' ? "{$time} - " : '')."Reminder: {$title}");
        }

        $items = collect($items)
            ->map(fn (string $item): string => str($item)->squish()->toString())
            ->filter()
            ->unique(fn (string $item): string => mb_strtolower($item))
            ->values()
            ->all();

        if ($items === []) {
            return "Nothing else is scheduled for {$date}.";
        }

        $limited = array_slice($items, 0, 8);
        $suffix = count($items) > count($limited) ? "\n- Plus ".(count($items) - count($limited)).' more.' : '';

        return "Here is what is coming up for {$date}:\n- ".implode("\n- ", $limited).$suffix;
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
