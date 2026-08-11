<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\static;

use app\common\model\Document;
use app\common\support\DbRead;
use think\db\Query;

/** 静态生成用文档查询（可走只读库） */
final class StaticHtmlDocumentQuery
{

    public function publishedQuery(): Query
    {
        $conn = DbRead::connectionName();

        return $conn !== null
            ? Document::connect($conn)->where('status', 1)->whereNull('deleted_at')
            : Document::where('status', 1)->whereNull('deleted_at');
    }

    public function countPublished(array $params = []): int
    {
        return (int) $this->applyFilters($this->publishedQuery(), $params)->count();
    }

    /**
     * @return list<int>
     */
    public function idsAfterCursor(int $cursor, int $limit, array $params = []): array
    {
        $limit = max(1, min(500, $limit));
        $query = $this->applyFilters($this->publishedQuery(), $params)
            ->where('id', '>', max(0, $cursor))
            ->order('id', 'asc')
            ->limit($limit);

        return array_map('intval', $query->column('id'));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function applyFilters(Query $query, array $params): Query
    {
        $tagId = (int) ($params['tag_id'] ?? 0);
        if ($tagId > 0) {
            // 子查询，禁止 column()+whereIn 万级 ID
            $query->whereIn('id', static function ($sub) use ($tagId): void {
                $sub->name('document_tags')->where('tag_id', $tagId)->field('document_id');
            });
        }

        $mode = strtolower(trim((string) ($params['mode'] ?? '')));
        if ($mode === 'time') {
            $since = trim((string) ($params['since'] ?? ''));
            if ($since !== '') {
                $query->where('updated_at', '>=', $since);
            }
        }

        $idFrom = (int) ($params['id_from'] ?? 0);
        $idTo   = (int) ($params['id_to'] ?? 0);
        if ($idFrom > 0 && $idTo > 0) {
            if ($idFrom > $idTo) {
                [$idFrom, $idTo] = [$idTo, $idFrom];
            }
            $query->whereBetween('id', [$idFrom, $idTo]);
        } elseif ($idFrom > 0) {
            $query->where('id', '>=', $idFrom);
        } elseif ($idTo > 0) {
            $query->where('id', '<=', $idTo);
        }

        return $query;
    }
}
