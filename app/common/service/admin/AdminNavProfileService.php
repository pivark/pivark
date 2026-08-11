<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

use app\common\service\product\ProductCenterGateService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\PluginService;
use app\common\service\user\PermissionService;
use think\facade\Config;
use think\facade\Session;

/** 后台 NavProfile：content | enterprise（SSOT config/admin/nav_profile.php） */
class AdminNavProfileService
{
    public const PROFILE_CONTENT    = 'content';
    public const PROFILE_ENTERPRISE = 'enterprise';

    public function resolve(): string
    {
        foreach ($this->layoutTriggers() as $identifier) {
            if ($this->pluginService->isEnabled($identifier)) {
                return self::PROFILE_ENTERPRISE;
            }
        }

        return self::PROFILE_CONTENT;
    }

    public function isEnterprise(): bool
    {
        return $this->resolve() === self::PROFILE_ENTERPRISE;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $profile = $this->resolve();
        $cfg     = $this->profileConfig($profile);

        return [
            'profile'                  => $profile,
            'content_menu_title'       => (string) ($cfg['content_menu_title'] ?? '内容发布'),
            'enterprise_group'         => $cfg['enterprise_group'] ?? null,
            'market_commerce_group'    => $cfg['market_commerce_group'] ?? null,
            'enabled_applications'     => $this->enabledEnterpriseApplications(),
            'enabled_market_commerce'    => $this->enabledMarketCommerceApplications(),
        ];
    }

    public function defaultHomePath(): string
    {
        if (!$this->isEnterprise()) {
            return '/dashboard/welcome';
        }

        $home = $this->profileConfig(self::PROFILE_ENTERPRISE)['default_home'] ?? [];
        if (!is_array($home)) {
            return '/dashboard/welcome';
        }

        foreach ($home as $pluginId => $path) {
            if ($pluginId === 'fallback') {
                continue;
            }
            if ($this->pluginService->isEnabled((string) $pluginId)) {
                return (string) $path;
            }
        }

        return (string) ($home['fallback'] ?? '/dashboard/welcome');
    }

    /**
     * @param list<array<string, mixed>> $tree
     * @return list<array<string, mixed>>
     */
    public function applyToMenuTree(array $tree): array
    {
        if (!$this->isEnterprise()) {
            return $tree;
        }

        $cfg     = $this->profileConfig(self::PROFILE_ENTERPRISE);
        $menuId  = (int) ($cfg['content_menu_id'] ?? 10);
        $title   = (string) ($cfg['content_menu_title'] ?? '数字资产');

        return $this->relabelMenuNode($tree, $menuId, $title);
    }

    /**
     * enterprise 侧栏：「企业经营」父节点 + 已装应用快捷方式
     *
     * @return list<array<string, mixed>>
     */
    public function enterpriseDynamicMenus(): array
    {
        if (!$this->isEnterprise()) {
            return [];
        }

        $cfg   = $this->profileConfig(self::PROFILE_ENTERPRISE);
        $group = is_array($cfg['enterprise_group'] ?? null) ? $cfg['enterprise_group'] : [];
        $title = (string) ($group['title'] ?? '企业经营');
        $sort  = (int) ($group['sort'] ?? 12);
        $icon  = (string) ($group['icon'] ?? 'fa fa-building');
        $gid   = -96000;

        $admin = Session::get('admin_user', []);
        $userId = (int) ($admin['id'] ?? 0);

        $out = [[
            'id'              => $gid,
            'parent_id'       => 0,
            'title'           => $title,
            'route'           => '',
            'icon'            => $icon,
            'permission_code' => '',
            'status'          => 1,
            'sort'            => $sort,
            'kernel_shortcut' => 1,
        ]];

        $seq = 0;
        foreach ($this->enabledEnterpriseApplications() as $identifier => $app) {
            $permission = (string) ($app['permission'] ?? '');
            if ($permission !== '' && $userId > 0 && !$this->permissionService->can($userId, $permission)) {
                continue;
            }
            $seq++;
            $out[] = [
                'id'              => $gid - $seq,
                'parent_id'       => $gid,
                'title'           => (string) ($app['title'] ?? $identifier),
                'route'           => (string) ($app['route'] ?? ''),
                'icon'            => (string) ($app['icon'] ?? 'fa fa-circle-o'),
                'permission_code' => $permission,
                'status'          => 1,
                'sort'            => (int) ($app['sort'] ?? $seq),
                'kernel_shortcut' => 1,
            ];
        }

        return count($out) > 1 ? $out : [];
    }

