<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

/** 会员账号类型（个人 / 企业）SSOT */
final class MemberAccountKind
{
    public const PERSONAL   = 'personal';
    public const ENTERPRISE = 'enterprise';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PERSONAL, self::ENTERPRISE];
    }

    public static function normalize(mixed $raw): string
    {
        $kind = strtolower(trim((string) $raw));
        if ($kind === self::ENTERPRISE) {
            return self::ENTERPRISE;
        }

        return self::PERSONAL;
    }

    public static function isEnterprise(mixed $raw): bool
    {
        return self::normalize($raw) === self::ENTERPRISE;
    }

    public static function label(mixed $raw): string
    {
        return self::isEnterprise($raw) ? '企业' : '个人';
    }
}
