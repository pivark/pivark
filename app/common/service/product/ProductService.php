<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\product;

use app\common\service\item\ItemService;
use app\common\service\weapp\WeappItemGateway;

use app\common\support\AppTime;
use app\common\support\ItemAttrKeyGuard;
use app\common\support\ServiceResult;
use app\common\support\SlugHelper;

use app\common\service\item\ItemListTagAttrsService;
use app\common\service\item\ItemPublicGateway;
use app\common\service\infra\PaginationService;
use app\common\service\product\OfferBridgeFacade;
use app\common\service\template\ArclistTagResolveService;
use app\common\service\template\TemplateEngine;
use app\common\model\Item;
use app\common\model\ProductParamDef;
use app\common\model\ProductParamGroup;
use app\common\service\template\TemplateTagParser;
use think\facade\Request;
use think\facade\Db;

/** 产品展示：自定义参数 + 前台筛选列表 */
class ProductService
{

    public static function boot(): void
    {
        if (!ProductConfigService::isOpen()) {
            return;
        }
        app(TemplateEngine::class)->registerKernelTag('product', [self::class, 'renderProductTag']);
        app(TemplateEngine::class)->registerKernelTag('product_related', [self::class, 'renderProductRelatedTag']);
        app(TemplateEngine::class)->registerKernelTag('product_accessories', [self::class, 'renderProductAccessoriesTag']);
        app(TemplateEngine::class)->registerKernelTag('products_compare', [self::class, 'renderProductsCompareTag']);
    }

    /**
     * @param list<array{id:int,sort:int}> $orders
     * @return ServiceResult
     */
    public static function sortParamDefs(array $orders): ServiceResult
    {
        if ($orders === []) {
            return ServiceResult::fail('无排序数据');
        }
        foreach ($orders as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            ProductParamDef::where('id', $id)->update([
                'sort'       => (int) ($row['sort'] ?? 0),
                'updated_at' => AppTime::now(),
            ]);
        }

        return ServiceResult::ok(null, '排序已保存');
    }

