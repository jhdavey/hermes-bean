<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\Blocker;
use App\Models\CalendarEvent;
use App\Models\Reminder;
use App\Models\SchedulerJobRecord;
use App\Models\Task;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DomainResourceController extends Controller
{
    public function storeTask(Request $request): JsonResponse
    {
        return $this->created(Task::create($request->validate([
            'conversation_session_id' => ['nullable', 'integer', 'exists:conversation_sessions,id'],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['todo', 'chore', 'maintenance'])],
            'status' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ])));
    }

    public function storeReminder(Request $request): JsonResponse
    {
        return $this->created(Reminder::create($request->validate([
            'conversation_session_id' => ['nullable', 'integer', 'exists:conversation_sessions,id'],
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'remind_at' => ['required', 'date'],
            'status' => ['nullable', 'string', 'max:50'],
            'metadata' => ['nullable', 'array'],
        ])));
    }

    public function storeCalendarEvent(Request $request): JsonResponse
    {
        return $this->created(CalendarEvent::create($request->validate([
            'conversation_session_id' => ['nullable', 'integer', 'exists:conversation_sessions,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'status' => ['nullable', 'string', 'max:50'],
            'metadata' => ['nullable', 'array'],
        ])));
    }

    public function storeApproval(Request $request): JsonResponse
    {
        return $this->created(Approval::create($request->validate([
            'conversation_session_id' => ['nullable', 'integer', 'exists:conversation_sessions,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:50'],
            'payload' => ['nullable', 'array'],
        ])));
    }

    public function storeBlocker(Request $request): JsonResponse
    {
        return $this->created(Blocker::create($request->validate([
            'conversation_session_id' => ['nullable', 'integer', 'exists:conversation_sessions,id'],
            'reason' => ['required', 'string'],
            'status' => ['nullable', 'string', 'max:50'],
            'context' => ['nullable', 'array'],
        ])));
    }

    public function storeSchedulerJob(Request $request): JsonResponse
    {
        return $this->created(SchedulerJobRecord::create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'scheduled_for' => ['nullable', 'date'],
            'started_at' => ['nullable', 'date'],
            'finished_at' => ['nullable', 'date'],
            'payload' => ['nullable', 'array'],
            'last_error' => ['nullable', 'string'],
        ])));
    }

    private function created(Model $model): JsonResponse
    {
        return response()->json(['data' => $model], 201);
    }
}
