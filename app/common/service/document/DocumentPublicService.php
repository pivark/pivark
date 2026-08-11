<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\document;

use app\common\support\QueryLimit;

use app\common\service\theme\ThemeTemplateCatalogService;
use think\facade\Request;

use app\common\model\Document;
use app\common\support\DbRead;
use app\common\model\DocumentTag;
use app\common\model\Tag;
use app\common\support\CursorPaginator;
use app\common\support\OpsLog;
use app\common\support\DbTable;
use app\common\support\SiteUrl;
use app\common\support\AppTime;
use app\common\service\site\SiteNavService;
use app\common\service\plugin\extension\DocumentAddonBridgeAccess;
use think\db\Query;

final class DocumentPublicService
{

    public function __construct(
        private readonly DocumentPublicSearchDeps $search,
        private readonly DocumentPublicPresentationDeps $view,
    ) {
    }

    /** @var array<string, array{list:list<array>,total:int,page:int,limit:int}> */
    private static array $listPublicRequestCache = [];

    /**
     * @param array<string, mixed> $params page, limit, tags, keyword, sort
     * @return array{list:list<array>,total:int,page:int,limit:int,next_cursor_id?:int}
     */
    public function listPublic(array $params): array
    {
        $cached = self::$listPublicRequestCache[self::listPublicCacheKey($params)] ?? null;
        if (is_array($cached)) {
            return $cached;
        }

        $searchCtx = $this->search->memberContext->forPublicSearch();
        $isGuest   = !empty($searchCtx['is_guest']);

        // skip_total / with_total=false：标签块首页模块不需要精确总数（TemplateTagdocumentsBatch 已传 with_total=false）
        // 须在物化读之前算好：skip_total 与精确 total 分键，禁止 -1 毒化搜索/分页
        $skipTotal = \app\common\support\catalog\CatalogQueryParams::truthy($params['skip_total'] ?? false);
        if (!$skipTotal && array_key_exists('with_total', $params)) {
            $skipTotal = !\app\common\support\catalog\CatalogQueryParams::truthy($params['with_total']);
        }

        $matParams = $params;
        if ($isGuest) {
            $matParams['_materialize_guest'] = 1;
            $matParams['_skip_total'] = $skipTotal ? 1 : 0;
        }
        $materialized = app(DocumentListMaterializedService::class);
        if ($isGuest) {
            $hit = $materialized->get($matParams);
            if (is_array($hit)) {
                $hitTotal = (int) ($hit['total'] ?? -1);
                // 旧缓存未分键时：total=-1 不得喂给需要总数的请求
                if ($skipTotal || $hitTotal >= 0) {
                    /** @var array{list: list<array>, total: int, page: int, limit: int, next_cursor_id?: int} $shaped */
                    $shaped = [
                        'list'  => array_values(is_array($hit['list'] ?? null) ? $hit['list'] : []),
                        'total' => $hitTotal,
                        'page'  => max(1, (int) ($hit['page'] ?? 1)),
                        'limit' => max(1, (int) ($hit['limit'] ?? 15)),
                    ];
                    if (isset($hit['next_cursor_id'])) {
                        $shaped['next_cursor_id'] = (int) $hit['next_cursor_id'];
                    }
                    self::$listPublicRequestCache[self::listPublicCacheKey($params)] = $shaped;

                    return $shaped;
                }
            }
        }

        $page     = max(1, (int) ($params['page'] ?? 1));
        $limit    = min(max((int) ($params['limit'] ?? 15), 1), 100);
        $offset   = max(0, (int) ($params['offset'] ?? 0));
        $cursorId = max(0, (int) ($params['cursor_id'] ?? 0));
        $keyword = trim((string) ($params['keyword'] ?? ''));
        $tagSlugs = array_filter(array_map('trim', explode(',', (string) ($params['tags'] ?? ''))));
        $navId   = max(0, (int) ($params['nav_id'] ?? 0));
        $sort    = (string) ($params['sort'] ?? 'id_desc');
        $attrFlag = trim((string) ($params['attr'] ?? ''));
        $noAttrFlag = trim((string) ($params['noattr'] ?? ''));
        $hasSlot = trim((string) ($params['has'] ?? ''));
        $noHasSlot = trim((string) ($params['nohas'] ?? ''));
        $tagFilter = $this->normalizeTagFilterParams($params, $tagSlugs);
        $hasTagScope = $navId < 1 && (
            $tagFilter['include_ids'] !== []
            || $tagFilter['exclude_ids'] !== []
            || $tagFilter['group_ids'] !== []
            || $tagFilter['exclude_group_ids'] !== []
            || $tagFilter['match'] === 'any'
        );
        $allowCursor = $keyword === '' && !$hasTagScope && $navId < 1 && $attrFlag === '' && $noAttrFlag === ''
            && $hasSlot === '' && $noHasSlot === ''
            && $offset === 0
            && in_array($sort, ['', 'id_desc'], true);

        $readConn = DbRead::connectionName();
        $query    = $readConn !== null
            ? Document::connect($readConn)->where('status', 1)->whereNull('deleted_at')
            : Document::where('status', 1)->whereNull('deleted_at');

        $this->search->memberContext->applyReadPermToQuery($query, $searchCtx);

        if ($keyword !== '') {
            if ($this->search->fulltext->useFulltext()) {
                $this->search->fulltext->applyFulltext($query, $keyword);
            } else {
                $this->search->contentSearch->applyArticleKeyword($query, $keyword, false);
            }
            $this->search->memberContext->applyBlockedTagsToQuery($query, $searchCtx);
        }

        $this->view->attrFlags->applyFlagFilter($query, $attrFlag);
        $this->view->attrFlags->applyExcludeFlagFilter($query, $noAttrFlag);
        $this->applyAddonAvailabilityFilters($query, $hasSlot, $noHasSlot);

        $publishedSince = AppTime::publishedCutoff(
            (string) ($params['period'] ?? ''),
            (string) ($params['since'] ?? $params['published_since'] ?? '')
        );
        $publishedUntil = AppTime::publishedUntil(
            (string) ($params['until'] ?? $params['published_until'] ?? '')
        );
        if ($publishedSince !== null) {
            $query->where('published_at', '>=', $publishedSince);
        }
        if ($publishedUntil !== null) {
            $query->where('published_at', '<=', $publishedUntil);
        }

        // 主栏目或附加栏目命中；归属只认栏目，不与 tag 混读
        if ($navId > 0) {
            $navIds = app(SiteNavService::class)->contentCategorySelfAndDescendantIds($navId);
            app(SiteNavService::class)->applyPrimaryOrExtraNavFilter($query, $navIds, 'document');
        } else {
            $this->applyTagDocumentFilters($query, $tagFilter);
        }

        $excludeDocumentIds = $this->normalizeIdList($params['exclude_document_ids'] ?? []);
        if ($excludeDocumentIds !== []) {
            $query->whereNotIn('id', $excludeDocumentIds);
        }

        $nextCursorId = 0;
        if ($allowCursor) {
            self::applySort($query, $sort);
            // skipTotal 时游标跳过 COUNT（total=-1）；需要总数时走传统分页
            $pageResult   = CursorPaginator::paginateById($query, $limit, $page, $cursorId, 'id', $skipTotal);
            $rows         = $pageResult['rows'];
            $total        = $skipTotal ? -1 : max(0, (int) $pageResult['total']);
            $nextCursorId = (int) $pageResult['next_cursor_id'];
        } else {
            // COUNT 必须在 ORDER BY 之前，避免大库无用排序拖垮 count
            $total = -1;
            if (!$skipTotal) {
                $total = (int) $query->count();
            }
            self::applySort($query, $sort);
            // 列表勿 SELECT *（content 大字段会拖慢迁站库）
            $query->field(
                'id,title,summary,litpic,status,click,published_at,created_at,'
                . 'html_name,url_path,attr_flags,read_perm,read_level_id,extra_json'
            );
            if ($offset > 0) {
                $rows = self::collectionToList($query->limit($offset, $limit)->select());
            } else {
                $rows = self::collectionToList($query->page($page, $limit)->select());
            }
        }
        $ids   = array_map(static fn ($r) => (int) $r['id'], $rows);
        $tagsMap = $this->view->tags->getTagsForDocuments($ids);
        $list  = [];
        foreach ($rows as $row) {
            $list[] = $this->view->format->formatForApi($row, false, $tagsMap[(int) $row['id']] ?? null);
        }

        $payload = ['list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit];
        if ($allowCursor && $nextCursorId > 0) {
            $payload['next_cursor_id'] = $nextCursorId;
        }
        self::$listPublicRequestCache[self::listPublicCacheKey($params)] = $payload;
        if ($isGuest) {
            $materialized->set($matParams, $payload);
        }

        return $payload;
    }

    /**
     * @param list<int> $ids
     * @param array<string, mixed> $searchContext
     * @return array{list:list<array>,total:int,page:int,limit:int}
     */
    public function listPublicByIds(array $ids, int $total, int $page, int $limit, array $searchContext = []): array
    {
        $page  = max(1, $page);
        $limit = min(max($limit, 1), 100);
        $ids   = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return ['list' => [], 'total' => max(0, $total), 'page' => $page, 'limit' => $limit];
        }

        $rows = self::collectionToList(
            DbRead::model(Document::class)
                ->whereIn('id', $ids)
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->select()
        );
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        if ($searchContext === []) {
            $searchContext = $this->search->memberContext->forPublicSearch();
        }
        $blocked = $searchContext['blocked_tag_ids'] ?? $this->search->searchConfig->blockedTagIds();
        $blockedDocSet = [];
        if (is_array($blocked) && $blocked !== []) {
            $blockedDocIds = DocumentTag::whereIn('tag_id', $blocked)->column('document_id');
            foreach ($blockedDocIds as $docId) {
                $blockedDocSet[(int) $docId] = true;
            }
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (!isset($byId[$id]) || isset($blockedDocSet[$id])) {
                continue;
            }
            $row = $byId[$id];
            if (!$this->view->frontAuth->canReadDocument($row)) {
                continue;
            }
            $ordered[] = $row;
        }

        $docIds  = array_map(static fn (array $r): int => (int) $r['id'], $ordered);
        $tagsMap = $this->view->tags->getTagsForDocuments($docIds);
        $list    = [];
        foreach ($ordered as $row) {
            $list[] = $this->view->format->formatForApi($row, false, $tagsMap[(int) $row['id']] ?? null);
        }

        return ['list' => $list, 'total' => max(0, $total), 'page' => $page, 'limit' => $limit];
    }