    /**
     * @param array<string, mixed> $attrs
     */
    public static function renderProductTag(array $attrs, array $pageVars, string $tpl = ''): string
    {
        $itemId = (int) ($attrs['id'] ?? $attrs['item_id'] ?? 0);
        $docId  = (int) ($attrs['document_id'] ?? $pageVars['document_id'] ?? 0);
        if ($itemId > 0) {
            return self::renderSingleProductPublic($itemId, $attrs, $pageVars, $tpl);
        }
        if ($docId > 0) {
            $list = self::listPublicForDocument($docId);
            if ($list === []) {
                return '';
            }
            if (trim($tpl) !== '') {
                return self::renderLoop($tpl, $list, $pageVars);
            }

            return self::renderDefaultCardGrid($list, 'pv-products-grid--doc');
        }

        return '';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private static function renderSingleProductPublic(
        int $itemId,
        array $attrs,
        array $pageVars,
        string $tpl,
    ): string {
        $row = Item::where('id', $itemId)->where('status', ItemService::STATUS_ACTIVE)->find();
        if (!$row) {
            return '';
        }
        $item = app(ItemPublicGateway::class)->findPublicBySlug((string) ($row['slug'] ?? ''));
        if ($item === null) {
            return '';
        }
        if (trim($tpl) !== '') {
            return self::renderLoop($tpl, [$item], $pageVars);
        }

        return self::defaultCardHtml($item);
    }

    /**
     * 文档已关联的全部在售品项（保持关联顺序）
     *
     * @return list<array<string, mixed>>
     */
    public static function listPublicForDocument(int $documentId): array
    {
        if ($documentId < 1) {
            return [];
        }
        $defs = self::listParamDefsForDocument($documentId);
        $list = app(ItemPublicGateway::class)->listPublicByIds(self::itemIdsForDocument($documentId));
        if ($defs === []) {
            return $list;
        }
        foreach ($list as &$item) {
            $attrs = is_array($item['attrs'] ?? null) ? $item['attrs'] : [];
            $item['attrs_summary_html'] = self::formatAttrsHtml($attrs, $defs);
        }
        unset($item);

        return $list;
    }

    /**
     * 同文档首品项 → 同标签相关推荐
     *
     * @param array<string, mixed> $attrs
     */
    /**
     * 主品项已配置的配件/辅件（非标签猜的相关推荐）
     *
     * @param array<string, mixed> $attrs item_id / document_id / limit / type=accessory|spare|component
     */
    public static function renderProductAccessoriesTag(array $attrs, array $pageVars, string $tpl = ''): string
    {
        if (!class_exists(ProductItemRelationService::class) || !ProductItemRelationService::isAvailable()) {
            return '';
        }
        $limit = min(48, max(1, (int) ($attrs['limit'] ?? 12)));
        $itemId = self::resolveItemIdFromPage($attrs, $pageVars);
        if ($itemId < 1) {
            return '';
        }
        $type = trim((string) ($attrs['type'] ?? $attrs['relation_type'] ?? ''));
        $list = ProductItemRelationService::listPublicForParent(
            $itemId,
            $limit,
            $type !== '' ? $type : null,
        );
        if ($list === []) {
            return '';
        }
        if (trim($tpl) !== '') {
            return self::renderLoop($tpl, $list, $pageVars);
        }
        $html = '<div class="pv-products-accessories">';
        foreach ($list as $item) {
            $html .= self::defaultCardHtml($item);
        }
        $html .= '</div>';

        return $html;
    }

    public static function renderProductRelatedTag(array $attrs, array $pageVars, string $tpl = ''): string
    {
        $limit = min(12, max(1, (int) ($attrs['limit'] ?? 6)));
        $itemId = self::resolveItemIdFromPage($attrs, $pageVars);
        if ($itemId < 1) {
            return '';
        }
        $list = app(ItemPublicGateway::class)->listRelatedPublic($itemId, $limit);
        if ($list === []) {
            return '';
        }
        if (trim($tpl) !== '') {
            return self::renderLoop($tpl, $list, $pageVars);
        }
        $html = '<div class="pv-products-related">';
        foreach ($list as $item) {
            $html .= self::defaultCardHtml($item);
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * 多品项参数对比表（attrs 列来自 product_param_defs）
     *
     * @param array<string, mixed> $attrs ids="1,2,3"
     */
    public static function renderProductsCompareTag(array $attrs, array $pageVars, string $tpl = ''): string
    {
        $idsRaw = trim((string) ($attrs['ids'] ?? $attrs['id'] ?? ''));
        if ($idsRaw === '') {
            return '';
        }
        $ids = [];
        foreach (preg_split('/[,\s|]+/', $idsRaw) ?: [] as $part) {
            $id = (int) trim((string) $part);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return '';
        }
        $list = app(ItemPublicGateway::class)->listPublicByIds($ids);
        if ($list === []) {
            return '';
        }
        if (trim($tpl) !== '') {
            return self::renderLoop($tpl, $list, $pageVars);
        }
        $docId = (int) ($attrs['document_id'] ?? $pageVars['document_id'] ?? 0);
        $defs  = $docId > 0 ? self::listParamDefsForDocument($docId) : self::listParamDefs();
        if ($defs === []) {
            $defs = self::listParamDefs();
        }
        $html = '<table class="pv-products-compare"><thead><tr><th>品项</th>';
        foreach ($defs as $def) {
            $html .= '<th>' . htmlspecialchars((string) ($def['label'] ?? $def['param_key'] ?? ''), ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($list as $item) {
            $attrsRow = is_array($item['attrs'] ?? null) ? $item['attrs'] : [];
            $html .= '<tr><td>' . htmlspecialchars((string) ($item['name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
            foreach ($defs as $def) {
                $key = (string) ($def['param_key'] ?? '');
                $val = htmlspecialchars((string) ($attrsRow[$key] ?? '—'), ENT_QUOTES, 'UTF-8');
                $html .= '<td>' . $val . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * 目录列表页已注入 product_list 时复用，避免与分页变量二次查询不一致。
     *
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $pageVars
     */
    /**
     * 供 `{pv:arclist entity=product}`：目录页已注入 product_list 时复用。
     *
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $pageVars
     */
    public static function preferInjectedProductList(array $attrs, array $pageVars): bool
    {
        if (!array_key_exists('product_list', $pageVars) || !is_array($pageVars['product_list'])) {
            return false;
        }
        if (trim((string) ($attrs['ids'] ?? '')) !== '') {
            return false;
        }

        $attrsService = app(ItemListTagAttrsService::class);
        $want = $attrsService->wantsExplicitLimit($attrs);
        if ($want !== null) {
            $pageLimit = (int) ($pageVars['limit'] ?? $pageVars['pagination_limit'] ?? 0);
            if ($pageLimit < 1) {
                $pageLimit = count($pageVars['product_list']);
            }
            if ($want !== $pageLimit) {
                return false;
            }
        }

        $pageTag = trim((string) ($pageVars['tag_slug'] ?? ''));
        $resolved = app(ArclistTagResolveService::class)->resolveSlugList($attrs, $pageVars);
        $scopeTag = $resolved[0] ?? trim((string) ($attrs['tag'] ?? ''));
        // 显式写了与本页不同的 Tag 定位 → 独立查询
        if ($scopeTag !== $pageTag) {
            return false;
        }

        return true;
    }

    public static function listParamsFromAttrs(array $attrs, array $pageVars = []): array
    {
        $query = Request::get();

        return app(ItemListTagAttrsService::class)->listParamsFromAttrs(
            $attrs,
            $pageVars,
            is_array($query) ? $query : []
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $loopAttrs
     */
    private static function renderLoop(string $tpl, array $items, array $pageVars, array $loopAttrs = []): string
    {
        return app(TemplateTagParser::class)->renderItemLoop($tpl, $items, $pageVars, 'field', $loopAttrs);
    }

    /**
     * @param array<string, mixed> $item
     */
    /**
     * @param list<array<string, mixed>> $items
     */
    private static function renderDefaultCardGrid(array $items, string $gridClass = ''): string
    {
        if ($items === []) {
            return '';
        }
        $class = 'pv-products-grid' . ($gridClass !== '' ? ' ' . $gridClass : '');
        $html  = '<div class="' . $class . '">';
        foreach ($items as $item) {
            $html .= self::defaultCardHtml($item);
        }
        $html .= '</div>';

        return $html;
    }

    private static function defaultCardHtml(array $item): string
    {
        $name = htmlspecialchars((string) ($item['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $code = htmlspecialchars((string) ($item['code'] ?? ''), ENT_QUOTES, 'UTF-8');
        $type = htmlspecialchars((string) ($item['item_type_text'] ?? ''), ENT_QUOTES, 'UTF-8');
        $spec = trim((string) ($item['attrs_summary_html'] ?? ''));
        if ($spec === '') {
            $spec = self::formatAttrsHtml(is_array($item['attrs'] ?? null) ? $item['attrs'] : []);
        }
        $coverUrl  = trim((string) ($item['cover_url'] ?? ''));
        $cardUrl   = trim((string) ($item['card_url'] ?? $item['page_url'] ?? $item['detail_url'] ?? ''));
        $detailUrl = trim((string) ($item['detail_url'] ?? ''));
        $wrapOpen  = $cardUrl !== ''
            ? '<a class="pv-product-card pv-product-card--rich pv-product-card--link" href="'
                . htmlspecialchars($cardUrl, ENT_QUOTES, 'UTF-8') . '">'
            : '<div class="pv-product-card pv-product-card--rich" data-item-id="' . (int) ($item['id'] ?? 0) . '">';
        $wrapClose = $detailUrl !== '' ? '</a>' : '</div>';
        $coverHtml = '';
        if ($coverUrl !== '') {
            $coverHtml = '<div class="pv-product-cover"><img src="'
                . htmlspecialchars($coverUrl, ENT_QUOTES, 'UTF-8')
                . '" alt="' . $name . '" loading="lazy"></div>';
        } elseif ($detailUrl !== '') {
            $coverHtml = '<div class="pv-product-cover pv-product-cover--placeholder" aria-hidden="true"></div>';
        }
        $typeHtml = $type !== '' ? '<span class="pv-product-card__type">' . $type . '</span>' : '';
        $ctaHtml  = $detailUrl !== ''
            ? '<span class="pv-product-card__cta">查看详情</span>'
            : '';

        return $wrapOpen
            . $coverHtml
            . '<div class="pv-product-card__body">'
            . $typeHtml
            . '<h4 class="pv-product-name">' . $name . '</h4>'
            . ($code !== '' ? '<div class="pv-product-code">货号 ' . $code . '</div>' : '')
            . ($spec !== '' ? '<div class="pv-product-attrs">' . $spec . '</div>' : '')
            . $ctaHtml
            . '</div>'
            . $wrapClose;
    }

    /** @param list<array<string,mixed>> $defs */
    public static function attrsSummaryText(array $attrs, array $defs = []): string
    {
        if ($defs === []) {
            $parts = [];
            foreach ($attrs as $k => $v) {
                if (is_array($v) || is_object($v)) {
                    continue;
                }
                $v = trim((string) $v);
                if ($v === '' || in_array((string) $k, ['summary', 'intro'], true)) {
                    continue;
                }
                $parts[] = $v;
                if (count($parts) >= 3) {
                    break;
                }
            }

            return implode(' · ', $parts);
        }
        $keys = [];
        foreach ($defs as $def) {
            $key = (string) ($def['param_key'] ?? '');
            if ($key !== '') {
                $keys[] = $key;
            }
        }
        $parts = [];
        foreach ($keys as $key) {
            $v = trim((string) ($attrs[$key] ?? ''));
            if ($v === '') {
                continue;
            }
            $parts[] = $v;
            if (count($parts) >= 3) {
                break;
            }
        }

        return implode(' · ', $parts);
    }

    /** @param list<array<string,mixed>> $defs */
    private static function formatAttrsHtml(array $attrs, array $defs = []): string
    {
        if ($defs === []) {
            $defs = self::listParamDefs();
        }
        $labels = [];
        foreach ($defs as $def) {
            $key = (string) ($def['param_key'] ?? '');
            if ($key !== '') {
                $labels[$key] = (string) ($def['label'] ?? $key);
            }
        }
        $html = '';
        $n    = 0;
        foreach ($attrs as $k => $v) {
            $v = trim((string) $v);
            if ($v === '') {
                continue;
            }
            if ($labels !== [] && !isset($labels[(string) $k])) {
                continue;
            }
            $label = htmlspecialchars($labels[(string) $k] ?? (string) $k, ENT_QUOTES, 'UTF-8');
            $html .= '<span class="pv-product-attr"><span class="pv-product-attr__k">' . $label
                . '</span><span class="pv-product-attr__v">' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '</span></span>';
            if (++$n >= 4) {
                break;
            }
        }

        return $html;
    }

    /**
     * 品项详情页优先 field.id；文档页回落 document_item_refs / primary_document_id
     *
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $pageVars
     */
    public static function resolveItemIdFromPage(array $attrs, array $pageVars): int
    {
        $itemId = (int) ($attrs['item_id'] ?? $attrs['id'] ?? 0);
        if ($itemId < 1) {
            $field = $pageVars['field'] ?? null;
            if (is_array($field)) {
                $itemId = (int) ($field['id'] ?? $field['item_id'] ?? 0);
            }
        }
        if ($itemId < 1) {
            $itemId = (int) ($pageVars['item_id'] ?? 0);
        }
        if ($itemId < 1) {
            $docId = (int) ($attrs['document_id'] ?? $pageVars['document_id'] ?? 0);
            if ($docId > 0) {
                $itemId = self::itemIdsForDocument($docId)[0] ?? 0;
            }
        }

        return $itemId > 0 ? $itemId : 0;
    }

    /** @return list<int> */
    public static function itemIdsForDocument(int $documentId): array
    {
        return app(WeappItemGateway::class)->itemIdsForDocument($documentId);
    }

    /** @return list<int> */
    public static function paramGroupIdsForDocument(int $documentId): array
    {
        if ($documentId < 1) {
            return [];
        }
        $pfx = (string) config('database.connections.mysql.prefix');
        $rows = Db::name('document_param_group_refs')
            ->where('document_id', $documentId)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->column('group_id');

        return array_values(array_filter(array_map('intval', $rows), static fn (int $id): bool => $id > 0));
    }

    public static function layoutModeForDocument(int $documentId): string
    {
        if ($documentId < 1) {
            return 'single';
        }
        $row = Db::name('document_product_settings')->where('document_id', $documentId)->find();
        $mode  = trim((string) ($row['layout_mode'] ?? 'single'));
        // multi_model 已退役：存量当单型号
        if ($mode === 'multi_model') {
            return 'single';
        }
        if ($mode === 'multi_spec') {
            return 'multi_spec';
        }

        $primary = app(\app\common\service\item\ItemService::class)->primaryItemRowForDocument($documentId);
        if (is_array($primary)) {
            $itemId = (int) ($primary['id'] ?? 0);
            if ($itemId > 0) {
                $variantCount = app(\app\common\service\item\ItemVariantService::class)->countByItemId($itemId);
                if ($variantCount > 1) {
                    return 'multi_spec';
                }
            }
        }

        return 'single';
    }

    public static function accessorySectionLabelForDocument(int $documentId): string
    {
        if ($documentId < 1) {
            return '零配件';
        }
        $row = Db::name('document_product_settings')->where('document_id', $documentId)->find();
        $label = trim((string) ($row['accessory_section_label'] ?? ''));

        return $label !== '' ? mb_substr($label, 0, 64) : '零配件';
    }

    public static function syncDocumentProductSettings(int $documentId, string $layoutMode, string $accessorySectionLabel = ''): void
    {
        if ($documentId < 1) {
            return;
        }
        $layoutMode = in_array($layoutMode, ['single', 'multi_spec'], true)
            ? $layoutMode
            : 'single';
        $label = trim($accessorySectionLabel);
        if ($label === '') {
            $label = '零配件';
        }
        $label = mb_substr($label, 0, 64);
        $exists = Db::name('document_product_settings')->where('document_id', $documentId)->count() > 0;
        if ($exists) {
            Db::name('document_product_settings')->where('document_id', $documentId)->update([
                'layout_mode'             => $layoutMode,
                'accessory_section_label' => $label,
            ]);
        } else {
            Db::name('document_product_settings')->insert([
                'document_id'             => $documentId,
                'layout_mode'             => $layoutMode,
                'accessory_section_label' => $label,
            ]);
        }
    }

    /** @param list<int> $groupIds 至多 1 项；空 = 未选参数组 */
    public static function syncDocumentParamGroupRefs(int $documentId, array $groupIds): void
    {
        if ($documentId < 1) {
            return;
        }
        Db::name('document_param_group_refs')->where('document_id', $documentId)->delete();
        $groupIds = array_values(array_unique(array_filter(
            array_map('intval', $groupIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($groupIds !== []) {
            $groupIds = [(int) $groupIds[0]];
        }
        foreach ($groupIds as $sort => $groupId) {
            Db::name('document_param_group_refs')->insert([
                'document_id' => $documentId,
                'group_id'    => $groupId,
                'sort'        => $sort,
                'created_at'  => AppTime::now(),
            ]);
        }
    }

    /**
     * @param list<int> $groupIds 空 = 全部组
     * @param list<array<string, mixed>> $defs
     * @return list<array<string, mixed>>
     */
    public static function filterParamDefsByGroupIds(array $defs, array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }
        $set = array_flip($groupIds);

        return array_values(array_filter(
            $defs,
            static fn (array $def): bool => isset($set[(int) ($def['group_id'] ?? 0)]),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listParamDefsForDocument(int $documentId): array
    {
        return self::filterParamDefsByGroupIds(
            self::listParamDefs(),
            self::paramGroupIdsForDocument($documentId),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listParamDefs(): array
    {
        $groupMap = self::paramGroupLabelMap();
        $rows     = ProductParamDef::order('sort', 'asc')->order('id', 'asc')->select()->toArray();
        $out      = [];
        foreach ($rows as $row) {
            $gid = (int) ($row['group_id'] ?? 0);
            $out[] = [
                'id'          => (int) ($row['id'] ?? 0),
                'group_id'    => $gid,
                'group_key'   => (string) ($groupMap[$gid]['group_key'] ?? ''),
                'group_label' => (string) ($groupMap[$gid]['label'] ?? ''),
                'param_key'   => (string) ($row['param_key'] ?? ''),
                'label'       => (string) ($row['label'] ?? ''),
                'input_type'  => (string) ($row['input_type'] ?? 'text'),
                'options'     => self::normalizeParamOptions(json_decode((string) ($row['options_json'] ?? '[]'), true) ?: []),
                'filterable'  => (int) ($row['filterable'] ?? 0),
                'default_value' => (string) ($row['default_value'] ?? ''),
                'sort'        => (int) ($row['sort'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * 参数可选值归一：兼容旧版 string[] 与 [{value,label}]；UI 用 label，attrs 存 value。
     *
     * @param mixed $raw
     * @return list<array{value:string,label:string}>
     */
    public static function normalizeParamOptions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $opt) {
            if (is_string($opt) || is_numeric($opt)) {
                $v = trim((string) $opt);
                if ($v === '' || $v === '[object Object]') {
                    continue;
                }
                $out[] = ['value' => $v, 'label' => $v];
                continue;
            }
            if (!is_array($opt)) {
                continue;
            }
            $rawValue = $opt['value'] ?? $opt['id'] ?? '';
            $rawLabel = $opt['label'] ?? $opt['name'] ?? '';
            // 嵌套对象 / 脏串一律丢弃
            if (is_array($rawValue) || is_object($rawValue)) {
                $rawValue = '';
            }
            if (is_array($rawLabel) || is_object($rawLabel)) {
                $rawLabel = '';
            }
            $value = trim((string) $rawValue);
            $label = trim((string) $rawLabel);
            if ($value === '[object Object]') {
                $value = '';
            }
            if ($label === '[object Object]') {
                $label = '';
            }
            if ($value === '' && $label !== '') {
                $value = $label;
            }
            if ($label === '' && $value !== '') {
                $label = $value;
            }
            if ($value === '') {
                continue;
            }
            $out[] = ['value' => $value, 'label' => $label];
        }

        return $out;
    }

    /**
     * @param list<array{value?:string,label?:string}|string> $options
     * @return list<string>
     */
    public static function paramOptionValues(array $options): array
    {
        $vals = [];
        foreach (self::normalizeParamOptions($options) as $opt) {
            $vals[] = $opt['value'];
        }

        return $vals;
    }

    /**
     * @param array{active_only?:bool} $options
     * @return list<array{id:int,group_key:string,label:string,description:string,status:int,sort:int,params:list<array<string,mixed>>}>
     */
    public static function listParamGroupsWithDefs(array $options = []): array
    {
        $activeOnly = !empty($options['active_only']);
        $groupMap = self::paramGroupLabelMap();
        $byGroup  = [];
        foreach (array_keys($groupMap) as $gid) {
            if ($gid > 0) {
                $byGroup[$gid] = [];
            }
        }
        foreach (self::listParamDefs() as $def) {
            $gid = (int) ($def['group_id'] ?? 0);
            if ($gid < 1) {
                continue;
            }
            $byGroup[$gid][] = $def;
        }

        $groups = [];
        foreach ($groupMap as $gid => $meta) {
            if ($gid < 1) {
                continue;
            }
            if ($activeOnly && (int) ($meta['status'] ?? 1) !== 1) {
                continue;
            }
            $groups[] = [
                'id'          => $gid,
                'group_key'   => (string) ($meta['group_key'] ?? ''),
                'label'       => (string) ($meta['label'] ?? ''),
                'description' => (string) ($meta['description'] ?? ''),
                'status'      => (int) ($meta['status'] ?? 1),
                'sort'        => (int) ($meta['sort'] ?? 0),
                'params'      => $byGroup[$gid] ?? [],
            ];
        }
        usort($groups, static fn (array $a, array $b): int => ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0)));

        return $groups;
    }

    /** @return array<int, array{group_key:string,label:string,description:string,status:int,sort:int}> */
    private static function paramGroupLabelMap(): array
    {
        $map = [];
        try {
            $rows = ProductParamGroup::order('sort', 'asc')->order('id', 'asc')->select()->toArray();
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id < 1) {
                    continue;
                }
                $map[$id] = [
                    'group_key'   => (string) ($row['group_key'] ?? ''),
                    'label'       => (string) ($row['label'] ?? ''),
                    'description' => (string) ($row['description'] ?? ''),
                    'status'      => (int) ($row['status'] ?? 1),
                    'sort'        => (int) ($row['sort'] ?? 0),
                ];
            }
        } catch (\Throwable) {
            // 参数分组表未就绪时返回空 map
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $context 当前 URL 查询（用于 facet 计数）
     * @return list<array{param_key:string,label:string,options:list<array{value:string,count:int,disabled:bool}>}>
     */
    public static function filterOptions(array $context = []): array
    {
        return app(ItemPublicGateway::class)->filterOptions($context);
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public static function saveParamGroup(array $data): ServiceResult
    {
        $id    = (int) ($data['id'] ?? 0);
        $label = trim((string) ($data['label'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $sort  = (int) ($data['sort'] ?? 0);
        $status = array_key_exists('status', $data)
            ? ((int) $data['status'] === 1 ? 1 : 0)
            : null;
        if ($label === '') {
            return ServiceResult::fail('参数组名称不能为空');
        }
        $rawKey = trim((string) ($data['group_key'] ?? ''));
        if ($id > 0 && $rawKey === '') {
            $key = (string) ProductParamGroup::where('id', $id)->value('group_key');
        } else {
            $key = self::resolveUniqueGroupKey($rawKey, $label, $id);
        }
        if ($key === '') {
            return ServiceResult::fail('无法生成参数组标识，请换一个名称');
        }
        $dup = ProductParamGroup::where('group_key', $key);
        if ($id > 0) {
            $dup->where('id', '<>', $id);
        }
        if ((int) $dup->count() > 0) {
            return ServiceResult::fail('参数组名称与已有组冲突，请换一个名称');
        }
        $payload = [
            'group_key'   => $key,
            'label'       => mb_substr($label, 0, 100),
            'description' => mb_substr($description, 0, 500),
            'sort'        => $sort,
            'updated_at'  => AppTime::now(),
        ];
        if ($status !== null) {
            $payload['status'] = $status;
        }
        if ($id > 0) {
            ProductParamGroup::where('id', $id)->update($payload);

            return ServiceResult::ok(['id' => $id], '保存成功');
        }
        $payload['status'] = $status ?? 1;
        $payload['created_at'] = AppTime::now();
        $newId = (int) ProductParamGroup::insertGetId($payload);

        return ServiceResult::ok(['id' => $newId], '保存成功');
    }

    /** @return ServiceResult */
    public static function deleteParamGroup(int $id): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数无效');
        }
        if ((int) ProductParamDef::where('group_id', $id)->count() > 0) {
            return ServiceResult::fail('组内仍有参数，请先删除或移走参数');
        }
        ProductParamGroup::where('id', $id)->delete();

        return ServiceResult::ok(null, '已删除');
    }

    /**
     * @param list<int> $ids
     * @return ServiceResult
     */
    public static function batchUpdateParamGroupStatus(array $ids, int $status): ServiceResult
    {
        $status = $status === 1 ? 1 : 0;
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return ServiceResult::fail('请选择参数组');
        }
        $n = ProductParamGroup::whereIn('id', $ids)->update([
            'status'     => $status,
            'updated_at' => AppTime::now(),
        ]);

        return ServiceResult::ok(['count' => $n], $status === 1 ? "已启用 {$n} 个参数组" : "已停用 {$n} 个参数组");
    }

    /**
     * @param list<int> $ids
     * @return ServiceResult
     */
    public static function batchDeleteParamGroups(array $ids): ServiceResult
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return ServiceResult::fail('请选择参数组');
        }
        $deleted = 0;
        $blocked = 0;
        foreach ($ids as $id) {
            $res = self::deleteParamGroup($id);
            if ($res->isOk()) {
                $deleted++;
            } else {
                $blocked++;
            }
        }
        if ($deleted < 1 && $blocked > 0) {
            return ServiceResult::fail('所选参数组均无法删除（组内仍有参数）');
        }

        return ServiceResult::ok(
            ['count' => $deleted, 'blocked' => $blocked],
            $blocked > 0
                ? "已删除 {$deleted} 个，{$blocked} 个因组内有参数未删"
                : "已删除 {$deleted} 个参数组",
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public static function saveParamDef(array $data): ServiceResult
    {
        $id = (int) ($data['id'] ?? 0);
        $groupId = (int) ($data['group_id'] ?? 0);
        $label = trim((string) ($data['label'] ?? ''));
        $inputType = trim((string) ($data['input_type'] ?? 'text'));
        if (!in_array($inputType, ['text', 'select', 'multi_select'], true)) {
            $inputType = 'text';
        }
        $filterable = !empty($data['filterable']) ? 1 : 0;
        $sort = (int) ($data['sort'] ?? 0);
        $defaultValue = trim((string) ($data['default_value'] ?? ''));
        $options = isset($data['options']) && is_array($data['options'])
            ? self::normalizeParamOptions($data['options'])
            : [];
        if ($label === '') {
            return ServiceResult::fail('参数名称不能为空');
        }
        if ($groupId < 1) {
            return ServiceResult::fail('请选择参数组');
        }
        if ((int) ProductParamGroup::where('id', $groupId)->count() < 1) {
            return ServiceResult::fail('参数组不存在');
        }
        $rawKey = trim((string) ($data['param_key'] ?? ''));
        if ($id > 0) {
            $key = (string) ProductParamDef::where('id', $id)->value('param_key');
            if ($key === '' && $rawKey !== '') {
                $key = self::resolveUniqueParamKey($rawKey, $label, $id);
            } elseif ($key === '') {
                $key = self::resolveUniqueParamKey('', $label, $id);
            }
        } else {
            $key = self::resolveUniqueParamKey($rawKey, $label, 0);
        }
        if ($key === '') {
            return ServiceResult::fail('无法生成参数字段标识，请换一个名称');
        }
        $optionValues = self::paramOptionValues($options);
        if ($defaultValue !== '' && $optionValues !== []) {
            if ($inputType === 'select' && !in_array($defaultValue, $optionValues, true)) {
                return ServiceResult::fail('默认值须在可选值列表中');
            }
            if ($inputType === 'multi_select') {
                $parts = preg_split('/[,，]/u', $defaultValue) ?: [];
                $norm = [];
                foreach ($parts as $part) {
                    $p = trim((string) $part);
                    if ($p === '') {
                        continue;
                    }
                    if (!in_array($p, $optionValues, true)) {
                        return ServiceResult::fail('默认值须在可选值列表中');
                    }
                    $norm[] = $p;
                }
                $defaultValue = implode(',', $norm);
            }
        }
        $payload = [
            'group_id'      => $groupId,
            'param_key'     => $key,
            'label'         => mb_substr($label, 0, 64),
            'input_type'    => $inputType,
            'options_json'  => json_encode($options, JSON_UNESCAPED_UNICODE),
            'filterable'    => $filterable,
            'default_value' => mb_substr($defaultValue, 0, 500),
            'sort'          => $sort,
            'updated_at'    => AppTime::now(),
        ];
        if ($id > 0) {
            ProductParamDef::where('id', $id)->update($payload);
            return ServiceResult::ok(['id' => $id], '保存成功');
        }
        $payload['created_at'] = AppTime::now();
        $newId = (int) ProductParamDef::insertGetId($payload);
        return ServiceResult::ok(['id' => $newId], '保存成功');
    }

    /** @return ServiceResult */
    public static function deleteParamDef(int $id): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数无效');
        }
        $key = (string) ProductParamDef::where('id', $id)->value('param_key');
        if ($key !== '' && ProductParamValidator::paramKeyInUse($key)) {
            return ServiceResult::fail('仍有品项使用该参数，无法删除');
        }
        ProductParamDef::where('id', $id)->delete();

        return ServiceResult::ok(null, '已删除');
    }

    private static function resolveUniqueGroupKey(string $rawKey, string $label, int $excludeId = 0): string
    {
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($rawKey))) ?? '';
        if ($key === '') {
            $key = self::labelToStorageKey($label, 'group');
        }

        return self::ensureUniqueStorageKey(
            $key,
            static function (string $candidate) use ($excludeId): bool {
                $query = ProductParamGroup::where('group_key', $candidate);
                if ($excludeId > 0) {
                    $query->where('id', '<>', $excludeId);
                }

                return (int) $query->count() > 0;
            },
            64,
        );
    }

    private static function resolveUniqueParamKey(string $rawKey, string $label, int $excludeId = 0): string
    {
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($rawKey))) ?? '';
        if ($key === '') {
            $key = self::labelToStorageKey($label, 'param');
        }

        return self::ensureUniqueStorageKey(
            $key,
            static function (string $candidate) use ($excludeId): bool {
                $query = ProductParamDef::where('param_key', $candidate);
                if ($excludeId > 0) {
                    $query->where('id', '<>', $excludeId);
                }

                return (int) $query->count() > 0;
            },
            64,
        );
    }

    private static function labelToStorageKey(string $label, string $prefix): string
    {
        $ascii = SlugHelper::asciiFromText($label, $prefix);
        $key   = preg_replace('/[^a-z0-9_]/', '_', str_replace('-', '_', $ascii)) ?? '';
        $key   = preg_replace('/_+/', '_', trim($key, '_')) ?? '';
        if ($key === '' || !preg_match('/^[a-z]/', $key)) {
            $key = $prefix . '_' . substr(md5($label), 0, 8);
        }

        return mb_substr($key, 0, 64);
    }

    /** @param callable(string): bool $exists */
    private static function ensureUniqueStorageKey(string $base, callable $exists, int $maxLen): string
    {
        $base = mb_substr(trim($base, '_'), 0, max(1, $maxLen - 4));
        if ($base === '') {
            $base = 'param';
        }
        $key = $base;
        $n   = 1;
        while ($exists($key)) {
            $suffix = '_' . $n++;
            $key    = mb_substr($base, 0, max(1, $maxLen - mb_strlen($suffix))) . $suffix;
        }

        return mb_substr($key, 0, $maxLen);
    }
}
