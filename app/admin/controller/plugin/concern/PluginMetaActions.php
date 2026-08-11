<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\plugin\concern;

use app\common\service\auth\CsrfService;
use app\common\service\audit\AuditLogService;
use app\common\service\payment\PaymentConfigService;
use app\common\service\plugin\registry\PluginCapabilityService;
use app\common\service\plugin\commerce\PluginCommercialPricingService;
use app\common\service\plugin\gateway\PluginGatewayAuditService;
use app\common\service\plugin\package\PluginInstallBackupService;


use app\common\service\plugin\package\PluginPackageAuditService;
use app\common\service\plugin\commerce\PluginWalletService;
use app\common\service\plugin\commerce\PluginCommerceReportService;
use app\common\support\ServiceResult;
use app\common\enum\ApiErrorCode;
use app\common\support\AdminApiResponse;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\commerce\PluginCommerceService;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\commerce\PluginSkuCatalogService;
use app\common\service\plugin\commerce\PluginCommercialPackageService;
use app\common\support\LocalFile;
use app\common\service\plugin\manifest\PluginLicenseFileService;
use app\common\service\plugin\package\PluginInstallPreflightService;
use app\common\service\plugin\package\PluginPackageService;
use app\common\service\plugin\security\PluginSecurityPolicyService;
use app\common\service\plugin\scaffold\PluginScaffoldService;

use app\common\service\plugin\commerce\PluginRefundRequestService;
use app\common\service\plugin\scaffold\PluginDeveloperWorkbenchService;
use app\common\service\plugin\weapp\PluginNav;
use app\common\service\plugin\extension\PluginPreflightExtensionAccess;
use app\common\service\release\PivarkEditionService;
use app\common\service\site\SiteCoreLicenseService;
use app\common\support\AppService;
use app\common\support\AdminSpa;
use think\facade\Request;
use think\facade\Session;
use think\Response;

trait PluginMetaActions
{
    public function weappInfo()
    {
        /** @var \app\common\service\admin\AdminSpaPluginMetaService $spaPluginMeta */
        $spaPluginMeta = AppService::make(\app\common\service\admin\AdminSpaPluginMetaService::class);

        return AdminApiResponse::admin($spaPluginMeta->pluginInfo(
            trim((string) Request::get('plugin', ''))
        ));
    }

    /** GET — 插件 weapp 使用说明 / 功能介绍（REST · 原 Spa::weappUsage） */
    public function weappUsage()
    {
        /** @var \app\common\service\admin\AdminSpaPluginMetaService $spaPluginMeta */
        $spaPluginMeta = AppService::make(\app\common\service\admin\AdminSpaPluginMetaService::class);
        try {
            return AdminApiResponse::fromResult(ServiceResult::ok($spaPluginMeta->weappUsage(
                trim((string) Request::get('plugin', '')),
                trim((string) Request::get('section', 'usage'))
            )));
        } catch (\InvalidArgumentException $e) {
            return AdminApiResponse::fail($e->getMessage(), 400);
        }
    }

    /** GET — 插件 identifier 黑名单预检（REST · 原 Spa::pluginIdentifierCheck） */
    public function identifierCheck()
    {
        /** @var \app\common\service\plugin\scaffold\PluginReservedIdentifierService $svc */
        $svc = AppService::make(\app\common\service\plugin\scaffold\PluginReservedIdentifierService::class);

        return AdminApiResponse::fromResult(ServiceResult::ok(
            $svc->checkPayload(trim((string) Request::get('identifier', '')))
        ));
    }

    /** GET — 插件 package 黑名单预检（REST · 原 Spa::pluginPackageCheck） */
    public function packageCheck()
    {
        /** @var \app\common\service\plugin\scaffold\PluginReservedIdentifierService $svc */
        $svc = AppService::make(\app\common\service\plugin\scaffold\PluginReservedIdentifierService::class);

        return AdminApiResponse::fromResult(ServiceResult::ok(
            $svc->checkPackagePayload(trim((string) Request::get('package', '')))
        ));
    }

    /** GET — 单词是否在黑名单（REST · 原 Spa::pluginNamingLookup） */
    public function namingLookup()
    {
        /** @var \app\common\service\plugin\scaffold\PluginReservedIdentifierService $svc */
        $svc = AppService::make(\app\common\service\plugin\scaffold\PluginReservedIdentifierService::class);

        return AdminApiResponse::fromResult(ServiceResult::ok(
            $svc->lookupSegment(trim((string) Request::get('term', '')))
        ));
    }

    /** GET — 命名策略 meta（REST · 原 Spa::pluginNamingPolicy） */
    public function namingPolicy()
    {
        /** @var \app\common\service\plugin\scaffold\PluginReservedIdentifierService $svc */
        $svc = AppService::make(\app\common\service\plugin\scaffold\PluginReservedIdentifierService::class);

        return AdminApiResponse::fromResult(ServiceResult::ok($svc->namingPolicyMeta()));
    }

    /** GET — 插件 SPA 列表（Registry · REST · 原 Spa::pluginWeappList） */
    public function weappList(string $plugin, string $action)
    {
        /** @var \app\common\service\admin\AdminSpaPluginMetaService $spaPluginMeta */
        $spaPluginMeta = AppService::make(\app\common\service\admin\AdminSpaPluginMetaService::class);

        return AdminApiResponse::admin($spaPluginMeta->spaPluginList(
            $plugin,
            $action,
            Request::get(),
        ));
    }
}
