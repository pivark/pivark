<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\item;

use app\common\support\AppTime;
use app\common\support\QueryLimit;
use app\common\support\DbTable;
use app\common\model\ItemAttrValue;

use think\db\Query;
use think\facade\Db;

/** 品项规格 EAV（P0：替代 items.attrs JSON_EXTRACT 筛选） */
final class ItemAttrValueService
{

    public function tableExists(): bool
    {
        return DbTable::modelExists(ItemAttrValue::class);
    }

    /**
     * @param array<string, mixed> $attrs
     */
    public function syncFromAttrs(int $itemId, array $attrs): void
    {
        if ($itemId < 1 || !$this->tableExists()) {
            return;
        }
        ItemAttrValue::where('item_id', $itemId)->delete();
        $now = AppTime::now();
        foreach ($attrs as $key => $val) {
            $paramKey = strtolower(trim((string) $key));
            if ($paramKey === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $paramKey)) {
                continue;
            }
            // 嵌套结构只存 items.attrs JSON；EAV 仅同步标量筛选字段
            if (is_array($val) || is_object($val)) {
                continue;
            }
            $attrVal = trim((string) $val);
            if ($attrVal === '') {
                continue;
            }
            ItemAttrValue::insert([
                'item_id'    => $itemId,
                'param_key'  => mb_substr($paramKey, 0, 64),
                'attr_value' => mb_substr($attrVal, 0, 255),
                'created_at' => $now,
            ]);
        }
    }

    public function deleteForItem(int $itemId): void
    {
        if ($itemId < 1 || !$this->tableExists()) {
            return;
        }
        ItemAttrValue::where('item_id', $itemId)->delete();
    }

    /**
     * @param array<string, mixed> $params filter_color 等
     */
    public function applyPublicFilters(Query $query, array $params): void
    {
        if (!$this->tableExists()) {
            return;
        }
        $matched = $this->itemIdsMatchingFilters($params);
        if ($matched !== null) {
            $query->whereIn('id', $matched !== [] ? $matched : [0]);
        }
    }

    /**
     * EAV 交集匹配 item_id；无 filter_* 时返回 null（表示不筛选）
     *
     * @param array<string, mixed> $params filter_* 键值
     * @return list<int>|null
     */
    public function itemIdsMatchingFilters(array $params): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }
        /** @var list<int>|null $matched */
        $matched = null;
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
            $ids = ItemAttrValue::where('param_key', $attrKey)
                ->where('attr_value', $val)
                ->column('item_id');
            $ids = array_values(array_unique(array_map('intval', $ids ?: [])));
            $matched = $matched === null ? $ids : array_values(array_intersect($matched, $ids));
        }

        return $matched;
    }

    /** @return list<array{param_key:string,attr_value:string,unit:string}> */
    public function valuesForItem(int $itemId): array
    {
        if ($itemId < 1 || !$this->tableExists()) {
            return [];
        }
        $rows = ItemAttrValue::where('item_id', $itemId)
            ->order('param_key', 'asc')
            ->select()
            ->toArray();
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'param_key'  => (string) ($row['param_key'] ?? ''),
                'attr_value' => (string) ($row['attr_value'] ?? ''),
                'unit'       => '',
            ];
        }

        return $out;
    }

    /** @return list<string> */
    public function distinctValues(string $paramKey, int $limit = QueryLimit::SITEMAP_BATCH): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        $paramKey = strtolower(trim($paramKey));
        if ($paramKey === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $paramKey)) {
            return [];
        }
        $limit = min(max($limit, 1), 500);
        $rows = ItemAttrValue::alias('v')
            ->join('items i', 'i.id = v.item_id')
            ->where('v.param_key', $paramKey)
            ->where('i.status', ItemService::STATUS_ACTIVE)
            ->group('v.attr_value')
            ->order('v.attr_value', 'asc')
            ->limit($limit)
            ->column('v.attr_value');

        return array_values(array_filter(array_map('strval', $rows ?: [])));
    }
}
