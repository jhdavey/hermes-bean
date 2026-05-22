<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\Blocker;
use App\Models\CalendarEvent;
use App\Models\EventCategory;
use App\Models\Reminder;
use App\Models\SchedulerJobRecord;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceItemLink;
use App\Services\GoogleCalendarSyncService;
use App\Services\StructuredHermesActionService;
use App\Services\WorkspaceItemSyncService;
use App\Services\WorkspaceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class DomainResourceController extends Controller
{
    public function __construct(
        private readonly StructuredHermesActionService $actions,
        private readonly GoogleCalendarSyncService $googleCalendar,
    ) {}

    public function listTasks(Request $request): JsonResponse
    {
        return $this->listed(
            $this->scoped(Task::query(), $request)
                ->where(function ($query): void {
                    $query->whereNull('status')
                        ->orWhereNotIn('status', ['completed', 'complete', 'done', 'COMPLETED', 'Complete', 'Done']);
                })
                ->orderBy('due_at')
                ->orderBy('id')
                ->get()
        );
    }

    public function listPastTasks(Request $request): JsonResponse
    {
        return $this->listed(
            $this->scoped(Task::query(), $request)
                ->whereNotNull('completed_at')
                ->where('completed_at', '>=', now()->subDays(10))
                ->where(function ($query): void {
                    $query->whereIn('status', ['completed', 'complete', 'done'])
                        ->orWhereIn('status', ['COMPLETED', 'Complete', 'Done']);
                })
                ->whereNot(function ($query): void {
                    $query->visibleInActiveViews();
                })
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->get()
        );
    }

    public function listReminders(Request $request): JsonResponse
    {
        return $this->listed($this->scoped(Reminder::query(), $request)->orderBy('remind_at')->orderBy('id')->get());
    }

    public function listCalendarEvents(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $this->googleCalendar->syncIfConnected($request->user(), $workspace);

        $query = $this->scoped(CalendarEvent::query(), $request);
        $this->scopeVisibleGoogleCalendars($query, $request, $workspace);

        $events = $query->orderBy('starts_at')->orderBy('id')->get();
        $accessibleWorkspaceIds = app(WorkspaceService::class)
            ->accessibleWorkspaces($request->user())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $events->each(function (CalendarEvent $event) use ($accessibleWorkspaceIds): void {
            $event->setAttribute('linked_workspace_ids', $this->linkedCalendarEventWorkspaceIds($event, $accessibleWorkspaceIds));
        });

        return $this->listed($events);
    }

    public function listEventCategories(Request $request): JsonResponse
    {
        return $this->listed($this->scoped(EventCategory::query(), $request)->orderBy('name')->orderBy('id')->get());
    }

    public function listApprovals(Request $request): JsonResponse
    {
        return $this->listed(Approval::where('user_id', $request->user()->id)->orderBy('id')->get());
    }

    public function listBlockers(Request $request): JsonResponse
    {
        return $this->listed(Blocker::where('user_id', $request->user()->id)->orderBy('id')->get());
    }

    public function storeTask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_session_id' => $this->ownedSessionRule($request),
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['todo', 'chore', 'maintenance'])],
            'status' => ['nullable', 'string', 'max:50'],
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

        $this->normalizeDateFields($validated, ['due_at', 'completed_at']);

        if ($this->taskStatusIsCompleted($validated['status'] ?? null) && empty($validated['completed_at'])) {
            $validated['completed_at'] = now();
        }

        $syncTo = $validated['sync_to_workspace_ids'] ?? [];
        unset($validated['sync_to_workspace_ids']);
        $task = Task::create($this->owned($request, $validated));
        $this->syncTo($request, $task, $syncTo);

        return $this->created($task->refresh());
    }

    public function updateTask(Request $request, string $task): JsonResponse
    {
        $model = $this->scoped(Task::query(), $request, false)->findOrFail($task);
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', Rule::in(['todo', 'chore', 'maintenance'])],
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
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

        $this->normalizeDateFields($validated, ['due_at', 'completed_at']);

        if (array_key_exists('status', $validated)) {
            $willBeCompleted = $this->taskStatusIsCompleted($validated['status']);
            if ($willBeCompleted && $model->completed_at === null && ! array_key_exists('completed_at', $validated)) {
                $validated['completed_at'] = now();
            }
            if (! $willBeCompleted && ! array_key_exists('completed_at', $validated)) {
                $validated['completed_at'] = null;
            }
            if (! $willBeCompleted && ! array_key_exists('due_at', $validated) && $model->due_at !== null && $model->due_at->lt(now()->startOfDay())) {
                $validated['due_at'] = null;
            }
        }

        $syncTo = $validated['sync_to_workspace_ids'] ?? [];
        unset($validated['sync_to_workspace_ids']);
        $model->update($validated);
        $this->syncTo($request, $model->refresh(), $syncTo);

        return response()->json(['data' => $model->refresh()]);
    }

    public function destroyTask(Request $request, string $task): JsonResponse
    {
        return $this->destroyed($this->scoped(Task::query(), $request, false)->findOrFail($task));
    }

    public function storeReminder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_session_id' => $this->ownedSessionRule($request),
            'calendar_event_id' => ['nullable', Rule::exists('calendar_events', 'id')->where('workspace_id', $this->workspace($request)->id)],
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:20'],
            'is_critical' => ['nullable', 'boolean'],
            'remind_at' => ['required', 'date'],
            'status' => ['nullable', 'string', 'max:50'],
            'metadata' => ['nullable', 'array'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
            'sync_to_workspace_ids' => ['nullable', 'array'],
            'sync_to_workspace_ids.*' => ['integer', 'exists:workspaces,id'],
        ]);
        $this->normalizeDateFields($validated, ['remind_at']);
        $syncTo = $validated['sync_to_workspace_ids'] ?? [];
        unset($validated['sync_to_workspace_ids']);
        $reminder = Reminder::create($this->owned($request, $validated));
        $this->syncTo($request, $reminder, $syncTo);

        return $this->created($reminder->refresh());
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
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'sync_to_workspace_ids' => ['nullable', 'array'],
            'sync_to_workspace_ids.*' => ['integer', 'exists:workspaces,id'],
        ]);
        $this->normalizeDateFields($validated, ['remind_at']);
        $syncTo = $validated['sync_to_workspace_ids'] ?? [];
        unset($validated['sync_to_workspace_ids']);
        $model->update($validated);
        $this->syncTo($request, $model->refresh(), $syncTo);

        return response()->json(['data' => $model->refresh()]);
    }

    public function destroyReminder(Request $request, string $reminder): JsonResponse
    {
        return $this->destroyed($this->scoped(Reminder::query(), $request, false)->findOrFail($reminder));
    }

    public function storeCalendarEvent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_session_id' => $this->ownedSessionRule($request),
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:20'],
            'is_critical' => ['nullable', 'boolean'],
            'recurrence' => ['nullable', 'string', 'max:50'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'status' => ['nullable', 'string', 'max:50'],
            'metadata' => ['nullable', 'array'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
            'sync_to_workspace_ids' => ['nullable', 'array'],
            'sync_to_workspace_ids.*' => ['integer', 'exists:workspaces,id'],
        ]);
        $this->normalizeDateFields($validated, ['starts_at', 'ends_at']);
        $syncTo = $validated['sync_to_workspace_ids'] ?? [];
        unset($validated['sync_to_workspace_ids']);
        $event = CalendarEvent::create($this->owned($request, $validated));
        $this->syncTo($request, $event, $syncTo);

        return $this->created($this->googleCalendar->exportEvent($event->refresh()));
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
            'recurrence' => ['sometimes', 'nullable', 'string', 'max:50'],
            'starts_at' => ['sometimes', 'required', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'sync_to_workspace_ids' => ['nullable', 'array'],
            'sync_to_workspace_ids.*' => ['integer', 'exists:workspaces,id'],
        ]);
        $this->normalizeDateFields($validated, ['starts_at', 'ends_at']);
        $syncTo = $validated['sync_to_workspace_ids'] ?? [];
        unset($validated['sync_to_workspace_ids']);
        $model->update($validated);
        $this->syncTo($request, $model->refresh(), $syncTo);

        return response()->json(['data' => $this->googleCalendar->exportEvent($model->refresh())]);
    }

    public function destroyCalendarEvent(Request $request, string $calendarEvent): JsonResponse
    {
        $event = $this->scoped(CalendarEvent::query(), $request, false)->findOrFail($calendarEvent);
        $validated = $request->validate([
            'delete_from_workspace_ids' => ['nullable', 'array'],
            'delete_from_workspace_ids.*' => ['integer', 'exists:workspaces,id'],
        ]);

        $workspaceIds = array_values(array_unique(array_map(
            'intval',
            $validated['delete_from_workspace_ids'] ?? [$event->workspace_id]
        )));
        if ($workspaceIds === []) {
            $workspaceIds = [(int) $event->workspace_id];
        }

        $workspaceService = app(WorkspaceService::class);
        $accessibleWorkspaceIds = $workspaceService->accessibleWorkspaces($request->user())->pluck('id')->map(fn ($id): int => (int) $id)->all();
        foreach ($workspaceIds as $workspaceId) {
            if (! in_array($workspaceId, $accessibleWorkspaceIds, true)) {
                $workspaceService->authorizeMember($request->user(), Workspace::findOrFail($workspaceId));
            }
        }

        $eventsByWorkspace = $this->linkedCalendarEventsByWorkspace($event, $accessibleWorkspaceIds);
        $eventsToDelete = $eventsByWorkspace->only($workspaceIds)->values();
        if ($eventsToDelete->isEmpty() && in_array((int) $event->workspace_id, $workspaceIds, true)) {
            $eventsToDelete = collect([$event]);
        }

        $eventIds = $eventsToDelete->pluck('id')->map(fn ($id): int => (int) $id)->all();
        foreach ($eventsToDelete as $eventToDelete) {
            $this->googleCalendar->deleteExportedEvent($eventToDelete);
        }
        CalendarEvent::whereIn('id', $eventIds)->delete();
        $this->deleteWorkspaceItemLinksFor('calendar_events', $eventIds);

        return response()->json(status: 204);
    }

    public function storeEventCategory(Request $request): JsonResponse
    {
        return $this->created(EventCategory::create($this->owned($request, $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['required', 'string', 'max:20'],
            'metadata' => ['nullable', 'array'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
        ]))));
    }

    public function updateEventCategory(Request $request, string $eventCategory): JsonResponse
    {
        $model = $this->scoped(EventCategory::query(), $request, false)->findOrFail($eventCategory);
        $model->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'color' => ['sometimes', 'required', 'string', 'max:20'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]));

        return response()->json(['data' => $model->refresh()]);
    }

    public function destroyEventCategory(Request $request, string $eventCategory): JsonResponse
    {
        $model = $this->scoped(EventCategory::query(), $request, false)->findOrFail($eventCategory);
        CalendarEvent::where('workspace_id', $model->workspace_id)
            ->where('category', $model->name)
            ->update(['category' => null, 'color' => null]);
        Task::where('workspace_id', $model->workspace_id)
            ->where('category', $model->name)
            ->update(['category' => null, 'color' => null]);
        Reminder::where('workspace_id', $model->workspace_id)
            ->where('category', $model->name)
            ->update(['category' => null, 'color' => null]);

        return $this->destroyed($model);
    }

    public function storeApproval(Request $request): JsonResponse
    {
        return $this->created(Approval::create($this->owned($request, $request->validate([
            'conversation_session_id' => $this->ownedSessionRule($request),
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:50'],
            'payload' => ['nullable', 'array'],
        ]))));
    }

    public function updateApproval(Request $request, string $approval): JsonResponse
    {
        $model = Approval::where('user_id', $request->user()->id)->findOrFail($approval);
        $model->update($request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'payload' => ['sometimes', 'nullable', 'array'],
        ]));

        return response()->json(['data' => $model->refresh()]);
    }

    public function destroyApproval(Request $request, string $approval): JsonResponse
    {
        return $this->destroyed(Approval::where('user_id', $request->user()->id)->findOrFail($approval));
    }

    public function approveApproval(Request $request, string $approval): JsonResponse
    {
        $ownedApproval = Approval::where('user_id', $request->user()->id)->findOrFail($approval);

        try {
            $result = $this->actions->approve($ownedApproval);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $result]);
    }

    public function denyApproval(Request $request, string $approval): JsonResponse
    {
        $ownedApproval = Approval::where('user_id', $request->user()->id)->findOrFail($approval);

        try {
            $approval = $this->actions->deny($ownedApproval);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => ['approval' => $approval]]);
    }

    public function storeBlocker(Request $request): JsonResponse
    {
        return $this->created(Blocker::create($this->owned($request, $request->validate([
            'conversation_session_id' => $this->ownedSessionRule($request),
            'reason' => ['required', 'string'],
            'status' => ['nullable', 'string', 'max:50'],
            'context' => ['nullable', 'array'],
        ]))));
    }

    public function updateBlocker(Request $request, string $blocker): JsonResponse
    {
        $model = Blocker::where('user_id', $request->user()->id)->findOrFail($blocker);
        $model->update($request->validate([
            'reason' => ['sometimes', 'required', 'string'],
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'context' => ['sometimes', 'nullable', 'array'],
        ]));

        return response()->json(['data' => $model->refresh()]);
    }

    public function destroyBlocker(Request $request, string $blocker): JsonResponse
    {
        return $this->destroyed(Blocker::where('user_id', $request->user()->id)->findOrFail($blocker));
    }

    public function storeSchedulerJob(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'scheduled_for' => ['nullable', 'date'],
            'started_at' => ['nullable', 'date'],
            'finished_at' => ['nullable', 'date'],
            'payload' => ['nullable', 'array'],
            'last_error' => ['nullable', 'string'],
        ]);
        $this->normalizeDateFields($validated, ['scheduled_for', 'started_at', 'finished_at']);

        return $this->created(SchedulerJobRecord::create($this->owned($request, $validated)));
    }

    public function updateSchedulerJob(Request $request, string $schedulerJob): JsonResponse
    {
        $model = SchedulerJobRecord::where('user_id', $request->user()->id)->findOrFail($schedulerJob);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'scheduled_for' => ['sometimes', 'nullable', 'date'],
            'started_at' => ['sometimes', 'nullable', 'date'],
            'finished_at' => ['sometimes', 'nullable', 'date'],
            'payload' => ['sometimes', 'nullable', 'array'],
            'last_error' => ['sometimes', 'nullable', 'string'],
        ]);
        $this->normalizeDateFields($validated, ['scheduled_for', 'started_at', 'finished_at']);
        $model->update($validated);

        return response()->json(['data' => $model->refresh()]);
    }

    public function destroySchedulerJob(Request $request, string $schedulerJob): JsonResponse
    {
        return $this->destroyed(SchedulerJobRecord::where('user_id', $request->user()->id)->findOrFail($schedulerJob));
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
     * @return array<int, mixed>
     */
    private function ownedSessionRule(Request $request): array
    {
        return [
            'nullable',
            'integer',
            Rule::exists('conversation_sessions', 'id')->where('user_id', $request->user()->id),
        ];
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
            'storeTask' => 'tasks',
            'storeReminder' => 'reminders',
            'storeCalendarEvent' => 'calendar_events',
            'storeEventCategory' => 'event_categories',
            'storeApproval' => 'approvals',
            'storeBlocker' => 'blockers',
            'storeSchedulerJob' => 'scheduler_job_records',
            default => 'tasks',
        };
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

    /**
     * @param  array<int, int>  $accessibleWorkspaceIds
     */
    private function linkedCalendarEventsByWorkspace(CalendarEvent $event, array $accessibleWorkspaceIds)
    {
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
        return in_array(strtolower(str_replace('_', '-', (string) $status)), ['completed', 'complete', 'done'], true);
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
}
