<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\menu;

/** 后台动态菜单贡献（admin.menu.dynamic） */
final class AdminMenuRegistry
{

    /** @var list<array{identifier:string, handler:callable, priority:int}> */
    private static array $handlers = [];

    public function reset(): void
    {
        self::$handlers = [];
    }

    /**
     * @param callable(): list<array<string,mixed>> $handler
     */
    public function register(string $identifier, callable $handler, int $priority = 100): void
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return;
        }
        self::$handlers[] = [
            'identifier' => $identifier,
            'handler'    => $handler,
            'priority'   => $priority,
        ];
        usort(self::$handlers, static function (array $a, array $b): int {
            return ($a['priority'] <=> $b['priority']) ?: strcmp($a['identifier'], $b['identifier']);
        });
    }

    /** @return list<array<string, mixed>> */
    public function collect(): array
    {
        $out = [];
        foreach (self::$handlers as $entry) {
            $menus = ($entry['handler'])();
            if (!is_array($menus)) {
                continue;
            }
            foreach ($menus as $menu) {
                if (is_array($menu) && $menu !== []) {
                    $out[] = $menu;
                }
            }
        }

        return $out;
    }
}
