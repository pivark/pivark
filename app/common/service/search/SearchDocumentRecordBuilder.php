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
use app\common\model\DocumentTag;
use app\common\service\document\DocumentFormatService;
/** 统一索引字段（Meili / 未来 ES 共用） */
final class SearchDocumentRecordBuilder
{

    public function __construct(
        private readonly SearchTextExtractor $textExtractor,
        private readonly DocumentFormatService $documents,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fromId(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = $this->documentRow(Document::where('id', $id)->whereNull('deleted_at')->find());
        if ($row === null) {
            return null;
        }

        return $this->fromRow($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function documentRow(mixed $found): ?array
    {
        if ($found instanceof Document) {
            return $found->toArray();
        }

        return is_array($found) ? $found : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    public function fromRow(array $row): ?array
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            return null;
        }
        $status = (int) ($row['status'] ?? 0);
        if ($status !== 1) {
            return null;
        }

        $tagIds = DocumentTag::where('document_id', $id)->column('tag_id');
        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));

        $searchText = (string) ($row['search_text'] ?? '');
        if ($searchText === '') {
            $searchText = $this->textExtractor->forDocumentSave($row);
        }

        return [
            'id' => $id,
            'title' => (string) ($row['title'] ?? ''),
            'subtitle' => (string) ($row['subtitle'] ?? ''),
            'summary' => (string) ($row['summary'] ?? ''),
            'search_text' => $searchText,
            'tag_ids' => $tagIds,
            // 真分类挂靠（聚合 Tag 仍在 tag_ids）
            'nav_id' => max(0, (int) ($row['nav_id'] ?? 0)),
            'status' => $status,
            'read_perm' => (int) ($row['read_perm'] ?? 0),
            'read_level_id' => (int) ($row['read_level_id'] ?? 0),
            'published_at' => (int) strtotime((string) ($row['published_at'] ?? $row['created_at'] ?? '')) ?: 0,
            'url' => $this->documents->buildPublicDocumentUrl($row),
        ];
    }
}
