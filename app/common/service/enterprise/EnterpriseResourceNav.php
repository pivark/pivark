<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\enterprise;

use app\common\service\menu\AdminMenuRegistry;
use app\common\service\menu\MenuService;

/** 内核企业资源库 · 内容与发布侧栏入口（非插件菜单） */
final class EnterpriseResourceNav
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        app(AdminMenuRegistry::class)->register('enterprise_resource', [self::class, 'dynamicMenus'], 90);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function dynamicMenus(): array
    {
        // 仅当已启用插件 manifest needs 点亮 enterprise_resource 时挂侧栏（AI标书/OA/CRM 等消费方；无消费方不出现）
        if (!app(EnterpriseResourceService::class)->isActive()) {
            return [];
        }

        $menu = app(MenuService::class);
        $anchor = $menu->anchorByRoute('/system/media');
        $parentId = (int) ($anchor['parent_id'] ?? 0);
        // www / 无「内容发布」锚点时禁止挂到顶栏孤儿项
        if ($parentId < 1 || !$menu->menuNodeExists($parentId)) {
            return [];
        }

        return [
            [
                'id'               => -91001,
                'parent_id'        => $parentId,
                'title'            => '企业资源库',
                'route'            => '/system/enterprise-resource',
                'icon'             => 'fa fa-database',
                'permission_code'  => 'admin.media.list',
                'status'           => 1,
                'sort'             => (int) ($anchor['sort'] ?? 0) + 1,
                'kernel_shortcut'  => 1,
            ],
        ];
    }
}
