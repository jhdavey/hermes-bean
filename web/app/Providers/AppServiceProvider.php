<?php

namespace App\Providers;

use App\Contracts\RealtimeVoiceProviderEventHandler;
use App\Contracts\RealtimeVoiceSidebandTransport;
use App\Models\CalendarEvent;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\Task;
use App\Observers\DashboardResourceObserver;
use App\Services\HermesRuntimeService;
use App\Services\HermesSemanticInterpreter;
use App\Services\HermesSemanticRuntimeService;
use App\Services\OpenAiHermesSemanticInterpreter;
use App\Services\PawlRealtimeVoiceSidebandTransport;
use App\Services\RealtimeVoiceApplicationEventHandler;
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
        $this->app->bind(RealtimeVoiceSidebandTransport::class, PawlRealtimeVoiceSidebandTransport::class);
        $this->app->bind(RealtimeVoiceProviderEventHandler::class, RealtimeVoiceApplicationEventHandler::class);
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
