<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\market;

use app\common\support\ServiceResult;
use app\common\service\plugin\boot\PluginBootService;
use app\common\service\plugin\gateway\PluginGatewayAuditService;
use app\common\service\plugin\registry\PluginCapabilityService;
use app\common\service\plugin\market\PluginMarketCatalogSecurityService;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\security\PluginSecurityPolicyService;
use app\common\service\plugin\market\PluginMarketBlocklistService;
use app\common\service\plugin\market\PluginMarketShelfDirectory;

use app\common\model\Plugin;
use app\common\service\audit\AuditLogService;

final class PluginMarketSecuritySyncService
{
    public function __construct(
        private readonly PluginMarketShelfDirectory $pluginMarketRemoteCatalog,
        private readonly PluginMarketBlocklistService $pluginMarketBlocklistService,
        private readonly PluginService $pluginService,
        private readonly AuditLogService $auditLogService,
        private readonly PluginMarketCatalogSecurityService $pluginMarketCatalogSecurityService,
        private readonly PluginCapabilityService $pluginCapabilityService,
        private readonly PluginGatewayAuditService $pluginGatewayAuditService,
    ) {
    }

    /**
     * @param bool $refreshRemoteCatalog 为 true 时强制刷新远程 catalog（手动同步 / cron）
     *
     * @return ServiceResult
     */
    public function enforceInstalledRevocations(bool $refreshRemoteCatalog = false): ServiceResult
    {
        if (!(bool) config('plugin.security.auto_disable_blocked_installed', true)) {
            return ServiceResult::ok(['disabled' => [], 'blocked' => []], '已跳过（auto_disable_blocked_installed=0）');
        }

        if ($refreshRemoteCatalog) {
            $this->pluginMarketRemoteCatalog->invalidateCache();
            PluginBootService::clearBlocklistBootstrapThrottle();
        }

        $blocked   = $this->pluginMarketBlocklistService->blockedIdentifiers();
        $disabled  = [];
        $warned    = [];
        $uninstalled = [];

        if ($blocked === []) {
            return ServiceResult::ok(['disabled' => [], 'blocked' => [], 'warned' => [], 'uninstalled' => []], '无远程下架项');
        }

        $rows = Plugin::where('installed', 1)->select()->toArray();
        foreach ($rows as $row) {
            $id = strtolower(trim((string) ($row['identifier'] ?? '')));
            if ($id === '' || !in_array($id, $blocked, true)) {
                continue;
            }

            $entry    = $this->pluginMarketBlocklistService->entry($id);
            $severity = $this->pluginMarketBlocklistService->effectiveSeverity(is_array($entry) ? $entry : []);
            $reason   = $this->pluginMarketBlocklistService->blockReason($id);

            if ($severity === PluginMarketBlocklistService::SEVERITY_WARN) {
                $warned[] = $id;
                $this->auditLogService->operate('远程下架安全警告', 'admin.plugin', [
                    'identifier' => $id,
                    'reason'     => $reason,
                ]);
                continue;
            }

            if (
                $severity === PluginMarketBlocklistService::SEVERITY_UNINSTALL
                && (bool) config('plugin.security.blocklist_auto_uninstall', false)
                && (int) ($row['enabled'] ?? 0) === 1
            ) {
                $purge = trim((string) config('plugin.security.blocklist_uninstall_purge', 'register'));
                if (!in_array($purge, ['register', 'config', 'data', 'full'], true)) {
                    $purge = 'register';
                }
                $result = $this->pluginService->uninstall($id, $purge);
                if ($result->isOk()) {
                    $uninstalled[] = $id;
                    $this->auditLogService->operate('远程下架自动卸载插件', 'admin.plugin', [
                        'identifier' => $id,
                        'reason'     => $reason,
                        'purge'      => $purge,
                    ]);
                }
                continue;
            }

            if ((int) ($row['enabled'] ?? 0) !== 1) {
                continue;
            }

            $result = $this->pluginService->disable($id);
            if ($result->isOk()) {
                $disabled[] = $id;
                $this->auditLogService->operate('远程下架自动停用插件', 'admin.plugin', [
                    'identifier' => $id,
                    'reason'     => $reason,
                    'severity'   => $severity,
                ]);
            }
        }

        $parts = [];
        if ($warned !== []) {
            $parts[] = count($warned) . ' 个警告期';
        }
        if ($disabled !== []) {
            $parts[] = '停用 ' . count($disabled) . ' 个';
        }
        if ($uninstalled !== []) {
            $parts[] = '卸载 ' . count($uninstalled) . ' 个';
        }
        $msg = $parts === []
            ? '已检查 blocklist，无需处置'
            : '已处置下架插件：' . implode('，', $parts);

        return ServiceResult::ok([
            'disabled'    => $disabled,
            'blocked'     => $blocked,
            'warned'      => $warned,
            'uninstalled' => $uninstalled,
        ], $msg);
    }

