<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\payment;

use app\common\support\MoneyMath;
use app\common\support\ServiceResult;

/** 支付下单入参校验（纯逻辑 + 通道归一；PaymentOrderService 委托） */
final class PaymentOrderValidator
{
    public function __construct(
        private readonly PaymentConfigService $paymentConfigService,
    ) {
    }

    public function createParams(int $userId, string $scene, float|string|int $amount, string $channel): ServiceResult
    {
        if ($userId < 1) {
            return ServiceResult::fail('请先登录');
        }
        $scene = trim($scene);
        if ($scene === '') {
            return ServiceResult::fail('场景无效');
        }
        $amountPlain = MoneyMath::formatPlain($amount);
        if (bccomp($amountPlain, '0.00', MoneyMath::SCALE) <= 0) {
            return ServiceResult::fail('金额无效');
        }

        return ServiceResult::ok([
            'user_id' => $userId,
            'scene'   => $scene,
            'amount'  => $amountPlain,
            'channel' => $this->paymentConfigService->normalizeChannel($channel),
        ]);
    }
}
