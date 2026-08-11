<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use think\facade\Cache;

/** 滑动窗口限流（Cache::inc 原子递增 + 首击设 TTL） */
final class RateLimitGuard
{
    public static function allowed(string $key, int $max, int $windowSeconds): bool
    {
        $max = max(1, $max);
        $windowSeconds = max(1, $windowSeconds);
        $count = (int) Cache::inc($key);
        if ($count === 1) {
            Cache::set($key, 1, $windowSeconds);
        }

        return $count <= $max;
    }

    /**
     * @return ServiceResult|null 超限时返回错误结构
     */
    public static function guard(string $key, int $max, int $windowSeconds, string $message = '请求过于频繁，请稍后再试'): ?ServiceResult
    {
        if (self::allowed($key, $max, $windowSeconds)) {
            return null;
        }

        return ServiceResult::rateLimited($message);
    }
}
