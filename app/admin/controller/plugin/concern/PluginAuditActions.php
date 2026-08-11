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

trait PluginAuditActions
{
    public function refreshCapabilitySnapshots()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        if (!$this->pluginSecurityPolicy->adminDiagnosticsPanel()) {
            return AdminApiResponse::fail('当前站点未开启插件开发诊断');
        }

        $count = $this->pluginCapability->refreshAllEntitlementSnapshots();
        $this->auditLog->operate('刷新插件能力快照', 'admin.plugin', [
            'snapshots_refreshed' => $count,
        ]);

        return AdminApiResponse::fromResult(ServiceResult::ok(['snapshots_refreshed' => $count], $count > 0 ? ('已刷新' . $count . ' 个插件能力快照') : '无已授权插件需刷新'));
    }

    public function toggleSafeMode()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        if (!$this->pluginSecurityPolicy->adminDiagnosticsPanel()) {
            return AdminApiResponse::fail('当前站点未开启插件开发诊断');
        }

        $enable = in_array(strtolower(trim((string) Request::post('enable', '1'))), ['1', 'true', 'yes'], true);
        $result = $this->plugins()->setRuntimeSafeMode($enable);

        return AdminApiResponse::fromResult($result->isOk()
            ? ServiceResult::ok(['safe_mode' => (bool) ($result->dataArray()['safe_mode'] ?? false)], (string) ($result->message() ?? ''))
            : ServiceResult::fail((string) ($result->message() ?? '操作失败')));
    }

    public function gatewayAudit()
    {
        if (!Request::isAjax()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        if (!$this->pluginSecurityPolicy->marketCompliancePanel()
            && !$this->pluginSecurityPolicy->adminDiagnosticsPanel()) {
            return AdminApiResponse::fail('无权访问插件 Gateway 审计');
        }

        $identifier = strtolower(trim((string) Request::get('identifier', '')));
        $scopeArg   = strtolower(trim((string) Request::get('scope', 'service')));
        if ($scopeArg === '') {
            $scopeArg = 'service';
        }
        $scope = $this->pluginGatewayAudit->normalizeScopes($scopeArg);

        if ($identifier !== '') {
            if (count($scope) === 1) {
                $data = $this->pluginGatewayAudit->auditPluginDirectory($identifier, $scope[0]);
            } else {
                $data = [
                    'identifier' => $identifier,
                    'reports'    => array_map(
                        static fn (string $one): array => $this->pluginGatewayAudit->auditPluginDirectory($identifier, $one),
                        $scope
                    ),
                ];
            }
        } else {
            $data = [
                'plugins' => $this->pluginGatewayAudit->auditInstalledPlugins($scope),
            ];
        }

        return AdminApiResponse::fromResult(ServiceResult::ok($data));
    }

    public function capabilityStatus()
    {
        if (!Request::isAjax()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $identifier = strtolower(trim((string) Request::get('identifier', '')));
        if ($identifier !== '') {
            $data = $this->pluginCapability->summary($identifier);
        } else {
            $data = [
                'gaps' => $this->pluginCapability->enabledDependencyGaps(),
            ];
        }

        return AdminApiResponse::fromResult(ServiceResult::ok($data));
    }

}
