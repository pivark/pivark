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
use app\common\service\item\ItemTagScopeService;
use app\common\service\item\ItemFacetCacheService;
use app\common\service\item\ItemAttrValueService;
use app\common\support\DbTable;
use app\common\model\ItemFilterFacet;
use app\common\model\ItemAttrValue;

use app\common\service\catalog\CatalogEavFilterService;
use app\common\service\catalog\CatalogFacetStatsService;
use app\common\service\infra\FrontCacheInvalidator;

/** 品项筛选 Facet 物化计数（Phase B）；上下文计数走 SQL 子查询聚合 */

final class ItemFilterFacetService
{

    public function __construct(
        private readonly ItemAttrValueService $itemAttrValueService,
        private readonly CatalogFacetStatsService $catalogFacetStatsService,
        private readonly FrontCacheInvalidator $frontCacheInvalidator,
        private readonly ItemFacetCacheService $itemFacetCacheService,
        private readonly CatalogEavFilterService $catalogEavFilterService,
        private readonly ItemTagScopeService $itemTagScopeService,
    ) {
    }

    public function tableExists(): bool
    {
        return DbTable::modelExists(ItemFilterFacet::class);
    }

    /** 品项 attrs/EAV 变更后重建相关 param_key 的全局 facet */

    public function syncAfterItemChange(int $itemId): void

    {

        if ($itemId < 1 || !$this->tableExists() || !$this->itemAttrValueService->tableExists()) {

            return;

        }

        $keys = ItemAttrValue::where('item_id', $itemId)->column('param_key');

        if ($keys === []) {

            return;

        }

        $this->rebuildForParamKeys(array_values(array_unique(array_map('strval', $keys))));

    }

    /** @param list<string> $paramKeys */

    public function syncAfterItemDeleted(array $paramKeys): void

    {

        if ($paramKeys === [] || !$this->tableExists()) {

            return;

        }

        $this->rebuildForParamKeys($paramKeys);

    }

    /**

     * @param list<string> $paramKeys

     */

    public function rebuildForParamKeys(array $paramKeys): void

    {

        if (!$this->tableExists() || !$this->itemAttrValueService->tableExists()) {

            return;

        }

        $paramKeys = array_values(array_filter(array_map(static function ($k): string {

            $k = strtolower(trim((string) $k));

            return preg_match('/^[a-z0-9_]+$/', $k) ? $k : '';

        }, $paramKeys)));

        if ($paramKeys === []) {

            return;

        }

        $grouped = $this->aggregateFacetsForParamKeys($paramKeys);
        ItemFilterFacet::whereIn('param_key', $paramKeys)->delete();
        $now = AppTime::now();

        foreach ($paramKeys as $paramKey) {
            $agg = $grouped[$paramKey] ?? [];
            foreach ($agg as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $val = trim((string) ($row['attr_value'] ?? ''));
                if ($val === '') {
                    continue;
                }
                ItemFilterFacet::insert([
                    'param_key'  => $paramKey,
                    'attr_value' => mb_substr($val, 0, 255),
                    'item_count' => max(0, (int) ($row['item_count'] ?? 0)),
                    'updated_at' => $now,
                ]);
            }
        }

        if ($this->catalogFacetStatsService->tableExists()) {
            $this->catalogFacetStatsService->rebuildForParamKeys(
                CatalogFacetStatsService::DOMAIN_ITEMS,
                '',
                $paramKeys,
                static function (string $paramKey) use ($grouped): array {
                    $rows = [];
                    foreach ($grouped[$paramKey] ?? [] as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $val = trim((string) ($row['attr_value'] ?? ''));
                        if ($val !== '') {
                            $rows[$val] = max(0, (int) ($row['item_count'] ?? 0));
                        }
                    }

                    return $rows;
                },
            );
        }

        $this->frontCacheInvalidator->invalidateItemCatalog();

    }

    /**
     * @param list<string> $paramKeys
     * @return array<string, list<array<string, mixed>>>
     */
    private function aggregateFacetsForParamKeys(array $paramKeys): array
    {
        if ($paramKeys === []) {
            return [];
        }
        $rows = ItemAttrValue::alias('v')
            ->join('items i', 'i.id = v.item_id')
            ->whereIn('v.param_key', $paramKeys)
            ->where('i.status', ItemService::STATUS_ACTIVE)
            ->field('v.param_key, v.attr_value, COUNT(DISTINCT v.item_id) AS item_count')
            ->group('v.param_key, v.attr_value')
            ->select()
            ->toArray();
        $grouped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pk = (string) ($row['param_key'] ?? '');
            if ($pk === '') {
                continue;
            }
            $grouped[$pk][] = $row;
        }

