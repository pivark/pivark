<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\plugin;

use app\admin\controller\plugin\concern\PluginAuditActions;
use app\admin\controller\plugin\concern\PluginCommerceActions;
use app\admin\controller\plugin\concern\PluginLifecycleActions;
use app\admin\controller\plugin\concern\PluginMarketActions;
use app\admin\controller\plugin\concern\PluginMetaActions;
use app\admin\controller\plugin\concern\PluginPackageActions;
use app\admin\controller\plugin\concern\PluginPostIdentifier;
use app\admin\controller\plugin\concern\PluginWorkbenchActions;

use app\common\service\auth\CsrfService;
use app\common\service\audit\AuditLogService;
use app\common\service\payment\PaymentConfigService;
use app\common\service\plugin\registry\PluginCapabilityService;
use app\common\service\plugin\commerce\PluginCommercialPricingService;
use app\common\service\plugin\gateway\PluginGatewayAuditService;
use app\common\service\plugin\package\PluginInstallBackupService;
use app\common\service\plugin\market\PluginMarketAutoUpdateService;
use app\common\service\plugin\market\PluginMarketSecuritySyncService;
use app\common\service\plugin\package\PluginPackageAuditService;
use app\common\service\plugin\commerce\PluginWalletService;
use app\common\service\plugin\commerce\PluginCommerceReportService;
use app\common\support\ServiceResult;
use app\common\enum\ApiErrorCode;

use app\common\support\AdminApiResponse;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\commerce\PluginCommerceService;
use app\common\service\plugin\market\PluginMarketAcquireService;
use app\common\service\plugin\market\PluginMarketCatalogService;
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

class Plugin extends \app\admin\controller\Base
{
    use PluginPostIdentifier;
    use PluginWorkbenchActions;
    use PluginMarketActions;
    use PluginLifecycleActions;
    use PluginCommerceActions;
    use PluginPackageActions;
    use PluginAuditActions;
    use PluginMetaActions;

    public function __construct(
        CsrfService $csrf,
        private readonly PivarkEditionService $pivarkEdition,
        private readonly PluginDeveloperWorkbenchService $pluginDeveloperWorkbench,
        private readonly PluginPackageService $pluginPackage,
        private readonly PluginInstallPreflightService $pluginInstallPreflight,
        private readonly PluginSecurityPolicyService $pluginSecurityPolicy,
        private readonly PaymentConfigService $paymentConfig,
        private readonly PluginNav $pluginNav,
        private readonly SiteCoreLicenseService $siteCoreLicense,
        private readonly PluginMarketAutoUpdateService $pluginMarketAutoUpdate,
        private readonly PluginScaffoldService $pluginScaffold,
        private readonly PluginCommercialPricingService $pluginCommercialPricing,
        private readonly PluginPackageAuditService $pluginPackageAudit,
        private readonly PluginMarketSecuritySyncService $pluginMarketSecuritySync,
        private readonly PluginCapabilityService $pluginCapability,
        private readonly AuditLogService $auditLog,
        private readonly PluginGatewayAuditService $pluginGatewayAudit,
        private readonly PluginInstallBackupService $pluginInstallBackup,
        private readonly PluginWalletService $pluginWallet,
        private readonly PluginCommerceReportService $pluginCommerceReport,
        private readonly PluginCommercialPackageService $pluginCommercialPackage,
        private readonly PluginLicenseFileService $pluginLicenseFile,
        private readonly PluginRefundRequestService $pluginRefundRequest,
    ) {
        parent::__construct($csrf);
    }

    private function entitlements(): EntitlementService
    {
        /** @var EntitlementService $svc */
        $svc = AppService::make(EntitlementService::class);

        return $svc;
    }

    private function plugins(): PluginService
    {
        /** @var PluginService $svc */
        $svc = AppService::make(PluginService::class);

        return $svc;
    }

    private function pluginMarketCatalog(): PluginMarketCatalogService
    {
        /** @var PluginMarketCatalogService $svc */
        $svc = AppService::make(PluginMarketCatalogService::class);

        return $svc;
    }

    private function pluginMarketAcquire(): PluginMarketAcquireService
    {
        /** @var PluginMarketAcquireService $svc */
        $svc = AppService::make(PluginMarketAcquireService::class);

        return $svc;
    }

    private function pluginCommerce(): PluginCommerceService
    {
        /** @var PluginCommerceService $svc */
        $svc = AppService::make(PluginCommerceService::class);

        return $svc;
    }

    private function pluginSkuCatalog(): PluginSkuCatalogService
    {
        /** @var PluginSkuCatalogService $svc */
        $svc = AppService::make(PluginSkuCatalogService::class);

        return $svc;
    }

    /** @return ServiceResult|null */
    private function guardPluginCommerceAdmin(): ?ServiceResult
    {
        if ($this->pivarkEdition->allowsPluginCommerceAdmin()) {
            return null;
        }

        return ServiceResult::forbidden('当前站点无插件商业化运营权限');
    }

}
