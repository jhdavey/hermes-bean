<?php

namespace App\Services\HermesToolRuntime;

use App\Models\ActivityEvent;
use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait CrudPlannerRuntime
{
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
        $baseModel = trim((string) ($baseModelRoute['model'] ?? ''));
        $model = $configuredModel !== ''
            ? $configuredModel
            : $baseModel;

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

    private function recordPlannedNativeWorkItems(ConversationSession $session, ConversationMessage $userMessage, array $toolCalls, int $startingOrder = 0): array
    {
        $planned = [];
        $order = $startingOrder;

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
}
