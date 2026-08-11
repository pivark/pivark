<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;


/** 后台 Core 分组插件快捷入口（route => 是否展示） */
final class AdminPluginSidebarRegistry
{

    /** @var array<string, callable(): bool> */
    private static array $shortcuts = [];

    public function reset(): void
    {
        self::$shortcuts = [];
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        foreach (array_keys(self::$shortcuts) as $route) {
            if (str_contains($route, '/' . $identifier)) {
                unset(self::$shortcuts[$route]);
            }
        }
    }

    public function register(string $route, callable $isVisible): void
    {
        $route = trim($route);
        if ($route === '') {
            return;
        }
        self::$shortcuts[$route] = $isVisible;
    }

    public function isShortcutVisible(string $route): bool
    {
        $route = trim($route);
        if ($route === '' || !isset(self::$shortcuts[$route])) {
            return false;
        }

        return (bool) (self::$shortcuts[$route])();
    }
}
