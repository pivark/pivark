<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support\dialect;

/** MySQL / MariaDB information_schema 方言 */
final class MySqlDialectAdapter implements DialectAdapter
{
    public function tableExistsProbe(string $physicalTable): array
    {
        return [
            'sql'  => 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            'bind' => [$physicalTable],
        ];
    }

    public function indexExistsProbe(string $physicalTable, string $indexName): array
    {
        return [
            'sql'  => 'SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            'bind' => [$physicalTable, $indexName],
        ];
    }

    public function listPhysicalTablesProbe(?string $namePrefix): array
    {
        $sql  = 'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()';
        $bind = [];
        if ($namePrefix !== null && $namePrefix !== '') {
            $sql     .= ' AND TABLE_NAME LIKE ?';
            $bind[]   = $namePrefix . '%';
        }
        $sql .= ' ORDER BY TABLE_NAME';

        return ['sql' => $sql, 'bind' => $bind];
    }

    public function listPhysicalTableMetaProbe(?string $namePrefix): array
    {
        $sql  = 'SELECT TABLE_NAME AS name, TABLE_ROWS AS rows,
                        (DATA_LENGTH + INDEX_LENGTH) AS size_bytes
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()';
        $bind = [];
        if ($namePrefix !== null && $namePrefix !== '') {
            $sql    .= ' AND TABLE_NAME LIKE ?';
            $bind[]  = $namePrefix . '%';
        }
        $sql .= ' ORDER BY TABLE_NAME';

        return ['sql' => $sql, 'bind' => $bind];
    }
}
