<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use app\common\support\OpsLog;
use think\facade\Db;

/**
 * 事务提交后副作用（仿 Laravel afterCommit）。
 * 在最外层 commit 时执行；最外层 rollback 时丢弃，避免嵌套 savepoint 回滚后仍派发事件。
 */
final class DbAfterCommit
{
    /** @var list<callable(): void> */
    private static array $callbacks = [];

    /**
     * @param callable(): void $callback
     */
    public static function run(callable $callback): void
    {
        if (self::inTransaction()) {
            self::$callbacks[] = $callback;

            return;
        }

        self::invoke($callback);
    }

    public static function flush(): void
    {
        while (self::$callbacks !== []) {
            $batch = self::$callbacks;
            self::$callbacks = [];
            foreach ($batch as $callback) {
                self::invoke($callback);
            }
        }
    }

    public static function discard(): void
    {
        self::$callbacks = [];
    }

    /** @internal 测试/探针 */
    public static function pendingCount(): int
    {
        return count(self::$callbacks);
    }

    public static function inTransaction(): bool
    {
        try {
            $conn = Db::connect();
            if (!method_exists($conn, 'getPdo')) {
                return false;
            }
            $pdo = $conn->getPdo();

            return $pdo instanceof \PDO && $pdo->inTransaction();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param callable(): void $callback
     */
    private static function invoke(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            OpsLog::businessWarning('db_after_commit_failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
