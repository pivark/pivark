<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\item;
use app\common\support\CatalogExistsSubqueryAlias;
use app\common\model\ItemTag;

use think\facade\Db;

use app\common\service\tag\TagService;

/** 品项 TAG 范围（EXISTS，避免 whereIn 大数组） */
final class ItemTagScopeService
{

    public function __construct(
        private readonly TagService $tags,
    ) {
    }

    /**
     * @param \think\db\Query|\think\Model $query
     */
    public function applyExistsByTagId($query, int $tagId, string $entityIdColumn = 'id', string $entityAlias = ''): void
    {
        if ($tagId < 1) {
            $col = $this->qualifiedIdColumn($query, $entityIdColumn, $entityAlias);
            $query->where($col, 0);

            return;
        }
        $idCol    = $this->qualifiedIdColumn($query, $entityIdColumn, $entityAlias);
        $tagTable = ItemTag::getTable();
        $query->whereExists(function ($sub) use ($tagTable, $idCol, $tagId) {
            $alias = CatalogExistsSubqueryAlias::ITEM_TAG;
            $sub->table($tagTable)->alias($alias)
                ->whereRaw($alias . '.item_id = ' . $idCol)
                ->where($alias . '.tag_id', $tagId);
        });
    }

    /**
     * @param \think\db\Query|\think\Model $query
     */
    public function applyExistsBySlug($query, string $tagSlug, string $entityIdColumn = 'id', string $entityAlias = ''): bool
    {
        $tagSlug = trim($tagSlug);
        if ($tagSlug === '') {
            return true;
        }
        $tag = $this->tags->findBySlug($tagSlug);
        if ($tag === null) {
            return false;
        }
        $this->applyExistsByTagId($query, (int) ($tag['id'] ?? 0), $entityIdColumn, $entityAlias);

        return true;
    }

    /**
     * EXISTS 子查询须引用外表主键；裸 `id` 在子查询内会解析为 {@see CatalogExistsSubqueryAlias::ITEM_TAG}.id，导致 TAG 筛选恒为空。
     *
     * @param \think\db\Query|\think\Model $query
     */
    private function qualifiedIdColumn($query, string $entityIdColumn, string $entityAlias): string
    {
        $col = preg_match('/^[a-zA-Z0-9_]+$/', $entityIdColumn) ? $entityIdColumn : 'id';
        if ($entityAlias !== '') {
            return $entityAlias . '.' . $col;
        }
        $table = method_exists($query, 'getTable') ? trim((string) $query->getTable()) : '';

        return $table !== '' ? $table . '.' . $col : $col;
    }
}
