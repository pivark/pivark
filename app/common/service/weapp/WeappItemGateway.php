<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappItemGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\support\ServiceResult;
use app\common\model\Item;
use app\common\model\ItemTag;
use app\common\model\ItemVariant;
use app\common\model\Tag;
use app\common\service\catalog\CatalogQueryService;
use app\common\service\item\ItemAttrValueService;
use app\common\service\item\ItemCatalogPublicService;
use app\common\service\item\ItemFilterFacetService;
use app\common\service\item\ItemPublicViewService;
use app\common\service\item\ItemService;
use app\common\service\item\ItemVariantService;
use app\common\service\product\ProductCenterGateService;
use app\common\service\product\ProductService;
use app\common\support\DbTable;
use app\common\service\product\ProductTabPersistRegistry;
use app\common\service\item\ItemPersistRegistry;
use app\common\service\search\ItemSearchIndexService;

final class WeappItemGateway
{

    public function __construct(
        private readonly ItemService $item,
        private readonly ItemPublicViewService $itemPublicView,
        private readonly ItemCatalogPublicService $itemCatalogPublic,
        private readonly ItemAttrValueService $itemAttrValue,
        private readonly ItemFilterFacetService $itemFilterFacet,
        private readonly ItemSearchIndexService $itemSearchIndex,
        private readonly ItemVariantService $itemVariant,
        private readonly ProductTabPersistRegistry $productTabPersist,
        private readonly ItemPersistRegistry $itemPersist,
        private readonly ProductCenterGateService $productCenterGate,
        private readonly CatalogQueryService $catalogQuery,
    ) {
    }

    public function itemIsActive(int $itemId): bool
    {
        if ($itemId < 1) {
            return false;
        }

        return (bool) Item::where('id', $itemId)->where('status', ItemService::STATUS_ACTIVE)->find();
    }

    /** @return list<array<string, mixed>> */
    public function itemOptionsForDocumentForm(): array
    {
        return $this->item->optionsForDocumentForm();
    }

    /** @param array<string, mixed> $params */
    public function itemListPublic(array $params): array
    {
        return $this->item->listPublic($params);
    }

    /** @return array<string, mixed>|null */
    public function itemFindPublicBySlug(string $slug): ?array
    {
        return $this->item->findPublicBySlug($slug);
    }

    /** @param array<string, mixed> $itemRow */
    public function itemEnrichRow(array $itemRow, bool $detail = false): array
    {
        return $this->itemPublicView->enrichRow($itemRow, $detail);
    }

    /** @param array<string, mixed> $filters @param list<int> $ids */
    public function itemListAdminExportRows(array $filters, array $ids = []): array
    {
        return $this->item->listAdminExportRows($filters, $ids);
    }

    /**
     * @param array<string, mixed> $payload
     * @return ServiceResult
     */
    public function itemSaveAdmin(array $payload): ServiceResult
    {
        return $this->item->saveAdmin($payload);
    }

    /** @return array<string, string> */
    public function itemStatusLabels(): array
    {
        return $this->item->statusLabels();
    }

    /** @return array<string, string> */
    public function itemTypeLabels(): array
    {
        return $this->item->typeLabels();
    }

    public function itemDeleteAdmin(int $id): ServiceResult
    {
        return $this->item->deleteAdmin($id);
    }

    public function itemResolvePublicCoverUrl(string $litpic): string
    {
        return $this->item->resolvePublicCoverUrl($litpic);
    }

    /** @return list<int> */
    public function itemIdsForDocument(int $documentId): array
    {
        return $this->item->itemIdsForDocument($documentId);
    }

    /** @param list<int> $ids @return list<array<string, mixed>> */
    public function itemListPublicByIds(array $ids): array
    {
        return $this->item->listPublicByIds($ids);
    }

    /** @return list<array<string, mixed>> */
    public function itemListRelatedPublic(int $itemId, int $limit = 8): array
    {
        return $this->item->listRelatedPublic($itemId, $limit);
    }

    /** @param array<string, mixed> $raw @return array<string, mixed> */
    public function itemFormatPublicRow(array $raw): array
    {
        return $this->item->formatPublicRow($raw);
    }

    public function itemResolvePublicPageUrl(string $slug): string
    {
        return $this->item->resolvePublicPageUrl($slug);
    }

