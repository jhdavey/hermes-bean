<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\EventCategory;
use App\Models\Note;
use App\Models\NoteFolder;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceItemLink;
use App\Models\WorkspaceMembership;
use App\Services\Domain\DomainResourceCatalog;
use App\Services\Domain\DomainResourceService;
use App\Services\GoogleCalendarSyncService;
use App\Services\OutlookCalendarSyncService;
use App\Services\PlanHistoryService;
use App\Services\PlanLimitService;
use App\Services\RecurringCalendarEventService;
use App\Services\WorkspaceItemSyncService;
use App\Services\WorkspaceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DomainResourceController extends Controller
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
        private readonly GoogleCalendarSyncService $googleCalendar,
        private readonly OutlookCalendarSyncService $outlookCalendar,
        private readonly RecurringCalendarEventService $recurringCalendarEvents,
        private readonly PlanLimitService $planLimits,
        private readonly PlanHistoryService $history,
        private readonly DomainResourceService $domainResources,
        private readonly DomainResourceCatalog $resourceCatalog,
    ) {}

    public function listNoteFolders(Request $request): JsonResponse
    {
        if ($response = $this->enforceNotesAccess($request)) {
            return $response;
        }

        return $this->listed(
            $this->scoped(NoteFolder::query(), $request)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->orderBy('id')
                ->get()
        );
    }

    public function storeNoteFolder(Request $request): JsonResponse
    {
        if ($response = $this->enforceNotesAccess($request)) {
            return $response;
        }

        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
        ]);

        $folder = $this->domainResources->createNoteFolder($request->user(), $attributes);
        return $folder->wasRecentlyCreated ? $this->created($folder) : response()->json(['data' => $folder]);
    }


    public function updateNoteFolder(Request $request, string $noteFolder): JsonResponse
    {
        if ($response = $this->enforceNotesAccess($request)) {
            return $response;
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);

        return response()->json(['data' => $this->domainResources->updateNoteFolder($request->user(), $noteFolder, $validated)]);
    }


    public function destroyNoteFolder(Request $request, string $noteFolder): JsonResponse
    {
        if ($response = $this->enforceNotesAccess($request)) {
            return $response;
        }

        $this->domainResources->deleteNoteFolder($request->user(), $noteFolder);
        return response()->json(status: 204);
    }


    public function listNotes(Request $request): JsonResponse
    {
        if ($response = $this->enforceNotesAccess($request)) {
            return $response;
        }

        $query = $this->scoped(Note::query()->with('folder'), $request);
        if ($request->filled('folder_id')) {
            $query->where('note_folder_id', $request->integer('folder_id'));
        }
        if ($request->boolean('pinned')) {
            $query->where('is_pinned', true);
        }
        if ($request->filled('query')) {
            $this->scopeNoteSearch($query, (string) $request->input('query'));
        }

        $notes = $query
            ->orderByDesc('is_pinned')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(min(max((int) $request->input('limit', 200), 1), 500))
            ->get();

        $accessibleWorkspaceIds = $this->accessibleWorkspaceIds($request);
        $notes->each(function (Note $note) use ($accessibleWorkspaceIds): void {
            $note->setAttribute('linked_workspace_ids', $this->linkedItemWorkspaceIds($note, 'notes', $accessibleWorkspaceIds));
        });

        return $this->listed($notes);
    }

    public function storeNote(Request $request): JsonResponse
    {
        if ($response = $this->enforceNotesAccess($request)) {
            return $response;
        }

        $workspace = $this->workspace($request);
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'body_html' => ['nullable', 'string'],
            'plain_text' => ['nullable', 'string'],
            'body_delta' => ['nullable', 'array'],
            'note_folder_id' => ['nullable', Rule::exists('note_folders', 'id')->where('workspace_id', $workspace->id)],
            'is_pinned' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
            'sync_to_workspace_ids' => ['nullable', 'array'],
            'sync_to_workspace_ids.*' => ['integer', 'exists:workspaces,id'],
        ]);

        return $this->created($this->domainResources->createNote($request->user(), $validated));
    }


    public function updateNote(Request $request, string $note): JsonResponse
    {
        if ($response = $this->enforceNotesAccess($request)) {
            return $response;
        }

        $model = $this->scoped(Note::query(), $request, false)->findOrFail($note);
        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body_html' => ['sometimes', 'nullable', 'string'],
            'plain_text' => ['sometimes', 'nullable', 'string'],
            'body_delta' => ['sometimes', 'nullable', 'array'],
            'note_folder_id' => ['sometimes', 'nullable', Rule::exists('note_folders', 'id')->where('workspace_id', $model->workspace_id)],
            'is_pinned' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'sync_to_workspace_ids' => ['nullable', 'array'],
            'sync_to_workspace_ids.*' => ['integer', 'exists:workspaces,id'],
        ]);

        return response()->json(['data' => $this->domainResources->updateNote($request->user(), $model, $validated)]);
    }


    public function destroyNote(Request $request, string $note): JsonResponse
    {
        if ($response = $this->enforceNotesAccess($request)) {
            return $response;
        }

        $validated = $request->validate([
            'delete_from_workspace_ids' => ['nullable', 'array'],
            'delete_from_workspace_ids.*' => ['integer', 'exists:workspaces,id'],
        ]);
        $this->domainResources->deleteNote($request->user(), $note, $validated);

        return response()->json(status: 204);
    }


    public function listTasks(Request $request): JsonResponse
    {
        $tasks = $this->scoped(Task::query(), $request)
            ->visibleInActiveViews()
            ->orderBy('due_at')
            ->orderBy('id')
            ->get();

        $accessibleWorkspaceIds = $this->accessibleWorkspaceIds($request);
        $tasks->each(function (Task $task) use ($accessibleWorkspaceIds): void {
            $task->setAttribute('linked_workspace_ids', $this->linkedItemWorkspaceIds($task, 'tasks', $accessibleWorkspaceIds));
        });

        return $this->listed($tasks);
    }

    public function listPastTasks(Request $request): JsonResponse
    {
        $historyCutoff = $this->planLimits->historyCutoffFor($request->user());

        return $this->listed(
            $this->scoped(Task::query(), $request)
                ->whereNotNull('completed_at')
                ->when($historyCutoff !== null, fn ($query) => $query->where('completed_at', '>=', $historyCutoff))
                ->where('status', 'completed')
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->get()
        );
    }

    public function listReminders(Request $request): JsonResponse
    {
        $reminders = $this->scoped(Reminder::query(), $request)->orderBy('remind_at')->orderBy('id')->get();
        $reminders = $this->history->filterReminders($reminders, $request->user());
        $accessibleWorkspaceIds = $this->accessibleWorkspaceIds($request);
        $reminders->each(function (Reminder $reminder) use ($accessibleWorkspaceIds): void {
            $reminder->setAttribute('linked_workspace_ids', $this->linkedItemWorkspaceIds($reminder, 'reminders', $accessibleWorkspaceIds));
        });

        return $this->listed($reminders);
    }

    public function listCalendarEvents(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        if (! $request->boolean('skip_google_sync')) {
            $this->googleCalendar->syncIfConnected($request->user(), $workspace);
        }
        if (! $request->boolean('skip_outlook_sync')) {
            $this->outlookCalendar->syncIfConnected($request->user(), $workspace);
        }
        $this->materializeRecurringCalendarEventsForWorkspace($request, $workspace);

        $query = $this->scoped(CalendarEvent::query(), $request);
        $this->scopeVisibleGoogleCalendars($query, $request, $workspace);

        $events = $this->history->filterCalendarEvents($query->orderBy('starts_at')->orderBy('id')->get(), $request->user())
            ->reject(fn (CalendarEvent $event): bool => (bool) (($event->metadata ?? [])['recurrence_source_hidden'] ?? false))
            ->values();
        $accessibleWorkspaceIds = $this->accessibleWorkspaceIds($request);

        $events->each(function (CalendarEvent $event) use ($accessibleWorkspaceIds): void {
            $event->setAttribute('linked_workspace_ids', $this->linkedCalendarEventWorkspaceIds($event, $accessibleWorkspaceIds));
        });

        return $this->listed($events);
    }

    public function listEventCategories(Request $request): JsonResponse
    {
        $workspaceIds = $this->accessibleWorkspaceIds($request);
        $categories = $request->filled('workspace_id')
            ? $this->scoped(EventCategory::query(), $request)->orderBy('name')->orderBy('id')->get()
            : EventCategory::query()
                ->where('user_id', $request->user()->id)
                ->where(function ($query) use ($workspaceIds): void {
                    $query->whereIn('workspace_id', $workspaceIds)
                        ->orWhereNull('workspace_id');
                })
                ->orderBy('name')
                ->orderBy('id')
                ->get();

        $linkedWorkspaceIdsByName = EventCategory::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('workspace_id', $workspaceIds)
            ->get()
            ->groupBy('name')
            ->map(fn ($items) => $items->pluck('workspace_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());

        $categories->each(function (EventCategory $category) use ($linkedWorkspaceIdsByName): void {
            $category->setAttribute('linked_workspace_ids', $linkedWorkspaceIdsByName->get($category->name, []));
        });

        return $this->listed($categories);
    }

    public function storeTask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['todo', 'chore', 'maintenance'])],
            'status' => ['sometimes', 'required', 'string', Rule::in($this->resourceCatalog->statusesFor('tasks'))],
            'notes' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:20'],
            'is_critical' => ['nullable', 'boolean'],
            'due_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
            'sync_to_workspace_ids' => ['nullable', 'array'],
            'sync_to_workspace_ids.*' => ['integer', 'exists:workspaces,id'],
        ]);

        return $this->created($this->domainResources->createTask($request->user(), $validated));
    }


    public function updateTask(Request $request, string $task): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', Rule::in(['todo', 'chore', 'maintenance'])],
            'status' => ['sometimes', 'required', 'string', Rule::in($this->resourceCatalog->statusesFor('tasks'))],
            'notes' => ['sometimes', 'nullable', 'string'],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'color' => ['sometimes', 'nullable', 'string', 'max:20'],
            'is_critical' => ['sometimes', 'boolean'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'completed_at' => ['sometimes', 'nullable', 'date'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'sync_to_workspace_ids' => ['nullable', 'array'],
            'sync_to_workspace_ids.*' => ['integer', 'exists:workspaces,id'],
        ]);

        return response()->json(['data' => $this->domainResources->updateTask($request->user(), $task, $validated)]);
    }


    public function destroyTask(Request $request, string $task): JsonResponse
    {
        $validated = $request->validate([
            'delete_from_workspace_ids' => ['nullable', 'array'],
            'delete_from_workspace_ids.*' => ['integer', 'exists:workspaces,id'],
        ]);
        $this->domainResources->deleteTask($request->user(), $task, $validated);

        return response()->json(status: 204);
    }


    public function storeReminder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'calendar_event_id' => ['nullable', Rule::exists('calendar_events', 'id')->where('workspace_id', $this->workspace($request)->id)],
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:20'],
            'is_critical' => ['nullable', 'boolean'],
            'remind_at' => ['required', 'date'],
            'status' => ['sometimes', 'required', 'string', Rule::in($this->resourceCatalog->statusesFor('reminders'))],
            'metadata' => ['nullable', 'array'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
            'sync_to_workspace_ids' => ['nullable', 'array'],
            'sync_to_workspace_ids.*' => ['integer', 'exists:workspaces,id'],
        ]);

        return $this->created($this->domainResources->createReminder($request->user(), $validated));
    }


    public function updateReminder(Request $request, string $reminder): JsonResponse
    {
        $model = $this->scoped(Reminder::query(), $request, false)->findOrFail($reminder);
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'calendar_event_id' => ['sometimes', 'nullable', Rule::exists('calendar_events', 'id')->where('workspace_id', $model->workspace_id)],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'color' => ['sometimes', 'nullable', 'string', 'max:20'],
            'is_critical' => ['sometimes', 'boolean'],
            'remind_at' => ['sometimes', 'required', 'date'],
            'status' => ['sometimes', 'required', 'string', Rule::in($this->resourceCatalog->statusesFor('reminders'))],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'sync_to_workspace_ids' => ['nullable', 'array'],
            'sync_to_workspace_ids.*' => ['integer', 'exists:workspaces,id'],
        ]);

        return response()->json(['data' => $this->domainResources->updateReminder($request->user(), $model, $validated)]);
    }


    public function destroyReminder(Request $request, string $reminder): JsonResponse
    {
        $validated = $request->validate([
            'delete_from_workspace_ids' => ['nullable', 'array'],
            'delete_from_workspace_ids.*' => ['integer', 'exists:workspaces,id'],
        ]);
        $this->domainResources->deleteReminder($request->user(), $reminder, $validated);

        return response()->json(status: 204);
    }


    public function storeCalendarEvent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:20'],
            'is_critical' => ['nullable', 'boolean'],
            'recurrence' => ['sometimes', 'required', 'string', Rule::in(self::RECURRENCES)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'all_day' => ['required', $this->canonicalBooleanRule()],
            'status' => ['sometimes', 'required', 'string', Rule::in($this->resourceCatalog->statusesFor('calendar_events'))],
            'metadata' => ['nullable', 'array'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
            'sync_to_workspace_ids' => ['nullable', 'array'],
            'sync_to_workspace_ids.*' => ['integer', 'exists:workspaces,id'],
        ]);

        return $this->created($this->domainResources->createCalendarEvent($request->user(), $validated));
    }


    public function updateCalendarEvent(Request $request, string $calendarEvent): JsonResponse
    {
        $model = $this->scoped(CalendarEvent::query(), $request, false)->findOrFail($calendarEvent);
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'color' => ['sometimes', 'nullable', 'string', 'max:20'],
            'is_critical' => ['sometimes', 'boolean'],
            'recurrence' => ['sometimes', 'required', 'string', Rule::in(self::RECURRENCES)],
            'starts_at' => ['sometimes', 'required', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'all_day' => ['sometimes', 'required', $this->canonicalBooleanRule()],
            'status' => ['sometimes', 'required', 'string', Rule::in($this->resourceCatalog->statusesFor('calendar_events'))],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'sync_to_workspace_ids' => ['nullable', 'array'],
            'sync_to_workspace_ids.*' => ['integer', 'exists:workspaces,id'],
        ]);

        return response()->json(['data' => $this->domainResources->updateCalendarEvent($request->user(), $model, $validated)]);
    }


    public function destroyCalendarEvent(Request $request, string $calendarEvent): JsonResponse
    {
        $validated = $request->validate([
            'delete_from_workspace_ids' => ['nullable', 'array'],
            'delete_from_workspace_ids.*' => ['integer', 'exists:workspaces,id'],
            'recurring_delete_mode' => ['nullable', Rule::in(['all', 'single', 'future'])],
            'recurring_occurrence_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $this->domainResources->deleteCalendarEvent($request->user(), $calendarEvent, $validated);

        return response()->json(status: 204);
    }


    public function storeEventCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['required', 'string', 'max:20'],
            'metadata' => ['nullable', 'array'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
        ]);

        return $this->created($this->domainResources->createEventCategory($request->user(), $validated));
    }


    public function updateEventCategory(Request $request, string $eventCategory): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'color' => ['sometimes', 'required', 'string', 'max:20'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);

        return response()->json(['data' => $this->domainResources->updateEventCategory($request->user(), $eventCategory, $validated)]);
    }


    public function destroyEventCategory(Request $request, string $eventCategory): JsonResponse
    {
        $validated = $request->validate([
            'delete_from_workspace_ids' => ['nullable', 'array'],
            'delete_from_workspace_ids.*' => ['integer', 'exists:workspaces,id'],
        ]);

        $this->domainResources->deleteEventCategory($request->user(), $eventCategory, $validated);
        return response()->json(status: 204);
    }


    private function listed(mixed $models): JsonResponse
    {
        return response()->json(['data' => $models]);
    }

    private function created(Model $model): JsonResponse
    {
        return response()->json(['data' => $model], 201);
    }

    private function destroyed(Model $model): JsonResponse
    {
        $model->delete();

        return response()->json(status: 204);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function owned(Request $request, array $attributes): array
    {
        $workspace = app(WorkspaceService::class)->resolveWorkspace($request->user(), $attributes['workspace_id'] ?? $request->input('workspace_id'));
        unset($attributes['workspace_id']);

        $owned = [
            'user_id' => $request->user()->id,
            'workspace_id' => $workspace->id,
        ] + $attributes;

        $modelClass = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? '';
        if (Schema::hasColumn($this->tableForStoreCaller($modelClass), 'created_by_user_id')) {
            $owned['created_by_user_id'] = $request->user()->id;
        }

        return $owned;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withDefaultUncategorizedColor(array $attributes, bool $missingCategoryIsUncategorized = false): array
    {
        $hasCategory = array_key_exists('category', $attributes);
        if (($missingCategoryIsUncategorized && ! $hasCategory) || ($hasCategory && blank($attributes['category']))) {
            $attributes['category'] = null;
            $attributes['color'] = self::DEFAULT_CATEGORY_COLOR;
        }

        return $attributes;
    }

    private function workspace(Request $request)
    {
        return app(WorkspaceService::class)->resolveWorkspace($request->user(), $request->input('workspace_id'));
    }

    private function scopeVisibleGoogleCalendars($query, Request $request, $workspace): void
    {
        $calendarIds = $this->googleCalendar->visibleGoogleCalendarIdsForWorkspace($request->user(), $workspace);
        if ($calendarIds === null) {
            return;
        }

        $query->where(function ($query) use ($calendarIds): void {
            $query->where(function ($query): void {
                $query->whereNull('metadata->source')
                    ->orWhere('metadata->source', '!=', 'google_calendar');
            });

            if ($calendarIds !== []) {
                $query->orWhere(function ($query) use ($calendarIds): void {
                    $query->where('metadata->source', 'google_calendar')
                        ->where(function ($query) use ($calendarIds): void {
                            $query->whereIn('google_calendar_id', $calendarIds);
                            foreach ($calendarIds as $calendarId) {
                                $query->orWhere('metadata->google_calendar_id', $calendarId);
                            }
                        });
                });
            }
        });
    }

    private function tableForStoreCaller(string $caller): string
    {
        return match ($caller) {
            'storeNoteFolder' => 'note_folders',
            'storeNote' => 'notes',
            'storeTask' => 'tasks',
            'storeReminder' => 'reminders',
            'storeCalendarEvent' => 'calendar_events',
            'storeEventCategory' => 'event_categories',
            default => 'tasks',
        };
    }

    private function scopeNoteSearch($query, string $text): void
    {
        $terms = collect(preg_split('/\s+/u', mb_strtolower($text)) ?: [])
            ->map(fn (string $term): string => trim($term, " \t\n\r\0\x0B'\".,!?-"))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 2)
            ->unique()
            ->take(8)
            ->values();

        $escapedText = addcslashes($text, '%_\\');
        $query->where(function ($query) use ($terms, $escapedText): void {
            $query->where('title', 'like', '%'.$escapedText.'%')
                ->orWhere('plain_text', 'like', '%'.$escapedText.'%')
                ->orWhereHas('folder', fn ($folderQuery) => $folderQuery->where('name', 'like', '%'.$escapedText.'%'));
            foreach ($terms as $term) {
                $escapedTerm = addcslashes($term, '%_\\');
                $query->orWhere('title', 'like', '%'.$escapedTerm.'%')
                    ->orWhere('plain_text', 'like', '%'.$escapedTerm.'%');
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizedNoteAttributes(array $attributes, ?Note $existing = null): array
    {
        $hasBodyHtml = array_key_exists('body_html', $attributes);
        $hasPlainText = array_key_exists('plain_text', $attributes);
        $bodyHtml = $hasBodyHtml ? (string) ($attributes['body_html'] ?? '') : (string) ($existing?->body_html ?? '');
        $plainText = $hasPlainText ? (string) ($attributes['plain_text'] ?? '') : '';
        if ($plainText === '' && $bodyHtml !== '') {
            $plainText = trim(html_entity_decode(strip_tags(str_replace(['</div>', '</p>', '<br>', '<br/>', '<br />'], "\n", $bodyHtml)), ENT_QUOTES | ENT_HTML5));
        }
        if ($hasBodyHtml && ! $hasPlainText) {
            $attributes['plain_text'] = preg_replace("/\n{3,}/", "\n\n", $plainText) ?: '';
        }
        if (! array_key_exists('title', $attributes) || blank($attributes['title'])) {
            $source = trim((string) ($attributes['plain_text'] ?? $plainText));
            $firstLine = trim((string) strtok($source, "\n"));
            $attributes['title'] = $firstLine !== '' ? str($firstLine)->limit(80, '')->toString() : ($existing?->title ?? 'New Note');
        }

        return $attributes;
    }

    private function scoped($query, Request $request, bool $useRequestWorkspace = true)
    {
        if ($useRequestWorkspace || $request->filled('workspace_id')) {
            $workspace = $this->workspace($request);

            return $query->where('workspace_id', $workspace->id);
        }

        $workspaceIds = app(WorkspaceService::class)->accessibleWorkspaces($request->user())->pluck('id')->all();

        return $query->whereIn('workspace_id', $workspaceIds);
    }

    private function syncTo(Request $request, Model $model, array $workspaceIds): void
    {
        if ($workspaceIds === []) {
            return;
        }
        $workspaceService = app(WorkspaceService::class);
        foreach ($workspaceIds as $workspaceId) {
            $workspaceService->authorizeMember($request->user(), Workspace::findOrFail($workspaceId));
        }
        app(WorkspaceItemSyncService::class)->syncToWorkspaceIds($model, $workspaceIds, $request->user());
    }

    private function replaceSyncTo(Request $request, Model $model, string $type, array $workspaceIds): void
    {
        $workspaceIds = array_values(array_unique(array_map('intval', $workspaceIds)));
        $workspaceService = app(WorkspaceService::class);
        foreach ($workspaceIds as $workspaceId) {
            if ($workspaceId > 0) {
                $workspaceService->authorizeMember($request->user(), Workspace::findOrFail($workspaceId));
            }
        }

        $accessibleWorkspaceIds = $this->accessibleWorkspaceIds($request);
        $desiredWorkspaceIds = collect([(int) $model->workspace_id])
            ->merge($workspaceIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $linkedItems = $model instanceof CalendarEvent
            ? $this->linkedCalendarEventsByWorkspace($model, $accessibleWorkspaceIds)
            : $this->linkedItemsByWorkspace($model, $type, $accessibleWorkspaceIds);

        $itemsToRemove = $linkedItems
            ->reject(fn (Model $item): bool => in_array((int) $item->workspace_id, $desiredWorkspaceIds, true))
            ->values();

        if ($itemsToRemove->isNotEmpty()) {
            if ($model instanceof CalendarEvent) {
                $itemsToRemove->each(function (CalendarEvent $event): void {
                    $this->recurringCalendarEvents->deleteGeneratedOccurrences($event);
                });
            }
            $idsToRemove = $itemsToRemove->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $itemsToRemove->each(fn (Model $item): ?bool => $item->delete());
            $this->deleteWorkspaceItemLinksFor($type, $idsToRemove);
        }

        if ($model instanceof Note) {
            $remainingWorkspaceIds = $linkedItems
                ->reject(fn (Model $item): bool => $itemsToRemove->contains(fn (Model $removed): bool => (int) $removed->id === (int) $item->id))
                ->keys()
                ->map(fn ($id): int => (int) $id)
                ->all();
            $notesToCreate = collect($workspaceIds)
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0 && ! in_array($id, $remainingWorkspaceIds, true))
                ->unique()
                ->count();
            if ($response = $this->planLimits->enforceNoteCreationLimit($request->user(), $notesToCreate)) {
                throw new HttpResponseException($response);
            }
        }

        $this->syncTo($request, $model, $workspaceIds);
    }

    private function refreshRecurringCalendarEvents(Request $request, CalendarEvent $event): void
    {
        $accessibleWorkspaceIds = $this->accessibleWorkspaceIds($request);
        $this->linkedCalendarEventsByWorkspace($event, $accessibleWorkspaceIds)
            ->values()
            ->each(fn (CalendarEvent $calendarEvent): int => $this->recurringCalendarEvents->refreshMaterializedOccurrences($calendarEvent));
    }

    private function materializeRecurringCalendarEventsForWorkspace(Request $request, Workspace $workspace): void
    {
        CalendarEvent::query()
            ->where('user_id', $request->user()->id)
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('recurrence')
            ->orderBy('id')
            ->get()
            ->each(fn (CalendarEvent $event): int => $this->recurringCalendarEvents->materialize($event));
    }

    private function accessibleWorkspaceIds(Request $request): array
    {
        return app(WorkspaceService::class)
            ->accessibleWorkspaces($request->user())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function destroyLinkedItems(Request $request, Model $model, string $type): JsonResponse
    {
        $validated = $request->validate([
            'delete_from_workspace_ids' => ['nullable', 'array'],
            'delete_from_workspace_ids.*' => ['integer', 'exists:workspaces,id'],
        ]);

        $workspaceIds = array_values(array_unique(array_map(
            'intval',
            $validated['delete_from_workspace_ids'] ?? [$model->workspace_id]
        )));
        if ($workspaceIds === []) {
            $workspaceIds = [(int) $model->workspace_id];
        }

        $workspaceService = app(WorkspaceService::class);
        $accessibleWorkspaceIds = $this->accessibleWorkspaceIds($request);
        foreach ($workspaceIds as $workspaceId) {
            if (! in_array($workspaceId, $accessibleWorkspaceIds, true)) {
                $workspaceService->authorizeMember($request->user(), Workspace::findOrFail($workspaceId));
            }
        }

        $itemsToDelete = $this->linkedItemsByWorkspace($model, $type, $accessibleWorkspaceIds)->only($workspaceIds)->values();
        if ($itemsToDelete->isEmpty() && in_array((int) $model->workspace_id, $workspaceIds, true)) {
            $itemsToDelete = collect([$model]);
        }

        $ids = $itemsToDelete->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($ids !== []) {
            $itemsToDelete->each(fn (Model $item): ?bool => $item->delete());
            $this->deleteWorkspaceItemLinksFor($type, $ids);
        }

        return response()->json(status: 204);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function propagateLinkedStatusUpdate(Request $request, Model $model, string $type, array $validated): void
    {
        $updates = collect(['status', 'completed_at', 'due_at', 'metadata'])
            ->filter(fn (string $key): bool => array_key_exists($key, $validated))
            ->mapWithKeys(fn (string $key): array => [$key => $validated[$key]])
            ->all();

        if ($updates === []) {
            return;
        }

        app(WorkspaceItemSyncService::class)->propagateStatusUpdate($model, $updates, $this->accessibleWorkspaceIds($request));
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
                ->where('source_type', $type)
                ->where('target_type', $type)
                ->where('link_type', 'copy')
                ->where(function ($query) use ($sourcePairs): void {
                    foreach ($sourcePairs as [$workspaceId, $sourceId]) {
                        $query->orWhere(function ($query) use ($workspaceId, $sourceId): void {
                            $query->where('source_workspace_id', $workspaceId)
                                ->where('source_id', $sourceId);
                        });
                    }
                })
                ->get()
                ->each(function (WorkspaceItemLink $link) use ($relatedIds): void {
                    $relatedIds->push((int) $link->source_id, (int) $link->target_id);
                });
        }

        return $model::query()
            ->whereIn('id', $relatedIds->unique()->values()->all())
            ->whereIn('workspace_id', $accessibleWorkspaceIds)
            ->get()
            ->keyBy(fn (Model $item): int => (int) $item->workspace_id);
    }

    private function linkedItemWorkspaceIds(Model $model, string $type, array $accessibleWorkspaceIds): array
    {
        return $this->linkedItemsByWorkspace($model, $type, $accessibleWorkspaceIds)
            ->keys()
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function itemLinksFor(Model $model, string $type)
    {
        return WorkspaceItemLink::query()
            ->where('source_type', $type)
            ->where('target_type', $type)
            ->where('link_type', 'copy')
            ->where(function ($query) use ($model): void {
                $query->where(function ($query) use ($model): void {
                    $query->where('source_workspace_id', $model->workspace_id)
                        ->where('source_id', $model->id);
                })->orWhere(function ($query) use ($model): void {
                    $query->where('target_workspace_id', $model->workspace_id)
                        ->where('target_id', $model->id);
                });
            })
            ->get();
    }

    /**
     * @param  array<int, int>  $accessibleWorkspaceIds
     */
    private function linkedCalendarEventsByWorkspace(CalendarEvent $event, array $accessibleWorkspaceIds)
    {
        if ($this->recurringCalendarEvents->isGeneratedOccurrence($event)) {
            $occurrenceDate = $this->recurringCalendarEvents->occurrenceDate($event);
            if ($occurrenceDate) {
                return $this->linkedCalendarEventsByWorkspace(
                    $this->recurringCalendarEvents->sourceEventFor($event),
                    $accessibleWorkspaceIds
                )
                    ->map(
                        fn (CalendarEvent $sourceEvent): CalendarEvent => $this->recurringCalendarEvents
                            ->generatedOccurrenceFor($sourceEvent, $occurrenceDate) ?? $sourceEvent
                    )
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
                ->where('source_type', 'calendar_events')
                ->where('target_type', 'calendar_events')
                ->where('link_type', 'copy')
                ->where(function ($query) use ($sourcePairs): void {
                    foreach ($sourcePairs as [$workspaceId, $sourceId]) {
                        $query->orWhere(function ($query) use ($workspaceId, $sourceId): void {
                            $query->where('source_workspace_id', $workspaceId)
                                ->where('source_id', $sourceId);
                        });
                    }
                })
                ->get()
                ->each(function (WorkspaceItemLink $link) use ($relatedIds): void {
                    $relatedIds->push((int) $link->source_id, (int) $link->target_id);
                });
        }

        return CalendarEvent::query()
            ->whereIn('id', $relatedIds->unique()->values()->all())
            ->whereIn('workspace_id', $accessibleWorkspaceIds)
            ->get()
            ->keyBy(fn (CalendarEvent $event): int => (int) $event->workspace_id);
    }

    private function calendarEventIsRecurring(CalendarEvent $event): bool
    {
        return is_string($event->recurrence)
            && in_array($event->recurrence, array_diff(self::RECURRENCES, ['none']), true);
    }

    private function applyRecurringCalendarDelete(CalendarEvent $event, string $mode, string $occurrenceDate): CalendarEvent
    {
        $metadata = $event->metadata ?? [];

        if ($mode === 'single') {
            $exceptions = collect($metadata['recurring_exception_dates'] ?? [])
                ->map(fn ($value): string => trim((string) $value))
                ->filter()
                ->push($occurrenceDate)
                ->unique()
                ->sort()
                ->values()
                ->all();
            $metadata['recurring_exception_dates'] = $exceptions;
        }

        if ($mode === 'future') {
            $metadata['recurrence_until'] = $occurrenceDate;
        }

        $sourceDate = $event->starts_at ? $event->starts_at->copy()->utc()->toDateString() : null;
        if ($sourceDate && $occurrenceDate <= $sourceDate) {
            $metadata['recurrence_source_hidden'] = true;
        }

        $event->forceFill(['metadata' => $metadata])->save();

        return $event->refresh();
    }

    /**
     * @param  array<int, int>  $accessibleWorkspaceIds
     * @return array<int, int>
     */
    private function linkedCalendarEventWorkspaceIds(CalendarEvent $event, array $accessibleWorkspaceIds): array
    {
        return $this->linkedCalendarEventsByWorkspace($event, $accessibleWorkspaceIds)
            ->keys()
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function calendarEventLinksFor(CalendarEvent $event)
    {
        return WorkspaceItemLink::query()
            ->where('source_type', 'calendar_events')
            ->where('target_type', 'calendar_events')
            ->where('link_type', 'copy')
            ->where(function ($query) use ($event): void {
                $query->where(function ($query) use ($event): void {
                    $query->where('source_workspace_id', $event->workspace_id)
                        ->where('source_id', $event->id);
                })->orWhere(function ($query) use ($event): void {
                    $query->where('target_workspace_id', $event->workspace_id)
                        ->where('target_id', $event->id);
                });
            })
            ->get();
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function deleteWorkspaceItemLinksFor(string $type, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        WorkspaceItemLink::query()
            ->where(function ($query) use ($type, $ids): void {
                $query->where(function ($query) use ($type, $ids): void {
                    $query->where('source_type', $type)->whereIn('source_id', $ids);
                })->orWhere(function ($query) use ($type, $ids): void {
                    $query->where('target_type', $type)->whereIn('target_id', $ids);
                });
            })
            ->delete();
    }

    private function taskStatusIsCompleted(?string $status): bool
    {
        return $status === 'completed';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function taskRecurrenceRequested(array $attributes): bool
    {
        $recurrence = $this->taskRecurrenceValue((array) ($attributes['metadata'] ?? []));

        return $recurrence !== null && $recurrence !== 'none';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function reminderRecurrenceRequested(array $attributes): bool
    {
        $recurrence = $this->taskRecurrenceValue((array) ($attributes['metadata'] ?? []));

        return $recurrence !== null && $recurrence !== 'none';
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, mixed>  $syncToWorkspaceIds
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function normalizeReminderNotificationRecipients(Request $request, array $attributes, ?Reminder $reminder = null, array $syncToWorkspaceIds = []): array
    {
        $metadata = $attributes['metadata'] ?? null;
        if (! is_array($metadata)) {
            return $attributes;
        }

        $hasWorkspaceRecipients = array_key_exists('notification_recipients_by_workspace', $metadata)
            || array_key_exists('notificationRecipientsByWorkspace', $metadata);
        $hasFlatRecipients = array_key_exists('notification_recipient_user_ids', $metadata)
            || array_key_exists('notificationRecipientUserIds', $metadata);

        if (! $hasWorkspaceRecipients && ! $hasFlatRecipients) {
            return $attributes;
        }

        $primaryWorkspace = $reminder?->workspace_id
            ? Workspace::findOrFail((int) $reminder->workspace_id)
            : app(WorkspaceService::class)->resolveWorkspace($request->user(), $attributes['workspace_id'] ?? $request->input('workspace_id'));
        $allowedWorkspaceIds = collect([(int) $primaryWorkspace->id])
            ->merge($syncToWorkspaceIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $rawByWorkspace = $metadata['notification_recipients_by_workspace']
            ?? $metadata['notificationRecipientsByWorkspace']
            ?? null;
        $recipientsByWorkspace = [];
        if (is_array($rawByWorkspace)) {
            foreach ($rawByWorkspace as $workspaceId => $recipientIds) {
                $workspaceId = (int) $workspaceId;
                if ($workspaceId <= 0) {
                    continue;
                }
                $recipientsByWorkspace[$workspaceId] = $this->normalizeReminderRecipientIds($recipientIds);
            }
        } elseif ($hasFlatRecipients) {
            $recipientsByWorkspace[(int) $primaryWorkspace->id] = $this->normalizeReminderRecipientIds(
                $metadata['notification_recipient_user_ids'] ?? $metadata['notificationRecipientUserIds'] ?? []
            );
        }

        $invalidWorkspaceIds = array_values(array_diff(array_keys($recipientsByWorkspace), $allowedWorkspaceIds));
        if ($invalidWorkspaceIds !== []) {
            throw ValidationException::withMessages([
                'metadata.notification_recipients_by_workspace' => 'Reminder notification recipients must belong to the reminder workspace or a synced workspace.',
            ]);
        }

        $memberships = WorkspaceMembership::query()
            ->whereIn('workspace_id', array_keys($recipientsByWorkspace) ?: $allowedWorkspaceIds)
            ->where('status', 'active')
            ->whereNotNull('user_id')
            ->get(['workspace_id', 'user_id'])
            ->groupBy(fn (WorkspaceMembership $membership): int => (int) $membership->workspace_id)
            ->map(fn ($items) => $items->pluck('user_id')->map(fn ($id): int => (int) $id)->all());

        foreach ($recipientsByWorkspace as $workspaceId => $recipientIds) {
            $allowedUserIds = $memberships->get($workspaceId, []);
            if (array_diff($recipientIds, $allowedUserIds) !== []) {
                throw ValidationException::withMessages([
                    'metadata.notification_recipients_by_workspace' => 'Reminder notification recipients must be active members of the selected workspace.',
                ]);
            }
        }

        $recipientsByWorkspace = collect($recipientsByWorkspace)
            ->map(fn (array $ids): array => array_values(array_unique($ids)))
            ->all();

        $metadata['notification_recipients_by_workspace'] = $recipientsByWorkspace;
        $metadata['notification_recipient_user_ids'] = collect($recipientsByWorkspace)->flatten()->unique()->values()->all();
        unset($metadata['notificationRecipientsByWorkspace'], $metadata['notificationRecipientUserIds']);

        $attributes['metadata'] = $this->resetReminderNotificationDeliveryMetadata($metadata);

        return $attributes;
    }

    /**
     * @return array<int, int>
     */
    private function normalizeReminderRecipientIds(mixed $recipientIds): array
    {
        return collect(is_array($recipientIds) ? $recipientIds : [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
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

    private function calendarRecurrenceRequested(mixed $recurrence): bool
    {
        return is_string($recurrence)
            && in_array($recurrence, array_diff(self::RECURRENCES, ['none']), true);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function advanceRecurringTaskCompletion(Task $task, array &$validated): bool
    {
        $metadata = $this->taskRecurrenceMetadata($task, $validated);
        $recurrence = $this->taskRecurrenceValue($metadata);
        if ($recurrence === null || $recurrence === 'none') {
            return false;
        }

        $nextDueAt = $this->nextRecurringTaskDueAt($task, $metadata, $recurrence);
        if ($nextDueAt === null) {
            return false;
        }

        $completedAt = Carbon::parse($validated['completed_at'] ?? now())->utc();
        $metadata['recurrence'] = $recurrence;
        $metadata['last_completed_at'] = $completedAt->toIso8601String();
        if ($task->due_at !== null) {
            $metadata['last_completed_due_at'] = $task->due_at->copy()->utc()->toIso8601String();
        }
        $metadata['completion_count'] = max(0, (int) ($metadata['completion_count'] ?? 0)) + 1;

        $validated['status'] = 'open';
        $validated['completed_at'] = null;
        $validated['due_at'] = $nextDueAt->utc();
        $validated['metadata'] = $metadata;

        return true;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function taskRecurrenceMetadata(Task $task, array $validated): array
    {
        $metadata = is_array($task->metadata) ? $task->metadata : [];

        if (array_key_exists('metadata', $validated)) {
            $metadata = is_array($validated['metadata']) ? $validated['metadata'] : [];
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function taskRecurrenceValue(array $metadata): ?string
    {
        $recurrence = $metadata['recurrence'] ?? null;

        return is_string($recurrence) && in_array($recurrence, self::RECURRENCES, true)
            ? $recurrence
            : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function nextRecurringTaskDueAt(Task $task, array $metadata, string $recurrence): ?Carbon
    {
        $cursor = ($task->due_at ?: now())->copy()->utc();
        $now = now()->utc();

        for ($guard = 0; $guard < 500; $guard++) {
            $cursor = $this->advanceTaskRecurrenceDate($cursor, $metadata, $recurrence);
            if ($cursor === null) {
                return null;
            }
            if ($cursor->gt($now)) {
                return $cursor;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
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

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function nextSpecificTaskDay(Carbon $from, array $metadata): ?Carbon
    {
        $days = collect($metadata['days'] ?? [])
            ->filter(fn ($day): bool => is_string($day) && in_array($day, self::RECURRENCE_DAYS, true))
            ->unique()
            ->values();
        if ($days->isEmpty()) {
            return null;
        }

        $cursor = $from->copy()->addDay();
        for ($guard = 0; $guard < 14; $guard++) {
            $day = strtolower($cursor->format('D'));
            if ($days->contains($day === 'thu' ? 'thu' : $day)) {
                return $cursor;
            }
            $cursor->addDay();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function addTaskRecurrenceInterval(Carbon $from, array $metadata): ?Carbon
    {
        $interval = $metadata['interval'] ?? null;
        $unit = $metadata['unit'] ?? null;
        if (! is_int($interval) || $interval < 1 || ! in_array($unit, self::RECURRENCE_UNITS, true)) {
            return null;
        }

        return match ($unit) {
            'days' => $from->copy()->addDays($interval),
            'weeks' => $from->copy()->addWeeks($interval),
            'months' => $from->copy()->addMonthsNoOverflow($interval),
            'years' => $from->copy()->addYearsNoOverflow($interval),
        };
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function recurrenceMetadataRules(bool $calendar = false): array
    {
        return [
            'metadata.recurrence' => $calendar
                ? ['prohibited']
                : ['sometimes', 'required', 'string', Rule::in(self::RECURRENCES)],
            'metadata.days' => ['sometimes', 'required', 'array', 'min:1'],
            'metadata.days.*' => ['required', 'string', Rule::in(self::RECURRENCE_DAYS), 'distinct:strict'],
            'metadata.interval' => ['sometimes', 'required', 'integer', 'min:1'],
            'metadata.unit' => ['sometimes', 'required', 'string', Rule::in(self::RECURRENCE_UNITS)],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertCanonicalRecurrenceMetadata(
        array $attributes,
        ?string $calendarRecurrence = null,
        bool $calendar = false,
        bool $rejectLegacyKeys = true,
    ): void {
        $metadata = $attributes['metadata'] ?? null;
        if (! is_array($metadata)) {
            return;
        }

        if ($rejectLegacyKeys) {
            validator(
                ['metadata' => $metadata],
                $this->recurrenceMetadataRules($calendar),
            )->validate();
        }

        $errors = [];
        if ($rejectLegacyKeys) {
            foreach (self::LEGACY_RECURRENCE_METADATA_KEYS as $key) {
                if (array_key_exists($key, $metadata)) {
                    $errors["metadata.{$key}"] = 'Use the canonical recurrence metadata fields recurrence, days, interval, and unit.';
                }
            }
        }

        $recurrence = $calendar ? $calendarRecurrence : ($metadata['recurrence'] ?? null);
        $hasDays = array_key_exists('days', $metadata);
        $hasInterval = array_key_exists('interval', $metadata);
        $hasUnit = array_key_exists('unit', $metadata);

        if ($recurrence === 'specific_days') {
            if (! $hasDays) {
                $errors['metadata.days'] = 'Specific-day recurrence requires canonical days.';
            }
            if ($hasInterval || $hasUnit) {
                $errors['metadata.interval'] = 'Specific-day recurrence accepts only days.';
            }
        } elseif ($recurrence === 'interval') {
            if (! $hasInterval) {
                $errors['metadata.interval'] = 'Interval recurrence requires a positive interval.';
            }
            if (! $hasUnit) {
                $errors['metadata.unit'] = 'Interval recurrence requires days, weeks, months, or years.';
            }
            if ($hasDays) {
                $errors['metadata.days'] = 'Interval recurrence does not accept days.';
            }
        } elseif ($hasDays || $hasInterval || $hasUnit) {
            $errors['metadata'] = 'Recurrence detail fields require specific_days or interval recurrence.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function enforceNotesAccess(Request $request): ?JsonResponse
    {
        return $this->planLimits->canUseNotes($request->user())
            ? null
            : $this->planLimits->limitResponse('Notes are available on this plan after upgrading.');
    }

    private function additionalNotesForSync(int $workspaceId, array $syncToWorkspaceIds): int
    {
        return 1 + collect($syncToWorkspaceIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0 && $id !== $workspaceId)
            ->unique()
            ->count();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $fields
     */
    private function normalizeDateFields(array &$attributes, array $fields): void
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $attributes) || $attributes[$field] === null || $attributes[$field] === '') {
                continue;
            }

            $attributes[$field] = Carbon::parse((string) $attributes[$field])->utc();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function storeCanonicalCalendarAllDay(array &$attributes, ?CalendarEvent $event = null): void
    {
        if (! array_key_exists('all_day', $attributes)) {
            return;
        }

        $allDay = $attributes['all_day'];
        unset($attributes['all_day']);

        $metadata = array_key_exists('metadata', $attributes)
            ? ($attributes['metadata'] ?? [])
            : ($event?->metadata ?? []);
        if (! is_array($metadata)) {
            $metadata = [];
        }
        unset($metadata['allDay']);
        $attributes['metadata'] = array_merge($metadata, ['all_day' => $allDay]);
    }

    private function canonicalBooleanRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_bool($value)) {
                $fail("The {$attribute} field must be a boolean.");
            }
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function rejectCalendarAllDayMetadataFields(array $attributes): void
    {
        $metadata = $attributes['metadata'] ?? null;
        if (! is_array($metadata)) {
            return;
        }

        $errors = [];
        foreach (['all_day', 'allDay'] as $field) {
            if (array_key_exists($field, $metadata)) {
                $errors["metadata.{$field}"] = ['All-day state must use the top-level all_day boolean.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
