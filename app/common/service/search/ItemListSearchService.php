<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\model\Item;
use app\common\service\item\ItemService;
use app\common\service\product\ProductCenterGateService;
use app\common\service\plugin\entitlement\EntitlementService;

/** 品项目录关键词检索（MySQL / Meili / Elastic 预留） */
final class ItemListSearchService
{

    public function __construct(
        private readonly ItemCatalogSearchDriverFactory $itemDriverFactory,
        private readonly MeilisearchItemIndex $meiliItemIndex,
        private readonly SearchDegradedGuard $degradedGuard,
        private readonly EntitlementService $entitlements,
        private readonly ProductCenterGateService $productCenterGate,
    ) {
    }

    /**
     * @param \think\db\Query<Item>|mixed $query
     * @param array<string, mixed>       $params filter_* / tag 等
     */
    public function applyKeywordFilter($query, string $keyword, array $params = []): void
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return;
        }

        $driver = $this->itemDriverFactory->makeForList();
        if ($driver->name() !== ItemCatalogSearchDriverFactory::DRIVER_SQL) {
            $hasMeiliFilters = $driver->name() === ItemCatalogSearchDriverFactory::DRIVER_MEILI
                && $this->meiliItemIndex->extractAttrFilters($params) !== [];
            $limit = $hasMeiliFilters ? 2000 : 500;
            $ids   = $driver->searchIds($keyword, $limit, $params);
            if ($ids !== []) {
                $query->whereIn('id', $ids);

                return;
            }
            if ($driver->strictKeyword()) {
                $query->whereIn('id', [0]);

                return;
            }
        }

        if ($this->degradedGuard->isDegraded() || $this->degradedGuard->shouldRejectSqlFallback($keyword)) {
            $query->whereIn('id', [0]);

            return;
        }

        if ($this->productCenterGate->allowsParams()
            && class_exists(\app\common\service\product\ProductItemSearchService::class)) {
            \app\common\service\product\ProductItemSearchService::applyKeywordFilter($query, $keyword);

            return;
        }

        $query->where(function ($q) use ($keyword) {
            $q->whereLike('code', '%' . $keyword . '%')
                ->whereOr('name', 'like', '%' . $keyword . '%')
                ->whereOr('slug', 'like', '%' . $keyword . '%');
        });
    }
}
