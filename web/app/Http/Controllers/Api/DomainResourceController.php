<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\Blocker;
use App\Models\CalendarEvent;
use App\Models\Reminder;
use App\Models\SchedulerJobRecord;
use App\Models\Task;
use App\Services\StructuredHermesActionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class DomainResourceController extends Controller
{
    public function __construct(private readonly StructuredHermesActionService $actions) {}

    public function listTasks(Request $request): JsonResponse
    {
        return $this->listed(Task::where('user_id', $request->user()->id)->orderBy('id')->get());
    }

    public function listReminders(Request $request): JsonResponse
    {
        return $this->listed(Reminder::where('user_id', $request->user()->id)->orderBy('remind_at')->orderBy('id')->get());
    }

    public function listCalendarEvents(Request $request): JsonResponse
    {
        return $this->listed(CalendarEvent::where('user_id', $request->user()->id)->orderBy('starts_at')->orderBy('id')->get());
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
        return $this->created(Task::create($this->owned($request, $request->validate([
            'conversation_session_id' => $this->ownedSessionRule($request),
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['todo', 'chore', 'maintenance'])],
            'status' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ]))));
    }

    public function updateTask(Request $request, string $task): JsonResponse
    {
        $model = Task::where('user_id', $request->user()->id)->findOrFail($task);
        $model->update($request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', Rule::in(['todo', 'chore', 'maintenance'])],
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]));

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
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
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
        return $this->created(CalendarEvent::create($this->owned($request, $request->validate([
            'conversation_session_id' => $this->ownedSessionRule($request),
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'status' => ['nullable', 'string', 'max:50'],
            'metadata' => ['nullable', 'array'],
        ]))));
    }

    public function updateCalendarEvent(Request $request, string $calendarEvent): JsonResponse
    {
        $model = CalendarEvent::where('user_id', $request->user()->id)->findOrFail($calendarEvent);
        $model->update($request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'starts_at' => ['sometimes', 'required', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]));

        return response()->json(['data' => $model->refresh()]);
    }

    public function destroyCalendarEvent(Request $request, string $calendarEvent): JsonResponse
    {
        return $this->destroyed(CalendarEvent::where('user_id', $request->user()->id)->findOrFail($calendarEvent));
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
}
