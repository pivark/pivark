<?php
/**
 * 后台 REST 插件中心路由（/api/v1/admin/plugins/*）
 */
declare(strict_types=1);

use app\admin\controller\plugin\Plugin;

/** @param array{0:class-string,1:string} $handler */
$pr = static function (string $method, string $path, array $handler, ?string $permAction = null): array {
    return [
        'method'  => $method,
        'path'    => $path === '' ? 'plugins' : 'plugins/' . $path,
        'handler' => $handler,
        'options' => [
            'permission_controller' => 'plugin',
            'permission_action'     => $permAction ?? strtolower((string) $handler[1]),
        ],
    ];
};

return [
    $pr('GET', '', [Plugin::class, 'index'], 'index'),
    $pr('GET', 'cloud', [Plugin::class, 'cloud']),
    $pr('GET', 'scaffold', [Plugin::class, 'scaffold']),
    $pr('GET', 'workbench', [Plugin::class, 'workbench']),
    $pr('GET', 'workbench/meta', [Plugin::class, 'workbenchMeta']),
    $pr('GET', 'workbench/gateways', [Plugin::class, 'workbenchGateways']),
    $pr('GET', 'workbench/extension-points', [Plugin::class, 'workbenchExtensionPoints']),
    $pr('POST', 'workbench/validate-manifest', [Plugin::class, 'workbenchValidateManifest']),
    $pr('GET', 'suggest-package', [Plugin::class, 'suggestPackage']),
    $pr('GET', 'preflight', [Plugin::class, 'preflight']),
    $pr('GET', 'upgrade-preflight', [Plugin::class, 'upgradePreflight']),
    $pr('GET', 'capabilities', [Plugin::class, 'capabilities']),
    $pr('GET', 'security-policy', [Plugin::class, 'securityPolicy']),
    $pr('GET', 'market/updates', [Plugin::class, 'marketUpdates']),
    $pr('POST', 'market/apply-updates', [Plugin::class, 'marketApplyUpdates']),
    $pr('POST', 'market/acquire', [Plugin::class, 'marketAcquire']),
    $pr('GET', 'purchased', [Plugin::class, 'purchased']),
    $pr('GET', 'pack-stats', [Plugin::class, 'packStats'], 'export'),
    $pr('GET', 'export', [Plugin::class, 'export']),
    $pr('GET', 'export-encoded', [Plugin::class, 'exportEncoded']),
    $pr('GET', 'export-license', [Plugin::class, 'exportLicense']),
    $pr('POST', 'import-license', [Plugin::class, 'importLicense']),
    $pr('POST', 'install', [Plugin::class, 'install']),
    $pr('POST', 'upload', [Plugin::class, 'upload']),
    $pr('POST', 'audit-package', [Plugin::class, 'auditPackage']),
    $pr('GET', 'market/audit-package', [Plugin::class, 'marketAuditPackage']),
    $pr('GET', 'market/security-status', [Plugin::class, 'marketSecurityStatus']),
    $pr('POST', 'market/security-sync', [Plugin::class, 'marketSecuritySync']),
    $pr('POST', 'refresh-capability-snapshots', [Plugin::class, 'refreshCapabilitySnapshots']),
    $pr('POST', 'toggle-safe-mode', [Plugin::class, 'toggleSafeMode']),
    $pr('GET', 'gateway-audit', [Plugin::class, 'gatewayAudit']),
    $pr('GET', 'capability-status', [Plugin::class, 'capabilityStatus']),
    $pr('GET', 'install-backups', [Plugin::class, 'installBackups']),
    $pr('POST', 'restore-install-backup', [Plugin::class, 'restoreInstallBackup']),
    $pr('POST', 'create-scaffold', [Plugin::class, 'createScaffold']),
    $pr('GET', 'download-scaffold-zip', [Plugin::class, 'downloadScaffoldZip'], 'createscaffold'),
    $pr('POST', 'uninstall', [Plugin::class, 'uninstall']),
    $pr('POST', 'upgrade', [Plugin::class, 'upgrade']),
    $pr('POST', 'enable', [Plugin::class, 'enable']), // 停用：同路径 POST ack_slot_conflict=2
    $pr('POST', 'switch', [Plugin::class, 'enable']), // WAF 常拦 /enable；SPA 优先走 switch
    $pr('POST', 'disable', [Plugin::class, 'disable']),
    $pr('POST', 'deactivate', [Plugin::class, 'disable']),
    $pr('POST', 'lifecycle/enable', [Plugin::class, 'enable']),
    $pr('POST', 'lifecycle/disable', [Plugin::class, 'disable']),
    $pr('POST', 'grant', [Plugin::class, 'grant']),
    $pr('POST', 'revoke-entitlement', [Plugin::class, 'revokeEntitlement']),
    $pr('GET', 'skus', [Plugin::class, 'skus']),
    $pr('GET', 'sku-catalog', [Plugin::class, 'skuCatalog']),
    $pr('POST', 'sku-catalog/active', [Plugin::class, 'skuCatalogActive']),
    $pr('GET', 'commercial-pricing/meta', [Plugin::class, 'commercialPricingMeta']),
    $pr('GET', 'commercial-pricing/form', [Plugin::class, 'commercialPricingForm']),
    $pr('POST', 'commercial-pricing', [Plugin::class, 'updateCommercialPricing']),
    $pr('GET', 'wallet/ledger', [Plugin::class, 'walletLedger']),
    $pr('POST', 'wallet/credit', [Plugin::class, 'walletCredit']),
    $pr('POST', 'orders', [Plugin::class, 'createOrder']),
    $pr('POST', 'orders/confirm', [Plugin::class, 'confirmOrder']),
    $pr('POST', 'orders/rollback', [Plugin::class, 'rollbackOrder']),
    $pr('POST', 'orders/refund', [Plugin::class, 'refundOrder']),
    $pr('GET', 'refund-requests', [Plugin::class, 'refundRequests']),
    $pr('POST', 'refund-requests', [Plugin::class, 'refundRequestSubmit']),
    $pr('POST', 'refund-requests/approve', [Plugin::class, 'approveRefundRequest']),
    $pr('POST', 'refund-requests/reject', [Plugin::class, 'rejectRefundRequest']),
    $pr('GET', 'orders/status', [Plugin::class, 'orderStatus']),
    $pr('GET', 'commerce/report', [Plugin::class, 'commerceReport']),
    $pr('GET', 'commerce', [Plugin::class, 'commerce']),
    $pr('GET', 'weapp-info', [Plugin::class, 'weappInfo'], 'weappplugininfo'),
    $pr('GET', 'weapp-usage', [Plugin::class, 'weappUsage'], 'weappusage'),
    $pr('GET', 'identifier-check', [Plugin::class, 'identifierCheck'], 'identifiercheck'),
    $pr('GET', 'package-check', [Plugin::class, 'packageCheck'], 'packagecheck'),
    $pr('GET', 'naming-lookup', [Plugin::class, 'namingLookup'], 'naminglookup'),
    $pr('GET', 'naming-policy', [Plugin::class, 'namingPolicy'], 'namingpolicy'),
    // 须在 plugins/market/* 子路由之后注册；ThinkPHP 叶子 GET market 见 admin_rest/thinkphp_leaf_routes.php
    $pr('GET', 'market', [Plugin::class, 'market']),
    // ThinkPHP 叶子 market 常 404；index 与 updates 同级，供 SPA 稳定命中
    $pr('GET', 'market/index', [Plugin::class, 'market']),
];
