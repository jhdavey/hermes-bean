<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\AgentProfile;
use App\Models\Approval;
use App\Models\Blocker;
use App\Models\CalendarEvent;
use App\Models\ConversationSession;
use App\Models\EventCategory;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StructuredHermesActionService
{
    private const DEFAULT_CATEGORY_COLOR = '#34C759';

    public function __construct(private readonly GoogleCalendarSyncService $googleCalendar) {}

    /**
     * @param  array<string, mixed>  $envelope
     * @return Collection<int, ActivityEvent>
     */
    public function applyEnvelope(ConversationSession $session, array $envelope): Collection
    {
        $events = collect();
        $actions = $envelope['actions'] ?? [];

        if (! is_array($actions)) {
            return $events;
        }

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            try {
                $parameters = is_array($action['parameters'] ?? null) ? $action['parameters'] : [];
                $parameters = $this->normalizeActionParameters($action, $parameters);
                $action['parameters'] = $parameters;
                $this->validateActionContract($action, $parameters);
                $actionSession = $this->sessionForAction($session, $parameters);
            } catch (\Throwable $exception) {
                $events->push($this->recordEvent($session, 'assistant.action.failed', [
                    'action_type' => $action['type'] ?? null,
                    'reason' => $exception->getMessage(),
                ], 'structured_action', 'failed'));

                continue;
            }

            if ($this->requiresApproval($actionSession, $action)) {
                $approval = Approval::create([
                    'user_id' => $actionSession->user_id,
                    'workspace_id' => $actionSession->workspace_id,
                    'conversation_session_id' => $actionSession->id,
                    'title' => $this->approvalTitle($action),
                    'description' => isset($action['description']) && is_string($action['description']) ? $action['description'] : null,
                    'status' => 'pending',
                    'payload' => ['action' => $action],
                ]);

                $events->push($this->recordEvent($actionSession, 'assistant.approval.created', [
                    'approval_id' => $approval->id,
                    'action_type' => $action['type'] ?? null,
                    'risk' => $action['risk'] ?? null,
                    'source_workspace_id' => $session->workspace_id,
                    'target_workspace_id' => $actionSession->workspace_id,
                ], 'approvals.create', 'succeeded'));

                continue;
            }

            try {
                $events = $events->concat($this->executeAction($actionSession, $action));
            } catch (\Throwable $exception) {
                $events->push($this->recordEvent($session, 'assistant.action.failed', [
                    'action_type' => $action['type'] ?? null,
                    'reason' => $exception->getMessage(),
                ], 'structured_action', 'failed'));
            }
        }

        return $events->values();
    }

    /**
     * @return array{approval: Approval, events: Collection<int, ActivityEvent>}
     */
    public function approve(Approval $approval, bool $alwaysApprove = false): array
    {
        if ($approval->status !== 'pending') {
            throw new InvalidArgumentException('Only pending approvals can be approved.');
        }

        return DB::transaction(function () use ($approval, $alwaysApprove): array {
            $session = ConversationSession::find($approval->conversation_session_id);
            if (! $session) {
                throw new InvalidArgumentException('Approval is not attached to an active conversation session.');
            }

            $action = $approval->payload['action'] ?? null;
            if (! is_array($action)) {
                throw new InvalidArgumentException('Approval payload is missing a structured action.');
            }

            $events = $this->executeAction($session, $action)->values();
            if ($alwaysApprove) {
                $this->rememberAlwaysApprovedAction($session, $action);
            }

            $approval->update([
                'status' => 'approved',
                'payload' => array_merge($approval->payload ?? [], [
                    'approved_at' => now()->toIso8601String(),
                    'always_approved' => $alwaysApprove,
                    'executed_event_ids' => $events->pluck('id')->all(),
                ]),
            ]);

            $events->push($this->recordEvent($session, 'assistant.approval.approved', [
                'approval_id' => $approval->id,
                'action_type' => $action['type'] ?? null,
            ], 'approvals.approve', 'succeeded'));

            return ['approval' => $approval->refresh(), 'events' => $events];
        });
    }

    public function deny(Approval $approval): Approval
    {
        if ($approval->status !== 'pending') {
            throw new InvalidArgumentException('Only pending approvals can be denied.');
        }

        $approval->update([
            'status' => 'denied',
            'payload' => array_merge($approval->payload ?? [], [
                'denied_at' => now()->toIso8601String(),
            ]),
        ]);

        if ($approval->conversation_session_id) {
            $session = ConversationSession::find($approval->conversation_session_id);
            if ($session) {
                $this->recordEvent($session, 'assistant.approval.denied', [
                    'approval_id' => $approval->id,
                ], 'approvals.deny', 'succeeded');
            }
        }

        return $approval->refresh();
    }

    private function requiresApproval(ConversationSession $session, array $action): bool
    {
        $type = (string) ($action['type'] ?? '');
        $profile = $this->profileForSession($session);
        $alwaysApprovedTypes = $profile?->approval_policy['always_approve_action_types'] ?? [];
        if (is_array($alwaysApprovedTypes) && in_array($type, $alwaysApprovedTypes, true)) {
            return false;
        }

        if (in_array($type, ['event_category.create', 'event_category.update'], true)) {
            return false;
        }

        $risk = strtolower((string) ($action['risk'] ?? 'medium'));
        if (! in_array($risk, ['low', 'safe'], true)) {
            return true;
        }

        if ($this->isLowRiskDashboardAction($type)) {
            return false;
        }

        $category = $this->approvalCategoryFor($type);
        if ($category === null) {
            return false;
        }

        $required = $profile?->approval_policy['require_approval_for']
            ?? config('services.hermes_runtime.require_approval_for', []);

        return is_array($required) && in_array($category, $required, true);
    }

    private function validateActionContract(array $action, array $parameters): void
    {
        $type = (string) ($action['type'] ?? '');

        match ($type) {
            'task.create' => $this->requireActionFields($type, $parameters, ['title']),
            'reminder.create' => $this->requireActionFields($type, $parameters, ['title', 'remind_at']),
            'calendar_event.create', 'calendar.create' => $this->requireCalendarCreateFields($type, $parameters),
            default => null,
        };
    }

    private function normalizeActionParameters(array $action, array $parameters): array
    {
        $type = (string) ($action['type'] ?? '');

        if (! $this->hasParameterValue($parameters, 'title')) {
            $this->copyFirstParameter($parameters, 'title', ['summary', 'name', 'label', 'text']);
        }

        if (in_array($type, ['calendar_event.create', 'calendar.create', 'calendar_event.update', 'calendar.update'], true)) {
            $this->copyFirstParameter($parameters, 'starts_at', ['startsAt', 'start_at', 'startAt', 'start', 'begin_at', 'begins_at']);
            $this->copyFirstParameter($parameters, 'ends_at', ['endsAt', 'end_at', 'endAt', 'end', 'finish_at']);

            if (! $this->hasParameterValue($parameters, 'starts_at')) {
                $this->combineDateAndTimeParameter($parameters, 'starts_at', ['start_time', 'startTime', 'time']);
            }
            if (! $this->hasParameterValue($parameters, 'ends_at')) {
                $this->combineDateAndTimeParameter($parameters, 'ends_at', ['end_time', 'endTime']);
            }
        }

        if (in_array($type, ['reminder.create', 'reminder.update'], true)) {
            $this->copyFirstParameter($parameters, 'remind_at', ['remindAt', 'reminder_at', 'reminderAt', 'reminder_time', 'reminderTime', 'alert_at', 'alertAt', 'time']);
            if (! $this->hasParameterValue($parameters, 'remind_at')) {
                $this->combineDateAndTimeParameter($parameters, 'remind_at', ['reminder_time', 'reminderTime', 'time']);
            }
        }

        if (in_array($type, ['task.create', 'task.update'], true)) {
            $this->copyFirstParameter($parameters, 'due_at', ['dueAt', 'due_date', 'dueDate', 'deadline_at', 'deadlineAt']);
            if (! $this->hasParameterValue($parameters, 'due_at')) {
                $this->combineDateAndTimeParameter($parameters, 'due_at', ['due_time', 'dueTime', 'time']);
            }
        }

        return $parameters;
    }

    /**
     * @param  array<int, string>  $sourceKeys
     */
    private function copyFirstParameter(array &$parameters, string $targetKey, array $sourceKeys): void
    {
        if ($this->hasParameterValue($parameters, $targetKey)) {
            return;
        }

        foreach ($sourceKeys as $sourceKey) {
            if ($this->hasParameterValue($parameters, $sourceKey)) {
                $parameters[$targetKey] = $parameters[$sourceKey];

                return;
            }
        }
    }

    /**
     * @param  array<int, string>  $timeKeys
     */
    private function combineDateAndTimeParameter(array &$parameters, string $targetKey, array $timeKeys): void
    {
        if (! $this->hasParameterValue($parameters, 'date')) {
            return;
        }

        foreach ($timeKeys as $timeKey) {
            if ($this->hasParameterValue($parameters, $timeKey)) {
                $parameters[$targetKey] = $this->combineDateAndWallClockTime($parameters['date'], $parameters[$timeKey]);

                return;
            }
        }
    }

    private function combineDateAndWallClockTime(mixed $date, mixed $time): string
    {
        $dateText = trim((string) $date);
        $timeText = trim((string) $time);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateText) === 1
            && preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)?$/i', $timeText, $matches) === 1) {
            $hour = (int) $matches[1];
            $minute = (int) ($matches[2] ?? 0);
            $meridiem = strtolower((string) ($matches[3] ?? ''));

            if ($meridiem === 'pm' && $hour < 12) {
                $hour += 12;
            } elseif ($meridiem === 'am' && $hour === 12) {
                $hour = 0;
            }

            if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                return sprintf('%sT%02d:%02d:00', $dateText, $hour, $minute);
            }
        }

        return $dateText.' '.$timeText;
    }

    private function hasParameterValue(array $parameters, string $key): bool
    {
        if (! array_key_exists($key, $parameters)) {
            return false;
        }

        $value = $parameters[$key];

        return is_scalar($value) ? trim((string) $value) !== '' : $value !== null;
    }

    private function requireCalendarCreateFields(string $type, array $parameters): void
    {
        $this->requireActionFields($type, $parameters, ['title']);

        $hasStart = collect(['starts_at', 'start_at'])
            ->contains(fn (string $field): bool => $this->hasParameterValue($parameters, $field));

        if (! $hasStart) {
            throw new InvalidArgumentException(sprintf('Structured action %s is missing required field: starts_at.', $type));
        }
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function requireActionFields(string $type, array $parameters, array $fields): void
    {
        $missing = collect($fields)
            ->reject(fn (string $field): bool => $this->hasParameterValue($parameters, $field))
            ->values()
            ->all();

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                'Structured action %s is missing required field%s: %s.',
                $type,
                count($missing) === 1 ? '' : 's',
                implode(', ', $missing)
            ));
        }
    }

    private function rememberAlwaysApprovedAction(ConversationSession $session, array $action): void
    {
        $type = (string) ($action['type'] ?? '');
        if ($type === '') {
            return;
        }

        $profile = $this->profileForSession($session);
        if (! $profile) {
            return;
        }

        $policy = $profile->approval_policy ?? [];
        $alwaysApprovedTypes = $policy['always_approve_action_types'] ?? [];
        if (! is_array($alwaysApprovedTypes)) {
            $alwaysApprovedTypes = [];
        }

        $policy['always_approve_action_types'] = collect($alwaysApprovedTypes)
            ->push($type)
            ->unique()
            ->values()
            ->all();
        $profile->update(['approval_policy' => $policy]);
    }

    private function isLowRiskDashboardAction(string $type): bool
    {
        return in_array($type, [
            'task.create', 'task.update', 'task.delete',
            'reminder.create', 'reminder.update', 'reminder.delete',
            'calendar_event.create', 'calendar_event.update', 'calendar_event.delete', 'calendar.create', 'calendar.update', 'calendar.delete',
            'event_category.create', 'event_category.update', 'event_category.delete',
            'approval.create', 'approval.update', 'approval.approve', 'approval.deny', 'approval.delete',
            'blocker.create', 'blocker.update', 'blocker.resolve', 'blocker.delete',
            'agent_profile.update',
            'workspace_memory.note',
            'conversation_session.update',
            'activity_event.create',
        ], true);
    }

    private function approvalCategoryFor(string $type): ?string
    {
        return match (true) {
            str_starts_with($type, 'email.'), str_starts_with($type, 'mail.') => 'outgoing_mail',
            str_starts_with($type, 'payment.'), str_starts_with($type, 'payments.') => 'payments',
            str_starts_with($type, 'deploy.'), str_starts_with($type, 'deployment.') => 'deployments',
            str_contains($type, 'delete'), str_contains($type, 'destroy') => 'destructive_actions',
            default => null,
        };
    }

    private function approvalTitle(array $action): string
    {
        if (isset($action['title']) && is_string($action['title']) && $action['title'] !== '') {
            return $action['title'];
        }

        return 'Approve '.str_replace(['.', '_'], ' ', (string) ($action['type'] ?? 'Hermes action'));
    }

    /**
     * @return Collection<int, ActivityEvent>
     */
    private function executeAction(ConversationSession $session, array $action): Collection
    {
        $type = (string) ($action['type'] ?? '');
        $parameters = $action['parameters'] ?? [];
        if (! is_array($parameters)) {
            $parameters = [];
        }
        $session = $this->sessionForAction($session, $parameters);

        return match ($type) {
            'task.create' => collect([$this->createTask($session, $parameters)]),
            'task.update' => collect([$this->updateTask($session, $parameters)]),
            'task.delete' => collect([$this->deleteOwned(Task::class, $session, $parameters, 'assistant.task.deleted', 'tasks.delete', 'task_id')]),
            'reminder.create' => collect([$this->createReminder($session, $parameters)]),
            'reminder.update' => collect([$this->updateReminder($session, $parameters)]),
            'reminder.delete' => collect([$this->deleteOwned(Reminder::class, $session, $parameters, 'assistant.reminder.deleted', 'reminders.delete', 'reminder_id')]),
            'calendar_event.create', 'calendar.create' => collect([$this->createCalendarEvent($session, $parameters)]),
            'calendar_event.update', 'calendar.update' => collect([$this->updateCalendarEvent($session, $parameters)]),
            'calendar_event.delete', 'calendar.delete' => collect([$this->deleteOwned(CalendarEvent::class, $session, $parameters, 'assistant.calendar_event.deleted', 'calendar.delete', 'calendar_event_id')]),
            'event_category.create' => collect([$this->createEventCategory($session, $parameters)]),
            'event_category.update' => collect([$this->updateEventCategory($session, $parameters)]),
            'event_category.delete' => collect([$this->deleteEventCategory($session, $parameters)]),
            'approval.create' => collect([$this->createApproval($session, $parameters)]),
            'approval.update' => collect([$this->updateApproval($session, $parameters)]),
            'approval.approve' => $this->approveOwned($session, $parameters),
            'approval.deny' => collect([$this->denyOwned($session, $parameters)]),
            'approval.delete' => collect([$this->deleteOwned(Approval::class, $session, $parameters, 'assistant.approval.deleted', 'approvals.delete', 'approval_id')]),
            'blocker.create' => collect([$this->createBlocker($session, $parameters)]),
            'blocker.update' => collect([$this->updateBlocker($session, $parameters)]),
            'blocker.resolve' => collect([$this->resolveBlocker($session, $parameters)]),
            'blocker.delete' => collect([$this->deleteOwned(Blocker::class, $session, $parameters, 'assistant.blocker.deleted', 'blockers.delete', 'blocker_id')]),
            'agent_profile.update' => collect([$this->updateAgentProfile($session, $parameters)]),
            'workspace_memory.note' => collect([$this->createWorkspaceMemoryNote($session, $parameters)]),
            'conversation_session.update' => collect([$this->updateConversationSession($session, $parameters)]),
            'activity_event.create' => collect([$this->createActivityEvent($session, $parameters)]),
            default => collect([$this->recordEvent($session, 'assistant.action.skipped', [
                'action_type' => $type,
                'reason' => 'Unsupported structured action type.',
            ], 'structured_action', 'skipped')]),
        };
    }

    private function createTask(ConversationSession $session, array $parameters): ActivityEvent
    {
        $task = Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->created_by_user_id ?: $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => $this->stringParameter($parameters, 'title', 'Task'),
            'type' => $this->stringParameter($parameters, 'type', 'todo'),
            'status' => $this->stringParameter($parameters, 'status', 'open'),
            'notes' => $parameters['notes'] ?? null,
            'category' => $this->resourceCategory($parameters),
            'color' => $this->resourceColor($parameters),
            'is_critical' => (bool) ($parameters['is_critical'] ?? $parameters['isCritical'] ?? false),
            'due_at' => isset($parameters['due_at']) ? $this->parseDashboardDateTime($session, $parameters['due_at']) : null,
            'metadata' => array_merge(
                ['created_by' => 'structured_hermes_action'],
                is_array($parameters['metadata'] ?? null) ? $parameters['metadata'] : []
            ),
        ]);

        return $this->recordEvent($session, 'assistant.task.created', [
            'task_id' => $task->id,
            'title' => $task->title,
        ], 'tasks.create', 'succeeded');
    }

    private function createReminder(ConversationSession $session, array $parameters): ActivityEvent
    {
        $reminder = Reminder::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->created_by_user_id ?: $session->user_id,
            'conversation_session_id' => $session->id,
            'calendar_event_id' => $parameters['calendar_event_id'] ?? null,
            'title' => $this->stringParameter($parameters, 'title', 'Reminder'),
            'notes' => $parameters['notes'] ?? null,
            'category' => $this->resourceCategory($parameters),
            'color' => $this->resourceColor($parameters),
            'is_critical' => (bool) ($parameters['is_critical'] ?? $parameters['isCritical'] ?? false),
            'remind_at' => $this->parseDashboardDateTime($session, $parameters['remind_at']),
            'status' => $this->stringParameter($parameters, 'status', 'scheduled'),
            'metadata' => array_merge(
                ['created_by' => 'structured_hermes_action'],
                is_array($parameters['metadata'] ?? null) ? $parameters['metadata'] : []
            ),
        ]);

        return $this->recordEvent($session, 'assistant.reminder.created', [
            'reminder_id' => $reminder->id,
            'title' => $reminder->title,
            'remind_at' => $reminder->remind_at->toIso8601String(),
        ], 'reminders.create', 'succeeded');
    }

    private function createCalendarEvent(ConversationSession $session, array $parameters): ActivityEvent
    {
        $startsAtValue = $parameters['starts_at'] ?? $parameters['start_at'];
        $endsAtValue = $parameters['ends_at'] ?? $parameters['end_at'] ?? null;
        $startsAt = $this->parseDashboardDateTime($session, $startsAtValue);
        $endsAt = $endsAtValue !== null ? $this->parseDashboardDateTime($session, $endsAtValue) : null;

        $calendarEvent = CalendarEvent::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->created_by_user_id ?: $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => $this->stringParameter($parameters, 'title', 'Event'),
            'description' => $parameters['description'] ?? null,
            'location' => $parameters['location'] ?? null,
            'category' => $this->resourceCategory($parameters),
            'color' => $this->resourceColor($parameters),
            'is_critical' => (bool) ($parameters['is_critical'] ?? $parameters['isCritical'] ?? false),
            'recurrence' => $parameters['recurrence'] ?? null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => $this->stringParameter($parameters, 'status', 'scheduled'),
            'metadata' => array_filter([
                'created_by' => 'structured_hermes_action',
                'recurrence' => $parameters['recurrence'] ?? null,
            ], static fn ($value) => $value !== null),
        ]);
        $this->exportCalendarEventBestEffort($session, $calendarEvent->refresh());

        return $this->recordEvent($session, 'assistant.calendar_event.created', [
            'calendar_event_id' => $calendarEvent->id,
            'title' => $calendarEvent->title,
            'starts_at' => $calendarEvent->starts_at->toIso8601String(),
            'ends_at' => $calendarEvent->ends_at?->toIso8601String(),
        ], 'calendar.create', 'succeeded');
    }

    private function updateTask(ConversationSession $session, array $parameters): ActivityEvent
    {
        $task = $this->ownedModel(Task::class, $session, $parameters);
        $updates = $this->onlyPresent($parameters, ['title', 'type', 'status', 'notes', 'category', 'color', 'is_critical', 'metadata']);
        $updates = $this->withDefaultUncategorizedColor($updates);
        if (array_key_exists('due_at', $parameters)) {
            $updates['due_at'] = $parameters['due_at'] ? $this->parseDashboardDateTime($session, $parameters['due_at']) : null;
        }
        $task->update($updates);

        return $this->recordEvent($session, 'assistant.task.updated', ['task_id' => $task->id, 'title' => $task->title], 'tasks.update', 'succeeded');
    }

    private function updateReminder(ConversationSession $session, array $parameters): ActivityEvent
    {
        $reminder = $this->ownedModel(Reminder::class, $session, $parameters);
        $updates = $this->onlyPresent($parameters, ['title', 'notes', 'status', 'category', 'color', 'is_critical', 'metadata']);
        $updates = $this->withDefaultUncategorizedColor($updates);
        if (array_key_exists('remind_at', $parameters)) {
            $updates['remind_at'] = $this->parseDashboardDateTime($session, $parameters['remind_at']);
        }
        $reminder->update($updates);

        return $this->recordEvent($session, 'assistant.reminder.updated', ['reminder_id' => $reminder->id, 'title' => $reminder->title], 'reminders.update', 'succeeded');
    }

    private function updateCalendarEvent(ConversationSession $session, array $parameters): ActivityEvent
    {
        $calendarEvent = $this->calendarEventForUpdate($session, $parameters);
        $updates = $this->onlyPresent($parameters, ['title', 'description', 'location', 'category', 'color', 'is_critical', 'recurrence', 'status', 'metadata']);
        $updates = $this->withDefaultUncategorizedColor($updates);
        if (array_key_exists('starts_at', $parameters)) {
            $updates['starts_at'] = $this->parseDashboardDateTime($session, $parameters['starts_at']);
        }
        if (array_key_exists('ends_at', $parameters)) {
            $updates['ends_at'] = $parameters['ends_at'] ? $this->parseDashboardDateTime($session, $parameters['ends_at']) : null;
        } elseif (isset($updates['starts_at']) && $calendarEvent->starts_at && $calendarEvent->ends_at) {
            $updates['ends_at'] = (clone $updates['starts_at'])->addSeconds(
                $calendarEvent->starts_at->diffInSeconds($calendarEvent->ends_at)
            );
        }
        $calendarEvent->update($updates);
        $this->exportCalendarEventBestEffort($session, $calendarEvent->refresh());

        return $this->recordEvent($session, 'assistant.calendar_event.updated', ['calendar_event_id' => $calendarEvent->id, 'title' => $calendarEvent->title], 'calendar.update', 'succeeded');
    }

    private function exportCalendarEventBestEffort(ConversationSession $session, CalendarEvent $calendarEvent): void
    {
        try {
            $this->googleCalendar->exportEvent($calendarEvent);
        } catch (\Throwable $exception) {
            $this->recordEvent($session, 'assistant.google_calendar.export_failed', [
                'calendar_event_id' => $calendarEvent->id,
                'title' => $calendarEvent->title,
                'reason' => 'Google Calendar export failed after the local calendar update succeeded.',
                'exception' => $exception->getMessage(),
            ], 'google_calendar.export', 'failed');
        }
    }

    private function createEventCategory(ConversationSession $session, array $parameters): ActivityEvent
    {
        $category = EventCategory::updateOrCreate(
            [
                'user_id' => $session->user_id,
                'workspace_id' => $session->workspace_id,
                'name' => $this->stringParameter($parameters, 'name', 'Personal'),
            ],
            [
                'color' => $this->stringParameter($parameters, 'color', self::DEFAULT_CATEGORY_COLOR),
                'metadata' => array_merge(
                    ['created_by' => 'structured_hermes_action'],
                    is_array($parameters['metadata'] ?? null) ? $parameters['metadata'] : []
                ),
            ]
        );

        return $this->recordEvent($session, 'assistant.event_category.saved', ['event_category_id' => $category->id, 'name' => $category->name], 'event_categories.create', 'succeeded');
    }

    private function updateEventCategory(ConversationSession $session, array $parameters): ActivityEvent
    {
        $category = $this->ownedModel(EventCategory::class, $session, $parameters);
        $oldName = $category->name;
        $category->update($this->onlyPresent($parameters, ['name', 'color', 'metadata']));
        if ($oldName !== $category->name) {
            CalendarEvent::where('user_id', $session->user_id)
                ->where('workspace_id', $session->workspace_id)
                ->where('category', $oldName)
                ->update(['category' => $category->name]);
        }

        return $this->recordEvent($session, 'assistant.event_category.updated', ['event_category_id' => $category->id, 'name' => $category->name], 'event_categories.update', 'succeeded');
    }

    private function deleteEventCategory(ConversationSession $session, array $parameters): ActivityEvent
    {
        $category = $this->ownedModel(EventCategory::class, $session, $parameters);
        CalendarEvent::where('user_id', $session->user_id)
            ->where('workspace_id', $session->workspace_id)
            ->where('category', $category->name)
            ->update(['category' => null, 'color' => self::DEFAULT_CATEGORY_COLOR]);
        Task::where('user_id', $session->user_id)
            ->where('workspace_id', $session->workspace_id)
            ->where('category', $category->name)
            ->update(['category' => null, 'color' => self::DEFAULT_CATEGORY_COLOR]);
        Reminder::where('user_id', $session->user_id)
            ->where('workspace_id', $session->workspace_id)
            ->where('category', $category->name)
            ->update(['category' => null, 'color' => self::DEFAULT_CATEGORY_COLOR]);
        $id = $category->id;
        $name = $category->name;
        $category->delete();

        return $this->recordEvent($session, 'assistant.event_category.deleted', ['event_category_id' => $id, 'name' => $name], 'event_categories.delete', 'succeeded');
    }

    private function createApproval(ConversationSession $session, array $parameters): ActivityEvent
    {
        $approval = Approval::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'title' => $this->stringParameter($parameters, 'title', 'Approval requested'),
            'description' => $parameters['description'] ?? null,
            'status' => $this->stringParameter($parameters, 'status', 'pending'),
            'payload' => $parameters['payload'] ?? null,
        ]);

        return $this->recordEvent($session, 'assistant.approval.created_directly', ['approval_id' => $approval->id, 'title' => $approval->title], 'approvals.create', 'succeeded');
    }

    private function updateApproval(ConversationSession $session, array $parameters): ActivityEvent
    {
        $approval = $this->ownedModel(Approval::class, $session, $parameters);
        $approval->update($this->onlyPresent($parameters, ['title', 'description', 'status', 'payload']));

        return $this->recordEvent($session, 'assistant.approval.updated', ['approval_id' => $approval->id, 'title' => $approval->title], 'approvals.update', 'succeeded');
    }

    private function approveOwned(ConversationSession $session, array $parameters): Collection
    {
        $approval = $this->ownedModel(Approval::class, $session, $parameters);

        return $this->approve($approval)['events'];
    }

    private function denyOwned(ConversationSession $session, array $parameters): ActivityEvent
    {
        $approval = $this->ownedModel(Approval::class, $session, $parameters);
        $this->deny($approval);

        return $this->recordEvent($session, 'assistant.approval.denied_by_action', ['approval_id' => $approval->id], 'approvals.deny', 'succeeded');
    }

    private function createBlocker(ConversationSession $session, array $parameters): ActivityEvent
    {
        $blocker = Blocker::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'reason' => $this->stringParameter($parameters, 'reason', $this->stringParameter($parameters, 'title', 'Needs attention')),
            'status' => $this->stringParameter($parameters, 'status', 'open'),
            'context' => $parameters['context'] ?? null,
        ]);

        return $this->recordEvent($session, 'assistant.blocker.created', ['blocker_id' => $blocker->id, 'reason' => $blocker->reason], 'blockers.create', 'succeeded');
    }

    private function updateBlocker(ConversationSession $session, array $parameters): ActivityEvent
    {
        $blocker = $this->ownedModel(Blocker::class, $session, $parameters);
        $blocker->update($this->onlyPresent($parameters, ['reason', 'status', 'context']));

        return $this->recordEvent($session, 'assistant.blocker.updated', ['blocker_id' => $blocker->id, 'status' => $blocker->status], 'blockers.update', 'succeeded');
    }

    private function resolveBlocker(ConversationSession $session, array $parameters): ActivityEvent
    {
        $blocker = $this->ownedModel(Blocker::class, $session, $parameters);
        $context = is_array($blocker->context) ? $blocker->context : [];
        $blocker->update([
            'status' => 'resolved',
            'context' => array_merge($context, ['resolved_at' => now()->toIso8601String()]),
        ]);

        return $this->recordEvent($session, 'assistant.blocker.resolved', ['blocker_id' => $blocker->id], 'blockers.resolve', 'succeeded');
    }

    private function updateAgentProfile(ConversationSession $session, array $parameters): ActivityEvent
    {
        $profile = $this->profileForSession($session);
        $updates = $this->onlyPresent($parameters, ['slug', 'display_name', 'status', 'provider', 'model', 'router_mode', 'runtime_home', 'tool_policy', 'approval_policy', 'metadata']);
        if (isset($parameters['settings']) && is_array($parameters['settings'])) {
            $agentProfiles = app(AgentProfileService::class);
            $profile = $agentProfiles->mergeSettings($profile, $parameters['settings'], 'agent');
            if (data_get($parameters['settings'], 'onboarding.completed') === true) {
                User::where('id', $session->user_id)->update(['onboard_complete' => $agentProfiles->preferencesReady($profile)]);
            }
        }
        if ($updates !== []) {
            $profile->update($updates);
        }

        return $this->recordEvent($session, 'assistant.agent_profile.updated', ['agent_profile_id' => $profile->id], 'agent_profile.update', 'succeeded');
    }

    private function updateConversationSession(ConversationSession $session, array $parameters): ActivityEvent
    {
        $session->update($this->onlyPresent($parameters, ['title', 'status', 'runtime_mode', 'metadata']));

        return $this->recordEvent($session, 'assistant.conversation_session.updated', ['session_id' => $session->id, 'title' => $session->title], 'conversation_sessions.update', 'succeeded');
    }

    private function createWorkspaceMemoryNote(ConversationSession $session, array $parameters): ActivityEvent
    {
        $workspace = Workspace::findOrFail($session->workspace_id);
        $actor = User::findOrFail($session->user_id);
        app(WorkspaceService::class)->authorizeMember($actor, $workspace);
        $profile = app(AgentProfileService::class)->appendWorkspaceMemoryNote(
            $workspace,
            $actor,
            $this->stringParameter($parameters, 'note', $this->stringParameter($parameters, 'content', ''))
        );

        return $this->recordEvent($session, 'assistant.workspace_memory.noted', [
            'workspace_id' => $workspace->id,
            'agent_profile_id' => $profile->id,
            'note' => $this->stringParameter($parameters, 'note', $this->stringParameter($parameters, 'content', '')),
        ], 'workspace_memory.note', 'succeeded');
    }

    private function createActivityEvent(ConversationSession $session, array $parameters): ActivityEvent
    {
        return $this->recordEvent(
            $session,
            $this->stringParameter($parameters, 'event_type', 'assistant.note'),
            is_array($parameters['payload'] ?? null) ? $parameters['payload'] : [],
            isset($parameters['tool_name']) ? (string) $parameters['tool_name'] : 'assistant',
            $this->stringParameter($parameters, 'status', 'recorded')
        );
    }

    private function profileForSession(ConversationSession $session): AgentProfile
    {
        if ($session->workspace_id) {
            $workspace = $session->workspace ?: Workspace::find($session->workspace_id);
            $user = User::find($session->user_id);
            if ($workspace && $user) {
                return app(AgentProfileService::class)->ensureForWorkspace($workspace, $user);
            }
        }

        return app(AgentProfileService::class)->ensureForUser(User::findOrFail($session->user_id));
    }

    private function sessionForAction(ConversationSession $session, array $parameters): ConversationSession
    {
        $targetWorkspaceId = $parameters['target_workspace_id'] ?? $parameters['workspace_id'] ?? null;
        if (! $targetWorkspaceId || (int) $targetWorkspaceId === (int) $session->workspace_id) {
            return $session;
        }

        $workspace = Workspace::findOrFail((int) $targetWorkspaceId);
        $actor = User::findOrFail($session->user_id);
        app(WorkspaceService::class)->authorizeMember($actor, $workspace);

        $targetSession = clone $session;
        $targetSession->workspace_id = $workspace->id;
        $targetSession->setRelation('workspace', $workspace);

        return $targetSession;
    }

    private function deleteOwned(string $modelClass, ConversationSession $session, array $parameters, string $eventType, string $toolName, string $payloadKey): ActivityEvent
    {
        $model = $this->ownedModel($modelClass, $session, $parameters);
        $id = $model->id;
        $model->delete();

        return $this->recordEvent($session, $eventType, [$payloadKey => $id], $toolName, 'succeeded');
    }

    private function ownedModel(string $modelClass, ConversationSession $session, array $parameters): mixed
    {
        $id = $parameters['id'] ?? null;
        if (! $id) {
            throw new InvalidArgumentException('Structured action is missing required resource id.');
        }

        $query = $modelClass::where('user_id', $session->user_id);
        if ($session->workspace_id) {
            $query->where('workspace_id', $session->workspace_id);
        }

        return $query->findOrFail($id);
    }

    private function calendarEventForUpdate(ConversationSession $session, array $parameters): CalendarEvent
    {
        if (! empty($parameters['id'])) {
            return $this->ownedModel(CalendarEvent::class, $session, $parameters);
        }

        $query = CalendarEvent::query()->where('user_id', $session->user_id);
        if ($session->workspace_id) {
            $query->where('workspace_id', $session->workspace_id);
        }

        $title = $this->calendarEventLookupTitle($parameters);
        if ($title !== null) {
            $query->where('title', 'like', '%'.addcslashes($title, '%_\\').'%');
        }

        $candidates = $query->latest('updated_at')->limit(20)->get();
        if ($candidates->isEmpty()) {
            throw new InvalidArgumentException('Bean could not find a matching calendar event to update.');
        }

        $sourceDate = $this->calendarEventSourceDate($session, $parameters);
        if ($sourceDate !== null) {
            $timezone = $this->clientTimezoneOffset($session) ?: 'UTC';
            $dateMatches = $candidates->filter(function (CalendarEvent $event) use ($sourceDate, $timezone): bool {
                return $event->starts_at?->copy()->setTimezone($timezone)->toDateString() === $sourceDate;
            })->values();

            if ($dateMatches->count() === 1) {
                return $dateMatches->first();
            }

            if ($dateMatches->count() > 1) {
                $candidates = $dateMatches;
            }
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        throw new InvalidArgumentException('Bean found multiple matching calendar events. Please include the event time or date.');
    }

    private function calendarEventLookupTitle(array $parameters): ?string
    {
        foreach (['match_title', 'original_title', 'current_title', 'lookup_title', 'title'] as $key) {
            $value = trim((string) ($parameters[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function calendarEventSourceDate(ConversationSession $session, array $parameters): ?string
    {
        foreach (['from_date', 'current_date', 'original_date', 'match_date', 'date'] as $key) {
            $value = trim((string) ($parameters[$key] ?? ''));
            if ($value !== '') {
                return $this->parseDashboardDateTime($session, $value)
                    ->setTimezone($this->clientTimezoneOffset($session) ?: 'UTC')
                    ->toDateString();
            }
        }

        $message = strtolower((string) $session->messages()->where('role', 'user')->latest('id')->value('content'));
        if ($message === '') {
            return null;
        }

        $now = $this->clientNow($session);
        if (preg_match('/\btomorrow\b/', $message)) {
            return $now->copy()->addDay()->toDateString();
        }

        if (preg_match('/\btoday\b/', $message)) {
            return $now->toDateString();
        }

        return null;
    }

    private function clientNow(ConversationSession $session): Carbon
    {
        $message = $session->messages()
            ->where('role', 'user')
            ->latest('id')
            ->first();
        $metadata = is_array($message?->metadata) ? $message->metadata : [];
        $currentLocalTime = data_get($metadata, 'client_context.current_local_time');
        if (is_string($currentLocalTime) && trim($currentLocalTime) !== '') {
            return Carbon::parse($currentLocalTime);
        }

        $offset = $this->clientTimezoneOffset($session);

        return $offset ? now()->setTimezone($offset) : now();
    }

    private function onlyPresent(array $parameters, array $keys): array
    {
        $updates = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $parameters)) {
                $updates[$key] = $parameters[$key];
            }
        }

        return $updates;
    }

    private function resourceCategory(array $parameters): ?string
    {
        $category = $parameters['category'] ?? null;
        if (! is_scalar($category) || trim((string) $category) === '') {
            return null;
        }

        return trim((string) $category);
    }

    private function resourceColor(array $parameters): ?string
    {
        if ($this->resourceCategory($parameters) === null) {
            return self::DEFAULT_CATEGORY_COLOR;
        }

        $color = $parameters['color'] ?? null;

        return is_scalar($color) ? (string) $color : null;
    }

    private function withDefaultUncategorizedColor(array $updates): array
    {
        if (array_key_exists('category', $updates) && blank($updates['category'])) {
            $updates['category'] = null;
            $updates['color'] = self::DEFAULT_CATEGORY_COLOR;
        }

        return $updates;
    }

    private function stringParameter(array $parameters, string $key, string $default): string
    {
        return isset($parameters[$key]) && is_scalar($parameters[$key]) && (string) $parameters[$key] !== ''
            ? (string) $parameters[$key]
            : $default;
    }

    private function parseDashboardDateTime(ConversationSession $session, mixed $value): Carbon
    {
        $dateTime = trim((string) $value);
        if ($dateTime === '') {
            return now();
        }

        if ($this->shouldTreatUtcWallClockAsClientLocal($session, $dateTime)) {
            $offset = $this->clientTimezoneOffset($session);

            return Carbon::parse($this->appendTimezoneOffset($this->stripUtcTimezone($dateTime), $offset))->utc();
        }

        if ($this->hasExplicitTimezone($dateTime)) {
            return Carbon::parse($dateTime)->utc();
        }

        $offset = $this->clientTimezoneOffset($session);
        if ($offset !== null && $this->looksLikeLocalDateTime($dateTime)) {
            return Carbon::parse($this->appendTimezoneOffset($dateTime, $offset))->utc();
        }

        return Carbon::parse($dateTime)->utc();
    }

    private function hasExplicitTimezone(string $value): bool
    {
        return (bool) preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', trim($value));
    }

    private function shouldTreatUtcWallClockAsClientLocal(ConversationSession $session, string $value): bool
    {
        if (! preg_match('/(?:Z|\+00:?00)$/i', trim($value))) {
            return false;
        }

        $offset = $this->clientTimezoneOffset($session);
        if ($offset === null) {
            return false;
        }

        $message = $this->latestUserMessageContent($session);
        if ($message === '' || preg_match('/\b(?:utc|gmt|zulu)\b/i', $message)) {
            return false;
        }

        $requestedMinutes = $this->requestedClockMinutes($message);
        if ($requestedMinutes === []) {
            return false;
        }

        $parsed = Carbon::parse($value);
        $utcMinutes = ((int) $parsed->copy()->utc()->format('G')) * 60 + (int) $parsed->copy()->utc()->format('i');
        $local = $parsed->copy()->setTimezone($offset);
        $localMinutes = ((int) $local->format('G')) * 60 + (int) $local->format('i');

        return in_array($utcMinutes, $requestedMinutes, true)
            && ! in_array($localMinutes, $requestedMinutes, true);
    }

    /**
     * @return array<int, int>
     */
    private function requestedClockMinutes(string $message): array
    {
        $minutes = [];

        if (preg_match_all('/\b(1[0-2]|0?[1-9])(?::([0-5]\d))?\s*([ap])\.?m\.?\b/i', $message, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $hour = (int) $match[1];
                $minute = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : 0;
                $meridiem = strtolower($match[3]);
                if ($meridiem === 'p' && $hour !== 12) {
                    $hour += 12;
                }
                if ($meridiem === 'a' && $hour === 12) {
                    $hour = 0;
                }
                $minutes[] = $hour * 60 + $minute;
            }
        }

        if (preg_match_all('/\b([01]?\d|2[0-3]):([0-5]\d)\b/', $message, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $minutes[] = ((int) $match[1]) * 60 + (int) $match[2];
            }
        }

        if (preg_match('/\bnoon\b/i', $message)) {
            $minutes[] = 12 * 60;
        }

        if (preg_match('/\bmidnight\b/i', $message)) {
            $minutes[] = 0;
        }

        return array_values(array_unique($minutes));
    }

    private function latestUserMessageContent(ConversationSession $session): string
    {
        return (string) $session->messages()
            ->where('role', 'user')
            ->latest('id')
            ->value('content');
    }

    private function stripUtcTimezone(string $value): string
    {
        return preg_replace('/(?:Z|\+00:?00)$/i', '', trim($value)) ?? trim($value);
    }

    private function looksLikeLocalDateTime(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}(?:[T\s]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?)?$/', trim($value));
    }

    private function appendTimezoneOffset(string $value, string $offset): string
    {
        return str_replace(' ', 'T', trim($value)).$offset;
    }

    private function clientTimezoneOffset(ConversationSession $session): ?string
    {
        $message = $session->messages()
            ->where('role', 'user')
            ->latest('id')
            ->first();
        $metadata = is_array($message?->metadata) ? $message->metadata : [];
        $context = data_get($metadata, 'client_context');
        if (! is_array($context)) {
            return null;
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
