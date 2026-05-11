<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use App\Models\AgentProfile;
use App\Models\Approval;
use App\Models\Blocker;
use App\Models\CalendarEvent;
use App\Models\ConversationSession;
use App\Models\Reminder;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TodaySummaryController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $session = ConversationSession::where('user_id', $user->id)
            ->latest('last_activity_at')
            ->latest('id')
            ->first();

        $tasks = Task::where('user_id', $user->id)->latest('updated_at')->get();
        $agentProfile = AgentProfile::where('user_id', $user->id)->first();
        $reminders = Reminder::where('user_id', $user->id)->latest('remind_at')->get();
        $calendarEvents = CalendarEvent::where('user_id', $user->id)->orderBy('starts_at')->get();
        $activityEvents = ActivityEvent::where('user_id', $user->id)->orderBy('id')->get();
        $approvals = Approval::where('user_id', $user->id)->latest('updated_at')->get();
        $blockers = Blocker::where('user_id', $user->id)->latest('updated_at')->get();

        return response()->json([
            'data' => [
                'user' => $user,
                'agent_profile' => $agentProfile,
                'session' => $session,
                'tasks' => $tasks,
                'reminders' => $reminders,
                'calendar_events' => $calendarEvents,
                'activity_events' => $activityEvents,
                'approvals' => $approvals,
                'blockers' => $blockers,
                'counts' => [
                    'tasks' => $tasks->count(),
                    'reminders' => $reminders->count(),
                    'calendar_events' => $calendarEvents->count(),
                    'activity_events' => $activityEvents->count(),
                    'approvals' => $approvals->count(),
                    'blockers' => $blockers->count(),
                ],
            ],
        ]);
    }
}
