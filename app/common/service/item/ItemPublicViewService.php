<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\item;

use app\common\service\item\ItemService;
use app\common\service\plugin\extension\DocumentAddonBridgeAccess;
use app\common\service\plugin\extension\PluginOfficialProduct;
use app\common\service\plugin\registry\PluginExtensionRegistry;
use app\common\service\product\DocumentProductFacade;
use app\common\service\product\ProductCatalogSidebarService;
use app\common\support\QueryLimit;

use app\common\model\Document;
use app\common\model\Item;
use app\common\model\ItemTag;
use app\common\model\Tag;
use app\common\support\DbRead;
use app\common\support\SiteUrl;

/** 品项前台展示：列表卡片字段、详情页变量、相邻篇 */
final class ItemPublicViewService
{

    public function __construct(
        private readonly ItemPublicViewDocumentDeps $document,
        private readonly ItemPublicViewCatalogDeps $catalog,
    ) {
    }

    private function itemService(): ItemService
    {
        return app(ItemService::class);
    }

    /**
     * @param array<string, mixed> $row items 表行或已格式化行
     * @return array<string, mixed>
     */
    public function enrichRow(array $row, bool $detail = false, array $prefetchedTagRows = []): array
    {
        $itemId = (int) ($row['id'] ?? 0);
        if ($itemId < 1) {
            return $row;
        }

        $slug = (string) ($row['slug'] ?? '');
        $pageUrl = $slug !== '' ? SiteUrl::productItem($slug) : '';
        $docId   = (int) ($row['primary_document_id'] ?? 0);
        $doc     = $docId > 0
            ? ($detail ? $this->loadPrimaryDocument($docId) : $this->loadPrimaryDocumentSummary($docId))
            : null;
        $docUrl  = $doc !== null ? $this->document->documentFormatService->buildPublicDocumentUrl($doc) : '';

        // 封面真源：主文档 litpic（禁止抽正文）
        $coverRaw = $doc !== null ? trim((string) ($doc['litpic'] ?? '')) : '';
        $coverUrl = $this->resolveCoverUrl($coverRaw, (string) ($row['name'] ?? ''));

        $attrs = $row['attrs'] ?? [];
        if (is_string($attrs)) {
            $decoded = json_decode($attrs, true);
            $attrs = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($attrs)) {
            $attrs = [];
        }
        $summary = $this->resolveSummary($row, $doc);
        $typeKey = (string) ($row['item_type'] ?? '');
        $tagRows = $prefetchedTagRows !== [] ? $prefetchedTagRows : $this->tagRowsForItem($itemId);
        $code    = (string) ($row['code'] ?? '');
        $modelDisplay = trim((string) ($attrs['型号'] ?? $attrs['model'] ?? ''));
        if ($modelDisplay === '') {
            $modelDisplay = $code;
        }

        $out = array_merge($row, [
            'id'               => $itemId,
            'code'             => $code,
            // 前台「产品型号」优先 attrs.型号（迁移保留原文），无则回退 code
            'model'            => $modelDisplay,
            'name'             => (string) ($row['name'] ?? ''),
            'slug'             => $slug,
            'item_type'        => $typeKey,
            'item_type_text'   => $this->itemService()->typeLabels()[$typeKey] ?? $typeKey,
            'status'           => (string) ($row['status'] ?? ''),
            'attrs'            => $attrs,
            'param_rows'       => $this->paramRows($attrs, 12),
            'flags'            => is_array($row['flags'] ?? null) ? $row['flags'] : [],
            // 列表缩略图约定：{$field.litpic}；cover_url 仅详情大图同值
            'litpic'           => $coverUrl,
            'cover_url'        => $coverUrl,
            'card_url'         => $pageUrl,
            'page_url'         => $pageUrl,
            'detail_url'       => $docUrl !== '' ? $docUrl : $pageUrl,
            'summary'          => $summary,
            'summary_empty'    => $summary === '' ? 1 : 0,
            'attrs_summary_text' => $this->attrsSummaryText($attrs),
            'tag_labels'         => array_values(array_filter(array_map(
                static fn (array $t): string => (string) ($t['name'] ?? ''),
                $tagRows,
            ))),
            'tag_labels_empty' => $tagRows === [] ? 1 : 0,
        ]);

        $variantService = app(ItemVariantService::class);
        $out['variant_count'] = $variantService->countByItemId($itemId, true);

        if ($detail) {
            $content = $doc !== null ? (string) ($doc['content'] ?? '') : '';
            if (trim(strip_tags($content)) === '') {
                $content = (string) ($attrs['detail_html'] ?? '');
            }
            $out['primary_document_id'] = $docId;
            $out['document_id']         = $docId;
            $out['document_content']    = $content;
            $out['document_content_empty'] = trim(strip_tags($content)) === '' ? 1 : 0;
            $out['specs_html']            = $this->buildSpecsHtml($attrs);
            $out['attrs_summary_html']    = $this->buildAttrsSummaryHtml($attrs);
            $hasGallery = $docId > 0 && $this->documentHasRenderableAddonContent($docId);
            $out['item_has_gallery']      = ($hasGallery && $coverUrl === '') ? 1 : 0;
            // 产品图真源 = document_product_images / litpic；禁止再从正文截图冒充缩略图
            $productImageUrls = $docId > 0
                ? app(\app\common\service\document\DocumentProductImageService::class)
                    ->listOrSynthesizeFromLitpic($docId, $coverRaw)
                : [];
            $out['images'] = $productImageUrls !== []
                ? $this->urlsToImageRows($productImageUrls, (string) ($row['name'] ?? ''))
                : $this->collectProductImages($coverUrl);
            $out['images_empty']          = $out['images'] === [] ? 1 : 0;
            $out['variants']                = $variantService->listPublicByItemId($itemId, true);
            // 主文档扩展字段 → 品项详情（如 brand）；禁止回落到站点名冒充产品品牌
            if ($doc !== null) {
                foreach ($doc as $docKey => $docVal) {
                    if (is_string($docKey) && str_starts_with($docKey, 'field_extra_')) {
                        $out[$docKey] = is_scalar($docVal) ? (string) $docVal : '';
                    }
                }
            }
            $productBrand = trim((string) ($out['field_extra_brand'] ?? ''));
            $out['product_brand'] = $productBrand;
            $out['product_brand_empty'] = $productBrand === '' ? 1 : 0;
        }

        return $out;
    }

