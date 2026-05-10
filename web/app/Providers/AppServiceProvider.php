<?php

namespace App\Providers;

use App\Services\HermesCliRuntimeService;
use App\Services\HermesRuntimeService;
use App\Services\StubHermesRuntimeService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(HermesRuntimeService::class, function ($app): HermesRuntimeService {
            if (config('services.hermes_runtime.mode', 'stub') === 'cli') {
                return $app->make(HermesCliRuntimeService::class);
            }

            return $app->make(StubHermesRuntimeService::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
