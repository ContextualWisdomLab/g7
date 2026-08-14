<?php

namespace App\Services;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * Evaluates whether this application instance can safely accept user traffic.
 *
 * Checks are side-effect free and fail closed. Detailed dependency failures
 * are intentionally not returned or logged because the public readiness
 * endpoint is an orchestration signal, not an infrastructure diagnostic API.
 */
class SystemReadinessService
{
    /**
     * Create the readiness evaluator.
     */
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly CacheManager $cache,
        private readonly Filesystem $filesystem,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Determine whether every configured required dependency is ready.
     */
    public function isReady(): bool
    {
        $checks = $this->config->get('readiness.checks');

        if (! is_array($checks) || $checks === []) {
            return false;
        }

        foreach ($checks as $check) {
            if (! is_string($check) || $check === '' || ! $this->runCheck($check)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Run one supported readiness check and convert every failure to false.
     */
    private function runCheck(string $check): bool
    {
        try {
            return match ($check) {
                'database' => $this->checkDatabase(),
                'cache' => $this->checkCache(),
                'storage' => $this->checkStorage(),
                default => false,
            };
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Verify that the configured database connection can be acquired.
     */
    private function checkDatabase(): bool
    {
        $this->database->connection()->getPdo();

        return true;
    }

    /**
     * Verify that the configured cache store can answer a read request.
     */
    private function checkCache(): bool
    {
        $this->cache->store()->get('__g7_readiness_probe__');

        return true;
    }

    /**
     * Verify that Laravel's runtime storage directory exists and is writable.
     */
    private function checkStorage(): bool
    {
        $path = $this->config->get('readiness.storage_path');

        if (! is_string($path) || $path === '') {
            return false;
        }

        return $this->filesystem->isDirectory($path)
            && $this->filesystem->isWritable($path);
    }
}
