<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/**
 * HTTP 响应结束后再跑重活（PHP-FPM：fastcgi_finish_request）。
 * 预热 / 心跳等 shutdown 任务须先调此方法，避免拖住后台 AJAX 直至超时。
 */
final class HttpResponseFinish
{
    private static bool $finished = false;

    public static function finishIfPossible(): void
    {
        if (self::$finished) {
            return;
        }
        self::$finished = true;
        if (\function_exists('fastcgi_finish_request')) {
            @\fastcgi_finish_request();
        }
    }
}
