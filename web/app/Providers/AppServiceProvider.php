<?php

namespace App\Providers;

use App\Models\CalendarEvent;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\Task;
use App\Observers\DashboardResourceObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Task::observe(DashboardResourceObserver::class);
        Reminder::observe(DashboardResourceObserver::class);
        CalendarEvent::observe(DashboardResourceObserver::class);
        Note::observe(DashboardResourceObserver::class);
    }
}
