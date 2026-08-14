<?php

namespace Tests\Feature\Operations;

use App\Services\SystemReadinessService;
use Mockery;
use Tests\TestCase;

/**
 * Traffic-readiness endpoint contract tests.
 */
class SystemReadinessControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * A healthy instance is admitted to traffic without authentication.
     */
    public function test_ready_instance_returns_minimal_uncached_success_response(): void
    {
        $service = Mockery::mock(SystemReadinessService::class);
        $service->shouldReceive('isReady')->once()->andReturnTrue();
        $this->app->instance(SystemReadinessService::class, $service);

        $response = $this->getJson('/ready');

        $response->assertOk()
            ->assertExactJson(['status' => 'ready'])
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertNoStoreCacheControl($response->headers->get('Cache-Control'));
    }

    /**
     * An unavailable dependency removes the instance from traffic without
     * disclosing dependency names or exception details.
     */
    public function test_unready_instance_returns_minimal_uncached_service_unavailable_response(): void
    {
        $service = Mockery::mock(SystemReadinessService::class);
        $service->shouldReceive('isReady')->once()->andReturnFalse();
        $this->app->instance(SystemReadinessService::class, $service);

        $response = $this->getJson('/ready');

        $response->assertStatus(503)
            ->assertExactJson(['status' => 'not_ready'])
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertNoStoreCacheControl($response->headers->get('Cache-Control'));
    }

    /**
     * Assert the response cannot be reused by an intermediary or browser cache.
     */
    private function assertNoStoreCacheControl(?string $cacheControl): void
    {
        self::assertNotNull($cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('max-age=0', $cacheControl);
    }
}