    /** @return array<string, mixed>|null */
    public function getPublicDetail(int $id): ?array
    {
        $loggedIn = $this->view->frontAuth->isLoggedIn();
        $view = $this->getPublicViewData($id, $loggedIn, false);
        if ($view === null) {
            return null;
        }

        return $this->view->format->formatForApi($view, true);
    }

    /** @return array<string, mixed>|null */
    public function getPublicDetailBySlug(string $slug): ?array
    {
        $id = $this->resolvePublicArticleKey($slug);

        return $id > 0 ? $this->getPublicDetail($id) : null;
    }

    public function resolvePublicDocumentId(string $key): int
    {
        $key = trim($key);
        if ($key === '') {
            return 0;
        }
        // 纯数字也可能是 html_name（迁移稿常为易优 aid），须先按 html_name 查，再回退主键 id
        $id = (int) (DbRead::model(Document::class)->where('html_name', $key)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->value('id') ?? 0);
        if ($id > 0) {
            return $id;
        }
        if (!ctype_digit($key)) {
            return 0;
        }
        $byId = (int) $key;
        if ($byId < 1) {
            return 0;
        }

        return (int) (DbRead::model(Document::class)->where('id', $byId)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->value('id') ?? 0);
    }

    public function resolvePublicArticleByUrlPath(string $path): int
    {
        $path = $this->view->urlPath->normalize($path);
        if ($path === '') {
            return 0;
        }
        $id = (int) (DbRead::model(Document::class)->where('url_path', $path)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->value('id') ?? 0);

        return $id > 0 ? $id : 0;
    }

