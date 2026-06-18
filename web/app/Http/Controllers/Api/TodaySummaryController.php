<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use App\Models\Approval;
use App\Models\Blocker;
use App\Models\CalendarEvent;
use App\Models\ConversationSession;
use App\Models\Reminder;
use App\Models\Task;
use App\Services\AgentProfileService;
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
        $session = ConversationSession::where('user_id', $user->id)
            ->where('workspace_id', $workspace->id)
            ->latest('last_activity_at')
            ->latest('id')
            ->first();

        $tasks = Task::where('workspace_id', $workspace->id)->visibleInActiveViews()->latest('updated_at')->get();
        $agentProfileService = app(AgentProfileService::class);
        $agentProfile = $agentProfileService->ensureForWorkspace($workspace, $user);
        $user = $agentProfileService->syncUserOnboardingFlag($user, $agentProfile);
        $agentProfile = $agentProfile->refresh();
        $user->setAttribute('needs_bean_onboarding', $agentProfileService->needsOnboarding($user, $agentProfile));
        $user->setAttribute('bean_preferences_ready', $agentProfileService->preferencesReady($agentProfile));
        $user->setAttribute('plan_limits', app(PlanLimitService::class)->publicLimitsFor($user));
        $agentProfileService->exposePublicSettings($agentProfile);
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
        $calendarEvents = $history->filterCalendarEvents($calendarEventsQuery->orderBy('starts_at')->get(), $user);
        $activityEvents = $history->filterActivityEvents(ActivityEvent::where('user_id', $user->id)->orderBy('id')->get(), $user);
        $approvals = $history->filterApprovals(Approval::where('user_id', $user->id)->latest('updated_at')->get(), $user);
        $blockers = $history->filterBlockers(Blocker::where('user_id', $user->id)->latest('updated_at')->get(), $user);

        return response()->json([
            'data' => [
                'user' => $user,
                'agent_profile' => $agentProfile,
                'workspace' => $workspace,
                'workspaces' => $workspaceService->accessibleWorkspaces($user),
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
