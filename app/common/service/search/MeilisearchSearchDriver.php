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
use app\common\service\search\SearchConfigService;
use app\common\support\search\SearchDriverInterface;

/** Meilisearch 全文驱动（系统内置） */
final class MeilisearchSearchDriver implements SearchDriverInterface
{
    private ?MeilisearchHttpClient $client = null;

    public function __construct(
        private readonly SearchConfigService $searchConfig,
        private readonly ContentSearchService $contentSearch,
        private readonly SearchMemberContext $memberContext,
        private readonly SearchModeHelper $searchMode,
    ) {
    }

    public function name(): string
    {
        return 'meili';
    }

    public function isAvailable(): bool
    {
        $cfg = $this->searchConfig->meiliConfig();
        if ($cfg['host'] === '') {
            return false;
        }

        return $this->client()->health();
    }

    public function searchDocumentIds(string $keyword, int $page, int $limit, array $context = []): array
    {
        $keyword = $this->contentSearch->normalizeKeyword($keyword);
        $page = max(1, $page);
        $limit = min(max($limit, 1), 100);
        if ($keyword === '' || !$this->isAvailable()) {
            return ['ids' => [], 'total' => 0];
        }

        $cfg = $this->searchConfig->meiliConfig();
        $offset = ($page - 1) * $limit;
        $ctx    = $context !== [] ? $context : $this->memberContext->forPublicSearch();
        $filter = $this->memberContext->meiliFilter($ctx);
        $mode   = $this->searchMode->meiliSearchParams($keyword);
        $res    = $this->client()->search(
            $cfg['index'],
            $mode['query'],
            $offset,
            $limit,
            $filter,
            $mode['attributesToSearchOn'],
        );
        $ids = [];
        foreach ($res['hits'] as $hit) {
            $ids[] = (int) ($hit['id'] ?? 0);
        }
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));

        return [
            'ids' => $ids,
            'total' => max((int) $res['estimatedTotal'], count($ids)),
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    public function upsert(array $record): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        $cfg = $this->searchConfig->meiliConfig();
        $client = $this->client();
        $client->ensureIndex($cfg['index'], 'id');
        $client->patchIndexSettings($cfg['index']);
        $client->addDocuments($cfg['index'], [$record]);
    }

    public function delete(int $documentId): void
    {
        if ($documentId < 1 || !$this->isAvailable()) {
            return;
        }
        $cfg = $this->searchConfig->meiliConfig();
        $this->client()->deleteDocuments($cfg['index'], [$documentId]);
    }

    /**
     * @param iterable<int, array<string, mixed>> $records
     */
    public function reindexBatch(iterable $records): int
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Meilisearch 不可用，请检查连接配置');
        }
        $cfg = $this->searchConfig->meiliConfig();
        $client = $this->client();
        $client->ensureIndex($cfg['index'], 'id');
        $client->patchIndexSettings($cfg['index']);
        $client->deleteAllDocuments($cfg['index']);

        $batch = [];
        $count = 0;
        foreach ($records as $record) {
            $batch[] = $record;
            if (count($batch) >= 500) {
                $client->addDocuments($cfg['index'], $batch);
                $count += count($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $client->addDocuments($cfg['index'], $batch);
            $count += count($batch);
        }

        return $count;
    }

    /**
     * @param iterable<int, array<string, mixed>> $records
     */
    public function appendBatch(iterable $records): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        $cfg    = $this->searchConfig->meiliConfig();
        $client = $this->client();
        $client->ensureIndex($cfg['index'], 'id');
        $batch  = [];
        $count  = 0;
        foreach ($records as $record) {
            $batch[] = $record;
            if (count($batch) >= 500) {
                $client->addDocuments($cfg['index'], $batch);
                $count += count($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $client->addDocuments($cfg['index'], $batch);
            $count += count($batch);
        }

        return $count;
    }

    private function client(): MeilisearchHttpClient
    {
        if ($this->client === null) {
            $cfg = $this->searchConfig->meiliConfig();
            $this->client = new MeilisearchHttpClient($cfg['host'], $cfg['key'], $cfg['timeout']);
        }

        return $this->client;
    }
}
