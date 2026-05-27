<?php

use App\Http\Middleware\ApiRateLimit;
use App\Http\Middleware\ApiSecurityHeaders;
use App\Http\Middleware\AuthenticateBearerToken;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\HandleApiCors;
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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([
            HandleApiCors::class,
            ApiSecurityHeaders::class,
        ]);

        $middleware->alias([
            'auth.bearer' => AuthenticateBearerToken::class,
            'admin' => EnsureAdmin::class,
            'api.rate_limit' => ApiRateLimit::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
