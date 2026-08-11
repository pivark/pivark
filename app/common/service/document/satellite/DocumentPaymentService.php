<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\document\satellite;

use app\common\support\ServiceResult;

use app\common\service\payment\PaymentConfigService;
use app\common\service\payment\PaymentOrderFactory;
use app\common\service\payment\PaymentOrderService;

class DocumentPaymentService
{

    public function __construct(
        private readonly PaymentConfigService $paymentConfigService,
        private readonly PaymentOrderFactory $paymentOrderFactory,
        private readonly PaymentOrderService $paymentOrderService,
    ) {
    }

    public function enabled(): bool
    {
        return $this->paymentConfigService->isOpen();
    }

    /**
     * @param array<string, mixed> $payload
     * @return ServiceResult
     */
    public function createOrder(
        int $userId,
        string $scene,
        int $sceneId,
        string $channel,
        array $payload = []
    ): ServiceResult {
        if (!$this->enabled()) {
            return ServiceResult::fail('在线支付未开启');
        }
        $amount = round((float) ($payload['amount'] ?? 0), 2);
        if ($amount <= 0) {
            return ServiceResult::fail('金额无效');
        }

        return $this->paymentOrderFactory->create($userId, $scene, $sceneId, $amount, $channel, $payload);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $notifyHeaders
     * @return ServiceResult
     */
    public function handleNotify(
        string $channel,
        array $input,
        string $rawBody = '',
        array $notifyHeaders = []
    ): ServiceResult {
        if (!$this->enabled()) {
            return ServiceResult::fail('在线支付未开启');
        }

        return $this->paymentOrderService->handleNotify($channel, $input, $rawBody, $notifyHeaders);
    }
}
