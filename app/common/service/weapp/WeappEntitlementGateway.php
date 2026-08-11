<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappEntitlementGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\registry\PluginCapabilityService;

final class WeappEntitlementGateway
{

    public function __construct(
        private readonly EntitlementService $entitlement,
        private readonly PluginCapabilityService $pluginCapability,
    ) {
    }

    public function entitlementCan(string $identifier): bool
    {
        return $this->entitlement->can($identifier);
    }

    /** @param array<string, mixed> $manifest */
    public function entitlementApplyInstallPolicy(string $identifier, array $manifest): void
    {
        $this->entitlement->applyInstallPolicy($identifier, $manifest);
    }

    public function entitlementRevoke(string $identifier): void
    {
        $this->entitlement->revoke($identifier);
    }

    public function entitlementGrantFreeInstall(string $identifier): void
    {
        $this->entitlement->grant($identifier, null, 'install', 'free');
    }

    public function entitlementGrant(
        string $identifier,
        ?string $expireAt = null,
        string $grantedBy = 'manual',
        string $licenseType = 'free',
    ): bool {
        return $this->entitlement->grant($identifier, $expireAt, $grantedBy, $licenseType);
    }

    public function entitlementCanFeature(string $identifier, string $feature): bool
    {
        return $this->pluginCapability->canFeature($identifier, $feature);
    }
}
