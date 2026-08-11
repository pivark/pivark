<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\item;

use app\common\model\Item;
use app\common\model\ItemTag;
use app\common\service\product\ProductCenterGateService;
use app\common\service\search\ItemListSearchService;
use app\common\service\site\SiteNavService;
use app\common\service\tag\TagService;
use app\common\support\catalog\CatalogQueryParams;
use app\common\support\DbRead;
use app\common\support\ItemAttrKeyGuard;

/**
 * 前台品项列表（自 ItemService::listPublic 拆出 · L3）。
 */
final class ItemPublicListService
{
    /** @var array<string, array{list:list<array<string,mixed>>,total:int,page:int,limit:int}> */
    private static array $requestCache = [];

    public function __construct(
        private readonly TagService $tagService,
        private readonly ItemAttrValueService $itemAttrValueService,
        private readonly ItemListSearchService $itemListSearchService,
        private readonly ItemPublicViewService $itemPublicViewService,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array{list:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function list(ItemService $items, array $params = []): array
    {
        $page  = max(1, (int) ($params['page'] ?? 1));
        $limit = min(max((int) ($params['limit'] ?? 20), 1), 100);
        // 无 Pro / 展示关闭：公开品项列表空（种子仍在库，授权后即显）
        if (!ProductCenterGateService::publicSurfaceOpen()) {
            return ['list' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
        }

        $cacheKey = hash('sha256', json_encode($params, JSON_UNESCAPED_UNICODE));
        if (isset(self::$requestCache[$cacheKey])) {
            return self::$requestCache[$cacheKey];
        }
        $offset  = max(0, (int) ($params['offset'] ?? 0));
        $tagSlug = trim((string) ($params['tag'] ?? ''));
        $navId   = max(0, (int) ($params['nav_id'] ?? 0));
        $sortKey = strtolower(trim((string) ($params['sort'] ?? '')));
        // cursor 留给 catalog AJAX；经典 ?page=/path页码分页须保留 page，禁止无 cursor 时打回第 1 页

        $hasFilter = false;
        foreach ($params as $paramKey => $paramVal) {
            $key = (string) $paramKey;
            if (str_starts_with($key, 'filter_') && trim((string) $paramVal) !== '') {
                $hasFilter = true;
                break;
            }
        }
        $skipTotal = CatalogQueryParams::truthy($params['skip_total'] ?? false) || $hasFilter;

        $query = DbRead::model(Item::class);
        app(ItemPublicVisibilityService::class)->applyToQuery($query, ItemPublicVisibilityService::CHANNEL_WWW);
        if ($navId > 0) {
            $navIds = app(SiteNavService::class)->contentCategorySelfAndDescendantIds($navId);
            app(SiteNavService::class)->applyPrimaryOrExtraNavFilter($query, $navIds, 'item');
        } elseif ($tagSlug !== '') {
            // Tag 仅聚合筛选（禁 slug→栏目桥）
            $tag = $this->tagService->findBySlug($tagSlug);
            if ($tag === null) {
                $byPath = $this->tagService->findRowByUrlPath($tagSlug);
                $tag = $byPath !== null ? $this->tagService->findBySlug((string) ($byPath['slug'] ?? '')) : null;
            }
            if ($tag === null) {
                return ['list' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
            }
            $tagId = (int) ($tag['id'] ?? 0);
            $tagIds = app(\app\common\service\tag\TagCore::class)->idsWithDescendants([$tagId]);
            $itemIds = ItemTag::whereIn('tag_id', $tagIds !== [] ? $tagIds : [$tagId])->column('item_id');
            if ($itemIds === []) {
                return ['list' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
            }
            $query->whereIn('id', $itemIds);
        }

        if ($this->itemAttrValueService->tableExists()) {
            $this->itemAttrValueService->applyPublicFilters($query, $params);
        } else {
            foreach ($params as $paramKey => $paramVal) {
                $key = (string) $paramKey;
                if (!str_starts_with($key, 'filter_')) {
                    continue;
                }
                $attrKey = substr($key, 7);
                $val     = trim((string) $paramVal);
                if ($attrKey === '' || $val === '' || !ItemAttrKeyGuard::isSafe($attrKey)) {
                    continue;
                }
                $query->whereRaw(
                    "JSON_UNQUOTE(JSON_EXTRACT(`attrs`, '$.\"{$attrKey}\"')) = ?",
                    [$val]
                );
            }
        }

        $keyword = trim((string) ($params['keyword'] ?? $params['q'] ?? ''));
        if ($keyword !== '') {
            $this->itemListSearchService->applyKeywordFilter($query, $keyword, $params);
        }

        $query = $this->applyPublicListSort($query, $sortKey);

        if ($skipTotal) {
            $total = -1;
            $rows  = $offset > 0
                ? $query->limit($offset, $limit)->select()->toArray()
                : $query->page($page, $limit)->select()->toArray();
        } else {
            $total = (int) $query->count();
            $rows  = $offset > 0
                ? $query->limit($offset, $limit)->select()->toArray()
                : $query->page($page, $limit)->select()->toArray();
        }
        $itemIds = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rows);
        $docIds  = array_values(array_unique(array_filter(array_map(
            static fn (array $r): int => (int) ($r['primary_document_id'] ?? 0),
            $rows
        ), static fn (int $id): bool => $id > 0)));
        if ($docIds !== []) {
            $this->tagService->getTagsForDocuments($docIds);
        }
        $tagMap  = $items->tagRowsMapForItems($itemIds);
        $list  = [];
        foreach ($rows as $row) {
            $itemId = (int) ($row['id'] ?? 0);
            $list[] = $this->itemPublicViewService->enrichRow($row, false, $tagMap[$itemId] ?? []);
        }

        $payload = ['list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit];
        self::$requestCache[$cacheKey] = $payload;

        return $payload;
    }

    public function forgetRequestCache(): void
    {
        self::$requestCache = [];
    }

    /**
     * @param \think\db\BaseQuery|\think\Model $query
     * @return \think\db\BaseQuery|\think\Model
     */
    private function applyPublicListSort(mixed $query, string $sortKey): mixed
    {
        return match ($sortKey) {
            'published_at_desc', 'created_at_desc' => $query->order('created_at', 'desc')->order('id', 'desc'),
            'id_desc', 'aid' => $query->order('id', 'desc'),
            'id_asc' => $query->order('id', 'asc'),
            'click_desc', 'hot' => $query->order('sort', 'asc')->order('id', 'desc'),
            default => $query->order('sort', 'asc')->order('id', 'desc'),
        };
    }
}
