<?php

namespace App\Extension;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\DeactivationReason;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutSourceType;
use App\Extension\Cache\CoreCacheDriver;
use App\Extension\Helpers\ExtensionBackupHelper;
use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\Helpers\ExtensionStatusGuard;
use App\Extension\Helpers\GithubHelper;
use App\Providers\CoreServiceProvider;
use App\Services\LayoutExtensionService;
use App\Services\LayoutService;
use App\Services\TemplateService;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 템플릿 관리자 클래스
 *
 * 템플릿의 로딩, 설치, 활성화, 비활성화, 제거 등을 담당합니다.
 */
class TemplateManager implements TemplateManagerInterface
{
    use Traits\CachesTemplateStatus;
    use Traits\ClearsTemplateCaches;
    use Traits\ComputesLayoutContentHash;
    use Traits\InspectsUninstallData;
    use Traits\InvalidatesLayoutCache;
    use Traits\ValidatesLayoutFiles;

    /** @var int install 프로그레스바 단계 수 */
    public const INSTALL_STEPS = 4;

    /** @var int update 프로그레스바 단계 수 */
    public const UPDATE_STEPS = 8;

    /** @var int uninstall 프로그레스바 단계 수 */
    public const UNINSTALL_STEPS = 3;

    protected array $templates = [];

    /**
     * _pending 디렉토리의 템플릿 메타데이터 배열
     *
     * @var array<string, array>
     */
    protected array $pendingTemplates = [];

    /**
     * _bundled 디렉토리의 템플릿 메타데이터 배열
     *
     * @var array<string, array>
     */
    protected array $bundledTemplates = [];

    protected string $templatesPath;

    protected string $pendingTemplatesPath;

    protected string $bundledTemplatesPath;

    public function __construct(
        protected ExtensionManager $extensionManager,
        protected TemplateRepositoryInterface $templateRepository,
        protected LayoutRepositoryInterface $layoutRepository,
        protected ModuleRepositoryInterface $moduleRepository,
        protected PluginRepositoryInterface $pluginRepository,
        protected LayoutExtensionService $layoutExtensionService
    ) {
        $this->templatesPath = base_path('templates');
        $this->pendingTemplatesPath = $this->templatesPath.DIRECTORY_SEPARATOR.'_pending';
        $this->bundledTemplatesPath = $this->templatesPath.DIRECTORY_SEPARATOR.'_bundled';
    }

    /**
     * 코어 캐시 드라이버를 반환합니다.
     *
     * 이 메서드가 다루는 키(`template.*`, `layout.*`)는 모두 코어 소유이므로
     * 항상 `g7:core:` 네임스페이스를 써야 한다. 컨테이너의 `CacheInterface`
     * 바인딩(모듈/플러그인 테스트가 일시적으로 `PluginCacheDriver` 등으로
     * 재바인딩할 수 있음)에 의존하면 누수된 바인딩 때문에 forget/remember 가
     * `g7:plugin.*` 네임스페이스로 빗나가므로, 항상 CoreCacheDriver 를 직접 생성한다.
     */
    private function cache(): CacheInterface
    {
        return new CoreCacheDriver(config('cache.default', 'array'));
    }

    /**
     * 모든 템플릿을 로드하고 초기화합니다.
     */
    public function loadTemplates(): void
    {
        // 기존 템플릿 캐시 초기화 (테스트 환경에서 재로드 지원)
        $this->templates = [];

        if (! File::exists($this->templatesPath)) {
            return;
        }

        $directories = File::directories($this->templatesPath);

        foreach ($directories as $directory) {
            $templateName = basename($directory);

            // _bundled, _pending 등 내부 디렉토리 건너뛰기
            if (str_starts_with($templateName, '_')) {
                continue;
            }

            $templateFile = $directory.'/template.json';

            // vendor-name 형식 검증
            if (! preg_match('/^[a-z0-9]+-[a-z0-9_]+$/i', $templateName)) {
                Log::warning("Invalid template directory name: {$templateName}. Expected format: vendor-name");

                continue;
            }

            // 무결성 검사: 활성 디렉토리는 있으나 template.json 누락 감지
            if (! File::exists($templateFile)) {
                Log::warning('템플릿 활성 디렉토리가 불완전합니다 (template.json 누락)', [
                    'template' => $templateName,
                    'directory' => $directory,
                    'hint' => "복구: php artisan template:install {$templateName} --force",
                ]);
            }

            if (File::exists($templateFile)) {
                try {
                    $jsonContent = File::get($templateFile);
                    $templateData = json_decode($jsonContent, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        Log::error("Failed to parse template.json in {$templateName}: ".json_last_error_msg());

                        continue;
                    }

                    // 필수 필드 검증
                    if (! $this->validateTemplateData($templateData, $templateName)) {
                        continue;
                    }

                    // 다국어 구조 확인 및 변환 (역호환성)
                    if (isset($templateData['name'])) {
                        $templateData['name'] = $this->convertToMultilingual($templateData['name']);
                    }
                    if (isset($templateData['description'])) {
                        $templateData['description'] = $this->convertToMultilingual($templateData['description']);
                    }

                    // 경로 정보 추가
                    $templateData['_paths'] = [
                        'root' => $directory,
                        'components_manifest' => $directory.'/components.json',
                        'routes' => $directory.'/routes.json',
                        'components_bundle' => $directory.'/dist/components.iife.js',
                        'assets' => $directory.'/assets',
                        'lang' => $directory.'/lang',
                        'layouts' => $directory.'/layouts',
                    ];

                    $this->templates[$templateName] = $templateData;
                } catch (\Exception $e) {
                    Log::error("Failed to load template {$templateName}: ".$e->getMessage());
                }
            }
        }

        // _pending 디렉토리 로드
        $this->loadPendingTemplates();

        // _bundled 디렉토리 로드
        $this->loadBundledTemplates();
    }

    /**
     * _pending 디렉토리의 템플릿 메타데이터를 로드합니다.
     *
     * 클래스 로드 없이 template.json 메타데이터만 읽어 저장합니다.
     * 이미 활성 디렉토리에 로드된 템플릿은 제외합니다.
     */
    protected function loadPendingTemplates(): void
    {
        $pending = ExtensionPendingHelper::loadPendingExtensions($this->templatesPath, 'template.json');

        foreach ($pending as $identifier => $metadata) {
            // 이미 활성 디렉토리 또는 pending에 로드된 템플릿은 제외
            if (isset($this->templates[$identifier])) {
                continue;
            }

            $this->pendingTemplates[$identifier] = $metadata;
        }
    }

    /**
     * _bundled 디렉토리의 템플릿 메타데이터를 로드합니다.
     *
     * 클래스 로드 없이 template.json 메타데이터만 읽어 저장합니다.
     * 이미 활성 디렉토리 또는 _pending에 로드된 템플릿은 제외합니다.
     */
    protected function loadBundledTemplates(): void
    {
        $bundled = ExtensionPendingHelper::loadBundledExtensions($this->templatesPath, 'template.json');

        foreach ($bundled as $identifier => $metadata) {
            // 이미 활성 디렉토리 또는 pending에 로드된 템플릿은 제외
            if (isset($this->templates[$identifier]) || isset($this->pendingTemplates[$identifier])) {
                continue;
            }

            $this->bundledTemplates[$identifier] = $metadata;
        }
    }

    /**
     * _pending 디렉토리의 템플릿 메타데이터를 반환합니다.
     *
     * @return array _pending 템플릿 메타데이터 배열
     */
    public function getPendingTemplates(): array
    {
        return $this->pendingTemplates;
    }

    /**
     * _bundled 디렉토리의 템플릿 메타데이터를 반환합니다.
     *
     * @return array _bundled 템플릿 메타데이터 배열
     */
    public function getBundledTemplates(): array
    {
        return $this->bundledTemplates;
    }

    /**
     * 문자열을 다국어 배열로 변환 (역호환성)
     *
     * @param  mixed  $value  변환할 값 (문자열 또는 배열)
     * @return array 다국어 배열
     */
    protected function convertToMultilingual($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $locales = config('app.translatable_locales', ['ko', 'en']);
            $result = [];
            foreach ($locales as $locale) {
                $result[$locale] = $value;
            }

            return $result;
        }

        $locales = config('app.translatable_locales', ['ko', 'en']);
        $result = [];
        foreach ($locales as $locale) {
            $result[$locale] = '';
        }

