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
use app\common\support\DbTable;
use app\common\support\OpsLog;
use think\db\Query;

/** MySQL FULLTEXT（documents.search_text）台阶检索 */
final class SearchFulltextSupport
{

    public function __construct(
        private readonly SearchConfigService $searchConfig,
    ) {
    }

    /** 破环：ContentSearchService → SearchDriverFactory → SearchDegradedGuard → 本类 */
    private function contentSearch(): ContentSearchService
    {
        return app(ContentSearchService::class);
    }

    private static ?bool $available = null;

    public function useFulltext(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }
        if ($this->searchConfig->mode() !== SearchConfigService::MODE_FUZZY) {
            self::$available = false;

            return false;
        }
        try {
            $table = Document::getTable();
            if (!is_string($table) || $table === '') {
                self::$available = false;

                return false;
            }
            self::$available = DbTable::indexExists($table, 'ft_document_search');
        } catch (\Throwable $e) {
            OpsLog::businessWarning('search_fulltext_index_probe_failed', [
                'msg' => $e->getMessage(),
            ]);
            self::$available = false;
        }

        return self::$available;
    }

    public function applyFulltext(Query $query, string $keyword): void
    {
        $keyword = $this->contentSearch()->normalizeKeyword($keyword);
        if ($keyword === '') {
            return;
        }
        $terms = preg_split('/\s+/u', $keyword) ?: [];
        $parts = [];
        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ($term === '') {
                continue;
            }
            $parts[] = '+' . addcslashes($term, '+-<>()~*"');
        }
        if ($parts === []) {
            return;
        }
        $against = implode(' ', $parts);
        $query->whereRaw(
            'MATCH(search_text) AGAINST (? IN BOOLEAN MODE)',
            [$against]
        );
    }
}