    /**
     * enterprise 侧栏：「市场与成交」(966) + M/C/X 已装应用
     *
     * @return list<array<string, mixed>>
     */
    public function marketCommerceDynamicMenus(): array
    {
        $apps = $this->enabledMarketCommerceApplications();
        if ($apps === []) {
            return [];
        }

        $profileKey = $this->isEnterprise() ? self::PROFILE_ENTERPRISE : self::PROFILE_CONTENT;
        $cfg   = $this->profileConfig($profileKey);
        $group = is_array($cfg['market_commerce_group'] ?? null) ? $cfg['market_commerce_group'] : [];
        $title = (string) ($group['title'] ?? ($this->isEnterprise() ? '市场与成交' : '商城'));
        $sort  = (int) ($group['sort'] ?? ($this->isEnterprise() ? 11 : 28));
        $icon  = (string) ($group['icon'] ?? 'fa fa-shopping-cart');
        $gid   = -96600;

        $admin  = Session::get('admin_user', []);
        $userId = (int) ($admin['id'] ?? 0);

        $out = [[
            'id'              => $gid,
            'parent_id'       => 0,
            'title'           => $title,
            'route'           => '',
            'icon'            => $icon,
            'permission_code' => '',
            'status'          => 1,
            'sort'            => $sort,
            'kernel_shortcut' => 1,
        ]];

        $seq = 0;
        foreach ($apps as $identifier => $app) {
            $permission = (string) ($app['permission'] ?? '');
            if ($permission !== '' && $userId > 0 && !$this->permissionService->can($userId, $permission)) {
                continue;
            }
            $seq++;
            $route = (string) ($app['route'] ?? '');
            $routeGate = (string) ($app['route_gate'] ?? '');
            if ($routeGate === 'product_center' && !$this->productCenterGate->allowsAdmin()) {
                $route = (string) ($app['route_fallback'] ?? $route);
            }
            $out[] = [
                'id'              => $gid - $seq,
                'parent_id'       => $gid,
                'title'           => (string) ($app['title'] ?? $identifier),
                'route'           => $route,
                'icon'            => (string) ($app['icon'] ?? 'fa fa-circle-o'),
                'permission_code' => $permission,
                'status'          => 1,
                'sort'            => (int) ($app['sort'] ?? $seq),
                'kernel_shortcut' => 1,
            ];
        }

        return count($out) > 1 ? $out : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function enabledMarketCommerceApplications(): array
    {
        return $this->enabledAppsFromConfig('admin_nav_profile.market_commerce_apps');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function enabledEnterpriseApplications(): array
    {
        return $this->enabledAppsFromConfig('admin_nav_profile.enterprise_apps');
    }

    /** @return array<string, array<string, mixed>> */
    private function enabledAppsFromConfig(string $configKey): array
    {
        $apps = Config::get($configKey, []);
        if (!is_array($apps)) {
            return [];
        }

        $out = [];
        foreach ($apps as $identifier => $app) {
            if (!is_array($app)) {
                continue;
            }
            if (!$this->pluginService->isEnabled((string) $identifier)) {
                continue;
            }
            if (!$this->entitlementService->can((string) $identifier)) {
                continue;
            }
            $out[(string) $identifier] = $app;
        }

        uasort($out, static fn (array $a, array $b): int => ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0)));

        return $out;
    }

    /** @return list<string> */
    private function layoutTriggers(): array
    {
        $list = Config::get('admin_nav_profile.layout_triggers', []);
        if (!is_array($list)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($v): string => strtolower(trim((string) $v)), $list)));
    }

    /** @return array<string, mixed> */
    private function profileConfig(string $profile): array
    {
        $profiles = Config::get('admin_nav_profile.profiles', []);
        if (!is_array($profiles)) {
            return [];
        }
        $cfg = $profiles[$profile] ?? [];

        return is_array($cfg) ? $cfg : [];
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private function relabelMenuNode(array $nodes, int $menuId, string $title): array
    {
        foreach ($nodes as $i => $node) {
            if ((int) ($node['id'] ?? 0) === $menuId) {
                $nodes[$i]['title'] = $title;
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                $nodes[$i]['children'] = $this->relabelMenuNode($node['children'], $menuId, $title);
            }
        }

        return $nodes;
    }

    public function __construct(
        private readonly PluginService $pluginService,
        private readonly EntitlementService $entitlementService,
        private readonly PermissionService $permissionService,
        private readonly ProductCenterGateService $productCenterGate,
    ) {
    }
}
