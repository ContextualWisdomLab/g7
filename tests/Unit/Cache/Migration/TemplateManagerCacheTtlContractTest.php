<?php

namespace Tests\Unit\Cache\Migration;

use App\Contracts\Extension\CacheInterface;
use App\Extension\Cache\CoreCacheDriver;
use App\Extension\TemplateManager;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Models\Template;
use App\Models\TemplateLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\Helpers\ProtectsExtensionDirectories;
use Tests\TestCase;

/**
 * TemplateManager layout-cache TTL authority regression tests.
 */
class TemplateManagerCacheTtlContractTest extends TestCase
{
    use ProtectsExtensionDirectories;
    use RefreshDatabase;

    private TemplateManager $templateManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestLayoutFiles();
        $this->setUpExtensionProtection();

        $this->templateManager = app(TemplateManager::class);
        $this->templateManager->loadTemplates();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        $this->tearDownExtensionProtection();
        $this->cleanupTestLayoutFiles();

        parent::tearDown();
    }

    /**
     * Return the same core cache namespace used by TemplateManager.
     */
    private function coreCache(): CacheInterface
    {
        return new CoreCacheDriver(config('cache.default', 'array'));
    }

    /**
     * Layout warming must expire on the central TTL, not the legacy fallback.
     */
    #[Test]
    public function template_manager_honors_central_layout_cache_ttl(): void
    {
        Config::set('template.layout.cache_ttl', 3600);
        Config::set('g7_settings.core.cache.layout_ttl', 1);

        $this->templateManager->installTemplate('sirsoft-admin_basic');
        $this->templateManager->activateTemplate('sirsoft-admin_basic');

        $template = Template::where('identifier', 'sirsoft-admin_basic')->first();
        $this->assertNotNull($template);

        $layoutName = TemplateLayout::where('template_id', $template->id)->value('name');
        $this->assertIsString($layoutName);

        $cacheVersion = ClearsTemplateCaches::getExtensionCacheVersion();
        $cacheKey = "layout.sirsoft-admin_basic.{$layoutName}.v{$cacheVersion}";

        $this->assertNotNull($this->coreCache()->get($cacheKey));

        $this->travel(2)->seconds();

        $this->assertNull($this->coreCache()->get($cacheKey));
    }
}