    public function resolvePublicArticleKey(string $key): int
    {
        $key = trim($key);
        if ($key === '') {
            return 0;
        }

        // 频道下第二段多为 html_name（可为纯数字 aid），优先走 html_name，避免先全表扫空 url_path
        if (!str_contains($key, '/')) {
            $byName = $this->resolvePublicDocumentId($key);
            if ($byName > 0) {
                return $byName;
            }
        }

        $byPath = $this->resolvePublicArticleByUrlPath($key);
        if ($byPath > 0) {
            return $byPath;
        }

        return $this->resolvePublicDocumentId($key);
    }

    /**
     * @param bool $renderAsGuest 静态 HTML 等场景：按游客权限渲染，忽略后台会话
     * @return array<string, mixed>|null
     */
    /**
     * 品项绑定的主文档正文：允许软删/下架（导入假重复清理后仍作 /items 详情来源）。
     * 不走公开列表条件，不递增 click。
     *
     * @return array<string, mixed>|null
     */
    public function getBoundPrimaryDocumentData(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = self::modelToRow(DbRead::model(Document::class)->where('id', $id)->find());
        if (!$row) {
            return null;
        }
        $tags = $this->view->tags->getTagsForDocument($id);
        /** @var list<array<string, mixed>> $tagList */
        $tagList = array_values($tags);
        $contentPc = (string) ($row['content'] ?? '');
        $contentMobile = trim((string) ($row['content_mobile'] ?? ''));
        $useMobile = self::preferMobileContent() && $contentMobile !== '';
        $body = $useMobile ? $contentMobile : $contentPc;
        if ($body !== '') {
            $body = $this->view->editor->processForDisplay($body, $tagList);
        }
        $out = array_merge($row, [
            'id'      => (int) $row['id'],
            'content' => $body,
            'summary' => (string) ($row['summary'] ?? ''),
            'tags'    => $tags,
        ]);
        $out = $this->view->format->applyExtraFieldsToRow($out);
        $this->view->format->applyListMediaFields($out);

        return $out;
    }

