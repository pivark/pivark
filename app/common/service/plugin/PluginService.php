<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin;

use app\common\service\plugin\package\PluginInstallBackupService;
use app\common\service\plugin\package\PluginInstallPreflightService;
use app\common\service\plugin\package\PluginPackageAuditService;
use app\common\service\plugin\package\PluginPackageService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\lifecycle\PluginDistributionService;
use app\common\service\plugin\lifecycle\PluginUninstallCallbackSandboxService;
use app\common\service\plugin\lifecycle\PluginUpgradeDbSnapshotService;
use app\common\service\plugin\boot\PluginBootRollbackService;
use app\common\service\plugin\boot\PluginBootSandboxService;
use app\common\service\plugin\boot\PluginBootService;
use app\common\service\plugin\gateway\PluginGatewayAuditService;
use app\common\service\plugin\boot\PluginRuntimeFaultGuard;
use app\common\service\plugin\commerce\PluginCommercialPricingService;
use app\common\service\plugin\market\PluginMarketBlocklistService;
use app\common\service\plugin\market\PluginMarketCatalogService;
use app\common\service\plugin\market\PluginMarketShelfDirectory;
use app\common\support\ServiceResult;
use app\common\service\plugin\manifest\PluginDistributionPolicy;
use app\common\service\plugin\seed\CrossPluginAclSeedService;
use app\common\service\plugin\seed\EnterprisePermissionSeedService;
use app\common\service\plugin\seed\NavPersonaPackSeedService;
use app\common\service\plugin\seed\PluginPermissionSeedService;
use app\common\service\plugin\manifest\PluginManifestPolicyDiscovery;
use app\common\service\plugin\manifest\PluginTaxonomyService;
use app\common\service\plugin\registry\PluginApiVersionRegistry;
use app\common\service\plugin\registry\PluginCapabilityService;
use app\common\service\plugin\registry\PluginCapabilitySlotConflictService;
use app\common\service\plugin\weapp\WeappAdminUiService;
use app\common\service\plugin\weapp\WeappPluginDocNavService;
use app\common\model\SchemaMigration;
use app\common\model\Config;

use app\common\service\admin\AdminPluginRouteRegistry;
use app\common\service\admin\AdminPortalService;
use app\common\service\admin\WeappAdminNavRegistry;
use app\common\service\audit\AuditLogService;
use app\common\service\plugin\package\PluginBundledPackageLocator;
use app\common\service\product\ProductL1Access;
use app\common\service\event\EventBusService;
use app\common\service\hook\HookService;
use app\common\service\kernel\KernelModuleRegistry;
use app\common\service\template\TemplateEngine;
use app\common\service\weapp\WeappInstallDemoService;
use app\common\service\weapp\WeappSchemaRunner;
use app\common\model\Plugin;
use app\common\model\Menu;
use app\common\support\AppTime;
use app\common\support\InstallGate;
use app\common\support\LocalFile;
use app\common\support\DbTable;
use app\common\support\OpsLog;
use app\common\support\QueryLimit;
use app\common\support\PluginSqlRunner;
use app\common\support\ProjectPaths;
use think\facade\Db;

