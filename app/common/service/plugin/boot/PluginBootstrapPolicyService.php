<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\boot;

use app\common\service\plugin\manifest\PluginDistributionPolicy;
use app\common\service\site\AdminEntryAliasService;
use think\Request;

/** 判定当前 HTTP 请求是否可跳过全量 plugin boot（登录提速 / 后台懒加载） */
final class PluginBootstrapPolicyService
{
    /** @var list<string> */
    private const AUTH_LIGHT_EXACT_PATHS = [
        'spa/bootstrap',
        'session/bootstrap',
        'spa/user',
        'session/user',
        'spa/codes',
    ];

    /** @var list<string> */
    private const AUTH_LIGHT_PATH_PREFIXES = [
        'login/',
    ];

    /** @var list<string> */
    private const ADMIN_PATH_PREFIXES = [
        'login/',
        'spa/',
        'plugin/',
        'weapp/',
        'content/',
        'system/',
        'member/',
        'dashboard/',
        'debug/',
    ];

    /** @var list<string> */
    private const PLUGIN_EAGER_PREFIXES = [
        'plugin/',
        'weapp/',
        'shop/',
    ];

    /**
     * 懒加载下仍须 boot 的 spa 路径（插件注册表：nav_tabs / 动态菜单 / *-meta / resolve-href）
     *
     * @var list<string>
     */
    private const SPA_PLUGIN_BOOT_EXACT_PATHS = [
        'spa-nav-tree',
        'nav-routes',
        'vben-menu-routes',
        'menu-tree',
        'spa/menus',
        'menus',
        'spa/resolve-href',
        'session/resolve-href',
        'spa/weapp-plugin-info',
        'spa/weapp-usage',
    ];

    /** @var list<string> */
    private const SPA_PLUGIN_BOOT_PREFIXES = [
        'spa/weapp/',
    ];

    /**
     * 文档保存须 eager boot：`PluginDocumentSaveService::syncAfterSave` 依赖已注册 addon handler。
     *
     * @var list<string>
     */
    private const CONTENT_PLUGIN_SYNC_EXACT_PATHS = [
        'document/save',
        'documents',
    ];

    /**
     * 产品中心 meta 须 eager boot：offerCenterNav / uiModules / item_list_ui_modules
     * 依赖已注册宿主 officialProduct handler。
     * SSOT 路径：REST `meta/items`（admin SPA fetchItemMetaApi）；兼容旧 `item/meta`。
     *
     * @var list<string>
     */
    private const PRODUCT_CENTER_META_EXACT_PATHS = [
        'meta/items',
        'item/meta',
    ];

    /**
     * 品项列表/详情须 eager boot：item_list_overlay / item_origin_meta
     * （仅 meta/items 不够——列壳有了，行上上架/审核/开发者仍会变「—」/「手动」）
     *
     * @var list<string>
     */
    private const PRODUCT_CENTER_ITEM_EXACT_PATHS = [
        'items',
    ];

    /** @var list<string> */
    private const PRODUCT_CENTER_ITEM_PREFIXES = [
        'items/',
        'item/',
    ];

    /**
     * 企业经营资料 API 须 eager boot：EnterpriseAssetBackendRegistry 由 tender boot 注册。
     *
     * @var list<string>
     */
    private const MEDIA_ENTERPRISE_BOOT_PREFIXES = [
        'media/enterprise',
    ];

    /** REST /api/v1/admin/meta/plugins/{id}（替代 spa/{id}-meta） */
    private const REST_PLUGIN_META_PREFIXES = [
        'meta/plugins/',
        'meta/documents',
    ];

    /**
     * 插件管理「救命」API：列表/停用/卸载/Safe Mode 不 boot 任何插件，确保入口可用。
     *
     * @var list<string>
     */
    private const PLUGIN_ADMIN_SURVIVAL_EXACT_PATHS = [
        'plugin/index',
        'plugin/disable',
        'plugin/uninstall',
        'plugin/toggleSafeMode',
        'plugin/securityPolicy',
        'plugin/marketSecurityStatus',
    ];

