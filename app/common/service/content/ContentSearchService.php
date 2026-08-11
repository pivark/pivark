<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\content;

use app\common\support\QueryLimit;



use app\common\service\front\FrontUrlBuilder;
use app\common\service\tag\TagService;
use app\common\service\document\DocumentFormatService;
use app\common\service\document\DocumentPublicService;
use app\common\service\site\SiteModeService;
use app\common\service\search\SearchConfigService;
use app\common\service\search\SearchDegradedGuard;
use app\common\service\search\SearchDriverFactory;
use app\common\service\search\SearchMemberContext;
use app\common\model\Tag;
use app\common\model\SitePage;
use app\common\support\SiteUrl;
use think\db\Query;


class ContentSearchService
{

    public function __construct(
        private readonly SearchConfigService $searchConfig,
        private readonly SiteModeService $siteMode,
        private readonly SearchDriverFactory $driverFactory,
        private readonly SearchMemberContext $memberContext,
        private readonly TagService $tags,
        private readonly FrontUrlBuilder $frontUrl,
    ) {
    }

    public const MAX_KEYWORD_LEN = 100;

    /**
     * @return mixed
     * @param mixed $keyword
     */
    public function normalizeKeyword(string $keyword): string
    {
        $keyword = trim(preg_replace('/\s+/u', ' ', $keyword) ?? '');
        if ($keyword === '') {
            return '';
        }
        if (mb_strlen($keyword) > self::MAX_KEYWORD_LEN) {
            $keyword = mb_substr($keyword, 0, self::MAX_KEYWORD_LEN);
        }

        return $keyword;
    }

    /**
     * @return mixed
     * @param mixed $keyword
     */
    public function escapeLike(string $keyword): string
    {
        return addcslashes($keyword, '%_\\');
    }

    /**
     * @return mixed
     * @param mixed $keyword
     */
    public function likePattern(string $keyword): string
    {
        $keyword = $this->normalizeKeyword($keyword);
        if ($keyword === '') {
            return '';
        }

        return '%' . $this->escapeLike($keyword) . '%';
    }

    /** 向文档 query 应用关键词条件 */
    public function applyArticleKeyword(Query $query, string $keyword, bool $adminScope = false): void
    {
        $keyword = $this->normalizeKeyword($keyword);
        if ($keyword === '') {
            return;
        }

        if ($adminScope) {
            $pattern = $this->likePattern($keyword);
            if ($pattern !== '') {
                $query->whereLike('title', $pattern);
            }
            return;
        }

        $mode = $this->searchConfig->mode();
        if ($mode === SearchConfigService::MODE_TITLE_EXACT) {
            $query->where('title', $keyword);
            return;
        }

        if ($mode === SearchConfigService::MODE_FUZZY) {
            $pattern = $this->likePattern($keyword);
            if ($pattern !== '') {
                $query->whereLike('title|subtitle|summary|search_text', $pattern);
            }
            return;
        }

        // title_seg：分词匹配
        $tokens = $this->tokenize($keyword);
        if ($tokens === []) {
            return;
        }
        $matchAll = $this->searchConfig->tokenMatch() === SearchConfigService::TOKEN_ALL;
        $query->where(function (Query $sub) use ($tokens, $matchAll): void {
            foreach ($tokens as $i => $token) {
                $pattern = $this->likePattern($token);
                if ($pattern === '') {
                    continue;
                }
                $clause = static function (Query $inner) use ($pattern): void {
                    $inner->whereLike('title', $pattern)
                        ->whereOr('search_text', 'like', $pattern);
                };
                if ($matchAll) {
                    $sub->where($clause);
                } elseif ($i === 0) {
                    $sub->where($clause);
                } else {
                    $sub->whereOr($clause);
                }
            }
        });
    }

