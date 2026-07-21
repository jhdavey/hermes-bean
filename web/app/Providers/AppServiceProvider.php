<?php

namespace App\Providers;

use App\Models\CalendarEvent;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\Task;
use App\Observers\DashboardResourceObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void {}

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerLandingBeanRateLimits();

        Task::observe(DashboardResourceObserver::class);
        Reminder::observe(DashboardResourceObserver::class);
        CalendarEvent::observe(DashboardResourceObserver::class);
        Note::observe(DashboardResourceObserver::class);
    }

    private function registerLandingBeanRateLimits(): void
    {
        $capacityResponse = static fn (Request $request, array $headers) => response()->json([
            'message' => 'Bean demos are at capacity right now. Please try again later.',
        ], 429, $headers);
        $visitorResponse = static fn (Request $request, array $headers) => response()->json([
            'message' => 'You have reached the landing Bean demo limit. Please try again later.',
        ], 429, $headers);

        RateLimiter::for('landing-bean-sessions', function (Request $request) use ($capacityResponse, $visitorResponse): array {
            $sessionKey = hash('sha256', $request->session()->getId());
            $ipKey = hash('sha256', (string) $request->ip());

            return [
                Limit::perMinute(1)->by("landing-bean:session:minute:{$sessionKey}")->response($visitorResponse),
                Limit::perHour(max(1, (int) config('bean.landing.sessions_per_hour', 3)))->by("landing-bean:session:hour:{$sessionKey}")->response($visitorResponse),
                Limit::perDay(max(1, (int) config('bean.landing.sessions_per_day', 6)))->by("landing-bean:session:day:{$sessionKey}")->response($visitorResponse),
                Limit::perHour(max(1, (int) config('bean.landing.ip_sessions_per_hour', 12)))->by("landing-bean:ip:hour:{$ipKey}")->response($visitorResponse),
                Limit::perDay(max(1, (int) config('bean.landing.ip_sessions_per_day', 30)))->by("landing-bean:ip:day:{$ipKey}")->response($visitorResponse),
                Limit::perMinute(max(1, (int) config('bean.landing.global_sessions_per_minute', 12)))->by('landing-bean:global:minute')->response($capacityResponse),
                Limit::perDay(max(1, (int) config('bean.landing.global_sessions_per_day', 150)))->by('landing-bean:global:day')->response($capacityResponse),
            ];
        });

        RateLimiter::for('landing-bean-messages', function (Request $request) use ($visitorResponse): array {
            $sessionKey = hash('sha256', $request->session()->getId());
            $ipKey = hash('sha256', (string) $request->ip());

            return [
                Limit::perMinute(12)->by("landing-bean:message:minute:{$sessionKey}")->response($visitorResponse),
                Limit::perHour(max(1, (int) config('bean.landing.messages_per_hour', 80)))->by("landing-bean:message:hour:{$sessionKey}")->response($visitorResponse),
                Limit::perDay(max(1, (int) config('bean.landing.messages_per_day', 160)))->by("landing-bean:message:day:{$sessionKey}")->response($visitorResponse),
                Limit::perHour(max(1, (int) config('bean.landing.messages_per_hour', 80)) * 4)->by("landing-bean:message:ip:hour:{$ipKey}")->response($visitorResponse),
            ];
        });
    }
}
