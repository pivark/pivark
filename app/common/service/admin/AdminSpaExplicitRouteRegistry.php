<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;


/** 后台 Vue SPA EXPLICIT 路由（插件 boot 注册 · 替代 PivarkVueRoute 硬编码） */
final class AdminSpaExplicitRouteRegistry
{
    /** 插件商业账房 SPA（旧 /plugin/commerce 跳转目标；由具备该能力的插件 boot 注册） */
    public const CAP_PLUGIN_COMMERCE_ADMIN = 'admin.plugin_commerce';

    /** @var array<string, array<string, mixed>> admin href => route def */
    private static array $routes = [];

    /** @var array<string, string> admin href => lucide icon */
    private static array $routeIcons = [];

    /** @var list<string> */
    private static array $maintenanceHiddenRoutes = [];

    /** @var array<string, string> admin href => title（发行受限宿主侧栏补全） */
    private static array $restrictedSpaExtras = [];

    /** @var array<string, string> capability => spa path */
    private static array $capabilityPaths = [];

    public function reset(): void
    {
        self::$routes                  = [];
        self::$routeIcons              = [];
        self::$maintenanceHiddenRoutes = [];
        self::$restrictedSpaExtras     = [];
        self::$capabilityPaths         = [];
    }

    public function registerCapabilityPath(string $capability, string $spaPath): void
    {
        $capability = trim($capability);
        $spaPath    = $this->normalizeHref($spaPath);
        if ($capability === '' || $spaPath === '') {
            return;
        }
        self::$capabilityPaths[$capability] = $spaPath;
    }

    public function capabilityPath(string $capability): ?string
    {
        $capability = trim($capability);
        if ($capability === '') {
            return null;
        }
        $path = self::$capabilityPaths[$capability] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * @param array<string, mixed> $def name/path/component/meta/redirect
     */
    public function register(string $adminHref, array $def): void
    {
        $key = $this->normalizeHref($adminHref);
        if ($key === '') {
            return;
        }
        self::$routes[$key] = $def;
    }

    public function registerRouteIcon(string $adminHref, string $lucideIcon): void
    {
        $key = $this->normalizeHref($adminHref);
        if ($key === '' || trim($lucideIcon) === '') {
            return;
        }
        self::$routeIcons[$key] = trim($lucideIcon);
    }

    public function registerMaintenanceHiddenRoute(string $adminHref): void
    {
        $key = $this->normalizeHref($adminHref);
        if ($key === '') {
            return;
        }
        self::$maintenanceHiddenRoutes[$key] = $key;
    }

    public function registerRestrictedSpaExtra(string $adminHref, string $title): void
    {
        $key = $this->normalizeHref($adminHref);
        if ($key === '' || trim($title) === '') {
            return;
        }
        self::$restrictedSpaExtras[$key] = trim($title);
    }

    /** @return array<string, string> */
    public function restrictedSpaExtras(): array
    {
        return self::$restrictedSpaExtras;
    }

    /** @return array<string, mixed>|null */
    public function get(string $adminHref): ?array
    {
        $key = $this->normalizeHref($adminHref);

        return $key !== '' ? (self::$routes[$key] ?? null) : null;
    }

    public function has(string $adminHref): bool
    {
        return $this->get($adminHref) !== null;
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return self::$routes;
    }

    /** @return array<string, string> */
    public function routeIcons(): array
    {
        return self::$routeIcons;
    }

    /** @return list<string> */
    public function maintenanceHiddenRoutes(): array
    {
        return array_values(self::$maintenanceHiddenRoutes);
    }

    /**
     * 已注册 SPA path 的一级前缀（供 AdminSpaRouteResolveService · 内核不写死插件 id）
     *
     * @return list<string> 如host_only 发行宿主 SPA 前缀、/weapp/host/shop/
     */
    public function spaCanonicalPathPrefixes(): array
    {
        $prefixes = [];
        foreach (self::$routes as $def) {
            $path = '/' . ltrim(trim((string) ($def['path'] ?? '')), '/');
            if ($path === '/') {
                continue;
            }
            $parts = explode('/', trim($path, '/'));
            if ($parts === []) {
                continue;
            }
            $prefixes['/' . $parts[0] . '/'] = true;
            if (($parts[0] ?? '') === 'weapp' && ($parts[1] ?? '') === 'host' && ($parts[2] ?? '') !== '') {
                $prefixes['/weapp/host/' . $parts[2] . '/'] = true;
            }
        }
        foreach (array_keys(self::$restrictedSpaExtras) as $spaPath) {
            $path = '/' . ltrim(trim(str_replace('\\', '/', $spaPath)), '/');
            $parts = explode('/', trim($path, '/'));
            if ($parts !== []) {
                $prefixes['/' . $parts[0] . '/'] = true;
            }
        }

        return array_keys($prefixes);
    }

    public function hasSpaPath(string $spaPath): bool
    {
        $spaPath = '/' . ltrim(trim($spaPath), '/');
        foreach (self::$routes as $def) {
            if ((string) ($def['path'] ?? '') === $spaPath) {
                return true;
            }
        }

        return false;
    }

    public function hasWeappAdminIndex(string $identifier): bool
    {
        $identifier = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($identifier))) ?? '';
        if ($identifier === '') {
            return false;
        }
        $prefix = '/weapp/host/' . $identifier . '/';
        foreach (self::$routes as $def) {
            $path = (string) ($def['path'] ?? '');
            if ($path !== '' && str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        foreach (array_keys(self::$routes) as $key) {
            if (str_contains($key, '/' . $identifier) || str_contains($key, '/weapp/' . $identifier)) {
                unset(self::$routes[$key]);
            }
        }
        foreach (array_keys(self::$routeIcons) as $key) {
            if (str_contains($key, '/' . $identifier) || str_contains($key, '/weapp/' . $identifier)) {
                unset(self::$routeIcons[$key]);
            }
        }
        self::$maintenanceHiddenRoutes = array_values(array_filter(
            self::$maintenanceHiddenRoutes,
            static fn (string $route): bool => !str_contains($route, '/' . $identifier),
        ));
    }

    private function normalizeHref(string $href): string
    {
        $href = trim(str_replace('\\', '/', $href));
        if ($href === '') {
            return '';
        }
        if (!str_starts_with($href, '/')) {
            $href = '/' . $href;
        }

        return rtrim($href, '/') ?: $href;
    }
}
