<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\boot;

use app\common\service\plugin\PluginService;
use app\common\service\plugin\entitlement\EntitlementService;

/** 内核按 identifier 动态调用 weapp service（管道实现；业务须经 PluginWeappAccess / HostRuntimeProbe） */
final class PluginWeappRuntime
{
    /** @return class-string */
    public static function serviceClass(string $identifier, string $serviceShortName): string
    {
        $slug = str_replace('-', '_', strtolower(trim($identifier)));

        return 'weapp\\' . $slug . '\\service\\' . ltrim($serviceShortName, '\\');
    }

    /**
     * @param list<mixed> $args
     */
    public static function invokeStatic(string $identifier, string $serviceShortName, string $method, array $args = [], mixed $default = null): mixed
    {
        if (!app(EntitlementService::class)->can($identifier)) {
            return $default;
        }
        app(PluginService::class)->registerAutoloadPublic($identifier);
        $class = self::serviceClass($identifier, $serviceShortName);
        if (!is_callable([$class, $method])) {
            return $default;
        }

        return PluginRuntimeFaultGuard::invoke(
            static fn (): mixed => ($class::$method)(...$args),
            'plugin_weapp_runtime_static_failed',
            [
                'identifier' => strtolower(trim($identifier)),
                'service'    => $serviceShortName,
                'method'     => $method,
            ],
            $default,
        );
    }

    /**
     * @param list<mixed> $args
     */
    public static function invokeInstance(string $identifier, string $serviceShortName, string $method, array $args = [], mixed $default = null): mixed
    {
        if (!app(EntitlementService::class)->can($identifier)) {
            return $default;
        }
        app(PluginService::class)->registerAutoloadPublic($identifier);
        $class = self::serviceClass($identifier, $serviceShortName);
        if (!class_exists($class) || !method_exists($class, $method)) {
            return $default;
        }
        $target = app($class);

        return PluginRuntimeFaultGuard::invoke(
            static fn (): mixed => $target->{$method}(...$args),
            'plugin_weapp_runtime_instance_failed',
            [
                'identifier' => strtolower(trim($identifier)),
                'service'    => $serviceShortName,
                'method'     => $method,
            ],
            $default,
        );
    }
}
