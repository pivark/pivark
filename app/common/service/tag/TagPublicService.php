<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\tag;
use app\common\service\tag\TagSlugIndexService;
use app\common\service\tag\TagCore;
use app\common\support\QueryLimit;

use app\common\service\infra\HotCacheService;
use app\common\service\infra\MetaSqlCacheService;
use app\common\service\infra\UrlPathService;
use app\common\model\TagGroup as TagGroupModel;
use think\facade\Db;
use app\common\model\Document;
use app\common\model\Tag;
use app\common\model\DocumentTag;
use app\common\support\AppTime;
use app\common\support\DbRead;
use app\common\support\DbTable;
use app\common\support\SiteDomainContext;
use app\common\support\SiteUrl;
use think\db\Query;

/** 标签（TAG）实现 */

/** 标签前台读路径 */
class TagPublicService
{

    /** @var array<string, array{list:list<array<string,mixed>>,total:int,page:int,limit:int}> */
    private static array $listPublicQueryRequestCache = [];

    public function __construct(
        private readonly HotCacheService $hotCacheService,
        private readonly TagCore $tagCore,
        private readonly TagSlugIndexService $tagSlugIndexService,
        private readonly UrlPathService $urlPathService,
        private readonly MetaSqlCacheService $metaSqlCacheService,
    ) {
    }

public function listPublic(int $page = 1, int $limit = QueryLimit::PUBLIC_CATALOG_LIST): array
    {
        return $this->listPublicQuery([
            'page'  => $page,
            'limit' => $limit,
            'sort'  => 'hot',
        ]);
    }

