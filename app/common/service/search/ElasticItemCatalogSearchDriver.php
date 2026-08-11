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

/**
 * OpenSearch / Elasticsearch 品项索引（Phase C 预留）
 * 配置 pivark.item_catalog_search_driver=elastic 且接入 ElasticItemIndex 后启用。
 */
final class ElasticItemCatalogSearchDriver implements ItemCatalogSearchDriverInterface
{
    public function name(): string
    {
        return ItemCatalogSearchDriverFactory::DRIVER_ELASTIC;
    }

    public function isAvailable(): bool
    {
        return class_exists(ElasticItemIndex::class) && app(ElasticItemIndex::class)->isAvailable();
    }

    public function strictKeyword(): bool
    {
        return false;
    }

    public function searchIds(string $keyword, int $limit = 24, array $context = []): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        return app(ElasticItemIndex::class)->searchIds($keyword, $limit);
    }
}
