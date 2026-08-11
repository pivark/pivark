<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\payment;
use app\common\support\ServiceResult;

/** 支付履约：委托 PaymentFulfillmentRegistry */
class PaymentFulfillmentService
{

    public function __construct(
        private readonly PaymentFulfillmentRegistry $registry,
    ) {
    }

    /**
     * @param array<string, mixed> $order
     */
    public function fulfill(array $order): ServiceResult
    {
        return $this->registry->dispatch($order);
    }
}
