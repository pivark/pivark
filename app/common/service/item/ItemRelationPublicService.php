<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\item;

use app\common\model\ProductItemRelation;
use app\common\support\DbTable;

/** 主品项 ↔ 配件/辅件（Community 无 product 插件时前台展示） */
final class ItemRelationPublicService
{

    public const TYPE_ACCESSORY = 'accessory';
    public const TYPE_SPARE     = 'spare';
    public const TYPE_COMPONENT = 'component';

    public function isAvailable(): bool
    {
        return DbTable::modelExists(ProductItemRelation::class);
    }

    public function hasForParent(int $parentItemId): bool
    {
        return $this->listForParent($parentItemId, 1) !== [];
    }

    public function hasForDocument(int $documentId): bool
    {
        if ($documentId < 1 || !$this->isAvailable()) {
            return false;
        }
        $itemIds = app(ItemService::class)->itemIdsForDocument($documentId);

        return $itemIds !== [] && $this->hasForParent((int) $itemIds[0]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForParent(int $parentItemId, int $limit = 24, ?string $relationType = null): array
    {
        if ($parentItemId < 1 || $limit < 1 || !$this->isAvailable()) {
            return [];
        }

        $query = ProductItemRelation::where('parent_item_id', $parentItemId)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->limit($limit);
        if ($relationType !== null && $relationType !== '') {
            $query->where('relation_type', $relationType);
        }

        $childIds = [];
        foreach ($query->column('child_item_id') ?: [] as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $childIds[] = $id;
            }
        }
        $childIds = array_values(array_unique($childIds));
        if ($childIds === []) {
            return [];
        }

        $list = app(ItemService::class)->listPublicByIds($childIds);
        $meta = $this->relationMetaByParent($parentItemId);
        foreach ($list as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            $m  = $meta[$id] ?? [];
            $row['relation_type']      = (string) ($m['relation_type'] ?? self::TYPE_ACCESSORY);
            $row['relation_type_text'] = (string) ($m['relation_type_text'] ?? '');
            $row['relation_note']      = (string) ($m['note'] ?? '');
            $row['relation_qty']       = (float) ($m['qty'] ?? 1);
        }
        unset($row);

        return $list;
    }

    /** @return array<int, array{relation_type:string,relation_type_text:string,note:string,qty:float}> */
    private function relationMetaByParent(int $parentItemId): array
    {
        $labels = [
            self::TYPE_ACCESSORY => '配件',
            self::TYPE_SPARE     => '备件',
            self::TYPE_COMPONENT => '辅件',
        ];
        $out = [];
        foreach (ProductItemRelation::where('parent_item_id', $parentItemId)->select()->toArray() as $ref) {
            $cid = (int) ($ref['child_item_id'] ?? 0);
            if ($cid < 1) {
                continue;
            }
            $type = (string) ($ref['relation_type'] ?? self::TYPE_ACCESSORY);
            $out[$cid] = [
                'relation_type'      => $type,
                'relation_type_text' => $labels[$type] ?? $type,
                'note'               => (string) ($ref['note'] ?? ''),
                'qty'                => (float) ($ref['qty'] ?? 1),
            ];
        }

        return $out;
    }
}
