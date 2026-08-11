<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\license;

use app\common\service\plugin\extension\HostRuntimeProbe;
use app\common\service\site\SiteKeyService;
use app\common\support\ServiceResult;

/** v1 /api/v1/license/* 门面 */
final class LicensePublicGateway
{

    /** 当前站点是否为已激活的插件宿主（平台宿主等） */
    public function isHostRuntimeActive(): bool
    {
        return HostRuntimeProbe::isAnyHostRuntimeActive();
    }

    public function isValidSiteKey(string $siteKey): bool
    {
        return app(SiteKeyService::class)->isValid($siteKey);
    }

    public function activateCommunity(string $siteKey, string $code): ServiceResult
    {
        return app(LicenseActivateService::class)->activate($siteKey, $code);
    }

    public function activatePlatform(string $siteKey, string $code, string $siteUrl, string $version): ServiceResult
    {
        return HostLicenseRuntime::activate($siteKey, $code, $siteUrl, $version);
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        return app(LicenseActivateService::class)->status();
    }

    /**
     * @param array<string, mixed> $plugins
     * @param array<string, mixed> $telemetry
     */
    public function heartbeat(
        string $siteKey,
        string $siteUrl,
        string $version,
        array $plugins,
        array $telemetry
    ): ServiceResult {
        return HostLicenseRuntime::heartbeat($siteKey, $siteUrl, $version, $plugins, $telemetry);
    }

    public function sync(string $siteKey, string $siteUrl, string $version): ServiceResult
    {
        return HostLicenseRuntime::sync($siteKey, $siteUrl, $version);
    }
}
