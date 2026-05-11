<?php

use App\Http\Controllers\Api\ActivityEventController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationMessageController;
use App\Http\Controllers\Api\ConversationSessionController;
use App\Http\Controllers\Api\DomainResourceController;
use App\Http\Controllers\Api\TodaySummaryController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.rate_limit')->group(function (): void {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth.bearer')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::delete('/account', [AuthController::class, 'destroy']);
        Route::get('/account/export', [AuthController::class, 'export']);

        Route::post('/assistant/sessions', [ConversationSessionController::class, 'store']);
        Route::get('/assistant/sessions/{session}', [ConversationSessionController::class, 'show']);
        Route::post('/assistant/sessions/{session}/messages', [ConversationMessageController::class, 'store']);
        Route::get('/assistant/sessions/{session}/events', [ActivityEventController::class, 'index']);

        Route::get('/today', [TodaySummaryController::class, 'show']);

        Route::get('/tasks', [DomainResourceController::class, 'listTasks']);
        Route::post('/tasks', [DomainResourceController::class, 'storeTask']);
        Route::get('/reminders', [DomainResourceController::class, 'listReminders']);
        Route::post('/reminders', [DomainResourceController::class, 'storeReminder']);
        Route::get('/calendar-events', [DomainResourceController::class, 'listCalendarEvents']);
        Route::post('/calendar-events', [DomainResourceController::class, 'storeCalendarEvent']);
        Route::get('/approvals', [DomainResourceController::class, 'listApprovals']);
        Route::post('/approvals', [DomainResourceController::class, 'storeApproval']);
        Route::post('/approvals/{approval}/approve', [DomainResourceController::class, 'approveApproval']);
        Route::post('/approvals/{approval}/deny', [DomainResourceController::class, 'denyApproval']);
        Route::get('/blockers', [DomainResourceController::class, 'listBlockers']);
        Route::post('/blockers', [DomainResourceController::class, 'storeBlocker']);
        Route::post('/scheduler-jobs', [DomainResourceController::class, 'storeSchedulerJob']);
    });
});
