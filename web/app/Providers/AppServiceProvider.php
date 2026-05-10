<?php

namespace App\Providers;

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
        $this->app->bind(HermesRuntimeService::class, StubHermesRuntimeService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
