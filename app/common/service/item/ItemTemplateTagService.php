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

use app\common\model\Item;

use app\common\service\infra\PaginationService;
use app\common\service\template\TemplateEngine;
use app\common\service\template\TemplateTagParser;

/**
 * Core 品项模板标签（不依赖 product 插件）
 * {pv:item} · {pv:product_related} · {pv:product_accessories}
 * 栏目/列表品项请用 {pv:arclist entity="product"}；本文关联多品用 {pv:product}（产品中心开）或本标签单卡。
 */
final class ItemTemplateTagService
{

    public function __construct(
        private readonly TemplateEngine $templateEngine,
        private readonly ItemService $itemService,
        private readonly PaginationService $paginationService,
        private readonly TemplateTagParser $templateTagParser,
    ) {
    }

    public function boot(): void
    {
        $this->templateEngine->registerKernelTag('item', static fn (array $attrs, array $pageVars, string $tpl = '') => app(self::class)->renderItemTag($attrs, $pageVars, $tpl));
        $this->templateEngine->registerKernelTag('product_related', static fn (array $attrs, array $pageVars, string $tpl = '') => app(self::class)->renderProductRelatedTag($attrs, $pageVars, $tpl));
        $this->templateEngine->registerKernelTag('product_accessories', static fn (array $attrs, array $pageVars, string $tpl = '') => app(self::class)->renderProductAccessoriesTag($attrs, $pageVars, $tpl));
    }

    /**
     * @param array<string, mixed> $attrs
     */
    public function renderItemTag(array $attrs, array $pageVars, string $tpl = ''): string
    {
        $itemId = (int) ($attrs['id'] ?? $attrs['item_id'] ?? 0);
        $slug   = trim((string) ($attrs['slug'] ?? ''));
        if ($itemId < 1 && $slug === '') {
            $docId = (int) ($attrs['document_id'] ?? $pageVars['document_id'] ?? 0);
            if ($docId > 0) {
                $ids = $this->itemService->itemIdsForDocument($docId);
                $itemId = $ids[0] ?? 0;
            }
        }
        if ($slug !== '') {
            $row = $this->itemService->findPublicBySlug($slug);
        } elseif ($itemId > 0) {
            $slug = (string) Item::where('id', $itemId)->value('slug');
            $row = $slug !== '' ? $this->itemService->findPublicBySlug($slug) : null;
        } else {
            $row = null;
        }
        if ($row === null) {
            return '';
        }
        if (trim($tpl) !== '') {
            return $this->renderLoop($tpl, [$row], $pageVars);
        }

        return $this->defaultCardHtml($row);
    }

    /**
     * @param array<string, mixed> $attrs
     */
    public function renderProductRelatedTag(array $attrs, array $pageVars, string $tpl = ''): string
    {
        if (class_exists(\app\common\service\product\ProductService::class)
            && \app\common\service\product\ProductCenterGateService::requirePublicApi() === null) {
            return \app\common\service\product\ProductService::renderProductRelatedTag($attrs, $pageVars, $tpl);
        }

        $limit  = min(12, max(1, (int) ($attrs['limit'] ?? 6)));
        $itemId = $this->resolveItemIdFromAttrs($attrs, $pageVars);
        if ($itemId < 1) {
            return '';
        }
        $list = $this->itemService->listRelatedPublic($itemId, $limit);
        if ($list === []) {
            return '';
        }
        if (trim($tpl) !== '') {
            return $this->renderLoop($tpl, $list, $pageVars);
        }

        return $this->renderDefaultCardGrid($list);
    }

