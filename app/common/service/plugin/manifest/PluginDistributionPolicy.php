<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\manifest;

use app\common\service\plugin\PluginService;
use app\common\service\release\PivarkEditionService;
use app\common\support\InstallGate;
use app\common\support\ProjectPaths;

/**
 * 插件发行形态门闩：读 plugin.json distribution。
 * 含在盘探测与 bootstrap 序；用户可见文案保持发行版中性。
 */
final class PluginDistributionPolicy
{
    public const DEFAULT_BOOTSTRAP_PRIORITY = -100000;

    /** @var list<string>|null */
    private static ?array $identifierCache = null;

    /** @return list<string> */
    public static function identifiers(): array
    {
        if (self::$identifierCache !== null) {
            return self::$identifierCache;
        }
        $out  = [];
        $root = ProjectPaths::root() . 'weapp/';
        if (!is_dir($root)) {
            self::$identifierCache = [];

            return self::$identifierCache;
        }
        foreach (glob($root . '*/plugin.json') ?: [] as $manifestPath) {
            if (!is_readable($manifestPath)) {
                continue;
            }
            $raw = file_get_contents($manifestPath);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }
            if (!self::manifestIsRestricted($data)) {
                continue;
            }
            $id = strtolower(trim((string) ($data['identifier'] ?? '')));
            if ($id !== '') {
                $out[] = $id;
            }
        }
        self::$identifierCache = array_values(array_unique($out));

