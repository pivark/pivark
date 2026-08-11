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
use app\common\service\admin\AdminSpaExplicitRouteRegistry;
use think\facade\Request;
use think\facade\Session;
use think\Response;

trait PluginCommerceActions
{
    private static function commercialPricingFormFromRequest(): ?array
    {
        $raw = Request::post('commercial_pricing', '');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode(trim($raw), true);

            return is_array($decoded) ? $decoded : null;
        }

        $mode = strtolower(trim((string) Request::post('pricing_mode', '')));
        if ($mode === '') {
            return null;
        }

        return [
            'pricing_mode'   => $mode,
            'price'          => Request::post('price', 0),
            'trial_days'     => Request::post('trial_days', 90),
            'period_days'    => Request::post('period_days', 365),
            'trial_quota'    => Request::post('trial_quota', 3),
            'quota_per_pack' => Request::post('quota_per_pack', 1),
        ];
    }

    public function commercialPricingMeta()
    {
        if (!Request::isAjax()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::fromResult(ServiceResult::ok($this->pluginCommercialPricing->pricingMeta()));
    }

    public function commercialPricingForm()
    {
        if (!Request::isAjax()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $identifier = strtolower(trim((string) Request::get('identifier', '')));
        if ($identifier === '') {
            return AdminApiResponse::fail('请提供 identifier');
        }
        $manifest = $this->plugins()->readManifest($identifier);
        if ($manifest === null) {
            return AdminApiResponse::fail('插件不存在');
        }

        $pricing = $this->pluginCommercialPricing;

        return AdminApiResponse::fromResult(ServiceResult::ok([
            'editable' => $pricing->canEditPricing($identifier),
            'form'     => $pricing->formFromManifest($manifest),
            'meta'     => $pricing->pricingMeta(),
        ]));
    }

    public function updateCommercialPricing()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $identifier = $this->pluginPostIdentifier();
        $form       = self::commercialPricingFormFromRequest();
        if ($identifier === '' || !is_array($form)) {
            return AdminApiResponse::fail('参数无效');
        }

        return AdminApiResponse::admin($this->pluginCommercialPricing->applyToWeapp($identifier, $form));
    }

    /** @return array<string, mixed>|null */
    public function skus()
    {
        if (!Request::isAjax()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $skus = $this->pluginCommerce()->listSkus();

        return AdminApiResponse::list(['total' => count($skus),
            'list'  => $skus]);
    }

    public function skuCatalog()
    {
        if (!Request::isAjax()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        if (($deny = $this->guardPluginCommerceAdmin()) !== null) {
            return AdminApiResponse::admin($deny);
        }

        $list = $this->pluginSkuCatalog()->listAdminCatalog();
        $meta = $this->pluginMarketCatalog()->catalogMeta();

        return AdminApiResponse::list(['meta' => $meta,
            'list' => $list]);
    }

    public function skuCatalogActive()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        if (($deny = $this->guardPluginCommerceAdmin()) !== null) {
            return AdminApiResponse::admin($deny);
        }

        return AdminApiResponse::admin($this->pluginSkuCatalog()->setActiveSku(
            $this->pluginPostIdentifier(false),
            trim((string) Request::post('sku_id', ''))
        ));
    }

    public function walletLedger()
    {
        if (!Request::isAjax()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $identifier = strtolower(trim((string) Request::get('identifier', '')));
        $limit      = min(max((int) Request::get('limit', 30), 1), 100);

        return AdminApiResponse::list(['list'  => $this->pluginWallet->listLedger($identifier, $limit),
            'total' => count($this->pluginWallet->listLedger($identifier, $limit))]);
    }

    public function walletCredit()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->pluginWallet->adminCredit(
            $this->pluginPostIdentifier(false),
            (int) Request::post('amount', 0),
            trim((string) Request::post('note', ''))
        ));
    }

    public function createOrder()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->pluginCommerce()->createEntitlementOrder(
            $this->pluginPostIdentifier(false),
            (int) Request::post('user_id', 1),
            trim((string) Request::post('channel', 'balance')),
            Request::post('amount') !== null ? (float) Request::post('amount') : null,
            trim((string) Request::post('sku_id', ''))
        ));
    }

    public function confirmOrder()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->pluginCommerce()->confirmOrderPaid(
            trim((string) Request::post('order_no', ''))
        ));
    }

    public function rollbackOrder()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->pluginCommerce()->rollbackConfirmedOrder(
            trim((string) Request::post('order_no', '')),
            trim((string) Request::post('reason', '')),
            (int) (Session::get('admin_user.id') ?? 0)
        ));
    }

    public function refundOrder()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->pluginCommerce()->refundEntitlementOrder(
            trim((string) Request::post('order_no', '')),
            trim((string) Request::post('reason', '')),
            (int) (Session::get('admin_user.id') ?? 0),
            (int) Request::post('via_gateway', 0) === 1
        ));
    }

    public function orderStatus()
    {
        if (!Request::isAjax()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->pluginCommerce()->orderStatusForAdmin(
            trim((string) Request::param('order_no', ''))
        ));
    }

    public function commerceReport()
    {
        if (!Request::isAjax()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        if (($deny = $this->guardPluginCommerceAdmin()) !== null) {
            return AdminApiResponse::admin($deny);
        }

        $days = max(7, min(365, (int) Request::param('days', 90)));

        return AdminApiResponse::fromResult(ServiceResult::ok($this->pluginCommerceReport->summary($days)));
    }

    public function commerce()
    {
        if ($this->guardPluginCommerceAdmin() !== null) {
            return redirect('/admin/#/plugin/mine');
        }

        return redirect('/admin/#' . (
            app(AdminSpaExplicitRouteRegistry::class)
                ->capabilityPath(AdminSpaExplicitRouteRegistry::CAP_PLUGIN_COMMERCE_ADMIN)
            ?? '/plugin/mine'
        ));
    }

    public function refundRequests()
    {
        if (!Request::isAjax()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        if (($deny = $this->guardPluginCommerceAdmin()) !== null) {
            return AdminApiResponse::admin($deny);
        }

        return AdminApiResponse::fromResult(ServiceResult::ok([
            'list' => $this->pluginRefundRequest->listPending(),
        ]));
    }

    public function refundRequestSubmit()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->pluginRefundRequest->request(
            trim((string) Request::post('order_no', '')),
            0,
            trim((string) Request::post('reason', ''))
        ));
    }

    public function approveRefundRequest()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        if (($deny = $this->guardPluginCommerceAdmin()) !== null) {
            return AdminApiResponse::admin($deny);
        }

        return AdminApiResponse::admin($this->pluginRefundRequest->approve(
            trim((string) Request::post('request_id', '')),
            (int) (Session::get('admin_user.id') ?? 0),
            (int) Request::post('via_gateway', 0) === 1
        ));
    }

    public function rejectRefundRequest()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        if (($deny = $this->guardPluginCommerceAdmin()) !== null) {
            return AdminApiResponse::admin($deny);
        }

        return AdminApiResponse::admin($this->pluginRefundRequest->reject(
            trim((string) Request::post('request_id', '')),
            (int) (Session::get('admin_user.id') ?? 0),
            trim((string) Request::post('reason', ''))
        ));
    }

    /** GET — 插件后台顶栏信息（REST · 原 Spa::weappPluginInfo） */
}
