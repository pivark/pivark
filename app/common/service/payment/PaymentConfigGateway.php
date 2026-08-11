<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\payment;

/**
 * 支付配置可注入门面（Phase 2 DI 试点：MemberRecharge 等逐步迁移至此）。
 */
final class PaymentConfigGateway
{

    public const CHANNEL_WECHAT = PaymentConfigService::CHANNEL_WECHAT;
    public const CHANNEL_ALIPAY = PaymentConfigService::CHANNEL_ALIPAY;

    /** @return array<string, mixed> */
    public function frontPaymentFlags(): array
    {
        return app(PaymentConfigService::class)->frontPaymentFlags();
    }

    public function isFrontChannelAllowed(string $channel): bool
    {
        return app(PaymentConfigService::class)->isFrontChannelAllowed($channel);
    }

    public function shouldUseDemo(string $channel): bool
    {
        return app(PaymentConfigService::class)->shouldUseDemo($channel);
    }
}
