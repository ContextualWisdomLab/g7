<?php

namespace App\Http\Controllers;

use App\Services\SystemReadinessService;
use Illuminate\Http\JsonResponse;

/**
 * Exposes the public traffic-admission signal for this application instance.
 */
final class SystemReadinessController extends Controller
{
    /**
     * Return a minimal readiness response without infrastructure diagnostics.
     */
    public function __invoke(SystemReadinessService $readiness): JsonResponse
    {
        $ready = $readiness->isReady();

        return response()
            ->json(
                ['status' => $ready ? 'ready' : 'not_ready'],
                $ready ? 200 : 503,
            )
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
