<?php

use App\Console\Commands\ExecuteBeanDashboardTool;
use App\Console\Commands\MaterializeRecurringCalendarEvents;
use App\Console\Commands\SendDueReminderNotifications;
use App\Http\Middleware\ApiRateLimit;
use App\Http\Middleware\ApiSecurityHeaders;
use App\Http\Middleware\AuthenticateBearerToken;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\HandleApiCors;
use App\Http\Middleware\RecordPageView;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        ExecuteBeanDashboardTool::class,
        MaterializeRecurringCalendarEvents::class,
        SendDueReminderNotifications::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([
            HandleApiCors::class,
            ApiSecurityHeaders::class,
        ]);
        $middleware->web(append: [
            RecordPageView::class,
        ]);

        $middleware->alias([
            'auth.bearer' => AuthenticateBearerToken::class,
            'admin' => EnsureAdmin::class,
            'api.rate_limit' => ApiRateLimit::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Application exceptions use Laravel's default rendering behavior.
    })->create();
