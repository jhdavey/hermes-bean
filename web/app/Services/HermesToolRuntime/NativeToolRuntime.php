<?php

namespace App\Services\HermesToolRuntime;

use App\Models\ActivityEvent;
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
use Illuminate\Support\Facades\Log;

trait NativeToolRuntime
{
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

        $hasWriteVerb = (bool) preg_match('/\b(add|create|make|plan|schedule|book|set|move|reschedule|change|update|edit|rename|delete|remove|cancel|clear|mark|complete|finish|pin|unpin|lock|unlock)\b/u', $content);
        $hasAppTarget = (bool) preg_match('/\b(event|events|calendar|calendars|appointment|appointments|meeting|meetings|block|blocks|task|tasks|todo|to\s+do|reminder|reminders|note|notes|folder|folders|list|lists)\b/u', $content);

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
                'message' => 'Notes are available on this plan after upgrading.',
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
}