        return $grouped;
    }

    public function rebuildAll(): int

    {

        if (!$this->tableExists() || !$this->itemAttrValueService->tableExists()) {

            return 0;

        }

        ItemFilterFacet::where('id', '>', 0)->delete();

        $rows = ItemAttrValue::alias('v')
            ->join('items i', 'i.id = v.item_id')
            ->where('i.status', ItemService::STATUS_ACTIVE)
            ->field('v.param_key, v.attr_value, COUNT(DISTINCT v.item_id) AS item_count')
            ->group('v.param_key, v.attr_value')
            ->select()
            ->toArray();

        $now = AppTime::now();

        $n   = 0;

        foreach ($rows as $row) {

            if (!is_array($row)) {

                continue;

            }

            ItemFilterFacet::insert([

                'param_key'  => (string) ($row['param_key'] ?? ''),

                'attr_value' => mb_substr((string) ($row['attr_value'] ?? ''), 0, 255),

                'item_count' => max(0, (int) ($row['item_count'] ?? 0)),

                'updated_at' => $now,

            ]);

            $n++;

        }

        if ($this->catalogFacetStatsService->tableExists()) {
            $keys = ItemFilterFacet::group('param_key')->column('param_key');
            if ($keys !== []) {
                $this->catalogFacetStatsService->rebuildForParamKeys(
                    CatalogFacetStatsService::DOMAIN_ITEMS,
                    '',
                    array_map('strval', $keys),
                    function (string $paramKey): array {
                        return $this->intMap(
                            ItemFilterFacet::where('param_key', $paramKey)
                                ->column('item_count', 'attr_value') ?: [],
                        );
                    },
                );
            }
        }

        $this->frontCacheInvalidator->invalidateItemCatalog();

        return $n;

    }

    /**

     * @param array<string, mixed> $activeFilters filter_* / tag

     * @return array<string, int> attr_value => count

     */

    public function countsForParam(string $paramKey, array $activeFilters = []): array

    {

        $paramKey = strtolower(trim($paramKey));

        if ($paramKey === '' || !preg_match('/^[a-z0-9_]+$/', $paramKey)) {

            return [];

        }

        $scopeTag = trim((string) ($activeFilters['tag'] ?? ''));

        $filters  = $this->normalizeFilterParams($activeFilters);

        unset($filters['filter_' . $paramKey]);

        if ($scopeTag === '' && $filters === []) {
            if ($this->catalogFacetStatsService->tableExists()) {
                $rows = $this->catalogFacetStatsService->counts(CatalogFacetStatsService::DOMAIN_ITEMS, '', $paramKey);
                if ($rows !== []) {
                    return $this->intMap($rows);
                }
            }
            if ($this->tableExists()) {
                $rows = ItemFilterFacet::where('param_key', $paramKey)
                    ->where('item_count', '>', 0)
                    ->order('attr_value', 'asc')
                    ->column('item_count', 'attr_value');

                return $this->intMap($rows ?: []);
            }

            return [];
        }

        $tag = $this->cacheTag($paramKey, $filters, $scopeTag);

        return $this->itemFacetCacheService->remember($tag, function () use ($paramKey, $filters, $scopeTag): array {

            return $this->queryContextualCounts($paramKey, $filters, $scopeTag);

        });

    }

    /**

     * @param array<string, string> $filters

     * @return array<string, int>

     */

    private function queryContextualCounts(string $paramKey, array $filters, string $scopeTag): array

    {

        if (!$this->itemAttrValueService->tableExists()) {

            return [];

        }

        return $this->catalogEavFilterService->aggregateFacetCounts(
            'item_attr_values',
            $paramKey,
            function ($sub) use ($filters, $scopeTag): void {
                $sub->where('i.status', ItemService::STATUS_ACTIVE);
                if ($scopeTag !== '') {
                    if (!$this->itemTagScopeService->applyExistsBySlug($sub, $scopeTag, 'id', 'i')) {
                        $sub->where('i.id', 0);
                    }
                }
                $this->catalogEavFilterService->applyExistsFilters(
                    $sub,
                    $filters,
                    ItemAttrValue::getTable(),
                    'id',
                    'i',
                    'item_id',
                );
            },
            'item_id',
            'items',
            'id',
            'i',
        );

    }

    /**

     * @param array<string, mixed> $params

     * @return array<string, string>

     */

    public function normalizeFilterParams(array $params): array

    {

        $out = [];

        foreach ($params as $k => $v) {

            $key = (string) $k;

            if (!str_starts_with($key, 'filter_')) {

                continue;

            }

            $val = trim((string) $v);

            if ($val !== '') {

                $out[$key] = $val;

            }

        }

        ksort($out);

        return $out;

    }

    /** @param array<string, string> $filters */

    private function cacheTag(string $paramKey, array $filters, string $scopeTag): string

    {

        $ctx = ['tag' => $scopeTag, 'filters' => $filters];

        return 'item_facet_' . $paramKey . '_' . hash('sha256', json_encode($ctx, JSON_UNESCAPED_UNICODE));

    }

    /** @param array<string, mixed> $rows */

    private function intMap(array $rows): array

    {

        $out = [];

        foreach ($rows as $val => $cnt) {

            $val = trim((string) $val);

            if ($val === '') {

                continue;

            }

            $out[$val] = max(0, (int) $cnt);

        }

        return $out;

    }

}