        return self::$identifierCache;
    }

    public static function isRestricted(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));

        return $identifier !== '' && in_array($identifier, self::identifiers(), true);
    }

    /** @deprecated 语义同 {@see isRestricted()}；保留至 host/ 目录退役 */
    public static function isHostOnly(string $identifier): bool
    {
        return self::isRestricted($identifier);
    }

    /** @return list<string> */
    public static function weappDirPrefixes(): array
    {
        $out = [];
        foreach (self::identifiers() as $id) {
            $out[] = 'weapp/' . $id;
        }

        return $out;
    }

    public static function resetCache(): void
    {
        self::$identifierCache = null;
    }

    /**
     * 受限发行插件在 app/route/admin.php 加载期须 apply 的 REST 注册器
     * （plugin.json admin.load_time_route_registrars）。
     *
     * @return list<array{class:string, method:string}>
     */
    public static function loadTimeAdminRouteRegistrars(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return [];
        }
        $manifestPath = ProjectPaths::root() . 'weapp/' . $identifier . '/plugin.json';
        if (!is_readable($manifestPath)) {
            return [];
        }
        $raw = file_get_contents($manifestPath);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $admin = is_array($data['admin'] ?? null) ? $data['admin'] : [];
        $rows  = is_array($admin['load_time_route_registrars'] ?? null) ? $admin['load_time_route_registrars'] : [];
        $out   = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $class  = trim((string) ($row['class'] ?? ''));
            $method = trim((string) ($row['method'] ?? ''));
            if ($class === '' || $method === '') {
                continue;
            }
            $out[] = ['class' => $class, 'method' => $method];
        }

        return $out;
    }

    public static function weappPresentFor(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return false;
        }

        return is_readable(ProjectPaths::root() . 'weapp/' . $identifier . '/plugin.json');
    }

    /** @return list<string> */
    public function installErrors(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || !self::isRestricted($identifier)) {
            return [];
        }
        $errors = [];
        if (InstallGate::lockedEdition() === PivarkEditionService::COMMUNITY) {
            $errors[] = '此插件不可在本发行版安装';
        } elseif (!self::weappPresentFor($identifier)) {
            $errors[] = '缺少 weapp/' . $identifier . ' 插件目录，无法安装';
        }

        return $errors;
    }

    public function marketInstallBlockedMessage(string $identifier): ?string
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || !self::isRestricted($identifier)) {
            return null;
        }

        return '此插件不可从插件市场安装';
    }

    public function disableBlockedMessage(string $identifier): ?string
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || !self::isRestricted($identifier)) {
            return null;
        }
        // 仅 platform 锁定不可停；dev/Community 可关以腾出卖货槽位
        if (app(PivarkEditionService::class)->isPlatform()) {
            return '此插件为平台预装组件，不可停用';
        }

        return null;
    }

    public function uninstallBlockedMessage(string $identifier): ?string
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || !self::isRestricted($identifier)) {
            return null;
        }

        return '此插件不可卸载';
    }

    public static function filesPresent(): bool
    {
        foreach (self::identifiers() as $id) {
            if (self::pluginEntryPresent($id)) {
                return true;
            }
        }

        return false;
    }

    /** @param non-empty-string $relative weapp/{id}/ 下相对路径 */
    public static function serviceFile(string $relative): bool
    {
        foreach (self::identifiers() as $id) {
            if (self::serviceFileFor($id, $relative)) {
                return true;
            }
        }

        return false;
    }

    public static function licenseServiceReady(): bool
    {
        return self::filesPresent();
    }

    public static function licenseCatalogReady(): bool
    {
        foreach (self::identifiers() as $id) {
            if (self::hostServiceGlobPresent($id, '*LicenseCatalog*Service.php')) {
                return true;
            }
        }

        return false;
    }

    public static function hostModuleOnDisk(): bool
    {
        foreach (self::identifiers() as $id) {
            if (self::hostModuleGlobPresent($id)) {
                return true;
            }
        }

        return false;
    }

    public static function weappPresent(): bool
    {
        foreach (self::identifiers() as $id) {
            if (self::weappPresentFor($id)) {
                return true;
            }
        }

        return false;
    }

    public static function bundlePresent(): bool
    {
        return self::filesPresent() && self::weappPresent();
    }

    public static function isHostBundleActive(): bool
    {
        if (!self::filesPresent() || !InstallGate::isInstalled()) {
            return false;
        }
        if (InstallGate::lockedEdition() === PivarkEditionService::COMMUNITY) {
            return false;
        }
        if (!self::weappPresent()) {
            return false;
        }
        $edition = app(PivarkEditionService::class)->edition();
        if (in_array($edition, [
            PivarkEditionService::DEV,
            PivarkEditionService::PLATFORM,
        ], true)) {
            return true;
        }
        foreach (self::identifiers() as $id) {
            if (class_exists(PluginService::class) && app(PluginService::class)->isEnabled($id)) {
                return true;
            }
        }

        return false;
    }

    public static function isAnyEnabled(): bool
    {
        if (!self::isHostBundleActive() || !class_exists(PluginService::class)) {
            return false;
        }
        foreach (self::identifiers() as $id) {
            if (app(PluginService::class)->isEnabled($id)) {
                return true;
            }
        }

        return false;
    }

    public static function marketCatalogVisible(string $identifier): bool
    {
        return !self::isRestricted($identifier);
    }

    /**
     * 客户站远程货架行是否受限（不依赖本机 weapp/{id}）。
     * 认 distribution.mode 受限或 community_release=false；并与本地 isRestricted 并集。
     *
     * @param array<string, mixed> $row
     */
    public static function isRestrictedCatalogRow(array $row): bool
    {
        $id = strtolower(trim((string) ($row['identifier'] ?? '')));
        if ($id !== '' && self::isRestricted($id)) {
            return true;
        }

        $distribution = is_array($row['distribution'] ?? null) ? $row['distribution'] : [];
        if ($distribution === [] && is_array($row['plugin'] ?? null)) {
            $plugin = $row['plugin'];
            $distribution = is_array($plugin['distribution'] ?? null) ? $plugin['distribution'] : [];
        }

        return self::manifestIsRestricted(['distribution' => $distribution]);
    }

    public static function bootstrapPriority(string $identifier): int
    {
        $identifier = strtolower(trim($identifier));
        $manifest   = class_exists(PluginService::class)
            ? app(PluginService::class)->readManifest($identifier)
            : null;
        if (is_array($manifest)) {
            $raw = $manifest['bootstrap_priority'] ?? null;
            if (is_int($raw)) {
                return $raw;
            }
            if (is_string($raw) && is_numeric($raw)) {
                return (int) $raw;
            }
        }

        return self::isRestricted($identifier)
            ? self::DEFAULT_BOOTSTRAP_PRIORITY
            : 0;
    }

    public static function compareBootstrapOrder(string $a, string $b): int
    {
        $pa = self::bootstrapPriority($a);
        $pb = self::bootstrapPriority($b);
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }

        return strcmp($a, $b);
    }

    /** @param list<array<string, mixed>> $rows */
    public static function sortRowsByBootstrapOrder(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            return self::compareBootstrapOrder(
                (string) ($a['identifier'] ?? ''),
                (string) ($b['identifier'] ?? '')
            );
        });

        return $rows;
    }

    /** @param array<string, mixed> $data */
    private static function manifestIsRestricted(array $data): bool
    {
        $distribution = is_array($data['distribution'] ?? null) ? $data['distribution'] : [];
        $hostOnly     = strtolower(trim((string) ($distribution['mode'] ?? ''))) === 'host_only';
        $noCommunity  = array_key_exists('community_release', $distribution)
            && $distribution['community_release'] === false;

        return $hostOnly || $noCommunity;
    }

    /** @param non-empty-string $identifier */
    private static function pluginEntryPresent(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return false;
        }
        $base = ProjectPaths::root() . 'weapp/' . $identifier . '/';

        return is_readable($base . 'plugin.json') && is_file($base . 'Plugin.php');
    }

    /** @param non-empty-string $identifier */
    private static function hostServiceGlobPresent(string $identifier, string $pattern): bool
    {
        $dir = ProjectPaths::root() . 'weapp/' . strtolower(trim($identifier)) . '/service';
        if (!is_dir($dir)) {
            return false;
        }
        foreach ([$dir . '/' . $pattern, $dir . '/*/' . $pattern] as $globPattern) {
            foreach (glob($globPattern) ?: [] as $file) {
                if (is_file($file)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param non-empty-string $identifier */
    private static function hostModuleGlobPresent(string $identifier): bool
    {
        $dir = ProjectPaths::root() . 'weapp/' . strtolower(trim($identifier));
        foreach (glob($dir . '/*Module.php') ?: [] as $file) {
            if (is_file($file)) {
                return true;
            }
        }

        return false;
    }

    /** @param non-empty-string $identifier */
    private static function serviceFileFor(string $identifier, string $relative): bool
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return false;
        }

        return is_file(ProjectPaths::root() . 'weapp/' . $identifier . '/' . ltrim($relative, '/'));
    }
}
