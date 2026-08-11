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

use app\common\service\plugin\market\PluginMarketAcquireService;
use app\common\service\plugin\market\PluginMarketAutoUpdateService;
use app\common\service\plugin\market\PluginMarketCatalogService;
use app\common\service\plugin\market\PluginMarketSecuritySyncService;

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

trait PluginMarketActions
{
    public function market()
    {
        if (!Request::isAjax()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $keyword = trim((string) Request::get('keyword', ''));
        // 筛条唯一入口：filter_{param_key}=选项值（与 Feed meta.param_filters 同词）
        $paramFilters = [];
        foreach (Request::get() as $k => $v) {
            $key = (string) $k;
            if (!str_starts_with($key, 'filter_')) {
                continue;
            }
            $val = trim((string) $v);
            if ($val !== '') {
                $paramFilters[$key] = $val;
            }
        }
        $limit     = max(1, min(48, (int) Request::get('limit', 12)));
        $offset    = 0;
        $cursor    = trim((string) Request::get('cursor', ''));
        if ($cursor !== '' && ctype_digit($cursor)) {
            $offset = (int) $cursor;
        }
        // 刷新官方货架品项列表：失效 Feed/catalog 缓存后再组页（与「检查更新」合并为同一动作）
        if ((int) Request::get('force_refresh', 0) === 1) {
            PluginMarketCatalogService::flushListCache();
        }
        $page  = $this->pluginMarketCatalog()->catalogPage($keyword, $limit, $offset, $paramFilters);
        $stats = $this->pluginMarketCatalog()->entitlementStats();

        $meta = $this->pluginMarketCatalog()->catalogMeta();
        $meta['has_more']         = (int) ($page['has_more'] ?? 0);
        $meta['next_cursor']      = (string) ($page['next_cursor'] ?? '');
        $meta['pending_updates']  = count($this->pluginMarketCatalog()->listUpdates());
        $roadmap = config('plugin.market.miniprogram_roadmap');
        if (is_array($roadmap)) {
            $meta['miniprogram_roadmap'] = $roadmap;
        }

        return AdminApiResponse::list(['total' => (int) ($page['total'] ?? 0),
            'list'  => $page['list'] ?? [],
            'stats' => $stats,
            'meta'  => $meta]);
    }

    public function marketUpdates()
    {
        if (!Request::isAjax()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        if ((int) Request::get('force_refresh', 0) === 1) {
            PluginMarketCatalogService::flushListCache();
        }

        $list = $this->pluginMarketCatalog()->listUpdates();

        return AdminApiResponse::list([
            'total' => count($list),
            'list'  => $list,
            'meta'  => $this->pluginMarketCatalog()->catalogMeta(),
        ]);
    }

    public function marketApplyUpdates()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->pluginMarketAutoUpdate->applyAll());
    }

    public function marketAcquire()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $identifier = $this->pluginPostIdentifier(false);
        $userId = (int) Request::post('user_id', 0);
        if ($userId < 1) {
            $userId = (int) (Session::get('admin_user.id') ?? 1);
        }

        return AdminApiResponse::admin($this->pluginMarketAcquire()->acquire(
            $identifier,
            $userId,
            trim((string) Request::post('channel', 'demo')),
            in_array(strtolower(trim((string) Request::post('auto_enable', '1'))), ['1', 'true', 'yes'], true),
            in_array(strtolower(trim((string) Request::post('auto_install', '1'))), ['1', 'true', 'yes'], true),
            trim((string) Request::post('sku_id', ''))
        ));
    }

    public function purchased()
    {
        if (Request::isAjax()) {
            $list = $this->entitlements()->listEntitledAdmin();

            return AdminApiResponse::list([
                'total' => count($list),
                'list'  => $list,
            ]);
        }

        return AdminSpa::respond();
    }

    public function marketAuditPackage()
    {
        if (!Request::isAjax() && !Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $identifier = trim((string) Request::param('identifier', ''));
        if ($identifier === '') {
            return AdminApiResponse::fail('请提供 identifier');
        }

        $result = $this->pluginMarketAcquire()->auditRemotePackage($identifier);
        if (!$result->isOk()) {
            return AdminApiResponse::fromResult(ServiceResult::fail((string) ($result->message() ?? '审计失败'), ApiErrorCode::VALIDATION, $result->dataArray() ?? null));
        }

        return AdminApiResponse::fromResult(ServiceResult::ok($result->dataArray() ?? null));
    }

    public function marketSecurityStatus()
    {
        if (!Request::isAjax()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::fromResult(ServiceResult::ok($this->pluginMarketSecuritySync->statusReport()));
    }

    /** POST — 同步远程 blocklist 并停用已装黑名单插件 */
    public function marketSecuritySync()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $result = $this->pluginMarketSecuritySync->enforceInstalledRevocations(true);
        $extra = $result->dataArray() ?? [];
        $extra['snapshots_refreshed'] = $this->pluginCapability->refreshAllEntitlementSnapshots();
        return AdminApiResponse::fromResult($result->isOk()
            ? ServiceResult::ok($extra, (string) ($result->message() ?? ''))
            : ServiceResult::fail((string) ($result->message() ?? '同步失败'), ApiErrorCode::VALIDATION, $extra));
    }

}