    public function getPublicViewData(int $id, bool $memberLoggedIn = false, bool $incrementClick = true, bool $renderAsGuest = false): ?array
    {
        $row = self::modelToRow(
            DbRead::model(Document::class)
                ->where('id', $id)
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->find()
        );
        if (!$row) {
            return null;
        }

        if ($incrementClick) {
            register_shutdown_function(static function () use ($id): void {
                try {
                    Document::where('id', $id)->inc('click')->update();
                } catch (\Throwable $e) {
                    OpsLog::businessWarning('document_click_increment_failed', [
                        'id'  => $id,
                        'msg' => $e->getMessage(),
                    ]);
                }
            });
        }

        $canRead = $renderAsGuest
            ? $this->view->memberLevels->canReadDocument($row, null)
            : $this->view->frontAuth->canReadDocument($row);
        $loginRequired = (int) ($row['read_perm'] ?? 0) === 1 && !$canRead;
        $readLevelId   = (int) ($row['read_level_id'] ?? 0);
        $contentPc     = (string) ($row['content'] ?? '');
        $contentMobile   = trim((string) ($row['content_mobile'] ?? ''));
        $useMobile       = self::preferMobileContent() && $contentMobile !== '';
        $body            = $useMobile ? $contentMobile : $contentPc;
        $summary         = (string) ($row['summary'] ?? '');
        if ($loginRequired) {
            $body = '';
            if ($summary !== '' && mb_strlen($summary) > 160) {
                $summary = mb_substr($summary, 0, 160) . '…';
            }
        }

        $tags = $this->view->tags->getTagsForDocument($id);
        /** @var list<array<string, mixed>> $tagList */
        $tagList = array_values($tags);
        $publishedAt = $row['published_at'] !== '' && $row['published_at'] !== null
            ? (string) $row['published_at']
            : (string) ($row['created_at'] ?? '');

        $contentPage     = 1;
        $contentPageTotal = 1;
        if (!$loginRequired && $body !== '') {
            $body = $this->view->editor->processForDisplay($body, $tagList);
            $cpage = max(1, (int) (\think\facade\Request::get('cpage', 1)));
            $pageData = $this->view->editor->paginateForDisplay($body, $cpage);
            $pageContent = $pageData['content'];
            $body = (string) $pageContent;
            $contentPage        = (int) $pageData['page'];
            $contentPageTotal   = (int) $pageData['total'];
        }

        $permCta = $this->view->memberUx->documentPermissionCta($row, $memberLoggedIn);

        $out = array_merge($row, [
            'id'               => (int) $row['id'],
            'content'          => $body,
            'content_page'     => $contentPage,
            'content_page_total' => $contentPageTotal,
            'summary'          => $summary,
            'content_pc'       => $contentPc,
            'content_mobile'   => $contentMobile,
            'content_is_mobile'=> $useMobile,
            'login_required'   => $loginRequired,
            'read_level_name'  => $readLevelId > 0 ? $this->view->memberLevels->getName($readLevelId) : '',
            'perm_hint'        => $permCta['hint'],
            'perm_cta_url'     => $permCta['cta_url'],
            'perm_cta_label'   => $permCta['cta_label'],
            'tags'             => $tags,
            'url'              => SiteUrl::documentFromRow($row, $tags),
            'published_at'     => $publishedAt,
        ]);
        $out = $this->view->format->applyExtraFieldsToRow($out);
        $this->view->format->applyListMediaFields($out);

        return $out;
    }

