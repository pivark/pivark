<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\gateway;

/** plugin.json gateway_permissions 声明 SSOT */
final class PluginGatewayPermissionCatalog
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            'document.read',
            'document.write',
            'document.delete',
            'item.read',
            'item.write',
            'user.read',
            'config.read',
            'payment.create',
            'payment.refund',
            'member.read',
            'member.write',
            'upload.write',
            'event.dispatch',
            'hook.register',
        ];
    }

    public static function isKnown(string $permission): bool
    {
        return in_array(strtolower(trim($permission)), self::all(), true);
    }
}
