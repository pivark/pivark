<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\front;

use app\common\service\plugin\boot\PluginRuntimeFaultGuard;

/** 前台脚本/样式扩展点（插件 boot 注册 · 内核不写死 identifier） */
final class PluginFrontAssetRegistry
{

    /** @var array<string, callable> */
    private static array $handlers = [];

    public function reset(): void
    {
        self::$handlers = [];
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        $prefix = $identifier . ':';
        foreach (array_keys(self::$handlers) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$handlers[$key]);
            }
        }
    }

    public function register(string $identifier, string $action, callable $handler): void
    {
        $identifier = strtolower(trim($identifier));
        $action     = strtolower(trim($action));
        if ($identifier === '' || $action === '') {
            return;
        }
        self::$handlers[$identifier . ':' . $action] = $handler;
    }

    public function dispatch(string $identifier, string $action, mixed ...$args): void
    {
        $identifier = strtolower(trim($identifier));
        $action     = strtolower(trim($action));
        $key        = $identifier . ':' . $action;
        $handler    = self::$handlers[$key] ?? null;
        if (!is_callable($handler)) {
            return;
        }
        PluginRuntimeFaultGuard::invoke(
            static fn (): mixed => $handler(...$args),
            'plugin_front_asset_failed',
            [
                'identifier' => $identifier,
                'action'     => $action,
            ],
        );
    }

    /** @return list<string> */
    public function identifiersForAction(string $action): array
    {
        $action = strtolower(trim($action));
        if ($action === '') {
            return [];
        }
        $suffix = ':' . $action;
        $ids    = [];
        foreach (array_keys(self::$handlers) as $key) {
            if (str_ends_with($key, $suffix)) {
                $ids[] = substr($key, 0, -strlen($suffix));
            }
        }
        sort($ids);

        return array_values(array_unique($ids));
    }

    public function dispatchForAction(string $action, mixed ...$args): void
    {
        foreach ($this->identifiersForAction($action) as $identifier) {
            $this->dispatch($identifier, $action, ...$args);
        }
    }
}
