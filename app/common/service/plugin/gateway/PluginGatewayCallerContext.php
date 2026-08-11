<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\gateway;

/** 从调用栈解析当前第三方插件 identifier（weapp\{dir}\ 命名空间） */
final class PluginGatewayCallerContext
{
    /** @var list<string>|null */
    private static ?array $overrideStack = null;

    public static function pushOverride(string $identifier): void
    {
        self::$overrideStack ??= [];
        self::$overrideStack[] = strtolower(trim($identifier));
    }

    public static function popOverride(): void
    {
        if (self::$overrideStack === null || self::$overrideStack === []) {
            return;
        }
        array_pop(self::$overrideStack);
    }

    public static function resetOverrides(): void
    {
        self::$overrideStack = null;
    }

    public static function currentIdentifier(): ?string
    {
        if (self::$overrideStack !== null && self::$overrideStack !== []) {
            return self::$overrideStack[count(self::$overrideStack) - 1];
        }
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 24) as $frame) {
            $class = (string) ($frame['class'] ?? '');
            if (!str_starts_with($class, 'weapp\\')) {
                continue;
            }
            if (preg_match('/^weapp\\\\([a-z0-9_-]+)\\\\/i', $class, $m) !== 1) {
                continue;
            }

            return self::normalizeWeappDirToIdentifier($m[1]);
        }

        return null;
    }

    private static function normalizeWeappDirToIdentifier(string $dir): string
    {
        $dir = strtolower(trim($dir));
        if ($dir === '') {
            return $dir;
        }
        $hyphen = str_replace('_', '-', $dir);
        // social_auth → social-auth（目录带连字符）；doc_ask 目录仍为下划线，勿误改
        $weappRoot = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'weapp' . DIRECTORY_SEPARATOR;
        if (is_dir($weappRoot . $hyphen)) {
            return $hyphen;
        }
        if (is_dir($weappRoot . $dir)) {
            return $dir;
        }

        return $hyphen;
    }
}
