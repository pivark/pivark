<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 *
 * 企业应用跨插件权限接口（插件安装并启用后由内核合并生效）。
 */
declare(strict_types=1);

/** @return array<string, mixed> */
if (!function_exists('enterprise_plugin_permissions')) {
function enterprise_plugin_permissions(): array
{
    return [
        'version'         => '3.0',
        'mode'            => 'interface_only',
        'system_admin_id' => 0,
    ];
}
}

return enterprise_plugin_permissions();