    /**
     * @return array{
     *   local:list<array<string,mixed>>,
     *   remote:list<array<string,mixed>>,
     *   installed_blocked:list<array<string,mixed>>
     * }
     */
    public function statusReport(): array
    {
        $local  = [];
        foreach ($this->pluginMarketBlocklistService->localEntries() as $id => $entry) {
            $local[] = array_merge(['identifier' => $id], $entry);
        }

        $remote = [];
        foreach ($this->pluginMarketCatalogSecurityService->remoteBlocklistEntries() as $id => $entry) {
            $remote[] = array_merge(['identifier' => $id], $entry);
        }

        $installedBlocked = [];
        $blockedSet       = array_flip($this->pluginMarketBlocklistService->blockedIdentifiers());
        $rows             = Plugin::where('installed', 1)->select()->toArray();
        foreach ($rows as $row) {
            $id = strtolower(trim((string) ($row['identifier'] ?? '')));
            if ($id === '' || !isset($blockedSet[$id])) {
                continue;
            }
            $installedBlocked[] = [
                'identifier' => $id,
                'enabled'    => (int) ($row['enabled'] ?? 0),
                'reason'     => $this->pluginMarketBlocklistService->blockReason($id),
            ];
        }

        $diagnostics = app(PluginSecurityPolicyService::class)->adminDiagnosticsPanel();
        $rawBoot     = $this->pluginService->bootFailures();

        return [
            'local'              => $local,
            'remote'             => $remote,
            'installed_blocked'  => $installedBlocked,
            'safe_mode'          => $this->pluginService->isSafeMode(),
            'safe_mode_env'      => (bool) config('plugin.security.safe_mode', false),
            'boot_failures'      => $diagnostics
                ? $rawBoot
                : app(PluginSecurityPolicyService::class)->userBootFailures($rawBoot),
            'diagnostics_panel'  => $diagnostics,
            'market_compliance_panel' => app(PluginSecurityPolicyService::class)->marketCompliancePanel(),
            'gateway_violations' => $diagnostics ? $this->gatewayViolationSummary() : [],
            'capability_gaps'    => $this->pluginCapabilityService->enabledDependencyGaps(),
            'feature_subset_gaps' => $diagnostics
                ? $this->pluginCapabilityService->enabledFeatureSubsetGaps()
                : [],
            'snapshot_drifts'    => $diagnostics
                ? $this->pluginCapabilityService->enabledSnapshotDrifts()
                : [],
        ];
    }

    /**
     * @return list<array{identifier:string,total:int,violations:list<array{file:string,messages:list<string>}>}>
     */
    private function gatewayViolationSummary(): array
    {
        $out = [];
        $scopes = $this->pluginGatewayAuditService->normalizeScopes('service,api,admin,boot');
        foreach ($this->pluginService->listInstalledIdentifiers() as $id) {
            $total = 0;
            $violations = [];
            foreach ($scopes as $scope) {
                $report = $this->pluginGatewayAuditService->auditPluginDirectory($id, $scope);
                $total += $report['total'];
                foreach ($report['violations'] as $row) {
                    $violations[] = $row;
                }
            }
            if ($total < 1) {
                continue;
            }
            $out[] = [
                'identifier' => $id,
                'total'      => $total,
                'violations' => $violations,
            ];
        }

        return $out;
    }
}
