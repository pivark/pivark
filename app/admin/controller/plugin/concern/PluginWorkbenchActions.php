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

trait PluginWorkbenchActions
{
    public function index()
    {
        $lite = Request::isAjax() || Request::get('lite') === '1';
        if (Request::isAjax()) {
            $list = $this->plugins()->listAdmin($lite);

            return AdminApiResponse::list(['total' => count($list),
                'list'  => $list]);
        }

        return $this->renderView('plugin/index', [
            'list' => $this->plugins()->listAdmin($lite),
        ]);
    }

    public function cloud()
    {
        return AdminSpa::respond();
    }

    public function scaffold()
    {
        return AdminSpa::respond();
    }

    public function workbench()
    {
        return AdminSpa::respond();
    }

    public function workbenchMeta()
    {
        if (!Request::isAjax()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::fromResult(ServiceResult::ok($this->pluginDeveloperWorkbench->meta()));
    }

    public function workbenchGateways()
    {
        if (!Request::isAjax()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::fromResult(ServiceResult::ok([
            'gateways' => $this->pluginDeveloperWorkbench->gatewayCatalog(),
        ]));
    }

    public function workbenchExtensionPoints()
    {
        if (!Request::isAjax()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::fromResult(ServiceResult::ok(
            $this->pluginDeveloperWorkbench->extensionPoints()
        ));
    }

    public function workbenchValidateManifest()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $json = (string) Request::post('manifest_json', '');
        if ($json === '') {
            $json = (string) Request::post('json', '');
        }

        return AdminApiResponse::fromResult($this->pluginDeveloperWorkbench->validateManifestJson($json));
    }

    public function suggestPackage()
    {
        return AdminApiResponse::fromResult(ServiceResult::ok(['package' => $this->pluginPackage->allocateUniquePackage()]));
    }

    public function preflight()
    {
        $adminId = (int) (Session::get('admin_user.id') ?? 0);

        return AdminApiResponse::fromResult(ServiceResult::ok(array_merge(
            $this->pluginInstallPreflight->environmentPublic(true),
            [
                'security'         => $this->pluginSecurityPolicy->summaryForAdmin($adminId),
                'payment'          => $this->paymentConfig->adminPluginPaymentMeta(),
                'module_nav'       => $this->pluginNav->items(),
                'commerce_admin'   => $this->pivarkEdition->allowsPluginCommerceAdmin(),
                'site_core_license'=> $this->siteCoreLicense->marketPreflight(),
            ],
            PluginPreflightExtensionAccess::collectFlags(),
        )));
    }

    public function securityPolicy()
    {
        if (!Request::isAjax()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $adminId = (int) (Session::get('admin_user.id') ?? 0);

        return AdminApiResponse::fromResult(ServiceResult::ok($this->pluginSecurityPolicy->summaryForAdmin($adminId)));
    }

    public function capabilities()
    {
        if (!Request::isAjax()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $identifier = trim((string) Request::param('identifier', ''));
        if ($identifier === '') {
            return AdminApiResponse::fail('请提供 identifier');
        }
        $row = $this->plugins()->adminRow($identifier, false);
        if ($row === null) {
            return AdminApiResponse::fail('插件不存在');
        }

        return AdminApiResponse::fromResult(ServiceResult::ok($row['capabilities'] ?? null));
    }

}
