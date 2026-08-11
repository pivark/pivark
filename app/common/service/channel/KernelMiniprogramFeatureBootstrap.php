<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\channel;

use app\common\service\payment\PaymentConfigService;

/** 内核小程序能力注册（form / payment 等） */
final class KernelMiniprogramFeatureBootstrap
{
    public static function register(): void
    {
        $registry = app(MiniprogramFeatureRegistry::class);
        $registry->register('form', '表单', false, static fn (): bool => true);
        $registry->register('payment', '在线支付', true, static fn (): bool => app(PaymentConfigService::class)->isOpen());
    }
}
