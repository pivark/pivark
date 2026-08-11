<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use think\facade\Request;

/**
 * 前台模板视口：pc / m（可选目录 template/{theme}/pc|m/，无则回退主题根）
 */
final class ClientViewport
{
    public const PC = 'pc';

    public const M = 'm';

    private const COOKIE = 'pv_view';

    private static ?string $currentCache = null;

    public static function current(): string
    {
        if (self::$currentCache !== null) {
            return self::$currentCache;
        }
        $forced = self::forcedFromRequest();
        if ($forced !== null) {
            return self::$currentCache = $forced;
        }

        return self::$currentCache = self::detectMobileFromUserAgent() ? self::M : self::PC;
    }

    public static function isMobile(): bool
    {
        return self::current() === self::M;
    }

    public static function forgetCache(): void
    {
        self::$currentCache = null;
    }

    private static function forcedFromRequest(): ?string
    {
        $view = Request::get('view');
        $cookie = Request::cookie(self::COOKIE);
        $raw = strtolower(trim((string) ($view ?? $cookie ?? '')));
        if ($raw === self::PC) {
            return self::PC;
        }
        if ($raw === self::M || $raw === 'mobile') {
            return self::M;
        }

        return null;
    }

    private static function detectMobileFromUserAgent(): bool
    {
        $ua = strtolower(trim((string) Request::header('user-agent', '')));
        if ($ua === '') {
            return false;
        }
        if (str_contains($ua, 'ipad')
            || (str_contains($ua, 'macintosh') && str_contains($ua, 'touch'))) {
            return false;
        }

        return (bool) preg_match('/mobile|android|iphone|ipod|windows phone|opera mini|iemobile/i', $ua);
    }
}
