<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\weapp;

use app\common\support\ProjectPaths;

/**
 * 内核按 identifier 解析 weapp 类（先 is_file 再 class_exists，禁止 per-plugin bridge 文件）。
 */
final class PluginWeappClassResolver
{
    public static function weappRoot(string $identifier): string
    {
        $slug = self::slug($identifier);

        return ProjectPaths::root() . 'weapp/' . $slug . '/';
    }

    public static function pluginJsonReadable(string $identifier): bool
    {
        return is_readable(self::weappRoot($identifier) . 'plugin.json');
    }

    /** @return class-string|null */
    public static function serviceClassIfPresent(string $identifier, string $serviceShortName): ?string
    {
        $serviceShortName = trim($serviceShortName);
        if ($serviceShortName === '' || !self::pluginJsonReadable($identifier)) {
            return null;
        }
        $relative = 'service/' . str_replace('\\', '/', $serviceShortName) . '.php';
        if (!is_file(self::weappRoot($identifier) . $relative)) {
            return null;
        }
        app(PluginService::class)->registerAutoloadPublic($identifier);
        $class = PluginWeappRuntime::serviceClass($identifier, $serviceShortName);

        return class_exists($class) ? $class : null;
    }

    /** @return class-string|null */
    public static function weappClassIfPresent(string $identifier, string $relativePath): ?string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || !self::pluginJsonReadable($identifier)) {
            return null;
        }
        $file = self::weappRoot($identifier) . $relativePath . '.php';
        if (!is_file($file)) {
            return null;
        }
        app(PluginService::class)->registerAutoloadPublic($identifier);
        $slug  = self::slug($identifier);
        $class = 'weapp\\' . $slug . '\\' . str_replace('/', '\\', $relativePath);

        return class_exists($class) ? $class : null;
    }

    private static function slug(string $identifier): string
    {
        return str_replace('-', '_', strtolower(trim($identifier)));
    }
}
