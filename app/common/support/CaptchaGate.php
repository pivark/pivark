<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use app\common\service\infra\RateLimitGateway;

/** 验证码出图频率限制（委托 RateLimitGateway） */
class CaptchaGate
{
    public static function guardImageFetch(string $scene, string $ip): ?string
    {
        $blocked = app(RateLimitGateway::class)->check('captcha.image', $ip, ['scene' => $scene]);
        if ($blocked !== null) {
            return (string) ($blocked->msg ?? '验证码请求过于频繁，请稍后再试');
        }

        return null;
    }
}
