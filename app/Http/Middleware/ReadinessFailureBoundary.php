<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Keeps the public readiness contract fail-closed when probe middleware fails.
 */
final class ReadinessFailureBoundary
{
    /**
     * Preserve intentional HTTP responses such as 429 while converting
     * unexpected limiter/dependency failures into the minimal 503 contract.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        try {
            return $next($request);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable) {
            return $this->notReadyResponse();
        }
    }

    /**
     * Build the same non-cacheable response exposed by the readiness controller.
     */
    private function notReadyResponse(): JsonResponse
    {
        return response()
            ->json(['status' => 'not_ready'], 503)
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
