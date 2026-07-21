<?php

namespace App\Services\Domain;

use App\Models\CalendarEvent;
use App\Models\EventCategory;
use App\Models\Note;
use App\Models\NoteFolder;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceItemLink;
use App\Models\WorkspaceMembership;
use App\Services\PlanLimitService;
use App\Services\RecurringCalendarEventService;
use App\Services\WorkspaceItemSyncService;
use App\Services\WorkspaceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use League\CommonMark\GithubFlavoredMarkdownConverter;

class DomainResourceService
{
    private const DEFAULT_CATEGORY_COLOR = '#34C759';
    private const RECURRENCES = ['none', 'daily', 'weekly', 'monthly', 'yearly', 'specific_days', 'interval'];
    private const RECURRENCE_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    private const RECURRENCE_UNITS = ['days', 'weeks', 'months', 'years'];
    private const LEGACY_RECURRENCE_METADATA_KEYS = [
        'recurring',
        'rrule',
        'specific_days',
        'specificDays',
        'interval_unit',
        'intervalUnit',
    ];

    public function __construct(
        private readonly WorkspaceService $workspaces,
        private readonly WorkspaceItemSyncService $sync,
        private readonly PlanLimitService $planLimits,
        private readonly RecurringCalendarEventService $recurringCalendarEvents,
    ) {}

