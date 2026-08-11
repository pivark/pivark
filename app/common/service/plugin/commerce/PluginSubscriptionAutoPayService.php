<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\commerce;

use app\common\model\PaymentOrder;
use app\common\service\member\MemberBalanceService;
use app\common\service\member\MemberMpWechatAuthService;
use app\common\service\payment\PaymentChannelRegistry;
use app\common\service\payment\PaymentConfigService;
use app\common\service\payment\PaymentOrderService;

/** 订阅续费自动扣款：余额优先 → 演示 → 网关 JSAPI 发起 + 查单 */
final class PluginSubscriptionAutoPayService
{
    public function resolveChannelFromGrant(string $grantedBy): string
    {
        $grantedBy = trim($grantedBy);
        if (!str_starts_with($grantedBy, 'order:')) {
            return PaymentConfigService::CHANNEL_BALANCE;
        }

        $orderNo = trim(substr($grantedBy, 6));
        if ($orderNo === '') {
            return PaymentConfigService::CHANNEL_BALANCE;
        }

        $order = app(PaymentOrderService::class)->findByOrderNo($orderNo);
        if (!is_array($order)) {
            return PaymentConfigService::CHANNEL_BALANCE;
        }

        $channel = strtolower(trim((string) ($order['channel'] ?? '')));

        return $channel !== '' ? $channel : PaymentConfigService::CHANNEL_BALANCE;
    }

    public function resolveUserIdFromGrant(string $grantedBy): int
    {
        $grantedBy = trim($grantedBy);
        if (!str_starts_with($grantedBy, 'order:')) {
            return 0;
        }

        $orderNo = trim(substr($grantedBy, 6));
        if ($orderNo === '') {
            return 0;
        }

        $order = PaymentOrder::where('order_no', $orderNo)->find();

        return is_object($order) ? max(0, (int) $order->getAttr('user_id')) : 0;
    }

    /**
     * @param array<string, mixed> $payloadExtras
     */
    public function buildRenewPayloadExtras(int $userId, string $channel): array
    {
        $extras = ['auto_renew' => true];
        if ($channel === PaymentConfigService::CHANNEL_WECHAT) {
            $openid = app(MemberMpWechatAuthService::class)->openidForUser($userId);
            if ($openid !== '') {
                $extras['openid'] = $openid;
            }
        }

        return $extras;
    }

    public function attemptPay(string $orderNo, int $userId, string $preferredChannel): bool
    {
        $orderNo = trim($orderNo);
        if ($orderNo === '' || $userId < 1) {
            return false;
        }

        $paymentCfg = app(PaymentConfigService::class);
        $orders     = app(PaymentOrderService::class);
        $order      = $orders->findByOrderNo($orderNo);
        if (!is_array($order)) {
            return false;
        }

        $preferBalance = (bool) config('plugin.commercial.auto_renew_prefer_balance', true);
        if ($preferBalance || (string) ($order['channel'] ?? '') === PaymentConfigService::CHANNEL_BALANCE) {
            if ($this->attemptBalancePay($orderNo, $userId, $orders)) {
                return true;
            }
        }

        $channel = strtolower(trim((string) ($order['channel'] ?? $preferredChannel)));
        if ($paymentCfg->shouldUseDemo($channel)) {
            $paid = $orders->markPaid($orderNo, 'auto_renew:' . $orderNo, ['auto_renew' => true]);

            return $paid->isOk();
        }

        if (app(PaymentChannelRegistry::class)->isOnline($channel) && $paymentCfg->channelReady($channel)) {
            return $orders->attemptGatewayRenewSettlement($orderNo, $userId);
        }

        return (string) ($order['status'] ?? '') === PaymentOrderService::STATUS_PAID;
    }

    private function attemptBalancePay(string $orderNo, int $userId, PaymentOrderService $orders): bool
    {
        $order = $orders->findByOrderNo($orderNo);
        if (!is_array($order)) {
            return false;
        }
        $amount = round((float) ($order['amount'] ?? 0), 2);
        if ($amount < 0.01) {
            return false;
        }

        $debit = app(MemberBalanceService::class)->adjust(
            $userId,
            -$amount,
            '插件订阅自动续费',
            0,
            MemberBalanceService::payRef($orderNo)
        );
        if (!$debit->isOk()) {
            return false;
        }

        $paid = $orders->markPaid($orderNo, 'balance_auto_renew:' . $orderNo, ['auto_renew' => true]);

        return $paid->isOk();
    }
}
