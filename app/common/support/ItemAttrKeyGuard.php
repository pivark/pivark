<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/** 品项 attrs JSON 键名白名单（防 JSON_EXTRACT 键注入） */
final class ItemAttrKeyGuard
{
    public static function isSafe(string $key): bool
    {
        return $key !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $key) === 1;
    }
}
