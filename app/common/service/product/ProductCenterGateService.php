<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\product;

use app\common\enum\ApiErrorCode;
use app\common\service\plugin\extension\PluginOfficialProduct;
use app\common\service\license\LicenseBundledEnhancementService;
use app\common\service\plugin\commerce\PluginDomainPurchaseGateService;
use app\common\service\release\PivarkEditionService;
use app\common\service\site\SiteCoreLicenseService;
use app\common\support\ServiceResult;

/** 产品中心档位门禁 + product_open 展示面开关 */
final class ProductCenterGateService
{
    public function __construct(
        private readonly PivarkEditionService $pivarkEditionService,
        private readonly SiteCoreLicenseService $siteCoreLicenseService,
        private readonly LicenseBundledEnhancementService $licenseBundledEnhancementService,
        private readonly PluginDomainPurchaseGateService $pluginDomainPurchaseGateService,
    ) {
    }

    public static function entitled(): bool
    {
        return app(self::class)->allowsAdmin();
    }

    /** 前台产品面（栏目/品项列表/详情/筛选/sitemap）；无 Pro 或关 product_open 则整面关闭 */
    public static function publicSurfaceOpen(): bool
    {
        return self::entitled() && ProductConfigService::isOpen();
    }

    /** @return ServiceResult|null null 表示通过 */
    public static function requirePublicApi(): ?ServiceResult
    {
        if (!self::entitled()) {
            return ServiceResult::fail(
                app(SiteCoreLicenseService::class)->proRequiredMessage(),
                ApiErrorCode::CORE_LICENSE_PRO_REQUIRED
            );
        }
        if (!ProductConfigService::isOpen()) {
            return ServiceResult::fail('产品展示已关闭');
        }

        return null;
    }

    /** @return ServiceResult|null */
    public static function requireDisplaySurface(): ?ServiceResult
    {
        return self::requirePublicApi();
    }

    /** 后台侧栏、参数维护、文档产品 Tab */
    public function allowsAdmin(): bool
    {
        if (PluginOfficialProduct::productCenterAdminEnabled()) {
            return true;
        }

        if (!$this->appliesLicenseTierGate()) {
            return true;
        }

        return $this->allowsAdminForLicensedSite();
    }

    /** Community 按档位；dev/platform 写入 site_core_license_tier 后才套档位 */
    public function appliesLicenseTierGate(): bool
    {
        if ($this->pivarkEditionService->isCommunity()) {
            return true;
        }

        $force = strtolower(trim((string) env('PIVARK_PRODUCT_CENTER_LICENSE_GATE', '')));
        if (in_array($force, ['1', 'true', 'yes', 'force'], true)) {
            return true;
        }

        return $this->siteCoreLicenseService->tier() !== '';
    }

    private function allowsAdminForLicensedSite(): bool
    {
        $tier = strtolower(trim($this->siteCoreLicenseService->tier()));
        if ($tier !== '') {
            return $this->licenseBundledEnhancementService->isProPlusTier($tier);
        }

        return $this->pluginDomainPurchaseGateService->tierMeets(
            PluginDomainPurchaseGateService::TIER_PRO
        );
    }

    /** 参数组 / attrs / 筛选 API */
    public function allowsParams(): bool
    {
        return $this->allowsAdmin();
    }

    /** 后台 SPA meta / admin API */
    public function allowsAdminApi(): bool
    {
        return $this->allowsAdmin();
    }

    /** 前台 {pv:product*} / 文档读侧（另受 product_open） */
    public function allowsFrontBridge(): bool
    {
        return $this->allowsAdmin();
    }
}
