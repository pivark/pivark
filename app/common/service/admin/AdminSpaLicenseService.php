<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

use app\common\service\license\LicenseActivateService;
use app\common\service\license\LicenseSyncService;
use app\common\service\release\CoreUpdateRemoteService;
use app\common\service\site\SiteBrandService;
use app\common\service\site\SiteKeyService;
use app\common\support\ServiceResult;

/** Vue 后台 SPA 授权激活/同步（从 Spa 控制器 batch 9 下沉） */
class AdminSpaLicenseService
{

    public function spaActivate(string $licenseCode): ServiceResult
    {
        app(SiteKeyService::class)->ensure();
        $result = app(LicenseActivateService::class)->activate(
            app(SiteKeyService::class)->get(),
            $licenseCode
        );
        if (!$result->isOk()) {
            return ServiceResult::fail((string) ($result->message() ?? '激活失败'));
        }

        return ServiceResult::ok([
            'activation'    => $result->dataArray() ?? null,
            'licenseStatus' => app(LicenseActivateService::class)->status(),
            'coreUpdate'    => app(CoreUpdateRemoteService::class)->check(),
            'siteBrand'     => app(SiteBrandService::class)->adminPayload(),
        ], (string) ($result->message() ?? '授权已生效'));
    }

    public function spaSync(): ServiceResult
    {
        app(SiteKeyService::class)->ensure();
        $result = app(LicenseSyncService::class)->sync(true);
        if (!$result->isOk()) {
            return ServiceResult::fail((string) ($result->message() ?? '同步失败'));
        }

        return ServiceResult::ok([
            'sync'          => $result->dataArray() ?? null,
            'licenseStatus' => app(LicenseActivateService::class)->status(),
            'coreUpdate'    => app(CoreUpdateRemoteService::class)->check(),
            'siteBrand'     => app(SiteBrandService::class)->adminPayload(),
        ], (string) ($result->message() ?? '同步完成'));
    }
}
