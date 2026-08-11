<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\cron;

use app\common\service\plugin\boot\PluginRuntimeFaultGuard;

/** 插件定时任务扩展点（cron.task · payload plugin+task 分发） */
final class PluginCronTaskRegistry
{

    /** @var list<array{key:string, identifier:string, task:string, handler:callable}> */
    private static array $handlers = [];

    /** @var array<string, array{identifier:string, task:string, description:string}> */
    private static array $legacyHandlers = [];

    public function reset(): void
    {
        self::$handlers       = [];
        self::$legacyHandlers = [];
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        self::$handlers = array_values(array_filter(
            self::$handlers,
            static fn (array $entry): bool => ($entry['identifier'] ?? '') !== $identifier,
        ));
        foreach (array_keys(self::$legacyHandlers) as $handlerKey) {
            if ((self::$legacyHandlers[$handlerKey]['identifier'] ?? '') === $identifier) {
                unset(self::$legacyHandlers[$handlerKey]);
            }
        }
    }

    public function registerLegacyHandler(string $handlerKey, string $identifier, string $taskId, string $description): void
    {
        $handlerKey  = strtolower(trim($handlerKey));
        $identifier  = strtolower(trim($identifier));
        $taskId      = strtolower(trim($taskId));
        $description = trim($description);
        if ($handlerKey === '' || $identifier === '' || $taskId === '' || $description === '') {
            return;
        }
        self::$legacyHandlers[$handlerKey] = [
            'identifier'  => $identifier,
            'task'        => $taskId,
            'description' => $description,
        ];
    }

    /** @return array<string, string> */
    public function legacyHandlerDescriptions(): array
    {
        $out = [];
        foreach (self::$legacyHandlers as $key => $meta) {
            $out[$key] = (string) ($meta['description'] ?? $key);
        }

        return $out;
    }

    public function hasHandler(string $handlerKey): bool
    {
        return isset(self::$legacyHandlers[strtolower(trim($handlerKey))]);
    }

    /** @param array<string, mixed> $payload */
    public function dispatchLegacyHandler(string $handlerKey, array $payload): ?string
    {
        $handlerKey = strtolower(trim($handlerKey));
        $meta       = self::$legacyHandlers[$handlerKey] ?? null;
        if (!is_array($meta)) {
            return null;
        }

        return $this->dispatch(
            (string) ($meta['identifier'] ?? ''),
            (string) ($meta['task'] ?? ''),
            $payload,
        );
    }

    /** @param callable(array<string,mixed>): string $handler */
    public function register(string $identifier, string $taskId, callable $handler): void
    {
        $identifier = strtolower(trim($identifier));
        $taskId     = strtolower(trim($taskId));
        if ($identifier === '' || $taskId === '') {
            return;
        }
        $key = $identifier . ':' . $taskId;
        foreach (self::$handlers as $idx => $entry) {
            if ($entry['key'] === $key) {
                self::$handlers[$idx] = [
                    'key'        => $key,
                    'identifier' => $identifier,
                    'task'       => $taskId,
                    'handler'    => $handler,
                ];

                return;
            }
        }
        self::$handlers[] = [
            'key'        => $key,
            'identifier' => $identifier,
            'task'       => $taskId,
            'handler'    => $handler,
        ];
    }

    /** @param array<string, mixed> $payload */
    public function dispatch(string $identifier, string $taskId, array $payload): string
    {
        $key = strtolower(trim($identifier)) . ':' . strtolower(trim($taskId));
        foreach (self::$handlers as $entry) {
            if ($entry['key'] !== $key) {
                continue;
            }

            return PluginRuntimeFaultGuard::invoke(
                static fn (): string => (string) ($entry['handler'])($payload),
                'plugin_cron_task_failed',
                [
                    'identifier' => strtolower(trim($identifier)),
                    'task'       => strtolower(trim($taskId)),
                ],
                'FAIL: plugin cron task error',
            );
        }

        throw new \RuntimeException('plugin cron not registered: ' . $key);
    }
}
