<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\hook;

use app\common\support\OpsLog;

/** 插件 Hook 分发 */
class HookService
{

    /** @var array<string, list<array{identifier:?string, listener:callable}>> */
    private static array $listeners = [];

    /**
     * @param callable(array<string, mixed>): void $listener
     */
    public function on(string $hook, callable $listener, ?string $identifier = null): void
    {
        $identifier = $identifier !== null ? strtolower(trim($identifier)) : null;
        if ($identifier === '') {
            $identifier = null;
        }
        self::$listeners[$hook][] = [
            'identifier' => $identifier,
            'listener'   => $listener,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed> 监听器可修改副本，调用方请接收返回值（勿传字面量数组并依赖写回）
     */
    public function fire(string $hook, array $payload = []): array
    {
        foreach (self::$listeners[$hook] ?? [] as $entry) {
            try {
                ($entry['listener'])($payload);
            } catch (\Throwable $e) {
                OpsLog::businessWarning('hook_listener_failed', [
                    'hook'       => $hook,
                    'identifier' => $entry['identifier'] ?? null,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $payload;
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        foreach (array_keys(self::$listeners) as $hook) {
            self::$listeners[$hook] = array_values(array_filter(
                self::$listeners[$hook],
                static fn (array $entry): bool => ($entry['identifier'] ?? null) !== $identifier,
            ));
            if (self::$listeners[$hook] === []) {
                unset(self::$listeners[$hook]);
            }
        }
    }

    public function reset(): void
    {
        self::$listeners = [];
    }
}
