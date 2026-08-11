<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\entitlement;

use app\common\support\ServiceResult;

/** 站点插件授权门面（只读 → Query · 写入 → Command） */
class EntitlementService
{
    public function __construct(
        private readonly EntitlementQueryService $query,
        private readonly EntitlementCommandService $command,
    ) {
    }

    public function can(string $identifier): bool
    {
        return $this->query->can($identifier);
    }

    public function resolveSlugIdentifier(string $identifier): string
    {
        return $this->query->resolveSlugIdentifier($identifier);
    }

    public function grant(
        string $identifier,
        ?string $expireAt = null,
        string $grantedBy = 'manual',
        string $licenseType = 'free'
    ): bool {
        return $this->command->grant($identifier, $expireAt, $grantedBy, $licenseType);
    }

    public function grantWithSkuApply(
        string $identifier,
        ?string $expireAt,
        string $grantedBy,
        string $licenseType,
        string $skuId = ''
    ): bool {
        return $this->command->grantWithSkuApply($identifier, $expireAt, $grantedBy, $licenseType, $skuId);
    }

    public function revoke(string $identifier): void
    {
        $this->command->revoke($identifier);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function commercialModel(array $manifest): string
    {
        return $this->query->commercialModel($manifest);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function applyInstallPolicy(string $identifier, array $manifest): void
    {
        $this->command->applyInstallPolicy($identifier, $manifest);
    }

    /**
     * @param list<string>|null $identifiers
     */
    public function reconcileEnhancementPackInstallGrants(?array $identifiers = null): ServiceResult
    {
        return $this->command->reconcileEnhancementPackInstallGrants($identifiers);
    }

    /**
     * @return array{
     *   status:string,
     *   status_label:string,
     *   license_type:string,
     *   license_label:string,
     *   expire_at:?string,
     *   granted_by:string,
     *   entitled:bool
     * }
     */
    public function summary(string $identifier): array
    {
        return $this->query->summary($identifier);
    }

    public function grantManual(string $identifier, string $licenseType = 'paid', int $trialDays = 0): ServiceResult
    {
        return $this->command->grantManual($identifier, $licenseType, $trialDays);
    }

    public function revokeManual(string $identifier): ServiceResult
    {
        return $this->command->revokeManual($identifier);
    }

    public function revokeIfGrantedBy(string $identifier, string $expectedGrantedBy): ServiceResult
    {
        return $this->command->revokeIfGrantedBy($identifier, $expectedGrantedBy);
    }

    /**
     * @param array<string, mixed> $commercial
     */
    public function resolveTrialPeriodDays(array $commercial, string $billingType): int
    {
        return $this->command->resolveTrialPeriodDays($commercial, $billingType);
    }

    public function refreshExpiredStatuses(): int
    {
        return $this->command->refreshExpiredStatuses();
    }

    /**
     * @return array{expired:int,disabled:int}
     */
    public function enforceExpiredPlugins(): array
    {
        return $this->command->enforceExpiredPlugins();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEntitledAdmin(): array
    {
        $this->command->refreshExpiredStatuses();

        return $this->query->listEntitledAdmin();
    }

    /**
     * @return array{synced:int,lifted:int,slug_created:int}
     */
    public function reconcilePackageMirrors(): array
    {
        return $this->command->reconcilePackageMirrors();
    }
}
