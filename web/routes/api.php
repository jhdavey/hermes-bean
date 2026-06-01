<?php

use App\Http\Controllers\Api\ActivityEventController;
use App\Http\Controllers\Api\AdminUsageController;
use App\Http\Controllers\Api\AssistantRunController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationMessageController;
use App\Http\Controllers\Api\ConversationSessionController;
use App\Http\Controllers\Api\DashboardChangeController;
use App\Http\Controllers\Api\DomainResourceController;
use App\Http\Controllers\Api\GoogleCalendarController;
use App\Http\Controllers\Api\IssueReportController;
use App\Http\Controllers\Api\RealtimeSessionController;
use App\Http\Controllers\Api\TextToSpeechController;
use App\Http\Controllers\Api\TodaySummaryController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.rate_limit')->group(function (): void {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::get('/google-calendar/callback', [GoogleCalendarController::class, 'callback']);

    Route::middleware('auth.bearer')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::patch('/auth/me', [AuthController::class, 'update']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::delete('/account', [AuthController::class, 'destroy']);
        Route::get('/account/export', [AuthController::class, 'export']);

        Route::get('/workspaces', [WorkspaceController::class, 'index']);
        Route::post('/workspaces', [WorkspaceController::class, 'store']);
        Route::patch('/workspaces/default', [WorkspaceController::class, 'setDefault']);
        Route::get('/workspaces/{workspace}', [WorkspaceController::class, 'show']);
        Route::patch('/workspaces/{workspace}', [WorkspaceController::class, 'update']);
        Route::post('/workspaces/{workspace}/invitations', [WorkspaceController::class, 'invite']);
        Route::post('/workspace-invitations/{token}/accept', [WorkspaceController::class, 'accept']);
        Route::patch('/workspaces/{workspace}/members/{member}', [WorkspaceController::class, 'updateMember']);
        Route::delete('/workspaces/{workspace}/members/{member}', [WorkspaceController::class, 'destroyMember']);
        Route::post('/workspaces/{workspace}/leave', [WorkspaceController::class, 'leave']);
        Route::post('/workspaces/{source}/sync-all', [WorkspaceController::class, 'syncAll']);
        Route::patch('/workspaces/{workspace}/google-calendars', [WorkspaceController::class, 'calendars']);

        Route::post('/assistant/sessions', [ConversationSessionController::class, 'store']);
        Route::get('/assistant/sessions/{session}', [ConversationSessionController::class, 'show']);
        Route::post('/assistant/sessions/{session}/cancel', [ConversationSessionController::class, 'cancel']);
        Route::post('/assistant/sessions/{session}/messages', [ConversationMessageController::class, 'store']);
        Route::post('/assistant/sessions/{session}/runs', [AssistantRunController::class, 'store']);
        Route::get('/assistant/sessions/{session}/events', [ActivityEventController::class, 'index']);
        Route::get('/assistant/runs/{run}', [AssistantRunController::class, 'show']);
        Route::post('/assistant/runs/{run}/cancel', [AssistantRunController::class, 'cancel']);
        Route::post('/assistant/realtime/sessions', [RealtimeSessionController::class, 'store']);
        Route::post('/assistant/realtime/tool-calls', [RealtimeSessionController::class, 'toolCall']);
        Route::post('/ai/realtime/session', [RealtimeSessionController::class, 'store']);
        Route::post('/assistant/tts', [TextToSpeechController::class, 'store']);

        Route::get('/today', [TodaySummaryController::class, 'show']);
        Route::get('/dashboard-changes', [DashboardChangeController::class, 'index']);
        Route::post('/issue-reports', [IssueReportController::class, 'store']);
        Route::get('/google-calendar/status', [GoogleCalendarController::class, 'status']);
        Route::post('/google-calendar/auth-url', [GoogleCalendarController::class, 'authUrl']);
        Route::post('/google-calendar/sync', [GoogleCalendarController::class, 'sync']);
        Route::patch('/google-calendar/calendars', [GoogleCalendarController::class, 'calendars']);
        Route::delete('/google-calendar', [GoogleCalendarController::class, 'disconnect']);

        Route::get('/tasks', [DomainResourceController::class, 'listTasks']);
        Route::get('/tasks/past', [DomainResourceController::class, 'listPastTasks']);
        Route::post('/tasks', [DomainResourceController::class, 'storeTask']);
        Route::patch('/tasks/{task}', [DomainResourceController::class, 'updateTask']);
        Route::delete('/tasks/{task}', [DomainResourceController::class, 'destroyTask']);
        Route::get('/reminders', [DomainResourceController::class, 'listReminders']);
        Route::post('/reminders', [DomainResourceController::class, 'storeReminder']);
        Route::patch('/reminders/{reminder}', [DomainResourceController::class, 'updateReminder']);
        Route::delete('/reminders/{reminder}', [DomainResourceController::class, 'destroyReminder']);
        Route::get('/calendar-events', [DomainResourceController::class, 'listCalendarEvents']);
        Route::post('/calendar-events', [DomainResourceController::class, 'storeCalendarEvent']);
        Route::patch('/calendar-events/{calendarEvent}', [DomainResourceController::class, 'updateCalendarEvent']);
        Route::delete('/calendar-events/{calendarEvent}', [DomainResourceController::class, 'destroyCalendarEvent']);
        Route::get('/event-categories', [DomainResourceController::class, 'listEventCategories']);
        Route::post('/event-categories', [DomainResourceController::class, 'storeEventCategory']);
        Route::patch('/event-categories/{eventCategory}', [DomainResourceController::class, 'updateEventCategory']);
        Route::delete('/event-categories/{eventCategory}', [DomainResourceController::class, 'destroyEventCategory']);
        Route::get('/approvals', [DomainResourceController::class, 'listApprovals']);
        Route::post('/approvals', [DomainResourceController::class, 'storeApproval']);
        Route::patch('/approvals/{approval}', [DomainResourceController::class, 'updateApproval']);
        Route::delete('/approvals/{approval}', [DomainResourceController::class, 'destroyApproval']);
        Route::post('/approvals/{approval}/approve', [DomainResourceController::class, 'approveApproval']);
        Route::post('/approvals/{approval}/deny', [DomainResourceController::class, 'denyApproval']);
        Route::get('/blockers', [DomainResourceController::class, 'listBlockers']);
        Route::post('/blockers', [DomainResourceController::class, 'storeBlocker']);
        Route::patch('/blockers/{blocker}', [DomainResourceController::class, 'updateBlocker']);
        Route::delete('/blockers/{blocker}', [DomainResourceController::class, 'destroyBlocker']);

        Route::middleware('admin')->prefix('admin')->group(function (): void {
            Route::get('/usage/summary', [AdminUsageController::class, 'summary']);
            Route::get('/usage/logs', [AdminUsageController::class, 'logs']);
            Route::get('/usage/alerts', [AdminUsageController::class, 'alerts']);
        });
    });
});
