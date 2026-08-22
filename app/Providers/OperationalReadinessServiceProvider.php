<?php

namespace App\Providers;

use App\Http\Controllers\SystemReadinessController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers stateless operational probe routes outside web and API groups.
 */
final class OperationalReadinessServiceProvider extends ServiceProvider
{
    /**
     * Register the traffic-readiness route with a bounded public probe rate.
     */
    public function boot(): void
    {
        RateLimiter::for(
            'readiness',
            static fn (Request $request): Limit => Limit::perMinute(120)->by($request->ip()),
        );

        Route::middleware('throttle:readiness')
            ->get('/ready', SystemReadinessController::class)
            ->name('system.readiness');
    }
}