    /**
     * @param list<array{url:string,sort?:int,is_cover?:int}> $rows
     * @return list<array{url:string}>
     */
    private function urlsToImageRows(array $rows, string $alt): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $resolved = $this->resolveCoverUrl((string) ($row['url'] ?? ''), $alt);
            if ($resolved === '' || isset($seen[$resolved])) {
                continue;
            }
            $seen[$resolved] = true;
            $out[] = ['url' => $resolved];
        }

        return $out;
    }

    /**
     * 无产品多图行时：仅封面 URL（不扫正文）。
     *
     * @return list<array{url:string}>
     */
    private function collectProductImages(string $coverUrl): array
    {
        $coverUrl = trim($coverUrl);
        if ($coverUrl === '') {
            return [];
        }

        return [['url' => $coverUrl]];
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function listByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $rows = DbRead::model(Item::class)->whereIn('id', $ids);
        app(ItemPublicVisibilityService::class)->applyToQuery($rows, ItemPublicVisibilityService::CHANNEL_WWW);
        $rows = $rows->select()->toArray();
        $docIds = array_values(array_unique(array_filter(array_map(
            static fn (array $r): int => (int) ($r['primary_document_id'] ?? 0),
            $rows
        ), static fn (int $id): bool => $id > 0)));
        if ($docIds !== []) {
            $this->catalog->tagService->getTagsForDocuments($docIds);
        }
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) ($row['id'] ?? 0)] = $row;
        }
        $out = [];
        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                continue;
            }
            $out[] = $this->enrichRow($byId[$id], false);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRelated(int $itemId, int $limit = QueryLimit::RELATED_ITEMS): array
    {
        if ($itemId < 1) {
            return [];
        }
        $tagIds = DbRead::model(ItemTag::class)->where('item_id', $itemId)->column('tag_id');
        $tagIds = array_values(array_unique(array_map('intval', $tagIds ?: [])));
        if ($tagIds === []) {
            return [];
        }
        $relatedIds = DbRead::model(ItemTag::class)->whereIn('tag_id', $tagIds)
            ->where('item_id', '<>', $itemId)
            ->column('item_id');
        $relatedIds = array_values(array_unique(array_filter(array_map('intval', $relatedIds ?: []))));
        if ($relatedIds === []) {
            return [];
        }
        $relatedQuery = DbRead::model(Item::class)->whereIn('id', $relatedIds);
        app(ItemPublicVisibilityService::class)->applyToQuery($relatedQuery, ItemPublicVisibilityService::CHANNEL_WWW);
        $ordered = $relatedQuery
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->limit(min(max($limit, 1), 48))
            ->column('id');

        return $this->listByIds(array_map('intval', $ordered ?: []));
    }

    public function resolveCoverUrl(string $pathOrUrl, string $title = ''): string
    {
        $pathOrUrl = trim($pathOrUrl);
        if ($pathOrUrl === '') {
            return '';
        }
        if (class_exists(\app\common\service\product\ProductCoverService::class)) {
            return \app\common\service\product\ProductCoverService::resolveUrl($pathOrUrl, $title);
        }
        if (preg_match('#^https?://#i', $pathOrUrl)) {
            return $pathOrUrl;
        }

        return $pathOrUrl[0] === '/' ? $pathOrUrl : '/' . $pathOrUrl;
    }


    /**
     * @return array<string, mixed>|null
     */
    public function buildItemPageVars(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }
        $row = DbRead::model(Item::class)->where('slug', $slug)->where('status', ItemService::STATUS_ACTIVE)->find()?->toArray();
        if ($row === null || !app(ItemPublicVisibilityService::class)->isVisibleOnWww($row)) {
            return null;
        }

        $field = app(DocumentProductFacade::class)->publicApiOpen()
            ? app(DocumentProductFacade::class)->enrichPublicRead($row)
            : $this->enrichRow($row, true);
        $sidebar   = app(ProductCatalogSidebarService::class);
        $itemId    = (int) ($field['id'] ?? 0);
        $docId     = (int) ($field['document_id'] ?? 0);
        $catalog   = $sidebar->catalogListUrl();
        $tagRows   = $this->tagRowsForItem($itemId);
        $primaryTag = $tagRows[0] ?? null;
        $listUrl   = $primaryTag !== null
            ? SiteUrl::tagFromRow($primaryTag)
            : $catalog;
        $adjacent  = $this->adjacentInTag($itemId, $primaryTag !== null ? (int) ($primaryTag['id'] ?? 0) : 0);
        $primarySlug = (string) ($primaryTag['slug'] ?? '');
        $listTitle   = is_array($primaryTag) ? trim((string) ($primaryTag['name'] ?? '')) : '';
        if ($listTitle === '' && is_array($hub = DbRead::model(Tag::class)->where('status', 1)->where('slug', 'pv-demo-product')->find()?->toArray())) {
            $listTitle = trim((string) ($hub['name'] ?? ''));
        }
        if ($listTitle === '') {
            $listTitle = '产品展示';
        }

        $seoTitle = trim((string) ($field['name'] ?? ''));
        $seoDesc  = trim((string) ($field['summary'] ?? ''));
        if ($seoDesc === '' && $seoTitle !== '') {
            $seoDesc = $seoTitle;
        }
        $seoModel = trim((string) ($field['model'] ?? ''));
        // 品项 SEO 只拼名称+型号；站点名由 SeoTitleService 按配置追加（禁硬编码客户品牌）
        $seoTitleRaw = $seoTitle;
        if ($seoTitleRaw !== '' && $seoModel !== '' && !str_contains($seoTitleRaw, $seoModel)) {
            $seoTitleRaw .= ' ' . $seoModel;
        }

        $channelHeader = $this->catalog->frontRenderService->channelHeaderFromTagRow($primaryTag);
        if ($primaryTag === null) {
            $hub = DbRead::model(Tag::class)->where('status', 1)->where('slug', 'pv-demo-product')->find()?->toArray();
            if (is_array($hub)) {
                $channelHeader = $this->catalog->frontRenderService->channelHeaderFromTagRow($hub);
            }
        }
        /** 频道头的 page_title 是栏目名（如「资讯」），不得盖住品项名 */
        $channelTitle = trim((string) ($channelHeader['page_title'] ?? ''));
        if ($channelTitle === '' || $channelTitle === '资讯') {
            $channelTitle = $listTitle !== '' ? $listTitle : '产品中心';
        }
        unset($channelHeader['page_title'], $channelHeader['seo_title']);

        $itemShareUrl = SiteUrl::absolute(SiteUrl::productItem($slug));
        $itemQrUrl    = $itemId > 0
            ? $this->document->documentQrService->publicItemCacheUrl($itemId)
            : '';

        $productImages = is_array($field['images'] ?? null) ? $field['images'] : [];
        $productImageMain = '';
        foreach ($productImages as $imgRow) {
            if (!is_array($imgRow)) {
                continue;
            }
            $u = trim((string) ($imgRow['url'] ?? ''));
            if ($u !== '') {
                $productImageMain = $u;
                break;
            }
        }
        if ($productImageMain === '') {
            $productImageMain = trim((string) ($field['cover_url'] ?? $field['litpic'] ?? ''));
        }

        // 宿主扩展自读 request（如宿主 ?edition=）；内核不认业务 query
        $hostPageVars = PluginOfficialProduct::dispatch('product_item_page_vars', [
            'slug'  => $slug,
            'row'   => $row,
            'field' => $field,
        ], []);
        if (!is_array($hostPageVars)) {
            $hostPageVars = [];
        }

        return array_merge(
            $sidebar->sidebarVars($primarySlug),
            $this->itemPagePluginVars($docId, $itemId),
            $channelHeader,
            $hostPageVars,
            [
            'field'                      => $field,
            'product_images'             => $productImages,
            'product_images_empty'       => $productImages === [] ? 1 : 0,
            'product_image_main'         => $productImageMain,
            'document_id'                => $docId,
            'item_has_gallery'           => (int) ($field['item_has_gallery'] ?? 0),
            'channel_title'              => $channelTitle,
            /** 与品项名相同的摘要不重复顶在 Hero 副标题 */
            'item_hero_lead'             => ($seoDesc !== '' && $seoDesc !== $seoTitle) ? $seoDesc : '',
            'item_hero_lead_empty'       => ($seoDesc !== '' && $seoDesc !== $seoTitle) ? 0 : 1,
            'page_title'                 => $seoTitle,
            'seo_title'                  => $seoTitleRaw,
            'seo_title_context'          => 'item',
            'seo_keywords'               => '',
            'seo_description'            => $seoDesc,
            'document_litpic'            => trim((string) ($field['litpic'] ?? '')),
            'seo_og_type'                => 'product',
            'breadcrumbs'                => $this->catalog->breadcrumbService->forItem($field, $listUrl, $listTitle),
            'product_list_url'           => $listUrl,
            'item_primary_tag_view_tpl'  => is_array($primaryTag)
                ? trim((string) ($primaryTag['view_tpl_name'] ?? ''))
                : '',
            'item_prev'                  => $adjacent['prev'],
            'item_next'                  => $adjacent['next'],
            'item_prev_empty'            => $adjacent['prev'] === null ? 1 : 0,
            'item_next_empty'            => $adjacent['next'] === null ? 1 : 0,
            'item_share_url'             => $itemShareUrl,
            'item_qr_url'                => $itemQrUrl,
            // 模板可复用 document_* 名，但值必须是品项本页（非关联文档 URL）
            'document_share_url'         => $itemShareUrl,
            'document_qr_url'            => $itemQrUrl,
        ]);
    }

    /**
     * 品项详情页：文档插件区块开关 + 资料锚点
     *
     * @return array<string, int>
     */
    private function itemPagePluginVars(int $documentId, int $itemId): array
    {
        $out = [
            'item_has_resources'          => 0,
            'item_has_accessories'        => 0,
            'item_has_related'            => 0,
            'item_has_related_documents'  => 0,
            'document_has_doc_gallery_album'  => 0,
        ];
        if ($documentId > 0) {
            $flags = $this->document->documentPluginAvailabilityService->templateVars($documentId);
            $out   = array_merge($out, $flags);
            $out['document_has_doc_gallery_album'] = (int) ($flags['document_has_doc_gallery'] ?? 0);
            $hasResources = (int) ($flags['document_has_doc_gallery'] ?? 0) === 1;
            if (!$hasResources) {
                foreach (app(PluginExtensionRegistry::class)->documentAvailabilitySlots() as $slot) {
                    if ((int) ($flags['document_has_' . $slot] ?? 0) === 1) {
                        $hasResources = true;
                        break;
                    }
                }
            }
            $out['item_has_resources'] = $hasResources ? 1 : 0;
        }
        if ($itemId > 0) {
            $relations = app(ItemRelationPublicService::class);
            if ($relations->isAvailable()) {
                $out['item_has_accessories'] = $relations->hasForParent($itemId) ? 1 : 0;
            }
            $out['item_has_related'] = $this->listRelated($itemId, 1) !== [] ? 1 : 0;
            if ($documentId > 0) {
                $relatedDocs = $this->document->documentService->getRelatedPublic($documentId, 1);
                $out['item_has_related_documents'] = $relatedDocs !== [] ? 1 : 0;
            } else {
                $out['item_has_related_documents'] = 0;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $attrs */
    private function buildAttrsSummaryHtml(array $attrs): string
    {
        if ($attrs === []) {
            return '';
        }
        $labels = [];
        if (class_exists(\app\common\service\product\ProductService::class)) {
            foreach (\app\common\service\product\ProductService::listParamDefs() as $def) {
                $key = (string) ($def['param_key'] ?? '');
                if ($key !== '') {
                    $labels[$key] = (string) ($def['label'] ?? $key);
                }
            }
        }
        $html = '';
        $n    = 0;
        foreach ($attrs as $k => $v) {
            if (is_array($v) || is_object($v)) {
                continue;
            }
            $v = trim((string) $v);
            if ($v === '' || in_array((string) $k, ['summary', 'intro', 'detail_html', 'content'], true)) {
                continue;
            }
            $label = htmlspecialchars($labels[(string) $k] ?? (string) $k, ENT_QUOTES, 'UTF-8');
            $val   = htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
            $html .= '<span class="pv-product-attr"><span class="pv-product-attr__k">' . $label
                . '</span><span class="pv-product-attr__v">' . $val . '</span></span>';
            if (++$n >= 4) {
                break;
            }
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $attrs
     * @return list<array{key:string,label:string,value:string}>
     */
    private function paramRows(array $attrs, int $limit = 12): array
    {
        $labels = [];
        if (class_exists(\app\common\service\product\ProductService::class)) {
            foreach (\app\common\service\product\ProductService::listParamDefs() as $def) {
                $key = (string) ($def['param_key'] ?? '');
                if ($key !== '') {
                    $labels[$key] = (string) ($def['label'] ?? $key);
                }
            }
        }
        $rows = [];
        $skip = ['summary', 'intro', 'detail_html', 'content', '型号', 'model', 'code', '货号', 'eyou_params', 'eyou_params_meta'];
        foreach ($attrs as $k => $v) {
            if (is_array($v) || is_object($v)) {
                continue;
            }
            $v = trim((string) $v);
            $key = (string) $k;
            if ($v === '' || in_array($key, $skip, true)) {
                continue;
            }
            $rows[] = [
                'key'   => $key,
                'label' => $labels[$key] ?? $key,
                'value' => $v,
            ];
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{prev:?array<string,mixed>,next:?array<string,mixed>}
     */
    private function adjacentInTag(int $itemId, int $tagId): array
    {
        $empty = ['prev' => null, 'next' => null];
        if ($itemId < 1 || $tagId < 1) {
            return $empty;
        }
        $peerIds = array_values(array_unique(array_map(
            'intval',
            DbRead::model(ItemTag::class)->where('tag_id', $tagId)->column('item_id') ?: [],
        )));
        if ($peerIds === []) {
            return $empty;
        }
        $ordered = DbRead::model(Item::class)->whereIn('id', $peerIds)
            ->where('status', ItemService::STATUS_ACTIVE)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->column('id');
        $ordered = array_map('intval', $ordered ?: []);
        $pos     = array_search($itemId, $ordered, true);
        if ($pos === false) {
            return $empty;
        }
        $pick = function (int $peerId): ?array {
            if ($peerId < 1) {
                return null;
            }
            $row = DbRead::model(Item::class)->where('id', $peerId)->where('status', ItemService::STATUS_ACTIVE)->find()?->toArray();

            return $row !== null ? $this->enrichRow($row, false) : null;
        };

        return [
            'prev' => $pos > 0 ? $pick((int) $ordered[$pos - 1]) : null,
            'next' => $pos < count($ordered) - 1 ? $pick((int) $ordered[$pos + 1]) : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tagRowsForItem(int $itemId): array
    {
        if ($itemId < 1) {
            return [];
        }
        $tagIds = DbRead::model(ItemTag::class)->where('item_id', $itemId)->order('id', 'asc')->column('tag_id');
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds ?: []))));
        if ($tagIds === []) {
            return [];
        }
        $out = [];
        foreach (DbRead::model(Tag::class)->whereIn('id', $tagIds)->where('status', 1)->select()->toArray() as $row) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function documentHasRenderableAddonContent(int $documentId): bool
    {
        return DocumentAddonBridgeAccess::hasDocumentRenderableContent($documentId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadPrimaryDocumentSummary(int $documentId): ?array
    {
        if ($documentId < 1) {
            return null;
        }
        static $cache = [];
        if (array_key_exists($documentId, $cache)) {
            return $cache[$documentId];
        }
        // 品项主文档可能已软删（导入假重复清理后仍作封面/摘要来源）
        $row = DbRead::model(Document::class)->where('id', $documentId)
            ->field('id,title,litpic,summary,html_name,url_path,attr_flags')
            ->find()?->toArray();

        return $cache[$documentId] = ($row ?: null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadPrimaryDocument(int $documentId): ?array
    {
        if ($documentId < 1) {
            return null;
        }
        $detail = $this->document->documentService->getBoundPrimaryDocumentData($documentId);

        return is_array($detail) ? $detail : null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $doc
     */
    private function resolveSummary(array $row, ?array $doc): string
    {
        $attrs = is_array($row['attrs'] ?? null) ? $row['attrs'] : [];
        $fromAttrs = trim((string) ($attrs['summary'] ?? $attrs['intro'] ?? ''));
        if ($fromAttrs !== '') {
            return $fromAttrs;
        }
        if ($doc !== null) {
            $s = trim((string) ($doc['summary'] ?? ''));
            if ($s !== '') {
                return $s;
            }
            $content = trim(strip_tags((string) ($doc['content'] ?? '')));
            if ($content !== '') {
                return mb_strlen($content) > 200 ? mb_substr($content, 0, 200) . '…' : $content;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $attrs */
    private function attrsSummaryText(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $k => $v) {
            if (is_array($v) || is_object($v)) {
                continue;
            }
            $v = trim((string) $v);
            if ($v === '' || in_array((string) $k, ['summary', 'intro', 'detail_html', 'content'], true)) {
                continue;
            }
            $parts[] = $v;
            if (count($parts) >= 3) {
                break;
            }
        }

        return implode(' · ', $parts);
    }

    /** @param array<string, mixed> $attrs */
    private function buildSpecsHtml(array $attrs): string
    {
        if ($attrs === []) {
            return '<p class="text-muted small mb-0">暂无结构化参数，请联系应用工程师获取选型表。</p>';
        }
        $labels = [];
        if (class_exists(\app\common\service\product\ProductService::class)) {
            foreach (\app\common\service\product\ProductService::listParamDefs() as $def) {
                $key = (string) ($def['param_key'] ?? '');
                if ($key !== '') {
                    $labels[$key] = (string) ($def['label'] ?? $key);
                }
            }
        }
        $items = '';
        $n     = 0;
        foreach ($attrs as $k => $v) {
            if (is_array($v) || is_object($v)) {
                continue;
            }
            $v = trim((string) $v);
            if ($v === '' || in_array((string) $k, ['summary', 'intro', 'detail_html', 'content'], true)) {
                continue;
            }
            $label = htmlspecialchars($labels[(string) $k] ?? (string) $k, ENT_QUOTES, 'UTF-8');
            $val   = htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
            $items .= '<li><span class="pv-product-specs__k">' . $label
                . '</span><span class="pv-product-specs__v">' . $val . '</span></li>';
            if (++$n >= 24) {
                break;
            }
        }
        if ($items === '') {
            return '<p class="text-muted small mb-0">暂无结构化参数，请联系应用工程师获取选型表。</p>';
        }

        return '<ul class="pv-product-specs">' . $items . '</ul>';
    }
}
