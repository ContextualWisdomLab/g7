<?php

namespace Tests\Unit\Cache\Migration;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TemplateManager layout-cache TTL authority regression tests.
 */
class TemplateManagerCacheTtlContractTest extends TestCase
{
    /**
     * Layout cache warming must honor the central runtime setting with the legacy config as fallback.
     */
    #[Test]
    public function template_manager_uses_central_layout_cache_ttl_setting(): void
    {
        $source = file_get_contents(app_path('Extension/TemplateManager.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString(
            "g7_core_settings('cache.layout_ttl', config('template.layout.cache_ttl', 3600))",
            $source
        );
    }
}
