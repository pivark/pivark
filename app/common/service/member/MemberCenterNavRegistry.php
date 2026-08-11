<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

/** 会员中心插件导航扩展（member.center.nav · 路由可见性过滤） */
final class MemberCenterNavRegistry
{

    /** @var array<string, list<callable(array<string,mixed>): bool>> */
    private static array $routeFilters = [];

    public function reset(): void
    {
        self::$routeFilters = [];
    }

    /**
     * @param callable(array<string,mixed>): bool $filter 返回 false 则隐藏该 route
     */
    public function registerRouteFilter(string $identifier, callable $filter): void
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return;
        }
        self::$routeFilters[$identifier] ??= [];
        self::$routeFilters[$identifier][] = $filter;
    }

    /**
     * @param array<string, mixed> $entry identifier, route, …
     */
    public function isRouteVisible(string $identifier, array $entry): bool
    {
        $identifier = trim($identifier);
        if ($identifier === '' || !isset(self::$routeFilters[$identifier])) {
            return true;
        }
        foreach (self::$routeFilters[$identifier] as $filter) {
            if (!$filter($entry)) {
                return false;
            }
        }

        return true;
    }
}
