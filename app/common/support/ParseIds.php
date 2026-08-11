<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/** 解析批量 ID：数组 / "1,2,3" / 单值 */
final class ParseIds
{
    /**
     * @param mixed $raw id / ids / "1,2,3" / [1,2]
     * @return list<int>
     */
    public static function fromMixed(mixed $raw): array
    {
        $parts = [];
        if (is_array($raw)) {
            foreach ($raw as $item) {
                $parts = array_merge($parts, self::splitTokens($item));
            }
        } else {
            $parts = self::splitTokens($raw);
        }
        $ids = [];
        foreach ($parts as $part) {
            $id = (int) $part;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<string> */
    private static function splitTokens(mixed $raw): array
    {
        return preg_split('/[\s,]+/', trim((string) $raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
