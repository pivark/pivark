<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * Elasticsearch 驱动（Phase C 桩：配置 search_driver=elastic 且集群可用时启用）
 *
 * @see docs/03-开发/ElasticSearch预留说明.md
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\support\search\SearchDriverInterface;

final class ElasticSearchDriver implements SearchDriverInterface
{

    public function __construct(
        private readonly SearchConfigService $searchConfig,
        private readonly ElasticDocumentIndex $elasticIndex,
    ) {
    }

    public function name(): string
    {
        return SearchConfigService::DRIVER_ELASTIC;
    }

    public function isAvailable(): bool
    {
        return $this->searchConfig->driver() === SearchConfigService::DRIVER_ELASTIC
            && $this->elasticIndex->isAvailable();
    }

    public function searchDocumentIds(string $keyword, int $page, int $limit, array $context = []): array
    {
        unset($context);

        return $this->elasticIndex->searchIds($keyword, $page, $limit);
    }

    /**
     * @param array<string, mixed> $record
     */
    public function upsert(array $record): void
    {
        $this->elasticIndex->upsert($record);
    }

    public function delete(int $documentId): void
    {
        $this->elasticIndex->delete($documentId);
    }

    public function reindexBatch(iterable $records): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        $count = 0;
        foreach ($records as $record) {
            $this->elasticIndex->upsert($record);
            $count++;
        }

        return $count;
    }
}
