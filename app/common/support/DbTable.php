<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use app\common\support\dialect\DialectAdapterFactory;
use think\db\Query;
use think\facade\Db;
use think\Model;

/**
 * 解析带 DB_PREFIX 的物理表名（供 Raw SQL 片段使用）
 *
 * ORM 查询构造器会自动加前缀；field('(SELECT … FROM …)')、Db::query() 等不会。
 */
final class DbTable
{
    /**
     * @param class-string<Model> $modelClass
     */
    public static function model(string $modelClass): string
    {
        if (!is_subclass_of($modelClass, Model::class)) {
            throw new \InvalidArgumentException('Not a ThinkPHP model: ' . $modelClass);
        }

        return $modelClass::getTable();
    }

    /** 逻辑表名（无前缀，与 Model::$name 一致） */
    public static function name(string $logical): string
    {
        $logical = trim($logical);
        if ($logical === '') {
            throw new \InvalidArgumentException('Logical table name is empty');
        }

        return Db::name($logical)->getTable();
    }

    /** 物理表是否存在于当前库（information_schema，不触发 SELECT 探测） */
    public static function physicalExists(string $physicalTable): bool
    {
        static $cache = [];

        $physicalTable = trim($physicalTable);
        if ($physicalTable === '') {
            return false;
        }
        if (array_key_exists($physicalTable, $cache)) {
            return $cache[$physicalTable];
        }
        try {
            $probe = DialectAdapterFactory::make()->tableExistsProbe($physicalTable);
            $rows  = Db::query($probe['sql'], $probe['bind']);
            $cache[$physicalTable] = $rows !== [];
        } catch (\Throwable $e) {
            OpsLog::businessWarning('db_table_physical_exists_probe_failed', [
                'table' => $physicalTable,
                'msg'   => $e->getMessage(),
            ]);
            $cache[$physicalTable] = false;
        }

        return $cache[$physicalTable];
    }

    /** 索引是否存在于物理表（information_schema.STATISTICS） */
    public static function indexExists(string $physicalTable, string $indexName): bool
    {
        static $cache = [];

        $physicalTable = trim($physicalTable);
        $indexName     = trim($indexName);
        if ($physicalTable === '' || $indexName === '') {
            return false;
        }
        $key = $physicalTable . "\0" . $indexName;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $probe = DialectAdapterFactory::make()->indexExistsProbe($physicalTable, $indexName);
            $rows  = Db::query($probe['sql'], $probe['bind']);
            $cache[$key] = $rows !== [];
        } catch (\Throwable $e) {
            OpsLog::businessWarning('db_table_index_exists_probe_failed', [
                'table' => $physicalTable,
                'index' => $indexName,
                'msg'   => $e->getMessage(),
            ]);
            $cache[$key] = false;
        }

        return $cache[$key];
    }

    /**
     * @return list<string> 物理表名（可选前缀过滤）
     */
    public static function listPhysicalTables(?string $namePrefix = null): array
    {
        $probe = DialectAdapterFactory::make()->listPhysicalTablesProbe($namePrefix);

        try {
            $rows = Db::query($probe['sql'], $probe['bind']);
        } catch (\Throwable $e) {
            OpsLog::businessWarning('db_table_list_physical_failed', [
                'prefix' => $namePrefix,
                'msg'    => $e->getMessage(),
            ]);

            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * @return list<array{name: string, rows: int, size: int, size_text: string}>
     */
    public static function listPhysicalTableMeta(?string $namePrefix = null): array
    {
        $probe = DialectAdapterFactory::make()->listPhysicalTableMetaProbe($namePrefix);

        try {
            $rows = Db::query($probe['sql'], $probe['bind']);
        } catch (\Throwable $e) {
            OpsLog::businessWarning('db_table_list_physical_meta_failed', [
                'prefix' => $namePrefix,
                'msg'    => $e->getMessage(),
            ]);

            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $size = max(0, (int) ($row['size_bytes'] ?? 0));
            $out[] = [
                'name'      => $name,
                'rows'      => max(0, (int) ($row['rows'] ?? 0)),
                'size'      => $size,
                'size_text' => self::formatTableBytes($size),
            ];
        }

        return $out;
    }

    private static function formatTableBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 2) . ' MB';
        }

        return round($bytes / 1073741824, 2) . ' GB';
    }

    /** 校验物理表名（Raw SQL / SHOW CREATE TABLE 前） */
    public static function assertPhysicalTableName(string $table, ?string $requiredPrefix = null): string
    {
        $table = trim($table);
        if ($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('Invalid table name');
        }
        if ($requiredPrefix !== null && $requiredPrefix !== '' && !str_starts_with($table, $requiredPrefix)) {
            throw new \InvalidArgumentException('Table outside allowed prefix');
        }

        return $table;
    }

    /**
     * @param class-string<Model> $modelClass
     */
    public static function modelExists(string $modelClass): bool
    {
        return self::physicalExists(self::model($modelClass));
    }

    /** 逻辑表是否可访问（迁移未跑或已 DROP 时返回 false） */
    public static function exists(string $logical): bool
    {
        static $logicalCache = [];

        $logical = trim($logical);
        if ($logical === '') {
            return false;
        }
        if (array_key_exists($logical, $logicalCache)) {
            return $logicalCache[$logical];
        }
        try {
            $logicalCache[$logical] = self::physicalExists(self::name($logical));
        } catch (\Throwable $e) {
            OpsLog::businessWarning('db_table_logical_exists_probe_failed', [
                'logical' => $logical,
                'msg'     => $e->getMessage(),
            ]);
            $logicalCache[$logical] = false;
        }

        return $logicalCache[$logical];
    }

    /** @return Query */
    public static function query(string $logical): Query
    {
        return Db::name($logical);
    }
}
