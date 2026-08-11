<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\service\content\ContentSearchService;

/**
 * 品项 Elasticsearch / OpenSearch 索引（Phase C 桩）
 * 实现 searchIds / upsert / delete 后，将 item_catalog_search_driver 设为 elastic 即可启用。
 */
final class ElasticItemIndex
{

    public function __construct(
        private readonly ElasticSearchHttpClient $elasticClient,
        private readonly ContentSearchService $contentSearch,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->elasticClient->isAvailable();
    }

    /**
     * @return list<int>
     */
    public function searchIds(string $keyword, int $limit = 24): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $keyword = $this->contentSearch->normalizeKeyword($keyword);
        if ($keyword === '') {
            return [];
        }

        return $this->elasticClient->searchItemIds($keyword, $limit);
    }

    /** @param array<string, mixed> $record */
    public function upsert(array $record): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        $id = (int) ($record['id'] ?? 0);
        if ($id < 1) {
            return;
        }
        $this->elasticClient->upsertDocument($this->elasticClient->itemIndexName(), $id, $record);
    }

    public function delete(int $itemId): void
    {
        if ($itemId < 1 || !$this->isAvailable()) {
            return;
        }
        $this->elasticClient->deleteDocument($this->elasticClient->itemIndexName(), $itemId);
    }
}
