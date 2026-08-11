<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/** 站点根 extend/function.php（diy_* 用户函数）加载 */
final class ExtendBootstrap
{
    private static bool $loaded = false;

    public static function loadUserFunctions(?string $root = null): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $root = $root ?? (defined('ROOT_PATH') ? (string) ROOT_PATH : '');
        if ($root === '') {
            return;
        }

        $file = rtrim(str_replace('\\', '/', $root), '/')
            . '/extend/function.php';
        if (is_file($file)) {
            require_once $file;
        }
    }

    /**
     * @param list<mixed> $args
     */
    public static function invokeDiy(string $function, array $args = []): mixed
    {
        self::loadUserFunctions();
        if (!str_starts_with($function, 'diy_') || !function_exists($function)) {
            return null;
        }

        return $function(...$args);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public static function invokeDiyTemplateVars(string $function, array $context = []): array
    {
        $result = self::invokeDiy($function, [$context]);

        return is_array($result) ? $result : [];
    }
}