    /**
     * 全站标签目录（分页 · 排序 · 筛选 · 周期），供 tagcloud / 无头 CMS 导航。
     *
     * @param array<string, mixed> $params page, limit|row, sort, kind, group_id, keyword, period, since
     * @return array{list:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function listPublicQuery(array $params): array
    {
        $page      = max(1, (int) ($params['page'] ?? 1));
        $limit     = min(max((int) ($params['limit'] ?? $params['row'] ?? QueryLimit::PUBLIC_CATALOG_LIST), 1), 100);
        $sort      = strtolower(trim((string) ($params['sort'] ?? $params['orderby'] ?? 'hot')));
        $kind      = trim((string) ($params['kind'] ?? ''));
        $keyword   = trim((string) ($params['keyword'] ?? ''));
        $groupId   = max(0, (int) ($params['group_id'] ?? 0));
        $groupIds  = $this->normalizeIdList($params['tag_group_ids'] ?? $params['group_ids'] ?? []);
        if ($groupId > 0 && !in_array($groupId, $groupIds, true)) {
            $groupIds[] = $groupId;
        }
        $includeTagIds       = $this->normalizeIdList($params['include_tag_ids'] ?? []);
        $excludeTagIds       = $this->normalizeIdList($params['exclude_tag_ids'] ?? []);
        $excludeGroupIds     = $this->normalizeIdList($params['exclude_tag_group_ids'] ?? $params['exclude_group_ids'] ?? []);
        $parentId            = max(0, (int) ($params['parent_id'] ?? 0));
        $minDocumentCount    = max(0, (int) ($params['min_document_count'] ?? $params['min_docs'] ?? 0));
        if ($minDocumentCount < 1 && in_array(strtolower(trim((string) ($params['has_documents'] ?? ''))), ['1', 'true', 'yes'], true)) {
            $minDocumentCount = 1;
        }
        $period    = trim((string) ($params['period'] ?? ''));
        $since     = trim((string) ($params['since'] ?? ''));
        $cutoff    = AppTime::publishedCutoff($period, $since);

        if ($sort === '' && isset($params['mode'])) {
            $sort = match (strtolower(trim((string) $params['mode']))) {
                'new', 'latest' => 'new',
                'nav', 'navigation' => 'nav',
                'name', 'alpha' => 'name',
                'rand', 'random', 'shuffle' => 'rand',
                default => 'hot',
            };
        }
        if (in_array($sort, ['random', 'shuffle'], true)) {
            $sort = 'rand';
        }
        if ($sort === 'hot' && $cutoff !== null) {
            $sort = 'hot_period';
        }
        $isRandom = $sort === 'rand';

        $cachePayload = [
            $page,
            $limit,
            $sort,
            $kind,
            $keyword,
            $groupIds,
            $includeTagIds,
            $excludeTagIds,
            $excludeGroupIds,
            $parentId,
            $minDocumentCount,
            $period,
            $since,
            SiteDomainContext::tagGroupId(),
        ];
        $cacheTag = 'tag_public_q_' . md5(json_encode($cachePayload, JSON_UNESCAPED_UNICODE) ?: '');

        if (!$isRandom && isset(self::$listPublicQueryRequestCache[$cacheTag])) {
            return self::$listPublicQueryRequestCache[$cacheTag];
        }

        $loader = function () use (
            $page,
            $limit,
            $sort,
            $kind,
            $keyword,
            $groupIds,
            $includeTagIds,
            $excludeTagIds,
            $excludeGroupIds,
            $parentId,
            $minDocumentCount,
            $cutoff
        ): array {
            $query = DbRead::model(Tag::class)->where('status', 1);
            $this->tagCore->applySiteDomainGroupScope($query);

            if ($groupIds !== []) {
                $query->whereIn('group_id', $groupIds);
            }
            if ($includeTagIds !== []) {
                $query->whereIn('id', $includeTagIds);
            }
            if ($excludeTagIds !== []) {
                $query->whereNotIn('id', $excludeTagIds);
            }
            if ($excludeGroupIds !== []) {
                $query->whereNotIn('group_id', $excludeGroupIds);
            }
            if ($parentId > 0) {
                $query->where('parent_id', $parentId);
            }
            if ($minDocumentCount > 0) {
                $this->applyMinDocumentCountFilter($query, $minDocumentCount);
            }
            if ($kind !== '') {
                $query->where('kind', $this->tagCore->normalizeKind($kind));
            }
            if ($keyword !== '') {
                $query->whereLike('name', '%' . $keyword . '%');
            }
            if ($sort === 'hot_period' && $cutoff !== null) {
                $this->applyTagHotPeriodSort($query, $cutoff);
            } elseif ($sort === 'new' && $cutoff !== null) {
                $query->where('created_at', '>=', $cutoff);
                $query->order('created_at', 'desc')->order('id', 'desc');
            } else {
                $this->applyTagCatalogSort($query, $sort);
            }

            $total = (int) $query->count();
            $rows  = $query->page($page, $limit)->select()->toArray();
            $list  = [];
            foreach ($rows as $row) {
                $list[] = $this->tagCore->formatForApi($row);
            }

            return ['list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit];
        };

        // 随机排序不走缓存，保证每次刷新结果不同
        $result = $isRandom
            ? $loader()
            : $this->hotCacheService->remember($cacheTag, $loader);

        if (!$isRandom) {
            self::$listPublicQueryRequestCache[$cacheTag] = $result;
        }

        return $result;
    }

    public function forgetListPublicQueryRequestCache(): void
    {
        self::$listPublicQueryRequestCache = [];
    }

    private function applyTagCatalogSort(Query $query, string $sort): void
    {
        switch ($sort) {
            case 'new':
            case 'created':
            case 'created_at':
            case 'latest':
                $query->order('created_at', 'desc')->order('id', 'desc');
                break;
            case 'name':
            case 'alpha':
                $query->order('name', 'asc')->order('id', 'asc');
                break;
            case 'nav':
            case 'nav_sort':
                $query->order('nav_sort', 'asc')->order('id', 'asc');
                break;
            case 'id':
                $query->order('id', 'desc');
                break;
            case 'rand':
            case 'random':
            case 'shuffle':
                $query->orderRaw('RAND()');
                break;
            case 'hot':
            case 'use_count':
            case 'hot_period':
            default:
                $query->order('use_count', 'desc')->order('id', 'desc');
        }
    }

    private function applyTagHotPeriodSort(Query $query, string $cutoff): void
    {
        $tagsTable = DbTable::name('tags');
        $dtTable   = DbTable::name('document_tags');
        $docTable  = DbTable::name('documents');
        $query->orderRaw(
            '(SELECT COUNT(*) FROM ' . $dtTable . ' dt INNER JOIN ' . $docTable . ' d ON d.id = dt.document_id'
            . ' WHERE dt.tag_id = ' . $tagsTable . '.id AND d.status = 1 AND d.deleted_at IS NULL'
            . ' AND d.published_at >= ?) DESC, use_count DESC, id DESC',
            [$cutoff]
        );
    }

    private function applyMinDocumentCountFilter(Query $query, int $minCount): void
    {
        $tagsTable = DbTable::name('tags');
        $dtTable   = DbTable::name('document_tags');
        $docTable  = DbTable::name('documents');
        $query->whereRaw(
            '(SELECT COUNT(*) FROM ' . $dtTable . ' dt INNER JOIN ' . $docTable . ' d ON d.id = dt.document_id'
            . ' WHERE dt.tag_id = ' . $tagsTable . '.id AND d.status = 1 AND d.deleted_at IS NULL) >= ?',
            [$minCount]
        );
    }

    /**
     * @return list<int>
     */
    private function normalizeIdList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

public function findRowBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }
        $row = $this->tagSlugIndexService->rowBySlug($slug);
        if ($row !== null) {
            return $row;
        }
        $query = DbRead::model(Tag::class)->where('slug', $slug)->where('status', 1);
        $this->tagCore->applySiteDomainGroupScope($query);
        $row = $query->find()?->toArray();

        return $row ?: null;
    }

