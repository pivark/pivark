<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\release;

use app\common\service\license\LicenseRemoteClientService;
use app\common\service\payment\PaymentOrderService;
use app\common\service\plugin\extension\HostRuntimeProbe;
use app\common\service\plugin\commerce\PluginCommercialPackageService;
use app\common\support\InstallGate;

final class PivarkEditionService
{

    public function __construct(
        private readonly PaymentOrderService $paymentOrderService,
        private readonly LicenseRemoteClientService $licenseRemoteClientService,
    ) {
    }

    public const COMMUNITY = 'community';
    public const PLATFORM  = 'platform';
    public const DEV       = 'dev';

    public function edition(): string
    {
        $locked = InstallGate::lockedEdition();
        if ($locked !== null) {
            return $locked;
        }

        $raw = defined('PIVARK_EDITION')
            ? (string) PIVARK_EDITION
            : (string) env('PIVARK_EDITION', self::COMMUNITY);
        $e = strtolower(trim($raw));

        return in_array($e, [self::COMMUNITY, self::PLATFORM, self::DEV], true) ? $e : self::COMMUNITY;
    }

    public function isCommunity(): bool
    {
        return $this->edition() === self::COMMUNITY;
    }

    public function isPlatform(): bool
    {
        return $this->edition() === self::PLATFORM;
    }

    public function isDev(): bool
    {
        return $this->edition() === self::DEV;
    }

    public function editionDisplayName(?string $edition = null): string
    {
        $key = strtolower(trim($edition ?? $this->edition()));
        $map = config('pivark.edition_display', []);

        return is_array($map) && isset($map[$key]) ? (string) $map[$key] : $key;
    }

    public function allowsLocalLicenseCodes(): bool
    {
        return $this->isDev();
    }

    public function allowsHostLicensePlatform(): bool
    {
        if (!$this->isPlatform() && !$this->isDev()) {
            return false;
        }

        return filter_var(config('pivark.license_platform_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function requiresRemoteLicenseActivate(): bool
    {
        return $this->isCommunity();
    }

    public function allowsManualGrant(string $identifier, string $licenseType = 'paid'): bool
    {
        if ($this->isDev() || $this->isPlatform()) {
            return true;
        }

        $licenseType = strtolower(trim($licenseType));
        if (!in_array($licenseType, ['free', 'bundled'], true)) {
            return false;
        }

        return app(PluginCommercialPackageService::class)->isSourceOpen($identifier);
    }

    public function allowsEntitlementGrant(
        string $identifier,
        string $grantedBy,
        string $licenseType
    ): bool {
        if ($this->isDev() || $this->isPlatform()) {
            return true;
        }

        $grantedBy   = trim($grantedBy);
        $licenseType = strtolower(trim($licenseType));
        $identifier  = strtolower(trim($identifier));

        if (in_array($grantedBy, ['install', 'migration'], true)) {
            return true;
        }

        if ($grantedBy === 'market' && in_array($licenseType, ['free', 'bundled', 'trial'], true)) {
            return true;
        }

        if (str_starts_with($grantedBy, 'license:') && $this->licenseRemoteClientService->isConfigured()) {
            return true;
        }

        if ($grantedBy === 'manual') {
            return $this->allowsManualGrant($identifier, $licenseType);
        }

        if (str_starts_with($grantedBy, 'order:')) {
            $orderNo = trim(substr($grantedBy, 6));

            return $orderNo !== ''
                && $orderNo !== 'manual'
                && $this->allowsInSitePluginPurchase()
                && $this->allowsPluginEntitlementOrderGrant($orderNo, $identifier);
        }

        if ($grantedBy === 'license_file') {
            return false;
        }

        return false;
    }

    public function allowsInSitePluginPurchase(): bool
    {
        return filter_var(
            config('plugin.commercial.in_site_purchase', true),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * 开源版站内购：仅当插件授权订单已支付且 payload 匹配时允许 grant
     */
    public function allowsPluginEntitlementOrderGrant(string $orderNo, string $identifier): bool
    {
        if ($this->isDev() || $this->isPlatform()) {
            return true;
        }

        if (!$this->allowsInSitePluginPurchase()) {
            return false;
        }

        $orderNo    = trim($orderNo);
        $identifier = strtolower(trim($identifier));
        if ($orderNo === '' || $identifier === '') {
            return false;
        }

        $order = $this->paymentOrderService->findByOrderNo($orderNo);
        if ($order === null) {
            return false;
        }
        if ((string) ($order['status'] ?? '') !== PaymentOrderService::STATUS_PAID) {
            return false;
        }
        if ((string) ($order['scene'] ?? '') !== PaymentOrderService::SCENE_PLUGIN_ENTITLEMENT) {
            return false;
        }

        $payload = json_decode((string) ($order['payload_json'] ?? ''), true);

        return is_array($payload)
            && strtolower(trim((string) ($payload['plugin_identifier'] ?? ''))) === $identifier;
    }

    public function allowsOfflineLicenseFile(): bool
    {
        return $this->isDev() || $this->isPlatform();
    }

    /**
     * 后台插件订单/入账 API 与页面门闩（宿主 runtime）。
     */
    public function allowsPluginCommerceAdmin(): bool
    {
        if (!HostRuntimeProbe::licenseCatalogReady()) {
            return false;
        }

        return HostRuntimeProbe::isAnyHostRuntimeActive();
    }
}
