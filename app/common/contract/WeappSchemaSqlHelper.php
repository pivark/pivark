<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\contract;

use think\facade\Db;

/** weapp 迁移共用 SQL 幂等助手 */
class WeappSchemaSqlHelper
{
    public static function ensureColumn(string $table, string $column, string $definition): void
    {
        self::assertIdentifier($table, 'table');
        self::assertIdentifier($column, 'column');
        $rows = Db::query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        if ($rows !== []) {
            return;
        }
        Db::execute("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }

    public static function ensureIndex(string $table, string $indexName, string $columns): void
    {
        self::assertIdentifier($table, 'table');
        self::assertIdentifier($indexName, 'index');
        if (!preg_match('/^[a-zA-Z0-9_`,\s()]+$/', $columns)) {
            throw new \InvalidArgumentException('columns 含非法字符');
        }
        $rows = Db::query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$indexName}'");
        if ($rows !== []) {
            return;
        }
        Db::execute("ALTER TABLE `{$table}` ADD KEY `{$indexName}` ({$columns})");
    }

    private static function assertIdentifier(string $name, string $label): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException($label . ' 含非法字符');
        }
    }
}
