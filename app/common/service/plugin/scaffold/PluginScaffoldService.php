<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\scaffold;

use app\common\service\plugin\PluginManifestService;
use app\common\service\plugin\lifecycle\PluginDistributionService;
use app\common\service\plugin\package\PluginPackageService;
use app\common\service\plugin\commerce\PluginCommercialPricingService;
use app\common\service\plugin\weapp\WeappAdminUiService;
use app\common\support\ServiceResult;
use app\common\service\admin\WeappAdminSpaHostRoutes;
use app\common\support\ProjectPaths;


use app\common\service\audit\AuditLogService;
use app\common\support\LocalFile;
/**
 * 从 manifest 生成 weapp 脚手架；生成物 AdminController JSON 须 {@see ApiResponse::plugin} / fromServiceResult。
 */
class PluginScaffoldService
{
    public function __construct(
        private readonly PluginManifestService $pluginManifestService,
        private readonly AuditLogService $auditLogService,
        private readonly PluginReservedIdentifierService $pluginReservedIdentifierService,
    ) {
    }

    /** @var list<string> */
    public const KINDS = [
        PluginManifestService::KIND_DOCUMENT_ADDON,
        PluginManifestService::KIND_PLATFORM,
        PluginManifestService::KIND_APPLICATION,
    ];

    /** 文档增强件脚手架默认发布页形态（可在插件「基础设置」中改） */
    public const DEFAULT_DOCUMENT_EDITOR_SLOT = 'tab';

    /**
     * 脚手架「插件类型」卡片（中文说明）
     *
     * @return list<array{value:string,label:string,desc:string,example:string}>
     */
    public function scaffoldKindCards(): array
    {
        return [
            [
                'value'   => PluginManifestService::KIND_DOCUMENT_ADDON,
                'label'   => '文档增强件',
                'desc'    => '绑定单篇文档，在发布页或前台扩展下载、图集、评论等能力。',
                'example' => '参考官方：下载、图集、评论',
            ],
            [
                'value'   => PluginManifestService::KIND_PLATFORM,
                'label'   => '平台能力',
                'desc'    => '横切全站的水电煤能力，一般不占用文档发布页 Tab。',
                'example' => '参考：缩略图钩子、支付、登录',
            ],
            [
                'value'   => PluginManifestService::KIND_APPLICATION,
                'label'   => '企业应用',
                'desc'    => '独立业务子系统，自带后台菜单树与前台路由（商城、OA 等）。',
                'example' => '参考：后台插件脚手架、商城、OA',
            ],
        ];
    }

    /** 留空包名时使用的默认厂商前缀 */
    public const DEFAULT_PACKAGE_VENDOR = 'dev';

    /**
     * 系统默认随机包名（格式 dev/pk_*，与库及 weapp 清单去重）
     */
    public function defaultPackage(): string
    {
        return app(PluginPackageService::class)->allocateUniquePackage(self::DEFAULT_PACKAGE_VENDOR);
    }

