<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support\dialect;

/** 元数据探测 SQL 方言（MySQL 默认；信创库可扩展实现） */
interface DialectAdapter
{
    /**
     * @return array{sql:string,bind:list<mixed>}
     */
    public function tableExistsProbe(string $physicalTable): array;

    /**
     * @return array{sql:string,bind:list<mixed>}
     */
    public function indexExistsProbe(string $physicalTable, string $indexName): array;

    /**
     * @return array{sql:string,bind:list<mixed>}
     */
    public function listPhysicalTablesProbe(?string $namePrefix): array;

    /**
     * @return array{sql:string,bind:list<mixed>}
     */
    public function listPhysicalTableMetaProbe(?string $namePrefix): array;
}
