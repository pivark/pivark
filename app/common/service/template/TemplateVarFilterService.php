<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

/** 模板变量过滤器（`|default` · `|truncate` 等） */
class TemplateVarFilterService
{

    public function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_array($value)) {
            return $value === [];
        }
        if (is_bool($value)) {
            return false;
        }

        return trim((string) $value) === '';
    }

    public function withDefault(mixed $value, string $fallback): string
    {
        if ($this->isEmpty($value)) {
            return $fallback;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    public function truncate(string $text, int $maxLen = 80, string $suffix = '...'): string
    {
        $maxLen = max(1, $maxLen);
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text) <= $maxLen) {
                return $text;
            }

            return rtrim(mb_substr($text, 0, $maxLen)) . $suffix;
        }
        if (strlen($text) <= $maxLen) {
            return $text;
        }

        return rtrim(substr($text, 0, $maxLen)) . $suffix;
    }
}