    /**
     * @param array{
     *   kind:string,
     *   identifier:string,
     *   name:string,
     *   package?:string,
     *   author?:string,
     *   tag_name?:string,
     *   pricing_form?:array<string, mixed>
     * } $options
     * @return ServiceResult
     */
    public function create(array $options): ServiceResult
    {
        $kind = strtolower(trim($options['kind']));
        if (!in_array($kind, self::KINDS, true)) {
            return ServiceResult::fail('插件类型无效，请选择文档增强件、平台能力或企业应用');
        }

        $identifier = $this->pluginReservedIdentifierService->normalizeIdentifier((string) $options['identifier']);
        if ($identifier === null) {
            return ServiceResult::fail($this->pluginReservedIdentifierService->identifierFormatMessage());
        }

        $reserved = $this->pluginReservedIdentifierService->validateForPlugin($identifier);
        if ($reserved !== null) {
            return ServiceResult::fail($reserved);
        }

        if (app(\app\common\service\plugin\PluginService::class)->blocksScaffoldOverwrite($identifier)) {
            return ServiceResult::fail('不可覆盖或占用官方插件标识');
        }

        $dest = app(\app\common\service\plugin\PluginService::class)->weappRoot() . $identifier;
        if (is_dir($dest)) {
            return ServiceResult::fail('目录 weapp/' . $identifier . ' 已存在');
        }

        $name = trim($options['name']);
        if ($name === '') {
            return ServiceResult::fail('请填写插件名称');
        }

        $package = trim((string) ($options['package'] ?? ''));
        if ($package === '') {
            $package = $this->defaultPackage();
        }
        $packageErr = $this->pluginReservedIdentifierService->validatePackageForPlugin($package);
        if ($packageErr !== null) {
            return ServiceResult::fail($packageErr);
        }
        if (app(PluginPackageService::class)->isPackageTaken($package)) {
            return ServiceResult::fail('包名已被占用：' . $package . '（请留空由系统分配，或更换 vendor/slug）');
        }

        // 脚手架仅生成本站自用包；市场上架时的 personal/enterprise 由市场开发者身份写入
        $publisher = PluginManifestService::TYPE_LOCAL;

        $author = trim((string) ($options['author'] ?? ''));
        if ($author === '') {
            $author = '开发者';
        }

        $tagName = strtolower(trim((string) ($options['tag_name'] ?? $identifier)));
        $tagName = preg_replace('/[^a-z0-9_]/', '', $tagName) ?: $identifier;

        $slug     = substr($package, strrpos($package, '/') + 1);
        $vendor   = strtolower(trim(explode('/', $package, 2)[0]));
        $manifest = $this->buildManifest(
            $kind,
            $identifier,
            $name,
            $package,
            $vendor,
            $slug,
            $author,
            $publisher,
            $tagName
        );
        $validation = $this->pluginManifestService->validate($manifest);
        if (!$validation['ok']) {
            return ServiceResult::fail('清单校验失败：' . implode('；', $validation['errors']));
        }

        $files = $this->buildFiles($kind, $identifier, $name, $package, $tagName, $manifest);
        if (!mkdir($dest, 0755, true) && !is_dir($dest)) {
            return ServiceResult::fail('无法创建目录 weapp/' . $identifier);
        }

        try {
            foreach ($files as $rel => $content) {
                $path = $dest . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                $parent = dirname($path);
                if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                    throw new \RuntimeException('无法创建子目录：' . $rel);
                }
                if (file_put_contents($path, $content) === false) {
                    throw new \RuntimeException('无法写入：' . $rel);
                }
            }
            foreach ($this->buildDocumentEditorPartials($kind, $identifier, $name) as $absPath => $content) {
                $parent = dirname($absPath);
                if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                    throw new \RuntimeException('无法创建文档编辑 partial 目录');
                }
                if (file_put_contents($absPath, $content) === false) {
                    throw new \RuntimeException('无法写入文档编辑 partial');
                }
            }
        } catch (\Throwable $e) {
            $this->removeDir($dest);
            $this->removeDocumentEditorPartials($identifier);

            return ServiceResult::fail($e->getMessage());
        }

        $pricingForm = $options['pricing_form'] ?? null;
        if (is_array($pricingForm) && $pricingForm !== []) {
            $pricing = app(PluginCommercialPricingService::class)->applyToWeapp($identifier, $pricingForm);
            if (!$pricing->isOk()) {
                $this->removeDir($dest);
                $this->removeDocumentEditorPartials($identifier);

                return ServiceResult::fail($pricing->message() !== '' ? $pricing->message() : '定价保存失败');
            }
        }

        $this->auditLogService->operate('创建插件脚手架', 'admin.plugin', [
            'identifier' => $identifier,
            'kind'       => $kind,
            'package'    => $package,
        ]);

        return ServiceResult::ok(
            ['identifier' => $identifier, 'package' => $package, 'path' => 'weapp/' . $identifier],
            '插件脚手架已生成（包名 ' . $package . '）。后台 UI 只写 weapp/' . $identifier . '/admin/ui/，无需 junction 或 admin/views 副本；build 时自动注入 SPA。',
        );
    }

    /**
     * 生成可下载脚手架 zip（不落盘 weapp/；用户用「导入插件」安装）。
     *
     * @param array{
     *   kind:string,
     *   identifier:string,
     *   name:string,
     *   package?:string,
     *   author?:string,
     *   tag_name?:string,
     *   pricing_form?:array<string, mixed>
     * } $options
     */
    public function createDownloadZip(array $options): ServiceResult
    {
        $kind = strtolower(trim($options['kind']));
        if (!in_array($kind, self::KINDS, true)) {
            return ServiceResult::fail('插件类型无效，请选择文档增强件、平台能力或企业应用');
        }

        $identifier = $this->pluginReservedIdentifierService->normalizeIdentifier((string) $options['identifier']);
        if ($identifier === null) {
            return ServiceResult::fail($this->pluginReservedIdentifierService->identifierFormatMessage());
        }

        $reserved = $this->pluginReservedIdentifierService->validateForPlugin($identifier);
        if ($reserved !== null) {
            return ServiceResult::fail($reserved);
        }

        if (app(\app\common\service\plugin\PluginService::class)->blocksScaffoldOverwrite($identifier)) {
            return ServiceResult::fail('不可覆盖或占用官方插件标识');
        }

        $name = trim($options['name']);
        if ($name === '') {
            return ServiceResult::fail('请填写插件名称');
        }

        $package = trim((string) ($options['package'] ?? ''));
        if ($package === '') {
            $package = $this->defaultPackage();
        }
        $packageErr = $this->pluginReservedIdentifierService->validatePackageForPlugin($package);
        if ($packageErr !== null) {
            return ServiceResult::fail($packageErr);
        }
        if (app(PluginPackageService::class)->isPackageTaken($package)) {
            return ServiceResult::fail('包名已被占用：' . $package . '（请留空由系统分配，或更换 vendor/slug）');
        }

        $publisher = PluginManifestService::TYPE_LOCAL;
        $author = trim((string) ($options['author'] ?? ''));
        if ($author === '') {
            $author = '开发者';
        }
        $tagName = strtolower(trim((string) ($options['tag_name'] ?? $identifier)));
        $tagName = preg_replace('/[^a-z0-9_]/', '', $tagName) ?: $identifier;
        $slug = substr($package, strrpos($package, '/') + 1);
        $vendor = strtolower(trim(explode('/', $package, 2)[0]));
        $manifest = $this->buildManifest(
            $kind,
            $identifier,
            $name,
            $package,
            $vendor,
            $slug,
            $author,
            $publisher,
            $tagName
        );
        $validation = $this->pluginManifestService->validate($manifest);
        if (!$validation['ok']) {
            return ServiceResult::fail('清单校验失败：' . implode('；', $validation['errors']));
        }

        $pricingForm = $options['pricing_form'] ?? null;
        if (is_array($pricingForm) && $pricingForm !== []) {
            $built = app(\app\common\service\plugin\commerce\PluginCommercialPricingService::class)
                ->buildCommercialBlock($pricingForm);
            $existing = isset($manifest['commercial']) && is_array($manifest['commercial'])
                ? $manifest['commercial']
                : [];
            $manifest['commercial'] = array_merge($existing, $built);
            $manifest = $this->pluginManifestService->applyValidation($manifest);
            if (empty($manifest['_manifest_valid'])) {
                $errs = is_array($manifest['_manifest_errors'] ?? null) ? $manifest['_manifest_errors'] : ['清单无效'];

                return ServiceResult::fail('定价写入后清单校验失败：' . implode('；', $errs));
            }
            unset($manifest['_manifest_valid'], $manifest['_manifest_errors'], $manifest['_manifest_warnings']);
        }

        $files = $this->buildFiles($kind, $identifier, $name, $package, $tagName, $manifest);
        $token = bin2hex(random_bytes(16));
        $staging = ProjectPaths::runtimeDir() . DIRECTORY_SEPARATOR . 'tmp'
            . DIRECTORY_SEPARATOR . 'plugin-scaffold' . DIRECTORY_SEPARATOR . $token;
        $zipPath = $staging . '.zip';
        if (!is_dir($staging) && !mkdir($staging, 0755, true) && !is_dir($staging)) {
            return ServiceResult::fail('无法创建临时目录');
        }

        try {
            foreach ($files as $rel => $content) {
                $path = $staging . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                $parent = dirname($path);
                if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                    throw new \RuntimeException('无法创建子目录：' . $rel);
                }
                if (file_put_contents($path, $content) === false) {
                    throw new \RuntimeException('无法写入：' . $rel);
                }
            }
            if (!class_exists(\ZipArchive::class)) {
                throw new \RuntimeException('服务器未启用 ZipArchive，无法打包下载');
            }
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('无法创建 zip');
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($staging, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $abs = $file->getPathname();
                $local = $identifier . '/' . str_replace('\\', '/', substr($abs, strlen($staging) + 1));
                $zip->addFile($abs, $local);
            }
            $zip->close();
        } catch (\Throwable $e) {
            $this->removeDir($staging);
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }

            return ServiceResult::fail($e->getMessage());
        }
        $this->removeDir($staging);

        $this->auditLogService->operate('下载插件脚手架包', 'admin.plugin', [
            'identifier' => $identifier,
            'kind'       => $kind,
            'package'    => $package,
        ]);

        return ServiceResult::ok([
            'identifier'     => $identifier,
            'package'        => $package,
            'download_token' => $token,
            'filename'       => $identifier . '-scaffold.zip',
        ], '脚手架包已生成，请下载后到「导入插件」安装');
    }

    /** @return string|null 绝对路径 */
    public function resolveDownloadZip(string $token): ?string
    {
        $token = strtolower(trim($token));
        if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $zipPath = ProjectPaths::runtimeDir() . DIRECTORY_SEPARATOR . 'tmp'
            . DIRECTORY_SEPARATOR . 'plugin-scaffold' . DIRECTORY_SEPARATOR . $token . '.zip';
        if (!is_file($zipPath)) {
            return null;
        }

        return $zipPath;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildManifest(
        string $kind,
        string $identifier,
        string $name,
        string $package,
        string $vendor,
        string $slug,
        string $author,
        string $publisher,
        string $tagName
    ): array {
        $defaultEditorSlot = self::DEFAULT_DOCUMENT_EDITOR_SLOT;
        $permBase = 'plugin.' . str_replace('-', '_', $slug);
        $permManage = $permBase . '.manage';
        $permSettings = $permBase . '.settings';
        $defaultPage = match ($kind) {
            PluginManifestService::KIND_APPLICATION => 'index',
            default => 'settings',
        };
        $spaPath = WeappAdminSpaHostRoutes::hostPath($identifier, $defaultPage);

        $surfaces = match ($kind) {
            PluginManifestService::KIND_PLATFORM => [
                'admin'            => ['settings'],
                'document_editor'  => ['enabled' => false],
                'frontend'         => ['render_hooks' => []],
            ],
            PluginManifestService::KIND_APPLICATION => [
                'admin'            => ['menu_tree'],
                'document_editor'  => ['enabled' => false],
                'frontend'         => ['standalone_route' => '/' . $identifier . '/'],
            ],
            default => [
                'admin'            => ['plugin_home'],
                'document_editor'  => [
                    'enabled'        => true,
                    'slot'           => $defaultEditorSlot,
                    'tab_label'      => $name,
                    'tab_order'      => 50,
                    'allow_override' => ['tab', 'inline', 'hidden'],
                    'ui_mode'        => 'iframe',
                    'iframe_entry'   => 'admin/dist/document-editor.html',
                ],
                'frontend'         => [
                    'template_tags' => [['name' => $tagName]],
                ],
            ],
        };

        $manifest = [
            'identifier'     => $identifier,
            'package'        => $package,
            'vendor'         => $vendor,
            'slug'           => $slug,
            'kind'           => $kind,
            'publisher_type' => $publisher,
            'name'           => $name,
            'version'        => '1.0.0',
            'edition'        => 'community',
            'description'    => $name . '（本站自用脚手架，与 Core 分离便于升级主程序）',
            'author'         => $author,
            'icon'           => '/weapp/' . $identifier . '/assets/icon.svg',
            'color'          => '#5fb878',
            'tags'           => [$identifier],
            'permissions'    => [$permBase, $permManage, $permSettings],
            'commercial'     => [
                'model'        => 'free',
                'price'        => 0,
                'currency'     => 'CNY',
                'period_days'  => null,
                'trial_days'   => null,
                'commercial_profile' => 'policy_flexible',
                'policy'       => ['active_sku' => 'official_limited_free'],
                'skus'         => [
                    [
                        'sku_id'       => 'official_limited_free',
                        'name'         => '限时免费',
                        'billing_type' => 'limited_free',
                        'price'        => 0,
                        'duration_days'=> 90,
                    ],
                    [
                        'sku_id'       => 'official_permanent_free',
                        'name'         => '永久免费',
                        'billing_type' => 'free',
                        'price'        => 0,
                    ],
                ],
                'revenue_share' => [
                    'host_percent' => 30,
                    'channel_percent'  => 0,
                    'author_percent'   => 70,
                ],
                'features'     => [],
            ],
            'distribution'   => [
                'mode'                  => PluginDistributionService::MODE_SOURCE_OPEN,
                'license_spdx'          => 'MIT',
                'allows_secondary_dev'  => true,
                'allows_redistribution' => true,
                'encryption'            => 'none',
                'notice_file'           => 'NOTICE.md',
            ],
            'admin'          => [
                'home_route' => $spaPath,
                'route'      => $spaPath,
                'title'      => $name,
                'ui'         => [
                    'mode'     => WeappAdminUiService::MODE_CORE_VUE,
                    'spa_path' => $spaPath,
                ],
            ],
            'dependencies'   => [],
            'requires'       => [
                'pivark_core' => '>=1.6.0 <2.0.0',
                'php'         => '>=8.1',
            ],
            'surfaces'       => $surfaces,
        ];

        $extensions = $this->defaultExtensionsBlock($kind, $identifier, $this->toStudly($identifier));
        if ($extensions !== []) {
            $manifest['extensions'] = $extensions;
        }

        return $this->applyTaxonomyToManifest($identifier, $kind, $manifest);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultExtensionsBlock(string $kind, string $identifier, string $classId): array
    {
        if ($kind !== PluginManifestService::KIND_DOCUMENT_ADDON) {
            return [];
        }

        $ns      = 'weapp\\' . $identifier;
        $postKey = 'product_item_' . str_replace('-', '_', $identifier) . '_json';

        return [
            'product_tab_after_persist' => [
                'post_keys' => [$postKey],
                'handler'   => $ns . '\\service\\' . $classId . 'DocumentTabSync::afterPersist',
                'priority'  => 100,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function applyTaxonomyToManifest(string $identifier, string $kind, array $manifest): array
    {
        $registry = config('plugin.taxonomy.plugins');
        $defaults = config('plugin.taxonomy.kind_defaults');
        $entry    = is_array($registry[$identifier] ?? null) ? $registry[$identifier] : [];
        $kindDef  = is_array($defaults[$kind] ?? null) ? $defaults[$kind] : [];
        $tier     = (string) ($entry['commercial_tier'] ?? ($kindDef['commercial_tier'] ?? ''));
        if ($tier !== '') {
            $manifest['commercial']['tier'] = $tier;
        }
        $domain = $entry['business_domain'] ?? ($kindDef['business_domain'] ?? null);
        if ($domain !== null && (string) $domain !== '' && (string) $domain !== '-') {
            $manifest['commercial']['domain'] = strtoupper((string) $domain);
        }
        $audience = (string) ($entry['editor_audience'] ?? ($kindDef['editor_audience'] ?? ''));
        if ($audience !== '') {
            $manifest['commercial']['editor_audience'] = $audience;
        }

        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, string> relative path => content
     */
    private function buildFiles(
        string $kind,
        string $identifier,
        string $name,
        string $package,
        string $tagName,
        array $manifest
    ): array {
        $classId   = $this->toStudly($identifier);
        $ns        = 'weapp\\' . $identifier;
        $tableBase = 'weapp_' . str_replace('-', '_', $identifier);
        $json      = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $json      = $json !== false ? $json . "\n" : "{}";

        $files = [
            'plugin.json' => $json,
            'README.md'   => $this->readme($identifier, $name, $kind, $package),
            'Plugin.php'  => $this->pluginPhp($ns, $identifier, $tagName, $kind),
            'database/install.sql' => $this->installSql($kind, $tableBase),
            'database/uninstall.sql' => $this->uninstallSql($kind, $tableBase),
            'assets/icon.svg' => $this->iconSvg($name),
            'assets/guide/.gitkeep' => '',
            'admin/AdminController.php' => $this->adminControllerPhp($ns, $identifier, $classId, $kind),
            'admin/dist/index.html' => $this->adminIframeHtml($identifier, $name, $tagName),
            'admin/dist/document-editor.html' => $this->documentEditorIframeHtml($identifier, $name),
        ];

        if ($kind === PluginManifestService::KIND_DOCUMENT_ADDON) {
            $files['api/controller/Api.php'] = $this->apiControllerPhp($ns, $identifier);
            $files['service/' . $classId . 'ConfigService.php'] = $this->configServicePhp($ns, $identifier, $classId);
            $files['service/' . $classId . 'DocumentTabSync.php'] = $this->documentTabSyncPhp($ns, $classId);
        }

        if ($kind === PluginManifestService::KIND_APPLICATION) {
            $files['api/controller/Api.php'] = $this->apiControllerPhp($ns, $identifier);
        }

        return $files;
    }

    /**
     * @return array<string, string> absolute path => content
     */
    private function buildDocumentEditorPartials(
        string $kind,
        string $identifier,
        string $name
    ): array {
        if ($kind !== PluginManifestService::KIND_DOCUMENT_ADDON) {
            return [];
        }

        $slug    = str_replace('-', '_', $identifier);
        $inline  = $this->documentPartialPath($identifier, '_inline');
        $tab     = $this->documentPartialPath($identifier, '_tab');
        $listUrl = WeappAdminSpaHostRoutes::hostPath($identifier, 'settings');

        $inlinePhp = <<<PHP
<?php
\$articleId = (int) (\$articleId ?? 0);
\$addonBridgeId = '{$identifier}';
\$addonBridgeLabel = trim((string) (\$editorInlineLabel ?? '{$name}'));
if (\$addonBridgeLabel === '') {
    \$addonBridgeLabel = '{$name}';
}
\$addonBridgeListUrl = '{$listUrl}' . (\$articleId > 0 ? '?document_id=' . \$articleId : '');
\$addonBridgeCount = 0;
include dirname(__DIR__, 3) . '/common/weapp/view/_document_editor_addon_bridge.php';

PHP;

        $tabPhp = <<<PHP
<?php
\$editorTabLabel = trim((string) (\$editorTabLabel ?? '{$name}'));
include __DIR__ . '/_weapp_{$slug}_inline.php';

PHP;

        return [
            $inline => $inlinePhp,
            $tab    => $tabPhp,
        ];
    }

    private function documentPartialPath(string $identifier, string $suffix): string
    {
        $slug = str_replace('-', '_', $identifier);

        return ProjectPaths::root() . 'app' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'view'
            . DIRECTORY_SEPARATOR . 'document' . DIRECTORY_SEPARATOR . '_weapp_' . $slug . $suffix . '.php';
    }

    private function removeDocumentEditorPartials(string $identifier): void
    {
        foreach (['_inline', '_tab'] as $suffix) {
            $path = $this->documentPartialPath($identifier, $suffix);
            if (is_file($path)) {
                LocalFile::unlinkIfExists($path);
            }
        }
    }

    private function readme(string $id, string $name, string $kind, string $package): string
    {
        $tag = $id;

        return <<<MD
# {$name}

- **identifier:** `{$id}`
- **package:** `{$package}`
- **kind:** `{$kind}`

**开发者只维护本 `README.md` 一个文件**（不依赖 `docs/06-插件/`；旧项目可保留独立 `CHANGELOG.md` 作兼容）：

| 章节 | 出现在哪 |
|------|----------|
| `## 功能介绍` | 后台「功能介绍」+ 市场「商品详情」 |
| `## 使用说明` | 后台「前台调用说明」+ 市场「使用说明」Tab |
| `## 升级日志` | 后台 / 市场「升级日志」Tab（版本用 `### 1.0.0 · 日期`） |
| `## 安装与开发` | 仅本 README，给开发者看 |

后台与市场详情**只读本机 `weapp/{$id}/`**，改完保存即生效，无需发布到文档库。

## 功能介绍

{$name}：能做什么、后台操作步骤（1→2→3）；插图放 `assets/guide/`，正文写 `guide-xxx.svg` 或 `assets/guide/xxx.svg`。

## 使用说明

**前台模板 / REST / Webhook**（第三方主题开发者看这一段）。示例：`{pv:{$tag}}`、属性表、可复制代码块。无前台则写明「本插件无访客页，仅在后台使用」。

## 升级日志

### 1.0.0 · YYYY-MM-DD

- 首次发布

## 安装与开发

后台 → 插件应用 → 安装 → 启用。卸载档位：**register** / **config** / **data**（`database/uninstall.sql`）。

- **后台 Vue 页**：只写 `admin/ui/*.vue`（SSOT 在本目录）；内核 `pnpm build:app` 时 glob 注入，**禁止**在 `admin/src/views/weapp/{id}` 建 junction 或副本。
- **后台 API 封装**：写 `admin/api/*.ts`，页面内 `import … from '@weapp-{identifier}'`（Vite 构建自动注册别名）；**禁止**在 `admin/src/api/pivark/weapp/` 手建转发文件。

MD;
    }

    private function adminIframeHtml(string $identifier, string $name, string $tagName): string
    {
        $apiBase = '/admin/weapp/' . $identifier;

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>{$name} · 插件后台</title>
  <style>
    :root { font-family: system-ui, sans-serif; color: #1e293b; }
    body { margin: 0; padding: 20px; background: #f8fafc; }
    .card { max-width: 640px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; }
    h1 { font-size: 18px; margin: 0 0 8px; }
    p { font-size: 13px; color: #64748b; line-height: 1.6; }
    label { display: block; font-size: 13px; margin: 12px 0 4px; }
    input[type="text"] { width: 100%; box-sizing: border-box; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; }
    button { margin-top: 16px; padding: 8px 16px; background: #2563eb; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
    pre { background: #f1f5f9; padding: 12px; border-radius: 6px; font-size: 12px; overflow: auto; }
    .ok { color: #059669; }
    .err { color: #dc2626; }
  </style>
</head>
<body>
  <div class="card">
    <h1>{$name}</h1>
    <p>这是脚手架自带的 iframe 后台示例。请用任意前端技术改写 <code>admin/dist/</code>，通过 <code>fetch</code> 调用 <code>{$apiBase}/*</code>（须带 Cookie）。</p>
    <label><input type="checkbox" id="open" checked /> 启用插件（{$identifier}_open）</label>
    <button type="button" id="save">保存设置</button>
    <p id="msg"></p>
    <pre id="ping">API 探测中…</pre>
  </div>
  <script>
    const API = '{$apiBase}';
    const msg = document.getElementById('msg');
    const pingEl = document.getElementById('ping');
    function restOk(data) {
      return data && typeof data === 'object' && !data.error && Object.prototype.hasOwnProperty.call(data, 'data');
    }
    async function api(path, opts) {
      const base = Object.assign({
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      }, opts || {});
      const res = await fetch(API + path, base);
      const text = await res.text();
      try { return JSON.parse(text); } catch (e) { return { error: { code: 'PARSE', message: text } }; }
    }
    api('/ping').then(function (data) {
      pingEl.textContent = JSON.stringify(data, null, 2);
    }).catch(function (e) {
      pingEl.textContent = String(e);
    });
    document.getElementById('save').addEventListener('click', async function () {
      msg.textContent = '保存中…';
      msg.className = '';
      const body = new URLSearchParams();
      body.set('{$identifier}_open', document.getElementById('open').checked ? '1' : '0');
      const data = await api('/configSave', { method: 'POST', body: body, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
      msg.textContent = (data.meta && data.meta.message) || (data.error && data.error.message) || (restOk(data) ? '保存成功' : '保存失败');
      msg.className = restOk(data) ? 'ok' : 'err';
    });
  </script>
</body>
</html>
HTML;
    }

    private function documentEditorIframeHtml(string $identifier, string $name): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>{$name} · 文档编辑</title>
  <style>
    :root { font-family: system-ui, sans-serif; color: #1e293b; font-size: 13px; }
    body { margin: 0; padding: 16px; background: #fff; }
    h1 { font-size: 15px; margin: 0 0 8px; }
    p.hint { color: #64748b; margin: 0 0 12px; line-height: 1.5; }
    button { padding: 6px 12px; border-radius: 6px; border: 1px solid #cbd5e1; background: #f8fafc; cursor: pointer; }
    button.primary { background: #2563eb; color: #fff; border-color: #2563eb; }
    .row { margin: 8px 0; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; }
    input[type="text"] { width: 100%; box-sizing: border-box; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; }
    label { display: block; margin: 6px 0 4px; }
    .err { color: #dc2626; }
  </style>
</head>
<body>
  <h1>{$name}</h1>
  <p class="hint">文档发布页 iframe 占位。官方 document-addon 请运行 <code>npm run weapp:build-document-editor -- --plugin={$identifier}</code> 生成标装壳，再补业务字段。</p>
  <p id="status" class="hint">加载中…</p>
  <div id="root"></div>
  <script>
    const PLUGIN_ID = '{$identifier}';
    const PREPARE = 'pivark:document-editor-prepare-save';
    const EXPORT = 'pivark:document-editor-export';
    const params = new URLSearchParams(location.search);
    const documentId = Number(params.get('document_id') || 0);
    const statusEl = document.getElementById('status');
    function exportFields() {
      return {};
    }
    window.addEventListener('message', function (ev) {
      const data = ev.data || {};
      if (data.type === PREPARE && String(data.identifier || '').toLowerCase() === PLUGIN_ID) {
        parent.postMessage({
          type: EXPORT,
          identifier: PLUGIN_ID,
          fields: exportFields(),
        }, '*');
      }
    });
    statusEl.textContent = '文档 ID: ' + documentId + ' · 请在插件内实现业务 UI';
  </script>
</body>
</html>
HTML;
    }

    private function pluginPhp(string $ns, string $identifier, string $tagName, string $kind): string
    {
        $bootBody = '';
        if ($kind === PluginManifestService::KIND_DOCUMENT_ADDON) {
            // heredoc 会吃 `\t`/`\e` 等转义；路径反斜杠一律加倍
            $bootBody = <<<PHP

        app(\\app\\common\\service\\template\\TemplateEngine::class)->registerPluginTag('{$tagName}', static function (array \$attrs, array \$pageVars, string \$tpl): string {
            return '<!-- {$identifier}：请在 Plugin::boot 中实现标签渲染 -->';
        });
PHP;
        }

        return <<<PHP
<?php
declare(strict_types=1);

namespace {$ns};

use app\\common\\service\\plugin\\entitlement\\EntitlementService;
use app\\common\\service\\plugin\\PluginService;
use app\\common\\service\\template\\TemplateEngine;

class Plugin
{
    public function install(): void
    {
        \$manifest = app(\\app\\common\\service\\plugin\\PluginService::class)->readManifest('{$identifier}') ?? [];
        app(\\app\\common\\service\\plugin\\entitlement\\EntitlementService::class)->applyInstallPolicy('{$identifier}', \$manifest);
    }

    public function enable(): void
    {
    }

    public function disable(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function upgrade(): void
    {
    }

    public function boot(): void
    {
{$bootBody}
    }
}

PHP;
    }

    private function installSql(string $kind, string $tableBase): string
    {
        if ($kind === PluginManifestService::KIND_DOCUMENT_ADDON) {
            return <<<SQL
CREATE TABLE IF NOT EXISTS `{{prefix}}{$tableBase}_items` (
    `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
    `document_id` int unsigned NOT NULL DEFAULT 0 COMMENT '文档 ID',
    `title` varchar(200) NOT NULL DEFAULT '' COMMENT '标题',
    `payload` text COMMENT '扩展 JSON',
    `status` tinyint NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_document_id` (`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='{$tableBase} 业务表';

SQL;
        }
        if ($kind === PluginManifestService::KIND_PLATFORM) {
            return <<<SQL
CREATE TABLE IF NOT EXISTS `{{prefix}}{$tableBase}_settings` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `cfg_key` varchar(64) NOT NULL DEFAULT '',
    `cfg_value` text,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_cfg_key` (`cfg_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='{$tableBase} 配置';

SQL;
        }

        return <<<SQL
CREATE TABLE IF NOT EXISTS `{{prefix}}{$tableBase}_entries` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `title` varchar(200) NOT NULL DEFAULT '',
    `status` tinyint NOT NULL DEFAULT 1,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='{$tableBase} 示例业务表';

SQL;
    }

    private function uninstallSql(string $kind, string $tableBase): string
    {
        if ($kind === PluginManifestService::KIND_DOCUMENT_ADDON) {
            return "DROP TABLE IF EXISTS `{{prefix}}{$tableBase}_items`;\n";
        }
        if ($kind === PluginManifestService::KIND_PLATFORM) {
            return "DROP TABLE IF EXISTS `{{prefix}}{$tableBase}_settings`;\n";
        }

        return "DROP TABLE IF EXISTS `{{prefix}}{$tableBase}_entries`;\n";
    }

    private function adminControllerPhp(string $ns, string $identifier, string $classId, string $kind): string
    {
        if ($kind === PluginManifestService::KIND_DOCUMENT_ADDON) {
            return <<<PHP
<?php
declare(strict_types=1);

namespace {$ns}\\admin;

use app\common\service\plugin\PluginService;
use app\common\support\ApiResponse;
use app\common\weapp\PluginAdminBase;
use {$ns}\\service\\{$classId}ConfigService;
use think\Response;

class AdminController
{
    use PluginAdminBase;

    public function __construct()
    {
        \$this->pluginIdentifier = '{$identifier}';
    }

    public function index(): Response
    {
        if (!\$this->entitled()) {
            return \$this->renderDisabled();
        }
        \$manifest = app(\app\common\service\plugin\PluginService::class)->readManifest('{$identifier}') ?? [];
        \$vars = \$this->pluginContext(\$manifest);
        \$vars['cfg'] = {$classId}ConfigService::all();

        return \$this->renderPluginView('index', \$vars);
    }

    public function configSave(): Response
    {
        if (!\$this->entitled()) {
            return ApiResponse::authRequired('插件未授权');
        }
        \$res = {$classId}ConfigService::saveAdmin(request()->post());

        return ApiResponse::fromServiceResult(\$res);
    }

    public function ping(): Response
    {
        return ApiResponse::success(['plugin' => '{$identifier}']);
    }
}

PHP;
        }

        return <<<PHP
<?php
declare(strict_types=1);

namespace {$ns}\\admin;

use app\common\service\plugin\PluginService;
use app\common\support\ApiResponse;
use app\common\weapp\PluginAdminBase;
use think\Response;

class AdminController
{
    use PluginAdminBase;

    public function __construct()
    {
        \$this->pluginIdentifier = '{$identifier}';
    }

    public function index(): Response
    {
        if (!\$this->entitled()) {
            return \$this->renderDisabled();
        }
        \$manifest = app(\app\common\service\plugin\PluginService::class)->readManifest('{$identifier}') ?? [];

        return \$this->renderPluginView('index', \$this->pluginContext(\$manifest));
    }

    public function ping(): Response
    {
        return ApiResponse::success(['plugin' => '{$identifier}']);
    }
}

PHP;
    }

    private function configServicePhp(string $ns, string $identifier, string $classId): string
    {
        $openKey = $identifier . '_open';
        $slotKey = $identifier . '_document_editor_slot';

        return <<<PHP
<?php
declare(strict_types=1);

namespace {$ns}\\service;

use app\common\service\config\ConfigService;
use app\common\service\plugin\extension\PluginEditorSurfaceService;

class {$classId}ConfigService
{
    /** @return list<string> */
    public function keys(): array
    {
        return ['{$openKey}', '{$slotKey}'];
    }

    /** @return array<string, string> */
    public function all(): array
    {
        \$out = [];
        foreach (\$this->keys() as \$key) {
            \$out[\$key] = (string) app(\app\common\service\config\ConfigService::class)->get(\$key, \$this->defaultFor(\$key));
        }
        \$out['{$slotKey}'] = app(PluginEditorSurfaceService::class)->resolveSlot('{$identifier}');

        return \$out;
    }

    public function defaultFor(string \$key): string
    {
        return match (\$key) {
            '{$openKey}' => '1',
            '{$slotKey}' => app(PluginEditorSurfaceService::class)->manifestDefaultSlot('{$identifier}'),
            default => '',
        };
    }

    /** @param array<string, mixed> \$post @return ServiceResult */
    public function saveAdmin(array \$post): ServiceResult
    {
        app(\app\common\service\config\ConfigService::class)->set('{$openKey}', !empty(\$post['{$openKey}']) ? '1' : '0');
        if (array_key_exists('{$slotKey}', \$post)) {
            \$slotRes = app(PluginEditorSurfaceService::class)->saveSiteSlot(
                '{$identifier}',
                (string) (\$post['{$slotKey}'] ?? '')
            );
            if (!\$slotRes->isOk()) {
                return \$slotRes;
            }
        }

        return ServiceResult::ok(null, '保存成功');
    }
}

PHP;
    }

    private function documentTabSyncPhp(string $ns, string $classId): string
    {
        return <<<PHP
<?php
declare(strict_types=1);

namespace {$ns}\\service;

use app\common\support\ServiceResult;

/** 文档产品 Tab 落库后扩展（manifest extensions · product_tab_after_persist） */
final class {$classId}DocumentTabSync
{
    /** @param array<string, mixed> \$ctx */
    public static function afterPersist(array \$ctx): ServiceResult
    {
        return ServiceResult::ok(null, 'skip');
    }
}

PHP;
    }

    private function apiControllerPhp(string $ns, string $identifier): string
    {
        return <<<PHP
<?php
declare(strict_types=1);

namespace {$ns}\\api\\controller;

use app\common\support\ApiResponse;
use think\Response;

/** 前台 API 占位 — 在 route 或 Plugin::boot 中注册路由后实现 */
class Api
{
    public function ping(): Response
    {
        return ApiResponse::success(['plugin' => '{$identifier}']);
    }
}

PHP;
    }

    private function iconSvg(string $name): string
    {
        $label = htmlspecialchars(mb_substr($name, 0, 1), ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64" role="img">
  <rect width="64" height="64" rx="12" fill="#5fb878"/>
  <text x="32" y="40" text-anchor="middle" font-size="28" fill="#fff" font-family="sans-serif">{$label}</text>
</svg>
SVG;
    }

    private function toStudly(string $identifier): string
    {
        $parts = preg_split('/[_-]+/', $identifier) ?: [$identifier];
        $out   = '';
        foreach ($parts as $p) {
            $out .= ucfirst(strtolower($p));
        }

        return $out !== '' ? $out : 'Plugin';
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                LocalFile::unlinkIfExists($path);
            }
        }
        LocalFile::rmdirIfExists($dir);
    }
}
