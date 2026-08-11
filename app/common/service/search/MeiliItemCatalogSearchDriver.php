<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\support\search\ItemCatalogSearchDriverInterface;

final class MeiliItemCatalogSearchDriver implements ItemCatalogSearchDriverInterface
{
    public function name(): string
    {
        return ItemCatalogSearchDriverFactory::DRIVER_MEILI;
    }

    public function isAvailable(): bool
    {
        return app(MeilisearchItemIndex::class)->isAvailable();
    }

    public function strictKeyword(): bool
    {
        return false;
    }

    public function searchIds(string $keyword, int $limit = 24, array $context = []): array
    {
        $meiliItems = app(MeilisearchItemIndex::class);
        $filters    = $meiliItems->extractAttrFilters($context);
        $limit      = max(1, min($filters !== [] ? 2000 : 500, $limit));
        $res        = app(ItemSearchIndexService::class)->searchIds($keyword, $limit, $filters);

        return array_values(array_filter(array_map('intval', $res['ids'])));
    }
}
