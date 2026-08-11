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

final class PaymentOrderFactory
{

    public function __construct(
        private readonly PaymentOrderService $orders,
        private readonly PaymentConfigService $config,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return ServiceResult
     */
    public function create(
        int $userId,
        string $scene,
        int $sceneId,
        float|string|int $amount,
        string $channel,
        array $payload
    ): ServiceResult {
        if ($userId < 1) {
            return ServiceResult::fail('请先登录会员账号');
        }

        $amountPlain = MoneyMath::formatPlain($amount);
        if (bccomp($amountPlain, '0.00', MoneyMath::SCALE) <= 0) {
            return ServiceResult::fail('金额无效');
        }

        return $this->orders->createOrder(
            max(1, $userId),
            $scene,
            $sceneId,
            $amountPlain,
            $this->config->normalizeChannel(trim($channel)),
            $payload
        );
    }

    /**
     * 前台下单：校验渠道可用后再 create
     *
     * @param array<string, mixed> $payload
     * @return ServiceResult
     */
    public function createForFront(
        int $userId,
        string $scene,
        int $sceneId,
        float|string|int $amount,
        string $channel,
        array $payload
    ): ServiceResult {
        $channel = $this->config->normalizeChannel(trim($channel));
        if (!$this->config->isFrontChannelAllowed($channel)) {
            return ServiceResult::fail($this->config->channelUnavailableMessage($channel));
        }

        return $this->create($userId, $scene, $sceneId, $amount, $channel, $payload);
    }

    /** @param array<string, mixed>|ServiceResult $result */
    public function isDemoPaid(array|ServiceResult $result): bool
    {
        if ($result instanceof ServiceResult) {
            $data = $result->dataArray();

            return ($data['type'] ?? '') === 'demo' || !empty($data['demo']);
        }

        return ($result['type'] ?? '') === 'demo' || !empty($result['demo']);
    }
}
