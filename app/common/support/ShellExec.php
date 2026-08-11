<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/** 检测 PHP exec() 是否可用；业务层请用 {@see TrustedShellRunner} / {@see CliProcessRunner} */
final class ShellExec
{
    public static function isAvailable(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = ini_get('disable_functions');
        if (!is_string($disabled) || trim($disabled) === '') {
            return true;
        }
        $list = array_map(static fn (string $fn): string => strtolower(trim($fn)), explode(',', $disabled));

        return !in_array('exec', $list, true);
    }

    public static function unavailableMessage(string $feature): string
    {
        return $feature . '需要 PHP exec()，当前环境已在 disable_functions 中禁用；请联系运维放行 exec，或启用 ZipArchive 等内置替代能力';
    }
}
