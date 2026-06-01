<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\AgentProfile;
use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
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
        $this->usageService->preflight($session, $userMessage, $modelRoute, $prompt);

        return $this->sendMessageWithTools($session, $userMessage, $received, $modelRoute, $prompt);
    }

    private function sendMessageWithTools(
        ConversationSession $session,
        ConversationMessage $userMessage,
        ActivityEvent $received,
        array $modelRoute,
        string $prompt
    ): array {
        $apiKey = $this->providerApiKey();
        if ($apiKey === '') {
            return $this->toolRuntimeFailed($session, $userMessage, collect([$received]), 'Bean is not configured to contact the agent model yet.', [
                'failure_type' => 'missing_api_key',
                'provider' => config('services.hermes_runtime.default_provider'),
            ]);
        }

        if (! filled($modelRoute['model'] ?? null)) {
            return $this->toolRuntimeFailed($session, $userMessage, collect([$received]), 'Bean is missing an agent model configuration.', [
                'failure_type' => 'missing_model',
            ]);
        }

        $started = $this->recordEvent($session, 'runtime.tool_model_started', [
            'message_id' => $userMessage->id,
            'provider' => config('services.hermes_runtime.default_provider'),
            'model' => $modelRoute['model'],
            'model_route' => $modelRoute,
        ], 'hermes.tools', 'started');
        $session->update(['status' => 'running', 'last_activity_at' => now()]);

        $messages = [
            ['role' => 'system', 'content' => $this->toolSystemInstructions()],
            ['role' => 'system', 'content' => "Runtime context:\n".json_encode($this->toolContextPayload($session, $userMessage), JSON_THROW_ON_ERROR)],
            ['role' => 'user', 'content' => $userMessage->content],
        ];
        $responses = [];
        $domainEvents = collect();
        $actions = [];
        $assistantContent = '';
        $finalResponse = null;

        try {
            for ($turn = 0; $turn < 3; $turn++) {
                if ($this->isCancellationRequested($session)) {
                    return $this->toolRuntimeCancelled($session, $userMessage, collect([$received, $started]));
                }

                $response = $this->chatCompletion($modelRoute, $messages, true);
                $responses[] = $response;
                $finalResponse = $response;
                $modelRoute['model'] = (string) data_get($response, 'model', $modelRoute['model']);
                $message = data_get($response, 'choices.0.message', []);
                $toolCalls = is_array($message) && is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];

                if ($toolCalls === []) {
                    $assistantContent = trim((string) data_get($message, 'content', ''));
                    break;
                }

                $messages[] = [
                    'role' => 'assistant',
                    'content' => data_get($message, 'content'),
                    'tool_calls' => $toolCalls,
                ];

                foreach ($toolCalls as $toolCall) {
                    if ($this->isCancellationRequested($session)) {
                        return $this->toolRuntimeCancelled($session, $userMessage, collect([$received, $started])->concat($domainEvents));
                    }

                    if (! is_array($toolCall)) {
                        continue;
                    }
                    [$toolActions, $toolEvents, $toolOutput] = $this->executeNativeToolCall($session, $toolCall);
                    $actions = array_merge($actions, $toolActions);
                    $domainEvents = $domainEvents->concat($toolEvents);
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) ($toolCall['id'] ?? ''),
                        'content' => json_encode($toolOutput, JSON_THROW_ON_ERROR),
                    ];
                }
            }

            if ($assistantContent === '' && $actions !== []) {
                try {
                    if ($this->isCancellationRequested($session)) {
                        return $this->toolRuntimeCancelled($session, $userMessage, collect([$received, $started])->concat($domainEvents));
                    }

                    $response = $this->chatCompletion($modelRoute, $messages, false);
                    $responses[] = $response;
                    $finalResponse = $response;
                    $modelRoute['model'] = (string) data_get($response, 'model', $modelRoute['model']);
                    $assistantContent = trim((string) data_get($response, 'choices.0.message.content', ''));
                } catch (\Throwable $exception) {
                    Log::warning('Hermes final response call failed after successful tool execution.', [
                        'session_id' => $session->id,
                        'message_id' => $userMessage->id,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $exception) {
            if ($actions !== []) {
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

                return $this->toolRuntimeFailed($session, $userMessage, collect([$received, $started]), 'Bean could not complete that request because the agent runtime failed.', [
                    'failure_type' => 'tool_runtime_failed',
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        if ($assistantContent === '') {
            $assistantContent = $actions !== [] ? $this->nativeActionFallbackContent($actions) : 'Done.';
        }

        return DB::transaction(function () use ($session, $userMessage, $received, $started, $modelRoute, $prompt, $responses, $domainEvents, $assistantContent, $finalResponse): array {
            $completed = $this->recordEvent($session, 'runtime.tool_model_completed', [
                'message_id' => $userMessage->id,
                'response_count' => count($responses),
                'finish_reason' => data_get($finalResponse, 'choices.0.finish_reason'),
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
    }

    private function chatCompletion(array $modelRoute, array $messages, bool $allowTools): array
    {
        $payload = [
            'model' => (string) $modelRoute['model'],
            'messages' => $messages,
        ];
        if ($allowTools) {
            $payload['tools'] = $this->nativeToolDefinitions();
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

    private function executeNativeToolCall(ConversationSession $session, array $toolCall): array
    {
        $name = (string) data_get($toolCall, 'function.name', '');
        $arguments = $this->decodeToolArguments((string) data_get($toolCall, 'function.arguments', '{}'));
        if ($this->isNativeReadTool($name)) {
            return [[], collect(), $this->executeNativeReadTool($session, $name, $arguments)];
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

        $action = [
            'type' => $actionType,
            'risk' => 'low',
            'parameters' => $arguments,
        ];

        $events = $this->actionService->applyEnvelope($session, ['actions' => [$action]]);
        $failed = $events->contains(fn (ActivityEvent $event): bool => $event->status === 'failed');

        return [[$action], $events, [
            'ok' => ! $failed,
            'action_type' => $actionType,
            'events' => $events->map(fn (ActivityEvent $event): array => [
                'event_type' => $event->event_type,
                'tool_name' => $event->tool_name,
                'status' => $event->status,
                'payload' => $event->payload,
            ])->values()->all(),
        ]];
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
            'create_event_category' => 'event_category.create',
            'update_event_category' => 'event_category.update',
            'delete_event_category' => 'event_category.delete',
            'create_blocker' => 'blocker.create',
            'update_blocker' => 'blocker.update',
            'resolve_blocker' => 'blocker.resolve',
            'delete_blocker' => 'blocker.delete',
            'update_agent_profile' => 'agent_profile.update',
            'note_workspace_memory' => 'workspace_memory.note',
            'update_conversation_session' => 'conversation_session.update',
            'create_activity_event' => 'activity_event.create',
        ][$name] ?? null;
    }

    private function isNativeReadTool(string $name): bool
    {
        return in_array($name, ['search_tasks', 'search_reminders', 'search_calendar_events', 'get_day_context'], true);
    }

    private function executeNativeReadTool(ConversationSession $session, string $name, array $arguments): array
    {
        return match ($name) {
            'search_tasks' => $this->searchTasksForTool($session, $arguments),
            'search_reminders' => $this->searchRemindersForTool($session, $arguments),
            'search_calendar_events' => $this->searchCalendarEventsForTool($session, $arguments),
            'get_day_context' => $this->dayContextForTool($session, $arguments),
            default => ['ok' => false, 'error_code' => 'unsupported_read_tool'],
        };
    }

    private function searchTasksForTool(ConversationSession $session, array $arguments): array
    {
        $workspaceId = $this->toolWorkspaceId($session, $arguments);
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
        [$from, $to] = $this->toolDateWindow($arguments);
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
                'due_at' => $task->due_at?->toIso8601String(),
                'recurrence' => data_get($task->metadata ?? [], 'recurrence'),
            ])->values()->all();

        return $this->readToolResult('search_tasks', $items, $workspaceId);
    }

    private function searchRemindersForTool(ConversationSession $session, array $arguments): array
    {
        $workspaceId = $this->toolWorkspaceId($session, $arguments);
        $query = Reminder::query()->where('user_id', $session->user_id)->where('workspace_id', $workspaceId);
        if (filled($arguments['status'] ?? null)) {
            $query->where('status', (string) $arguments['status']);
        }
        if (filled($arguments['query'] ?? null)) {
            $this->whereLooseTitle($query, (string) $arguments['query']);
        }
        [$from, $to] = $this->toolDateWindow($arguments);
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
                'remind_at' => $reminder->remind_at?->toIso8601String(),
                'recurrence' => data_get($reminder->metadata ?? [], 'recurrence'),
            ])->values()->all();

        return $this->readToolResult('search_reminders', $items, $workspaceId);
    }

    private function searchCalendarEventsForTool(ConversationSession $session, array $arguments): array
    {
        $workspaceId = $this->toolWorkspaceId($session, $arguments);
        $query = CalendarEvent::query()->where('user_id', $session->user_id)->where('workspace_id', $workspaceId);
        if (filled($arguments['status'] ?? null)) {
            $query->where('status', (string) $arguments['status']);
        }
        if (filled($arguments['query'] ?? null)) {
            $this->whereLooseTitle($query, (string) $arguments['query']);
        }
        [$from, $to] = $this->toolDateWindow($arguments);
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
                'starts_at' => $event->starts_at?->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
            ])->values()->all();

        return $this->readToolResult('search_calendar_events', $items, $workspaceId);
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
            'tasks' => $this->searchTasksForTool($session, [...$arguments, 'include_completed' => false])['items'],
            'reminders' => $this->searchRemindersForTool($session, $arguments)['items'],
            'calendar_events' => $this->searchCalendarEventsForTool($session, $arguments)['items'],
        ];
    }

    private function readToolResult(string $tool, array $items, int $workspaceId): array
    {
        return [
            'ok' => true,
            'tool' => $tool,
            'workspace_id' => $workspaceId,
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

    private function toolDateWindow(array $arguments): array
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

        return [
            Carbon::parse($fromDate)->startOfDay(),
            Carbon::parse($toDate)->endOfDay(),
        ];
    }

    private function toolLimit(array $arguments): int
    {
        return max(1, min(50, (int) ($arguments['limit'] ?? 12)));
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

    private function providerApiKey(): string
    {
        return (string) config('services.hermes_runtime.api_key', '');
    }

    private function toolPromptFor(ConversationSession $session, ConversationMessage $message, array $modelRoute): string
    {
        return json_encode([
            'runtime_context' => $this->toolContextPayload($session, $message),
            'message' => $message->content,
            'message_metadata' => $message->metadata,
            'route' => $modelRoute,
        ], JSON_THROW_ON_ERROR);
    }

    private function toolContextPayload(ConversationSession $session, ConversationMessage $message): array
    {
        $user = User::find($session->user_id);
        $workspace = $this->workspaceForSession($session, $user);
        $profile = $workspace ? $this->agentProfileService->ensureForWorkspace($workspace, $user) : $this->profileForSession($session);
        if ($user && $profile) {
            $user = $this->agentProfileService->syncUserOnboardingFlag($user, $profile);
        }
        $profileSettings = $profile->settings ?? [];

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

    private function toolSystemInstructions(): string
    {
        return <<<'PROMPT'
You are Bean, a capable human-like assistant inside the Hey Bean app.

You own intent and conversation. Interpret the user's message naturally, including messy wording, typos, shorthand, and voice transcription errors. Decide whether to answer directly, call read tools, call write tools, or ask one concise follow-up.

Use read tools when you need current app state. Use write tools when app state should change. Do not describe a dashboard change as complete unless a write tool result confirms it succeeded.

Laravel owns app mechanics: workspace access, database writes, validation, syncing, and tool results. Trust tool results. If a read/write tool says not found, ambiguous, or failed, respond naturally from that result.

Prefer acting on clear scheduling/productivity requests instead of asking for optional details. Infer sensible defaults: current workspace, no category, not critical, no recurrence, and no notes unless the user says otherwise. For relative dates/times, use temporal_context.client_context and emit local ISO-8601 timestamps with the client's UTC offset.
When setting recurrence, always use recurrence as one of: none, daily, weekly, monthly, specific_days, or interval. For custom intervals like "every 3 days", set recurrence to interval and put interval plus interval_unit in metadata. Never put an object in recurrence.

Use the current workspace unless the user clearly names another accessible workspace. Adapt tone to agent_profile settings and memory. If onboarding is incomplete, run a quick onboarding interview and use update_agent_profile when enough preferences are provided.

If runtime_context.voice_context.quick_reply is present, Bean already said that sentence aloud in this same voice turn. Do not repeat it or paraphrase it. Continue naturally from it with only new information, the result of any work, or a concise next step.

Respond to the user in natural language only. Never output JSON, tool arguments, ids, schema text, routing details, or debug text.
PROMPT;
    }

    private function nativeToolDefinitions(): array
    {
        return [
            $this->nativeTool('search_tasks', 'Search tasks in the current or specified workspace. Use this before updating a task when the matching item is not already known.', $this->searchTaskProperties()),
            $this->nativeTool('search_reminders', 'Search reminders in the current or specified workspace.', $this->searchReminderProperties()),
            $this->nativeTool('search_calendar_events', 'Search calendar events in the current or specified workspace.', $this->searchCalendarEventProperties()),
            $this->nativeTool('get_day_context', 'Get tasks, reminders, and calendar events for a specific local date.', $this->dayContextProperties(), ['date']),
            $this->nativeTool('create_task', 'Create a visible task in Hey Bean.', $this->taskProperties(), ['title']),
            $this->nativeTool('update_task', 'Update one existing task. Prefer id from search_tasks; otherwise use match_title and Laravel will require a unique match.', $this->taskProperties(requireId: false)),
            $this->nativeTool('delete_task', 'Delete one existing task by id.', $this->idProperties(), ['id']),
            $this->nativeTool('create_reminder', 'Create a visible reminder in Hey Bean.', $this->reminderProperties(), ['title', 'remind_at']),
            $this->nativeTool('update_reminder', 'Update one existing reminder by id.', $this->reminderProperties(requireId: true), ['id']),
            $this->nativeTool('delete_reminder', 'Delete one existing reminder by id.', $this->idProperties(), ['id']),
            $this->nativeTool('create_calendar_event', 'Create a visible calendar event in Hey Bean.', $this->calendarEventProperties(), ['title', 'starts_at']),
            $this->nativeTool('update_calendar_event', 'Update one existing calendar event. Prefer id; use match_title and from_date only when needed.', $this->calendarEventProperties(requireId: false)),
            $this->nativeTool('delete_calendar_event', 'Delete one existing calendar event by id.', $this->idProperties(), ['id']),
            $this->nativeTool('create_event_category', 'Create or save an event category.', $this->categoryProperties(), ['name']),
            $this->nativeTool('update_event_category', 'Update one event category by id.', $this->categoryProperties(requireId: true), ['id']),
            $this->nativeTool('delete_event_category', 'Delete one event category by id.', $this->idProperties(), ['id']),
            $this->nativeTool('create_blocker', 'Create a blocker or issue for the user.', $this->blockerProperties(), ['reason']),
            $this->nativeTool('update_blocker', 'Update a blocker by id.', $this->blockerProperties(requireId: true), ['id']),
            $this->nativeTool('resolve_blocker', 'Resolve a blocker by id.', $this->idProperties(), ['id']),
            $this->nativeTool('delete_blocker', 'Delete a blocker by id.', $this->idProperties(), ['id']),
            $this->nativeTool('update_agent_profile', 'Update Bean preferences, onboarding, model/profile settings, or memory settings.', $this->agentProfileProperties()),
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

    private function dayContextProperties(): array
    {
        return [
            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD local date.'],
            'workspace_id' => ['type' => 'integer'],
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

    private function recurrenceProperty(): array
    {
        return [
            'type' => ['string', 'null'],
            'enum' => ['none', 'daily', 'weekly', 'monthly', 'specific_days', 'interval', null],
            'description' => 'Use a string only. For "every N days/weeks/months", use interval and set metadata.interval plus metadata.interval_unit.',
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
                'content' => $message,
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
        $defaultModel = (string) config('services.hermes_runtime.default_model', 'gpt-5.5');
        $model = (string) ($this->profileForSession($session)?->model ?: $defaultModel);

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

    private function nativeActionFallbackContent(array $actions): string
    {
        if (count($actions) !== 1 || ! is_array($actions[0] ?? null)) {
            return 'Done.';
        }

        $action = $actions[0];
        $title = trim((string) data_get($action, 'parameters.title', ''));
        $name = trim((string) data_get($action, 'parameters.name', ''));

        return match ((string) ($action['type'] ?? '')) {
            'calendar_event.create', 'calendar.create' => $title !== '' ? "I added {$title} to your calendar." : 'I added that to your calendar.',
            'task.create' => $title !== '' ? "I added {$title} to your tasks." : 'I added that to your tasks.',
            'task.update' => $title !== '' ? "I updated {$title}." : 'I updated that task.',
            'reminder.create' => $title !== '' ? "I set the reminder: {$title}." : 'I set that reminder.',
            'event_category.create' => $name !== '' ? "I created {$name}." : 'I created that.',
            default => 'Done.',
        };
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
