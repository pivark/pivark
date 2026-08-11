<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\service\plugin\gateway\PluginGatewayPermissionService;

trait PluginGatewayGuardTrait
{
    protected function gatewayRequire(string $permission): void
    {
        $this->gatewayPermission->assertPermission($permission);
    }
}
