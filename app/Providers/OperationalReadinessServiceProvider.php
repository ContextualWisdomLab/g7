<?php

namespace App\Providers;

use App\Http\Controllers\SystemReadinessController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers stateless operational probe routes outside web and API groups.
 */
final class OperationalReadinessServiceProvider extends ServiceProvider
{
    /**
     * Register the traffic-readiness route.
     */
    public function boot(): void
    {
        Route::get('/ready', SystemReadinessController::class)
            ->name('system.readiness');
    }
}
