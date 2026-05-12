<?php

namespace App\Providers;

use App\Services\HermesCliRuntimeService;
use App\Services\HermesRuntimeService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(HermesRuntimeService::class, HermesCliRuntimeService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