    public function publicTemplateBasename(string $tplFile): string
    {
        $tplFile = basename(str_replace(['\\', "\0"], '', trim($tplFile)));
        if ($tplFile === '') {
            return ThemeTemplateCatalogService::TPL_VIEW_DOCUMENT;
        }
        $bare = $this->view->themeCatalog->toCanonicalBasename($tplFile);
        if ($bare !== '' && (str_starts_with($bare, 'view_') || preg_match('/^article[a-z0-9_\-]*$/', $bare))) {
            return $bare;
        }

        return ThemeTemplateCatalogService::TPL_VIEW_DOCUMENT;
    }

    /**
     * Tag 侧栏已退役；栏目侧栏请用 `{pv:nav}` / site_nav。
     * 保留方法签名；恒返回空列表。
     *
     * @return list<array<string, mixed>>
     */
    public function buildTagsNav(string $activeSlug = '', int $limit = QueryLimit::NAV_TAGS): array
    {
        unset($activeSlug, $limit);

        return [];
    }

    /**
     * @return array{prev: ?array<string, mixed>, next: ?array<string, mixed>}
     */
    public function getAdjacentPublic(int $id): array
    {
        $prev = self::modelToRow(
            Document::where('status', 1)->whereNull('deleted_at')
                ->where('id', '<', $id)->order('id', 'desc')->find()
        );
        $next = self::modelToRow(
            Document::where('status', 1)->whereNull('deleted_at')
                ->where('id', '>', $id)->order('id', 'asc')->find()
        );

        return ['prev' => $prev, 'next' => $next];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRelatedPublic(int $id, int $limit = QueryLimit::RELATED_DOCS): array
    {
        if ($id > 0
            && class_exists(\app\common\service\product\DocumentRelatedRefService::class)
            && \app\common\service\product\DocumentRelatedRefService::isAvailable()) {
            $manual = \app\common\service\product\DocumentRelatedRefService::listPublicForDocument($id, $limit);
            if ($manual !== []) {
                return $manual;
            }
        }

        $limit = max(1, min(50, $limit));
        $tagIds = DocumentTag::where('document_id', $id)->column('tag_id');
        if ($tagIds === []) {
            return [];
        }
        // 禁止 column() 拉全栏目数万 id 再 whereIn（大站内容页会卡死）
        $rows = self::collectionToList(
            Document::alias('d')
                ->join('document_tags dt', 'dt.document_id = d.id')
                ->whereIn('dt.tag_id', $tagIds)
                ->where('d.id', '<>', $id)
                ->where('d.status', 1)
                ->whereNull('d.deleted_at')
                ->field('d.id,d.title,d.html_name,d.url_path')
                ->group('d.id')
                ->order('d.id', 'desc')
                ->limit($limit)
                ->select()
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'    => (int) $row['id'],
                'title' => $row['title'],
                'url'   => SiteUrl::documentFromRow($row),
            ];
        }

        return $out;
    }

    private function applySort(Query $query, string $sort): void
    {
        switch ($sort) {
            case 'id_asc':
            case 'aid_asc':
                $query->order('id', 'asc');
                break;
            case 'click_desc':
                $query->order('click', 'desc');
                break;
            case 'created_at_desc':
                $query->order('created_at', 'desc');
                break;
            case 'published_at_desc':
                $query->order('published_at', 'desc');
                break;
            case 'favorite_desc':
            case 'collect_desc':
                if ($this->view->favorites->enabled()) {
                    $col = $sort === 'collect_desc' ? 'collect_count' : 'like_count';
                    if (!in_array($col, ['like_count', 'collect_count'], true)) {
                        $query->order('id', 'desc');
                        break;
                    }
                    $table = DbTable::name('favorite_stats');
                    $query->orderRaw(
                        'IFNULL((SELECT fs.' . $col . ' FROM ' . $table
                        . ' fs WHERE fs.document_id = documents.id LIMIT 1), 0) DESC'
                    )->order('documents.id', 'desc');
                } else {
                    $query->order('id', 'desc');
                }
                break;
            default:
                $query->order('id', 'desc');
        }
    }

