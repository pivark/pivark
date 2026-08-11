<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * weapp 插件 boot / autoload / Safe Mode
 */
declare(strict_types=1);

namespace app\common\service\plugin\boot;

use app\common\service\plugin\market\PluginMarketSecuritySyncService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\contract\WeappPluginLifecycle;
use app\common\support\ServiceResult;
use app\common\support\AppTime;
use app\common\model\Plugin;
use app\common\service\admin\AdminPluginRouteRegistry;
use app\common\service\admin\AdminPermissionExtensionRegistry;
use app\common\service\admin\AdminSpaExplicitRouteRegistry;
use app\common\service\admin\KernelWeappAdminNavBootstrap;
use app\common\service\admin\WeappAdminNavRegistry;
use app\common\service\front\FrontPluginPathRegistry;
use app\common\service\member\MemberCenterPageRegistry;
use app\common\service\auth\SocialAuthCapabilityRegistry;
use app\common\service\audit\AuditLogService;
use app\common\service\enterprise\EnterpriseAssetBackendRegistry;
use app\common\service\event\EventBusService;
use app\common\service\hook\HookService;
use app\common\service\template\TemplateEngine;
use app\common\support\InstallGate;
use app\common\support\LocalFile;
use app\common\support\ProjectPaths;
use app\common\service\admin\AdminPluginSidebarRegistry;
use app\common\service\channel\KernelMiniprogramFeatureBootstrap;
use app\common\service\channel\MiniprogramChannelRegistry;
use app\common\service\channel\MiniprogramFeatureRegistry;
use app\common\service\cron\PluginCronTaskRegistry;
use app\common\service\front\PluginFrontAssetRegistry;
use app\common\service\member\PluginMemberConsumptionRegistry;
use app\common\service\plugin\registry\PluginApiRegistry;
use app\common\service\plugin\registry\PluginFrontTemplateRegistry;
use app\common\service\plugin\registry\PluginRouteService;
use app\common\service\plugin\registry\PluginSeoRegistry;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\lifecycle\WeappPluginLifecycleAdapter;
use app\common\service\plugin\extension\PluginExtensionManifestLoader;
use app\common\service\plugin\extension\PluginOfferBridgeRegistry;
use app\common\service\plugin\manifest\PluginDistributionPolicy;
use app\common\service\release\PivarkEditionService;

