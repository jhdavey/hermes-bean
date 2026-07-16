<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\Reminder;
use App\Models\Task;
use App\Services\GoogleCalendarSyncService;
use App\Services\PlanHistoryService;
use App\Services\PlanLimitService;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TodaySummaryController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $workspaceService = app(WorkspaceService::class);
        $workspace = $workspaceService->resolveWorkspace($user, $request->query('workspace_id'));
        $tasks = Task::where('workspace_id', $workspace->id)->visibleInActiveViews()->latest('updated_at')->get();
        $user->setAttribute('plan_limits', app(PlanLimitService::class)->publicLimitsFor($user));
        $history = app(PlanHistoryService::class);
        $reminders = $history->filterReminders(Reminder::where('workspace_id', $workspace->id)->latest('remind_at')->get(), $user);
        $calendarEventsQuery = CalendarEvent::where('workspace_id', $workspace->id);
        $visibleGoogleCalendarIds = app(GoogleCalendarSyncService::class)->visibleGoogleCalendarIdsForWorkspace($user, $workspace);
        if ($visibleGoogleCalendarIds !== null) {
            $calendarEventsQuery->where(function ($query) use ($visibleGoogleCalendarIds): void {
                $query->where(function ($query): void {
                    $query->whereNull('metadata->source')
                        ->orWhere('metadata->source', '!=', 'google_calendar');
                });

                if ($visibleGoogleCalendarIds !== []) {
                    $query->orWhere(function ($query) use ($visibleGoogleCalendarIds): void {
                        $query->where('metadata->source', 'google_calendar')
                            ->where(function ($query) use ($visibleGoogleCalendarIds): void {
                                $query->whereIn('google_calendar_id', $visibleGoogleCalendarIds);
                                foreach ($visibleGoogleCalendarIds as $calendarId) {
                                    $query->orWhere('metadata->google_calendar_id', $calendarId);
                                }
                            });
                    });
                }
            });
        }
        $calendarEvents = $history->filterCalendarEvents($calendarEventsQuery->orderBy('starts_at')->get(), $user)
            ->reject(fn (CalendarEvent $event): bool => (bool) (($event->metadata ?? [])['recurrence_source_hidden'] ?? false))
            ->values();
        return response()->json([
            'data' => [
                'user' => $user,
                'workspace' => $workspace,
                'workspaces' => $workspaceService->accessibleWorkspaces($user),
                'tasks' => $tasks,
                'reminders' => $reminders,
                'calendar_events' => $calendarEvents,
                'counts' => [
                    'tasks' => $tasks->count(),
                    'reminders' => $reminders->count(),
                    'calendar_events' => $calendarEvents->count(),
                ],
            ],
        ]);
    }
}
