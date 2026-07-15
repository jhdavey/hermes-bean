<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\AgentProfile;
use App\Models\Approval;
use App\Models\Blocker;
use App\Models\CalendarEvent;
use App\Models\ConversationSession;
use App\Models\EventCategory;
use App\Models\MemoryItem;
use App\Models\Note;
use App\Models\NoteFolder;
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

    private const TASK_TYPES = ['todo', 'chore', 'maintenance'];

    private const TASK_STATUSES = ['open', 'completed'];

    private const REMINDER_STATUSES = ['scheduled', 'completed'];

    private const CALENDAR_STATUSES = ['scheduled', 'cancelled'];

    private const RECURRENCES = ['none', 'daily', 'weekly', 'monthly', 'yearly'];

    private const MEMORY_TYPES = [
        'fact', 'preference', 'identity', 'relationship', 'project',
        'routine', 'constraint', 'decision', 'instruction', 'temporary_context',
    ];

    public function __construct(
        private readonly PlanLimitService $planLimits,
    ) {}

    /**
     * Execute one already schema-validated semantic operation without the
     * generic action normalizer interpreting aliases, prose, or omitted data.
     *
     * @param  array<string, mixed>  $parameters
     * @return Collection<int, ActivityEvent>
     */
    public function applyCanonicalSemanticAction(
        ConversationSession $session,
        string $type,
        array $parameters,
    ): Collection {
        if (! in_array($type, [
            'task.create', 'task.update', 'task.delete',
            'reminder.create', 'reminder.update', 'reminder.delete',
            'calendar_event.create', 'calendar_event.update', 'calendar_event.delete',
            'note.create', 'note.update', 'note.delete',
            'note_folder.create', 'note_folder.update', 'note_folder.delete',
            'event_category.create', 'event_category.update', 'event_category.delete',
            'blocker.create', 'blocker.update', 'blocker.resolve', 'blocker.delete',
            'agent_profile.update',
            'memory.create', 'memory.update', 'memory.delete',
            'conversation_session.update',
        ], true)) {
            throw new InvalidArgumentException("Unsupported canonical semantic action {$type}.");
        }

        $action = [
            'type' => $type,
            'risk' => 'low',
            'parameters' => $parameters,
        ];

        return DB::transaction(function () use ($session, $action, $parameters): Collection {
            $this->validateActionContract($action, $parameters);

            return $this->executeAction(
                $session,
                $action,
            )->values();
        }, 3);
    }

    /**
     * @return array{approval: Approval, events: Collection<int, ActivityEvent>}
     */
    public function approve(Approval $approval): array
    {
        if ($approval->status !== 'pending') {
            throw new InvalidArgumentException('Only pending approvals can be approved.');
        }

        return DB::transaction(function () use ($approval): array {
            $session = ConversationSession::find($approval->conversation_session_id);
            if (! $session) {
                throw new InvalidArgumentException('Approval is not attached to an active conversation session.');
            }

            $action = $approval->payload['action'] ?? null;
            if (! is_array($action)) {
                throw new InvalidArgumentException('Approval payload is missing a structured action.');
            }

            $type = (string) ($action['type'] ?? '');
            $parameters = $action['parameters'] ?? null;
            if (! is_array($parameters)) {
                throw new InvalidArgumentException('Approval payload is missing canonical action parameters.');
            }
            $events = $this->applyCanonicalSemanticAction($session, $type, $parameters);

            $approval->update([
                'status' => 'approved',
                'payload' => array_merge($approval->payload ?? [], [
                    'approved_at' => now()->toIso8601String(),
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

    private function validateActionContract(array $action, array $parameters): void
    {
        $type = (string) ($action['type'] ?? '');
        [$allowed, $required] = $this->canonicalActionContract($type);

        $unknown = array_values(array_diff(array_keys($parameters), $allowed));
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Canonical action %s received unsupported field%s: %s.',
                $type,
                count($unknown) === 1 ? '' : 's',
                implode(', ', $unknown),
            ));
        }
        $this->requireActionFields($type, $parameters, $required);
        if (in_array('id', $allowed, true)
            && (! isset($parameters['id']) || ! is_int($parameters['id']) || $parameters['id'] <= 0)) {
            throw new InvalidArgumentException("Canonical action {$type} requires a positive integer id.");
        }
        if ((str_ends_with($type, '.update') || in_array($type, ['agent_profile.update', 'conversation_session.update'], true))
            && array_values(array_diff(array_keys($parameters), ['id'])) === []) {
            throw new InvalidArgumentException("Canonical action {$type} requires at least one explicit field to update.");
        }

        $nullableStrings = ['notes', 'category', 'color', 'description', 'location', 'plain_text', 'body_html', 'summary', 'expires_at', 'display_name'];
        $stringFields = [
            'title', 'type', 'status', 'notes', 'category', 'color', 'description', 'location',
            'plain_text', 'body_html', 'name', 'reason', 'content', 'summary', 'display_name',
            'expires_at',
        ];
        foreach ($stringFields as $field) {
            if (! array_key_exists($field, $parameters)) {
                continue;
            }
            $value = $parameters[$field];
            if ($field === 'title'
                && $value === null
                && in_array($type, ['memory.create', 'memory.update', 'conversation_session.update'], true)) {
                continue;
            }
            if ($value === null && in_array($field, $nullableStrings, true)) {
                continue;
            }
            if (! is_string($value)) {
                throw new InvalidArgumentException("Canonical action {$type} requires {$field} to be a string.");
            }
            if (in_array($field, ['title', 'name', 'reason', 'content', 'type', 'status'], true)
                && trim($value) === '') {
                throw new InvalidArgumentException("Canonical action {$type} cannot use an empty {$field} value.");
            }
        }
        foreach (['is_critical', 'all_day', 'is_pinned'] as $field) {
            if (array_key_exists($field, $parameters) && ! is_bool($parameters[$field])) {
                throw new InvalidArgumentException("Canonical action {$type} requires {$field} to be boolean.");
            }
        }
        if (str_starts_with($type, 'event_category.')
            && array_key_exists('color', $parameters)
            && $parameters['color'] === null) {
            throw new InvalidArgumentException("Canonical action {$type} requires color to be a string when supplied.");
        }
        foreach (['note_folder_id', 'calendar_event_id'] as $field) {
            if (array_key_exists($field, $parameters)
                && $parameters[$field] !== null
                && (! is_int($parameters[$field]) || $parameters[$field] <= 0)) {
                throw new InvalidArgumentException("Canonical action {$type} requires {$field} to be a positive integer or null.");
            }
        }
        if (array_key_exists('sort_order', $parameters)
            && (! is_int($parameters['sort_order']) || $parameters['sort_order'] < 0)) {
            throw new InvalidArgumentException("Canonical action {$type} requires sort_order to be a non-negative integer.");
        }
        foreach (['confidence', 'importance'] as $field) {
            if (array_key_exists($field, $parameters)
                && (! is_int($parameters[$field]) || $parameters[$field] < 0 || $parameters[$field] > 100)) {
                throw new InvalidArgumentException("Canonical action {$type} requires {$field} between 0 and 100.");
            }
        }
        foreach (['body_delta', 'context'] as $field) {
            if (array_key_exists($field, $parameters)
                && $parameters[$field] !== null
                && ! is_array($parameters[$field])) {
                throw new InvalidArgumentException("Canonical action {$type} requires {$field} to be an object or null.");
            }
        }
        foreach (['due_at', 'completed_at', 'remind_at', 'starts_at', 'ends_at', 'expires_at'] as $field) {
            if (array_key_exists($field, $parameters)
                && $parameters[$field] !== null
                && (! is_string($parameters[$field]) || ! $this->isValidCanonicalTimestamp($parameters[$field]))) {
                throw new InvalidArgumentException("Canonical action {$type} requires {$field} as an absolute ISO-8601 timestamp with an offset.");
            }
        }

        $this->validateCanonicalEnums($type, $parameters);
        $this->validateCanonicalCrossFieldRules($type, $parameters);
    }

    /** @return array{0:list<string>,1:list<string>} */
    private function canonicalActionContract(string $type): array
    {
        $task = ['title', 'type', 'status', 'notes', 'category', 'color', 'is_critical', 'due_at', 'completed_at', 'recurrence'];
        $reminder = ['title', 'notes', 'status', 'category', 'color', 'is_critical', 'remind_at', 'recurrence', 'calendar_event_id'];
        $calendar = ['title', 'description', 'location', 'category', 'color', 'is_critical', 'recurrence', 'starts_at', 'ends_at', 'status', 'all_day'];
        $note = ['title', 'plain_text', 'body_html', 'body_delta', 'note_folder_id', 'is_pinned'];
        $memory = ['type', 'title', 'content', 'summary', 'confidence', 'importance', 'expires_at'];

        return match ($type) {
            'task.create' => [$task, ['title']],
            'task.update' => [[...$task, 'id'], ['id']],
            'task.delete' => [['id'], ['id']],
            'reminder.create' => [$reminder, ['title', 'remind_at']],
            'reminder.update' => [[...$reminder, 'id'], ['id']],
            'reminder.delete' => [['id'], ['id']],
            'calendar_event.create' => [$calendar, ['title', 'starts_at']],
            'calendar_event.update' => [[...$calendar, 'id'], ['id']],
            'calendar_event.delete' => [['id'], ['id']],
            'note.create' => [$note, ['title']],
            'note.update' => [[...$note, 'id'], ['id']],
            'note.delete' => [['id'], ['id']],
            'note_folder.create' => [['name', 'sort_order'], ['name']],
            'note_folder.update' => [['id', 'name', 'sort_order'], ['id']],
            'note_folder.delete' => [['id'], ['id']],
            'event_category.create' => [['name', 'color'], ['name']],
            'event_category.update' => [['id', 'name', 'color'], ['id']],
            'event_category.delete' => [['id'], ['id']],
            'blocker.create' => [['reason', 'status', 'context'], ['reason']],
            'blocker.update' => [['id', 'reason', 'status', 'context'], ['id']],
            'blocker.resolve', 'blocker.delete' => [['id'], ['id']],
            'agent_profile.update' => [['display_name'], []],
            'memory.create' => [$memory, ['type', 'content']],
            'memory.update' => [[...$memory, 'id'], ['id']],
            'memory.delete' => [['id'], ['id']],
            'conversation_session.update' => [['title'], []],
            default => throw new InvalidArgumentException("Unsupported canonical semantic action {$type}."),
        };
    }

    private function validateCanonicalEnums(string $type, array $parameters): void
    {
        if (str_starts_with($type, 'task.')
            && array_key_exists('type', $parameters)
            && ! in_array($parameters['type'], self::TASK_TYPES, true)) {
            throw new InvalidArgumentException("Canonical action {$type} received a non-canonical task type.");
        }
        $statuses = match (true) {
            str_starts_with($type, 'task.') => self::TASK_STATUSES,
            str_starts_with($type, 'reminder.') => self::REMINDER_STATUSES,
            str_starts_with($type, 'calendar_event.') => self::CALENDAR_STATUSES,
            str_starts_with($type, 'blocker.') => ['open', 'resolved'],
            default => null,
        };
        if (is_array($statuses)
            && array_key_exists('status', $parameters)
            && ! in_array($parameters['status'], $statuses, true)) {
            throw new InvalidArgumentException("Canonical action {$type} received a non-canonical status.");
        }
        if (array_key_exists('recurrence', $parameters)
            && ! in_array($parameters['recurrence'], self::RECURRENCES, true)) {
            throw new InvalidArgumentException("Canonical action {$type} received a non-canonical recurrence.");
        }
        if (str_starts_with($type, 'memory.')
            && array_key_exists('type', $parameters)
            && ! in_array($parameters['type'], self::MEMORY_TYPES, true)) {
            throw new InvalidArgumentException("Canonical action {$type} received a non-canonical memory type.");
        }
    }

    private function validateCanonicalCrossFieldRules(string $type, array $parameters): void
    {
        if (in_array($type, ['task.create', 'task.update'], true)) {
            $hasStatus = array_key_exists('status', $parameters);
            $hasCompletedAt = array_key_exists('completed_at', $parameters);
            $status = $hasStatus ? $parameters['status'] : null;
            if ($hasCompletedAt && ! $hasStatus) {
                throw new InvalidArgumentException("Canonical action {$type} requires status with completed_at.");
            }
            if ($status === 'completed' && (! $hasCompletedAt || $parameters['completed_at'] === null)) {
                throw new InvalidArgumentException("Canonical action {$type} requires completed_at when status is completed.");
            }
            if ($status === 'open' && $hasCompletedAt && $parameters['completed_at'] !== null) {
                throw new InvalidArgumentException("Canonical action {$type} requires completed_at=null when status is open.");
            }
            if ($type === 'task.update' && $hasStatus && $status === 'open' && ! $hasCompletedAt) {
                throw new InvalidArgumentException('Canonical task reopening requires completed_at=null.');
            }
        }
        $requiresCompleteCalendarBounds = ($type === 'calendar_event.create' && ($parameters['all_day'] ?? null) === true)
            || ($type === 'calendar_event.update' && array_key_exists('all_day', $parameters));
        if ($requiresCompleteCalendarBounds
            && (! is_string($parameters['starts_at'] ?? null) || ! is_string($parameters['ends_at'] ?? null))) {
            throw new InvalidArgumentException("Canonical action {$type} requires complete bounds when setting all_day.");
        }
        if (isset($parameters['starts_at'], $parameters['ends_at'])
            && Carbon::parse($parameters['ends_at'])->lt(Carbon::parse($parameters['starts_at']))) {
            throw new InvalidArgumentException("Canonical action {$type} cannot set ends_at before starts_at.");
        }
        if (in_array($type, ['note.create', 'note.update'], true)) {
            $bodyFields = array_intersect(['plain_text', 'body_html', 'body_delta'], array_keys($parameters));
            if (count($bodyFields) > 1) {
                throw new InvalidArgumentException("Canonical action {$type} accepts one body representation.");
            }
        }
        if ($type === 'memory.update'
            && array_key_exists('content', $parameters)
            && (! is_string($parameters['content']) || trim($parameters['content']) === '')) {
            throw new InvalidArgumentException('Canonical memory updates require non-empty content when supplied.');
        }
    }

    private function hasParameterValue(array $parameters, string $key): bool
    {
        if (! array_key_exists($key, $parameters)) {
            return false;
        }

        $value = $parameters[$key];

        return is_scalar($value) ? trim((string) $value) !== '' : $value !== null;
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

    /**
     * @return Collection<int, ActivityEvent>
     */
    private function executeAction(
        ConversationSession $session,
        array $action,
    ): Collection {
        $type = (string) ($action['type'] ?? '');
        $parameters = $action['parameters'] ?? [];
        if (! is_array($parameters)) {
            $parameters = [];
        }
        if (str_starts_with($type, 'note.')) {
            $this->guardNotesAccess($session);
        }
        if ($type === 'note.create') {
            $this->guardNoteCreationAccess($session);
        }
        if (str_starts_with($type, 'note_folder.')) {
            $this->guardNotesAccess($session);
        }

        return match ($type) {
            'task.create' => collect([$this->createTask($session, $parameters)]),
            'task.update' => collect([$this->updateTask($session, $parameters)]),
            'task.delete' => collect([$this->deleteOwned(Task::class, $session, $parameters, 'assistant.task.deleted', 'tasks.delete', 'task_id')]),
            'reminder.create' => collect([$this->createReminder($session, $parameters)]),
            'reminder.update' => collect([$this->updateReminder($session, $parameters)]),
            'reminder.delete' => collect([$this->deleteOwned(Reminder::class, $session, $parameters, 'assistant.reminder.deleted', 'reminders.delete', 'reminder_id')]),
            'calendar_event.create' => collect([$this->createCalendarEvent($session, $parameters)]),
            'calendar_event.update' => collect([$this->updateCalendarEvent($session, $parameters)]),
            'calendar_event.delete' => $this->deleteCalendarEvent($session, $parameters),
            'note.create' => collect([$this->createNote($session, $parameters)]),
            'note.update' => collect([$this->updateNote($session, $parameters)]),
            'note.delete' => collect([$this->deleteNote($session, $parameters)]),
            'note_folder.create' => collect([$this->createNoteFolder($session, $parameters)]),
            'note_folder.update' => collect([$this->updateNoteFolder($session, $parameters)]),
            'note_folder.delete' => collect([$this->deleteOwned(NoteFolder::class, $session, $parameters, 'assistant.note_folder.deleted', 'note_folders.delete', 'note_folder_id')]),
            'event_category.create' => collect([$this->createEventCategory($session, $parameters)]),
            'event_category.update' => collect([$this->updateEventCategory($session, $parameters)]),
            'event_category.delete' => collect([$this->deleteEventCategory($session, $parameters)]),
            'blocker.create' => collect([$this->createBlocker($session, $parameters)]),
            'blocker.update' => collect([$this->updateBlocker($session, $parameters)]),
            'blocker.resolve' => collect([$this->resolveBlocker($session, $parameters)]),
            'blocker.delete' => collect([$this->deleteOwned(Blocker::class, $session, $parameters, 'assistant.blocker.deleted', 'blockers.delete', 'blocker_id')]),
            'agent_profile.update' => collect([$this->updateAgentProfile($session, $parameters)]),
            'memory.create' => collect([$this->createMemory($session, $parameters)]),
            'memory.update' => collect([$this->updateMemory($session, $parameters)]),
            'memory.delete' => collect([$this->deleteMemory($session, $parameters)]),
            'conversation_session.update' => collect([$this->updateConversationSession($session, $parameters)]),
            default => throw new InvalidArgumentException("Unsupported canonical semantic action {$type}."),
        };
    }

    private function createTask(ConversationSession $session, array $parameters): ActivityEvent
    {
        $metadata = $this->canonicalMetadataWithRecurrence($parameters, [
            'created_by' => 'structured_hermes_action',
        ]);
        $this->guardRecurringTaskAccess($session, $metadata);
        $status = (string) ($parameters['status'] ?? 'open');
        $completedAt = ($parameters['completed_at'] ?? null) !== null
            ? $this->parseCanonicalDateTime($parameters['completed_at'])
            : null;

        $task = Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->created_by_user_id ?: $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => (string) $parameters['title'],
            'type' => (string) ($parameters['type'] ?? 'todo'),
            'status' => $status,
            'notes' => $parameters['notes'] ?? null,
            'category' => $parameters['category'] ?? null,
            'color' => $this->stringParameter($parameters, 'color', self::DEFAULT_CATEGORY_COLOR),
            'is_critical' => (bool) ($parameters['is_critical'] ?? false),
            'due_at' => ($parameters['due_at'] ?? null) !== null ? $this->parseCanonicalDateTime($parameters['due_at']) : null,
            'completed_at' => $completedAt,
            'metadata' => $metadata,
        ]);

        return $this->recordEvent($session, 'assistant.task.created', [
            'task_id' => $task->id,
            'title' => $task->title,
        ], 'tasks.create', 'succeeded');
    }

    private function createReminder(ConversationSession $session, array $parameters): ActivityEvent
    {
        $metadata = $this->canonicalMetadataWithRecurrence($parameters, [
            'created_by' => 'structured_hermes_action',
        ]);
        $this->guardRecurringReminderAccess($session, $metadata);
        $calendarEventId = $this->ownedCalendarEventId(
            $session,
            $parameters['calendar_event_id'] ?? null,
        );

        $reminder = Reminder::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->created_by_user_id ?: $session->user_id,
            'conversation_session_id' => $session->id,
            'calendar_event_id' => $calendarEventId,
            'title' => (string) $parameters['title'],
            'notes' => $parameters['notes'] ?? null,
            'category' => $parameters['category'] ?? null,
            'color' => $this->stringParameter($parameters, 'color', self::DEFAULT_CATEGORY_COLOR),
            'is_critical' => (bool) ($parameters['is_critical'] ?? false),
            'remind_at' => $this->parseCanonicalDateTime($parameters['remind_at']),
            'status' => (string) ($parameters['status'] ?? 'scheduled'),
            'metadata' => $metadata,
        ]);

        return $this->recordEvent($session, 'assistant.reminder.created', [
            'reminder_id' => $reminder->id,
            'title' => $reminder->title,
            'remind_at' => $reminder->remind_at->toIso8601String(),
        ], 'reminders.create', 'succeeded');
    }

    private function createCalendarEvent(ConversationSession $session, array $parameters): ActivityEvent
    {
        $startsAt = $this->parseCanonicalDateTime($parameters['starts_at']);
        $endsAt = ($parameters['ends_at'] ?? null) !== null
            ? $this->parseCanonicalDateTime($parameters['ends_at'])
            : null;
        $recurrence = $this->canonicalRecurrenceValue($parameters);
        $metadata = $this->canonicalCalendarMetadata([
            'created_by' => 'structured_hermes_action',
        ]);
        $metadata['all_day'] = (bool) ($parameters['all_day'] ?? false);
        $this->guardRecurringCalendarAccess($session, $recurrence);

        $calendarEvent = CalendarEvent::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->created_by_user_id ?: $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => (string) $parameters['title'],
            'description' => $parameters['description'] ?? null,
            'location' => $parameters['location'] ?? null,
            'category' => $parameters['category'] ?? null,
            'color' => $this->stringParameter($parameters, 'color', self::DEFAULT_CATEGORY_COLOR),
            'is_critical' => (bool) ($parameters['is_critical'] ?? false),
            'recurrence' => $recurrence,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => (string) ($parameters['status'] ?? 'scheduled'),
            'metadata' => $metadata,
        ]);

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
        $updates = $this->onlyPresent($parameters, ['title', 'type', 'status', 'notes', 'category', 'color', 'is_critical', 'completed_at']);
        $updates = $this->withDefaultUncategorizedColor($updates);
        if (array_key_exists('recurrence', $parameters)) {
            $updates['metadata'] = $this->canonicalMetadataWithRecurrence(
                $parameters,
                $task->metadata ?? [],
            );
            $this->guardRecurringTaskAccess($session, $updates['metadata']);
        }
        if (array_key_exists('due_at', $parameters)) {
            $updates['due_at'] = $parameters['due_at'] !== null
                ? $this->parseCanonicalDateTime($parameters['due_at'])
                : null;
        }
        if (array_key_exists('completed_at', $updates) && $updates['completed_at'] !== null) {
            $updates['completed_at'] = $this->parseCanonicalDateTime($updates['completed_at']);
        }
        $task->update($updates);

        return $this->recordEvent($session, 'assistant.task.updated', ['task_id' => $task->id, 'title' => $task->title], 'tasks.update', 'succeeded');
    }

    private function updateReminder(ConversationSession $session, array $parameters): ActivityEvent
    {
        $reminder = $this->ownedModel(Reminder::class, $session, $parameters);
        $updates = $this->onlyPresent($parameters, ['title', 'notes', 'status', 'category', 'color', 'is_critical', 'calendar_event_id']);
        $updates = $this->withDefaultUncategorizedColor($updates);
        if (array_key_exists('calendar_event_id', $updates)) {
            $updates['calendar_event_id'] = $this->ownedCalendarEventId(
                $session,
                $updates['calendar_event_id'],
            );
        }
        if (array_key_exists('recurrence', $parameters)) {
            $updates['metadata'] = $this->canonicalMetadataWithRecurrence(
                $parameters,
                $reminder->metadata ?? [],
            );
            $this->guardRecurringReminderAccess($session, $updates['metadata']);
        }
        if (array_key_exists('remind_at', $parameters)) {
            $updates['remind_at'] = $this->parseCanonicalDateTime($parameters['remind_at']);
        }
        $reminder->update($updates);

        return $this->recordEvent($session, 'assistant.reminder.updated', ['reminder_id' => $reminder->id, 'title' => $reminder->title], 'reminders.update', 'succeeded');
    }

    private function updateCalendarEvent(ConversationSession $session, array $parameters): ActivityEvent
    {
        $calendarEvent = $this->ownedModel(CalendarEvent::class, $session, $parameters);
        $updates = $this->onlyPresent($parameters, ['title', 'description', 'location', 'category', 'color', 'is_critical', 'recurrence', 'status']);
        $updates = $this->withDefaultUncategorizedColor($updates);
        if (array_key_exists('recurrence', $parameters)) {
            $updates['recurrence'] = $this->canonicalRecurrenceValue($parameters);
            $updates['metadata'] = $this->canonicalCalendarMetadata($calendarEvent->metadata ?? []);
            $this->guardRecurringCalendarAccess($session, $updates['recurrence']);
        }
        if (array_key_exists('starts_at', $parameters)) {
            $updates['starts_at'] = $this->parseCanonicalDateTime($parameters['starts_at']);
        }
        if (array_key_exists('ends_at', $parameters)) {
            $updates['ends_at'] = $parameters['ends_at'] !== null
                ? $this->parseCanonicalDateTime($parameters['ends_at'])
                : null;
        }
        $effectiveStart = $updates['starts_at'] ?? $calendarEvent->starts_at;
        $effectiveEnd = array_key_exists('ends_at', $updates)
            ? $updates['ends_at']
            : $calendarEvent->ends_at;
        if ($effectiveEnd !== null && $effectiveEnd->lt($effectiveStart)) {
            throw new InvalidArgumentException('Canonical calendar updates cannot leave ends_at before starts_at.');
        }
        $updatedMetadata = is_array($updates['metadata'] ?? null) ? $updates['metadata'] : ($calendarEvent->metadata ?? []);
        if (array_key_exists('all_day', $parameters)) {
            $updatedMetadata['all_day'] = (bool) $parameters['all_day'];
            $updates['metadata'] = $updatedMetadata;
        }
        $calendarEvent->update($updates);

        return $this->recordEvent($session, 'assistant.calendar_event.updated', ['calendar_event_id' => $calendarEvent->id, 'title' => $calendarEvent->title], 'calendar.update', 'succeeded');
    }

    private function createNote(ConversationSession $session, array $parameters): ActivityEvent
    {
        $bodyHtml = $this->stringParameter($parameters, 'body_html', '');
        $plainText = $this->notePlainText($parameters, $bodyHtml);
        $note = Note::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->created_by_user_id ?: $session->user_id,
            'note_folder_id' => $this->noteFolderId($session, $parameters),
            'title' => (string) $parameters['title'],
            'body_html' => $bodyHtml !== '' ? $bodyHtml : nl2br(e($plainText)),
            'plain_text' => $plainText,
            'body_delta' => is_array($parameters['body_delta'] ?? null) ? $parameters['body_delta'] : null,
            'is_pinned' => (bool) ($parameters['is_pinned'] ?? false),
            'metadata' => ['created_by' => 'structured_hermes_action'],
        ]);

        return $this->recordEvent($session, 'assistant.note.created', [
            'note_id' => $note->id,
            'title' => $note->title,
        ], 'notes.create', 'succeeded');
    }

    private function updateNote(ConversationSession $session, array $parameters): ActivityEvent
    {
        $note = $this->ownedModel(Note::class, $session, $parameters);
        $updates = $this->onlyPresent($parameters, ['title', 'body_html', 'plain_text', 'body_delta']);
        if (array_key_exists('is_pinned', $parameters)) {
            $updates['is_pinned'] = (bool) $parameters['is_pinned'];
        }
        if (array_key_exists('note_folder_id', $parameters)) {
            $updates['note_folder_id'] = $this->noteFolderId($session, $parameters);
        }
        if (array_key_exists('body_html', $updates) && ! array_key_exists('plain_text', $updates)) {
            $updates['plain_text'] = $this->notePlainText([], (string) ($updates['body_html'] ?? ''));
        }
        $note->update($updates);

        return $this->recordEvent($session, 'assistant.note.updated', [
            'note_id' => $note->id,
            'title' => $note->title,
        ], 'notes.update', 'succeeded');
    }

    private function deleteNote(ConversationSession $session, array $parameters): ActivityEvent
    {
        $note = $this->ownedModel(Note::class, $session, $parameters);
        $id = $note->id;
        $title = $note->title;
        $note->delete();

        return $this->recordEvent($session, 'assistant.note.deleted', [
            'note_id' => $id,
            'title' => $title,
        ], 'notes.delete', 'succeeded');
    }

    private function createNoteFolder(ConversationSession $session, array $parameters): ActivityEvent
    {
        $folder = NoteFolder::firstOrCreate(
            [
                'user_id' => $session->user_id,
                'workspace_id' => $session->workspace_id,
                'name' => (string) $parameters['name'],
            ],
            [
                'created_by_user_id' => $session->created_by_user_id ?: $session->user_id,
                'sort_order' => (int) ($parameters['sort_order'] ?? 0),
                'metadata' => null,
            ]
        );

        return $this->recordEvent($session, 'assistant.note_folder.created', [
            'note_folder_id' => $folder->id,
            'name' => $folder->name,
        ], 'note_folders.create', 'succeeded');
    }

    private function updateNoteFolder(ConversationSession $session, array $parameters): ActivityEvent
    {
        $folder = $this->ownedModel(NoteFolder::class, $session, $parameters);
        $folder->update($this->onlyPresent($parameters, ['name', 'sort_order']));

        return $this->recordEvent($session, 'assistant.note_folder.updated', [
            'note_folder_id' => $folder->id,
            'name' => $folder->name,
        ], 'note_folders.update', 'succeeded');
    }

    private function createEventCategory(ConversationSession $session, array $parameters): ActivityEvent
    {
        $category = EventCategory::updateOrCreate(
            [
                'user_id' => $session->user_id,
                'workspace_id' => $session->workspace_id,
                'name' => (string) $parameters['name'],
            ],
            [
                'color' => $this->stringParameter($parameters, 'color', self::DEFAULT_CATEGORY_COLOR),
                'metadata' => ['created_by' => 'structured_hermes_action'],
            ]
        );

        return $this->recordEvent($session, 'assistant.event_category.saved', ['event_category_id' => $category->id, 'name' => $category->name], 'event_categories.create', 'succeeded');
    }

    private function updateEventCategory(ConversationSession $session, array $parameters): ActivityEvent
    {
        $category = $this->ownedModel(EventCategory::class, $session, $parameters);
        $oldName = $category->name;
        $category->update($this->onlyPresent($parameters, ['name', 'color']));
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

    private function createBlocker(ConversationSession $session, array $parameters): ActivityEvent
    {
        $blocker = Blocker::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'reason' => (string) $parameters['reason'],
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
        $profile->update($this->onlyPresent($parameters, ['display_name']));

        return $this->recordEvent($session, 'assistant.agent_profile.updated', ['agent_profile_id' => $profile->id], 'agent_profile.update', 'succeeded');
    }

    private function createMemory(ConversationSession $session, array $parameters): ActivityEvent
    {
        $workspace = Workspace::findOrFail($session->workspace_id);
        $actor = User::findOrFail($session->user_id);
        app(WorkspaceService::class)->authorizeMember($actor, $workspace);

        $item = app(BeanMemoryService::class)->createItem($actor, $workspace, [
            'type' => (string) $parameters['type'],
            'title' => $this->optionalScalar($parameters, 'title'),
            'content' => (string) $parameters['content'],
            'summary' => $this->optionalScalar($parameters, 'summary'),
            'confidence' => $this->boundedInteger($parameters, 'confidence', 90),
            'importance' => $this->boundedInteger($parameters, 'importance', 70),
            'expires_at' => $parameters['expires_at'] ?? null,
            'source_type' => 'assistant_tool',
            'source_id' => $session->id,
            'metadata' => ['source' => 'bean_tool'],
        ], $actor);

        return $this->recordEvent($session, 'assistant.memory.created', [
            'memory_item_id' => $item->id,
            'type' => $item->type,
            'content' => $item->content,
        ], 'memory.create', 'succeeded');
    }

    private function updateMemory(ConversationSession $session, array $parameters): ActivityEvent
    {
        $actor = User::findOrFail($session->user_id);
        $item = $this->ownedModel(MemoryItem::class, $session, $parameters);
        $updates = $this->onlyPresent($parameters, ['type', 'title', 'content', 'summary', 'expires_at']);
        if (array_key_exists('confidence', $parameters)) {
            $updates['confidence'] = $this->boundedInteger($parameters, 'confidence', 70);
        }
        if (array_key_exists('importance', $parameters)) {
            $updates['importance'] = $this->boundedInteger($parameters, 'importance', 50);
        }
        $updated = app(BeanMemoryService::class)->updateItem($actor, $item, $updates);

        return $this->recordEvent($session, 'assistant.memory.updated', [
            'memory_item_id' => $updated->id,
            'type' => $updated->type,
        ], 'memory.update', 'succeeded');
    }

    private function deleteMemory(ConversationSession $session, array $parameters): ActivityEvent
    {
        $actor = User::findOrFail($session->user_id);
        $item = $this->ownedModel(MemoryItem::class, $session, $parameters);
        $id = $item->id;
        $content = $item->content;
        app(BeanMemoryService::class)->forgetItem($actor, $item);

        return $this->recordEvent($session, 'assistant.memory.deleted', [
            'memory_item_id' => $id,
            'content' => $content,
        ], 'memory.delete', 'succeeded');
    }

    private function updateConversationSession(ConversationSession $session, array $parameters): ActivityEvent
    {
        $session->update($this->onlyPresent($parameters, ['title']));

        return $this->recordEvent($session, 'assistant.conversation_session.updated', ['session_id' => $session->id, 'title' => $session->title], 'conversation_sessions.update', 'succeeded');
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

    private function deleteOwned(string $modelClass, ConversationSession $session, array $parameters, string $eventType, string $toolName, string $payloadKey): ActivityEvent
    {
        $model = $this->ownedModel($modelClass, $session, $parameters);
        $id = $model->id;
        $title = $model->title ?? $model->name ?? null;
        $model->delete();

        return $this->recordEvent($session, $eventType, array_filter([
            $payloadKey => $id,
            'title' => is_scalar($title) ? (string) $title : null,
        ], fn (mixed $value): bool => $value !== null), $toolName, 'succeeded');
    }

    /**
     * @return Collection<int, ActivityEvent>
     */
    private function deleteCalendarEvent(ConversationSession $session, array $parameters): Collection
    {
        $calendarEvent = $this->ownedModel(CalendarEvent::class, $session, $parameters);
        $calendarId = $calendarEvent->id;
        $calendarTitle = $calendarEvent->title;
        $calendarEvent->delete();

        return collect([$this->recordEvent($session, 'assistant.calendar_event.deleted', [
            'calendar_event_id' => $calendarId,
            'title' => $calendarTitle,
        ], 'calendar.delete', 'succeeded')]);
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

    private function ownedCalendarEventId(ConversationSession $session, mixed $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }

        $query = CalendarEvent::query()->where('user_id', $session->user_id);
        if ($session->workspace_id) {
            $query->where('workspace_id', $session->workspace_id);
        }

        return (int) $query->findOrFail($id)->id;
    }

    private function noteFolderId(ConversationSession $session, array $parameters): ?int
    {
        $folderId = $parameters['note_folder_id'] ?? null;
        if ($folderId === null) {
            return null;
        }

        return (int) NoteFolder::query()
            ->where('user_id', $session->user_id)
            ->where('workspace_id', $session->workspace_id)
            ->findOrFail((int) $folderId)
            ->id;
    }

    private function notePlainText(array $parameters, string $bodyHtml): string
    {
        $plainText = (string) ($parameters['plain_text'] ?? '');
        if ($plainText !== '') {
            return $plainText;
        }

        return trim(html_entity_decode(strip_tags(str_replace(['</div>', '</p>', '<br>', '<br/>', '<br />'], "\n", $bodyHtml)), ENT_QUOTES | ENT_HTML5));
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

    private function optionalScalar(array $parameters, string $key): ?string
    {
        return isset($parameters[$key]) && is_scalar($parameters[$key]) && trim((string) $parameters[$key]) !== ''
            ? trim((string) $parameters[$key])
            : null;
    }

    private function boundedInteger(array $parameters, string $key, int $default): int
    {
        return max(0, min(100, (int) ($parameters[$key] ?? $default)));
    }

    private function canonicalMetadataWithRecurrence(array $parameters, array $base = []): array
    {
        $metadata = $base;
        if (array_key_exists('recurrence', $parameters)) {
            unset(
                $metadata['recurrence'],
                $metadata['days'],
                $metadata['interval'],
                $metadata['unit'],
            );
            $recurrence = $this->canonicalRecurrenceValue($parameters);
            if ($recurrence !== null) {
                $metadata['recurrence'] = $recurrence;
            }
        }

        return array_filter($metadata, static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function canonicalCalendarMetadata(array $metadata): array
    {
        unset(
            $metadata['recurrence'],
            $metadata['days'],
            $metadata['interval'],
            $metadata['unit'],
        );

        return array_filter($metadata, static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function canonicalRecurrenceValue(array $parameters): ?string
    {
        if (! array_key_exists('recurrence', $parameters)) {
            return null;
        }
        $value = $parameters['recurrence'];

        return is_string($value) && $value !== 'none' ? $value : null;
    }

    private function guardRecurringTaskAccess(ConversationSession $session, array $metadata): void
    {
        if ($this->recurrenceRequested($metadata['recurrence'] ?? null) && ! $this->planLimits->canUseRecurringTasks($this->sessionUser($session))) {
            throw new InvalidArgumentException('Recurring tasks are available on Premium, Pro, and Enterprise plans.');
        }
    }

    private function guardRecurringReminderAccess(ConversationSession $session, array $metadata): void
    {
        if ($this->recurrenceRequested($metadata['recurrence'] ?? null) && ! $this->planLimits->canUseRecurringReminders($this->sessionUser($session))) {
            throw new InvalidArgumentException('Recurring reminders are available on Premium, Pro, and Enterprise plans.');
        }
    }

    private function guardRecurringCalendarAccess(ConversationSession $session, mixed $recurrence): void
    {
        if ($this->recurrenceRequested($recurrence) && ! $this->planLimits->canUseRecurringCalendar($this->sessionUser($session))) {
            throw new InvalidArgumentException('Recurring calendar events are available on Premium, Pro, and Enterprise plans.');
        }
    }

    private function guardNotesAccess(ConversationSession $session): void
    {
        if (! $this->planLimits->canUseNotes($this->sessionUser($session))) {
            throw new InvalidArgumentException('Notes are available on this plan after upgrading.');
        }
    }

    private function guardNoteCreationAccess(ConversationSession $session): void
    {
        $response = $this->planLimits->enforceNoteCreationLimit($this->sessionUser($session));
        if ($response !== null) {
            throw new InvalidArgumentException((string) data_get($response->getData(true), 'message', 'Your current plan note limit has been reached.'));
        }
    }

    private function recurrenceRequested(mixed $recurrence): bool
    {
        return is_string($recurrence) && $recurrence !== '' && $recurrence !== 'none';
    }

    private function sessionUser(ConversationSession $session): User
    {
        return User::findOrFail($session->user_id);
    }

    private function parseCanonicalDateTime(mixed $value): Carbon
    {
        if (! is_string($value) || ! $this->isValidCanonicalTimestamp($value)) {
            throw new InvalidArgumentException('Canonical semantic timestamps require a valid ISO-8601 value with an explicit offset.');
        }

        return Carbon::parse($value)->utc();
    }

    private function isValidCanonicalTimestamp(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,6}))?)?(Z|[+-](\d{2}):(\d{2}))$/', $value, $parts) !== 1) {
            return false;
        }

        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23
            || (int) $parts[5] > 59
            || (isset($parts[6]) && $parts[6] !== '' && (int) $parts[6] > 59)) {
            return false;
        }
        if ($parts[8] === 'Z') {
            return true;
        }

        $offsetHour = (int) ($parts[9] ?? 0);
        $offsetMinute = (int) ($parts[10] ?? 0);

        return $offsetHour <= 14 && $offsetMinute <= 59 && ($offsetHour < 14 || $offsetMinute === 0);
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
