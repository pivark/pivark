<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/** 金额精确运算 SSOT（钱袋子审计 · 禁止 float 乘除累计） */
final class MoneyMath
{
    public const SCALE = 2;

    /** 支付回调与订单金额比对容差（元） */
    public const PAY_TOLERANCE = '0.01';

    public static function round2(float|string|int $value): string
    {
        if (is_int($value)) {
            return bcadd((string) $value, '0', self::SCALE);
        }
        if (is_float($value)) {
            return number_format($value, self::SCALE, '.', '');
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return '0.00';
        }

        return bcadd($trimmed, '0', self::SCALE);
    }

    public static function mul(float|string|int $unitPrice, int $qty): string
    {
        $qty = max(0, $qty);

        return bcmul(self::round2($unitPrice), (string) $qty, self::SCALE);
    }

    public static function toFloat(float|string|int $value): float
    {
        return (float) self::round2($value);
    }

    /** 两金额差值是否在容差内（含相等） */
    public static function withinTolerance(
        float|string|int $a,
        float|string|int $b,
        string $tolerance = self::PAY_TOLERANCE,
    ): bool {
        $left  = self::round2($a);
        $right = self::round2($b);
        if (bccomp($left, $right, self::SCALE) === 0) {
            return true;
        }
        $diff = bcsub($left, $right, self::SCALE);
        if (bccomp($diff, '0', self::SCALE) < 0) {
            $diff = bcsub('0', $diff, self::SCALE);
        }

        return bccomp($diff, self::round2($tolerance), self::SCALE) <= 0;
    }

    /** 两位小数纯数字字符串（展示 / API 字段） */
    public static function formatPlain(float|string|int $value): string
    {
        return self::round2($value);
    }

    /**
     * 格式化为金额字符串；$decimals=null 时保留 2 位小数。
     */
    public static function formatYuan(float|string|int $value, bool $withSymbol = false, ?int $decimals = null): string
    {
        $rounded = self::round2($value);
        $dec     = $decimals ?? self::SCALE;
        if ($dec === 0) {
            $num = (string) (int) floor((float) $rounded + 0.000001);
        } else {
            $num = $rounded;
        }

        return ($withSymbol ? '¥' : '') . $num;
    }

    /** 定价展示：>=100 元无小数，否则 2 位 */
    public static function formatYuanAdaptive(float|string|int $value, bool $withSymbol = true, string $suffix = ''): string
    {
        $n   = (float) self::round2($value);
        $dec = $n >= 100 ? 0 : self::SCALE;

        return self::formatYuan($value, $withSymbol, $dec) . $suffix;
    }
}
