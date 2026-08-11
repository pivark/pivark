<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\product;

use app\common\service\weapp\WeappItemGateway;

use app\common\support\AppTime;
use app\common\support\ServiceResult;

use app\common\service\item\ItemPublicGateway;
use app\common\support\DbTable;
use app\common\model\ProductItemRelation;
use app\common\model\Item;
use think\facade\Db;

/** 主品项 ↔ 配件/辅件（展示用；ERP BOM 另表） */
final class ProductItemRelationService
{
    public const TYPE_ACCESSORY = 'accessory';
    public const TYPE_SPARE     = 'spare';
    public const TYPE_COMPONENT = 'component';

    /** @return array<string, string> */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_ACCESSORY => '配件',
            self::TYPE_SPARE     => '备件',
            self::TYPE_COMPONENT => '辅件',
        ];
    }

    public static function isAvailable(): bool
    {
        return ProductCenterGateService::entitled() && self::tableExists();
    }

    /** 文档首关联品项是否配置了可展示的配件/辅件 */
    public static function hasPublicForDocument(int $documentId): bool
    {
        if ($documentId < 1 || !self::isAvailable()) {
            return false;
        }
        $itemIds = app(WeappItemGateway::class)->itemIdsForDocument($documentId);
        if ($itemIds === []) {
            return false;
        }

        return self::listPublicForParent((int) $itemIds[0], 1) !== [];
    }

    /**
     * @return ServiceResult
     */
    public static function listForParentAdmin(int $parentItemId): ServiceResult
    {
        if (!self::isAvailable()) {
            return ServiceResult::fail('产品中心未授权或表结构未就绪');
        }
        if ($parentItemId < 1) {
            return ServiceResult::fail('参数无效');
        }

        return ServiceResult::ok(self::rowsForParent($parentItemId, true));
    }

    /**
     * @param list<array<string, mixed>> $rows child_item_id, relation_type?, note?, qty?, sort?
     * @return ServiceResult
     */
    public static function syncForParent(int $parentItemId, array $rows): ServiceResult
    {
        if (!self::isAvailable()) {
            return ServiceResult::fail('产品中心未授权或表结构未就绪');
        }
        if ($parentItemId < 1 || !Item::where('id', $parentItemId)->find()) {
            return ServiceResult::fail('主品项不存在');
        }

        $labels = self::typeLabels();
        $ordered = [];
        $seen    = [];
        $sort    = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $childId = (int) ($row['child_item_id'] ?? $row['item_id'] ?? 0);
            if ($childId < 1 || $childId === $parentItemId || isset($seen[$childId])) {
                continue;
            }
            if (!Item::where('id', $childId)->find()) {
                continue;
            }
            $type = strtolower(trim((string) ($row['relation_type'] ?? self::TYPE_ACCESSORY)));
            if (!isset($labels[$type])) {
                $type = self::TYPE_ACCESSORY;
            }
            $qty = (float) ($row['qty'] ?? 1);
            if ($qty <= 0) {
                $qty = 1;
            }
            $seen[$childId] = true;
            $ordered[] = [
                'parent_item_id' => $parentItemId,
                'child_item_id'  => $childId,
                'relation_type'  => $type,
                'note'           => mb_substr(trim((string) ($row['note'] ?? '')), 0, 200),
                'qty'            => round($qty, 3),
                'sort'           => $sort++,
                'created_at'     => AppTime::now(),
            ];
        }

        Db::startTrans();
        try {
            ProductItemRelation::where('parent_item_id', $parentItemId)->delete();
            foreach ($ordered as $data) {
                ProductItemRelation::insert($data);
            }
            Db::commit();

            return ServiceResult::ok(null, 'ok');
        } catch (\Throwable $e) {
            Db::rollback();

            return ServiceResult::fail(\app\common\support\ClientErrorMessage::fromThrowable($e));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listPublicForParent(int $parentItemId, int $limit = 24, ?string $relationType = null): array
    {
        if ($parentItemId < 1 || $limit < 1 || !self::tableExists()) {
            return [];
        }
        $childIds = self::childIdsForParent($parentItemId, $limit, $relationType);
        if ($childIds === []) {
            return [];
        }
        $list = app(ItemPublicGateway::class)->listPublicByIds($childIds);
        $meta = self::relationMetaByParent($parentItemId);
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

    public static function purgeForItemId(int $itemId): void
    {
        if ($itemId < 1 || !self::tableExists()) {
            return;
        }
        ProductItemRelation::where('parent_item_id', $itemId)->delete();
        ProductItemRelation::where('child_item_id', $itemId)->delete();
    }

    public static function copyFromParent(int $fromParentId, int $toParentId): void
    {
        if ($fromParentId < 1 || $toParentId < 1 || $fromParentId === $toParentId || !self::tableExists()) {
            return;
        }
        $rows = ProductItemRelation::where('parent_item_id', $fromParentId)
            ->order('sort', 'asc')
            ->select()
            ->toArray();
        if ($rows === []) {
            return;
        }
        $payload = [];
        foreach ($rows as $row) {
            $payload[] = [
                'child_item_id' => (int) ($row['child_item_id'] ?? 0),
                'relation_type' => (string) ($row['relation_type'] ?? self::TYPE_ACCESSORY),
                'note'          => (string) ($row['note'] ?? ''),
                'qty'           => (float) ($row['qty'] ?? 1),
                'sort'          => (int) ($row['sort'] ?? 0),
            ];
        }
        self::syncForParent($toParentId, $payload);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rowsForParent(int $parentItemId, bool $adminDetail): array
    {
        if (!self::tableExists()) {
            return [];
        }
        $refs = ProductItemRelation::where('parent_item_id', $parentItemId)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        if ($refs === []) {
            return [];
        }
        $childIds = array_values(array_unique(array_map(
            static fn (array $r): int => (int) ($r['child_item_id'] ?? 0),
            $refs,
        )));
        $itemsById = [];
        foreach (app(ItemPublicGateway::class)->listPublicByIds($childIds) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemsById[(int) ($item['id'] ?? 0)] = $item;
        }
        if ($adminDetail) {
            foreach ($childIds as $cid) {
                if ($cid < 1 || isset($itemsById[$cid])) {
                    continue;
                }
                $row = Item::where('id', $cid)->find()?->toArray();
                if (!$row) {
                    continue;
                }
                $itemsById[$cid] = [
                    'id'     => $cid,
                    'code'   => (string) ($row['code'] ?? ''),
                    'name'   => (string) ($row['name'] ?? ''),
                    'status' => (string) ($row['status'] ?? ''),
                ];
            }
        }
        $labels = self::typeLabels();
        $out    = [];
        foreach ($refs as $ref) {
            $childId = (int) ($ref['child_item_id'] ?? 0);
            if ($childId < 1) {
                continue;
            }
            $type = (string) ($ref['relation_type'] ?? self::TYPE_ACCESSORY);
            $item = $itemsById[$childId] ?? [];
            $out[] = [
                'child_item_id'       => $childId,
                'relation_type'       => $type,
                'relation_type_text'  => $labels[$type] ?? $type,
                'note'                => (string) ($ref['note'] ?? ''),
                'qty'                 => (float) ($ref['qty'] ?? 1),
                'sort'                => (int) ($ref['sort'] ?? 0),
                'code'                => (string) ($item['code'] ?? ''),
                'name'                => (string) ($item['name'] ?? ''),
                'status'              => (string) ($item['status'] ?? ''),
                'cover_url'           => (string) ($item['cover_url'] ?? ''),
                'page_url'            => (string) ($item['page_url'] ?? $item['card_url'] ?? ''),
            ];
        }

        return $out;
    }

    /** @return list<int> */
    private static function childIdsForParent(int $parentItemId, int $limit, ?string $relationType): array
    {
        $query = ProductItemRelation::where('parent_item_id', $parentItemId)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->limit($limit);
        if ($relationType !== null && $relationType !== '') {
            $query->where('relation_type', $relationType);
        }
        $ids = [];
        foreach ($query->column('child_item_id') ?: [] as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return array<int, array{relation_type:string,relation_type_text:string,note:string,qty:float}> */
    private static function relationMetaByParent(int $parentItemId): array
    {
        $labels = self::typeLabels();
        $out    = [];
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

    private static function tableExists(): bool
    {
        return DbTable::modelExists(ProductItemRelation::class);
    }
}
