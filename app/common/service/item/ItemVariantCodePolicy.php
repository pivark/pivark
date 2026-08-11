<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\item;

/**
 * 订货号（variant_code）站点命名策略 —— 纯拼接，不写库。
 *
 * 仅用于「字段为空」时的建议值；已有订货号不得被本类覆盖。
 * 商城 sku_code 规则日后可镜像同构，本批不接入 shop。
 */
final class ItemVariantCodePolicy
{
    public const MODE_EQUALS_ITEM         = 'equals_item';
    public const MODE_ITEM_SPEC           = 'item_spec';
    public const MODE_ITEM_SEQ            = 'item_seq';
    public const MODE_PREFIX_ITEM_SUFFIX  = 'prefix_item_suffix';

    public const CASE_KEEP  = 'keep';
    public const CASE_UPPER = 'upper';
    public const CASE_LOWER = 'lower';

    /** @return list<string> */
    public static function modes(): array
    {
        return [
            self::MODE_EQUALS_ITEM,
            self::MODE_ITEM_SPEC,
            self::MODE_ITEM_SEQ,
            self::MODE_PREFIX_ITEM_SUFFIX,
        ];
    }

    /**
     * 后台设置页选项（label + hint）。
     *
     * @return list<array{value:string,label:string,hint:string}>
     */
    public static function modeOptions(): array
    {
        return [
            [
                'value' => self::MODE_EQUALS_ITEM,
                'label' => '等于型号',
                'hint'  => '一型号一配置时常用；订货号与型号相同。',
            ],
            [
                'value' => self::MODE_ITEM_SPEC,
                'label' => '型号 + 规格',
                'hint'  => '如 DJ-810-红-220V-50W；多颜色/电压/功率时推荐。',
            ],
            [
                'value' => self::MODE_ITEM_SEQ,
                'label' => '型号 + 序号',
                'hint'  => '如 DJ-810-v1、DJ-810-v2。',
            ],
            [
                'value' => self::MODE_PREFIX_ITEM_SUFFIX,
                'label' => '前缀 + 型号 + 后缀',
                'hint'  => '在型号两侧加固定前缀/后缀；冲突时自动加 -2。',
            ],
        ];
    }

    /**
     * @param array{
     *   mode?:string,
     *   prefix?:string,
     *   suffix?:string,
     *   separator?:string,
     *   case?:string
     * } $policy
     * @param array<string, scalar|null>|null $specMap
     */
    public static function build(
        array $policy,
        string $itemCode,
        string $specLabel = '',
        int $sequence = 1,
        ?array $specMap = null,
    ): string {
        $itemCode = trim($itemCode);
        $mode     = self::normalizeMode((string) ($policy['mode'] ?? self::MODE_ITEM_SPEC));
        $sep      = self::normalizeSeparator((string) ($policy['separator'] ?? '-'));
        $prefix   = trim((string) ($policy['prefix'] ?? ''));
        $suffix   = trim((string) ($policy['suffix'] ?? ''));
        $case     = self::normalizeCase((string) ($policy['case'] ?? self::CASE_KEEP));
        $seq      = max(1, $sequence);

        $core = match ($mode) {
            self::MODE_EQUALS_ITEM => $itemCode !== '' ? $itemCode : ('v' . $seq),
            self::MODE_ITEM_SEQ => self::joinParts($sep, [
                $itemCode,
                'v' . $seq,
            ]),
            self::MODE_PREFIX_ITEM_SUFFIX => self::joinParts($sep, [
                $prefix,
                $itemCode !== '' ? $itemCode : ('item-v' . $seq),
                $suffix,
            ]),
            default => self::joinParts($sep, [
                $itemCode,
                self::specFragment($specLabel, $specMap, $sep, $seq),
            ]),
        };

        if ($mode !== self::MODE_PREFIX_ITEM_SUFFIX) {
            $core = self::joinParts($sep, [$prefix, $core, $suffix]);
        }

        $core = self::applyCase($core, $case);
        $core = preg_replace('/\s+/u', $sep, $core) ?? $core;
        $core = trim($core, $sep . ' ');

        if ($core === '') {
            $core = 'VAR-' . $seq;
        }

        return mb_substr($core, 0, 64);
    }

    /** @param array<string, scalar|null>|null $specMap */
    private static function specFragment(
        string $specLabel,
        ?array $specMap,
        string $sep,
        int $seq,
    ): string {
        $fromMap = [];
        if (is_array($specMap)) {
            foreach ($specMap as $key => $value) {
                if (!is_string($key) || $key === '' || strcasecmp($key, 'price') === 0) {
                    continue;
                }
                $v = is_scalar($value) ? trim((string) $value) : '';
                if ($v === '') {
                    continue;
                }
                $fromMap[] = self::slugPart($v);
            }
        }
        if ($fromMap !== []) {
            return implode($sep, $fromMap);
        }

        $label = trim($specLabel);
        if ($label !== '') {
            $slug = self::slugPart($label);
            if ($slug !== '') {
                return $slug;
            }
        }

        return 'v' . $seq;
    }

    private static function slugPart(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        // 保留中文与常见字母数字，空白与其它符号压成分隔
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text) ?? '';
        $slug = trim($slug, '-');

        return $slug;
    }

    /** @param list<string> $parts */
    private static function joinParts(string $sep, array $parts): string
    {
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '' || $part === $sep) {
                continue;
            }
            $out[] = trim($part, $sep);
        }

        return implode($sep, $out);
    }

    public static function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return in_array($mode, self::modes(), true) ? $mode : self::MODE_ITEM_SPEC;
    }

    public static function normalizeSeparator(string $sep): string
    {
        $sep = trim($sep);
        if ($sep === '' || mb_strlen($sep) > 3) {
            return '-';
        }

        return $sep;
    }

    public static function normalizeCase(string $case): string
    {
        $case = strtolower(trim($case));

        return in_array($case, [self::CASE_KEEP, self::CASE_UPPER, self::CASE_LOWER], true)
            ? $case
            : self::CASE_KEEP;
    }

    private static function applyCase(string $value, string $case): string
    {
        return match ($case) {
            self::CASE_UPPER => mb_strtoupper($value),
            self::CASE_LOWER => mb_strtolower($value),
            default => $value,
        };
    }
}
