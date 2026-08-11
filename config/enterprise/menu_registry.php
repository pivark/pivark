<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 *
 * 企业应用后台导航接口（L2 菜单段；具体菜单项由插件安装时注册）。
 */
declare(strict_types=1);

/** @return array<string, mixed> */
if (!function_exists('enterprise_menu_registry')) {
function enterprise_menu_registry(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [
        'version'    => '2.0',
        'mode'       => 'plugin_manifest',
        'l2_shell'   => [
            'oa'  => ['menu_id' => 961],
            'crm' => ['menu_id' => 962],
            'erp' => ['menu_id' => 963],
            'plm' => ['menu_id' => 964],
            'mes' => ['menu_id' => 965],
        ],
        'menus'      => [],
        'lifecycles' => [],
    ];

    return $cache;
}
}

return enterprise_menu_registry();
