<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use app\common\service\site\SiteDomainService;

/** 当前请求的域名解析上下文（由中间件 bootstrap） */
final class SiteDomainContext
{
    /** @var array<string, mixed>|null */
    private static ?array $resolved = null;

    private static bool $booted = false;

    public static function bootstrap(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        $svc            = app(SiteDomainService::class);
        self::$resolved = $svc->resolveCurrentHost();
        if (self::$resolved === null) {
            self::$resolved = $svc->resolveDebugPreviewHost();
        }
    }

    public static function reset(): void
    {
        self::$booted   = false;
        self::$resolved = null;
    }

    /** @return array<string, mixed>|null */
    public static function resolved(): ?array
    {
        self::bootstrap();

        return self::$resolved;
    }

    public static function host(): string
    {
        $row = self::resolved();

        return (string) ($row['host'] ?? '');
    }

    public static function tagGroupId(): int
    {
        $row = self::resolved();

        return (int) ($row['tag_group_id'] ?? 0);
    }

    public static function defaultTagSlug(): string
    {
        $row = self::resolved();

        return trim((string) ($row['default_tag_slug'] ?? ''));
    }

    public static function isPrimary(): bool
    {
        $row = self::resolved();

        return !empty($row['is_primary']);
    }
}
