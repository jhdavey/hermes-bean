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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        return $this->listed(Task::where('user_id', $request->user()->id)->visibleInActiveViews()->orderBy('id')->get());
    }

    public function listPastTasks(Request $request): JsonResponse
    {
        return $this->listed(
            Task::where('user_id', $request->user()->id)
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
        return $this->listed(Reminder::where('user_id', $request->user()->id)->orderBy('remind_at')->orderBy('id')->get());
    }

    public function listCalendarEvents(Request $request): JsonResponse
    {
        $this->googleCalendar->syncIfConnected($request->user());

        return $this->listed(CalendarEvent::where('user_id', $request->user()->id)->orderBy('starts_at')->orderBy('id')->get());
    }

    public function listEventCategories(Request $request): JsonResponse
    {
        return $this->listed(EventCategory::where('user_id', $request->user()->id)->orderBy('name')->orderBy('id')->get());
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
        ]);

        if ($this->taskStatusIsCompleted($validated['status'] ?? null) && empty($validated['completed_at'])) {
            $validated['completed_at'] = now();
        }

        return $this->created(Task::create($this->owned($request, $validated)));
    }

    public function updateTask(Request $request, string $task): JsonResponse
    {
        $model = Task::where('user_id', $request->user()->id)->findOrFail($task);
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

        $model->update($validated);

        return response()->json(['data' => $model->refresh()]);
    }

    public function destroyTask(Request $request, string $task): JsonResponse
    {
        return $this->destroyed(Task::where('user_id', $request->user()->id)->findOrFail($task));
    }

    public function storeReminder(Request $request): JsonResponse
    {
        return $this->created(Reminder::create($this->owned($request, $request->validate([
            'conversation_session_id' => $this->ownedSessionRule($request),
            'calendar_event_id' => ['nullable', Rule::exists('calendar_events', 'id')->where('user_id', $request->user()->id)],
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:20'],
            'is_critical' => ['nullable', 'boolean'],
            'remind_at' => ['required', 'date'],
            'status' => ['nullable', 'string', 'max:50'],
            'metadata' => ['nullable', 'array'],
        ]))));
    }

    public function updateReminder(Request $request, string $reminder): JsonResponse
    {
        $model = Reminder::where('user_id', $request->user()->id)->findOrFail($reminder);
        $model->update($request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'calendar_event_id' => ['sometimes', 'nullable', Rule::exists('calendar_events', 'id')->where('user_id', $request->user()->id)],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'color' => ['sometimes', 'nullable', 'string', 'max:20'],
            'is_critical' => ['sometimes', 'boolean'],
            'remind_at' => ['sometimes', 'required', 'date'],
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]));

        return response()->json(['data' => $model->refresh()]);
    }

    public function destroyReminder(Request $request, string $reminder): JsonResponse
    {
        return $this->destroyed(Reminder::where('user_id', $request->user()->id)->findOrFail($reminder));
    }

    public function storeCalendarEvent(Request $request): JsonResponse
    {
        $event = CalendarEvent::create($this->owned($request, $request->validate([
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
        ])));

        return $this->created($this->googleCalendar->exportEvent($event));
    }

    public function updateCalendarEvent(Request $request, string $calendarEvent): JsonResponse
    {
        $model = CalendarEvent::where('user_id', $request->user()->id)->findOrFail($calendarEvent);
        $model->update($request->validate([
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
        ]));

        return response()->json(['data' => $this->googleCalendar->exportEvent($model->refresh())]);
    }

    public function destroyCalendarEvent(Request $request, string $calendarEvent): JsonResponse
    {
        return $this->destroyed(CalendarEvent::where('user_id', $request->user()->id)->findOrFail($calendarEvent));
    }

    public function storeEventCategory(Request $request): JsonResponse
    {
        return $this->created(EventCategory::create($this->owned($request, $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['required', 'string', 'max:20'],
            'metadata' => ['nullable', 'array'],
        ]))));
    }

    public function updateEventCategory(Request $request, string $eventCategory): JsonResponse
    {
        $model = EventCategory::where('user_id', $request->user()->id)->findOrFail($eventCategory);
        $model->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'color' => ['sometimes', 'required', 'string', 'max:20'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]));

        return response()->json(['data' => $model->refresh()]);
    }

    public function destroyEventCategory(Request $request, string $eventCategory): JsonResponse
    {
        $model = EventCategory::where('user_id', $request->user()->id)->findOrFail($eventCategory);
        CalendarEvent::where('user_id', $request->user()->id)
            ->where('category', $model->name)
            ->update(['category' => null, 'color' => null]);
        Task::where('user_id', $request->user()->id)
            ->where('category', $model->name)
            ->update(['category' => null, 'color' => null]);
        Reminder::where('user_id', $request->user()->id)
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
        return ['user_id' => $request->user()->id] + $attributes;
    }

    private function taskStatusIsCompleted(?string $status): bool
    {
        return in_array(strtolower(str_replace('_', '-', (string) $status)), ['completed', 'complete', 'done'], true);
    }
}
