<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\gateway;

use app\common\service\plugin\PluginService;
use app\common\service\plugin\security\PluginThirdPartyPolicyService;

/** manifest gateway_permissions 审包 + 运行时 requirePermission（第三方 enforce 时） */
final class PluginGatewayPermissionService
{
    /** @return list<string> */
    public function declaredPermissions(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return [];
        }
        $manifest = app(PluginService::class)->readManifest($identifier);
        if (!is_array($manifest)) {
            return [];
        }
        $raw = $manifest['gateway_permissions'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $perm) {
            $perm = strtolower(trim((string) $perm));
            if ($perm !== '') {
                $out[] = $perm;
            }
        }

        return array_values(array_unique($out));
    }

    public function assertPermission(string $permission): void
    {
        $permission = strtolower(trim($permission));
        if ($permission === '') {
            return;
        }
        if (!(bool) config('plugin.security.gateway_permission_enforce', false)) {
            return;
        }
        $caller = PluginGatewayCallerContext::currentIdentifier();
        if ($caller === null || $caller === '') {
            return;
        }
        if (PluginThirdPartyPolicyService::isOfficialIdentifier($caller)) {
            return;
        }
        $declared = $this->declaredPermissions($caller);
        if (!in_array($permission, $declared, true)) {
            throw new \RuntimeException(
                'Plugin ' . $caller . ' lacks gateway permission ' . $permission
            );
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function auditManifestGatewayPermissions(array $manifest): array
    {
        $issues = [];
        $raw = $manifest['gateway_permissions'] ?? null;
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            $issues[] = 'gateway_permissions 必须是字符串数组';

            return $issues;
        }
        foreach ($raw as $perm) {
            $perm = strtolower(trim((string) $perm));
            if ($perm === '') {
                $issues[] = 'gateway_permissions 含空条目';
                continue;
            }
            if (!PluginGatewayPermissionCatalog::isKnown($perm)) {
                $issues[] = 'gateway_permissions 未知权限：' . $perm;
            }
        }

        return array_values(array_unique($issues));
    }
}
