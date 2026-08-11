<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;


/** 插件 load-time 注册的敏感 REST 路径（内核不写死插件 URL） */
final class AdminSensitiveConfirmRouteRegistry
{

    /** @var list<string> 如 /admin/{host-plugin}/license-platform/save-code */
    private static array $pathSuffixes = [];

    public function reset(): void
    {
        self::$pathSuffixes = [];
    }

    /** @param string $adminUrlPath 完整或 /admin/... 后缀 */
    public function registerPath(string $adminUrlPath): void
    {
        $path = strtolower(trim(str_replace('\\', '/', $adminUrlPath)));
        if ($path === '') {
            return;
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . ltrim($path, '/');
        }
        if (!in_array($path, self::$pathSuffixes, true)) {
            self::$pathSuffixes[] = $path;
        }
    }

    public function matchesRequestPath(string $path): bool
    {
        $path = strtolower(trim(str_replace('\\', '/', $path)));
        if ($path === '') {
            return false;
        }
        foreach (self::$pathSuffixes as $suffix) {
            if (str_ends_with($path, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function all(): array
    {
        return self::$pathSuffixes;
    }
}