    public function applySortToQuery(Query $query, string $sort): void
    {
        self::applySort($query, $sort);
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>         $tagSlugs
     * @return array{match:string,include_ids:list<int>,exclude_ids:list<int>,group_ids:list<int>,exclude_group_ids:list<int>}
     */
    private function normalizeTagFilterParams(array $params, array $tagSlugs): array
    {
        $match = strtolower(trim((string) ($params['tag_match'] ?? 'all')));
        $match = in_array($match, ['any', 'or'], true) ? 'any' : 'all';

        $includeIds = $this->normalizeIdList($params['include_tag_ids'] ?? []);
        if ($includeIds === [] && $tagSlugs !== []) {
            $includeIds = $this->tagIdsFromSlugs($tagSlugs);
        }

        // 频道列表默认含下级：单 Tag 筛选时扩子孙，OR 匹配
        $expandDesc = $params['include_descendants'] ?? null;
        if ($expandDesc === null) {
            $expandDesc = count($includeIds) === 1 && empty($params['include_tag_ids']);
        }
        $expandDesc = $expandDesc === true
            || $expandDesc === 1
            || $expandDesc === '1'
            || $expandDesc === 'true'
            || $expandDesc === 'yes';
        if ($expandDesc && $includeIds !== []) {
            $includeIds = app(\app\common\service\tag\TagCore::class)->idsWithDescendants($includeIds);
            $match      = 'any';
        }

        return [
            'match'              => $match,
            'include_ids'        => $includeIds,
            'exclude_ids'        => $this->normalizeIdList($params['exclude_tag_ids'] ?? []),
            'group_ids'          => $this->normalizeIdList($params['tag_group_ids'] ?? []),
            'exclude_group_ids'  => $this->normalizeIdList($params['exclude_tag_group_ids'] ?? []),
        ];
    }

    /**
     * @param array{match:string,include_ids:list<int>,exclude_ids:list<int>,group_ids:list<int>,exclude_group_ids:list<int>} $filter
     */
    private function applyTagDocumentFilters(Query $query, array $filter): void
    {
        if ($filter['group_ids'] !== []) {
            $tagIdsInGroup = $this->activeTagIdsInGroups($filter['group_ids']);
            if ($tagIdsInGroup === []) {
                $query->where('id', 0);
            } else {
                $this->applyDocumentIdsByTagIdsAny($query, $tagIdsInGroup);
            }
        }

        if ($filter['include_ids'] !== []) {
            if ($filter['match'] === 'any') {
                $this->applyDocumentIdsByTagIdsAny($query, $filter['include_ids']);
            } else {
                $this->applyDocumentIdsByTagIdsAll($query, $filter['include_ids']);
            }
        }

        $excludeIds = $filter['exclude_ids'];
        if ($filter['exclude_group_ids'] !== []) {
            $excludeIds = array_values(array_unique(array_merge(
                $excludeIds,
                $this->activeTagIdsInGroups($filter['exclude_group_ids'])
            )));
        }
        if ($excludeIds !== []) {
            $this->applyDocumentIdsExcludeByTagIds($query, $excludeIds);
        }
    }

    /**
     * Tag 命中文档：子查询，禁止 column()+whereIn 万级 ID（几万稿列表会卡死）。
     *
     * @param list<int> $tagIds
     */
    private function applyDocumentIdsByTagIdsAny(Query $query, array $tagIds): void
    {
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds), static fn (int $id): bool => $id > 0)));
        if ($tagIds === []) {
            $query->where('id', 0);

            return;
        }
        $query->whereIn('id', static function ($sub) use ($tagIds): void {
            $sub->name('document_tags')->whereIn('tag_id', $tagIds)->field('document_id');
        });
    }

    /**
     * @param list<int> $tagIds
     */
    private function applyDocumentIdsByTagIdsAll(Query $query, array $tagIds): void
    {
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds), static fn (int $id): bool => $id > 0)));
        if ($tagIds === []) {
            $query->where('id', 0);

            return;
        }
        $need = count($tagIds);
        $query->whereIn('id', static function ($sub) use ($tagIds, $need): void {
            $sub->name('document_tags')
                ->alias('at')
                ->whereIn('at.tag_id', $tagIds)
                ->group('at.document_id')
                ->having('COUNT(DISTINCT at.tag_id) = ' . $need)
                ->field('at.document_id');
        });
    }

    /**
     * @param list<int> $tagIds
     */
    private function applyDocumentIdsExcludeByTagIds(Query $query, array $tagIds): void
    {
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds), static fn (int $id): bool => $id > 0)));
        if ($tagIds === []) {
            return;
        }
        $query->whereNotIn('id', static function ($sub) use ($tagIds): void {
            $sub->name('document_tags')->whereIn('tag_id', $tagIds)->field('document_id');
        });
    }

    /**
     * @param list<int> $groupIds
     * @return list<int>
     */
    private function activeTagIdsInGroups(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }
        $query = DbRead::model(Tag::class)->where('status', 1)->whereIn('group_id', $groupIds);
        app(\app\common\service\tag\TagCore::class)->applySiteDomainGroupScope($query);

        return array_map('intval', $query->column('id'));
    }

    /**
     * @param list<string> $slugs
     * @return list<int>
     */
    private function tagIdsFromSlugs(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }
        $query = DbRead::model(Tag::class)->where('status', 1)->whereIn('slug', $slugs);
        app(\app\common\service\tag\TagCore::class)->applySiteDomainGroupScope($query);

        return array_map('intval', $query->column('id'));
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

    /**
     * @param array<string, mixed> $params
     * @param array{list:list<array>,total:int,page:int,limit:int} $payload
     */
    public function seedListPublicRequestCache(array $params, array $payload): void
    {
        self::$listPublicRequestCache[self::listPublicCacheKey($params)] = $payload;
    }

    public function forgetListPublicRequestCache(): void
    {
        self::$listPublicRequestCache = [];
    }

    /** @param array<string, mixed> $params */
    private function listPublicCacheKey(array $params): string
    {
        $copy = $params;
        ksort($copy);

        $encoded = json_encode($copy, JSON_UNESCAPED_UNICODE);

        return hash('sha256', $encoded !== false ? $encoded : '');
    }

    private function preferMobileContent(): bool
    {
        $uaHeader = Request::header('user-agent', '');
        $ua = strtolower(is_array($uaHeader) ? '' : $uaHeader);

        return (bool) preg_match('/mobile|android|iphone|ipod|windows phone/i', $ua);
    }

    /**
     * arclist has/nohas：按 document_availability 槽过滤（经 DocumentAddonBridgeAccess，无 weapp 路径字面量）。
     */
    private function applyAddonAvailabilityFilters(Query $query, string $hasSlot, string $noHasSlot): void
    {
        $hasSlot = DocumentAddonBridgeAccess::resolveAvailabilitySlot($hasSlot);
        $noHasSlot = DocumentAddonBridgeAccess::resolveAvailabilitySlot($noHasSlot);
        if ($hasSlot !== '') {
            $filter = DocumentAddonBridgeAccess::availabilityListFilter($hasSlot);
            if ($filter['mode'] === 'none') {
                $query->whereIn('id', [0]);
            } elseif ($filter['mode'] === 'ids') {
                $query->whereIn('id', $filter['ids'] !== [] ? $filter['ids'] : [0]);
            }
            // mode=all：全站槽已开，不过滤
        }
        if ($noHasSlot !== '') {
            $filter = DocumentAddonBridgeAccess::availabilityListFilter($noHasSlot);
            if ($filter['mode'] === 'all') {
                $query->whereIn('id', [0]);
            } elseif ($filter['mode'] === 'ids' && $filter['ids'] !== []) {
                $query->whereNotIn('id', $filter['ids']);
            }
            // mode=none：无挂载，nohas 不过滤
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function modelToRow(mixed $model): ?array
    {
        if (!is_object($model) || !method_exists($model, 'toArray')) {
            return null;
        }
        $row = $model->toArray();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function collectionToList(mixed $collection): array
    {
        if (!is_object($collection) || !method_exists($collection, 'toArray')) {
            return [];
        }
        $rows = $collection->toArray();
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $out[] = $row;
        }

        return $out;
    }
}
