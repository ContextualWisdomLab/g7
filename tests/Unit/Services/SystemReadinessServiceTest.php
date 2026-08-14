<?php

namespace Tests\Unit\Services;

use App\Services\SystemReadinessService;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Fail-closed dependency readiness tests.
 */
class SystemReadinessServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Every configured dependency must be reachable before the instance is ready.
     */
    public function test_returns_true_when_all_configured_dependencies_are_ready(): void
    {
        $database = Mockery::mock(DatabaseManager::class);
        $connection = Mockery::mock(Connection::class);
        $database->shouldReceive('connection')->once()->andReturn($connection);
        $connection->shouldReceive('getPdo')->once()->andReturn(new \stdClass());

        $cache = Mockery::mock(CacheManager::class);
        $cacheRepository = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('store')->once()->andReturn($cacheRepository);
        $cacheRepository->shouldReceive('get')
            ->once()
            ->with('__g7_readiness_probe__')
            ->andReturnNull();

        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('isDirectory')->once()->with('/runtime')->andReturnTrue();
        $filesystem->shouldReceive('isWritable')->once()->with('/runtime')->andReturnTrue();

        $service = $this->makeService(
            ['database', 'cache', 'storage'],
            '/runtime',
            $database,
            $cache,
            $filesystem,
        );

        self::assertTrue($service->isReady());
    }

    /**
     * Database connection exceptions fail closed and stop later checks.
     */
    public function test_returns_false_when_database_connection_throws(): void
    {
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->once()->andThrow(new \RuntimeException('secret host'));

        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldNotReceive('store');
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldNotReceive('isDirectory');

        $service = $this->makeService(
            ['database', 'cache', 'storage'],
            '/runtime',
            $database,
            $cache,
            $filesystem,
        );

        self::assertFalse($service->isReady());
    }

    /**
     * Cache connection exceptions fail closed and stop later checks.
     */
    public function test_returns_false_when_cache_connection_throws(): void
    {
        $database = Mockery::mock(DatabaseManager::class);
        $connection = Mockery::mock(Connection::class);
        $database->shouldReceive('connection')->once()->andReturn($connection);
        $connection->shouldReceive('getPdo')->once()->andReturn(new \stdClass());

        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldReceive('store')->once()->andThrow(new \RuntimeException('secret redis host'));

        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldNotReceive('isDirectory');

        $service = $this->makeService(
            ['database', 'cache', 'storage'],
            '/runtime',
            $database,
            $cache,
            $filesystem,
        );

        self::assertFalse($service->isReady());
    }

    /**
     * A missing runtime directory is not ready and does not evaluate writability.
     */
    public function test_returns_false_when_storage_directory_is_missing(): void
    {
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldNotReceive('connection');
        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldNotReceive('store');
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('isDirectory')->once()->with('/missing')->andReturnFalse();
        $filesystem->shouldNotReceive('isWritable');

        $service = $this->makeService(
            ['storage'],
            '/missing',
            $database,
            $cache,
            $filesystem,
        );

        self::assertFalse($service->isReady());
    }

    /**
     * An existing but read-only runtime directory is not ready.
     */
    public function test_returns_false_when_storage_directory_is_not_writable(): void
    {
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldNotReceive('connection');
        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldNotReceive('store');
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('isDirectory')->once()->with('/readonly')->andReturnTrue();
        $filesystem->shouldReceive('isWritable')->once()->with('/readonly')->andReturnFalse();

        $service = $this->makeService(
            ['storage'],
            '/readonly',
            $database,
            $cache,
            $filesystem,
        );

        self::assertFalse($service->isReady());
    }

    /**
     * Filesystem probe exceptions fail closed.
     */
    public function test_returns_false_when_storage_probe_throws(): void
    {
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldNotReceive('connection');
        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldNotReceive('store');
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('isDirectory')
            ->once()
            ->with('/runtime')
            ->andThrow(new \RuntimeException('secret path'));

        $service = $this->makeService(
            ['storage'],
            '/runtime',
            $database,
            $cache,
            $filesystem,
        );

        self::assertFalse($service->isReady());
    }

    /**
     * An unknown check name is a configuration error and fails closed.
     */
    public function test_returns_false_for_unknown_check_name(): void
    {
        $service = $this->makeServiceWithUnusedDependencies(['unknown_dependency'], '/runtime');

        self::assertFalse($service->isReady());
    }

    /**
     * An empty check list cannot silently disable readiness validation.
     */
    public function test_returns_false_for_empty_check_list(): void
    {
        $service = $this->makeServiceWithUnusedDependencies([], '/runtime');

        self::assertFalse($service->isReady());
    }

    /**
     * A non-array check configuration is rejected.
     */
    public function test_returns_false_for_malformed_check_configuration(): void
    {
        $service = $this->makeServiceWithUnusedDependencies('database,cache', '/runtime');

        self::assertFalse($service->isReady());
    }

    /**
     * Every configured entry must be a non-empty string.
     */
    public function test_returns_false_for_non_string_check_entry(): void
    {
        $service = $this->makeServiceWithUnusedDependencies(['database', 7], '/runtime');

        self::assertFalse($service->isReady());
    }

    /**
     * Empty check names are rejected rather than ignored.
     */
    public function test_returns_false_for_empty_check_name(): void
    {
        $service = $this->makeServiceWithUnusedDependencies([''], '/runtime');

        self::assertFalse($service->isReady());
    }

    /**
     * A non-string storage path is rejected without touching the filesystem.
     */
    public function test_returns_false_for_non_string_storage_path(): void
    {
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldNotReceive('connection');
        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldNotReceive('store');
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldNotReceive('isDirectory');

        $service = $this->makeService(
            ['storage'],
            null,
            $database,
            $cache,
            $filesystem,
        );

        self::assertFalse($service->isReady());
    }

    /**
     * An empty storage path is rejected without touching the filesystem.
     */
    public function test_returns_false_for_empty_storage_path(): void
    {
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldNotReceive('connection');
        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldNotReceive('store');
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldNotReceive('isDirectory');

        $service = $this->makeService(
            ['storage'],
            '',
            $database,
            $cache,
            $filesystem,
        );

        self::assertFalse($service->isReady());
    }

    /**
     * Build a service with explicit dependency doubles.
     *
     * @param mixed $checks Configured readiness check list
     * @param mixed $storagePath Configured writable runtime path
     */
    private function makeService(
        mixed $checks,
        mixed $storagePath,
        DatabaseManager $database,
        CacheManager $cache,
        Filesystem $filesystem,
    ): SystemReadinessService {
        $config = new ConfigRepository([
            'readiness' => [
                'checks' => $checks,
                'storage_path' => $storagePath,
            ],
        ]);

        return new SystemReadinessService($database, $cache, $filesystem, $config);
    }

    /**
     * Build a service whose infrastructure dependencies must remain untouched.
     *
     * @param mixed $checks Configured readiness check list
     * @param mixed $storagePath Configured writable runtime path
     */
    private function makeServiceWithUnusedDependencies(mixed $checks, mixed $storagePath): SystemReadinessService
    {
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldNotReceive('connection');
        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldNotReceive('store');
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldNotReceive('isDirectory');
        $filesystem->shouldNotReceive('isWritable');

        return $this->makeService($checks, $storagePath, $database, $cache, $filesystem);
    }
}
