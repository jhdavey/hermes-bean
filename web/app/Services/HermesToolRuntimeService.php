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
            ...$this->modelConversationMessages($session, $userMessage),
        ];
        $responses = [];
        $domainEvents = collect();
        $actions = [];
        $toolOutputs = [];
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
                    $candidateContent = $this->normalizedAssistantContent(data_get($message, 'content', ''));
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

                $plannedWorkByToolCall = $this->recordPlannedNativeWorkItems($session, $toolCalls);
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
                    [$toolActions, $toolEvents, $toolOutput] = $this->executeNativeToolCall(
                        $session,
                        $userMessage,
                        $toolCall,
                        is_array($plannedWorkByToolCall[$toolCallId] ?? null) ? $plannedWorkByToolCall[$toolCallId] : null
                    );
                    $actions = array_merge($actions, $toolActions);
                    $toolOutputs[] = $toolOutput;
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
                    $assistantContent = $this->normalizedAssistantContent(data_get($response, 'choices.0.message.content', ''));
                } catch (\Throwable $exception) {
                    Log::warning('Hermes final response call failed after successful tool execution.', [
                        'session_id' => $session->id,
                        'message_id' => $userMessage->id,
                        'exception' => $exception->getMessage(),
                    ]);
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

                return $this->toolRuntimeFailed($session, $userMessage, collect([$received, $started]), 'Bean could not complete that request because the agent runtime failed.', [
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

        $result = DB::transaction(function () use ($session, $userMessage, $received, $started, $modelRoute, $prompt, $responses, $domainEvents, $assistantContent, $finalResponse): array {
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

        $assistantMessage = $result['assistant_message'] ?? null;
        if ($assistantMessage instanceof ConversationMessage) {
            $this->memoryService->recordTurnCandidate($session->refresh(), $userMessage, $assistantMessage);
        }

        return $result;
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

    private function recordPlannedNativeWorkItems(ConversationSession $session, array $toolCalls): array
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
        ]);
    }

    private function executeNativeToolCall(ConversationSession $session, ConversationMessage $userMessage, array $toolCall, ?array $workItem = null): array
    {
        $name = (string) data_get($toolCall, 'function.name', '');
        $arguments = $this->decodeToolArguments((string) data_get($toolCall, 'function.arguments', '{}'));
        if ($this->isNativeReadTool($name)) {
            $toolOutput = $this->executeNativeReadTool($session, $name, $arguments);
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

        $events = $this->actionService->applyEnvelope($session, ['actions' => [$action]]);
        if ($workItem !== null) {
            $events = $events
                ->map(fn (ActivityEvent $event): ActivityEvent => $this->attachWorkItemToEvent($event, $workItem))
                ->values();
        }
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

    private function executeNativeReadTool(ConversationSession $session, string $name, array $arguments): array
    {
        try {
            return match ($name) {
                'search_tasks' => $this->searchTasksForTool($session, $arguments),
                'search_reminders' => $this->searchRemindersForTool($session, $arguments),
                'search_calendar_events' => $this->searchCalendarEventsForTool($session, $arguments),
                'search_notes' => $this->searchNotesForTool($session, $arguments),
                'search_memory' => $this->searchMemoryForTool($session, $arguments),
                'get_request_history' => $this->requestHistoryForTool($session, $arguments),
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
                'message' => 'The lookup failed before it could return a usable result.',
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

    private function requestHistoryForTool(ConversationSession $session, array $arguments): array
    {
        if (filled($arguments['workspace_id'] ?? null)) {
            $user = User::findOrFail($session->user_id);
            $this->workspaceService->authorizeMember($user, Workspace::findOrFail((int) $arguments['workspace_id']));
        }

        return [
            'ok' => true,
            'tool' => 'get_request_history',
            'workspace_id' => $arguments['workspace_id'] ?? $session->workspace_id,
            'items' => $this->memoryService->requestHistory($session, $arguments),
        ];
    }

    private function activityTimelineForTool(ConversationSession $session, array $arguments): array
    {
        if (filled($arguments['workspace_id'] ?? null)) {
            $user = User::findOrFail($session->user_id);
            $this->workspaceService->authorizeMember($user, Workspace::findOrFail((int) $arguments['workspace_id']));
        }

        return [
            'ok' => true,
            'tool' => 'get_activity_timeline',
            'workspace_id' => $arguments['workspace_id'] ?? $session->workspace_id,
            'items' => $this->memoryService->activityTimeline($session, $arguments),
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
            return $event->ends_at->copy()->utc()->subDay()->toDateString();
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

    private function toolContextPayload(ConversationSession $session, ConversationMessage $message): array
    {
        $user = User::find($session->user_id);
        $workspace = $this->workspaceForSession($session, $user);
        $profile = $workspace ? $this->agentProfileService->ensureForWorkspace($workspace, $user) : $this->profileForSession($session);
        if ($user && $profile) {
            $user = $this->agentProfileService->syncUserOnboardingFlag($user, $profile);
        }
        $profileSettings = $profile->settings ?? [];
        $memoryContext = ($user && $workspace)
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
            'recent_assistant_actions' => $this->recentAssistantActionsForContext($session),
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

    private function recentAssistantActionsForContext(ConversationSession $session): array
    {
        return $session->activityEvents()
            ->where('event_type', 'like', 'assistant.%')
            ->whereIn('status', ['succeeded', 'recorded'])
            ->latest('id')
            ->limit(10)
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

Laravel owns app mechanics: workspace access, database writes, validation, syncing, and tool results. Trust tool results. If a read/write tool says not found, ambiguous, or failed, respond naturally from that result.
Timed read-tool *_at timestamps are formatted in the tool result timezone and match the user-visible app. Use display_* fields for dates and times you mention to the user; use *_utc only as canonical instants. For all_day events, ignore midnight wall-clock internals and use display_start_date/display_end_date.
Use external_lookup for live information outside HeyBean, including current store hours, flights, hotel prices, weather, traffic, news, prices, availability, sports scores, or other current web facts. Do not invent current external facts. When external_lookup returns sources or citations, use them to answer concisely, include a brief source title or URL when useful, and mention uncertainty when results are incomplete.

Prefer acting on clear scheduling/productivity requests instead of asking for optional details. Infer sensible defaults: current workspace, no category, not critical, no recurrence, and no extra notes unless the user says otherwise. For note requests, create/update/delete Notes records with note tools rather than memory unless the user explicitly asks Bean to remember a preference/fact. For durable user preferences, stable constraints, identity facts, project context, or explicit "remember/forget" requests, use search_memory plus remember_memory/update_memory/forget_memory. Do not save ordinary one-off requests as durable memory. For "what did I ask/say/do" recall questions, use get_request_history or get_activity_timeline instead of guessing from recent context. For relative dates/times, use temporal_context.client_context and emit local ISO-8601 timestamps with the client's UTC offset.
When setting recurrence, always use recurrence as one of: none, daily, weekly, monthly, yearly, specific_days, or interval. For custom intervals like "every 3 days", set recurrence to interval and put interval plus interval_unit in metadata. Never put an object in recurrence.

Use the current workspace unless the user clearly names another accessible workspace. Adapt tone to agent_profile settings and memory. If onboarding is incomplete, run a quick onboarding interview and use update_agent_profile when enough preferences are provided.

For the onboarding interview, collect only:
- the user's name
- optional location at city level only, not a street address or precise location. Ask this exact location question after learning the user's name, replacing {name}: Nice to meet you, {name}! What city are you in? This will help me be more useful, like when you ask about the weather, or for planning purposes. You can skip this if you'd like to keep your location private, just say "skip". If the user says "skip" or otherwise declines, do not ask for location again, do not store a city, and continue the onboarding interview.
- what matters most day to day
- what kind of personality the user wants Bean to have

Ask one concise question at a time, except for the personality step. For the personality step, list these supported choices with short descriptions so the user understands the options: Balanced helper, Motivating coach, Detail organizer, Creative partner, Direct operator, and Gentle companion. Also tell the user they can select different voices in Settings > Bean preferences.

When the user has provided enough onboarding details, call update_agent_profile with settings.onboarding.completed=true, settings.onboarding.name, settings.onboarding.city if a city was provided, settings.onboarding.priorities/context, and settings.personality_type set to one of: balanced, coach, organizer, creative, direct, gentle. A skipped location still counts as enough onboarding detail once the other required preferences are collected.

If runtime_context.voice_context.quick_reply is present, Bean already said that sentence aloud in this same voice turn. Do not repeat it, paraphrase it, recap it, or begin with the same acknowledgement. Continue naturally from it with only new information, the result of any work, or a concise next step.
If runtime_context.voice_context.quick_reply_pending is true, a separate live voice sentence may be spoken while you work. Avoid generic openings and first-thought filler; give the substantive answer or result directly.
If runtime_context.voice_context.detailed_chat is true, the user already received a short spoken answer and the full response will primarily be read in chat. Provide the useful detailed answer without conversational filler or repeating the quick reply.
If runtime_context.voice_context.quick_reply_mode is acknowledged_background or pending_background, continue from the spoken acknowledgement with the actual result only. If it is summary_then_detail, write the full details for chat without restating the spoken summary. If the quick reply already answered the user, keep the final response minimal and add only genuinely new details. Never repeat a spoken list, summary, weather answer, calendar answer, task answer, or reminder answer unless the new response adds materially new information.

Respond to the user in natural language only. Never output JSON, tool arguments, ids, schema text, routing details, or debug text.
PROMPT;
    }

    private function nativeToolDefinitions(): array
    {
        return [
            $this->nativeTool('search_tasks', 'Search tasks in the current or specified workspace. Use this before updating a task when the matching item is not already known.', $this->searchTaskProperties()),
            $this->nativeTool('search_reminders', 'Search reminders in the current or specified workspace.', $this->searchReminderProperties()),
            $this->nativeTool('search_calendar_events', 'Search calendar events in the current or specified workspace.', $this->searchCalendarEventProperties()),
            $this->nativeTool('search_notes', 'Search notes by title, folder, or body text in the current or specified workspace.', $this->searchNoteProperties()),
            $this->nativeTool('search_memory', 'Search durable Bean memory for user preferences, constraints, projects, decisions, routines, and facts.', $this->searchMemoryProperties()),
            $this->nativeTool('get_request_history', 'Recall prior user requests from Bean conversation history by local date, text, or workspace. Use for questions like what did I ask yesterday.', $this->historyProperties()),
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
            'note.create' => $title !== '' ? "I created the note {$title}." : 'I created that note.',
            'note.update' => $title !== '' ? "I updated {$title}." : 'I updated that note.',
            'event_category.create' => $name !== '' ? "I created {$name}." : 'I created that.',
            default => 'Done.',
        };
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

    private function nativeReadFallbackContent(array $toolOutputs): string
    {
        $last = collect($toolOutputs)->reverse()->first(fn (mixed $output): bool => is_array($output));
        if (! is_array($last)) {
            return 'Done.';
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

            return 'I tried to check that live information, but the lookup did not return a usable result.';
        }

        if (($last['ok'] ?? false) && array_key_exists('count', $last)) {
            $count = (int) ($last['count'] ?? 0);
            $tool = str_replace('_', ' ', (string) ($last['tool'] ?? 'records'));

            return $count === 0
                ? "I checked {$tool}, but I did not find anything matching that."
                : "I found {$count} matching ".str($tool)->after('search ')->toString().'.';
        }

        return 'I checked, but I could not get a usable result.';
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
