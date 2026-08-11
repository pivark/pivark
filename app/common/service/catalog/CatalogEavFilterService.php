<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\catalog;
use app\common\support\CatalogExistsSubqueryAlias;

use app\common\support\DbTable;
use think\db\Query;

/** EAV 精确筛选（EXISTS 子查询，ERP/MES/Shop 流水扩展表可复用） */
final class CatalogEavFilterService
{

    /**
     * @param array<string, mixed> $params filter_* 键值
     */
    public function applyExistsFilters(
        Query $query,
        array $params,
        string $eavTable,
        string $entityIdColumn,
        string $entityAlias = '',
        string $eavEntityFkColumn = 'item_id',
    ): void {
        $entityAlias = $this->assertSqlIdentifier($entityAlias, 'entityAlias', true);
        $entityIdColumn = $this->assertSqlIdentifier($entityIdColumn, 'entityIdColumn');
        $eavTable = $this->assertSqlIdentifier($eavTable, 'eavTable');
        $prefix = $entityAlias !== '' ? $entityAlias . '.' : '';
        $idCol  = $prefix . $entityIdColumn;
        $eavFk  = $this->assertSqlIdentifier($eavEntityFkColumn, 'eavEntityFkColumn');
        foreach ($params as $paramKey => $paramVal) {
            $key = (string) $paramKey;
            if (!str_starts_with($key, 'filter_')) {
                continue;
            }
            $attrKey = substr($key, 7);
            $val     = trim((string) $paramVal);
            if ($attrKey === '' || $val === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $attrKey)) {
                continue;
            }
            $query->whereExists(function ($sub) use ($eavTable, $idCol, $eavFk, $attrKey, $val) {
                $alias = CatalogExistsSubqueryAlias::EAV;
                $sub->table($eavTable)->alias($alias)
                    ->whereRaw($alias . '.' . $eavFk . ' = ' . $idCol)
                    ->where($alias . '.param_key', $attrKey)
                    ->where($alias . '.attr_value', $val);
            });
        }
    }

    /**
     * 上下文 Facet：在实体子查询范围内按 param_key 聚合（禁止拉全量 ID 到 PHP）
     *
     * @param callable(\think\db\Query $sub): void $applyEntityScope
     * @return array<string, int>
     */
    public function aggregateFacetCounts(
        string $eavTable,
        string $paramKey,
        callable $applyEntityScope,
        string $eavEntityFkColumn = 'item_id',
        string $entityTable = 'items',
        string $entityIdColumn = 'id',
        string $entityAlias = 'i',
    ): array {
        $paramKey = strtolower(trim($paramKey));
        if ($paramKey === '' || !preg_match('/^[a-z0-9_]+$/', $paramKey)) {
            return [];
        }
        $eavTable = $this->assertSqlIdentifier($eavTable, 'eavTable');
        $eavFk = $this->assertSqlIdentifier($eavEntityFkColumn, 'eavEntityFkColumn');
        $eTbl  = $this->assertSqlIdentifier($entityTable, 'entityTable');
        $eId   = $this->assertSqlIdentifier($entityIdColumn, 'entityIdColumn');
        $eAl   = $this->assertSqlIdentifier($entityAlias, 'entityAlias');

        $agg = DbTable::query($eavTable)->alias('fv')
            ->where('fv.param_key', $paramKey)
            ->whereIn('fv.' . $eavFk, function ($sub) use ($applyEntityScope, $eTbl, $eAl, $eId) {
                $sub->name($eTbl)->alias($eAl)->field($eAl . '.' . $eId);
                $applyEntityScope($sub);
            })
            ->field('fv.attr_value, COUNT(DISTINCT fv.' . $eavFk . ') AS cnt')
            ->group('fv.attr_value')
            ->order('fv.attr_value', 'asc')
            ->select()
            ->toArray();

        $rows = [];
        foreach ($agg as $row) {
            if (!is_array($row)) {
                continue;
            }
            $val = trim((string) ($row['attr_value'] ?? ''));
            if ($val !== '') {
                $rows[$val] = (int) ($row['cnt'] ?? 0);
            }
        }

        return $rows;
    }

    private function assertSqlIdentifier(string $name, string $label, bool $allowEmpty = false): string
    {
        $name = trim($name);
        if ($name === '') {
            if ($allowEmpty) {
                return '';
            }
            throw new \InvalidArgumentException($label . ' 不能为空');
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException($label . ' 含非法字符');
        }

        return $name;
    }
}
