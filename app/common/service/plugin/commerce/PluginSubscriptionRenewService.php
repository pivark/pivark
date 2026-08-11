<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\commerce;

use app\common\service\plugin\entitlement\EntitlementQueryService;
use app\common\service\audit\AuditLogService;
use app\common\service\payment\PaymentConfigService;
use app\common\service\payment\PaymentOrderService;
use app\common\service\plugin\commerce\PluginCommerceService;
use app\common\service\plugin\commerce\PluginSkuCatalogService;
use app\common\support\AppTime;

/** 订阅类授权到期前自动续费（Cron · 余额/演示/网关查单） */
final class PluginSubscriptionRenewService
{
    /**
     * @return array{scanned:int,renewed:int,reminded:int,skipped:int,failed:int}
     */
    public function runDueRenewals(): array
    {
        $stats = [
            'scanned'  => 0,
            'renewed'  => 0,
            'reminded' => 0,
            'skipped'  => 0,
            'failed'   => 0,
        ];

        if (!(bool) config('plugin.commercial.auto_renew_enabled', false)) {
            return $stats;
        }

        $autoPay = app(PluginSubscriptionAutoPayService::class);
        $daysBefore = max(1, (int) config('plugin.commercial.auto_renew_days_before', 3));
        $now        = AppTime::now();
        $windowEnd  = AppTime::format('Y-m-d H:i:s', strtotime('+' . $daysBefore . ' days'));

        $rows = app(EntitlementQueryService::class)->listActiveExpiringInWindow($now, $windowEnd);

        foreach ($rows as $row) {
            $stats['scanned']++;
            $identifier = strtolower(trim((string) ($row['plugin_identifier'] ?? '')));
            if ($identifier === '') {
                $stats['skipped']++;
                continue;
            }

            $manifest = app(PluginService::class)->readManifest($identifier);
            if ($manifest === null || empty($manifest['_manifest_valid'])) {
                $stats['skipped']++;
                continue;
            }

            $commercial = app(PluginSkuCatalogService::class)->resolveCommercial($identifier, $manifest);
            if (!$this->isAutoRenewCommercial($commercial)) {
                $stats['skipped']++;
                continue;
            }

            if (!(bool) config('plugin.commercial.auto_renew_apply', false)) {
                app(AuditLogService::class)->operate('插件订阅续费待办', 'admin.plugin', [
                    'identifier' => $identifier,
                    'expire_at'  => (string) ($row['expire_at'] ?? ''),
                ]);
                $stats['reminded']++;
                continue;
            }

            $grantedBy = (string) ($row['granted_by'] ?? '');
            $userId    = $autoPay->resolveUserIdFromGrant($grantedBy);
            if ($userId < 1) {
                $stats['skipped']++;
                continue;
            }

            $channel  = $autoPay->resolveChannelFromGrant($grantedBy);
            $skuId    = $this->resolveSkuId($row, $commercial);
            $extras   = $autoPay->buildRenewPayloadExtras($userId, $channel);
            $create   = app(PluginCommerceService::class)->createEntitlementOrder(
                $identifier,
                $userId,
                $channel,
                null,
                $skuId,
                $extras
            );
            if (!$create->isOk()) {
                $stats['failed']++;
                continue;
            }

            $orderNo = (string) ($create->dataArray()['order_no'] ?? '');
            if ($orderNo === '') {
                $stats['failed']++;
                continue;
            }

            if (!$autoPay->attemptPay($orderNo, $userId, $channel)) {
                app(AuditLogService::class)->operate('插件订阅续费订单待支付', 'admin.plugin', [
                    'identifier' => $identifier,
                    'order_no'   => $orderNo,
                    'user_id'    => $userId,
                    'channel'    => $channel,
                ]);
                $stats['reminded']++;
                continue;
            }

            $order = app(PaymentOrderService::class)->findByOrderNo($orderNo);
            if (!is_array($order)) {
                $stats['failed']++;
                continue;
            }

            $fulfill = app(PluginCommerceService::class)->fulfillEntitlement($order);
            if ($fulfill->isOk()) {
                $stats['renewed']++;
            } else {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $commercial
     */
    private function isAutoRenewCommercial(array $commercial): bool
    {
        $model = strtolower(trim((string) ($commercial['model'] ?? '')));
        $billing = strtolower(trim((string) ($commercial['billing_type'] ?? '')));
        if ($model !== 'subscription' && !in_array($billing, [
            PluginSkuCatalogService::BILLING_SUBSCRIPTION_TIME,
            PluginSkuCatalogService::BILLING_SUBSCRIPTION_QUOTA,
        ], true)) {
            return false;
        }

        return filter_var($commercial['auto_renew'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $commercial
     */
    private function resolveSkuId(array $row, array $commercial): string
    {
        $snapshot = json_decode((string) ($row['capability_snapshot_json'] ?? ''), true);
        if (is_array($snapshot)) {
            $sku = trim((string) ($snapshot['active_sku_id'] ?? ''));
            if ($sku !== '') {
                return $sku;
            }
        }

        return trim((string) ($commercial['sku_id'] ?? ''));
    }
}