    public function itemResolvePublicDetailUrl(int $documentId): string
    {
        return $this->item->resolvePublicDetailUrl($documentId);
    }

    /** @param array<string, mixed> $params */
    public function itemCatalogPublic(array $params): array
    {
        return $this->itemCatalogPublic->catalog($params);
    }

    /**
     * 前台筛选项元数据（filterable 参数定义 + facet 计数）。
     *
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function itemCatalogFilterOptions(array $params = []): array
    {
        return $this->itemCatalogPublic->filterOptions($params);
    }

    /**
     * 按参数组 group_key 取 filterable 字段定义（不受 product 展示门禁影响；供市场筛条）。
     *
     * @return list<array{param_key:string,label:string,options:list<string>}>
     */
    public function itemFilterableDefsForGroupKey(string $groupKey): array
    {
        return app(\app\common\service\catalog\CatalogFacetPathService::class)->filterableDefsForGroupKey($groupKey);
    }

    /**
     * 门牌 extra_json.catalog_facet_group → 参数组 group_key（真源 site_nav）
     *
     * @param array<string, mixed> $doorRow
     */
    public function catalogFacetGroupKeyFromDoor(array $doorRow): string
    {
        return app(\app\common\service\catalog\CatalogFacetPathService::class)->groupKeyFromExtra($doorRow);
    }

