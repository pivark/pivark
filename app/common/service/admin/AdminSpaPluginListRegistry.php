<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;


/**
 * 后台 SPA 插件列表扩展点（admin.spa_plugin_list）
 *
 * 插件 boot 自注册 list action；内核仅按 pluginId + action 分发，不写 per-plugin 方法。
 */
final class AdminSpaPluginListRegistry
{

    /** @var array<string, array<string, array{identifier:string, handler:callable}>> */
    private static array $handlersByPlugin = [];

    public function reset(): void
    {
        self::$handlersByPlugin = [];
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        unset(self::$handlersByPlugin[$identifier]);
    }

    /**
     * @param callable(array<string,mixed>): array<string,mixed> $handler
     */
    public function register(string $identifier, string $action, callable $handler): void
    {
        $identifier = strtolower(trim($identifier));
        $action     = strtolower(trim($action));
        if ($identifier === '' || $action === '') {
            return;
        }
        self::$handlersByPlugin[$identifier] ??= [];
        self::$handlersByPlugin[$identifier][$action] = [
            'identifier' => $identifier,
            'handler'    => $handler,
        ];
    }

    public function has(string $identifier, string $action): bool
    {
        $identifier = strtolower(trim($identifier));
        $action     = strtolower(trim($action));

        return isset(self::$handlersByPlugin[$identifier][$action]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>|null
     */
    public function invoke(string $identifier, string $action, array $query = []): ?array
    {
        $identifier = strtolower(trim($identifier));
        $action     = strtolower(trim($action));
        if ($identifier === '' || $action === '' || !isset(self::$handlersByPlugin[$identifier][$action])) {
            return null;
        }
        $result = (self::$handlersByPlugin[$identifier][$action]['handler'])($query);

        return is_array($result) ? $result : null;
    }

    /** @return list<string> */
    public function actionsForIdentifier(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || !isset(self::$handlersByPlugin[$identifier])) {
            return [];
        }

        return array_keys(self::$handlersByPlugin[$identifier]);
    }
}