    public function createNoteFolder(User $user, array $attributes): NoteFolder
    {
        if (! $this->planLimits->canUseNotes($user)) {
            $this->throwLimit('Notes are available on this plan after upgrading.');
        }
        $workspace = $this->workspace($user, $attributes['workspace_id'] ?? null);
        if (($attributes['sort_order'] ?? null) === null) {
            $attributes['sort_order'] = ((int) NoteFolder::query()
                ->where('user_id', $user->id)
                ->where('workspace_id', $workspace->id)
                ->max('sort_order')) + 1;
        }

        $existing = NoteFolder::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspace->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($attributes['name']))])
            ->first();

        if ($existing) {
            return $existing;
        }

        return NoteFolder::create($this->owned($user, $attributes, NoteFolder::class))->refresh();
    }

    public function updateNoteFolder(User $user, NoteFolder|int|string $noteFolder, array $attributes): NoteFolder
    {
        if (! $this->planLimits->canUseNotes($user)) {
            $this->throwLimit('Notes are available on this plan after upgrading.');
        }
        $model = $noteFolder instanceof NoteFolder ? $noteFolder : $this->scoped(NoteFolder::query(), $user, null, false)->findOrFail($noteFolder);
        $model->update($attributes);
        return $model->refresh();
    }

    public function deleteNoteFolder(User $user, NoteFolder|int|string $noteFolder): void
    {
        if (! $this->planLimits->canUseNotes($user)) {
            $this->throwLimit('Notes are available on this plan after upgrading.');
        }
        $model = $noteFolder instanceof NoteFolder ? $noteFolder : $this->scoped(NoteFolder::query(), $user, null, false)->findOrFail($noteFolder);
        Note::query()->where('user_id', $user->id)->where('note_folder_id', $model->id)->update(['note_folder_id' => null]);
        $model->delete();
    }

    public function createTask(User $user, array $attributes): Task
    {
        $this->assertCanonicalRecurrenceMetadata($attributes);
        $this->normalizeDateFields($attributes, ['due_at', 'completed_at']);
        $attributes = $this->withDefaultUncategorizedColor($attributes, true);
        $attributes = $this->withoutNullDefaults($attributes, ['is_critical']);
        if ($this->taskRecurrenceRequested($attributes) && ! $this->planLimits->canUseRecurringTasks($user)) {
            $this->throwLimit('Recurring tasks are available on Premium, Pro, and Enterprise plans.');
        }
        if (($attributes['status'] ?? null) === 'completed' && empty($attributes['completed_at'])) {
            $attributes['completed_at'] = now();
        }

        $syncTo = $attributes['sync_to_workspace_ids'] ?? [];
        unset($attributes['sync_to_workspace_ids']);
        $task = Task::create($this->owned($user, $attributes, Task::class));
        $this->syncTo($user, $task, $syncTo);

        return $task->refresh();
    }

    public function updateTask(User $user, Task|int|string $task, array $attributes): Task
    {
        $model = $task instanceof Task ? $task : $this->scoped(Task::query(), $user, null, false)->findOrFail($task);
        $this->assertCanonicalRecurrenceMetadata($attributes);
        $this->normalizeDateFields($attributes, ['due_at', 'completed_at']);
        $attributes = $this->withDefaultUncategorizedColor($attributes);
        $attributes = $this->withoutNullDefaults($attributes, ['is_critical']);
        if ($this->taskRecurrenceRequested($attributes) && ! $this->planLimits->canUseRecurringTasks($user)) {
            $this->throwLimit('Recurring tasks are available on Premium, Pro, and Enterprise plans.');
        }

        $statusProvided = array_key_exists('status', $attributes);
        if ($statusProvided) {
            $willBeCompleted = $attributes['status'] === 'completed';
            if ($willBeCompleted && $this->advanceRecurringTaskCompletion($model, $attributes)) {
                $willBeCompleted = false;
            } elseif ($willBeCompleted && $model->completed_at === null && ! array_key_exists('completed_at', $attributes)) {
                $attributes['completed_at'] = now();
            }
            if (! $willBeCompleted && ! array_key_exists('completed_at', $attributes)) {
                $attributes['completed_at'] = null;
            }
            if (! $willBeCompleted && ! array_key_exists('due_at', $attributes) && $model->due_at !== null && $model->due_at->lt(now()->startOfDay())) {
                $attributes['due_at'] = null;
            }
        }

        $syncToProvided = array_key_exists('sync_to_workspace_ids', $attributes);
        $syncTo = $attributes['sync_to_workspace_ids'] ?? [];
        unset($attributes['sync_to_workspace_ids']);
        $model->update($attributes);
        if ($statusProvided) {
            $this->propagateLinkedStatusUpdate($user, $model->refresh(), 'tasks', $attributes);
        }
        if ($syncToProvided) {
            $this->replaceSyncTo($user, $model->refresh(), 'tasks', $syncTo);
        }

        return $model->refresh();
    }

    public function deleteTask(User $user, Task|int|string $task, array $options = []): void
    {
        $model = $task instanceof Task ? $task : $this->scoped(Task::query(), $user, null, false)->findOrFail($task);
        $this->destroyLinkedItems($user, $model, 'tasks', $options['delete_from_workspace_ids'] ?? null);
    }

    public function createReminder(User $user, array $attributes): Reminder
    {
        $this->assertCanonicalRecurrenceMetadata($attributes);
        $attributes['status'] ??= 'scheduled';
        $this->normalizeDateFields($attributes, ['remind_at']);
        $attributes = $this->withDefaultUncategorizedColor($attributes, true);
        $attributes = $this->withoutNullDefaults($attributes, ['is_critical']);
        if ($this->reminderRecurrenceRequested($attributes) && ! $this->planLimits->canUseRecurringReminders($user)) {
            $this->throwLimit('Recurring reminders are available on Premium, Pro, and Enterprise plans.');
        }

        $syncTo = $attributes['sync_to_workspace_ids'] ?? [];
        $attributes = $this->normalizeReminderNotificationRecipients($user, $attributes, null, $syncTo);
        unset($attributes['sync_to_workspace_ids']);
        $reminder = Reminder::create($this->owned($user, $attributes, Reminder::class));
        $this->syncTo($user, $reminder, $syncTo);

        return $reminder->refresh();
    }

    public function updateReminder(User $user, Reminder|int|string $reminder, array $attributes): Reminder
    {
        $model = $reminder instanceof Reminder ? $reminder : $this->scoped(Reminder::query(), $user, null, false)->findOrFail($reminder);
        $this->assertCanonicalRecurrenceMetadata($attributes);
        $this->normalizeDateFields($attributes, ['remind_at']);
        $attributes = $this->withDefaultUncategorizedColor($attributes);
        $attributes = $this->withoutNullDefaults($attributes, ['is_critical']);
        if ($this->reminderRecurrenceRequested($attributes) && ! $this->planLimits->canUseRecurringReminders($user)) {
            $this->throwLimit('Recurring reminders are available on Premium, Pro, and Enterprise plans.');
        }

        $syncToProvided = array_key_exists('sync_to_workspace_ids', $attributes);
        $syncTo = $attributes['sync_to_workspace_ids'] ?? [];
        $attributes = $this->normalizeReminderNotificationRecipients($user, $attributes, $model, $syncTo);
        unset($attributes['sync_to_workspace_ids']);
        $model->update($attributes);
        if (array_key_exists('status', $attributes)) {
            $this->propagateLinkedStatusUpdate($user, $model->refresh(), 'reminders', $attributes);
        }
        if ($syncToProvided) {
            $this->replaceSyncTo($user, $model->refresh(), 'reminders', $syncTo);
        }

        return $model->refresh();
    }

    public function deleteReminder(User $user, Reminder|int|string $reminder, array $options = []): void
    {
        $model = $reminder instanceof Reminder ? $reminder : $this->scoped(Reminder::query(), $user, null, false)->findOrFail($reminder);
        $this->destroyLinkedItems($user, $model, 'reminders', $options['delete_from_workspace_ids'] ?? null);
    }

    public function createCalendarEvent(User $user, array $attributes): CalendarEvent
    {
        $attributes['status'] ??= 'scheduled';
        $this->assertCanonicalRecurrenceMetadata($attributes, $attributes['recurrence'] ?? null, true);
        $this->rejectCalendarAllDayMetadataFields($attributes);
        $this->normalizeDateFields($attributes, ['starts_at', 'ends_at']);
        $this->storeCanonicalCalendarAllDay($attributes);
        $attributes = $this->withDefaultUncategorizedColor($attributes, true);
        $attributes = $this->withoutNullDefaults($attributes, ['is_critical']);
        if ($this->calendarRecurrenceRequested($attributes['recurrence'] ?? null) && ! $this->planLimits->canUseRecurringCalendar($user)) {
            $this->throwLimit('Recurring calendar events are available on Premium, Pro, and Enterprise plans.');
        }

        $syncTo = $attributes['sync_to_workspace_ids'] ?? [];
        unset($attributes['sync_to_workspace_ids']);
        $event = CalendarEvent::create($this->owned($user, $attributes, CalendarEvent::class));
        $this->syncTo($user, $event, $syncTo);
        $this->refreshRecurringCalendarEvents($user, $event->refresh());

        return $event->refresh();
    }

    public function updateCalendarEvent(User $user, CalendarEvent|int|string $calendarEvent, array $attributes): CalendarEvent
    {
        $model = $calendarEvent instanceof CalendarEvent ? $calendarEvent : $this->scoped(CalendarEvent::query(), $user, null, false)->findOrFail($calendarEvent);
        $effectiveRecurrence = array_key_exists('recurrence', $attributes) ? $attributes['recurrence'] : $model->recurrence;
        $effectiveMetadata = array_key_exists('metadata', $attributes) ? $attributes['metadata'] : $model->metadata;
        $this->assertCanonicalRecurrenceMetadata(['metadata' => $effectiveMetadata], $effectiveRecurrence, true, array_key_exists('metadata', $attributes));
        $this->rejectCalendarAllDayMetadataFields($attributes);
        $this->normalizeDateFields($attributes, ['starts_at', 'ends_at']);
        $this->storeCanonicalCalendarAllDay($attributes, $model);
        $attributes = $this->withDefaultUncategorizedColor($attributes);
        $attributes = $this->withoutNullDefaults($attributes, ['is_critical']);
        if ($this->recurringCalendarEvents->isGeneratedOccurrence($model)) {
            if (isset($attributes['recurrence']) && $attributes['recurrence'] !== 'none') {
                throw ValidationException::withMessages(['recurrence' => 'A generated occurrence cannot define a recurrence.']);
            }
            $attributes['recurrence'] = null;
            $metadata = (array) ($attributes['metadata'] ?? $model->metadata ?? []);
            unset($metadata['recurrence'], $metadata['days'], $metadata['interval'], $metadata['unit']);
            $attributes['metadata'] = $metadata;
        }
        if (array_key_exists('recurrence', $attributes) && $this->calendarRecurrenceRequested($attributes['recurrence']) && ! $this->planLimits->canUseRecurringCalendar($user)) {
            $this->throwLimit('Recurring calendar events are available on Premium, Pro, and Enterprise plans.');
        }

        $syncToProvided = array_key_exists('sync_to_workspace_ids', $attributes);
        $syncTo = $attributes['sync_to_workspace_ids'] ?? [];
        unset($attributes['sync_to_workspace_ids']);
        $model->update($attributes);
        if ($syncToProvided) {
            $this->replaceSyncTo($user, $model->refresh(), 'calendar_events', $syncTo);
        }
        $this->refreshRecurringCalendarEvents($user, $model->refresh());

        return $model->refresh();
    }

    public function deleteCalendarEvent(User $user, CalendarEvent|int|string $calendarEvent, array $options = []): void
    {
        $event = $calendarEvent instanceof CalendarEvent ? $calendarEvent : $this->scoped(CalendarEvent::query(), $user, null, false)->findOrFail($calendarEvent);
        $workspaceIds = $this->workspaceIdsToActOn($event, $options['delete_from_workspace_ids'] ?? null);
        $accessibleWorkspaceIds = $this->accessibleWorkspaceIds($user);
        $this->authorizeWorkspaceIds($user, $workspaceIds, $accessibleWorkspaceIds);

        $eventsByWorkspace = $this->linkedCalendarEventsByWorkspace($event, $accessibleWorkspaceIds);
        $eventsToDelete = $eventsByWorkspace->filter(fn (CalendarEvent $event): bool => in_array((int) $event->workspace_id, $workspaceIds, true))->values();
        if ($eventsToDelete->isEmpty() && in_array((int) $event->workspace_id, $workspaceIds, true)) {
            $eventsToDelete = collect([$event]);
        }

        $recurringDeleteMode = $options['recurring_delete_mode'] ?? 'all';
        $recurringOccurrenceDate = $options['recurring_occurrence_date'] ?? null;
        if ($this->recurringCalendarEvents->isRecurringSeriesEvent($event)) {
            $recurringOccurrenceDate ??= $this->recurringCalendarEvents->occurrenceDate($event);
        }
        if ($recurringOccurrenceDate && $recurringDeleteMode !== 'all' && $this->recurringCalendarEvents->isRecurringSeriesEvent($event)) {
            $eventsToDelete->each(function (CalendarEvent $event) use ($recurringDeleteMode, $recurringOccurrenceDate): void {
                $sourceEvent = $this->recurringCalendarEvents->sourceEventFor($event);
                if ($recurringDeleteMode === 'single') {
                    $this->recurringCalendarEvents->deleteGeneratedOccurrence($sourceEvent, $recurringOccurrenceDate);
                }
                if ($recurringDeleteMode === 'future') {
                    $this->recurringCalendarEvents->deleteGeneratedOccurrencesFrom($sourceEvent, $recurringOccurrenceDate);
                }
                $this->applyRecurringCalendarDelete($sourceEvent, $recurringDeleteMode, $recurringOccurrenceDate);
            });
            return;
        }

        if ($recurringDeleteMode === 'all' && $this->recurringCalendarEvents->isRecurringSeriesEvent($event)) {
            $eventsToDelete = $eventsToDelete
                ->map(fn (CalendarEvent $event): CalendarEvent => $this->recurringCalendarEvents->sourceEventFor($event))
                ->unique(fn (CalendarEvent $event): int => (int) $event->id)
                ->values();
        }

        foreach ($eventsToDelete as $eventToDelete) {
            $this->recurringCalendarEvents->deleteGeneratedOccurrences($eventToDelete);
        }
        $eventIds = $eventsToDelete->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $eventsToDelete->each(fn (CalendarEvent $event): ?bool => $event->delete());
        $this->deleteWorkspaceItemLinksFor('calendar_events', $eventIds);
    }

    public function createEventCategory(User $user, array $attributes): EventCategory
    {
        return EventCategory::create($this->owned($user, $attributes, EventCategory::class))->refresh();
    }

    public function updateEventCategory(User $user, EventCategory|int|string $eventCategory, array $attributes): EventCategory
    {
        $model = $eventCategory instanceof EventCategory ? $eventCategory : $this->scoped(EventCategory::query(), $user, null, false)->findOrFail($eventCategory);
        $model->update($attributes);
        return $model->refresh();
    }

    public function deleteEventCategory(User $user, EventCategory|int|string $eventCategory, array $options = []): void
    {
        $model = $eventCategory instanceof EventCategory ? $eventCategory : $this->scoped(EventCategory::query(), $user, null, false)->findOrFail($eventCategory);
        $workspaceIds = $this->workspaceIdsToActOn($model, $options['delete_from_workspace_ids'] ?? null);
        $accessibleWorkspaceIds = $this->accessibleWorkspaceIds($user);
        $this->authorizeWorkspaceIds($user, $workspaceIds, $accessibleWorkspaceIds);

        CalendarEvent::whereIn('workspace_id', $workspaceIds)
            ->where('user_id', $user->id)
            ->where('category', $model->name)
            ->update(['category' => null, 'color' => self::DEFAULT_CATEGORY_COLOR]);
        Task::whereIn('workspace_id', $workspaceIds)
            ->where('user_id', $user->id)
            ->where('category', $model->name)
            ->update(['category' => null, 'color' => self::DEFAULT_CATEGORY_COLOR]);
        Reminder::whereIn('workspace_id', $workspaceIds)
            ->where('user_id', $user->id)
            ->where('category', $model->name)
            ->update(['category' => null, 'color' => self::DEFAULT_CATEGORY_COLOR]);

        EventCategory::query()
            ->where('user_id', $user->id)
            ->where('name', $model->name)
            ->whereIn('workspace_id', $workspaceIds)
            ->delete();
    }

    public function createNote(User $user, array $attributes): Note
    {
        if (! $this->planLimits->canUseNotes($user)) {
            $this->throwLimit('Notes are available on this plan after upgrading.');
        }
        $workspace = $this->workspace($user, $attributes['workspace_id'] ?? null);
        $this->validateNoteFolderForWorkspace($user, (int) $workspace->id, $attributes);
        if ($response = $this->planLimits->enforceNoteCreationLimit($user, $this->additionalNotesForSync($workspace->id, $attributes['sync_to_workspace_ids'] ?? []))) {
            throw new HttpResponseException($response);
        }
        $attributes = $this->normalizedNoteAttributes($attributes);
        $attributes = $this->withoutNullDefaults($attributes, ['is_pinned']);
        $syncTo = $attributes['sync_to_workspace_ids'] ?? [];
        unset($attributes['sync_to_workspace_ids']);
        $note = Note::create($this->owned($user, $attributes, Note::class));
        $this->syncTo($user, $note, $syncTo);

        return $note->refresh()->load('folder');
    }

    public function updateNote(User $user, Note|int|string $note, array $attributes): Note
    {
        if (! $this->planLimits->canUseNotes($user)) {
            $this->throwLimit('Notes are available on this plan after upgrading.');
        }
        $model = $note instanceof Note ? $note : $this->scoped(Note::query(), $user, null, false)->findOrFail($note);
        $this->validateNoteFolderForWorkspace($user, (int) $model->workspace_id, $attributes);
        $syncToProvided = array_key_exists('sync_to_workspace_ids', $attributes);
        $syncTo = $attributes['sync_to_workspace_ids'] ?? [];
        unset($attributes['sync_to_workspace_ids']);
        $model->update($this->withoutNullDefaults($this->normalizedNoteAttributes($attributes, $model), ['is_pinned']));
        if ($syncToProvided) {
            $this->replaceSyncTo($user, $model->refresh(), 'notes', $syncTo);
        }

        return $model->refresh()->load('folder');
    }

    public function deleteNote(User $user, Note|int|string $note, array $options = []): void
    {
        if (! $this->planLimits->canUseNotes($user)) {
            $this->throwLimit('Notes are available on this plan after upgrading.');
        }
        $model = $note instanceof Note ? $note : $this->scoped(Note::query(), $user, null, false)->findOrFail($note);
        $this->destroyLinkedItems($user, $model, 'notes', $options['delete_from_workspace_ids'] ?? null);
    }

    public function scoped($query, User $user, mixed $workspaceId = null, bool $useWorkspace = true)
    {
        if ($useWorkspace || $workspaceId !== null) {
            $workspace = $this->workspace($user, $workspaceId);
            return $query->where('workspace_id', $workspace->id);
        }

        return $query->whereIn('workspace_id', $this->accessibleWorkspaceIds($user));
    }

    public function accessibleWorkspaceIds(User $user): array
    {
        return $this->workspaces->accessibleWorkspaces($user)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function workspace(User $user, mixed $workspaceId = null): Workspace
    {
        return $this->workspaces->resolveWorkspace($user, $workspaceId);
    }

    private function owned(User $user, array $attributes, string $modelClass): array
    {
        $workspace = $this->workspace($user, $attributes['workspace_id'] ?? null);
        unset($attributes['workspace_id']);
        $owned = ['user_id' => $user->id, 'workspace_id' => $workspace->id] + $attributes;
        if (Schema::hasColumn((new $modelClass())->getTable(), 'created_by_user_id')) {
            $owned['created_by_user_id'] = $user->id;
        }
        return $owned;
    }

    private function throwLimit(string $message): never
    {
        throw new HttpResponseException($this->planLimits->limitResponse($message));
    }

    private function withDefaultUncategorizedColor(array $attributes, bool $missingCategoryIsUncategorized = false): array
    {
        $hasCategory = array_key_exists('category', $attributes);
        if (($missingCategoryIsUncategorized && ! $hasCategory) || ($hasCategory && blank($attributes['category']))) {
            $attributes['category'] = null;
            $attributes['color'] = self::DEFAULT_CATEGORY_COLOR;
        }
        return $attributes;
    }

    private function withoutNullDefaults(array $attributes, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $attributes) && $attributes[$field] === null) {
                unset($attributes[$field]);
            }
        }
        return $attributes;
    }

    private function validateNoteFolderForWorkspace(User $user, int $workspaceId, array $attributes): void
    {
        if (! array_key_exists('note_folder_id', $attributes) || $attributes['note_folder_id'] === null || $attributes['note_folder_id'] === '') {
            return;
        }

        $folderId = (int) $attributes['note_folder_id'];
        $valid = $folderId > 0 && NoteFolder::query()
            ->where('id', $folderId)
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspaceId)
            ->exists();

        if (! $valid) {
            throw ValidationException::withMessages([
                'note_folder_id' => 'The selected note folder is invalid for this workspace.',
            ]);
        }
    }

    private function syncTo(User $user, Model $model, array $workspaceIds): void
    {
        if ($workspaceIds === []) return;
        foreach ($workspaceIds as $workspaceId) {
            $this->workspaces->authorizeMember($user, Workspace::findOrFail($workspaceId));
        }
        $this->sync->syncToWorkspaceIds($model, $workspaceIds, $user);
    }

    private function replaceSyncTo(User $user, Model $model, string $type, array $workspaceIds): void
    {
        $workspaceIds = array_values(array_unique(array_map('intval', $workspaceIds)));
        foreach ($workspaceIds as $workspaceId) {
            if ($workspaceId > 0) {
                $this->workspaces->authorizeMember($user, Workspace::findOrFail($workspaceId));
            }
        }

        $accessibleWorkspaceIds = $this->accessibleWorkspaceIds($user);
        $desiredWorkspaceIds = collect([(int) $model->workspace_id])
            ->merge($workspaceIds)->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)->unique()->values()->all();

        $linkedItems = $model instanceof CalendarEvent
            ? $this->linkedCalendarEventsByWorkspace($model, $accessibleWorkspaceIds)
            : $this->linkedItemsByWorkspace($model, $type, $accessibleWorkspaceIds);

        $itemsToRemove = $linkedItems->reject(fn (Model $item): bool => in_array((int) $item->workspace_id, $desiredWorkspaceIds, true))->values();
        if ($itemsToRemove->isNotEmpty()) {
            if ($model instanceof CalendarEvent) {
                $itemsToRemove->each(fn (CalendarEvent $event): int => $this->recurringCalendarEvents->deleteGeneratedOccurrences($event));
            }
            $idsToRemove = $itemsToRemove->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $itemsToRemove->each(fn (Model $item): ?bool => $item->delete());
            $this->deleteWorkspaceItemLinksFor($type, $idsToRemove);
        }

        if ($model instanceof Note) {
            $remainingWorkspaceIds = $linkedItems
                ->reject(fn (Model $item): bool => $itemsToRemove->contains(fn (Model $removed): bool => (int) $removed->id === (int) $item->id))
                ->keys()->map(fn ($id): int => (int) $id)->all();
            $notesToCreate = collect($workspaceIds)
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0 && ! in_array($id, $remainingWorkspaceIds, true))
                ->unique()->count();
            if ($response = $this->planLimits->enforceNoteCreationLimit($user, $notesToCreate)) {
                throw new HttpResponseException($response);
            }
        }

        $this->syncTo($user, $model, $workspaceIds);
    }

    private function refreshRecurringCalendarEvents(User $user, CalendarEvent $event): void
    {
        $accessibleWorkspaceIds = $this->accessibleWorkspaceIds($user);
        $this->linkedCalendarEventsByWorkspace($event, $accessibleWorkspaceIds)
            ->values()->each(fn (CalendarEvent $calendarEvent): int => $this->recurringCalendarEvents->refreshMaterializedOccurrences($calendarEvent));
    }

    private function destroyLinkedItems(User $user, Model $model, string $type, ?array $workspaceIds = null): void
    {
        $workspaceIds = $this->workspaceIdsToActOn($model, $workspaceIds);
        $accessibleWorkspaceIds = $this->accessibleWorkspaceIds($user);
        $this->authorizeWorkspaceIds($user, $workspaceIds, $accessibleWorkspaceIds);

        $itemsToDelete = $this->linkedItemsByWorkspace($model, $type, $accessibleWorkspaceIds)->only($workspaceIds)->values();
        if ($itemsToDelete->isEmpty() && in_array((int) $model->workspace_id, $workspaceIds, true)) {
            $itemsToDelete = collect([$model]);
        }
        $ids = $itemsToDelete->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($ids !== []) {
            $itemsToDelete->each(fn (Model $item): ?bool => $item->delete());
            $this->deleteWorkspaceItemLinksFor($type, $ids);
        }
    }

    private function workspaceIdsToActOn(Model $model, ?array $workspaceIds): array
    {
        $workspaceIds = array_values(array_unique(array_map('intval', $workspaceIds ?? [$model->workspace_id])));
        return $workspaceIds === [] ? [(int) $model->workspace_id] : $workspaceIds;
    }

    private function authorizeWorkspaceIds(User $user, array $workspaceIds, array $accessibleWorkspaceIds): void
    {
        foreach ($workspaceIds as $workspaceId) {
            if (! in_array($workspaceId, $accessibleWorkspaceIds, true)) {
                $this->workspaces->authorizeMember($user, Workspace::findOrFail($workspaceId));
            }
        }
    }

    private function propagateLinkedStatusUpdate(User $user, Model $model, string $type, array $validated): void
    {
        $updates = collect(['status', 'completed_at', 'due_at', 'metadata'])
            ->filter(fn (string $key): bool => array_key_exists($key, $validated))
            ->mapWithKeys(fn (string $key): array => [$key => $validated[$key]])->all();
        if ($updates === []) return;
        $this->sync->propagateStatusUpdate($model, $updates, $this->accessibleWorkspaceIds($user));
    }

    private function linkedItemsByWorkspace(Model $model, string $type, array $accessibleWorkspaceIds)
    {
        $relatedIds = collect([(int) $model->id]);
        $links = $this->itemLinksFor($model, $type);
        $sourcePairs = collect();
        foreach ($links as $link) {
            $relatedIds->push((int) $link->source_id, (int) $link->target_id);
            $sourcePairs->push([(int) $link->source_workspace_id, (int) $link->source_id]);
        }
        $sourcePairs = $sourcePairs->unique(fn (array $pair): string => $pair[0].':'.$pair[1])->values();
        if ($sourcePairs->isNotEmpty()) {
            WorkspaceItemLink::query()
                ->where('source_type', $type)->where('target_type', $type)->where('link_type', 'copy')
                ->where(function ($query) use ($sourcePairs): void {
                    foreach ($sourcePairs as [$workspaceId, $sourceId]) {
                        $query->orWhere(fn ($query) => $query->where('source_workspace_id', $workspaceId)->where('source_id', $sourceId));
                    }
                })->get()->each(fn (WorkspaceItemLink $link) => $relatedIds->push((int) $link->source_id, (int) $link->target_id));
        }
        return $model::query()->whereIn('id', $relatedIds->unique()->values()->all())->whereIn('workspace_id', $accessibleWorkspaceIds)->get()->keyBy(fn (Model $item): int => (int) $item->workspace_id);
    }

    private function itemLinksFor(Model $model, string $type)
    {
        return WorkspaceItemLink::query()
            ->where('source_type', $type)->where('target_type', $type)->where('link_type', 'copy')
            ->where(function ($query) use ($model): void {
                $query->where(fn ($query) => $query->where('source_workspace_id', $model->workspace_id)->where('source_id', $model->id))
                    ->orWhere(fn ($query) => $query->where('target_workspace_id', $model->workspace_id)->where('target_id', $model->id));
            })->get();
    }

    private function linkedCalendarEventsByWorkspace(CalendarEvent $event, array $accessibleWorkspaceIds)
    {
        if ($this->recurringCalendarEvents->isGeneratedOccurrence($event)) {
            $occurrenceDate = $this->recurringCalendarEvents->occurrenceDate($event);
            if ($occurrenceDate) {
                return $this->linkedCalendarEventsByWorkspace($this->recurringCalendarEvents->sourceEventFor($event), $accessibleWorkspaceIds)
                    ->map(fn (CalendarEvent $sourceEvent): CalendarEvent => $this->recurringCalendarEvents->generatedOccurrenceFor($sourceEvent, $occurrenceDate) ?? $sourceEvent)
                    ->keyBy(fn (CalendarEvent $event): int => (int) $event->workspace_id);
            }
        }

        $relatedIds = collect([(int) $event->id]);
        $links = $this->calendarEventLinksFor($event);
        $sourcePairs = collect();
        foreach ($links as $link) {
            $relatedIds->push((int) $link->source_id, (int) $link->target_id);
            $sourcePairs->push([(int) $link->source_workspace_id, (int) $link->source_id]);
        }
        $sourcePairs = $sourcePairs->unique(fn (array $pair): string => $pair[0].':'.$pair[1])->values();
        if ($sourcePairs->isNotEmpty()) {
            WorkspaceItemLink::query()
                ->where('source_type', 'calendar_events')->where('target_type', 'calendar_events')->where('link_type', 'copy')
                ->where(function ($query) use ($sourcePairs): void {
                    foreach ($sourcePairs as [$workspaceId, $sourceId]) {
                        $query->orWhere(fn ($query) => $query->where('source_workspace_id', $workspaceId)->where('source_id', $sourceId));
                    }
                })->get()->each(fn (WorkspaceItemLink $link) => $relatedIds->push((int) $link->source_id, (int) $link->target_id));
        }
        return CalendarEvent::query()->whereIn('id', $relatedIds->unique()->values()->all())->whereIn('workspace_id', $accessibleWorkspaceIds)->get()->keyBy(fn (CalendarEvent $event): int => (int) $event->workspace_id);
    }

    private function calendarEventLinksFor(CalendarEvent $event)
    {
        return WorkspaceItemLink::query()
            ->where('source_type', 'calendar_events')->where('target_type', 'calendar_events')->where('link_type', 'copy')
            ->where(function ($query) use ($event): void {
                $query->where(fn ($query) => $query->where('source_workspace_id', $event->workspace_id)->where('source_id', $event->id))
                    ->orWhere(fn ($query) => $query->where('target_workspace_id', $event->workspace_id)->where('target_id', $event->id));
            })->get();
    }

    private function deleteWorkspaceItemLinksFor(string $type, array $ids): void
    {
        if ($ids === []) return;
        WorkspaceItemLink::query()->where(function ($query) use ($type, $ids): void {
            $query->where(fn ($query) => $query->where('source_type', $type)->whereIn('source_id', $ids))
                ->orWhere(fn ($query) => $query->where('target_type', $type)->whereIn('target_id', $ids));
        })->delete();
    }

    private function normalizedNoteAttributes(array $attributes, ?Note $existing = null): array
    {
        $hasMarkdown = array_key_exists('body_markdown', $attributes);
        $markdown = $hasMarkdown
            ? (string) ($attributes['body_markdown'] ?? '')
            : (string) ($existing?->body_markdown ?? '');
        $plainText = (string) ($existing?->plain_text ?? '');
        if ($hasMarkdown) {
            $attributes['body_markdown'] = $markdown;
            $attributes['plain_text'] = $plainText = $this->markdownToPlainText($markdown);
        }
        if (! array_key_exists('title', $attributes) || blank($attributes['title'])) {
            $source = trim((string) ($attributes['plain_text'] ?? $plainText));
            $firstLine = trim((string) strtok($source, "\n"));
            $attributes['title'] = $firstLine !== '' ? str($firstLine)->limit(80, '')->toString() : ($existing?->title ?? 'New Note');
        }
        return $attributes;
    }

    private function markdownToPlainText(string $markdown): string
    {
        if (trim($markdown) === '') return '';

        $converter = new GithubFlavoredMarkdownConverter([
            'allow_unsafe_links' => false,
            'html_input' => 'strip',
        ]);
        $html = (string) $converter->convert($markdown);
        $html = preg_replace_callback(
            '/<img\b[^>]*\balt=(?:"([^"]*)"|\'([^\']*)\')[^>]*>/iu',
            fn (array $matches): string => html_entity_decode((string) ($matches[1] ?? $matches[2] ?? ''), ENT_QUOTES | ENT_HTML5),
            $html,
        ) ?: $html;
        $html = preg_replace('/<\/(?:td|th)>/iu', "\t", $html) ?: $html;
        $html = preg_replace('/<(?:br|hr)\b[^>]*>|<\/(?:p|div|h[1-6]|li|blockquote|pre|tr)>/iu', "\n", $html) ?: $html;
        $plainText = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $plainText = preg_replace("/[ \t]+\n/", "\n", $plainText) ?: $plainText;
        $plainText = preg_replace("/\n{3,}/", "\n\n", $plainText) ?: $plainText;

        return trim($plainText);
    }

    private function additionalNotesForSync(int $workspaceId, array $syncToWorkspaceIds): int
    {
        return 1 + collect($syncToWorkspaceIds)->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0 && $id !== $workspaceId)->unique()->count();
    }

    private function normalizeReminderNotificationRecipients(User $user, array $attributes, ?Reminder $reminder = null, array $syncToWorkspaceIds = []): array
    {
        $metadata = $attributes['metadata'] ?? null;
        if (! is_array($metadata)) return $attributes;
        $hasWorkspaceRecipients = array_key_exists('notification_recipients_by_workspace', $metadata) || array_key_exists('notificationRecipientsByWorkspace', $metadata);
        $hasFlatRecipients = array_key_exists('notification_recipient_user_ids', $metadata) || array_key_exists('notificationRecipientUserIds', $metadata);
        if (! $hasWorkspaceRecipients && ! $hasFlatRecipients) return $attributes;

        $primaryWorkspace = $reminder?->workspace_id ? Workspace::findOrFail((int) $reminder->workspace_id) : $this->workspace($user, $attributes['workspace_id'] ?? null);
        $allowedWorkspaceIds = collect([(int) $primaryWorkspace->id])->merge($syncToWorkspaceIds)->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->unique()->values()->all();
        $rawByWorkspace = $metadata['notification_recipients_by_workspace'] ?? $metadata['notificationRecipientsByWorkspace'] ?? null;
        $recipientsByWorkspace = [];
        if (is_array($rawByWorkspace)) {
            foreach ($rawByWorkspace as $workspaceId => $recipientIds) {
                $workspaceId = (int) $workspaceId;
                if ($workspaceId > 0) $recipientsByWorkspace[$workspaceId] = $this->normalizeReminderRecipientIds($recipientIds);
            }
        } elseif ($hasFlatRecipients) {
            $recipientsByWorkspace[(int) $primaryWorkspace->id] = $this->normalizeReminderRecipientIds($metadata['notification_recipient_user_ids'] ?? $metadata['notificationRecipientUserIds'] ?? []);
        }

        if (array_diff(array_keys($recipientsByWorkspace), $allowedWorkspaceIds) !== []) {
            throw ValidationException::withMessages(['metadata.notification_recipients_by_workspace' => 'Reminder notification recipients must belong to the reminder workspace or a synced workspace.']);
        }
        $memberships = WorkspaceMembership::query()
            ->whereIn('workspace_id', array_keys($recipientsByWorkspace) ?: $allowedWorkspaceIds)
            ->where('status', 'active')->whereNotNull('user_id')->get(['workspace_id', 'user_id'])
            ->groupBy(fn (WorkspaceMembership $membership): int => (int) $membership->workspace_id)
            ->map(fn ($items) => $items->pluck('user_id')->map(fn ($id): int => (int) $id)->all());
        foreach ($recipientsByWorkspace as $workspaceId => $recipientIds) {
            $allowedUserIds = $memberships->get($workspaceId, []);
            if (array_diff($recipientIds, $allowedUserIds) !== []) {
                throw ValidationException::withMessages(['metadata.notification_recipients_by_workspace' => 'Reminder notification recipients must be active members of the selected workspace.']);
            }
        }
        $recipientsByWorkspace = collect($recipientsByWorkspace)->map(fn (array $ids): array => array_values(array_unique($ids)))->all();
        $metadata['notification_recipients_by_workspace'] = $recipientsByWorkspace;
        $metadata['notification_recipient_user_ids'] = collect($recipientsByWorkspace)->flatten()->unique()->values()->all();
        unset($metadata['notificationRecipientsByWorkspace'], $metadata['notificationRecipientUserIds']);
        $attributes['metadata'] = $this->resetReminderNotificationDeliveryMetadata($metadata);
        return $attributes;
    }

    private function normalizeReminderRecipientIds(mixed $recipientIds): array
    {
        return collect(is_array($recipientIds) ? $recipientIds : [])->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->unique()->values()->all();
    }

    private function resetReminderNotificationDeliveryMetadata(array $metadata): array
    {
        unset(
            $metadata['email_notification_sent_at'],
            $metadata['email_notification_failed_at'],
            $metadata['email_notification_resolved_at'],
            $metadata['push_notification_sent_at'],
            $metadata['push_notification_resolved_at'],
        );
        $delivery = is_array($metadata['notification_delivery'] ?? null) ? $metadata['notification_delivery'] : [];
        $delivery['email_sent_at_by_user'] = [];
        $delivery['email_failed_at_by_user'] = [];
        $delivery['email_retry_after_by_user'] = [];
        $delivery['email_terminal_at_by_user'] = [];
        $delivery['email_terminal_reason_by_user'] = [];
        $delivery['push_sent_at_by_user'] = [];
        $metadata['notification_delivery'] = $delivery;
        return $metadata;
    }

    private function taskRecurrenceRequested(array $attributes): bool
    {
        $recurrence = $this->taskRecurrenceValue((array) ($attributes['metadata'] ?? []));
        return $recurrence !== null && $recurrence !== 'none';
    }

    private function reminderRecurrenceRequested(array $attributes): bool
    {
        $recurrence = $this->taskRecurrenceValue((array) ($attributes['metadata'] ?? []));
        return $recurrence !== null && $recurrence !== 'none';
    }

    private function calendarRecurrenceRequested(mixed $recurrence): bool
    {
        return is_string($recurrence) && in_array($recurrence, array_diff(self::RECURRENCES, ['none']), true);
    }

    private function advanceRecurringTaskCompletion(Task $task, array &$attributes): bool
    {
        $metadata = $this->taskRecurrenceMetadata($task, $attributes);
        $recurrence = $this->taskRecurrenceValue($metadata);
        if ($recurrence === null || $recurrence === 'none') return false;
        $nextDueAt = $this->nextRecurringTaskDueAt($task, $metadata, $recurrence);
        if ($nextDueAt === null) return false;

        $completedAt = Carbon::parse($attributes['completed_at'] ?? now())->utc();
        $metadata['recurrence'] = $recurrence;
        $metadata['last_completed_at'] = $completedAt->toIso8601String();
        if ($task->due_at !== null) {
            $metadata['last_completed_due_at'] = $task->due_at->copy()->utc()->toIso8601String();
        }
        $metadata['completion_count'] = max(0, (int) ($metadata['completion_count'] ?? 0)) + 1;
        $attributes['status'] = 'open';
        $attributes['completed_at'] = null;
        $attributes['due_at'] = $nextDueAt->utc();
        $attributes['metadata'] = $metadata;
        return true;
    }

    private function taskRecurrenceMetadata(Task $task, array $attributes): array
    {
        $metadata = is_array($task->metadata) ? $task->metadata : [];
        if (array_key_exists('metadata', $attributes)) $metadata = is_array($attributes['metadata']) ? $attributes['metadata'] : [];
        return $metadata;
    }

    private function taskRecurrenceValue(array $metadata): ?string
    {
        $recurrence = $metadata['recurrence'] ?? null;
        return is_string($recurrence) && in_array($recurrence, self::RECURRENCES, true) ? $recurrence : null;
    }

    private function nextRecurringTaskDueAt(Task $task, array $metadata, string $recurrence): ?Carbon
    {
        $cursor = ($task->due_at ?: now())->copy()->utc();
        $now = now()->utc();
        for ($guard = 0; $guard < 500; $guard++) {
            $cursor = $this->advanceTaskRecurrenceDate($cursor, $metadata, $recurrence);
            if ($cursor === null) return null;
            if ($cursor->gt($now)) return $cursor;
        }
        return null;
    }

    private function advanceTaskRecurrenceDate(Carbon $from, array $metadata, string $recurrence): ?Carbon
    {
        return match ($recurrence) {
            'daily' => $from->copy()->addDay(),
            'weekly' => $from->copy()->addWeek(),
            'monthly' => $from->copy()->addMonthNoOverflow(),
            'yearly' => $from->copy()->addYearNoOverflow(),
            'specific_days' => $this->nextSpecificTaskDay($from, $metadata),
            'interval' => $this->addTaskRecurrenceInterval($from, $metadata),
            default => null,
        };
    }

    private function nextSpecificTaskDay(Carbon $from, array $metadata): ?Carbon
    {
        $days = collect($metadata['days'] ?? [])->filter(fn ($day): bool => is_string($day) && in_array($day, self::RECURRENCE_DAYS, true))->unique()->values();
        if ($days->isEmpty()) return null;
        $cursor = $from->copy()->addDay();
        for ($guard = 0; $guard < 14; $guard++) {
            $day = strtolower($cursor->format('D'));
            if ($days->contains($day === 'thu' ? 'thu' : $day)) return $cursor;
            $cursor->addDay();
        }
        return null;
    }

    private function addTaskRecurrenceInterval(Carbon $from, array $metadata): ?Carbon
    {
        $interval = $metadata['interval'] ?? null;
        $unit = $metadata['unit'] ?? null;
        if (! is_int($interval) || $interval < 1 || ! in_array($unit, self::RECURRENCE_UNITS, true)) return null;
        return match ($unit) {
            'days' => $from->copy()->addDays($interval),
            'weeks' => $from->copy()->addWeeks($interval),
            'months' => $from->copy()->addMonthsNoOverflow($interval),
            'years' => $from->copy()->addYearsNoOverflow($interval),
        };
    }

    private function recurrenceMetadataRules(bool $calendar = false): array
    {
        return [
            'metadata.recurrence' => $calendar ? ['prohibited'] : ['sometimes', 'required', 'string', Rule::in(self::RECURRENCES)],
            'metadata.days' => ['sometimes', 'required', 'array', 'min:1'],
            'metadata.days.*' => ['required', 'string', Rule::in(self::RECURRENCE_DAYS), 'distinct:strict'],
            'metadata.interval' => ['sometimes', 'required', 'integer', 'min:1'],
            'metadata.unit' => ['sometimes', 'required', 'string', Rule::in(self::RECURRENCE_UNITS)],
        ];
    }

    private function assertCanonicalRecurrenceMetadata(array $attributes, ?string $calendarRecurrence = null, bool $calendar = false, bool $rejectLegacyKeys = true): void
    {
        $metadata = $attributes['metadata'] ?? null;
        if (! is_array($metadata)) return;
        if ($rejectLegacyKeys) validator(['metadata' => $metadata], $this->recurrenceMetadataRules($calendar))->validate();
        $errors = [];
        if ($rejectLegacyKeys) {
            foreach (self::LEGACY_RECURRENCE_METADATA_KEYS as $key) {
                if (array_key_exists($key, $metadata)) $errors["metadata.{$key}"] = 'Use the canonical recurrence metadata fields recurrence, days, interval, and unit.';
            }
        }
        $recurrence = $calendar ? $calendarRecurrence : ($metadata['recurrence'] ?? null);
        $hasDays = array_key_exists('days', $metadata);
        $hasInterval = array_key_exists('interval', $metadata);
        $hasUnit = array_key_exists('unit', $metadata);
        if ($recurrence === 'specific_days') {
            if (! $hasDays) $errors['metadata.days'] = 'Specific-day recurrence requires canonical days.';
            if ($hasInterval || $hasUnit) $errors['metadata.interval'] = 'Specific-day recurrence accepts only days.';
        } elseif ($recurrence === 'interval') {
            if (! $hasInterval) $errors['metadata.interval'] = 'Interval recurrence requires a positive interval.';
            if (! $hasUnit) $errors['metadata.unit'] = 'Interval recurrence requires days, weeks, months, or years.';
            if ($hasDays) $errors['metadata.days'] = 'Interval recurrence does not accept days.';
        } elseif ($hasDays || $hasInterval || $hasUnit) {
            $errors['metadata'] = 'Recurrence detail fields require specific_days or interval recurrence.';
        }
        if ($errors !== []) throw ValidationException::withMessages($errors);
    }

    private function normalizeDateFields(array &$attributes, array $fields): void
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $attributes) || $attributes[$field] === null || $attributes[$field] === '') continue;
            $attributes[$field] = Carbon::parse((string) $attributes[$field])->utc();
        }
    }

    private function storeCanonicalCalendarAllDay(array &$attributes, ?CalendarEvent $event = null): void
    {
        if (! array_key_exists('all_day', $attributes)) return;
        $allDay = $attributes['all_day'];
        unset($attributes['all_day']);
        $metadata = array_key_exists('metadata', $attributes) ? ($attributes['metadata'] ?? []) : ($event?->metadata ?? []);
        if (! is_array($metadata)) $metadata = [];
        unset($metadata['allDay']);
        $attributes['metadata'] = array_merge($metadata, ['all_day' => $allDay]);
    }

    private function rejectCalendarAllDayMetadataFields(array $attributes): void
    {
        $metadata = $attributes['metadata'] ?? null;
        if (! is_array($metadata)) return;
        $errors = [];
        foreach (['all_day', 'allDay'] as $field) {
            if (array_key_exists($field, $metadata)) $errors["metadata.{$field}"] = ['All-day state must use the top-level all_day boolean.'];
        }
        if ($errors !== []) throw ValidationException::withMessages($errors);
    }

    private function applyRecurringCalendarDelete(CalendarEvent $event, string $mode, string $occurrenceDate): CalendarEvent
    {
        $metadata = $event->metadata ?? [];
        if ($mode === 'single') {
            $metadata['recurring_exception_dates'] = collect($metadata['recurring_exception_dates'] ?? [])->map(fn ($value): string => trim((string) $value))->filter()->push($occurrenceDate)->unique()->sort()->values()->all();
        }
        if ($mode === 'future') $metadata['recurrence_until'] = $occurrenceDate;
        $sourceDate = $event->starts_at ? $event->starts_at->copy()->utc()->toDateString() : null;
        if ($sourceDate && $occurrenceDate <= $sourceDate) $metadata['recurrence_source_hidden'] = true;
        $event->forceFill(['metadata' => $metadata])->save();
        return $event->refresh();
    }
}