    /**
     * @param array<string, string> $filters filter_{param_key} => value
     */
    public function catalogFacetBuildPath(string $baseUrlPath, string $groupKey, array $filters): string
    {
        return app(\app\common\service\catalog\CatalogFacetPathService::class)->buildPath($baseUrlPath, $groupKey, $filters);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    public function catalogFacetNormalizeFilters(array $params, string $groupKey): array
    {
        return app(\app\common\service\catalog\CatalogFacetPathService::class)->normalizeFilters($params, $groupKey);
    }

    /**
     * @param array<string, string> $existing
     * @param array<string, mixed>  $meta
     * @return array<string, string>
     */
    public function catalogFacetMergeDerivedFilterAttrs(array $existing, array $meta): array
    {
        return app(\app\common\service\catalog\CatalogFacetPathService::class)->mergeDerivedFilterAttrs($existing, $meta);
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @param array<string, string>      $active
     * @return list<array<string, mixed>>
     */
    public function catalogFacetMatchByFilterAttrs(array $cards, array $active, ?array $eavIdentifierSet = null): array
    {
        return app(\app\common\service\catalog\CatalogFacetPathService::class)
            ->matchByFilterAttrs($cards, $active, $eavIdentifierSet);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<int>|null
     */
    public function itemAttrIdsMatchingFilters(array $params): ?array
    {
        return $this->itemAttrValue->itemIdsMatchingFilters($params);
    }

    /**
     * @param list<array<string, mixed>>              $allCards
     * @param array<string, string>                   $active
     * @param callable(array<string, string>): string $urlForFilters
     * @return list<array<string, mixed>>
     */
    public function catalogFacetBuildFilterBarRows(
        array $allCards,
        array $active,
        string $groupKey,
        callable $urlForFilters
    ): array {
        return app(\app\common\service\catalog\CatalogFacetPathService::class)->buildFilterBarRows(
            $allCards,
            $active,
            $groupKey,
            $urlForFilters
        );
    }

    public function itemAttrTableExists(): bool
    {
        return $this->itemAttrValue->tableExists();
    }

    /** @return list<string> */
    public function itemAttrDistinctValues(string $key): array
    {
        return $this->itemAttrValue->distinctValues($key);
    }

    /** @return list<array{param_key:string,attr_value:string,unit:string}> */
    public function itemAttrValues(int $itemId): array
    {
        return $this->itemAttrValue->valuesForItem($itemId);
    }

    public function itemFilterFacetServiceAvailable(): bool
    {
        return class_exists(ItemFilterFacetService::class);
    }

    /** @param list<string> $paramKeys */
    public function itemFilterFacetRebuildForParamKeys(array $paramKeys): void
    {
        if (!$this->itemFilterFacetServiceAvailable()) {
            return;
        }
        $this->itemFilterFacet->rebuildForParamKeys($paramKeys);
    }

    /** @param array<string, mixed> $activeFilters @return array<string, int> */
    public function itemFilterFacetCountsForParam(string $paramKey, array $activeFilters = []): array
    {
        if (!$this->itemFilterFacetServiceAvailable()) {
            return [];
        }

        return $this->itemFilterFacet->countsForParam($paramKey, $activeFilters);
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    public function itemFilterFacetNormalizeFilterParams(array $params): array
    {
        if (!$this->itemFilterFacetServiceAvailable()) {
            return [];
        }

        return $this->itemFilterFacet->normalizeFilterParams($params);
    }

    public function itemSearchIndexServiceAvailable(): bool
    {
        return class_exists(ItemSearchIndexService::class);
    }

    /** @param array<string, string> $attrFilters @return array<string, mixed> */
    public function itemSearchIndexSearchIds(string $keyword, int $limit = 24, array $attrFilters = []): array
    {
        if (!$this->itemSearchIndexServiceAvailable()) {
            return ['ids' => []];
        }

        return $this->itemSearchIndex->searchIds($keyword, $limit, $attrFilters);
    }

    /**
     * @param list<int> $ids
     * @return list<array{id:int,title:string,summary:string,url:string,type_label:string}>
     */
    public function itemRecallByIds(array $ids, int $limit = 8): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0,
        )));
        $ids = array_slice($ids, 0, max(1, $limit));
        if ($ids === []) {
            return [];
        }

        $sources = [];
        foreach ($this->item->listPublicByIds($ids) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? $row['name'] ?? ''));
            $summary = trim(strip_tags((string) ($row['summary'] ?? $row['excerpt'] ?? '')));
            $sources[] = [
                'id'         => $id,
                'title'      => $title !== '' ? $title : ('产品#' . $id),
                'summary'    => mb_substr($summary, 0, 400),
                'url'        => (string) ($row['url'] ?? ''),
                'type_label' => '指定产品',
            ];
        }

        return $sources;
    }

    public function itemVariantResolveIdForOfferSku(
        int $itemId,
        string $skuCode,
        string $specLabel,
        mixed $specMap,
    ): int {
        return $this->itemVariant->resolveIdForOfferSku($itemId, $skuCode, $specLabel, $specMap);
    }

    public function itemVariantTableExists(): bool
    {
        return $this->itemVariant->tableExists();
    }

    /** @return array<string, mixed>|null */
    public function itemVariantFindDefaultRow(int $itemId): ?array
    {
        if ($itemId < 1 || !$this->itemVariantTableExists()) {
            return null;
        }
        $row = ItemVariant::where('item_id', $itemId)
            ->order('is_default', 'desc')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->find()?->toArray();

        return is_array($row) ? $row : null;
    }

    public function itemVariantSuggestCode(int $itemId, string $specLabel, int $sequence = 1, string $itemCode = ''): string
    {
        return $this->itemVariant->suggestVariantCode($itemId, $specLabel, $sequence, $itemCode);
    }

    /** @param array<string, mixed> $data */
    public function itemVariantSaveAdmin(array $data): ServiceResult
    {
        return $this->itemVariant->saveAdmin($data);
    }

    /** @return array<string, mixed>|null */
    public function itemVariantFindRowByCode(int $itemId, string $variantCode): ?array
    {
        if ($itemId < 1 || trim($variantCode) === '' || !$this->itemVariantTableExists()) {
            return null;
        }
        $row = ItemVariant::where('item_id', $itemId)
            ->where('variant_code', trim($variantCode))
            ->find()?->toArray();

        return is_array($row) ? $row : null;
    }

    public function itemVariantStatusActive(): string
    {
        return ItemVariantService::STATUS_ACTIVE;
    }

    /**
     * 文档产品 Tab 落库后扩展（product_tab.after_persist）
     *
     * @param list<string> $postKeys
     * @param callable(array<string,mixed>): ServiceResult $handler
     */
    public function productTabAfterPersistRegister(
        string $identifier,
        array $postKeys,
        callable $handler,
        int $priority = 100,
    ): void {
        $this->productTabPersist->register($identifier, $postKeys, $handler, $priority);
    }

    /**
     * 文档产品 Tab 落库前校验（product_tab.before_persist）
     *
     * @param list<string> $postKeys
     * @param callable(array<string,mixed>): ServiceResult $handler
     */
    public function productTabBeforePersistRegister(
        string $identifier,
        array $postKeys,
        callable $handler,
        int $priority = 100,
    ): void {
        $this->productTabPersist->registerBeforePersist($identifier, $postKeys, $handler, $priority);
    }

    /**
     * 产品中心品项保存后扩展（item.after_save）
     *
     * @param callable(array<string,mixed>): ServiceResult $handler
     */
    public function itemAfterSaveRegister(
        string $identifier,
        callable $handler,
        int $priority = 100,
    ): void {
        $this->itemPersist->register($identifier, $handler, $priority);
    }

    public function productCenterAllowsFrontBridge(): bool
    {
        return $this->productCenterGate->allowsFrontBridge();
    }

    public function itemTableName(): string
    {
        return (new Item())->getTable();
    }

    public function tagTableName(): string
    {
        return (new Tag())->getTable();
    }

    public function itemTagTableName(): string
    {
        return (new ItemTag())->getTable();
    }

    /** @return list<int> */
    public function itemActiveIds(): array
    {
        $ids = Item::where('status', ItemService::STATUS_ACTIVE)->column('id');

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /**
     * @param list<int> $itemIds
     *
     * @return list<int>
     */
    public function itemActiveIdsIn(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(
            array_map('intval', $itemIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($itemIds === []) {
            return [];
        }
        $ids = Item::where('status', ItemService::STATUS_ACTIVE)->whereIn('id', $itemIds)->column('id');

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /** @return list<int> */
    public function itemIdsByNameOrCodeLike(string $keyword): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }
        $like = '%' . addcslashes($keyword, '%_\\') . '%';
        $ids  = Item::whereLike('name|code', $like)->column('id');

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    public function itemExists(int $itemId): bool
    {
        if ($itemId < 1) {
            return false;
        }

        return (bool) Item::where('id', $itemId)->find();
    }

    public function itemFieldById(int $itemId, string $field): mixed
    {
        if ($itemId < 1 || trim($field) === '') {
            return null;
        }

        return Item::where('id', $itemId)->value($field);
    }

    /** @return array<string, mixed>|null */
    public function itemRowById(int $itemId): ?array
    {
        if ($itemId < 1) {
            return null;
        }
        $row = Item::where('id', $itemId)->find()?->toArray();

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<int> $itemIds
     *
     * @return array<int, string>
     */
    public function itemTypesByIds(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(
            array_map('intval', $itemIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($itemIds === []) {
            return [];
        }
        $out = [];
        foreach (Item::whereIn('id', $itemIds)->field('id,item_type')->select()->toArray() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[(int) ($row['id'] ?? 0)] = (string) ($row['item_type'] ?? ItemService::TYPE_PHYSICAL);
        }

        return $out;
    }

    /**
     * @param list<int> $itemIds
     *
     * @return list<array{item_type:string,cnt:int}>
     */
    public function itemTypeCountsByIds(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(
            array_map('intval', $itemIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($itemIds === []) {
            return [];
        }
        $rows = Item::whereIn('id', $itemIds)
            ->where('status', ItemService::STATUS_ACTIVE)
            ->field('item_type, COUNT(*) AS cnt')
            ->group('item_type')
            ->select()
            ->toArray();

        return is_array($rows) ? $rows : [];
    }

    /** @return list<int> */
    public function itemTagIdsForTagId(int $tagId): array
    {
        if ($tagId < 1) {
            return [];
        }
        $ids = ItemTag::where('tag_id', $tagId)->column('item_id');

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /** @param array<string, mixed> $params @return array{list:list<array<string,mixed>>,total:int,page:int,limit:int} */
    public function catalogQueryEmptyList(array $params): array
    {
        return $this->catalogQuery->emptyList($params);
    }

    public function productOfferPostKeySingle(): string
    {
        return \app\common\service\product\OfferBridgeFacade::POST_KEY_SINGLE;
    }

    public function productOfferPostKeySingleJson(): string
    {
        return \app\common\service\product\OfferBridgeFacade::POST_KEY_SINGLE_JSON;
    }

    public function itemKernelModelExists(): bool
    {
        return DbTable::modelExists(Item::class);
    }

    public function itemVariantKernelModelExists(): bool
    {
        return DbTable::modelExists(ItemVariant::class);
    }

    public function itemExistsByCode(string $code): bool
    {
        return (bool) Item::where('code', $code)->find();
    }

    public function itemFindModelByCode(string $code): ?Item
    {
        $row = Item::where('code', $code)->find();

        return $row instanceof Item ? $row : null;
    }

    public function itemFindModelById(int $itemId): ?Item
    {
        if ($itemId < 1) {
            return null;
        }
        $row = Item::where('id', $itemId)->find();

        return $row instanceof Item ? $row : null;
    }

    public function itemDeleteById(int $itemId): void
    {
        if ($itemId < 1) {
            return;
        }
        Item::where('id', $itemId)->delete();
    }

    public function itemCountByCodeLike(string $prefix): int
    {
        return (int) Item::whereLike('code', $prefix)->count();
    }

    /** @param array<string, mixed> $data */
    public function itemUpdateById(int $itemId, array $data): void
    {
        if ($itemId < 1 || $data === []) {
            return;
        }
        // pv_items 无 summary/description/price 等列；简介在 attrs.official_catalog.summary
        unset($data['summary'], $data['description'], $data['price'], $data['sale_price']);
        if ($data === []) {
            return;
        }
        // attrs 旁路更新也要 ensure 筛字段并同步 EAV（禁止只改 JSON 丢 item_attr_values）
        if (isset($data['attrs']) && is_array($data['attrs'])) {
            $code = '';
            $existing = Item::where('id', $itemId)->field('code')->find();
            if (is_object($existing)) {
                $code = (string) ($existing['code'] ?? '');
            }
            $data['attrs'] = app(\app\common\service\catalog\CatalogFacetPathService::class)
                ->ensurePluginFilterAttrsOnItemAttrs($data['attrs'], $code);
            $data['attrs'] = app(\app\common\service\catalog\CatalogFacetPathService::class)
                ->ensureTemplateFilterAttrsOnItemAttrs($data['attrs'], $code);
        }
        Item::where('id', $itemId)->update($data);
        if (isset($data['attrs']) && is_array($data['attrs'])) {
            $this->itemAttrValue->syncFromAttrs($itemId, $data['attrs']);
            try {
                $this->itemFilterFacet->syncAfterItemChange($itemId);
            } catch (\Throwable) {
                // facet 派生层，主写已成功
            }
        }
    }

    public function itemVariantFindModelByVariantCode(string $variantCode): ?ItemVariant
    {
        $row = ItemVariant::where('variant_code', $variantCode)->find();

        return $row instanceof ItemVariant ? $row : null;
    }

    /** @return list<ItemVariant> */
    public function itemVariantModelsForItemId(int $itemId): array
    {
        if ($itemId < 1) {
            return [];
        }
        $rows = ItemVariant::where('item_id', $itemId)->select();
        $out  = [];
        foreach ($rows as $row) {
            if ($row instanceof ItemVariant) {
                $out[] = $row;
            }
        }

        return $out;
    }

    public function itemVariantDeleteById(int $variantId): void
    {
        if ($variantId < 1) {
            return;
        }
        ItemVariant::where('id', $variantId)->delete();
    }

    public function itemVariantStatusDiscontinued(): string
    {
        return ItemVariantService::STATUS_DISCONTINUED;
    }

    public function productCenterGateAvailable(): bool
    {
        return class_exists(ProductCenterGateService::class);
    }

    public function productCenterAllowsAdmin(): bool
    {
        return $this->productCenterGate->allowsAdmin();
    }

    /**
     * @param list<int> $itemIds
     * @return list<array<string, mixed>>
     */
    public function itemRowsByIdsFields(array $itemIds, string $fields = 'id,attrs'): array
    {
        $itemIds = array_values(array_unique(array_filter(
            array_map('intval', $itemIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($itemIds === []) {
            return [];
        }
        $rows = Item::whereIn('id', $itemIds)->field($fields)->select()->toArray();

        return is_array($rows) ? $rows : [];
    }

    /** @param array<string, mixed> $data */
    public function productSaveParamGroup(array $data): ServiceResult
    {
        return ProductService::saveParamGroup($data);
    }

    /**
     * @param list<int> $groupIds
     */
    public function productSyncDocumentParamGroupRefs(int $documentId, array $groupIds): void
    {
        ProductService::syncDocumentParamGroupRefs($documentId, $groupIds);
    }
}