    /** @return list<string> */
    public function tokenize(string $keyword): array
    {
        $keyword = $this->normalizeKeyword($keyword);
        if ($keyword === '') {
            return [];
        }
        $parts = preg_split('/[\s,，、]+/u', $keyword) ?: [];
        $out   = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '' && mb_strlen($part) >= 1) {
                $out[] = $part;
            }
        }

        return $out !== [] ? $out : [$keyword];
    }

    /**
     * 前台综合搜索：文档（驱动可 Meili/SQL）+ 标签 + 单页
     *
     * @return array{
     *   keyword:string,
     *   documents:array{list:list<array>,total:int,page:int,limit:int},
     *   tags:list<array<string,mixed>>,
     *   pages:list<array<string,mixed>>
     * }
     * @param mixed $keyword
     * @param mixed $page
     * @param mixed $limit
     */
    /**
     * @param array{exclude_document_ids?:list<int>} $options
     * @return array{
     *   keyword:string,
     *   documents:array{list:list<array>,total:int,page:int,limit:int},
     *   tags:list<array>,
     *   pages:list<array>
     * }
     * @param mixed $keyword
     * @param mixed $page
     * @param mixed $limit
     */
    public function searchPublic(string $keyword, int $page = 1, int $limit = QueryLimit::FRONT_LIST, array $options = []): array
    {
        $keyword = $this->normalizeKeyword($keyword);
        $page    = max(1, $page);
        $limit   = min(max($limit, 1), 50);
        $excludeDocIds = $this->normalizeExcludeDocumentIds($options['exclude_document_ids'] ?? []);
        $cacheKey = $excludeDocIds === []
            ? $keyword
            : $keyword . "\0ex:" . md5(implode(',', $excludeDocIds));

        if ($keyword !== '') {
            $cached = $this->siteMode->getSearchCache($cacheKey, $page, $limit);
            if ($cached !== null) {
                return $cached;
            }
        }

        if ($keyword !== '') {
            $context = $this->memberContext->forPublicSearch();
            $driver  = $this->driverFactory->makeForSearch();
            if ($this->driverFactory->fellBackToSql() && app(SearchDegradedGuard::class)->shouldRejectSqlFallback($keyword)) {
                $articles = ['list' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
            } elseif ($driver->name() !== 'sql') {
                $fetchLimit = $limit;
                if ($excludeDocIds !== []) {
                    $fetchLimit = min(100, $limit + min(50, count($excludeDocIds)));
                }
                $hit = $driver->searchDocumentIds($keyword, $page, $fetchLimit, array_merge($context, [
                    'exclude_document_ids' => $excludeDocIds,
                ]));
                $ids = is_array($hit['ids'] ?? null) ? $hit['ids'] : [];
                $total = (int) ($hit['total'] ?? 0);
                if ($excludeDocIds !== []) {
                    $excludeSet = array_fill_keys($excludeDocIds, true);
                    $ids = array_values(array_filter(
                        array_map('intval', $ids),
                        static fn (int $id): bool => $id > 0 && !isset($excludeSet[$id]),
                    ));
                    $ids = array_slice($ids, 0, $limit);
                }
                $articles = app(DocumentPublicService::class)->listPublicByIds($ids, $total, $page, $limit, $context);
            } else {
                $articles = app(DocumentPublicService::class)->listPublic([
                    'keyword'              => $keyword,
                    'page'                 => $page,
                    'limit'                => $limit,
                    'sort'                 => 'id_desc',
                    'exclude_document_ids' => $excludeDocIds,
                ]);
            }
        } else {
            $articles = app(DocumentPublicService::class)->listPublic([
                'keyword' => '',
                'page'    => $page,
                'limit'   => $limit,
                'sort'    => 'id_desc',
            ]);
        }
        $articles['list'] = app(DocumentFormatService::class)->enrichListForView($articles['list']);

        if ($keyword === '') {
            return [
                'keyword'  => '',
                'documents' => $articles,
                'tags'     => [],
                'pages'    => [],
            ];
        }

        $result = [
            'keyword'  => $keyword,
            'documents' => $articles,
            'tags'     => $this->searchTags($keyword, QueryLimit::SEARCH_AUX_HITS),
            'pages'    => $this->searchPages($keyword, QueryLimit::SEARCH_AUX_HITS),
        ];
        $this->siteMode->setSearchCache($cacheKey, $page, $limit, $result);

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     * @param mixed $keyword
     * @param mixed $limit
     */
    public function searchTags(string $keyword, int $limit = QueryLimit::STATS_TOP_SMALL): array
    {
        $keyword = $this->normalizeKeyword($keyword);
        if ($keyword === '') {
            return [];
        }
        $limit = min(max($limit, 1), 30);
        $pattern = $this->likePattern($keyword);
        $rows  = Tag::where('status', 1)
            ->whereLike('name|slug|seo_title|seo_description', $pattern)
            ->order('use_count', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        $out = [];
        foreach ($rows as $row) {
            $formatted = $this->tags->formatForApi($row);
            $formatted['url'] = SiteUrl::tagFromRow($row);
            $out[] = $formatted;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     * @param mixed $keyword
     * @param mixed $limit
     */
    public function searchPages(string $keyword, int $limit = QueryLimit::STATS_TOP_SMALL): array
    {
        $keyword = $this->normalizeKeyword($keyword);
        if ($keyword === '') {
            return [];
        }
        $limit = min(max($limit, 1), 30);
        $pattern = $this->likePattern($keyword);
        $rows  = SitePage::where('status', 1)
            ->whereLike('title|seo_title|seo_description|path|tpl_name', $pattern)
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $out = [];
        foreach ($rows as $row) {
            $title = (string) ($row['title'] ?? '');
            $out[] = [
                'id'          => (int) ($row['id'] ?? 0),
                'title'       => $title,
                'description' => (string) ($row['seo_description'] ?? ''),
                'url'         => $this->frontUrl->pageFromRow($row),
            ];
        }

        return $out;
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private function normalizeExcludeDocumentIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $raw),
            static fn (int $id): bool => $id > 0,
        )));
    }
}
