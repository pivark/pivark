<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\weapp;

use app\common\service\plugin\boot\PluginWeappRuntime;

/**
 * 内核调用任意已授权插件的唯一通用入口（非 platform 宿主专用）。
 * 平台宿主能力走 HostRuntimeProbe。
 */
final class PluginWeappAccess
{
    /**
     * @param list<mixed> $args
     */
    public static function invokeStatic(
        string $identifier,
        string $serviceShortName,
        string $method,
        array $args = [],
        mixed $default = null
    ): mixed {
        return PluginWeappRuntime::invokeStatic($identifier, $serviceShortName, $method, $args, $default);
    }

    /**
     * @param list<mixed> $args
     */
    public static function invokeInstance(
        string $identifier,
        string $serviceShortName,
        string $method,
        array $args = [],
        mixed $default = null
    ): mixed {
        return PluginWeappRuntime::invokeInstance($identifier, $serviceShortName, $method, $args, $default);
    }

    /** @return class-string|null */
    public static function serviceClassIfPresent(string $identifier, string $serviceShortName): ?string
    {
        return PluginWeappClassResolver::serviceClassIfPresent($identifier, $serviceShortName);
    }
}