public function findRowByUrlPath(string $path): ?array
    {
        $path = $this->urlPathService->normalize($path);
        if ($path === '' || $this->urlPathService->isReserved($path)) {
            return null;
        }
        $row = $this->tagSlugIndexService->rowByUrlPath($path);
        if ($row !== null) {
            return $row;
        }
        // 仅认显式 url_path；禁 slug 冒充门牌（分类门牌归 site_nav）
        $row = DbRead::model(Tag::class)->where('url_path', $path)->where('status', 1)->find()?->toArray();

        return $row ?: null;
    }

    public function findRowById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $query = DbRead::model(Tag::class)->where('id', $id)->where('status', 1);
        $this->tagCore->applySiteDomainGroupScope($query);
        $row = $query->find()?->toArray();

        return $row ?: null;
    }

    public function findRowByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $query = DbRead::model(Tag::class)->where('name', $name)->where('status', 1);
        $this->tagCore->applySiteDomainGroupScope($query);
        $row = $query->find()?->toArray();

        return $row ?: null;
    }

public function publicPath(array $row): string
    {
        // 空 url_path = 无公开门牌（仅聚合）；禁回落 slug
        $path = trim((string) ($row['url_path'] ?? ''));

        return $path === '' ? '' : $this->urlPathService->normalize($path);
    }

public function templateUrlVars(): array
    {
        /** @var array<string, string> $out */
        $out = $this->metaSqlCacheService->remember('tag_url_vars', function (): array {
            $vars = [];
            foreach ($this->tagSlugIndexService->slugIdMap() as $slug => $_id) {
                $slug = trim((string) $slug);
                if ($slug === '') {
                    continue;
                }
                $row = $this->tagSlugIndexService->rowBySlug($slug);
                if ($row === null) {
                    continue;
                }
                $key          = 'tag_url_' . str_replace('-', '_', $slug);
                $vars[$key] = SiteUrl::tagFromRow($row);
            }

            return $vars;
        });

        return $out;
    }

public function findBySlug(string $slug): ?array
    {
        $row = $this->findRowBySlug($slug);

        return $row ? $this->tagCore->formatForApi($row) : null;
    }

public function findByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $row = DbRead::model(Tag::class)->where('name', $name)->where('status', 1)->find()?->toArray();
        return $row ? $this->tagCore->formatForApi($row) : null;
    }

public function listNav(int $limit = 20): array
    {
        // Tag 侧栏已退役；栏目树请用 {pv:nav} / site_nav
        unset($limit);

        return [];
    }

    /**
     * 为侧栏树补齐父节点的子孙（Tag 侧栏已退役，恒空）。
     *
     * @param list<array<string, mixed>> $navRows
     * @return list<array<string, mixed>>
     */
    public function expandNavRowsWithDescendants(array $navRows, int $maxDepth = 4): array
    {
        $byId = [];
        foreach ($navRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $byId[$id] = $row;
            }
        }
        if ($byId === []) {
            return [];
        }

        $frontier = array_keys($byId);
        $depth    = 0;
        $maxDepth = max(1, min($maxDepth, 8));
        while ($frontier !== [] && $depth < $maxDepth) {
            $query = DbRead::model(Tag::class)->where('status', 1)
                ->whereIn('parent_id', $frontier)
                ->order('nav_sort', 'asc')
                ->order('id', 'asc');
            $this->tagCore->applySiteDomainGroupScope($query);
            /** @var list<array<string, mixed>> $children */
            $children = $query->limit(QueryLimit::NAV_TAGS * 4)->select()->toArray();
            $next = [];
            foreach ($children as $child) {
                $cid = (int) ($child['id'] ?? 0);
                if ($cid < 1 || isset($byId[$cid])) {
                    continue;
                }
                $byId[$cid] = $child;
                $next[] = $cid;
            }
            $frontier = $next;
            $depth++;
        }

        return array_values($byId);
    }

    public function forgetTemplateUrlVarsCache(): void
    {
        $this->metaSqlCacheService->forget('tag_url_vars');
    }
}