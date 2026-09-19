<?php

namespace Tests\Feature\Operations;

use PDO;
use Tests\TestCase;

/**
 * Guards bounded connection behavior for public readiness dependency probes.
 */
class ReadinessDependencyConfigurationTest extends TestCase
{
    /**
     * Database and Redis connection attempts must fail before the two-second
     * Kubernetes readiness probe budget is exhausted.
     */
    public function test_dependency_connection_timeout_defaults_are_bounded_to_one_second(): void
    {
        $mysqlOptions = config('database.connections.mysql.options');

        self::assertIsArray($mysqlOptions);
        self::assertArrayHasKey(PDO::ATTR_TIMEOUT, $mysqlOptions);
        self::assertSame(1, $mysqlOptions[PDO::ATTR_TIMEOUT]);

        foreach (['default', 'cache'] as $connection) {
            self::assertSame(1.0, (float) config("database.redis.{$connection}.timeout"));
            self::assertSame(1.0, (float) config("database.redis.{$connection}.read_timeout"));
        }
    }
}
