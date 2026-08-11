<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\commerce;

use app\common\service\plugin\PluginService;
use app\common\service\site\SiteCoreLicenseService;

final class PluginDomainPurchaseGateService
{
    public const TIER_ALL   = 'all';
    public const TIER_BASIC = 'basic';
    public const TIER_PRO   = 'pro';

    public function __construct(
        private readonly SiteCoreLicenseService $siteCoreLicense,
        private readonly PluginService $pluginService,
        private readonly PluginSkuCatalogService $pluginSkuCatalog,
    ) {
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $commercial
     * @return self::TIER_*
     */
    public function resolvePurchaseTier(array $manifest, array $commercial = []): string
    {
        $commercialBlock = is_array($manifest['commercial'] ?? null) ? $manifest['commercial'] : [];
        $raw             = strtolower(trim((string) (
            $commercialBlock['purchase_tier']
            ?? $commercial['purchase_tier']
            ?? $commercial['tier']
            ?? ''
        )));
        if (in_array($raw, ['pro', 'professional', 'lifetime', '599'], true)) {
            return self::TIER_PRO;
        }
        if (in_array($raw, ['basic', '299', 'standard'], true)) {
            return self::TIER_BASIC;
        }

        return self::TIER_ALL;
    }

    /** @return self::TIER_* */
    public function siteDomainTier(): string
    {
        $tier = strtolower(trim($this->siteCoreLicense->marketDomainTier()));
        if (in_array($tier, [self::TIER_BASIC, self::TIER_PRO, self::TIER_ALL], true)) {
            return $tier;
        }

        return self::TIER_ALL;
    }

    public function tierMeets(string $required, ?string $current = null): bool
    {
        $required = strtolower(trim($required));
        $current  = $current !== null ? strtolower(trim($current)) : $this->siteDomainTier();
        if ($required === '' || $required === self::TIER_ALL) {
            return true;
        }
        if ($required === self::TIER_BASIC) {
            return in_array($current, [self::TIER_BASIC, self::TIER_PRO], true);
        }
        if ($required === self::TIER_PRO) {
            return $current === self::TIER_PRO;
        }

        return true;
    }

    public function tierLabel(string $tier): string
    {
        return match (strtolower(trim($tier))) {
            self::TIER_BASIC => '需基础版',
            self::TIER_PRO   => '需专业版',
            default          => '全员可购',
        };
    }

    public function upgradeHint(string $required): string
    {
        return match (strtolower(trim($required))) {
            self::TIER_PRO   => '该插件需专业版域名授权（599/终身），请先开通或升级后再购买',
            self::TIER_BASIC => '该插件需基础版域名授权（299/年），请先开通后再购买',
            default          => '',
        };
    }

    /**
     * @param array<string, mixed>      $manifest
     * @param array<string, mixed>      $commercial
     */
    public function assertForPurchase(string $identifier, ?array $manifest = null, array $commercial = []): ?string
    {
        if ($manifest === null) {
            $manifest = $this->pluginService->readManifest($identifier) ?? [];
        }
        if ($commercial === [] && $manifest !== []) {
            $commercial = $this->pluginSkuCatalog->resolveCommercial($identifier, $manifest);
        }
        $required = $this->resolvePurchaseTier($manifest, $commercial);
        if (!$this->tierMeets($required)) {
            return $this->upgradeHint($required);
        }

        return null;
    }

}
