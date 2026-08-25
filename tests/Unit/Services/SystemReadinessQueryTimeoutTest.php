<?php

namespace Tests\Unit\Services;

use App\Services\SystemReadinessService;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Guards the statement-execution budget of the public readiness database probe.
 */
class SystemReadinessQueryTimeoutTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * Both database paths must use a server-enforced one-second SELECT budget.
     */
    public function test_database_probe_bounds_query_execution_to_one_second(): void
    {
        $database = Mockery::mock(DatabaseManager::class);
        $connection = Mockery::mock(Connection::class);
        $database->shouldReceive('connection')->once()->andReturn($connection);

        $boundedProbe = 'SELECT /*+ MAX_EXECUTION_TIME(1000) */ 1 AS readiness_value';
        $connection->shouldReceive('selectOne')
            ->once()
            ->with($boundedProbe, [], false)
            ->andReturn((object) ['readiness_value' => 1]);
        $connection->shouldReceive('selectOne')
            ->once()
            ->with($boundedProbe, [], true)
            ->andReturn((object) ['readiness_value' => 1]);

        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldNotReceive('store');
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldNotReceive('isDirectory');
        $filesystem->shouldNotReceive('isWritable');

        $config = new ConfigRepository([
            'readiness' => [
                'checks' => ['database'],
                'database_query_timeout_ms' => 1000,
            ],
        ]);

        $service = new SystemReadinessService($database, $cache, $filesystem, $config);

        self::assertTrue($service->isReady());
    }
}
