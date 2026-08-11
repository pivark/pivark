<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\item;

use app\common\model\Document;
use app\common\model\ItemTag;
use app\common\service\document\DocumentFormatService;
use app\common\service\plugin\extension\PluginOfficialProduct;

/**
 * 后台品项列表行组装（自 ItemService 拆出 · L3）。
 */
final class ItemAdminRowPresenter
{
    public function __construct(
        private readonly ItemVariantService $itemVariantService,
        private readonly ItemPublicUrlService $itemPublicUrlService,
        private readonly ItemPublicViewService $itemPublicViewService,
        private readonly DocumentFormatService $documentFormatService,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function formatRow(ItemService $items, array $row): array
    {
        $flags = is_array($row['flags'] ?? null) ? $row['flags'] : [];
        $itemId = (int) ($row['id'] ?? 0);
        // 列表批量预取优先；单条详情回落查库
        if (array_key_exists('_admin_tag_ids', $row) && is_array($row['_admin_tag_ids'])) {
            $tagIds = array_values(array_map('intval', $row['_admin_tag_ids']));
        } else {
            $tagIds = array_map('intval', ItemTag::where('item_id', $itemId)->column('tag_id') ?: []);
        }
        $primaryDocId = (int) ($row['primary_document_id'] ?? 0);
        // 列表封面真源：主文档 litpic（可预取 _primary_doc_litpic）；无主文档则空
        $coverRaw = '';
        if ($primaryDocId > 0) {
            if (array_key_exists('_primary_doc_litpic', $row)) {
                $coverRaw = trim((string) $row['_primary_doc_litpic']);
            } else {
                $coverRaw = trim((string) (Document::where('id', $primaryDocId)->value('litpic') ?: ''));
            }
        }
        $typeLabels = $items->typeLabels();
        $statusLabels = $items->statusLabels();

        if (array_key_exists('_admin_extra_nav_ids', $row) && is_array($row['_admin_extra_nav_ids'])) {
            $extraNavIds = array_values(array_map('intval', $row['_admin_extra_nav_ids']));
        } else {
            $extraNavIds = app(\app\common\service\site\SiteNavService::class)
                ->listItemExtraNavIds($itemId);
        }
        $variantCount = array_key_exists('_admin_variant_count', $row)
            ? (int) $row['_admin_variant_count']
            : $this->itemVariantService->countByItemId($itemId);

        $litpic = $coverRaw !== ''
            ? $this->itemPublicViewService->resolveCoverUrl($coverRaw, (string) ($row['name'] ?? ''))
            : '';

        // 宿主可注入 litpic（如插件包 icon）；主文档封面优先，空才采纳（array + 左键胜出）
        $platformMeta = $this->platformLicenseListMeta($row);
        if ($litpic === '') {
            $hostCover = trim((string) ($platformMeta['litpic'] ?? $platformMeta['cover_url'] ?? ''));
            if ($hostCover !== '') {
                $litpic = $hostCover;
            }
        }
        unset($platformMeta['litpic'], $platformMeta['cover_url']);

        return [
            'id'                  => $itemId,
            'code'                => (string) ($row['code'] ?? ''),
            'name'                => (string) ($row['name'] ?? ''),
            'slug'                => (string) ($row['slug'] ?? ''),
            'item_type'           => (string) ($row['item_type'] ?? ''),
            'item_type_text'      => $typeLabels[(string) ($row['item_type'] ?? '')] ?? '',
            'status'              => (string) ($row['status'] ?? ''),
            'status_text'         => $statusLabels[(string) ($row['status'] ?? '')] ?? '',
            'sort'                => (int) ($row['sort'] ?? 0),
            'primary_document_id' => $primaryDocId,
            'nav_id'              => (int) ($row['nav_id'] ?? 0),
            'extra_nav_ids'       => $extraNavIds,
            'tag_ids'             => $tagIds,
            'flag_sellable'       => !empty($flags['sellable']) ? 1 : 0,
            'flag_purchasable'    => !empty($flags['purchasable']) ? 1 : 0,
            'flag_manufacturable' => !empty($flags['manufacturable']) ? 1 : 0,
            'flag_web_visible'    => array_key_exists('web_visible', $flags)
                ? (!empty($flags['web_visible']) ? 1 : 0)
                : (!empty($flags['sellable']) ? 1 : 0),
            'flag_market_featured' => !empty($flags['market_featured']) ? 1 : 0,
            // 列表缩略图约定 litpic；cover_url 同值供未 rebuild 的 dist 兼容
            'litpic'              => $litpic,
            'cover_url'           => $litpic,
            'front_url'           => $this->resolveAdminFrontUrl($row),
            'attrs'               => is_array($row['attrs'] ?? null) ? $row['attrs'] : [],
            'variant_count'       => $variantCount,
            'created_at'          => (string) ($row['created_at'] ?? ''),
            'updated_at'          => (string) ($row['updated_at'] ?? ''),
        ] + app(ItemPublicVisibilityService::class)->adminVisibilityMeta($row)
            + $platformMeta;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function resolveAdminFrontUrl(array $row): string
    {
        $slug = trim((string) ($row['slug'] ?? ''));

        // 官方/插件品项：优先宿主声明的市场详情（须带 row，模板≠插件）
        if ($slug !== '') {
            $redirect = PluginOfficialProduct::dispatch('product_item_redirect', [
                'slug' => $slug,
                'row'  => $row,
            ], null);
            if (is_string($redirect) && $redirect !== '') {
                return $redirect;
            }

            // 普通品项：canonical 用 /items/{slug}，勿链到主文档栏目 URL
            $itemUrl = $this->itemPublicUrlService->productItemPage($slug);
            if ($itemUrl !== '') {
                return $itemUrl;
            }
        }

        $docId = (int) ($row['primary_document_id'] ?? 0);
        if ($docId > 0) {
            $url = $this->resolvePublicDetailUrl($docId);
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function platformLicenseListMeta(array $row): array
    {
        $meta = PluginOfficialProduct::dispatch('item_row_list_meta', ['row' => $row], null);
        if (is_array($meta) && $meta !== []) {
            return $meta;
        }

        $attrs = is_array($row['attrs'] ?? null) ? $row['attrs'] : [];
        $catalog = is_array($attrs['official_catalog'] ?? null) ? $attrs['official_catalog'] : [];
        if ($catalog === []) {
            return [];
        }

        return [
            'official_catalog' => $catalog,
            'catalog_badge'    => '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{item_origin:string,item_origin_label:string}
     */
    public function itemOriginMeta(array $row): array
    {
        $meta = PluginOfficialProduct::dispatch('item_origin_meta', ['row' => $row], null);
        if (is_array($meta)) {
            return $meta;
        }

        return ['item_origin' => 'manual', 'item_origin_label' => '手动'];
    }

    /**
     * @param list<int> $itemIds
     * @return array<int, array<string, mixed>>
     */
    public function marketplaceListingOverlayByItemIds(array $itemIds): array
    {
        $overlay = PluginOfficialProduct::dispatch('item_list_overlay', ['item_ids' => $itemIds], null);

        return is_array($overlay) ? $overlay : [];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{id:int,name:string,slug:string}>
     */
    public function formatAdminTagRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $out[] = [
                'id'   => $id,
                'name' => (string) ($row['name'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
            ];
        }

        return $out;
    }

    private function resolvePublicDetailUrl(int $documentId): string
    {
        if ($documentId < 1) {
            return '';
        }
        $doc = Document::where('id', $documentId)->where('status', 1)->find()?->toArray();

        return is_array($doc) ? $this->documentFormatService->buildPublicDocumentUrl($doc) : '';
    }
}