    /**
     * @param array<string, mixed> $attrs
     */
    public function renderProductAccessoriesTag(array $attrs, array $pageVars, string $tpl = ''): string
    {
        if (class_exists(\app\common\service\product\ProductService::class)) {
            if (class_exists(\app\common\service\product\ProductItemRelationService::class)
                && \app\common\service\product\ProductItemRelationService::isAvailable()) {
                return \app\common\service\product\ProductService::renderProductAccessoriesTag($attrs, $pageVars, $tpl);
            }
        }

        $limit  = min(48, max(1, (int) ($attrs['limit'] ?? 12)));
        $itemId = $this->resolveItemIdFromAttrs($attrs, $pageVars);
        if ($itemId < 1) {
            return '';
        }
        $type = trim((string) ($attrs['type'] ?? $attrs['relation_type'] ?? ''));
        $list = app(ItemRelationPublicService::class)->listForParent(
            $itemId,
            $limit,
            $type !== '' ? $type : null,
        );
        if ($list === []) {
            return '';
        }
        if (trim($tpl) !== '') {
            return $this->renderLoop($tpl, $list, $pageVars);
        }

        return $this->renderDefaultCardGrid($list);
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $pageVars
     */
    private function resolveItemIdFromAttrs(array $attrs, array $pageVars): int
    {
        $itemId = (int) ($attrs['item_id'] ?? $attrs['id'] ?? 0);
        $docId  = (int) ($attrs['document_id'] ?? $pageVars['document_id'] ?? 0);
        if ($itemId < 1 && $docId > 0) {
            $ids = $this->itemService->itemIdsForDocument($docId);
            $itemId = $ids[0] ?? 0;
        }
        if ($itemId < 1) {
            $field = $pageVars['field'] ?? null;
            if (is_array($field)) {
                $itemId = (int) ($field['id'] ?? 0);
            }
        }

        return $itemId;
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $pageVars
     * @return array<string, mixed>
     */
    public function listParamsFromAttrs(array $attrs, array $pageVars = []): array
    {
        return app(ItemListTagAttrsService::class)->listParamsFromAttrs(
            $attrs,
            $pageVars,
            $this->requestQueryFromPageVars($pageVars)
        );
    }

    /**
     * @param array<string, mixed> $pageVars
     * @return array<string, mixed>
     */
    private function requestQueryFromPageVars(array $pageVars): array
    {
        $query = $pageVars['request_query'] ?? [];

        return is_array($query) ? $query : [];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $loopAttrs
     */
    private function renderLoop(string $tpl, array $items, array $pageVars, array $loopAttrs = []): string
    {
        return $this->templateTagParser->renderItemLoop($tpl, $items, $pageVars, 'field', $loopAttrs);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function renderDefaultCardGrid(array $items): string
    {
        if ($items === []) {
            return '';
        }
        $html = '<div class="pv-items-grid pv-products-grid">';
        foreach ($items as $item) {
            $html .= $this->defaultCardHtml($item);
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function defaultCardHtml(array $item): string
    {
        $name = htmlspecialchars((string) ($item['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $code = htmlspecialchars((string) ($item['code'] ?? ''), ENT_QUOTES, 'UTF-8');
        $type = htmlspecialchars((string) ($item['item_type_text'] ?? ''), ENT_QUOTES, 'UTF-8');
        $spec = trim((string) ($item['attrs_summary_html'] ?? ''));
        $coverUrl  = trim((string) ($item['cover_url'] ?? ''));
        $cardUrl   = trim((string) ($item['card_url'] ?? $item['page_url'] ?? $item['detail_url'] ?? ''));
        $wrapOpen  = $cardUrl !== ''
            ? '<a class="pv-product-card pv-product-card--rich pv-product-card--link" href="'
                . htmlspecialchars($cardUrl, ENT_QUOTES, 'UTF-8') . '">'
            : '<div class="pv-product-card pv-product-card--rich" data-item-id="' . (int) ($item['id'] ?? 0) . '">';
        $wrapClose = $cardUrl !== '' ? '</a>' : '</div>';
        $coverHtml = $coverUrl !== ''
            ? '<div class="pv-product-cover"><img src="'
                . htmlspecialchars($coverUrl, ENT_QUOTES, 'UTF-8')
                . '" alt="' . $name . '" loading="lazy"></div>'
            : '';
        $typeHtml = $type !== '' ? '<span class="pv-product-card__type">' . $type . '</span>' : '';

        return $wrapOpen
            . $coverHtml
            . '<div class="pv-product-card__body">'
            . $typeHtml
            . '<h4 class="pv-product-name">' . $name . '</h4>'
            . ($code !== '' ? '<div class="pv-product-code">货号 ' . $code . '</div>' : '')
            . ($spec !== '' ? '<div class="pv-product-attrs">' . $spec . '</div>' : '')
            . '</div>'
            . $wrapClose;
    }
}
