<?php

namespace App\Providers;

use App\Models\CalendarEvent;
use App\Models\Reminder;
use App\Models\Task;
use App\Observers\DashboardResourceObserver;
use App\Services\HermesRuntimeService;
use App\Services\HermesSemanticInterpreter;
use App\Services\HermesSemanticRuntimeService;
use App\Services\OpenAiHermesSemanticInterpreter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(HermesRuntimeService::class, HermesSemanticRuntimeService::class);
        $this->app->bind(HermesSemanticInterpreter::class, OpenAiHermesSemanticInterpreter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Task::observe(DashboardResourceObserver::class);
        Reminder::observe(DashboardResourceObserver::class);
        CalendarEvent::observe(DashboardResourceObserver::class);
    }
}