        return $result;
    }

    /**
     * 다국어 값 추출 헬퍼 메서드
     *
     * @param  mixed  $value  추출할 값 (문자열 또는 배열)
     * @param  string|null  $locale  로케일 (null이면 현재 로케일)
     * @return string 추출된 값
     */
    protected function getLocalizedValue($value, ?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            return $value[$locale]
                ?? $value[config('app.fallback_locale')]
                ?? (! empty($value) ? array_values($value)[0] : '')
                ?? '';
        }

        return '';
    }

    /**
     * 템플릿 데이터 유효성 검증
     *
     * @param  array  $data  템플릿 데이터
     * @param  string  $templateName  템플릿 디렉토리명
     * @return bool 유효성 검증 결과
     */
    protected function validateTemplateData(array $data, string $templateName): bool
    {
        $requiredFields = ['identifier', 'vendor', 'name', 'version', 'type'];

        foreach ($requiredFields as $field) {
            if (! isset($data[$field])) {
                Log::error("Missing required field '{$field}' in template.json for {$templateName}");

                return false;
            }
        }

        if (! in_array($data['type'], ['admin', 'user'])) {
            Log::error("Invalid type '{$data['type']}' in template.json for {$templateName}. Must be 'admin' or 'user'");

            return false;
        }

        return true;
    }

    protected function convertDirectoryToNamespace(string $directoryName): string
    {
        return ExtensionManager::directoryToNamespace($directoryName);
    }

    public function getActiveTemplate(string $type): ?array
    {
        $activeIdentifiers = self::getActiveTemplateIdentifiersByType($type);

        if (empty($activeIdentifiers)) {
            return null;
        }

        $identifier = $activeIdentifiers[0];

        return $this->templates[$identifier] ?? null;
    }

    public function installTemplate(string $templateName, ?\Closure $onProgress = null, bool $force = false): bool
    {
        ExtensionManager::validateIdentifierFormat($templateName);

        $existingRecord = $this->templateRepository->findByIdentifier($templateName);
        if ($existingRecord) {
            ExtensionStatusGuard::assertNotInProgress(
                ExtensionStatus::from($existingRecord->status),
                $templateName
            );
        }

        $onProgress?->__invoke('copy', '파일 복사 중...');
        if ($force || ! isset($this->templates[$templateName])) {
            $this->copyToActiveFromSource($templateName, $onProgress, $force);
        }

        $onProgress?->__invoke('validate', '검증 중...');

        return DB::transaction(function () use ($templateName, $onProgress) {
            $template = $this->getTemplate($templateName);
            if (! $template) {
                throw new \Exception(__('templates.errors.not_found', ['template' => $templateName]));
            }

            $this->checkDependencies($template);
            $this->validateSeoConfig($templateName);
            $this->validateLayouts($templateName);

            $name = $this->convertToMultilingual($template['name']);
            $description = $this->convertToMultilingual($template['description'] ?? '');

            $manifest = HookManager::applyFilters(
                "template.{$templateName}.manifest.translations",
                ['name' => $name, 'description' => $description]
            );
            $name = $manifest['name'] ?? $name;
            $description = $manifest['description'] ?? $description;

            $onProgress?->__invoke('db', 'DB 등록 중...');

            $templateRecord = $this->templateRepository->updateOrCreate(
                ['identifier' => $templateName],
                [
                    'vendor' => $template['vendor'],
                    'name' => $name,
                    'version' => $template['version'],
                    'type' => $template['type'],
                    'description' => $description,
                    'github_url' => $template['github_url'] ?? null,
                    'metadata' => $template['metadata'] ?? null,
                    'status' => ExtensionStatus::Inactive->value,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]
            );

            $onProgress?->__invoke('layout', '레이아웃 등록 중...');
            $this->registerLayouts($templateName, $templateRecord->id);
            $this->registerLayoutOverrides($templateName, $templateRecord->id);
            $this->registerExtensionOverrides($templateName, $templateRecord->id);
            $this->validateErrorLayouts($templateName, $template);
            self::invalidateTemplateStatusCache();
            $this->incrementExtensionCacheVersion();
            HookManager::doAction('core.templates.installed', $templateName);

            return true;
        });
    }

    public function activateTemplate(string $templateName, bool $force = false): array
    {
        $template = $this->getTemplate($templateName);
        if (! $template) {
            throw new \Exception(__('templates.errors.not_found', ['template' => $templateName]));
        }

        $templateRecord = $this->templateRepository->findByIdentifier($templateName);
        if (! $templateRecord) {
            throw new \Exception(__('templates.errors.not_installed', ['template' => $templateName]));
        }

        if ($templateRecord->status === ExtensionStatus::Active->value) {
            throw new \Exception(__('templates.errors.already_active'));
        }

        if (! $force && ! CoreServiceProvider::isCoreUpdateInProgress()) {
            CoreVersionChecker::validateExtension(
                $template['g7_version'] ?? null,
                $templateName,
                'template'
            );
        }

        $missingModules = [];
        $missingPlugins = [];
        $requiredModules = $template['dependencies']['modules'] ?? [];
        foreach ($requiredModules as $requiredModuleIdentifier => $_versionConstraint) {
            $requiredModule = $this->moduleRepository->findByIdentifier($requiredModuleIdentifier);
            if (! $requiredModule) {
                $missingModules[] = [
                    'identifier' => $requiredModuleIdentifier,
                    'name' => $requiredModuleIdentifier,
                    'status' => 'not_installed',
                ];
            } elseif ($requiredModule->status !== ExtensionStatus::Active->value) {
                $missingModules[] = [
                    'identifier' => $requiredModule->identifier,
                    'name' => $requiredModule->getLocalizedName(),
                    'status' => 'inactive',
                ];
            }
        }

        $requiredPlugins = $template['dependencies']['plugins'] ?? [];
        foreach ($requiredPlugins as $requiredPluginIdentifier => $_versionConstraint) {
            $requiredPlugin = $this->pluginRepository->findByIdentifier($requiredPluginIdentifier);
            if (! $requiredPlugin) {
                $missingPlugins[] = [
                    'identifier' => $requiredPluginIdentifier,
                    'name' => $requiredPluginIdentifier,
                    'status' => 'not_installed',
                ];
            } elseif ($requiredPlugin->status !== ExtensionStatus::Active->value) {
                $missingPlugins[] = [
                    'identifier' => $requiredPlugin->identifier,
                    'name' => $requiredPlugin->getLocalizedName(),
                    'status' => 'inactive',
                ];
            }
        }

        $hasMissingDependencies = ! empty($missingModules) || ! empty($missingPlugins);

        if ($hasMissingDependencies && ! $force) {
            return [
                'success' => false,
                'warning' => true,
                'missing_modules' => $missingModules,
                'missing_plugins' => $missingPlugins,
                'message' => __('templates.warnings.missing_dependencies'),
            ];
        }

        DB::transaction(function () use ($templateName, $template, $templateRecord) {
            $this->deactivateTemplatesByType($template['type']);
            $this->templateRepository->updateByIdentifier($templateName, [
                'status' => ExtensionStatus::Active->value,
                'deactivated_reason' => null,
                'deactivated_at' => null,
                'incompatible_required_version' => null,
                'updated_at' => now(),
            ]);
            $this->layoutExtensionService->registerAllActiveExtensionsToTemplate($templateRecord->id);
            app(ModuleManager::class)->registerLayoutsForAllActiveModules();
            app(PluginManager::class)->registerLayoutsForAllActivePlugins();
            $this->incrementExtensionCacheVersion();
            $this->warmTemplateCache($templateName);
            self::invalidateTemplateStatusCache();

            Log::info(__('templates.messages.template_activated'), [
                'template' => $templateName,
                'type' => $template['type'],
            ]);
        });

        HookManager::doAction('core.templates.activated', $templateName);

        return ['success' => true];
    }

    protected function deactivateTemplatesByType(string $type): void
    {
        $activeTemplates = $this->templateRepository->getActiveByType($type);

        foreach ($activeTemplates as $templateRecord) {
            $this->clearTemplateCache($templateRecord->identifier);
            $this->templateRepository->updateByIdentifier($templateRecord->identifier, [
                'status' => ExtensionStatus::Inactive->value,
                'updated_at' => now(),
            ]);
        }
    }

    public function deactivateTemplate(
        string $templateName,
        string $reason = DeactivationReason::Manual->value,
        ?string $incompatibleRequiredVersion = null,
    ): bool {
        $template = $this->getTemplate($templateName);
        if (! $template) {
            return false;
        }

        $existingRecord = $this->templateRepository->findByIdentifier($templateName);
        if ($existingRecord) {
            ExtensionStatusGuard::assertNotInProgress(
                ExtensionStatus::from($existingRecord->status),
                $templateName
            );
        }

        $this->clearTemplateCache($templateName);
        $this->templateRepository->updateByIdentifier($templateName, [
            'status' => ExtensionStatus::Inactive->value,
            'deactivated_reason' => $reason,
            'deactivated_at' => now(),
            'incompatible_required_version' => $incompatibleRequiredVersion,
            'updated_at' => now(),
        ]);
        self::invalidateTemplateStatusCache();
        $this->incrementExtensionCacheVersion();
        HookManager::doAction('core.templates.after_deactivate', $templateName);

        Log::info(__('templates.messages.template_deactivated'), [
            'template' => $templateName,
        ]);

        return true;
    }

    public function uninstallTemplate(string $templateName, ?\Closure $onProgress = null): bool
    {
        $onProgress?->__invoke('cache', '캐시 삭제 중...');

        $result = DB::transaction(function () use ($templateName, $onProgress) {
            $template = $this->getTemplate($templateName);
            if (! $template) {
                throw new \Exception(__('templates.errors.not_found', ['template' => $templateName]));
            }

            $this->clearTemplateCache($templateName);
            $onProgress?->__invoke('db', 'DB 삭제 중...');

            $templateRecord = $this->templateRepository->findByIdentifier($templateName);
            if ($templateRecord) {
                $this->unregisterLayouts($templateRecord->id);
            }

            $this->unregisterExtensionOverrides($templateName);
            $this->templateRepository->deleteByIdentifier($templateName);
            self::invalidateTemplateStatusCache();
            $this->incrementExtensionCacheVersion();

            Log::info(__('templates.messages.template_uninstalled'), [
                'template' => $templateName,
            ]);

            return true;
        });

        if ($result) {
            $onProgress?->__invoke('files', '파일 삭제 중...');
            ExtensionPendingHelper::deleteExtensionDirectory($this->templatesPath, $templateName);
            unset($this->templates[$templateName]);
        }

        return $result;
    }

    public function getTemplateUninstallInfo(string $templateName): ?array
    {
        $template = $this->getTemplate($templateName);
        if (! $template) {
            return null;
        }

        $extensionDirInfo = $this->getExtensionDirectoryInfo('templates', $templateName);

        return [
            'extension_directory' => $extensionDirInfo,
        ];
    }

    public function getTemplate(string $templateName): ?array
    {
        return $this->templates[$templateName] ?? null;
    }

    public function getAllTemplates(): array
    {
        return $this->templates;
    }

    public function getUninstalledTemplates(): array
    {
        $uninstalledTemplates = [];
        $installedTemplateIdentifiers = self::getInstalledTemplateIdentifiers();
        $locale = app()->getLocale();

        foreach ($this->templates as $identifier => $template) {
            if (! in_array($identifier, $installedTemplateIdentifiers)) {
                $uninstalledTemplates[$identifier] = [
                    'identifier' => $template['identifier'],
                    'vendor' => $template['vendor'],
                    'name' => $this->getLocalizedValue($template['name'], $locale),
                    'version' => $template['version'],
                    'type' => $template['type'],
                    'description' => $this->getLocalizedValue($template['description'] ?? '', $locale),
                    'dependencies' => $template['dependencies'] ?? [],
                    'status' => 'uninstalled',
                    'source' => 'active',
                    'hidden' => (bool) ($template['hidden'] ?? false),
                ];
            }
        }

        foreach ($this->pendingTemplates as $identifier => $metadata) {
            if (! in_array($identifier, $installedTemplateIdentifiers) && ! isset($uninstalledTemplates[$identifier])) {
                $name = $this->convertToMultilingual($metadata['name'] ?? $identifier);
                $description = $this->convertToMultilingual($metadata['description'] ?? '');
                $uninstalledTemplates[$identifier] = [
                    'identifier' => $identifier,
                    'vendor' => $metadata['vendor'] ?? '',
                    'name' => $this->getLocalizedValue($name, $locale),
                    'version' => $metadata['version'] ?? '0.0.0',
                    'type' => $metadata['type'] ?? 'admin',
                    'description' => $this->getLocalizedValue($description, $locale),
                    'dependencies' => $metadata['dependencies'] ?? [],
                    'status' => 'uninstalled',
                    'source' => 'pending',
                    'hidden' => (bool) ($metadata['hidden'] ?? false),
                ];
            }
        }

        foreach ($this->bundledTemplates as $identifier => $metadata) {
            if (! in_array($identifier, $installedTemplateIdentifiers) && ! isset($uninstalledTemplates[$identifier])) {
                $name = $this->convertToMultilingual($metadata['name'] ?? $identifier);
                $description = $this->convertToMultilingual($metadata['description'] ?? '');
                $uninstalledTemplates[$identifier] = [
                    'identifier' => $identifier,
                    'vendor' => $metadata['vendor'] ?? '',
                    'name' => $this->getLocalizedValue($name, $locale),
                    'version' => $metadata['version'] ?? '0.0.0',
                    'type' => $metadata['type'] ?? 'admin',
                    'description' => $this->getLocalizedValue($description, $locale),
                    'dependencies' => $metadata['dependencies'] ?? [],
                    'status' => 'uninstalled',
                    'source' => 'bundled',
                    'hidden' => (bool) ($metadata['hidden'] ?? false),
                ];
            }
        }

        return $uninstalledTemplates;
    }

    public function getInstalledTemplatesWithDetails(): array
    {
        $installedTemplates = [];
        $templateRecords = $this->templateRepository->getAllKeyedByIdentifier();
        $locale = app()->getLocale();

        foreach ($this->templates as $identifier => $template) {
            if ($templateRecords->has($identifier)) {
                $record = $templateRecords->get($identifier);
                $fileVersion = $template['version'];
                $updateAvailable = $record->update_available ?? false;
                $latestVersion = $record->latest_version ?? null;

                if ($updateAvailable && $latestVersion === null) {
                    $bundledVersion = $this->bundledTemplates[$identifier]['version'] ?? null;
                    if ($bundledVersion === null) {
                        $bundledMeta = ExtensionPendingHelper::loadBundledExtensions($this->templatesPath, 'template.json');
                        $bundledVersion = $bundledMeta[$identifier]['version'] ?? null;
                    }
                    $latestVersion = $bundledVersion ?? $fileVersion;
                }

                $installedTemplates[$identifier] = [
                    'identifier' => $template['identifier'],
                    'vendor' => $template['vendor'],
                    'name' => $this->getLocalizedValue($template['name'], $locale),
                    'version' => $record->version,
                    'type' => $template['type'],
                    'description' => $this->getLocalizedValue($template['description'] ?? '', $locale),
                    'dependencies' => $template['dependencies'] ?? [],
                    'status' => $record->status,
                    'update_available' => $updateAvailable,
                    'latest_version' => $latestVersion,
                    'file_version' => $fileVersion,
                    'update_source' => $record->update_source ?? null,
                    'github_url' => $template['github_url'] ?? ($record->github_url ?? null),
                    'github_changelog_url' => $record->github_changelog_url ?? ($template['github_changelog_url'] ?? null),
                    'hidden' => (bool) ($template['hidden'] ?? false),
                    'user_modified_at' => $record->user_modified_at,
                    'created_at' => $record->created_at,
                    'updated_at' => $record->updated_at,
                ];
            }
        }

        return $installedTemplates;
    }

    public function getTemplateInfo(string $templateName): ?array
    {
        $template = $this->getTemplate($templateName);

        if (! $template) {
            return $this->getTemplateInfoFromMetadata($templateName);
        }

        $templateRecord = $this->templateRepository->findByIdentifier($templateName);
        $locale = app()->getLocale();
        $layoutsCount = 0;
        if ($templateRecord) {
            $layoutsCount = $this->layoutRepository->countByTemplateId($templateRecord->id);
        }

        $components = $this->getTemplateComponents($templateName);
        $nameJson = $templateRecord?->name ?: $template['name'];
        $descriptionJson = $templateRecord?->description ?: ($template['description'] ?? '');

        return [
            'identifier' => $template['identifier'],
            'vendor' => $template['vendor'],
            'name' => $this->getLocalizedValue($nameJson, $locale),
            'version' => $template['version'],
            'latest_version' => $template['latest_version'] ?? null,
            'update_available' => $template['update_available'] ?? false,
            'type' => $template['type'],
            'description' => $this->getLocalizedValue($descriptionJson, $locale),
            'github_url' => $template['github_url'] ?? null,
            'github_changelog_url' => $template['github_changelog_url'] ?? null,
            'requires_core' => $template['g7_version'] ?? null,
            'dependencies' => $template['dependencies'] ?? [],
            'externals' => $template['externals'] ?? [],
            'locales' => $template['locales'] ?? [],
            'layouts_count' => $layoutsCount,
            'components' => $components,
            'license' => $template['license'] ?? null,
            'metadata' => $template['metadata'] ?? [],
            'status' => $templateRecord ? $templateRecord->status : 'not_installed',
            'is_installed' => (bool) $templateRecord,
            'user_modified_at' => $templateRecord?->user_modified_at,
            'created_at' => $templateRecord?->created_at,
            'updated_at' => $templateRecord?->updated_at,
        ];
    }

    protected function getTemplateInfoFromMetadata(string $templateName): ?array
    {
        $metadata = $this->pendingTemplates[$templateName] ?? $this->bundledTemplates[$templateName] ?? null;

        if (! $metadata) {
            return null;
        }

        $locale = app()->getLocale();
        $name = $this->convertToMultilingual($metadata['name'] ?? $templateName);
        $description = $this->convertToMultilingual($metadata['description'] ?? '');

        return [
            'identifier' => $metadata['identifier'] ?? $templateName,
            'vendor' => $metadata['vendor'] ?? '',
            'name' => $this->getLocalizedValue($name, $locale),
            'version' => $metadata['version'] ?? '0.0.0',
            'latest_version' => null,
            'update_available' => false,
            'type' => $metadata['type'] ?? 'admin',
            'description' => $this->getLocalizedValue($description, $locale),
            'github_url' => $metadata['github_url'] ?? null,
            'github_changelog_url' => $metadata['github_changelog_url'] ?? null,
            'requires_core' => $metadata['g7_version'] ?? null,
            'dependencies' => $metadata['dependencies'] ?? [],
            'externals' => $metadata['externals'] ?? [],
            'locales' => $metadata['locales'] ?? [],
            'layouts_count' => 0,
            'components' => $metadata['components'] ?? [],
            'license' => $metadata['license'] ?? null,
            'metadata' => $metadata,
            'status' => 'not_installed',
            'is_installed' => false,
            'user_modified_at' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    protected function validateSeoConfig(string $templateId): bool
    {
        $path = base_path("templates/{$templateId}/seo-config.json");

        if (! File::exists($path)) {
            Log::info("[Template] seo-config.json not found for {$templateId} — SEO rendering will use div fallback");

            return true;
        }

        $content = File::get($path);
        $config = json_decode($content, true);
        if (! is_array($config)) {
            throw new \Exception("seo-config.json is invalid JSON for template '{$templateId}'");
        }

        if (isset($config['component_map'])) {
            foreach ($config['component_map'] as $name => $entry) {
                if (! is_array($entry)) {
                    throw new \Exception("seo-config.json: component_map.{$name} must be an object");
                }

                if (! array_key_exists('tag', $entry) || ! is_string($entry['tag'])) {
                    throw new \Exception("seo-config.json: component_map.{$name}.tag is required and must be a string");
                }

                if (isset($entry['render'])) {
                    $renderModeName = $entry['render'];
                    if (! isset($config['render_modes'][$renderModeName])) {
                        throw new \Exception("seo-config.json: component_map.{$name}.render references undefined mode '{$renderModeName}'");
                    }
                }
            }
        }

        if (isset($config['render_modes'])) {
            $validTypes = ['iterate', 'format', 'raw', 'fields', 'pagination'];
            foreach ($config['render_modes'] as $modeName => $modeConfig) {
                if (! is_array($modeConfig)) {
                    throw new \Exception("seo-config.json: render_modes.{$modeName} must be an object");
                }

                $type = $modeConfig['type'] ?? null;
                if (! $type || ! in_array($type, $validTypes, true)) {
                    throw new \Exception("seo-config.json: render_modes.{$modeName}.type must be one of: ".implode(', ', $validTypes));
                }
            }
        }

        if (isset($config['stylesheets']) && ! is_array($config['stylesheets'])) {
            throw new \Exception('seo-config.json: stylesheets must be an array');
        }

        if (isset($config['self_closing']) && ! is_array($config['self_closing'])) {
            throw new \Exception('seo-config.json: self_closing must be an array');
        }

        if (isset($config['seo_overrides'])) {
            if (! is_array($config['seo_overrides'])) {
                throw new \Exception('seo-config.json: seo_overrides must be an object');
            }
            $allowedScopes = ['_local', '_global'];
            foreach (array_keys($config['seo_overrides']) as $scope) {
                if (! in_array($scope, $allowedScopes, true)) {
                    throw new \Exception("seo-config.json: seo_overrides.{$scope} is not allowed (use _local or _global)");
                }
                if (! is_array($config['seo_overrides'][$scope])) {
                    throw new \Exception("seo-config.json: seo_overrides.{$scope} must be an object");
                }
            }
        }

        return true;
    }

    protected function validateErrorLayouts(string $templateId, array $templateData): bool
    {
        $templatePath = base_path("templates/{$templateId}");

        if (! File::exists($templatePath)) {
            Log::debug('에러 레이아웃 검증 건너뛰기: 템플릿 디렉토리가 존재하지 않음', [
                'template' => $templateId,
            ]);

            return true;
        }

        if (! isset($templateData['error_config']['layouts'])) {
            throw new \Exception(__('templates.errors.missing_error_config'));
        }

        $errorLayouts = $templateData['error_config']['layouts'];
        $requiredErrorCodes = [404, 403, 500];

        foreach ($requiredErrorCodes as $code) {
            if (! isset($errorLayouts[$code]) && ! isset($errorLayouts[(string) $code])) {
                throw new \Exception(__('templates.errors.missing_error_layout', ['code' => $code]));
            }

            $layoutName = $errorLayouts[$code] ?? $errorLayouts[(string) $code];
            $layoutFilePath = $templatePath.'/layouts/'.$layoutName.'.json';
            if (! File::exists($layoutFilePath)) {
                throw new \Exception(__('templates.errors.error_layout_not_found', [
                    'code' => $code,
                    'path' => $layoutName,
                ]));
            }
        }

        return true;
    }

    protected function checkDependencies(array $template): void
    {
        $g7Version = $template['g7_version'] ?? null;
        CoreVersionChecker::validateExtension($g7Version, $template['identifier'], 'template');

        $dependencies = $template['dependencies'] ?? [];
        $unmetDependencies = [];

        if (isset($dependencies['modules']) && ! empty($dependencies['modules'])) {
            foreach ($dependencies['modules'] as $moduleName => $versionConstraint) {
                $module = $this->moduleRepository->findActiveByIdentifier($moduleName);
                if (! $module) {
                    $unmetDependencies[] = __('templates.errors.dependency_not_met', [
                        'dependency' => $moduleName,
                        'type' => 'module',
                    ]);

                    continue;
                }

                if (! $this->checkVersionConstraint($module->version, $versionConstraint)) {
                    $unmetDependencies[] = __('templates.errors.version_mismatch', [
                        'dependency' => $moduleName,
                        'required' => $versionConstraint,
                        'installed' => $module->version,
                    ]);
                }
            }
        }

        if (isset($dependencies['plugins']) && ! empty($dependencies['plugins'])) {
            foreach ($dependencies['plugins'] as $pluginName => $versionConstraint) {
                $plugin = $this->pluginRepository->findActiveByIdentifier($pluginName);
                if (! $plugin) {
                    $unmetDependencies[] = __('templates.errors.dependency_not_met', [
                        'dependency' => $pluginName,
                        'type' => 'plugin',
                    ]);

                    continue;
                }

                if (! $this->checkVersionConstraint($plugin->version, $versionConstraint)) {
                    $unmetDependencies[] = __('templates.errors.version_mismatch', [
                        'dependency' => $pluginName,
                        'required' => $versionConstraint,
                        'installed' => $plugin->version,
                    ]);
                }
            }
        }

        if (! empty($unmetDependencies)) {
            throw new \Exception(implode("\n", $unmetDependencies));
        }
    }

    protected function checkVersionConstraint(string $installedVersion, string $versionConstraint): bool
    {
        try {
            return Semver::satisfies($installedVersion, $versionConstraint);
        } catch (\Exception $e) {
            Log::warning('Version constraint check failed', [
                'installed_version' => $installedVersion,
                'constraint' => $versionConstraint,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function scanTemplates(): array
    {
        if (! File::exists($this->templatesPath)) {
            return [];
        }

        $scannedTemplates = [];
        $directories = File::directories($this->templatesPath);

        foreach ($directories as $directory) {
            $templateName = basename($directory);

            if (str_starts_with($templateName, '_')) {
                continue;
            }

            if (! preg_match('/^[a-z0-9]+-[a-z0-9_]+$/i', $templateName)) {
                Log::warning("Invalid template directory name: {$templateName}. Expected format: vendor-name");

                continue;
            }

            $templateFile = $directory.'/template.json';
            if (! File::exists($templateFile)) {
                Log::warning("template.json not found in: {$directory}");

                continue;
            }

            $scannedTemplates[$templateName] = [
                'path' => $directory,
                'identifier' => $templateName,
            ];
        }

        return $scannedTemplates;
    }

    public function validateTemplate(string $identifier): bool
    {
        $template = $this->getTemplate($identifier);

        if (! $template) {
            throw new \Exception(__('templates.errors.not_found', ['template' => $identifier]));
        }

        $this->checkDependencies($template);

        return true;
    }

    public function getTemplatesByType(string $type): array
    {
        $templatesByType = [];

        foreach ($this->templates as $identifier => $template) {
            $templateRecord = $this->templateRepository->findByIdentifier($identifier);

            if ($templateRecord && $templateRecord->type === $type) {
                $templatesByType[$identifier] = $template;
            }
        }

        return $templatesByType;
    }

    protected function validateLayouts(string $templateName): array
    {
        $layoutsPath = base_path("templates/{$templateName}/layouts");

        return $this->validateLayoutFiles($layoutsPath, $templateName, 'template', true);
    }

    protected function registerLayouts(string $templateName, int $templateId): void
    {
        $layoutsPath = base_path("templates/{$templateName}/layouts");

        if (! File::exists($layoutsPath)) {
            Log::info(__('templates.info.no_layouts_directory'), ['template' => $templateName]);

            return;
        }

        try {
            $validatedLayouts = $this->validateLayoutFiles($layoutsPath, $templateName, 'template', true);
        } catch (\Exception $e) {
            Log::error(__('templates.errors.layout_registration_failed'), [
                'template' => $templateName,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (empty($validatedLayouts)) {
            Log::info(__('templates.info.no_layout_files'), ['template' => $templateName]);

            return;
        }

        foreach ($validatedLayouts as $validatedLayout) {
            try {
                $layoutFile = $validatedLayout['file'];
                $layoutData = $validatedLayout['data'];
                $layoutName = $validatedLayout['layout_name'];

                $this->layoutRepository->updateOrCreate(
                    [
                        'template_id' => $templateId,
                        'name' => $layoutName,
                    ],
                    [
                        'content' => $layoutData,
                        'original_content_hash' => $this->computeContentHash($layoutData),
                        'original_content_size' => $this->computeContentSize($layoutData),
                    ]
                );

                Log::info(__('templates.info.layout_registered'), [
                    'layout' => $layoutName,
                    'template' => $templateName,
                    'file' => basename($layoutFile),
                ]);
            } catch (\Exception $e) {
                Log::error(__('templates.errors.layout_registration_failed'), [
                    'file' => $validatedLayout['file'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function unregisterLayouts(int $templateId): void
    {
        $deletedCount = $this->layoutRepository->deleteByTemplateId($templateId);

        if ($deletedCount > 0) {
            Log::info(__('templates.info.layouts_deleted'), [
                'count' => $deletedCount,
                'template_id' => $templateId,
            ]);
        }
    }

    protected function registerLayoutOverrides(string $templateName, int $templateId): void
    {
        $overridesPath = base_path("templates/{$templateName}/layouts/overrides");

        if (! File::exists($overridesPath)) {
            Log::info(__('templates.info.no_overrides_directory'), ['template' => $templateName]);
            $this->cleanupOrphanOverrideLayouts($templateId, $templateName, []);

            return;
        }

        $moduleDirectories = File::directories($overridesPath);

        if (empty($moduleDirectories)) {
            Log::info(__('templates.info.no_override_modules'), ['template' => $templateName]);
            $this->cleanupOrphanOverrideLayouts($templateId, $templateName, []);

            return;
        }

        $registeredCount = 0;
        $registeredLayoutNames = [];

        foreach ($moduleDirectories as $moduleDirectory) {
            $moduleIdentifier = basename($moduleDirectory);

            try {
                $validatedLayouts = $this->validateLayoutFiles($moduleDirectory, $moduleIdentifier, 'template', true);
            } catch (\Exception $e) {
                Log::error(__('templates.errors.override_layout_registration_failed'), [
                    'template' => $templateName,
                    'module' => $moduleIdentifier,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (empty($validatedLayouts)) {
                Log::info(__('templates.info.no_override_layouts_for_module'), [
                    'template' => $templateName,
                    'module' => $moduleIdentifier,
                ]);

                continue;
            }

            foreach ($validatedLayouts as $validatedLayout) {
                try {
                    $layoutFile = $validatedLayout['file'];
                    $layoutData = $validatedLayout['data'];
                    $layoutName = $validatedLayout['layout_name'];
                    $content = $this->extractLayoutContent($layoutData);

                    $this->layoutRepository->updateOrCreate(
                        [
                            'template_id' => $templateId,
                            'name' => $layoutName,
                        ],
                        [
                            'content' => $content,
                            'extends' => $layoutData['extends'] ?? null,
                            'source_type' => LayoutSourceType::Template,
                            'source_identifier' => $templateName,
                            'created_by' => Auth::id(),
                            'updated_by' => Auth::id(),
                        ]
                    );

                    $registeredCount++;
                    $registeredLayoutNames[] = $layoutName;

                    Log::info(__('templates.info.override_layout_registered'), [
                        'layout' => $layoutName,
                        'template' => $templateName,
                        'module' => $moduleIdentifier,
                        'file' => basename($layoutFile),
                    ]);
                } catch (\Exception $e) {
                    Log::error(__('templates.errors.override_layout_registration_failed'), [
                        'file' => $validatedLayout['file'],
                        'template' => $templateName,
                        'module' => $moduleIdentifier,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->cleanupOrphanOverrideLayouts($templateId, $templateName, $registeredLayoutNames);

        if ($registeredCount > 0) {
            $this->invalidateOverrideLayoutCaches($templateId);

            Log::info(__('templates.info.override_layouts_registered'), [
                'template' => $templateName,
                'count' => $registeredCount,
            ]);
        }
    }

    protected function unregisterExtensionOverrides(string $templateIdentifier): void
    {
        $deletedCount = $this->layoutExtensionService->unregisterBySource(
            LayoutSourceType::Template,
            $templateIdentifier
        );

        if ($deletedCount > 0) {
            Log::info('템플릿 Extension 오버라이드 제거 완료', [
                'template' => $templateIdentifier,
                'count' => $deletedCount,
            ]);
        }
    }

    public function checkDependenciesStatus(string $identifier): array
    {
        $template = $this->getTemplate($identifier);

        if (! $template) {
            return [
                'met' => false,
                'modules' => [],
                'plugins' => [],
                'error' => __('templates.errors.not_found', ['template' => $identifier]),
            ];
        }

        $dependencies = $template['dependencies'] ?? [];
        $moduleDependencies = $dependencies['modules'] ?? [];
        $pluginDependencies = $dependencies['plugins'] ?? [];

        $moduleStatuses = [];
        $pluginStatuses = [];
        $allMet = true;

        foreach ($moduleDependencies as $moduleName => $versionConstraint) {
            $module = $this->moduleRepository->findByIdentifier($moduleName);
            $activeModule = $module && $module->status === ExtensionStatus::Active->value;

            $status = [
                'identifier' => $moduleName,
                'name' => $module ? $module->getLocalizedName() : $moduleName,
                'required_version' => $versionConstraint,
                'installed_version' => $module?->version,
                'is_active' => $activeModule,
                'version_met' => false,
                'met' => false,
            ];

            if ($activeModule) {
                $status['version_met'] = $this->checkVersionConstraint($module->version, $versionConstraint);
                $status['met'] = $status['version_met'];
            }

            if (! $status['met']) {
                $allMet = false;
            }

            $moduleStatuses[] = $status;
        }

        foreach ($pluginDependencies as $pluginName => $versionConstraint) {
            $plugin = $this->pluginRepository->findByIdentifier($pluginName);
            $activePlugin = $plugin && $plugin->status === ExtensionStatus::Active->value;

            $status = [
                'identifier' => $pluginName,
                'name' => $plugin ? $plugin->getLocalizedName() : $pluginName,
                'required_version' => $versionConstraint,
                'installed_version' => $plugin?->version,
                'is_active' => $activePlugin,
                'version_met' => false,
                'met' => false,
            ];

            if ($activePlugin) {
                $status['version_met'] = $this->checkVersionConstraint($plugin->version, $versionConstraint);
                $status['met'] = $status['version_met'];
            }

            if (! $status['met']) {
                $allMet = false;
            }

            $pluginStatuses[] = $status;
        }

        return [
            'met' => $allMet,
            'modules' => $moduleStatuses,
            'plugins' => $pluginStatuses,
        ];
    }

    public function getUnmetDependencies(string $identifier): array
    {
        $status = $this->checkDependenciesStatus($identifier);

        if (isset($status['error'])) {
            return [
                'modules' => [],
                'plugins' => [],
            ];
        }

        $unmetModules = array_filter($status['modules'], function ($module) {
            return ! $module['met'];
        });
        $unmetPlugins = array_filter($status['plugins'], function ($plugin) {
            return ! $plugin['met'];
        });

        return [
            'modules' => array_values($unmetModules),
            'plugins' => array_values($unmetPlugins),
        ];
    }

    public function getTemplatesDependingOnModule(string $moduleIdentifier): array
    {
        $dependentTemplates = $this->templateRepository->findActiveByModuleDependency($moduleIdentifier);

        return $dependentTemplates->pluck('identifier')->toArray();
    }

    public function getTemplatesDependingOnPlugin(string $pluginIdentifier): array
    {
        $dependentTemplates = $this->templateRepository->findActiveByPluginDependency($pluginIdentifier);

        return $dependentTemplates->pluck('identifier')->toArray();
    }

    protected function fetchLatestVersion(string $githubUrl): ?string
    {
        if (! $githubUrl) {
            return null;
        }

        try {
            [$owner, $repo] = GithubHelper::parseUrl($githubUrl);
        } catch (\RuntimeException $e) {
            return null;
        }

        try {
            $token = (string) (config('app.update.github_token') ?? '');
            $result = GithubHelper::fetchLatestRelease($owner, $repo, $token);

            return $result['version'];
        } catch (\Exception $e) {
            Log::error('최신 버전 확인 중 오류 발생', [
                'github_url' => $githubUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function buildChangelogUrl(?string $githubUrl): ?string
    {
        if (! $githubUrl) {
            return null;
        }

        $githubUrl = rtrim($githubUrl, '/');
        $githubUrl = preg_replace('/\.git$/', '', $githubUrl);

        return $githubUrl.'/releases';
    }

    protected function copyToActiveFromSource(string $templateName, ?\Closure $onProgress = null, bool $force = false): void
    {
        $targetPath = $this->templatesPath.DIRECTORY_SEPARATOR.$templateName;

        if (isset($this->pendingTemplates[$templateName]) || ExtensionPendingHelper::isPending($this->templatesPath, $templateName)) {
            $sourcePath = ExtensionPendingHelper::getPendingPath($this->templatesPath, $templateName);
            ExtensionPendingHelper::copyToActive($sourcePath, $targetPath, $onProgress);
            Log::info('템플릿을 _pending에서 활성 디렉토리로 복사', ['template' => $templateName, 'force' => $force]);
            $this->reloadTemplate($templateName);

            return;
        }

        if (isset($this->bundledTemplates[$templateName]) || ExtensionPendingHelper::isBundled($this->templatesPath, $templateName)) {
            $sourcePath = ExtensionPendingHelper::getBundledPath($this->templatesPath, $templateName);
            ExtensionPendingHelper::copyToActive($sourcePath, $targetPath, $onProgress);
            Log::info('템플릿을 _bundled에서 활성 디렉토리로 복사', ['template' => $templateName, 'force' => $force]);
            $this->reloadTemplate($templateName);

            return;
        }

        throw new \RuntimeException(__('templates.pending_not_found', ['template' => $templateName]));
    }

    protected function reloadTemplate(string $templateName): void
    {
        $directory = $this->templatesPath.DIRECTORY_SEPARATOR.$templateName;
        $templateFile = $directory.DIRECTORY_SEPARATOR.'template.json';

        if (! File::exists($templateFile)) {
            return;
        }

        try {
            $jsonContent = File::get($templateFile);
            $templateData = json_decode($jsonContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("Failed to parse template.json in {$templateName}: ".json_last_error_msg());

                return;
            }

            if (! $this->validateTemplateData($templateData, $templateName)) {
                return;
            }

            if (isset($templateData['name'])) {
                $templateData['name'] = $this->convertToMultilingual($templateData['name']);
            }
            if (isset($templateData['description'])) {
                $templateData['description'] = $this->convertToMultilingual($templateData['description']);
            }

            $templateData['_paths'] = [
                'root' => $directory,
                'components_manifest' => $directory.'/components.json',
                'routes' => $directory.'/routes.json',
                'components_bundle' => $directory.'/dist/components.iife.js',
                'assets' => $directory.'/assets',
                'lang' => $directory.'/lang',
                'layouts' => $directory.'/layouts',
            ];

            $this->templates[$templateName] = $templateData;
            unset($this->pendingTemplates[$templateName]);
            unset($this->bundledTemplates[$templateName]);
        } catch (\Exception $e) {
            Log::error("Failed to reload template {$templateName}: ".$e->getMessage());
        }
    }

    public function checkTemplateUpdate(string $identifier): array
    {
        $record = $this->templateRepository->findByIdentifier($identifier);
        if (! $record) {
            return $this->buildTemplateUpdateResponse(false, null, null, null, null);
        }

        $currentVersion = $record->version;
        $template = $this->getTemplate($identifier);
        $activeRequiredCoreVersion = $template['g7_version'] ?? null;
        $githubUrl = $template['github_url'] ?? ($record->github_url ?? null);
        if ($githubUrl) {
            try {
                $latestVersion = $this->fetchLatestVersion($githubUrl);
            } catch (\Throwable $e) {
                Log::warning('템플릿 GitHub 버전 조회 실패', [
                    'template' => $identifier,
                    'url' => $githubUrl,
                    'error' => $e->getMessage(),
                ]);
                $latestVersion = null;
            }

            if ($latestVersion !== null) {
                if (version_compare($latestVersion, $currentVersion, '>')) {
                    return $this->buildTemplateUpdateResponse(
                        true, 'github', $latestVersion, $currentVersion, $activeRequiredCoreVersion
                    );
                }

                return $this->buildTemplateUpdateResponse(
                    false, null, $currentVersion, $currentVersion, $activeRequiredCoreVersion
                );
            }

            Log::info('템플릿 업데이트 확인: GitHub 조회 실패로 bundled 폴백', [
                'template' => $identifier,
            ]);
        }

        if (isset($this->bundledTemplates[$identifier])) {
            $bundledVersion = $this->bundledTemplates[$identifier]['version'] ?? null;
            $bundledRequired = $this->bundledTemplates[$identifier]['g7_version'] ?? $activeRequiredCoreVersion;
            if ($bundledVersion && version_compare($bundledVersion, $currentVersion, '>')) {
                return $this->buildTemplateUpdateResponse(
                    true, 'bundled', $bundledVersion, $currentVersion, $bundledRequired
                );
            }
        } else {
            $bundledMeta = ExtensionPendingHelper::loadBundledExtensions($this->templatesPath, 'template.json');
            if (isset($bundledMeta[$identifier])) {
                $bundledVersion = $bundledMeta[$identifier]['version'] ?? null;
                $bundledRequired = $bundledMeta[$identifier]['g7_version'] ?? $activeRequiredCoreVersion;
                if ($bundledVersion && version_compare($bundledVersion, $currentVersion, '>')) {
                    return $this->buildTemplateUpdateResponse(
                        true, 'bundled', $bundledVersion, $currentVersion, $bundledRequired
                    );
                }
            }
        }

        return $this->buildTemplateUpdateResponse(
            false, null, $currentVersion, $currentVersion, $activeRequiredCoreVersion
        );
    }

    protected function buildTemplateUpdateResponse(
        bool $updateAvailable,
        ?string $updateSource,
        ?string $latestVersion,
        ?string $currentVersion,
        ?string $requiredCoreVersion,
    ): array {
        return [
            'update_available' => $updateAvailable,
            'update_source' => $updateSource,
            'latest_version' => $latestVersion,
            'current_version' => $currentVersion,
            'required_core_version' => $requiredCoreVersion,
            'is_compatible' => CoreVersionChecker::isCompatible($requiredCoreVersion),
            'current_core_version' => CoreVersionChecker::getCoreVersion(),
        ];
    }

    public function checkAllTemplatesForUpdates(): array
    {
        $templateRecords = $this->templateRepository->getAllKeyedByIdentifier();
        $details = [];
        $updatedCount = 0;

        foreach ($templateRecords as $identifier => $record) {
            $result = $this->checkTemplateUpdate($identifier);

            $updateData = [
                'update_available' => $result['update_available'],
                'latest_version' => $result['latest_version'],
                'update_source' => $result['update_source'],
                'updated_at' => now(),
            ];

            if ($result['update_source'] === 'github') {
                $template = $this->getTemplate($identifier);
                $githubUrl = $template['github_url'] ?? ($record->github_url ?? null);
                if ($githubUrl) {
                    $updateData['github_changelog_url'] = $this->buildChangelogUrl($githubUrl);
                }
            }

            $this->templateRepository->updateByIdentifier($identifier, $updateData);

            if ($result['update_available']) {
                $updatedCount++;
                $details[] = [
                    'identifier' => $identifier,
                    'current_version' => $result['current_version'],
                    'latest_version' => $result['latest_version'],
                    'update_source' => $result['update_source'],
                ];
            }
        }

        return [
            'updated_count' => $updatedCount,
            'details' => $details,
        ];
    }

    protected function downloadTemplateUpdate(string $identifier, string $githubUrl, string $version): string
    {
        if (! $githubUrl) {
            throw new \RuntimeException(__('templates.errors.invalid_github_url'));
        }

        if (! preg_match('#github\.com[/:]([^/]+)/([^/\.]+)#', $githubUrl, $matches)) {
            throw new \RuntimeException(__('templates.errors.invalid_github_url'));
        }

        $owner = $matches[1];
        $repo = $matches[2];
        $stagingPath = ExtensionPendingHelper::createUpdateStagingPath($this->templatesPath, $identifier);
        $tempDir = storage_path('app/temp/template_update_'.uniqid());

        try {
            File::ensureDirectoryExists($tempDir);
            $extractedDir = $this->extensionManager->downloadAndExtractFromGitHub(
                $owner, $repo, $version, $tempDir, config('app.update.github_token') ?? ''
            );
            ExtensionPendingHelper::stageForUpdate($extractedDir, $stagingPath);

            Log::info('템플릿 업데이트 다운로드 및 스테이징 완료', [
                'template' => $identifier,
                'version' => $version,
                'staging_path' => $stagingPath,
            ]);

            return $stagingPath;
        } catch (\Exception $e) {
            ExtensionPendingHelper::cleanupStaging($stagingPath);
            throw $e;
        } finally {
            if (File::isDirectory($tempDir)) {
                File::deleteDirectory($tempDir);
            }
        }
    }

    public function hasModifiedLayouts(string $identifier): array
    {
        $record = $this->templateRepository->findByIdentifier($identifier);
        if (! $record) {
            return [
                'has_modified_layouts' => false,
                'modified_count' => 0,
                'modified_layouts' => [],
            ];
        }

        $allLayouts = $this->layoutRepository->getByTemplateId($record->id);
        $modifiedLayouts = $allLayouts->filter(function ($layout) {
            if (! $layout->original_content_hash) {
                return false;
            }

            $currentContent = is_string($layout->content)
                ? json_decode($layout->content, true)
                : $layout->content;
            $currentHash = $this->computeContentHash($currentContent);

            return $currentHash !== $layout->original_content_hash;
        });

        return [
            'has_modified_layouts' => $modifiedLayouts->isNotEmpty(),
            'modified_count' => $modifiedLayouts->count(),
            'modified_layouts' => $modifiedLayouts->map(function ($layout) {
                $currentContent = is_string($layout->content)
                    ? json_decode($layout->content, true)
                    : $layout->content;
                $currentSize = $this->computeContentSize($currentContent);
                $originalSize = $layout->original_content_size ?? $currentSize;

                return [
                    'id' => $layout->id,
                    'name' => $layout->name,
                    'updated_at' => $layout->updated_at?->format('Y-m-d H:i:s'),
                    'size_diff' => $currentSize - $originalSize,
                ];
            })->values()->toArray(),
        ];
    }

    public function updateTemplate(string $identifier, bool $force = false, ?\Closure $onProgress = null, string $layoutStrategy = 'overwrite', ?string $sourceOverride = null, ?string $zipPath = null): array
    {
        $record = $this->templateRepository->findByIdentifier($identifier);
        if (! $record) {
            throw new \RuntimeException(__('templates.not_installed', ['template' => $identifier]));
        }

        ExtensionStatusGuard::assertNotInProgress(
            ExtensionStatus::from($record->status),
            $identifier
        );

        $previousStatus = $record->status;
        $fromVersion = $record->version;
        $updateInfo = $this->checkTemplateUpdate($identifier);
        $zipTempDir = null;
        $zipExtractedDir = null;
        if ($zipPath !== null) {
            $prepared = $this->extensionManager->prepareZipSource($zipPath, $identifier, 'template.json');
            $zipTempDir = $prepared['temp_dir'];
            $zipExtractedDir = $prepared['extracted_dir'];
            $updateSource = 'zip';
            $toVersion = $prepared['to_version'];
        } elseif ($sourceOverride === 'bundled') {
            $bundled = $this->getBundledVersion($identifier);
            if ($bundled === null) {
                throw new \RuntimeException(__('templates.errors.force_update_no_source', ['template' => $identifier]));
            }
            $updateSource = 'bundled';
            $toVersion = $bundled;
        } elseif ($sourceOverride === 'github') {
            $template = $this->getTemplate($identifier);
            $githubUrl = $template['github_url'] ?? ($record->github_url ?? null);
            if (empty($githubUrl)) {
                throw new \RuntimeException(__('templates.errors.force_update_no_source', ['template' => $identifier]));
            }
            $updateSource = 'github';
            $toVersion = ($updateInfo['update_source'] === 'github' ? $updateInfo['latest_version'] : null)
                ?? $updateInfo['current_version'];
        } elseif (! $updateInfo['update_available'] && ! $force) {
            return [
                'success' => false,
                'from_version' => $fromVersion,
                'to_version' => $fromVersion,
                'message' => __('templates.no_update_available'),
            ];
        } elseif ($force && ! $updateInfo['update_available']) {
            $updateSource = $this->resolveForceUpdateSource($identifier);

            if ($updateSource === null) {
                throw new \RuntimeException(__('templates.errors.force_update_no_source', ['template' => $identifier]));
            }

            if ($updateSource === 'bundled') {
                $toVersion = $this->getBundledVersion($identifier) ?? $updateInfo['current_version'];
            } else {
                $toVersion = $updateInfo['current_version'];
            }
        } else {
            $toVersion = $updateInfo['latest_version'];
            $updateSource = $updateInfo['update_source'];
        }

        if ($fromVersion && version_compare($toVersion, $fromVersion, '<') && ! $force) {
            throw new \RuntimeException(__('templates.errors.downgrade_blocked', [
                'from' => $fromVersion,
                'to' => $toVersion,
            ]));
        }

        $backupPath = null;

        try {
            $onProgress?->__invoke('backup', '백업 생성 중...');
            $backupPath = ExtensionBackupHelper::createBackup('templates', $identifier, $onProgress);
            $onProgress?->__invoke('status', '상태 변경 중...');
            $this->templateRepository->updateByIdentifier($identifier, [
                'status' => ExtensionStatus::Updating->value,
                'updated_at' => now(),
            ]);
            $onProgress?->__invoke('staging', '스테이징 중...');
            $stagingPath = null;

            try {
                if ($updateSource === 'github') {
                    $template = $this->getTemplate($identifier);
                    $githubUrl = $template['github_url'] ?? ($record->github_url ?? null);
                    $stagingPath = $this->downloadTemplateUpdate($identifier, $githubUrl, $toVersion);
                } elseif ($updateSource === 'bundled') {
                    $sourcePath = ExtensionPendingHelper::getBundledPath($this->templatesPath, $identifier);
                    $stagingPath = ExtensionPendingHelper::createUpdateStagingPath($this->templatesPath, $identifier);
                    ExtensionPendingHelper::stageForUpdate($sourcePath, $stagingPath, $onProgress);
                } elseif ($updateSource === 'zip') {
                    $stagingPath = ExtensionPendingHelper::createUpdateStagingPath($this->templatesPath, $identifier);
                    ExtensionPendingHelper::stageForUpdate($zipExtractedDir, $stagingPath, $onProgress);
                }

                if ($stagingPath && ! $force && ! CoreServiceProvider::isCoreUpdateInProgress()) {
                    $stagedManifest = (new Vendor\VendorIntegrityChecker)->readManifest($stagingPath);
                    CoreVersionChecker::validateExtension(
                        $stagedManifest['g7_version'] ?? null,
                        $identifier,
                        'template'
                    );
                }

                $onProgress?->__invoke('files', '파일 교체 중...');
                if ($stagingPath) {
                    $targetPath = $this->templatesPath.DIRECTORY_SEPARATOR.$identifier;
                    ExtensionPendingHelper::copyToActive($stagingPath, $targetPath, $onProgress);
                }
            } finally {
                if ($stagingPath) {
                    ExtensionPendingHelper::cleanupStaging($stagingPath);
                }
                if ($zipTempDir && File::isDirectory($zipTempDir)) {
                    File::deleteDirectory($zipTempDir);
                }
            }

            $onProgress?->__invoke('reload', '재로드 중...');
            $this->reloadTemplate($identifier);
            $template = $this->getTemplate($identifier);
            $this->validateSeoConfig($identifier);
            $onProgress?->__invoke('db', 'DB 갱신 중...');
            DB::beginTransaction();
            try {
                $name = $template ? $this->convertToMultilingual($template['name']) : $record->name;
                $description = $template ? $this->convertToMultilingual($template['description'] ?? '') : $record->description;

                if ($template) {
                    $manifest = HookManager::applyFilters(
                        "template.{$identifier}.manifest.translations",
                        ['name' => $name, 'description' => $description]
                    );
                    $name = $manifest['name'] ?? $name;
                    $description = $manifest['description'] ?? $description;
                }

                $this->templateRepository->updateByIdentifier($identifier, [
                    'version' => $toVersion,
                    'latest_version' => $toVersion,
                    'name' => $name,
                    'description' => $description,
                    'update_available' => false,
                    'update_source' => null,
                    'github_url' => $template['github_url'] ?? $record->github_url,
                    'github_changelog_url' => $this->buildChangelogUrl($template['github_url'] ?? $record->github_url),
                    'metadata' => $template['metadata'] ?? $record->metadata,
                    'updated_by' => Auth::id(),
                    'updated_at' => now(),
                ]);

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            $onProgress?->__invoke('restore_status', '상태 복원 중...');
            $this->templateRepository->updateByIdentifier($identifier, [
                'status' => $previousStatus,
                'updated_at' => now(),
            ]);

            $onProgress?->__invoke('layout', '레이아웃 갱신 중...');
            if ($previousStatus === ExtensionStatus::Active->value) {
                $preserveModified = ($layoutStrategy === 'keep');
                $this->refreshTemplateLayouts($identifier, $preserveModified);
            }

            $onProgress?->__invoke('cleanup', '정리 중...');
            ExtensionBackupHelper::deleteBackup($backupPath);
            $this->clearAllTemplateLanguageCaches();
            $this->clearAllTemplateRoutesCaches();
            if ($previousStatus !== ExtensionStatus::Active->value) {
                $this->incrementExtensionCacheVersion();
            }
            self::invalidateTemplateStatusCache();
            HookManager::doAction('core.templates.updated', $identifier);

            Log::info('템플릿 업데이트 완료', [
                'template' => $identifier,
                'from' => $fromVersion,
                'to' => $toVersion,
                'source' => $updateSource,
                'layout_strategy' => $layoutStrategy,
            ]);

            return [
                'success' => true,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'message' => __('templates.update_success', [
                    'template' => $identifier,
                    'version' => $toVersion,
                ]),
            ];
        } catch (\Throwable $e) {
            Log::error('템플릿 업데이트 실패', [
                'template' => $identifier,
                'error' => $e->getMessage(),
            ]);

            if ($backupPath) {
                try {
                    ExtensionBackupHelper::restoreFromBackup('templates', $identifier, $backupPath);
                    ExtensionBackupHelper::deleteBackup($backupPath);
                    $this->reloadTemplate($identifier);
                } catch (\Throwable $restoreError) {
                    Log::error('템플릿 백업 복원 실패', [
                        'template' => $identifier,
                        'error' => $restoreError->getMessage(),
                    ]);
                }
            }

            $this->templateRepository->updateByIdentifier($identifier, [
                'status' => $previousStatus,
                'updated_at' => now(),
            ]);

            throw new \RuntimeException(
                __('templates.errors.update_failed', [
                    'template' => $identifier,
                    'error' => $e->getMessage(),
                ]),
                0,
                $e
            );
        }
    }

    private function resolveForceUpdateSource(string $identifier): ?string
    {
        if (isset($this->bundledTemplates[$identifier])) {
            return 'bundled';
        }

        $bundledMeta = ExtensionPendingHelper::loadBundledExtensions($this->templatesPath, 'template.json');
        if (isset($bundledMeta[$identifier])) {
            return 'bundled';
        }

        $template = $this->getTemplate($identifier);
        $record = $this->templateRepository->findByIdentifier($identifier);
        $githubUrl = ($template['github_url'] ?? null) ?: ($record->github_url ?? null);
        if ($githubUrl) {
            return 'github';
        }

        return null;
    }

    private function getBundledVersion(string $identifier): ?string
    {
        if (isset($this->bundledTemplates[$identifier]['version'])) {
            return $this->bundledTemplates[$identifier]['version'];
        }

        $meta = ExtensionPendingHelper::loadBundledExtensions($this->templatesPath, 'template.json');

        return $meta[$identifier]['version'] ?? null;
    }

    protected function warmTemplateCache(string $templateIdentifier): void
    {
        $template = $this->templateRepository->findByIdentifier($templateIdentifier);
        if (! $template || $template->status !== ExtensionStatus::Active->value) {
            return;
        }

        $layouts = $this->layoutRepository->getLayoutNamesByTemplateId($template->id)->toArray();

        if (empty($layouts)) {
            Log::debug('캐시 워밍할 레이아웃 없음', [
                'template' => $templateIdentifier,
            ]);

            return;
        }

        $cacheVersion = self::getExtensionCacheVersion();
        $layoutService = app(LayoutService::class);
        $cacheTtl = (int) g7_core_settings(
            'cache.layout_ttl',
            config('template.layout.cache_ttl', 3600)
        );

        foreach ($layouts as $layoutName) {
            try {
                $cacheKey = "layout.{$templateIdentifier}.{$layoutName}.v{$cacheVersion}";

                $this->cache()->remember($cacheKey, function () use ($templateIdentifier, $layoutName, $layoutService) {
                    return $layoutService->getLayout($templateIdentifier, $layoutName);
                }, $cacheTtl);

                Log::debug('레이아웃 캐시 워밍 완료', [
                    'template' => $templateIdentifier,
                    'layout' => $layoutName,
                ]);
            } catch (\Exception $e) {
                Log::debug('레이아웃 캐시 워밍 실패', [
                    'template' => $templateIdentifier,
                    'layout' => $layoutName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $templateService = app(TemplateService::class);
            $result = $templateService->getRoutesDataWithModules($templateIdentifier);

            if ($result['success']) {
                $cacheKey = "template.routes.{$templateIdentifier}.v{$cacheVersion}";
                $this->cache()->put($cacheKey, ['success' => true, 'data' => $result['data']], $cacheTtl);
            }
        } catch (\Exception $e) {
            Log::debug('Routes 캐시 워밍 실패', [
                'template' => $templateIdentifier,
                'error' => $e->getMessage(),
            ]);
        }

        $supportedLocales = config('app.supported_locales', ['ko', 'en']);
        foreach ($supportedLocales as $locale) {
            try {
                $langFilePath = base_path("templates/{$templateIdentifier}/lang/{$locale}.json");
                if (file_exists($langFilePath)) {
                    $cacheKey = "template.language.{$templateIdentifier}.{$locale}.v{$cacheVersion}";
                    $this->cache()->remember($cacheKey, function () use ($templateIdentifier, $locale, $templateService) {
                        $result = $templateService->getLanguageDataWithModules($templateIdentifier, $locale);

                        if (! $result['success']) {
                            return ['error' => $result['error']];
                        }

                        return ['success' => true, 'data' => $result['data']];
                    }, $cacheTtl);
                }
            } catch (\Exception $e) {
                Log::debug('다국어 파일 캐시 워밍 실패', [
                    'template' => $templateIdentifier,
                    'locale' => $locale,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info(__('templates.info.cache_warmed'), [
            'template' => $templateIdentifier,
        ]);
    }
}
