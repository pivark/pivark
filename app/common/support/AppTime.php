<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/** 应用时区下的 datetime 字符串 SSOT（替代散弹 date()） */
final class AppTime
{
    public static function timezone(): string
    {
        return (string) config('app.default_timezone', 'Asia/Shanghai');
    }

    public static function now(): string
    {
        return self::format('Y-m-d H:i:s');
    }

    public static function timestamp(): int
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::timezone())))->getTimestamp();
    }

    public static function today(): string
    {
        return self::format('Y-m-d');
    }

    public static function startOfToday(): string
    {
        return self::format('Y-m-d 00:00:00');
    }

    public static function format(string $format, ?int $timestamp = null): string
    {
        $tz = new \DateTimeZone(self::timezone());
        $dt = $timestamp === null
            ? new \DateTimeImmutable('now', $tz)
            : (new \DateTimeImmutable('@' . $timestamp))->setTimezone($tz);

        return $dt->format($format);
    }

    /**
     * 解析发布时间下界：period 简写优先，否则 since 绝对时间。
     * 供全站 arclist / tag 目录周期筛选共用。
     */
    public static function publishedCutoff(?string $period = null, ?string $since = null): ?string
    {
        $since = trim((string) $since);
        if ($since !== '') {
            $ts = strtotime($since);

            return $ts !== false ? self::format('Y-m-d H:i:s', $ts) : null;
        }

        $period = strtolower(trim((string) $period));
        if ($period === '') {
            return null;
        }

        $seconds = match ($period) {
            '1d', '24h'       => 86400,
            '3d'              => 86400 * 3,
            '7d', '1w'        => 86400 * 7,
            '14d', '2w'       => 86400 * 14,
            '30d', '1m'       => 86400 * 30,
            '90d', '3m'       => 86400 * 90,
            '180d', '6m'      => 86400 * 180,
            '365d', '1y'      => 86400 * 365,
            default           => null,
        };
        if ($seconds === null && preg_match('/^(\d+)(h|d|w|m|y)$/', $period, $m)) {
            $n = (int) $m[1];
            $seconds = match ($m[2]) {
                'h' => $n * 3600,
                'd' => $n * 86400,
                'w' => $n * 86400 * 7,
                'm' => $n * 86400 * 30,
                'y' => $n * 86400 * 365,
                default => null,
            };
        }
        if ($seconds === null || $seconds < 1) {
            return null;
        }

        return self::format('Y-m-d H:i:s', self::timestamp() - $seconds);
    }

    /** 解析发布时间上界（until / published_until）。 */
    public static function publishedUntil(?string $until = null): ?string
    {
        $until = trim((string) $until);
        if ($until === '') {
            return null;
        }
        $ts = strtotime($until);

        return $ts !== false ? self::format('Y-m-d H:i:s', $ts) : null;
    }
}
