<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\extension;

use app\common\contract\PluginHostRuntimeHandlerInterface;
use app\common\service\plugin\boot\PluginBootService;
use app\common\service\plugin\manifest\PluginDistributionPolicy;
use app\common\service\plugin\registry\PluginExtensionRegistry;

/**
 * Handler / 路由加载门面（会员→MemberCenterPageRegistry；授权→HostLicenseRuntime；portal 视图→HostPortalAccountView）。
 * portal 软调用已迁 {@see PluginPortalInvoke}；发行探测已迁 {@see PluginDistributionPolicy}；官方品项已迁 {@see PluginOfficialProduct}。
 */
final class HostRuntimeProbe
{
    // ── 官方：宿主运行态探测 ─────────────────────────────────────────────

    public static function isBundleActive(): bool
    {
        return PluginDistributionPolicy::isHostBundleActive();
    }

    public static function isAnyHostEnabled(): bool
    {
        return PluginDistributionPolicy::isAnyEnabled();
    }

    public static function licenseCatalogReady(): bool
    {
        return PluginDistributionPolicy::licenseCatalogReady()
            || self::isAnyHostRuntimeLicenseCatalogReady();
    }

    public static function isAnyHostRuntimeActive(): bool
    {
        foreach (PluginDistributionPolicy::identifiers() as $identifier) {
            $handler = self::hostRuntimeHandler($identifier);
            if ($handler !== null && $handler->isHostRuntimeActive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * 官方宿主用量快照（心跳/运营统计入口；业务实现落在 weapp 扩展 usageTelemetry）
     *
     * @return array{
     *   edition:string,
     *   host_bundle_active:bool,
     *   host_plugin_enabled:bool,
     *   host_identifiers:list<string>,
     *   license_catalog_ready:bool,
     *   telemetry:array<string, array<string, mixed>>
     * }
     */
    public static function usageSnapshot(): array
    {
        $telemetry = [];
        foreach (PluginDistributionPolicy::identifiers() as $identifier) {
            $handler = self::hostRuntimeHandler($identifier);
            if ($handler === null || !$handler->isHostRuntimeActive()) {
                continue;
            }
            $row = $handler->usageTelemetry();
            if (is_array($row) && $row !== []) {
                $telemetry[$identifier] = $row;
            }
        }

        return [
            'edition'               => class_exists(\app\common\service\release\PivarkEditionService::class)
                ? app(\app\common\service\release\PivarkEditionService::class)->edition()
                : '',
            'host_bundle_active'    => self::isBundleActive(),
            'host_plugin_enabled'   => self::isAnyHostEnabled(),
            'host_identifiers'      => PluginDistributionPolicy::identifiers(),
            'license_catalog_ready' => self::licenseCatalogReady(),
            'telemetry'             => $telemetry,
        ];
    }

    // ── 内核 dispatch：宿主运行时 handler ─────────────────────────────────

    public static function hostRuntimeHandler(string $identifier): ?PluginHostRuntimeHandlerInterface
    {
        return app(PluginExtensionRegistry::class)->getHostRuntimeHandler($identifier);
    }

    public static function hostRuntimeHasHandler(string $identifier): bool
    {
        return self::hostRuntimeHandler($identifier) !== null;
    }

    public static function isHostRuntimeActive(string $identifier): bool
    {
        $handler = self::hostRuntimeHandler($identifier);

        return $handler !== null && $handler->isHostRuntimeActive();
    }

    /** @return list<PluginHostRuntimeHandlerInterface> */
    public static function activeHostRuntimeHandlers(): array
    {
        $out = [];
        foreach (app(PluginExtensionRegistry::class)->hostRuntimeIdentifiers() as $identifier) {
            $handler = self::hostRuntimeHandler($identifier);
            if ($handler !== null && $handler->isHostRuntimeActive()) {
                $out[] = $handler;
            }
        }

        return $out;
    }

    public static function firstActiveHostRuntimeHandler(): ?PluginHostRuntimeHandlerInterface
    {
        foreach (self::activeHostRuntimeHandlers() as $handler) {
            return $handler;
        }

        return null;
    }

    /** 宿主站点授权 API（经 PluginExtensionRegistry 已注册 handler）已迁 {@see \app\common\service\license\HostLicenseRuntime} */

    /**
     * @param list<mixed> $args
     */
    public static function hostRuntimeInvoke(string $identifier, string $method, array $args = []): mixed
    {
        return app(PluginExtensionRegistry::class)->invokeHostRuntimeHandler($identifier, $method, $args);
    }

    private static function isAnyHostRuntimeLicenseCatalogReady(): bool
    {
        foreach (app(PluginExtensionRegistry::class)->hostRuntimeIdentifiers() as $identifier) {
            $handler = self::hostRuntimeHandler($identifier);
            if ($handler !== null && $handler->licenseCatalogReady()) {
                return true;
            }
        }

        return false;
    }

    /**
     * 后台宿主 API 须在 app/route/admin.php 加载期 apply（早于 SPA fallback）。
     * host_only 插件经 plugin.json `admin.load_time_route_registrars` 写入 AdminPluginRouteRegistry，本方法 applyOnce。
     */
    public static function registerLoadTimeAdminRoutes(): void
    {
        foreach (PluginDistributionPolicy::identifiers() as $identifier) {
            PluginBootService::registerAutoloadPublic($identifier);
            $slug = str_replace('-', '_', $identifier);
            foreach (PluginDistributionPolicy::loadTimeAdminRouteRegistrars($identifier) as $row) {
                $class  = trim((string) ($row['class'] ?? ''));
                $method = trim((string) ($row['method'] ?? ''));
                if ($class === '' || $method === '') {
                    continue;
                }
                $fqcn = 'weapp\\' . $slug . '\\' . ltrim($class, '\\');
                if (!is_callable([$fqcn, $method])) {
                    continue;
                }
                $fqcn::$method();
            }
        }
        app(\app\common\service\admin\AdminPluginRouteRegistry::class)->applyOnce(true);
    }
}
