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

/** 搜索模式：SQL 与 Meili 对齐 */
final class SearchModeHelper
{

    public function __construct(
        private readonly ContentSearchService $contentSearch,
        private readonly SearchConfigService $searchConfig,
    ) {
    }

    /**
     * @return array{attributesToSearchOn:list<string>,query:string}
     */
    public function meiliSearchParams(string $keyword): array
    {
        $keyword = $this->contentSearch->normalizeKeyword($keyword);
        $mode    = $this->searchConfig->mode();
        if ($mode === SearchConfigService::MODE_TITLE_EXACT) {
            $q = $keyword;
            if ($q !== '' && !str_starts_with($q, '"')) {
                $q = '"' . str_replace('"', '', $q) . '"';
            }

            return ['attributesToSearchOn' => ['title'], 'query' => $q];
        }
        if ($mode === SearchConfigService::MODE_TITLE_SEG) {
            return ['attributesToSearchOn' => ['title', 'search_text'], 'query' => $keyword];
        }

        return [
            'attributesToSearchOn' => ['title', 'subtitle', 'summary', 'search_text'],
            'query'              => $keyword,
        ];
    }
}
