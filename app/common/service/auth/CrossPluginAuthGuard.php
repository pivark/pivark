<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\auth;

use app\common\service\auth\CrossPluginAclRegistry;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\user\PermissionService;

/** Enterprise 跨插件 ACL 守卫（Perm-0 · ADR-13） */
final class CrossPluginAuthGuard
{

    public function __construct(
        private readonly CrossPluginAclRegistry $crossPluginAclRegistry,
        private readonly PermissionService $permissionService,
        private readonly EntitlementService $entitlementService,
    ) {
    }

    public function assert(string $aclId, ?int $adminUserId = null): void
    {
        $row  = $this->aclRow($aclId);
        $this->assertEntitlement($row);
        $actor = (string) ($row['actor'] ?? 'user');
        if ($actor === 'system') {
            return;
        }
        $uid = $adminUserId ?? $this->systemActor()->actingAdminId();
        if ($uid < 1) {
            throw new \RuntimeException('CrossPluginAuthGuard: admin not authenticated');
        }
        $callerRbac = (string) ($row['caller_rbac'] ?? '');
        if ($callerRbac !== '' && !$this->permissionService->can($uid, $callerRbac)) {
            throw new \RuntimeException('CrossPluginAuthGuard: caller RBAC denied for ' . $aclId);
        }
        $calleeRbac = (string) ($row['callee_rbac'] ?? '');
        if ($calleeRbac !== '' && !$this->permissionService->can($uid, $calleeRbac)) {
            throw new \RuntimeException('CrossPluginAuthGuard: callee RBAC denied for ' . $aclId);
        }
    }

    public function assertSystem(string $aclId): void
    {
        $row = $this->aclRow($aclId);
        $this->assertEntitlement($row);
        if (($row['actor'] ?? '') !== 'system') {
            throw new \RuntimeException('CrossPluginAuthGuard: ACL ' . $aclId . ' is not system actor');
        }
    }

    /** @param callable(): mixed $fn */
    public function runAsSystem(string $aclId, callable $fn): mixed
    {
        return $this->systemActor()->run($aclId, $fn);
    }

    /** @param array<string, string> $row */
    private function assertEntitlement(array $row): void
    {
        $callee = (string) ($row['callee_plugin'] ?? '');
        if ($callee === '' || in_array($callee, ['kernel', 'enterprise_resource', 'insights'], true)) {
            return;
        }
        if (!$this->entitlementService->can($callee)) {
            throw new \RuntimeException('CrossPluginAuthGuard: plugin not entitled: ' . $callee);
        }
    }

    /** @return array<string, string> */
    private function aclRow(string $aclId): array
    {
        return $this->crossPluginAclRegistry->get($aclId);
    }

    /** CrossPluginSystemActor ↔ Guard 环：Guard 侧延迟 app() 解析，Actor 侧 ctor 注入 Guard。 */
    private function systemActor(): CrossPluginSystemActor
    {
        return app(CrossPluginSystemActor::class);
    }
}
