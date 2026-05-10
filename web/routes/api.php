<?php

use App\Http\Controllers\Api\ActivityEventController;
use App\Http\Controllers\Api\ConversationMessageController;
use App\Http\Controllers\Api\ConversationSessionController;
use App\Http\Controllers\Api\DomainResourceController;
use Illuminate\Support\Facades\Route;

Route::post('/assistant/sessions', [ConversationSessionController::class, 'store']);
Route::get('/assistant/sessions/{session}', [ConversationSessionController::class, 'show']);
Route::post('/assistant/sessions/{session}/messages', [ConversationMessageController::class, 'store']);
Route::get('/assistant/sessions/{session}/events', [ActivityEventController::class, 'index']);

Route::post('/tasks', [DomainResourceController::class, 'storeTask']);
Route::post('/reminders', [DomainResourceController::class, 'storeReminder']);
Route::post('/calendar-events', [DomainResourceController::class, 'storeCalendarEvent']);
Route::post('/approvals', [DomainResourceController::class, 'storeApproval']);
Route::post('/blockers', [DomainResourceController::class, 'storeBlocker']);
Route::post('/scheduler-jobs', [DomainResourceController::class, 'storeSchedulerJob']);
