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

use app\common\service\document\satellite\DocumentPaymentService;

/**
 * 支付热路径可注入门面（notify / 查单 / 履约 dispatch）。
 */
final class PaymentPublicGateway
{

    public const STATUS_PENDING = PaymentOrderService::STATUS_PENDING;
    public const STATUS_PAID    = PaymentOrderService::STATUS_PAID;

    public function __construct(
        private readonly PaymentOrderService $orders,
    ) {
    }

    public function paymentEnabled(): bool
    {
        return app(DocumentPaymentService::class)->enabled();
    }

    /** @param array<string, mixed>|null $order */
    public function isPaid(?array $order): bool
    {
        return is_array($order) && (string) ($order['status'] ?? '') === self::STATUS_PAID;
    }

    /** @return array<string, mixed>|null */
    public function findByOrderNo(string $orderNo): ?array
    {
        return $this->orders->findByOrderNo($orderNo);
    }

    public function trySyncPaidFromGateway(string $orderNo, int $userId = 0): void
    {
        $this->orders->trySyncPaidFromGateway($orderNo, $userId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return ServiceResult
     */
    public function createOrder(
        int $userId,
        string $scene,
        int $sceneId,
        float|string|int $amount,
        string $channel,
        array $payload = []
    ): ServiceResult {
        return $this->orders->createOrder($userId, $scene, $sceneId, $amount, $channel, $payload);
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
        if (!$this->paymentEnabled()) {
            return ServiceResult::fail('在线支付未开启');
        }

        return $this->orders->handleNotify($channel, $input, $rawBody, $notifyHeaders);
    }

    /**
     * @param array<string, mixed> $order
     * @return ServiceResult
     */
    public function fulfill(array $order): ServiceResult
    {
        return app(PaymentFulfillmentService::class)->fulfill($order);
    }
}