final class PluginBootService
{

    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly PluginMarketSecuritySyncService $pluginMarketSecuritySync,
        private readonly PluginApiRegistry $pluginApiRegistry,
        private readonly HookService $hook,
        private readonly PluginRouteService $pluginRoute,
        private readonly TemplateEngine $templateEngine,
        private readonly EntitlementService $entitlement,
        private readonly EventBusService $eventBus,
        private readonly PluginService $plugins,
    ) {
    }

    /** @var array<string, string> */
    private static array $bootFailures = [];
    private static bool $booted = false;
    private static int $bootGeneration = -1;
    private static ?int $blocklistCheckedAt = null;

    /** @var array<string, callable(string):void> */
    private static array $pluginAutoloaders = [];

    private const BLOCKLIST_THROTTLE_FILE = 'plugin_blocklist_bootstrap.ts';
    private const BOOT_GENERATION_FILE = 'plugin_boot_generation.txt';

    public function isSafeMode(): bool
    {
        if ((bool) config('plugin.security.safe_mode', false)) {
            return true;
        }

        return is_file(ProjectPaths::runtimeDir() . 'plugin_safe_mode.lock');
    }

    /**
     * 运行时 Safe Mode 锁（不影响 .env PIVARK_PLUGIN_SAFE_MODE=1）
     *
     * @return ServiceResult
     */
    public function setRuntimeSafeMode(bool $enabled): ServiceResult
    {
        if ((bool) config('plugin.security.safe_mode', false)) {
            return ServiceResult::fail('环境变量 PIVARK_PLUGIN_SAFE_MODE=1 已锁定，无法通过后台切换');
        }

        $lock = ProjectPaths::runtimeDir() . 'plugin_safe_mode.lock';
        if ($enabled) {
            LocalFile::mkdirIfMissing(ProjectPaths::runtimeDir());
            file_put_contents($lock, AppTime::format('c') . "\n");
            $this->auditLog->operate('开启插件 Safe Mode', 'admin.plugin', []);
        } elseif (is_file($lock)) {
            LocalFile::unlinkQuiet($lock, 'plugin_safe_mode');
            $this->auditLog->operate('关闭插件 Safe Mode', 'admin.plugin', []);
        }

        return ServiceResult::ok(['safe_mode' => $this->isSafeMode()], $enabled ? '已开启 Safe Mode，所有插件 boot 已跳过' : '已关闭 Safe Mode');
    }

    public function bootstrapEnabled(): void
    {
        $generation = self::readBootGeneration();
        if (self::$booted && self::$bootGeneration === $generation) {
            return;
        }
        self::$booted = false;
        self::$bootGeneration = $generation;
        if (!InstallGate::isInstalled()) {
            return;
        }
        if ($this->isSafeMode()) {
            self::$booted = true;

            return;
        }
        if ($this->shouldEnforceBlocklistOnBootstrap()) {
            $this->pluginMarketSecuritySync->enforceInstalledRevocations(false);
        }
        self::$booted = true;
        self::$bootFailures = [];
        $this->pluginApiRegistry->reset();
        $this->hook->reset();
        $this->pluginRoute->reset();
        app(AdminPluginRouteRegistry::class)->reset();
        app(AdminSpaExplicitRouteRegistry::class)->reset();
        app(AdminPermissionExtensionRegistry::class)->reset();
        app(WeappAdminNavRegistry::class)->reset();
        KernelWeappAdminNavBootstrap::register();
        app(FrontPluginPathRegistry::class)->reset();
        app(MemberCenterPageRegistry::class)->reset();
        app(PluginOfferBridgeRegistry::class)->reset();
        app(PluginFrontTemplateRegistry::class)->reset();
        app(PluginFrontAssetRegistry::class)->reset();
        app(PluginCronTaskRegistry::class)->reset();
        app(MiniprogramFeatureRegistry::class)->reset();
        app(MiniprogramChannelRegistry::class)->resetHubRegistrations();
        app(SocialAuthCapabilityRegistry::class)->reset();
        app(AdminPluginSidebarRegistry::class)->reset();
        app(PluginMemberConsumptionRegistry::class)->reset();
        app(\app\common\service\plugin\registry\HubCapabilityRegistry::class)->reset();
        app(PluginSeoRegistry::class)->reset();
        app(EnterpriseAssetBackendRegistry::class)->reset();
        $this->templateEngine->resetExtensionTags();
        KernelMiniprogramFeatureBootstrap::register();
        /** @var list<array<string, mixed>> $rows */
        $rows = array_values(Plugin::where('installed', 1)->where('enabled', 1)->select()->toArray());
        $rows = PluginDistributionPolicy::sortRowsByBootstrapOrder($rows);
        $attempted = 0;
        /** @var list<string> $bootedIds */
        $bootedIds = [];
        foreach ($rows as $row) {
            $id = strtolower(trim((string) ($row['identifier'] ?? '')));
            if ($id === '' || !$this->entitlement->can($id)) {
                continue;
            }
            ++$attempted;
            if ($this->bootInstalledPlugin($id)) {
                $bootedIds[] = $id;
            }
        }
        foreach ($this->hostOnlyDevLaneBundleIdsToBoot($bootedIds) as $id) {
            ++$attempted;
            if ($this->bootInstalledPlugin($id)) {
                $bootedIds[] = $id;
            }
        }
        $this->maybeAutoSafeModeOnMassBootFailure($attempted);
    }

    /**
     * platform 版：宿主 bundle 在盘且已启用时须 boot 公开路由。
     * 已停用的host_only 发行宿主不再强制 boot（勿复活已停用宿主）。
     *
     * @param list<string> $alreadyBooted
     * @return list<string>
     */
    private function hostOnlyDevLaneBundleIdsToBoot(array $alreadyBooted): array
    {
        if (!PluginDistributionPolicy::isHostBundleActive()) {
            return [];
        }
        $edition = app(PivarkEditionService::class)->edition();
        if (!in_array($edition, [PivarkEditionService::DEV, PivarkEditionService::PLATFORM], true)) {
            return [];
        }
        $out = [];
        foreach (PluginDistributionPolicy::identifiers() as $id) {
            if (in_array($id, $alreadyBooted, true) || !$this->entitlement->can($id)) {
                continue;
            }
            $row = Plugin::where('identifier', $id)->where('installed', 1)->where('enabled', 1)->find();
            if ($row !== null) {
                $out[] = $id;
            }
        }

        return $out;
    }

    private function bootInstalledPlugin(string $id): bool
    {
        try {
            $plugin = $this->loadPluginClass($id);
            if ($plugin !== null) {
                $plugin->boot();
            }
            app(PluginExtensionManifestLoader::class)->applyForIdentifier($id, $this->plugins->readManifest($id));
            $this->eventBus->registerManifestSubscribes($id, $this->plugins->readManifest($id));
            $this->hook->fire('app_init', ['identifier' => $id]);
            $this->clearBootFailure($id);

            return true;
        } catch (\Throwable $e) {
            self::$bootFailures[$id] = $e->getMessage();
            app(PluginBootRollbackService::class)->purgeForIdentifier($id);
            $this->persistBootFailure($id, $e->getMessage());
            error_log('[PivArk plugin boot] ' . $id . ': ' . $e->getMessage());
            $this->auditLog->write(
                'system',
                'plugin_boot_failed',
                'weapp',
                ['identifier' => $id, 'error' => $e->getMessage()],
                false
            );
            if ((bool) config('plugin.security.auto_disable_on_boot_fail', true)) {
                Plugin::where('identifier', $id)->update([
                    'enabled'    => 0,
                    'updated_at' => AppTime::now(),
                ]);
                $this->auditLog->operate('boot 失败自动停用插件', 'admin.plugin', [
                    'identifier' => $id,
                    'error'      => $e->getMessage(),
                ]);
            }

            return false;
        }
    }

    private function maybeAutoSafeModeOnMassBootFailure(int $attemptedCount): void
    {
        if (!(bool) config('plugin.security.auto_safe_mode_on_mass_boot_fail', true)) {
            return;
        }
        if ($this->isSafeMode()) {
            return;
        }
        if ((bool) config('plugin.security.safe_mode', false)) {
            return;
        }

        $failureCount = count(self::$bootFailures);
        if ($attemptedCount < 1 || $failureCount < 1) {
            return;
        }
        if ($failureCount / $attemptedCount < 0.5) {
            return;
        }

        $result = $this->setRuntimeSafeMode(true);
        if (!$result->isOk()) {
            return;
        }

        error_log(sprintf(
            '[PivArk] %d/%d plugins failed boot, auto-enabling Safe Mode',
            $failureCount,
            $attemptedCount,
        ));
        $this->auditLog->operate('多数插件 boot 失败，自动开启 Safe Mode', 'admin.plugin', [
            'failures' => $failureCount,
            'total'    => $attemptedCount,
        ]);
    }

    public static function clearBlocklistBootstrapThrottle(): void
    {
        self::$blocklistCheckedAt = null;
        $stamp = ProjectPaths::runtimeDir() . self::BLOCKLIST_THROTTLE_FILE;
        if (is_file($stamp)) {
            LocalFile::unlinkQuiet($stamp, 'plugin_blocklist_bootstrap');
        }
    }

    private function shouldEnforceBlocklistOnBootstrap(): bool
    {
        if (!(bool) config('plugin.security.enforce_blocklist_on_bootstrap', false)) {
            return false;
        }

        $ttl = (int) config('plugin.security.blocklist_bootstrap_ttl', 300);
        $now = time();
        if ($ttl > 0) {
            if (self::$blocklistCheckedAt !== null && ($now - self::$blocklistCheckedAt) < $ttl) {
                return false;
            }
            $stamp = ProjectPaths::runtimeDir() . self::BLOCKLIST_THROTTLE_FILE;
            if (is_file($stamp)) {
                $last = (int) trim((string) file_get_contents($stamp));
                if ($last > 0 && ($now - $last) < $ttl) {
                    self::$blocklistCheckedAt = $last;

                    return false;
                }
            }
        }

        self::$blocklistCheckedAt = $now;
        if ($ttl > 0) {
            LocalFile::mkdirIfMissing(ProjectPaths::runtimeDir());
            file_put_contents(ProjectPaths::runtimeDir() . self::BLOCKLIST_THROTTLE_FILE, (string) $now);
        }

        return true;
    }

    /**
     * 本次请求 bootstrap 中启动失败的插件（identifier => message）
     *
     * @return array<string, string>
     */
    public function bootFailures(): array
    {
        // 持久化失败只保留仍已安装的；本次请求 runtime（含 seed）始终可见
        $merged = $this->filterBootFailuresToInstalled($this->loadPersistedBootFailures());
        foreach (self::$bootFailures as $id => $message) {
            $key = strtolower(trim((string) $id));
            if ($key === '') {
                continue;
            }
            $merged[$key] = trim((string) $message);
        }

        return $merged;
    }

    /**
     * @param array<string, string> $failures
     *
     * @return array<string, string>
     */
    private function filterBootFailuresToInstalled(array $failures): array
    {
        if ($failures === []) {
            return [];
        }

        $installed = [];
        foreach ($this->plugins->listInstalledIdentifiers() as $id) {
            $key = strtolower(trim($id));
            if ($key !== '') {
                $installed[$key] = true;
            }
        }

        $filtered = [];
        $stale      = [];
        foreach ($failures as $id => $message) {
            $key = strtolower(trim((string) $id));
            if ($key === '') {
                continue;
            }
            if (isset($installed[$key])) {
                $filtered[$key] = trim((string) $message);
                continue;
            }
            $stale[] = $key;
        }

        if ($stale !== []) {
            $this->removePersistedBootFailures($stale);
        }

        return $filtered;
    }

    /**
     * @param list<string> $identifiers
     */
    private function removePersistedBootFailures(array $identifiers): void
    {
        $path = $this->bootFailuresStorePath();
        if (!is_file($path)) {
            return;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            LocalFile::unlinkQuiet($path, 'plugin_boot_failures_clear');

            return;
        }
        $changed = false;
        foreach ($identifiers as $identifier) {
            $key = strtolower(trim($identifier));
            if ($key === '' || !isset($decoded[$key])) {
                continue;
            }
            unset($decoded[$key]);
            $changed = true;
        }
        if (!$changed) {
            return;
        }
        if ($decoded === []) {
            LocalFile::unlinkQuiet($path, 'plugin_boot_failures_clear');

            return;
        }
        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        if (is_string($encoded)) {
            LocalFile::putContents($path, $encoded);
        }
    }

    public function clearBootFailure(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        unset(self::$bootFailures[$identifier]);
        $path = $this->bootFailuresStorePath();
        if (!is_file($path)) {
            return;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !isset($decoded[$identifier])) {
            return;
        }
        unset($decoded[$identifier]);
        if ($decoded === []) {
            LocalFile::unlinkQuiet($path, 'plugin_boot_failures_clear');

            return;
        }
        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        if (is_string($encoded)) {
            LocalFile::putContents($path, $encoded);
        }
    }

    private function persistBootFailure(string $identifier, string $message): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || $message === '') {
            return;
        }
        $existing = $this->loadPersistedBootFailures();
        $existing[$identifier] = $message;
        LocalFile::mkdirIfMissing(ProjectPaths::runtimeDir());
        $encoded = json_encode($existing, JSON_UNESCAPED_UNICODE);
        if (is_string($encoded)) {
            LocalFile::putContents($this->bootFailuresStorePath(), $encoded);
        }
    }

    /** @return array<string, string> */
    private function loadPersistedBootFailures(): array
    {
        $path = $this->bootFailuresStorePath();
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $id => $message) {
            if (!is_string($id) || !is_scalar($message)) {
                continue;
            }
            $out[strtolower(trim($id))] = trim((string) $message);
        }

        return $out;
    }

    private function bootFailuresStorePath(): string
    {
        return ProjectPaths::runtimeDir() . 'plugin_boot_failures.json';
    }

    public static function registerAutoloadPublic(string $identifier): void
    {
        app(self::class)->registerPluginAutoload($identifier);
    }

    private function pluginClassNamespace(string $identifier): string
    {
        return 'weapp\\' . str_replace('-', '_', $identifier) . '\\';
    }

    public function loadPluginClass(string $identifier): ?WeappPluginLifecycle
    {
        $file = $this->weappRoot() . $identifier . '/Plugin.php';
        if (!is_readable($file)) {
            return null;
        }
        self::registerPluginAutoload($identifier);
        require_once $file;
        $class = self::pluginClassNamespace($identifier) . 'Plugin';
        if (!class_exists($class)) {
            return null;
        }

        return WeappPluginLifecycleAdapter::from(new $class());
    }

    private function registerPluginAutoload(string $identifier): void
    {
        if (isset(self::$pluginAutoloaders[$identifier])) {
            return;
        }

        $base   = $this->weappRoot() . $identifier;
        $prefix = self::pluginClassNamespace($identifier);
        $loader = static function (string $class) use ($base, $prefix): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
            $path = $base . DIRECTORY_SEPARATOR . $rel . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        };
        spl_autoload_register($loader);
        self::$pluginAutoloaders[$identifier] = $loader;
    }

    public function resetBooted(): void
    {
        foreach (self::$pluginAutoloaders as $loader) {
            spl_autoload_unregister($loader);
        }
        self::$pluginAutoloaders = [];
        self::$booted = false;
        self::$bootGeneration = -1;
        self::bumpBootGeneration();
    }

    private static function bootGenerationPath(): string
    {
        return ProjectPaths::runtimeDir() . self::BOOT_GENERATION_FILE;
    }

    private static function readBootGeneration(): int
    {
        $path = self::bootGenerationPath();
        if (!is_file($path)) {
            return 0;
        }

        return (int) trim((string) file_get_contents($path));
    }

    private static function bumpBootGeneration(): void
    {
        LocalFile::mkdirIfMissing(ProjectPaths::runtimeDir());
        file_put_contents(self::bootGenerationPath(), (string) time());
    }

    public function isBooted(): bool
    {
        return self::$booted;
    }

    public function runtimeBootFailure(string $identifier): string
    {
        $identifier = strtolower(trim($identifier));

        return trim((string) (self::$bootFailures[$identifier] ?? ''));
    }

    /** @param array<string, string> $failures */
    public function seedRuntimeBootFailures(array $failures): void
    {
        foreach ($failures as $id => $message) {
            self::$bootFailures[strtolower(trim((string) $id))] = trim((string) $message);
        }
    }

    private function weappRoot(): string
    {
        return $this->plugins->weappRoot();
    }
}
