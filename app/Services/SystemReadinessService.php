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
    /** @var list<string> */
    private const SUPPORTED_CHECKS = ['database', 'cache', 'storage'];

    /**
     * MySQL terminates this read-only probe after one second if execution stalls.
     */
    private const DATABASE_PROBE = 'SELECT /*+ MAX_EXECUTION_TIME(1000) */ 1 AS readiness_value';

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

        $validatedChecks = [];

        foreach ($checks as $check) {
            if (
                ! is_string($check)
                || $check === ''
                || ! in_array($check, self::SUPPORTED_CHECKS, true)
                || isset($validatedChecks[$check])
            ) {
                return false;
            }

            $validatedChecks[$check] = true;
        }

        foreach ($checks as $check) {
            if (! $this->runCheck($check)) {
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
            };
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Verify that both write and read database paths can execute a bounded query.
     */
    private function checkDatabase(): bool
    {
        $connection = $this->database->connection();
        $connection->selectOne(self::DATABASE_PROBE, [], false);
        $connection->selectOne(self::DATABASE_PROBE, [], true);

        return true;
    }

    /**
     * Verify that an explicitly configured non-ephemeral cache store can answer
     * a read request. Array, null, or missing stores do not prove dependency
     * readiness and therefore fail closed before cache access.
     */
    private function checkCache(): bool
    {
        $cacheStore = (string) $this->config->get('readiness.cache_store', '');
        $cacheDriver = (string) $this->config->get("cache.stores.{$cacheStore}.driver", '');

        if ($cacheStore === '' || in_array($cacheDriver, ['', 'array', 'null'], true)) {
            return false;
        }

        $this->cache->store($cacheStore)->get('__g7_readiness_probe__');

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
