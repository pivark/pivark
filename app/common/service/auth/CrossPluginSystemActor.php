<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\auth;

use app\common\support\OpsLog;

/** 跨插件系统执行主体（Batch 0 · Perm-0） */
final class CrossPluginSystemActor
{

    private int $stackAdminId = 0;

    public function actingAdminId(): int
    {
        return $this->stackAdminId > 0 ? $this->stackAdminId : $this->resolveSystemAdminId();
    }

    /** @param callable(): mixed $fn */
    public function run(string $aclId, callable $fn): mixed
    {
        $this->authGuard()->assertSystem($aclId);
        $previous           = $this->stackAdminId;
        $this->stackAdminId = $this->resolveSystemAdminId();
        OpsLog::businessAction('cross_plugin_system_actor', [
            'acl'      => $aclId,
            'admin_id' => $this->stackAdminId,
        ]);
        try {
            return $fn();
        } finally {
            $this->stackAdminId = $previous;
        }
    }

    private function resolveSystemAdminId(): int
    {
        /** @var array<string, mixed> $registry */
        $registry = require dirname(__DIR__, 3) . '/config/enterprise/plugin_permissions.php';

        return max(0, (int) ($registry['system_admin_id'] ?? 0));
    }

    /** CrossPluginSystemActor ↔ Guard 环：Actor 侧延迟 app() 解析。 */
    private function authGuard(): CrossPluginAuthGuard
    {
        return app(CrossPluginAuthGuard::class);
    }
}