/** weapp 插件生命周期 */
class PluginService
{

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly PluginManifestService $pluginManifest,
    ) {
    }

    private function enterprisePermissionSeed(): EnterprisePermissionSeedService
    {
        return app(EnterprisePermissionSeedService::class);
    }

    private function pluginMarketBlocklist(): PluginMarketBlocklistService
    {
        return app(PluginMarketBlocklistService::class);
    }

    /** @var array<string, string> identifier => error message */

    /**
     * 历史常量：仅当 plugin_block_official_upload_replace 开启时，阻止 zip 覆盖这些目录
     * @var list<string>
     */
    /** 已收归内核、不在插件应用中心展示 */
    public const KERNEL_BUILTIN_IDENTIFIERS = ['favorite'];

    /** 原规划为 weapp 插件，能力已并入 Core（禁止 discover/安装） */

    /** @return list<string> */
    public static function uploadReplaceProtectedIdentifiers(): array
    {
        return PluginManifestPolicyDiscovery::mergeIdentifierLists(
            config('pivark.plugin_upload_replace_protected'),
            PluginManifestPolicyDiscovery::uploadReplaceProtectedIdentifiers(),
        );
    }

    /** @return list<string> */
    public static function coreMergedIdentifiers(): array
    {
        $cfg = config('pivark.core_merged_identifiers');

        return is_array($cfg) ? array_values($cfg) : [];
    }

    /** @return list<string> 安装向导默认预装插件（可由 pivark.plugin_install_defaults / .env 配置） */
    public function installDefaultIdentifiers(): array
    {
        return PluginManifestPolicyDiscovery::mergeIdentifierLists(
            config('pivark.plugin_install_defaults'),
            PluginManifestPolicyDiscovery::installDefaultIdentifiers(),
        );
    }

    public function weappRoot(): string
    {
        return ProjectPaths::root() . 'weapp' . DIRECTORY_SEPARATOR;
    }

    /** @var list<array<string, mixed>>|null */
    private static ?array $discoverCache = null;

    /** @var list<array<string, mixed>>|null */
    /** @var array<string, list<array<string, mixed>>> */
    private static array $listAdminCache = [];

    public static function clearListAdminCache(): void
    {
        self::$listAdminCache = [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function discover(): array
    {
        if (self::$discoverCache !== null) {
            return self::$discoverCache;
        }

        $root = $this->weappRoot();
        if (!is_dir($root)) {
            return self::$discoverCache = [];
        }
        $list = [];
        foreach (scandir($root) ?: [] as $dir) {
            if ($dir === '.' || $dir === '..' || !is_dir($root . $dir)) {
                continue;
            }
            if ($this->isKernelBuiltin($dir) || $this->isCoreMerged($dir)) {
                continue;
            }
            $manifest = null;
            try {
                $manifest = $this->readManifest($dir);
            } catch (\Throwable $e) {
                OpsLog::businessWarning('plugin_discover_manifest_failed', [
                    'identifier' => $dir,
                    'msg'        => $e->getMessage(),
                ]);
            }
            if ($manifest !== null) {
                $list[] = $manifest;
            }
        }

        return self::$discoverCache = $list;
    }

    /**
     * @return array<string, mixed>|null
     * @phpstan-impure
     */
    public function readManifest(string $identifier): ?array
    {
        $path = $this->weappRoot() . $identifier . '/plugin.json';
        if (!is_readable($path)) {
            return null;
        }
        $raw = (string) file_get_contents($path);
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return null;
        }

        return $this->pluginManifest->applyValidation(
            app(WeappContext::class)->normalizeManifest($json, $identifier)
        );
    }

    /**
     * 读取 weapp/{id}/composer.json（Phase 4 独立包试点，可选）
     *
     * @return array<string, mixed>|null
     */
    public function readComposerMeta(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }
        $path = $this->weappRoot() . $identifier . '/composer.json';
        if (!is_readable($path)) {
            return null;
        }
        $raw = (string) file_get_contents($path);
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $json = json_decode($raw, true);

        return is_array($json) ? $json : null;
    }

    /** @return array<string, string> identifier => packagist name */
    public function officialComposerBaseline(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }
        $path = ProjectPaths::optionalWorkspaceToolsFile(['ops', 'baselines', 'weapp_composer.json']);
        if ($path === '' || !is_readable($path)) {
            return $cache = [];
        }
        $json = json_decode((string) file_get_contents($path), true);

        return $cache = is_array($json) ? $json : [];
    }

    /**
     * @return array{ok:bool,missing:list<string>,mismatch:list<string>,total:int}
     */
    public function auditOfficialComposer(): array
    {
        $baseline = $this->officialComposerBaseline();
        $missing  = [];
        $mismatch = [];
        foreach ($baseline as $id => $expectedName) {
            $id           = (string) $id;
            $expectedName = (string) $expectedName;
            $meta         = $this->readComposerMeta($id);
            if ($meta === null) {
                $missing[] = $id;
                continue;
            }
            if ((string) ($meta['name'] ?? '') !== $expectedName) {
                $mismatch[] = $id;
                continue;
            }
            $extraId = (string) ($meta['extra']['pivark']['identifier'] ?? '');
            if ($extraId !== '' && $extraId !== $id) {
                $mismatch[] = $id;
            }
        }

        return [
            'ok'       => $missing === [] && $mismatch === [],
            'missing'  => $missing,
            'mismatch' => $mismatch,
            'total'    => count($baseline),
        ];
    }

    /**
     * 后台插件应用页顶栏展示信息（名称、版本、描述、图标等）
     *
     * @return array<string, mixed>|null
     */
    public function presentationForAdmin(string $identifier): ?array
    {
        $identifier = strtolower(trim($identifier));
        $manifest   = $this->readManifest($identifier);
        if ($manifest === null) {
            return $this->presentationForCoreMerged($identifier);
        }
        if (!app(AdminPortalService::class)->pluginAdminVisible($manifest)) {
            return null;
        }
        $row = Plugin::where('identifier', $identifier)->find();
        $full  = self::formatAdminRow($manifest, $this->pluginRow($row), false);

        $iconImage = (string) ($full['icon_image'] ?? '');
        if ($iconImage !== '' && str_starts_with($iconImage, '/weapp/')) {
            if (preg_match('#^/weapp/([a-z][a-z0-9_-]{0,49})/(.+)$#', $iconImage, $m)) {
                $iconImage = \app\common\support\WeappPublicAsset::url($m[1], $m[2]);
            }
        }

        return [
            'identifier'  => (string) ($full['identifier'] ?? $identifier),
            'name'        => (string) ($full['name'] ?? $identifier),
            'version'     => (string) ($full['version'] ?? '1.0.0'),
            'description' => (string) ($full['description'] ?? ''),
            'author'      => (string) ($full['author'] ?? ''),
            'icon_color'  => (string) ($full['icon_color'] ?? '#5fb878'),
            'icon_image'  => $iconImage,
            'composer'    => self::composerPresentation($identifier),
            'doc_tabs'    => app(WeappPluginDocNavService::class)->tabsForAdmin($identifier, $manifest),
            'nav_tabs'    => app(WeappAdminNavRegistry::class)->tabsForAdmin($identifier),
        ];
    }

    /**
     * L1 并入内核、无 weapp manifest 时的后台顶栏展示（product / form 等）
     *
     * @return array<string, mixed>|null
     */
    private function presentationForCoreMerged(string $identifier): ?array
    {
        if (!$this->isCoreMerged($identifier)) {
            return null;
        }
        if (ProductL1Access::isKernel($identifier) && !ProductL1Access::allowsAdmin()) {
            return null;
        }

        $catalog = app(KernelModuleRegistry::class)->catalog();
        $meta    = is_array($catalog[$identifier] ?? null) ? $catalog[$identifier] : [];

        return [
            'identifier'  => $identifier,
            'name'        => (string) ($meta['label'] ?? $identifier),
            'version'     => '1.0.0',
            'description' => (string) ($meta['description'] ?? ''),
            'author'      => 'PivArk',
            'icon_color'  => '#2563eb',
            'icon_image'  => '',
            'composer'    => null,
            'doc_tabs'    => app(WeappPluginDocNavService::class)->tabsForAdmin($identifier, []),
            'nav_tabs'    => app(WeappAdminNavRegistry::class)->tabsForAdmin($identifier),
        ];
    }

    /** @return array<string, string>|null */
    private function composerPresentation(string $identifier): ?array
    {
        $composer = $this->readComposerMeta($identifier);
        if ($composer === null) {
            return null;
        }

        return [
            'name'       => (string) ($composer['name'] ?? ''),
            'type'       => (string) ($composer['type'] ?? ''),
            'identifier' => (string) ($composer['extra']['pivark']['identifier'] ?? $identifier),
        ];
    }

    /**
     * @param bool $lite 插件中心列表：跳过 capabilities 等重字段
     *
     * @return list<array<string, mixed>>
     */
    public function listAdmin(bool $lite = false): array
    {
        $cacheKey = $lite ? 'lite' : 'full';
        if (isset(self::$listAdminCache[$cacheKey])) {
            return self::$listAdminCache[$cacheKey];
        }

        $discovered = [];
        foreach ($this->discover() as $m) {
            $id = (string) ($m['identifier'] ?? '');
            if ($id !== '') {
                $discovered[$id] = $m;
            }
        }
        $rows = Plugin::order('id', 'asc')->limit(QueryLimit::PLUGIN_REGISTRY)->select()->toArray();
        $rowById = [];
        foreach ($rows as $r) {
            $idKey = strtolower(trim((string) ($r['identifier'] ?? '')));
            if ($idKey !== '') {
                $rowById[$idKey] = $r;
            }
        }
        $out  = [];
        foreach ($discovered as $id => $manifest) {
            if (!app(AdminPortalService::class)->pluginAdminVisible($manifest)) {
                continue;
            }
            $row = $rowById[strtolower($id)] ?? null;
            $out[] = self::formatAdminRow($manifest, $row, $lite);
        }

        usort($out, static function (array $a, array $b): int {
            return PluginDistributionPolicy::compareBootstrapOrder(
                (string) ($a['identifier'] ?? ''),
                (string) ($b['identifier'] ?? '')
            );
        });

        return self::$listAdminCache[$cacheKey] = $out;
    }

    /** @return array<string, mixed>|null */
    public function adminRow(string $identifier, bool $lite = false): ?array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return null;
        }
        $manifest = $this->readManifest($identifier);
        if ($manifest === null || !app(AdminPortalService::class)->pluginAdminVisible($manifest)) {
            return null;
        }
        $row = $this->pluginRow(Plugin::where('identifier', $identifier)->find());

        return self::formatAdminRow($manifest, $row, $lite);
    }

    /** @return list<string> */
    public function listInstalledIdentifiers(): array
    {
        if (!InstallGate::isInstalled()) {
            return [];
        }
        $out = [];
        foreach (Plugin::where('installed', 1)->order('id', 'asc')->column('identifier') as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * @return ServiceResult
     */
    public function installFromWeapp(string $identifier): ServiceResult
    {
        $preflight = app(PluginInstallPreflightService::class)->forInstall($identifier);
        if (!$preflight['ok']) {
            return ServiceResult::fail('安装预检未通过：' . implode('；', $preflight['errors']));
        }

        $manifest = $this->readManifest($identifier);
        if ($manifest === null) {
            return ServiceResult::fail('插件不存在或 plugin.json 无效');
        }
        $composer = $this->readComposerMeta($identifier);
        if ($composer !== null) {
            $composerId = (string) ($composer['extra']['pivark']['identifier'] ?? '');
            if ($composerId !== '' && $composerId !== $identifier) {
                return ServiceResult::fail('composer.json extra.pivark.identifier 与插件目录不一致');
            }
        }
        $hardNeedsBlock = app(PluginCapabilityService::class)->installHardNeedsGuardMessage($identifier);
        if ($hardNeedsBlock !== null) {
            return ServiceResult::fail($hardNeedsBlock);
        }
        $now = AppTime::now();
        $commercial = is_array($manifest['commercial'] ?? null) ? $manifest['commercial'] : [];
        $package = (string) ($manifest['package'] ?? '');
        $kind    = (string) ($manifest['kind'] ?? 'document-addon');
        $exists = $this->pluginRow(Plugin::where('identifier', $identifier)->find());
        $priorRow = is_array($exists) ? $exists : null;
        $diskVersion = (string) ($manifest['version'] ?? '1.0.0');
        if ($exists && (int) ($exists['installed'] ?? 0) === 1) {
            $dbVersion = (string) ($exists['version'] ?? '0.0.0');
            if (version_compare($diskVersion, $dbVersion, '<')) {
                return ServiceResult::fail(
                    '磁盘版本 (' . $diskVersion . ') 低于已安装版本 (' . $dbVersion . ')，请使用升级或先卸载',
                );
            }
        }
        $instanceId = (string) ($exists['instance_id'] ?? '');
        if ($instanceId === '') {
            $instanceId = app(WeappContext::class)->generateInstanceId();
        }
        $taxonomyDb = app(PluginTaxonomyService::class)->dbColumnsForInstall($identifier, $manifest);
        $data = [
            'name'             => (string) ($manifest['name'] ?? $identifier),
            'identifier'       => $identifier,
            'package'          => $package,
            'instance_id'      => $instanceId,
            'kind'             => $kind,
            'commercial_tier'  => $taxonomyDb['commercial_tier'],
            'business_domain'  => $taxonomyDb['business_domain'],
            'version'          => (string) ($manifest['version'] ?? '1.0.0'),
            'description'      => (string) ($manifest['description'] ?? ''),
            'author'           => (string) ($manifest['author'] ?? ''),
            'edition'          => (string) ($manifest['edition'] ?? 'community'),
            'commercial_model' => (string) ($commercial['model'] ?? 'free'),
            'price'            => (float) ($commercial['price'] ?? 0),
            'period_days'      => isset($commercial['period_days']) ? (int) $commercial['period_days'] : null,
            'installed'        => 1,
            'enabled'          => 0,
            'status'           => 1,
            'updated_at'       => $now,
        ];

        app(WeappContext::class)->resetCache();

        try {
            self::runPluginSql($identifier, $instanceId);
            app(WeappSchemaRunner::class)->apply($identifier);

            if ($exists) {
                Plugin::where('identifier', $identifier)->update($data);
            } else {
                $data['created_at'] = $now;
                Plugin::insert($data);
            }

            app(PluginBootService::class)->loadPluginClass($identifier)?->install();
            app(WeappInstallDemoService::class)->seedDefaultDataOnWizardInstall($identifier);
            app(PluginPermissionSeedService::class)->syncFromManifest($identifier, $manifest);
            $this->enterprisePermissionSeed()->afterEnterprisePluginInstall($identifier);
            app(CrossPluginAclSeedService::class)->afterEnterprisePluginChange($identifier);
            app(NavPersonaPackSeedService::class)->afterPluginChange($identifier);
            app(EntitlementService::class)->applyInstallPolicy($identifier, $manifest);
            app(KernelModuleRegistry::class)->sync();
        } catch (\Throwable $e) {
            $this->rollbackFailedInstallFromWeapp($identifier, $instanceId, $priorRow, $e);

            return ServiceResult::fail('安装失败并已回滚：' . $e->getMessage());
        }

        $this->auditLogService->operate('安装插件', 'admin.plugin', ['identifier' => $identifier, 'package' => $package]);

        return ServiceResult::ok(['instance_id' => $instanceId, 'reload_admin' => true], '安装成功');
    }

    /**
     * 安装向导：优先 weapp 明文，否则从发行包内 public/static/market/plugins/{id}.zip 解压后安装
     */
    public function installForWizard(string $identifier): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return ServiceResult::fail('插件标识无效');
        }

        if ($this->readManifest($identifier) !== null) {
            return $this->installFromWeapp($identifier);
        }

        $zip = app(PluginBundledPackageLocator::class)->resolveBundledPackagePath($identifier);
        if ($zip === null) {
            return ServiceResult::fail('插件未包含在安装包中：' . $identifier);
        }

        $upload = app(PluginPackageService::class)->installUpload(
            $zip,
            basename($zip),
            false,
            0,
            null,
            PluginPackageAuditService::AUDIT_CONTEXT_MARKET_CATALOG
        );
        if (!$upload->isOk()) {
            return ServiceResult::fail((string) ($upload->message() ?: '插件包安装失败'));
        }

        if ($this->readManifest($identifier) === null) {
            return ServiceResult::fail('插件包解压后仍无法读取 plugin.json：' . $identifier);
        }

        return $this->installFromWeapp($identifier);
    }

    /**
     * installFromWeapp 后半段失败时尽量回滚至安装前（不删 weapp 目录）。
     *
     * @param array<string, mixed>|null $priorRow 写入 plugins 表前的行快照；null 表示此前无行
     */
    private function rollbackFailedInstallFromWeapp(
        string $identifier,
        string $instanceId,
        ?array $priorRow,
        \Throwable $cause
    ): void {
        OpsLog::businessWarning('plugin_install_from_weapp_rollback', [
            'identifier'  => $identifier,
            'instance_id' => $instanceId,
            'error'       => $cause->getMessage(),
            'exception'   => $cause::class,
        ]);

        try {
            app(PluginBootService::class)->loadPluginClass($identifier)?->uninstall();
        } catch (\Throwable $inner) {
            OpsLog::businessWarning('plugin_install_rollback_uninstall_callback_failed', [
                'identifier' => $identifier,
                'error'      => $inner->getMessage(),
            ]);
        }

        try {
            app(WeappSchemaRunner::class)->rollback($identifier, 0);
        } catch (\Throwable $inner) {
            OpsLog::businessWarning('plugin_install_rollback_schema_failed', [
                'identifier' => $identifier,
                'error'      => $inner->getMessage(),
            ]);
        }

        try {
            self::runPluginUninstallSql($identifier, $instanceId);
        } catch (\Throwable $inner) {
            OpsLog::businessWarning('plugin_install_rollback_uninstall_sql_failed', [
                'identifier' => $identifier,
                'error'      => $inner->getMessage(),
            ]);
        }

        try {
            app(WeappSchemaRunner::class)->purgeVersionRecord($identifier);
        } catch (\Throwable $inner) {
            OpsLog::businessWarning('plugin_install_rollback_purge_version_failed', [
                'identifier' => $identifier,
                'error'      => $inner->getMessage(),
            ]);
        }

        try {
            app(EntitlementService::class)->revoke($identifier);
        } catch (\Throwable $inner) {
            OpsLog::businessWarning('plugin_install_rollback_revoke_failed', [
                'identifier' => $identifier,
                'error'      => $inner->getMessage(),
            ]);
        }

        try {
            app(PluginPermissionSeedService::class)->purgeForIdentifier($identifier);
        } catch (\Throwable $inner) {
            OpsLog::businessWarning('plugin_install_rollback_purge_perm_failed', [
                'identifier' => $identifier,
                'error'      => $inner->getMessage(),
            ]);
        }

        try {
            if ($priorRow === null) {
                Plugin::where('identifier', $identifier)->delete();
            } else {
                $restore = $priorRow;
                unset($restore['id']);
                Plugin::where('identifier', $identifier)->update($restore);
            }
        } catch (\Throwable $inner) {
            OpsLog::businessWarning('plugin_install_rollback_register_failed', [
                'identifier' => $identifier,
                'error'      => $inner->getMessage(),
            ]);
        }

        try {
            app(WeappContext::class)->resetCache();
            app(PluginBootService::class)->resetBooted();
            app(KernelModuleRegistry::class)->sync();
        } catch (\Throwable $inner) {
            OpsLog::businessWarning('plugin_install_rollback_cache_sync_failed', [
                'identifier' => $identifier,
                'error'      => $inner->getMessage(),
            ]);
        }
    }

    /**
     * 升级已安装插件：重跑 install.sql、调用 Plugin::upgrade()（若有）、同步 manifest 版本。
     *
     * @return ServiceResult
     */
    public function upgrade(string $identifier): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return ServiceResult::fail('插件标识无效');
        }
        $manifest = $this->readManifest($identifier);
        if ($manifest === null || empty($manifest['_manifest_valid'])) {
            return ServiceResult::fail('插件不存在或 plugin.json 无效');
        }

        $row = $this->pluginRow(Plugin::where('identifier', $identifier)->where('installed', 1)->find());
        if ($row === null) {
            return ServiceResult::fail('请先安装插件');
        }

        $instanceId = (string) ($row['instance_id'] ?? '');
        if ($instanceId === '') {
            $instanceId = app(WeappContext::class)->instanceId($identifier);
        }

        $preflight = app(PluginInstallPreflightService::class)->forUpgrade($identifier);
        if (!$preflight['ok']) {
            return ServiceResult::fail('升级预检未通过：' . implode('；', $preflight['errors']));
        }

        $diskVersion = (string) ($manifest['version'] ?? '1.0.0');
        $dbVersion   = (string) ($row['version'] ?? '0.0.0');
        if (version_compare($diskVersion, $dbVersion, '<')) {
            return ServiceResult::fail('磁盘版本 (' . $diskVersion . ') 低于已安装版本 (' . $dbVersion . ')，请使用升级或先卸载');
        }
        if (version_compare($diskVersion, $dbVersion, '==')) {
            return ServiceResult::ok(['reload_admin' => false], '已是目标版本 v' . $diskVersion);
        }

        $weappDir = $this->weappRoot() . $identifier;
        $backup   = app(PluginInstallBackupService::class)->archiveWeappDirectory($identifier, $weappDir);
        $sqlSnap  = app(PluginUpgradeDbSnapshotService::class)->snapshotSqlBeforeUpgrade($identifier);
        $tableSnap = $sqlSnap === null
            ? app(PluginUpgradeDbSnapshotService::class)->snapshotBeforeUpgrade($identifier)
            : null;

        try {
            self::runPluginSql($identifier, $instanceId);
            self::runPluginUpgradeSql($identifier, $dbVersion, $diskVersion, $instanceId);
            app(WeappSchemaRunner::class)->apply($identifier);
            $plugin = app(PluginBootService::class)->loadPluginClass($identifier);
            if ($plugin !== null && method_exists($plugin, 'upgrade')) {
                $plugin->upgrade();
            }
        } catch (\Throwable $e) {
            if ($backup !== null) {
                $filename = basename($backup);
                app(PluginInstallBackupService::class)->restoreFromArchive($identifier, $filename);
            }
            if ($tableSnap !== null || $sqlSnap !== null) {
                app(PluginUpgradeDbSnapshotService::class)->restoreBestEffort($identifier, $sqlSnap, $tableSnap);
            }

            return ServiceResult::fail('升级失败已尝试还原备份：' . $e->getMessage());
        }

        $commercial = is_array($manifest['commercial'] ?? null) ? $manifest['commercial'] : [];
        Plugin::where('identifier', $identifier)->update([
            'name'             => (string) ($manifest['name'] ?? $identifier),
            'version'          => $diskVersion,
            'description'      => (string) ($manifest['description'] ?? ''),
            'author'           => (string) ($manifest['author'] ?? ''),
            'package'          => (string) ($manifest['package'] ?? ($row['package'] ?? '')),
            'kind'             => (string) ($manifest['kind'] ?? ($row['kind'] ?? 'document-addon')),
            'edition'          => (string) ($manifest['edition'] ?? 'community'),
            'commercial_model' => (string) ($commercial['model'] ?? 'free'),
            'updated_at'       => AppTime::now(),
        ]);
        app(PluginPermissionSeedService::class)->syncFromManifest($identifier, $manifest);
        $this->enterprisePermissionSeed()->afterEnterprisePluginInstall($identifier);
        app(CrossPluginAclSeedService::class)->afterEnterprisePluginChange($identifier);
        app(NavPersonaPackSeedService::class)->afterPluginChange($identifier);

        app(WeappContext::class)->resetCache();
        app(PluginBootService::class)->resetBooted();

        $this->auditLogService->operate('升级插件', 'admin.plugin', [
            'identifier' => $identifier,
            'from'       => $dbVersion,
            'to'         => $diskVersion,
        ]);

        return ServiceResult::ok(['reload_admin' => true], '已升级至 v' . $diskVersion);
    }

    /**
     * 已安装但数据表缺失时（如 purge=data 后未重装 SQL）补跑 install.sql + schema 台阶
     *
     * @return ServiceResult
     */
    public function healPluginDataPlane(string $identifier): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return ServiceResult::fail('插件标识无效');
        }
        $row = $this->pluginRow(Plugin::where('identifier', $identifier)->where('installed', 1)->find());
        if ($row === null) {
            return ServiceResult::fail('插件未安装');
        }
        $instanceId = (string) ($row['instance_id'] ?? '');
        if ($instanceId === '') {
            $instanceId = app(WeappContext::class)->instanceId($identifier);
        }
        try {
            self::registerAutoloadPublic($identifier);
            self::runPluginSql($identifier, $instanceId);
            app(WeappSchemaRunner::class)->apply($identifier);
        } catch (\Throwable $e) {
            return ServiceResult::fail('数据平面修复失败：' . $e->getMessage());
        }

        return ServiceResult::ok(null, 'install.sql + schema 已补跑');
    }

    /**
     * @param array<string, mixed>|null $row
     * @param array<string, mixed> $manifest
     */
    public function needsUpgrade(string $identifier, ?array $row, array $manifest): bool
    {
        if ($row === null || empty($row['installed'])) {
            return false;
        }
        $diskVersion = (string) ($manifest['version'] ?? '1.0.0');
        $dbVersion   = (string) ($row['version'] ?? '0.0.0');

        return version_compare($diskVersion, $dbVersion, '>');
    }

    /**
     * 远程 Feed 版本高于本站已装版本（已安装「检查更新」/卡片升级）
     *
     * @param array<string, mixed>|null $row
     * @param array<string, mixed>      $manifest
     */
    public function marketNeedsUpgrade(string $identifier, ?array $row, array $manifest): bool
    {
        if ($row === null || empty($row['installed'])) {
            return false;
        }
        $remoteVer = trim(app(PluginMarketShelfDirectory::class)->remoteVersion($identifier));
        if ($remoteVer === '') {
            return false;
        }
        $localVer = trim((string) ($row['version'] ?? ''));
        if ($localVer === '') {
            $localVer = trim((string) ($manifest['version'] ?? ''));
        }
        if ($localVer === '') {
            return false;
        }

        return app(PluginMarketShelfDirectory::class)->versionNewer($remoteVer, $localVer);
    }

    /**
     * @return ServiceResult
     */
    public function enable(string $identifier, bool $ackSlotConflict = false): ServiceResult
    {
        app(EntitlementService::class)->refreshExpiredStatuses();
        if (!app(EntitlementService::class)->can($identifier)) {
            $manifest = $this->readManifest($identifier);
            $label    = trim((string) ($manifest['name'] ?? $identifier));
            if ($label === '') {
                $label = $identifier;
            }

            return ServiceResult::fail('插件「' . $label . '」未授权，请先在「我的插件」手动授权后再启用');
        }
        $row = $this->pluginRow(Plugin::where('identifier', $identifier)->where('installed', 1)->find());
        if ($row === null) {
            return ServiceResult::fail('请先安装插件');
        }
        if ($this->pluginMarketBlocklist()->isBlocked($identifier)) {
            return ServiceResult::fail('插件已远程下架，无法启用：' . $this->pluginMarketBlocklist()->blockReason($identifier));
        }

        $capabilityBlock = app(PluginCapabilityService::class)->enableGuardMessage($identifier);
        if ($capabilityBlock !== null) {
            return ServiceResult::fail($capabilityBlock);
        }

        $commerceBlock = app(PluginCapabilitySlotConflictService::class)->commerceEnableGuardMessage($identifier);
        if ($commerceBlock !== null) {
            return ServiceResult::fail($commerceBlock);
        }

        $slotConflict = app(PluginCapabilitySlotConflictService::class)->enableGuardResult($identifier, $ackSlotConflict);
        if ($slotConflict !== null) {
            return $slotConflict;
        }

        try {
            app(PluginApiVersionRegistry::class)->assertCompatible($identifier);
        } catch (\RuntimeException $e) {
            return ServiceResult::fail($e->getMessage());
        }

        $gatewayScopes = app(PluginGatewayAuditService::class)->normalizeScopes('service,api,admin,boot');
        $gatewayTotal  = 0;
        foreach ($gatewayScopes as $scope) {
            $report = app(PluginGatewayAuditService::class)->auditPluginDirectory($identifier, $scope);
            $gatewayTotal += $report['total'];
        }
        if ($gatewayTotal > 0 && (bool) config('plugin.security.gateway_direct_service_block', false)) {
            return ServiceResult::fail('Gateway 审计未通过（' . $gatewayTotal . ' 处违规），请先整改');
        }

        $sandboxOnEnable = (bool) config('plugin.security.sandbox_boot_on_enable', true);
        if ($sandboxOnEnable) {
            $trial = app(PluginBootSandboxService::class)->trialBoot($identifier);
            if (!$trial->isOk()) {
                return ServiceResult::fail((string) ($trial->message() ?? '启用前沙箱试 boot 失败'));
            }
        }

        Plugin::where('identifier', $identifier)->update([
            'enabled'    => 1,
            'updated_at' => AppTime::now(),
        ]);
        app(PluginBootService::class)->loadPluginClass($identifier)?->enable();
        app(KernelModuleRegistry::class)->sync();

        if (
            !$sandboxOnEnable
            && (bool) config('plugin.security.health_check_on_enable', true)
            && !app(PluginBootService::class)->isBooted()
        ) {
            try {
                app(PluginBootService::class)->loadPluginClass($identifier)?->boot();
            } catch (\Throwable $e) {
                Plugin::where('identifier', $identifier)->update([
                    'enabled'    => 0,
                    'updated_at' => AppTime::now(),
                ]);
                $this->auditLogService->operate('启用自检失败自动停用', 'admin.plugin', [
                    'identifier' => $identifier,
                    'error'      => $e->getMessage(),
                ]);

                return ServiceResult::fail('启用后自检失败，已自动停用：' . $e->getMessage());
            }
        }

        $this->auditLogService->operate('启用插件', 'admin.plugin', ['identifier' => $identifier]);
        app(PluginCapabilityService::class)->refreshEntitlementSnapshot($identifier);
        app(PluginBootService::class)->clearBootFailure($identifier);
        app(PluginBootService::class)->resetBooted();
        app(\app\common\service\admin\AdminSpaMenuRouteCacheService::class)->bustAll();

        return ServiceResult::ok(['reload_admin' => true], '已启用');
    }

    /**
     * @return ServiceResult
     */
    public function disable(string $identifier): ServiceResult
    {
        $hostDisableBlock = app(PluginDistributionPolicy::class)->disableBlockedMessage($identifier);
        if ($hostDisableBlock !== null) {
            return ServiceResult::fail($hostDisableBlock);
        }
        Plugin::where('identifier', $identifier)->update([
            'enabled'    => 0,
            'updated_at' => AppTime::now(),
        ]);
        app(PluginBootService::class)->loadPluginClass($identifier)?->disable();
        app(PluginBootRollbackService::class)->purgeForIdentifier($identifier);
        app(PluginBootService::class)->resetBooted();
        app(WeappContext::class)->resetCache();
        app(AdminPluginRouteRegistry::class)->applyOnce(true);
        $this->auditLogService->operate('停用插件', 'admin.plugin', ['identifier' => $identifier]);
        app(\app\common\service\admin\AdminSpaMenuRouteCacheService::class)->bustAll();

        return ServiceResult::ok(null, '已停用');
    }

    /** 已安装、已启用、已授权且 weapp 目录存在（与 bootstrapEnabled 条件一致） */
    public function isEnabled(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || !is_dir($this->weappRoot() . $identifier)) {
            return false;
        }
        if (app(PluginBootService::class)->runtimeBootFailure($identifier) !== '') {
            return false;
        }
        if (!app(EntitlementService::class)->can($identifier)) {
            return false;
        }
        $row = $this->pluginRow(Plugin::where('identifier', $identifier)->where('installed', 1)->find());

        return $row !== null && (int) ($row['enabled'] ?? 0) === 1;
    }

    /** manifest 为官方 pivark 发布（用于展示「官方插件」徽章，不等于不可卸载） */
    public function isOfficialPublisherPlugin(string $identifier): bool
    {
        if ($identifier === '') {
            return false;
        }
        $manifest = $this->readManifest($identifier);

        return $manifest !== null && $this->pluginManifest->packageVendor($manifest) === 'pivark';
    }

    /** 是否禁止卸载（仅显式配置，开源版默认可卸载官方插件） */
    public function blocksUninstall(string $identifier): bool
    {
        if ($identifier === '') {
            return false;
        }
        $cfg = config('pivark.plugin_uninstall_blocked');

        return is_array($cfg) && in_array($identifier, $cfg, true);
    }

    /** 脚手架创建前：是否不可占用该 identifier（卸载保护或官方包覆盖限制） */
    public function blocksScaffoldOverwrite(string $identifier): bool
    {
        return $this->blocksUninstall($identifier) || $this->blocksOfficialPackageUpload($identifier);
    }

    /** 是否禁止插件市场上传 zip 覆盖已有官方包目录 */
    public function blocksOfficialPackageUpload(string $identifier): bool
    {
        if ($identifier === '') {
            return false;
        }
        if (!config('pivark.plugin_block_official_upload_replace')) {
            return false;
        }
        if (in_array($identifier, self::uploadReplaceProtectedIdentifiers(), true)) {
            return is_dir($this->weappRoot() . $identifier);
        }

        return $this->isOfficialPublisherPlugin($identifier) && is_dir($this->weappRoot() . $identifier);
    }

    /**
     * @param string $purge register | config | data | full（data + 删除 weapp 目录）
     * @return ServiceResult
     */
    public function uninstall(string $identifier, string $purge = 'register'): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return ServiceResult::fail('插件标识无效');
        }
        if ($this->blocksUninstall($identifier)) {
            return ServiceResult::fail('该插件已配置为不可卸载');
        }
        $hostUninstallBlock = app(PluginDistributionPolicy::class)->uninstallBlockedMessage($identifier);
        if ($hostUninstallBlock !== null) {
            return ServiceResult::fail($hostUninstallBlock);
        }
        $dependentsBlock = app(PluginCapabilityService::class)->uninstallGuardMessage($identifier);
        if ($dependentsBlock !== null) {
            return ServiceResult::fail($dependentsBlock);
        }
        $purge = strtolower($purge);
        if (!in_array($purge, ['register', 'config', 'data', 'full'], true)) {
            return ServiceResult::fail('purge 无效，仅允许 register、config、data、full');
        }

        $row = $this->pluginRow(Plugin::where('identifier', $identifier)->where('installed', 1)->find());
        if ($row === null) {
            return ServiceResult::fail('插件未安装');
        }
        if ((int) ($row['enabled'] ?? 0) === 1) {
            $this->disable($identifier);
        }

        $callbackWarning = '';
        $preBackup       = null;
        if (in_array($purge, ['data', 'full'], true)) {
            $srcDir = $this->weappRoot() . $identifier;
            if (is_dir($srcDir)) {
                $preBackup = app(PluginInstallBackupService::class)->archiveWeappDirectory($identifier, $srcDir);
            }
        }

        try {
            if ((bool) config('plugin.security.uninstall_callback_sandbox', true)) {
                $sandbox = app(PluginUninstallCallbackSandboxService::class)->invoke($identifier);
                if (!$sandbox->isOk()) {
                    throw new \RuntimeException((string) ($sandbox->message() ?? '卸载回调失败'));
                }
            } else {
                PluginRuntimeFaultGuard::invoke(
                    static fn () => app(PluginBootService::class)->loadPluginClass($identifier)?->uninstall(),
                    'plugin_uninstall_callback_failed',
                    ['identifier' => $identifier],
                    null
                );
            }
        } catch (\Throwable $e) {
            $callbackWarning = trim($e->getMessage());
            OpsLog::businessWarning('plugin_uninstall_callback_failed', [
                'identifier' => $identifier,
                'msg'        => $callbackWarning,
            ]);
            if ((bool) config('plugin.security.uninstall_callback_block_on_fail', false)) {
                return ServiceResult::fail('卸载回调失败已阻断：' . $callbackWarning);
            }
        }

        $instanceId = (string) ($row['instance_id'] ?? '');

        if (in_array($purge, ['config', 'data', 'full'], true)) {
            self::purgePluginConfigs($identifier);
        }
        if ($purge === 'data' || $purge === 'full') {
            app(WeappSchemaRunner::class)->rollback($identifier, 0);
            self::runPluginUninstallSql($identifier, $instanceId);
            $this->purgePluginDataTables($identifier);
            app(WeappSchemaRunner::class)->purgeVersionRecord($identifier);
        }

        Db::transaction(function () use ($identifier): void {
            app(EntitlementService::class)->revoke($identifier);
            Plugin::where('identifier', $identifier)->delete();
        });
        app(PluginPermissionSeedService::class)->purgeForIdentifier($identifier);
        app(CrossPluginAclSeedService::class)->afterEnterprisePluginChange($identifier);
        app(NavPersonaPackSeedService::class)->afterPluginChange($identifier);
        self::purgePluginAdminMenus($identifier);
        if ($purge === 'full') {
            self::removeWeappDirectory($identifier);
            self::removeDocumentEditorPartials($identifier);
        }

        app(WeappContext::class)->resetCache();
        app(PluginBootService::class)->clearBootFailure($identifier);
        app(PluginBootService::class)->resetBooted();
        PluginMarketCatalogService::flushListCache();

        $this->auditLogService->operate('卸载插件', 'admin.plugin', [
            'identifier' => $identifier,
            'purge'      => $purge,
            'callback_warning' => $callbackWarning !== '' ? $callbackWarning : null,
        ]);

        $msg = '已卸载（档位：' . $purge . '）';
        if (is_string($preBackup) && $preBackup !== '') {
            $msg .= '；已自动备份至 ' . $preBackup;
        }
        if ($callbackWarning !== '') {
            $msg .= '；插件 uninstall 回调异常（内核已兜底清理菜单/权限）：' . $callbackWarning;
        }

        return ServiceResult::ok(null, $msg);
    }

    private function removeWeappDirectory(string $identifier): void
    {
        if ($this->blocksUninstall($identifier)) {
            return;
        }
        $dir = $this->weappRoot() . $identifier;
        if (!is_dir($dir)) {
            return;
        }
        // 开发仓（含 .git）上的官方插件明文是 SSOT，purge=full 不得擦盘（否则 lifecycle 误卸装会删掉 weapp/doc_bundle）
        $projectRoot = rtrim(str_replace('\\', '/', (string) ProjectPaths::root()), '/');
        if (is_dir($projectRoot . '/.git') && $this->isOfficialPublisherPlugin($identifier)) {
            OpsLog::businessWarning('plugin_uninstall_skip_weapp_wipe_git_official', [
                'identifier' => $identifier,
            ]);

            return;
        }
        $root = realpath($this->weappRoot());
        $target = realpath($dir);
        if ($root === false || $target === false || !str_starts_with($target, $root . DIRECTORY_SEPARATOR)) {
            return;
        }
        self::removeDirRecursive($target);
    }

    private function removeDocumentEditorPartials(string $identifier): void
    {
        $slug = str_replace('-', '_', $identifier);
        $dir  = ProjectPaths::root() . 'app' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'view'
            . DIRECTORY_SEPARATOR . 'document' . DIRECTORY_SEPARATOR;
        foreach (['_inline', '_tab'] as $suffix) {
            $path = $dir . '_weapp_' . $slug . $suffix . '.php';
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function removeDirRecursive(string $dir): void
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
                self::removeDirRecursive($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }

    private function purgePluginConfigs(string $identifier): void
    {
        foreach (self::pluginConfigKeyPrefixes($identifier) as $prefix) {
            $like = addcslashes($prefix, '%_\\') . '%';
            Config::where('key', 'like', $like)->delete();
        }
    }

    /**
     * 插件 configs 键前缀（identifier 含连字符时，业务键常用下划线，如 social-auth → social_auth_）
     *
     * @return list<string>
     */
    public static function pluginConfigKeyPrefixes(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return [];
        }
        $out = [$identifier . '_'];
        $underscore = str_replace('-', '_', $identifier);
        if ($underscore !== $identifier) {
            $out[] = $underscore . '_';
        }
        try {
            $manifest = app(self::class)->readManifest($identifier);
            if (is_array($manifest)) {
                foreach ((array) ($manifest['config_key_prefixes'] ?? []) as $extra) {
                    $extra = trim((string) $extra);
                    if ($extra !== '') {
                        $out[] = $extra;
                    }
                }
            }
        } catch (\Throwable) {
            // manifest 不可读时仅 identifier 前缀
        }

        return array_values(array_unique($out));
    }

    /**
     * purge=data/full：DROP 插件业务表（model / install.sql / uninstall.sql / Db::name 扫描）
     */
    private function purgePluginDataTables(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        $pfx = (string) config('database.connections.mysql.prefix');
        foreach ($this->discoverPluginDataTables($identifier) as $table) {
            $full = str_starts_with($table, $pfx) ? $table : $pfx . $table;
            try {
                Db::execute('DROP TABLE IF EXISTS `' . str_replace('`', '``', $full) . '`');
            } catch (\Throwable $e) {
                OpsLog::businessWarning('plugin_purge_drop_table_failed', [
                    'identifier' => $identifier,
                    'table'      => $full,
                    'msg'        => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return list<string> 表名（不含库前缀）
     */
    public function discoverPluginDataTables(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || PluginDistributionPolicy::isHostOnly($identifier)) {
            return [];
        }

        $tables = [];
        // 用本文件所在仓根（lane ROOT 重定向时 ProjectPaths::root 会指到 b/test，缺 SSOT）
        $codeRoot = dirname(__DIR__, 4);
        $legacyFile = $codeRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'plugin'
            . DIRECTORY_SEPARATOR . 'uninstall_legacy_tables.php';
        if (is_readable($legacyFile)) {
            /** @var mixed $map */
            $map = require $legacyFile;
            if (is_array($map) && isset($map[$identifier]) && is_array($map[$identifier])) {
                foreach ($map[$identifier] as $t) {
                    $t = strtolower(trim((string) $t));
                    if ($t !== '') {
                        $tables[] = $t;
                    }
                }
            }
        }

        $weappDir = $this->weappRoot() . $identifier;
        $slugUnderscore = str_replace('-', '_', $identifier);
        $slugCompact = str_replace('-', '', $identifier);

        if (is_dir($weappDir)) {
            foreach (glob($weappDir . '/model/*.php') ?: [] as $file) {
                $content = (string) file_get_contents($file);
                if (preg_match("/protected\s+\\\$name\s*=\s*'([a-z][a-z0-9_]*)'/", $content, $m)) {
                    $tables[] = $m[1];
                }
            }

            foreach (['install.sql', 'uninstall.sql'] as $sqlName) {
                $sqlFile = $weappDir . '/database/' . $sqlName;
                if (!is_readable($sqlFile)) {
                    continue;
                }
                $content = (string) file_get_contents($sqlFile);
                if (preg_match_all('/`(?:\{\{prefix\}\})?([a-z][a-z0-9_]*)`/i', $content, $m) && is_array($m[1])) {
                    foreach ($m[1] as $table) {
                        $table = strtolower((string) $table);
                        if (
                            str_starts_with($table, 'weapp_')
                            || str_starts_with($table, 'ai_')
                            || str_contains($table, $slugUnderscore)
                            || str_contains($table, $slugCompact)
                        ) {
                            $tables[] = $table;
                        }
                    }
                }
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($weappDir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                    continue;
                }
                $content = (string) file_get_contents($fileInfo->getPathname());
                if (!preg_match_all("/Db::name\('([a-z][a-z0-9_]*)'\)/", $content, $m) || !is_array($m[1])) {
                    continue;
                }
                foreach ($m[1] as $table) {
                    $table = strtolower((string) $table);
                    if (str_starts_with($table, 'weapp_') || str_contains($table, $slugUnderscore)) {
                        $tables[] = $table;
                    }
                }
            }
        }

        // 库内物理前缀：weapp_{slug}_*（无目录的幽灵标也能卸表）
        $pfx = (string) config('database.connections.mysql.prefix');
        try {
            $like = $pfx . 'weapp_' . $slugUnderscore . '\_%';
            $exact = $pfx . 'weapp_' . $slugUnderscore;
            $rows = Db::query(
                'SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
                . ' AND (TABLE_NAME LIKE ? ESCAPE \'\\\\\' OR TABLE_NAME = ?)',
                [$like, $exact],
            );
            foreach ($rows as $row) {
                $full = (string) ($row['t'] ?? $row['TABLE_NAME'] ?? '');
                if ($full === '' || $full === $pfx . 'weapp_plugin_schema_versions') {
                    continue;
                }
                $logical = str_starts_with($full, $pfx) ? substr($full, strlen($pfx)) : $full;
                $tables[] = $logical;
            }
        } catch (\Throwable) {
            // 安装早期无库时忽略
        }

        $tables = array_values(array_unique(array_filter($tables, static fn ($t) => is_string($t) && $t !== '')));
        sort($tables);

        return $tables;
    }

    private function runPluginUninstallSql(string $identifier, string $instanceId = ''): void
    {
        $sqlFile = $this->weappRoot() . $identifier . '/database/uninstall.sql';
        if (!is_readable($sqlFile)) {
            return;
        }
        if ($instanceId === '') {
            $instanceId = app(WeappContext::class)->instanceId($identifier);
        }
        $sql = app(WeappContext::class)->substituteSql((string) file_get_contents($sqlFile), $identifier, $instanceId);
        PluginSqlRunner::executeBatch($sql, $identifier);
    }

    private function runPluginSql(string $identifier, string $instanceId = ''): void
    {
        $sqlFile = $this->weappRoot() . $identifier . '/database/install.sql';
        if (!is_readable($sqlFile)) {
            return;
        }
        if ($instanceId === '') {
            $instanceId = app(WeappContext::class)->instanceId($identifier);
        }
        self::executePluginSqlFile($sqlFile, $identifier, $instanceId);
    }

    /**
     * 执行 weapp/{id}/database/upgrade/*.sql（文件名须为语义化版本，如 1.0.1.sql）
     * 仅执行 (dbVersion, diskVersion] 区间内未记录在 schema_migrations 的脚本。
     */
    private function runPluginUpgradeSql(
        string $identifier,
        string $fromVersion,
        string $toVersion,
        string $instanceId = ''
    ): void {
        $dir = $this->weappRoot() . $identifier . '/database/upgrade';
        if (!is_dir($dir)) {
            return;
        }
        if ($instanceId === '') {
            $instanceId = app(WeappContext::class)->instanceId($identifier);
        }

        $files = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (!preg_match('/^(.+)\.sql$/i', $name, $m)) {
                continue;
            }
            $ver = trim($m[1]);
            if ($ver === '' || !preg_match('/^\d+\.\d+(\.\d+)?$/', $ver)) {
                continue;
            }
            if (version_compare($ver, $fromVersion, '<=') || version_compare($ver, $toVersion, '>')) {
                continue;
            }
            $files[$ver] = $dir . DIRECTORY_SEPARATOR . $name;
        }
        if ($files === []) {
            return;
        }
        uksort($files, static fn (string $a, string $b): int => version_compare($a, $b));

        foreach ($files as $ver => $path) {
            $migrationName = 'plugin:' . $identifier . ':' . $ver;
            if (self::pluginMigrationApplied($migrationName)) {
                continue;
            }
            self::executePluginSqlFile($path, $identifier, $instanceId);
            self::markPluginMigrationApplied($migrationName);
        }
    }

    private function executePluginSqlFile(string $sqlFile, string $identifier, string $instanceId): void
    {
        if (!is_readable($sqlFile)) {
            return;
        }
        $sql = app(WeappContext::class)->substituteSql((string) file_get_contents($sqlFile), $identifier, $instanceId);
        PluginSqlRunner::executeBatch($sql, $identifier);
    }

    private function pluginMigrationApplied(string $name): bool
    {
        if (!self::schemaMigrationsTableExists()) {
            return false;
        }

        return SchemaMigration::where('name', $name)->count() > 0;
    }

    private function markPluginMigrationApplied(string $name): void
    {
        if (!self::schemaMigrationsTableExists()) {
            return;
        }
        $now = AppTime::now();
        SchemaMigration::insert([
            'name'       => $name,
            'created_at' => $now,
        ]);
    }

    private function schemaMigrationsTableExists(): bool
    {
        return DbTable::modelExists(SchemaMigration::class);
    }

    public function isKernelBuiltin(string $identifier): bool
    {
        return in_array(strtolower(trim($identifier)), self::KERNEL_BUILTIN_IDENTIFIERS, true);
    }

    public function isCoreMerged(string $identifier): bool
    {
        return in_array(strtolower(trim($identifier)), self::coreMergedIdentifiers(), true);
    }

    /** 永久 L1 内核模块（config/kernel/l1_modules.php） */
    public function isL1KernelModule(string $identifier): bool
    {
        return in_array(strtolower(trim($identifier)), $this->l1KernelModuleIds(), true);
    }

    /**
     * 已并入内核 / L1 的能力，不应出现在插件中心「应用市场」
     */
    public function isPermanentKernelSurface(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));

        return $identifier !== ''
            && ($this->isKernelBuiltin($identifier)
                || $this->isCoreMerged($identifier)
                || $this->isL1KernelModule($identifier));
    }

    /** 插件中心应用市场是否展示（host_only 发行 / L1 / 已合并内核 / 已下架均隐藏） */
    public function marketCatalogVisible(string $identifier): bool
    {
        if (!PluginDistributionPolicy::marketCatalogVisible($identifier)) {
            return false;
        }
        if ($this->isCatalogRetired($identifier)) {
            return false;
        }

        return !$this->isPermanentKernelSurface($identifier);
    }

    public function isCatalogRetired(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return false;
        }

        return in_array($identifier, $this->catalogRetiredIdentifiers(), true);
    }

    /** @return list<string> */
    public function catalogRetiredIdentifiers(): array
    {
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }
        $cfg = config('plugin.market.catalog_retired_identifiers');
        if (!is_array($cfg)) {
            return $memo = [];
        }
        $memo = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => strtolower(trim((string) $id)),
            $cfg
        ))));

        return $memo;
    }

    /** @return list<string> */
    private function l1KernelModuleIds(): array
    {
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }
        $cfg = config('kernel.l1_modules');
        if (!is_array($cfg)) {
            return $memo = [];
        }
        $memo = array_values(array_filter(array_map(
            static fn ($id): string => strtolower(trim((string) $id)),
            $cfg
        )));

        return $memo;
    }

    /**
     * @param array<string, mixed>      $manifest
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    private function formatAdminRow(array $manifest, ?array $row, bool $lite = false): array
    {
        $id = (string) ($manifest['identifier'] ?? '');
        $commercial = is_array($manifest['commercial'] ?? null) ? $manifest['commercial'] : [];
        $admin = is_array($manifest['admin'] ?? null) ? $manifest['admin'] : [];
        $visual = self::visualForPlugin($id, $manifest);

        $out = [
            'registry_id'      => (int) ($row['id'] ?? 0),
            'identifier'       => $id,
            'name'             => (string) ($manifest['name'] ?? $id),
            'version'          => (string) ($manifest['version'] ?? '1.0.0'),
            /** 库表已装版本（检查更新对比用；卡片展示优先于此，避免磁盘已新库未升时看起来「无更新」） */
            'installed_version' => $row !== null ? trim((string) ($row['version'] ?? '')) : '',
            'description'      => (string) ($manifest['description'] ?? ''),
            'author'           => (string) ($manifest['author'] ?? ($row['author'] ?? '')),
            'author_contact'   => (string) ($manifest['author_contact'] ?? $manifest['contact'] ?? ''),
            'commercial_model' => (string) ($commercial['model'] ?? 'free'),
            'installed'        => (int) ($row['installed'] ?? 0),
            'enabled'          => (int) ($row['enabled'] ?? 0),
            'entitled'         => app(EntitlementService::class)->can($id),
            'entitlement'      => app(EntitlementService::class)->summary($id),
            'distribution_mode' => app(PluginDistributionService::class)->mode($manifest),
            'distribution_label' => app(PluginDistributionService::class)->modeLabel(app(PluginDistributionService::class)->mode($manifest)),
            'package'          => (string) ($manifest['package'] ?? app(WeappContext::class)->packageForIdentifier($id)),
            'instance_id'      => (string) ($row['instance_id'] ?? app(WeappContext::class)->instanceId($id)),
            'kind'             => (string) ($manifest['kind'] ?? ($row['kind'] ?? 'document-addon')),
            'kind_label'       => $this->pluginManifest->labelForKind((string) ($manifest['kind'] ?? ($row['kind'] ?? 'document-addon'))),
            'tags'             => is_array($manifest['tags'] ?? null) ? array_values($manifest['tags']) : [],
            'admin_route'      => app(WeappAdminUiService::class)->adminRouteFromManifest($manifest, $id),
            'admin_spa_path'   => app(WeappAdminUiService::class)->spaPath($id, $manifest),
            'admin_ui_mode'    => app(WeappAdminUiService::class)->mode($manifest, $id),
            'admin_title'      => (string) ($admin['title'] ?? ($manifest['name'] ?? '管理')),
            'publisher_type'   => (string) ($manifest['publisher_type'] ?? ''),
            'publisher_label'  => (string) ($manifest['publisher_label'] ?? $this->pluginManifest->labelForType((string) ($manifest['publisher_type'] ?? ''))),
            'manifest_valid'   => !empty($manifest['_manifest_valid']),
            'manifest_errors'  => is_array($manifest['_manifest_errors'] ?? null) ? $manifest['_manifest_errors'] : [],
            'official_protected' => $this->blocksUninstall($id),
            'official_publisher'   => $this->isOfficialPublisherPlugin($id),
            'upload_replace_blocked' => $this->blocksOfficialPackageUpload($id),
            'needs_upgrade'    => false,
            'upgrade_from_market' => false,
            'remote_version'   => '',
            'boot_failure'     => app(PluginBootService::class)->runtimeBootFailure($id),
            'commercial_editable' => app(PluginCommercialPricingService::class)->canEditPricing($id),
            'marketplace_blocked' => $this->pluginMarketBlocklist()->isBlocked($id),
            'block_reason'        => $this->pluginMarketBlocklist()->blockReason($id),
            'disk_version'     => (string) ($manifest['version'] ?? '1.0.0'),
            'icon'             => $visual['icon'],
            'icon_color'       => $visual['color'],
            'icon_image'       => $visual['image'],
        ];

        $localUp  = $this->needsUpgrade($id, $row, $manifest);
        $marketUp = $this->marketNeedsUpgrade($id, $row, $manifest);
        $out['needs_upgrade']       = $localUp || $marketUp;
        $out['upgrade_from_market'] = $marketUp;
        if ($marketUp) {
            $out['remote_version'] = trim(app(PluginMarketShelfDirectory::class)->remoteVersion($id));
        } elseif ($localUp) {
            // 仅磁盘>库：目标版用磁盘清单，供卡片「已装 → 可升」展示
            $out['remote_version'] = trim((string) ($manifest['version'] ?? ''));
        }

        if ($lite) {
            $out['capabilities'] = null;
        } else {
            $out['capabilities'] = app(PluginCapabilityService::class)->summary($id);
        }

        return app(PluginTaxonomyService::class)->enrichRow($out, $manifest);
    }

    /**
     * 应用市场 / 远程 catalog：manifest 图标 → 本地 weapp → static/market/icons 回退
     *
     * @param array<string, mixed> $manifest
     * @return array{icon:string,color:string,image:string}
     */
    public function marketVisual(string $identifier, array $manifest): array
    {
        $visual = $this->visualForPlugin($identifier, $manifest);
        if ($visual['image'] !== '') {
            return $visual;
        }

        $static = $this->marketStaticIconUrl($identifier);
        if ($static !== '') {
            $visual['image'] = $static;
        }

        return $visual;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{icon:string,color:string,image:string}
     */
    private function visualForPlugin(string $identifier, array $manifest): array
    {
        $iconRaw = trim((string) ($manifest['icon'] ?? ''));
        $color = trim((string) ($manifest['color'] ?? ''));
        $image = '';
        $icon = '';
        if ($iconRaw !== '' && (str_starts_with($iconRaw, '/') || str_starts_with($iconRaw, 'http'))) {
            $image = $iconRaw;
        } elseif ($iconRaw !== '') {
            $icon = $iconRaw;
        }
        if ($color === '') {
            $color = '#5fb878';
        }
        if ($image !== '' && str_starts_with($image, '/weapp/')) {
            if (preg_match('#^/weapp/([a-z][a-z0-9_-]{0,49})/(.+)$#', $image, $m)) {
                if ($this->weappAssetExists($m[1], $m[2])) {
                    $image = \app\common\support\WeappPublicAsset::url($m[1], $m[2]);
                } else {
                    $image = '';
                }
            }
        }

        return ['icon' => $icon, 'color' => $color, 'image' => $image];
    }

    private function weappAssetExists(string $identifier, string $relativePath): bool
    {
        $identifier   = preg_replace('/[^a-z0-9_-]/', '', strtolower($identifier)) ?? '';
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($identifier === '' || $relativePath === '' || str_contains($relativePath, '..')) {
            return false;
        }

        $root = ProjectPaths::root();
        $file = $root . 'weapp/' . $identifier . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        return is_file($file);
    }

    private function marketStaticIconUrl(string $identifier): string
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return '';
        }

        $root = ProjectPaths::root();
        $file = $root . 'public/static/market/icons/' . $identifier . '.svg';
        if (!is_file($file)) {
            return '';
        }

        return \app\common\support\WeappPublicAsset::absoluteAssetUrl('/static/market/icons/' . $identifier . '.svg');
    }

    public function bootstrapEnabled(): void
    {
        app(PluginBootService::class)->bootstrapEnabled();
    }

    /** @return array<string, string> */
    public function bootFailures(): array
    {
        return app(PluginBootService::class)->bootFailures();
    }

    public function clearBootFailure(string $identifier): void
    {
        app(PluginBootService::class)->clearBootFailure($identifier);
    }

    public function isSafeMode(): bool
    {
        return app(PluginBootService::class)->isSafeMode();
    }

    public function setRuntimeSafeMode(bool $enabled): ServiceResult
    {
        return app(PluginBootService::class)->setRuntimeSafeMode($enabled);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pluginRow(mixed $result): ?array
    {
        return $result instanceof Plugin ? $result->toArray() : null;
    }

    public static function registerAutoloadPublic(string $identifier): void
    {
        PluginBootService::registerAutoloadPublic($identifier);
    }

    /** 卸载兜底：删除 manifest 未清理的后台菜单（route 前缀匹配） */
    private static function purgePluginAdminMenus(string $identifier): int
    {
        $slug = strtolower(trim($identifier));
        if ($slug === '') {
            return 0;
        }
        $prefixWeapp = '/admin/weapp/' . $slug;
        $prefixAdmin = '/admin/' . $slug . '/';
        $prefixHost  = '/weapp/host/' . $slug . '/';
        $rows = Menu::whereLike('route', $prefixWeapp . '%')
            ->whereOr('route', 'like', $prefixAdmin . '%')
            ->whereOr('route', 'like', $prefixHost . '%')
            ->select()
            ->toArray();
        $purged = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            Menu::deleteWithChildren($id);
            $purged++;
        }

        return $purged;
    }

}