    public function shouldSkipPluginBoot(Request $request): bool
    {
        $rawPath = strtolower(trim((string) $request->pathinfo(), '/'));
        // 前台会员中心与后台 REST「member/」前缀撞名：绝不能 lazy-skip，
        // 否则 PluginRouteService 不 apply，表单 POST /member/shop/publish 直接 404。
        if ($rawPath === 'member' || str_starts_with($rawPath, 'member/')) {
            return false;
        }

        $path = $this->normalizeAdminPath((string) $request->pathinfo());
        if ($this->isPluginAdminSurvivalPath($path)) {
            return true;
        }
        if ($this->isAuthLightPath($path)) {
            return true;
        }
        if (!(bool) config('plugin.security.lazy_boot_admin', true)) {
            return false;
        }
        if (!$this->isAdminHttpRequest($request, $path)) {
            return false;
        }

        return !$this->requiresEagerPluginBoot($path);
    }

    /** 插件管理核心 API 是否免 boot（供校验逻辑与文档引用） */
    public function isPluginAdminSurvivalPath(string $normalizedPath): bool
    {
        return in_array($normalizedPath, self::PLUGIN_ADMIN_SURVIVAL_EXACT_PATHS, true);
    }

    public function normalizeAdminPath(string $pathinfo): string
    {
        $path = strtolower(trim($pathinfo, '/'));
        if (str_starts_with($path, 'api/v1/admin/')) {
            $path = substr($path, strlen('api/v1/admin/'));
        }
        if (str_starts_with($path, 'admin/')) {
            $path = substr($path, 6);
        }

        return $path;
    }

    private function isAuthLightPath(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if (in_array($path, self::AUTH_LIGHT_EXACT_PATHS, true)) {
            return true;
        }
        foreach (self::AUTH_LIGHT_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isAdminHttpRequest(Request $request, string $normalizedPath): bool
    {
        $uri = strtolower((string) $request->url(true));
        $base = strtolower(rtrim(app(AdminEntryAliasService::class)->publicBasePath(), '/'));
        if ($base !== '' && (str_contains($uri, $base . '/') || str_ends_with(rtrim($uri, '/'), $base))) {
            return true;
        }
        foreach (self::ADMIN_PATH_PREFIXES as $prefix) {
            $bare = rtrim($prefix, '/');
            if ($normalizedPath === $bare || str_starts_with($normalizedPath, $prefix)) {
                return true;
            }
        }
        foreach ($this->hostOnlyAdminPathPrefixes() as $prefix) {
            $bare = rtrim($prefix, '/');
            if ($normalizedPath === $bare || str_starts_with($normalizedPath, $prefix)) {
                return true;
            }
        }

        return $normalizedPath === '' || $normalizedPath === 'index';
    }

    /** 发行受限宿主插件后台 REST 前缀（来自 plugin.json · 不写死 identifier） · @return list<string> */
    private function hostOnlyAdminPathPrefixes(): array
    {
        $out = [];
        foreach (PluginDistributionPolicy::identifiers() as $identifier) {
            $out[] = $identifier . '/';
        }

        return $out;
    }

    private function requiresEagerPluginBoot(string $path): bool
    {
        if (in_array($path, self::SPA_PLUGIN_BOOT_EXACT_PATHS, true)) {
            return true;
        }
        if (in_array($path, self::CONTENT_PLUGIN_SYNC_EXACT_PATHS, true)) {
            return true;
        }
        if (in_array($path, self::PRODUCT_CENTER_META_EXACT_PATHS, true)) {
            return true;
        }
        if (in_array($path, self::PRODUCT_CENTER_ITEM_EXACT_PATHS, true)) {
            return true;
        }
        foreach (self::PRODUCT_CENTER_ITEM_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        foreach (self::MEDIA_ENTERPRISE_BOOT_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        foreach (self::REST_PLUGIN_META_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        foreach ($this->hostOnlyAdminPathPrefixes() as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        foreach (self::SPA_PLUGIN_BOOT_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        if (preg_match('#^spa/[a-z0-9_-]+-meta$#', $path) === 1) {
            return true;
        }
        foreach (self::PLUGIN_EAGER_PREFIXES as $prefix) {
            $bare = rtrim($prefix, '/');
            if ($path === $bare || str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
