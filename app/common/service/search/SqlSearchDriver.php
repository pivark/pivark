<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\model\Document;
use app\common\service\content\ContentSearchService;
use app\common\service\search\SearchConfigService;
use app\common\support\search\SearchDriverInterface;

/** MySQL 检索（LIKE / 分词），不依赖外部引擎 */
final class SqlSearchDriver implements SearchDriverInterface
{
    public function __construct(
        private readonly ContentSearchService $contentSearch,
        private readonly SearchMemberContext $memberContext,
        private readonly SearchFulltextSupport $fulltext,
    ) {
    }

    public function name(): string
    {
        return 'sql';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function searchDocumentIds(string $keyword, int $page, int $limit, array $context = []): array
    {
        $keyword = $this->contentSearch->normalizeKeyword($keyword);
        $page = max(1, $page);
        $limit = min(max($limit, 1), 100);
        if ($keyword === '') {
            return ['ids' => [], 'total' => 0];
        }

        $ctx   = $context !== [] ? $context : $this->memberContext->forPublicSearch();
        $query = Document::where('status', 1)->whereNull('deleted_at');
        if ($this->fulltext->useFulltext()) {
            $this->fulltext->applyFulltext($query, $keyword);
        } else {
            $this->contentSearch->applyArticleKeyword($query, $keyword, false);
        }
        $this->memberContext->applyReadPermToQuery($query, $ctx);
        $this->memberContext->applyBlockedTagsToQuery($query, $ctx);

        $excludeIds = is_array($context['exclude_document_ids'] ?? null)
            ? array_values(array_filter(array_map('intval', $context['exclude_document_ids']), static fn (int $id): bool => $id > 0))
            : [];
        if ($excludeIds !== []) {
            $query->whereNotIn('id', $excludeIds);
        }

        $total = (int) $query->count();
        $ids = $query->page($page, $limit)->order('id', 'desc')->column('id');

        return [
            'ids' => array_values(array_map('intval', $ids)),
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    public function upsert(array $record): void
    {
        // SQL 模式以 MySQL 为准，无需外部索引
    }

    public function delete(int $documentId): void
    {
    }

    public function reindexBatch(iterable $records): int
    {
        return 0;
    }
}
