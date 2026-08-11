<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\support\AppTime;

use app\common\model\Document;
use think\facade\Db;

/**
 * 文档保存 / 插件同步后：重建 search_text（含插件块）并同步 Meili
 */
final class DocumentSearchEnrichService
{

    public function __construct(
        private readonly SearchTextExtractor $textExtractor,
        private readonly SearchIndexService $searchIndex,
    ) {
    }

    public function rebuildForDocument(int $documentId): void
    {
        if ($documentId < 1) {
            return;
        }
        $row = $this->documentRow(Document::where('id', $documentId)->whereNull('deleted_at')->find());
        if ($row === null) {
            return;
        }

        $text = $this->textExtractor->forDocument($documentId, $row);
        $prev = (string) ($row['search_text'] ?? '');
        if ($text !== $prev) {
            Document::where('id', $documentId)->update([
                'search_text' => $text,
                'updated_at'  => AppTime::now(),
            ]);
        }

        if ((int) ($row['status'] ?? 0) === 1) {
            $this->searchIndex->syncDocumentById($documentId);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function documentRow(mixed $result): ?array
    {
        return $result instanceof Document ? $result->toArray() : null;
    }
}
