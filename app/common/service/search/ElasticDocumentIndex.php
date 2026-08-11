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

/** 文档 Elasticsearch 索引（Phase C 桩） */
final class ElasticDocumentIndex
{

    public function __construct(
        private readonly ElasticSearchHttpClient $http,
        private readonly ContentSearchService $contentSearch,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->http->isAvailable();
    }

    /**
     * @return array{ids:list<int>,total:int}
     */
    public function searchIds(string $keyword, int $page = 1, int $limit = 24): array
    {
        if (!$this->isAvailable()) {
            return ['ids' => [], 'total' => 0];
        }
        $keyword = $this->contentSearch->normalizeKeyword($keyword);
        if ($keyword === '') {
            return ['ids' => [], 'total' => 0];
        }
        $page   = max(1, $page);
        $limit  = max(1, min(100, $limit));
        $offset = ($page - 1) * $limit;

        return $this->http->searchDocumentIds($keyword, $offset, $limit);
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
        $this->http->upsertDocument($this->http->documentIndexName(), $id, $record);
    }

    public function delete(int $documentId): void
    {
        if ($documentId < 1 || !$this->isAvailable()) {
            return;
        }
        $this->http->deleteDocument($this->http->documentIndexName(), $documentId);
    }
}
