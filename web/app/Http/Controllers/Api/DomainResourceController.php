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
use App\Services\GoogleCalendarSyncService;
use App\Services\StructuredHermesActionService;
use App\Services\WorkspaceItemSyncService;
use App\Services\WorkspaceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        return $this->listed($this->scoped(Task::query(), $request)->visibleInActiveViews()->orderBy('id')->get());
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
        $this->googleCalendar->syncIfConnected($request->user(), $this->workspace($request));

        return $this->listed($this->scoped(CalendarEvent::query(), $request)->orderBy('starts_at')->orderBy('id')->get());
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
        $syncTo = $validated['sync_to_workspace_ids'] ?? [];
        unset($validated['sync_to_workspace_ids']);
        $model->update($validated);
        $this->syncTo($request, $model->refresh(), $syncTo);

        return response()->json(['data' => $this->googleCalendar->exportEvent($model->refresh())]);
    }

    public function destroyCalendarEvent(Request $request, string $calendarEvent): JsonResponse
    {
        return $this->destroyed($this->scoped(CalendarEvent::query(), $request, false)->findOrFail($calendarEvent));
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
        return $this->created(SchedulerJobRecord::create($this->owned($request, $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'scheduled_for' => ['nullable', 'date'],
            'started_at' => ['nullable', 'date'],
            'finished_at' => ['nullable', 'date'],
            'payload' => ['nullable', 'array'],
            'last_error' => ['nullable', 'string'],
        ]))));
    }

    public function updateSchedulerJob(Request $request, string $schedulerJob): JsonResponse
    {
        $model = SchedulerJobRecord::where('user_id', $request->user()->id)->findOrFail($schedulerJob);
        $model->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'scheduled_for' => ['sometimes', 'nullable', 'date'],
            'started_at' => ['sometimes', 'nullable', 'date'],
            'finished_at' => ['sometimes', 'nullable', 'date'],
            'payload' => ['sometimes', 'nullable', 'array'],
            'last_error' => ['sometimes', 'nullable', 'string'],
        ]));

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
            $workspaceService->authorizeMember($request->user(), \App\Models\Workspace::findOrFail($workspaceId));
        }
        app(WorkspaceItemSyncService::class)->syncToWorkspaceIds($model, $workspaceIds, $request->user());
    }

    private function taskStatusIsCompleted(?string $status): bool
    {
        return in_array(strtolower(str_replace('_', '-', (string) $status)), ['completed', 'complete', 'done'], true);
    }
}
