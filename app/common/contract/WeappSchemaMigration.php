<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\contract;

/** weapp 插件 DB 台阶迁移基类（幂等 up；down 可选） */
abstract class WeappSchemaMigration
{
    abstract public static function identifier(): string;

    abstract public static function version(): int;

    abstract public static function up(): void;

    public static function down(): void
    {
    }

    protected static function prefix(): string
    {
        return (string) config('database.connections.mysql.prefix');
    }
}
