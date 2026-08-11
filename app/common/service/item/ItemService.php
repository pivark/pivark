<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);


namespace app\common\service\item;

use app\common\service\plugin\extension\PluginOfficialProduct;

use app\common\service\site\SiteNavService;

use app\common\support\AppTime;
use app\common\support\HtmlSanitizer;
use app\common\support\ServiceResult;

use app\common\service\event\EventBusService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\item\ItemPublicViewService;
use app\common\service\item\ItemAttrValueService;
use app\common\model\DocumentItemRef;
use app\common\model\ItemTag;

use app\common\service\document\DocumentAdminService;
use app\common\service\document\DocumentFormatService;
use app\common\service\product\OfferBridgeFacade;
use app\common\service\plugin\extension\PluginOfferBridgeRegistry;
use app\common\service\plugin\PluginService;
use app\common\service\product\ProductCenterGateService;
use app\common\service\product\ProductConfigService;
use app\common\service\search\ItemListSearchService;
use app\common\service\tag\TagCore;
use app\common\service\tag\TagService;
use app\common\service\tag\TagSlugIndexService;
use app\common\model\Document;
use app\common\model\Item;
use app\common\model\ItemNav;
use app\common\support\SiteUrl;
use app\common\support\QueryLimit;
use app\common\support\SlugHelper;
use think\facade\Config;
use think\facade\Db;

/** 品项中枢（AD-020） */
class ItemService
{

    public function __construct(
        private readonly TagService $tagService,
        private readonly ItemAttrValueService $itemAttrValueService,
        private readonly ItemListSearchService $itemListSearchService,
        private readonly ItemPublicViewService $itemPublicViewService,
        private readonly TagSlugIndexService $tagSlugIndexService,
        private readonly DocumentFormatService $documentFormatService,
        private readonly ItemPublicUrlService $itemPublicUrlService,
        private readonly ItemVariantService $itemVariantService,
    ) {
    }

    private function adminRowPresenter(): ItemAdminRowPresenter
    {
        return app(ItemAdminRowPresenter::class);
    }

    public const TYPE_PHYSICAL  = 'physical';
    public const TYPE_SERVICE   = 'service';
    public const TYPE_DIGITAL   = 'digital';
    public const TYPE_COMPONENT = 'component';
    public const TYPE_KIT       = 'kit';

    /** 能力标签绑定的 weapp 插件（未安装启用时不展示对应勾选项） */
    public const CAPABILITY_PLUGIN_ERP = 'erp';
    public const CAPABILITY_PLUGIN_MES = 'mes';

    public const STATUS_DRAFT        = 'draft';
    public const STATUS_ACTIVE       = 'active';
    public const STATUS_DISCONTINUED = 'discontinued';

    /** @return array<string, string> */
    public function typeLabels(): array
    {
        return [
            self::TYPE_PHYSICAL  => '实物',
            self::TYPE_SERVICE   => '服务',
            self::TYPE_DIGITAL   => '虚拟/数字',
            self::TYPE_COMPONENT => '零配件',
            self::TYPE_KIT       => '套件',
        ];
    }

    /** 品项类型卡片短说明（后台选择器） */
    /** @return array<string, string> */
    public function typeDescriptions(): array
    {
        return [
            self::TYPE_PHYSICAL  => '实体货品，需库存或发货',
            self::TYPE_SERVICE   => '按次/按期交付的服务',
            self::TYPE_DIGITAL   => '软件、授权、电子版',
            self::TYPE_COMPONENT => '备件、零件、辅件',
            self::TYPE_KIT       => '多品项组合套装',
        ];
    }

    /** 品项类型选中后的详细说明（后台表单提示） */
    /** @return array<string, string> */
    public function typeHints(): array
    {
        return [
            self::TYPE_PHYSICAL  => '适合整机、设备、耗材等需要实物交付的产品；可展示封面与参数，并关联详情文档。',
            self::TYPE_SERVICE   => '适合安装、维保、咨询等无实物交付的服务；前台多以服务说明或预约方式呈现。',
            self::TYPE_DIGITAL   => '适合会员、授权码、资料下载等虚拟交付物；无需物流，常用于数字商品。',
            self::TYPE_COMPONENT => '适合挂在主品项下的配件、备件或 BOM 子项；常与主型号搭配展示或对比。',
            self::TYPE_KIT       => '适合将多个品项打包成一套售卖；便于客户一次选购完整方案。',
        ];
    }

    /** 销售能力说明（后台表单） */
    public function capabilityFlagOverview(): string
    {
        return '勾选后可在产品目录展示，并供商城插件配置报价销售。'
            . '仅做对外/产品展示时，通常只勾这一项即可。';
    }

    /**
     * 品项表单字段说明（后台 · 用户向文案）
     *
     * @return array<string, array{label?:string, placeholder?:string, tooltip?:string, hint?:string}>
     */
    public function formFieldHints(): array
    {
        $erpOn = app(EntitlementService::class)->can(self::CAPABILITY_PLUGIN_ERP);
        $codeHint = '型号/系列编码（SPU）：代表这一款产品身份，用于对外展示与客户选型；全站不可重复。'
            . '具体可订货规格（颜色、电压、口径等）在「规格/订货号」维护，一条对应一个 variant_code。'
            . '不是仓库物料号，也不是商城售价 SKU 行。';
        if ($erpOn) {
            $codeHint .= '内部采购物料编码在 ERP 模块映射到规格订货号，不在此填写。';
        }

        return [
            'code' => [
                'label'       => '型号编码',
                'placeholder' => '如 PUMP-A100、CM-SERIES',
                'tooltip'     => '对外型号或系列锚点；具体订货号在规格层维护',
                'hint'        => $codeHint,
            ],
            'variants' => [
                'label'   => '规格/订货号',
                'tooltip' => '可订货的最小单元；客户报 variant_code 即可对准具体配置',
                'hint'    => '工业多规格时在此维护多条订货编码（全站唯一）。'
                    . '留空时按「产品中心 → 设置」的订货号规则自动生成，生成后可手改；改型号不会覆盖已有订货号。'
                    . '未装报价插件也可先维护规格；启用后叠加价格与库存。',
            ],
            'name' => [
                'label'       => '名称',
                'placeholder' => '如 立式离心泵、上门安装服务',
                'tooltip'     => '给客户看的产品/服务名称',
                'hint'        => '展示名称可与货号不同，建议写完整、易懂。',
            ],
            'slug' => [
                'label'       => '网址别名',
                'placeholder' => '留空则按货号自动生成',
                'tooltip'     => '品项独立页的 URL 路径',
                'hint'        => '仅小写字母、数字、-、_；留空时系统根据货号生成。',
            ],
            'cover' => [
                'hint' => '用于产品列表与卡片封面；不填时，前台可能回退到详情文档头图。',
            ],
            'status' => [
                'hint' => '草稿：仅后台可见；在售：前台产品目录可展示；停售：保留记录但不再对外展示。',
            ],
            'tags' => [
                'hint' => '用于产品目录筛选与分组，可按需多选。',
            ],
            'primary_document_id' => [
                'label'       => '详情文档',
                'placeholder' => '0 表示暂不关联',
                'tooltip'     => '已发布文档的数字 ID',
                'hint'        => '填写后，前台可出现「阅读详细介绍」链接。'
                    . '也可在文档编辑页的「产品展示」里反向关联本品项。',
            ],
            'sort' => [
                'hint' => '数字越小越靠前，用于列表与目录排序。',
            ],
        ];
    }

    /**
     * 品项能力模块（后台表单 · 按插件授权展示）
     *
     * @return array<string, array{visible:bool, plugin:string, pluginTitle:string, label:string, hint:string, overview:string}>
     */
    public function capabilityModules(): array
    {
        $entitled = static fn (string $plugin): bool => $plugin !== ''
            && app(EntitlementService::class)->can($plugin);
        $offerPlugin = app(PluginOfferBridgeRegistry::class)->identifier();
        $offerOn     = $offerPlugin !== null && app(EntitlementService::class)->can($offerPlugin);
        $offerTitle  = '';
        if ($offerOn && $offerPlugin !== null) {
            $manifest = app(PluginService::class)->readManifest($offerPlugin) ?? [];
            $offerTitle = trim((string) ($manifest['name'] ?? ''));
        }

        return [
            'sellable' => [
                'visible'      => $offerOn,
                'plugin'       => $offerOn ? (string) $offerPlugin : '',
                'pluginTitle'  => $offerTitle,
                'label'        => '可售',
                'hint'         => '前台可展示但不可售时关闭；开启后须配置 SKU 价格',
                'overview'     => $offerOn
                    ? '已装报价插件：关闭后前台仍可见产品信息，但不显示购买入口。'
                    : '',
            ],
            'purchasable' => [
                'visible'      => $entitled(self::CAPABILITY_PLUGIN_ERP),
                'plugin'       => self::CAPABILITY_PLUGIN_ERP,
                'pluginTitle'  => 'ERP',
                'label'        => '可购',
                'hint'         => '可向供应商采购；勾选后该品项纳入 ERP 采购与进货管理',
                'overview'     => '已启用 ERP 插件：勾选后可在采购模块中向供应商下单、管理进货。',
            ],
            'manufacturable' => [
                'visible'      => $entitled(self::CAPABILITY_PLUGIN_MES),
                'plugin'       => self::CAPABILITY_PLUGIN_MES,
                'pluginTitle'  => 'MES',
                'label'        => '可制造',
                'hint'         => '可纳入生产制造；勾选后该品项可用于 MES 工单、BOM 与领料',
                'overview'     => '已启用 MES 插件：勾选后可将该品项纳入生产工单、BOM 与装配流程。',
            ],
            'web_visible' => $this->webVisibleCapabilityModule(),
        ];
    }

    /** @return array{visible:bool, plugin:string, pluginTitle:string, label:string, hint:string, overview:string} */
    private function webVisibleCapabilityModule(): array
    {
        $admin = Config::get('item_public_visibility.admin.web_visible', []);
        if (!is_array($admin)) {
            $admin = [];
        }

        return [
            'visible'     => true,
            'plugin'      => '',
            'pluginTitle' => '',
            'label'       => '前台显示',
            'hint'        => '与文档「发布」类似：开启后可在产品目录与详情页展示',
            'overview'    => (string) ($admin['overview'] ?? '未开启时为草稿，前台不可见。'),
        ];
    }

    /** @return array<string, string> */
    public function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT        => '草稿',
            self::STATUS_ACTIVE       => '在售',
            self::STATUS_DISCONTINUED => '停售',
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{list:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function listPublic(array $params = []): array
    {
        return app(ItemPublicListService::class)->list($this, $params);
    }

    public function forgetListPublicRequestCache(): void
    {
        app(ItemPublicListService::class)->forgetRequestCache();
    }

    /**
     * @param list<int> $itemIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function tagRowsMapForItems(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if ($itemIds === []) {
            return [];
        }
        $links = ItemTag::whereIn('item_id', $itemIds)->order('id', 'asc')->select()->toArray();
        if ($links === []) {
            return [];
        }
        $tagIds = array_values(array_unique(array_map(static fn (array $l): int => (int) ($l['tag_id'] ?? 0), $links)));
        $tagRows = [];
        foreach ($this->tagSlugIndexService->rowsByIds($tagIds) as $id => $row) {
            $tagRows[$id] = $row;
        }
        if (count($tagRows) < count($tagIds)) {
            foreach (\app\common\model\Tag::whereIn('id', $tagIds)->where('status', 1)->select()->toArray() as $row) {
                $tagRows[(int) ($row['id'] ?? 0)] = $row;
            }
        }
        $out = array_fill_keys($itemIds, []);
        foreach ($links as $link) {
            $itemId = (int) ($link['item_id'] ?? 0);
            $tagId  = (int) ($link['tag_id'] ?? 0);
            if (isset($tagRows[$tagId])) {
                $out[$itemId][] = $tagRows[$tagId];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPublicBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }
        if (!\app\common\service\product\ProductCenterGateService::publicSurfaceOpen()) {
            return null;
        }
        $row = Item::where('slug', $slug)->where('status', self::STATUS_ACTIVE)->find()?->toArray();
        if ($row === null || !app(ItemPublicVisibilityService::class)->isVisibleOnWww($row)) {
            return null;
        }

        return $this->itemPublicViewService->enrichRow($row, true);
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function listPublicByIds(array $ids): array
    {
        return $this->itemPublicViewService->listByIds($ids);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRelatedPublic(int $itemId, int $limit = QueryLimit::RELATED_ITEMS): array
    {
        return $this->itemPublicViewService->listRelated($itemId, $limit);
    }

    public function resolvePublicPageUrl(string $slug): string
    {
        return $this->itemPublicUrlService->productItemPage($slug);
    }

    public function resolvePublicCoverUrl(string $pathOrUrl, string $title = ''): string
    {
        return $this->itemPublicViewService->resolveCoverUrl($pathOrUrl, $title);
    }

    public function resolvePublicDetailUrl(int $documentId): string
    {
        if ($documentId < 1) {
            return '';
        }
        $doc = Document::where('id', $documentId)->where('status', 1)->find()?->toArray();

        return is_array($doc) ? $this->documentFormatService->buildPublicDocumentUrl($doc) : '';
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function listAdminExportRows(array $filters = [], array $ids = []): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0,
        )));

        $query = Item::order('sort', 'asc')->order('id', 'desc');
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } else {
            $keyword = trim((string) ($filters['keyword'] ?? ''));
            if ($keyword !== '') {
                $query->where(function ($q) use ($keyword) {
                    $q->whereLike('code', '%' . $keyword . '%')
                        ->whereOr('name', 'like', '%' . $keyword . '%')
                        ->whereOr('slug', 'like', '%' . $keyword . '%');
                });
            }
            $itemType = trim((string) ($filters['item_type'] ?? ''));
            if ($itemType !== '' && isset($this->typeLabels()[$itemType])) {
                $query->where('item_type', $itemType);
            }
            $status = trim((string) ($filters['status'] ?? ''));
            if ($status !== '' && isset($this->statusLabels()[$status])) {
                $query->where('status', $status);
            }
            $tagId = (int) ($filters['tag_id'] ?? 0);
            if ($tagId > 0) {
                $itemIds = ItemTag::where('tag_id', $tagId)->column('item_id');
                if ($itemIds === []) {
                    return [];
                }
                $query->whereIn('id', $itemIds);
            }
        }

        $out = [];
        foreach ($query->select()->toArray() as $row) {
            $out[] = $this->adminRowPresenter()->formatRow($this, $row);
        }

        return $out;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function formatPublicRow(array $row, bool $detail = false): array
    {
        return $this->itemPublicViewService->enrichRow($row, $detail);
    }

    /**
     * @param array{keyword?:string,page?:int,limit?:int} $params
     * @return array{list:list<array<string,mixed>>,total:int}
     */
    public function listAdmin(array $params = []): array
    {
        $keyword = trim((string) ($params['keyword'] ?? ''));
        $page    = max(1, (int) ($params['page'] ?? 1));
        $limit   = max(1, min(200, (int) ($params['limit'] ?? 20)));
        $catalogLine = trim((string) ($params['catalog_line'] ?? ''));
        $pluginKind  = trim((string) ($params['plugin_kind'] ?? ''));
        $itemOrigin  = preg_replace('/[^a-z_]/', '', strtolower(trim((string) ($params['item_origin'] ?? '')))) ?? '';

        $query = Item::order('sort', 'asc')->order('id', 'desc');
        $status = trim((string) ($params['status'] ?? ''));
        if ($status !== '' && isset($this->statusLabels()[$status])) {
            $query->where('status', $status);
        } else {
            // 默认不列已停售（旧官方品项退役后仍占列表）
            $query->where('status', '<>', 'discontinued');
        }
        $itemType = trim((string) ($params['item_type'] ?? ''));
        if ($itemType !== '' && isset($this->typeLabels()[$itemType])) {
            $query->where('item_type', $itemType);
        }
        if ($keyword !== '') {
            $this->itemListSearchService->applyKeywordFilter($query, $keyword, $params);
        }
        $tagId = (int) ($params['tag_id'] ?? 0);
        $navId = max(0, (int) ($params['nav_id'] ?? 0));
        // 主栏目或附加栏目命中（含子孙）
        if ($navId > 0) {
            $navIds = app(SiteNavService::class)->contentCategorySelfAndDescendantIds($navId);
            app(SiteNavService::class)->applyPrimaryOrExtraNavFilter($query, $navIds, 'item');
        }
        app(\app\common\service\admin\AdminTagScopeService::class)->applyNavQueryScope($query, 'nav_id', 'item');
        if ($tagId < 1) {
            $tagSlug = trim((string) ($params['tag'] ?? ''));
            if ($tagSlug !== '') {
                $tagRow = app(TagService::class)->findRowBySlug($tagSlug)
                    ?? app(TagService::class)->findRowByName($tagSlug);
                $tagId = (int) ($tagRow['id'] ?? 0);
            }
        }
        if ($tagId > 0) {
            // Tag 仅聚合：与 nav_id 可 AND
            $tagIds = app(TagCore::class)->idsWithDescendants([$tagId]);
            if ($tagIds === []) {
                $tagIds = [$tagId];
            }
            $query->whereIn('id', static function ($sub) use ($tagIds): void {
                $sub->name('item_tags')->whereIn('tag_id', $tagIds)->field('item_id');
            });
        }

        if (in_array($itemOrigin, ['official', 'merchant', 'manual'], true)) {
            PluginOfficialProduct::dispatch(
                'item_origin_apply_filter',
                ['query' => $query, 'origin' => $itemOrigin],
                null,
            );
        }

        if ($catalogLine !== '') {
            // 产线/kind JSON 筛选只在宿主（www OfficialCatalogService）；禁内核硬写 official_catalog.*
            PluginOfficialProduct::dispatch(
                'catalog_apply_list_filter',
                ['query' => $query, 'line' => $catalogLine, 'plugin_kind' => $pluginKind],
                null,
            );
        }

        $allowSkipCount = $keyword === ''
            && $tagId < 1
            && $navId < 1
            && $catalogLine === ''
            && $pluginKind === ''
            && $itemOrigin === '';

        $hasMore = 0;
        if ($allowSkipCount) {
            $offset = ($page - 1) * $limit;
            $fetch = (clone $query)
                ->limit($offset, $limit + 1)
                ->select()
                ->toArray();
            $hasMore = count($fetch) > $limit ? 1 : 0;
            $rows = $hasMore === 1 ? array_slice($fetch, 0, $limit) : $fetch;
            $total = -1;
        } else {
            $paginator = $query->paginate([
                'list_rows' => $limit,
                'page'      => $page,
            ]);
            $total = (int) $paginator->total();
            $rows  = [];
            foreach ($paginator->items() as $item) {
                $rows[] = is_array($item) ? $item : $item->toArray();
            }
            $hasMore = ($page * $limit) < $total ? 1 : 0;
        }
        $itemIds     = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rows);
        $tagMap      = $this->tagRowsMapForItems($itemIds);
        $offerMap    = app(OfferBridgeFacade::class)->adminListOverlayByItemIds($itemIds);
        $marketMap   = $this->adminRowPresenter()->marketplaceListingOverlayByItemIds($itemIds);
        $variantMeta = $this->itemVariantService->adminListMetaByItemIds($itemIds);
        $docIds = [];
        foreach ($rows as $row) {
            $did = (int) ($row['primary_document_id'] ?? 0);
            if ($did > 0) {
                $docIds[$did] = true;
            }
        }
        $docLitpicMap = $docIds === []
            ? []
            : Document::whereIn('id', array_keys($docIds))->column('litpic', 'id');
        $extraNavMap = $this->extraNavIdsByItemIds($itemIds);
        $out         = [];
        foreach ($rows as $row) {
            $itemId = (int) ($row['id'] ?? 0);
            $did = (int) ($row['primary_document_id'] ?? 0);
            if ($did > 0) {
                $row['_primary_doc_litpic'] = (string) ($docLitpicMap[$did] ?? '');
            }
            $tagRows = $tagMap[$itemId] ?? [];
            $row['_admin_tag_ids'] = array_map(
                static fn (array $t): int => (int) ($t['id'] ?? 0),
                $tagRows
            );
            $row['_admin_extra_nav_ids'] = $extraNavMap[$itemId] ?? [];
            $row['_admin_variant_count'] = isset($variantMeta[$itemId])
                ? (int) ($variantMeta[$itemId]['count'] ?? 0)
                : 0;
            $formatted = $this->adminRowPresenter()->formatRow($this, $row);
            if (isset($variantMeta[$itemId])) {
                $formatted['variant_count'] = $variantMeta[$itemId]['count'];
                if ($variantMeta[$itemId]['count'] > 1) {
                    $formatted['variant_preview'] = [
                        'labels' => $variantMeta[$itemId]['labels'],
                        'more'   => $variantMeta[$itemId]['more'],
                    ];
                }
            }
            $formatted['tags'] = $this->adminRowPresenter()->formatAdminTagRows($tagRows);
            if (isset($offerMap[$itemId])) {
                $formatted['offer'] = $offerMap[$itemId];
            }
            if (isset($marketMap[$itemId])) {
                $formatted['marketplace_listing'] = $marketMap[$itemId];
            }
            $formatted += $this->adminRowPresenter()->itemOriginMeta($row);
            $out[] = $formatted;
        }

        return ['list' => $out, 'total' => $total, 'has_more' => $hasMore];
    }

    /**
     * @param list<int> $itemIds
     * @return array<int, list<int>>
     */
    private function extraNavIdsByItemIds(array $itemIds): array
    {
        $itemIds = array_values(array_filter(array_map('intval', $itemIds)));
        if ($itemIds === []) {
            return [];
        }
        $raw = ItemNav::whereIn('item_id', $itemIds)
            ->order('id', 'asc')
            ->field('item_id,nav_id')
            ->select()
            ->toArray();
        $out = [];
        foreach ($raw as $r) {
            $iid = (int) ($r['item_id'] ?? 0);
            $nid = (int) ($r['nav_id'] ?? 0);
            if ($iid < 1 || $nid < 1) {
                continue;
            }
            $out[$iid][] = $nid;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $gate = app(ItemCapabilityGate::class)->guardAdminWrite();
        if ($gate !== null) {
            return $gate;
        }

        $id        = (int) ($data['id'] ?? 0);
        $code      = trim((string) ($data['code'] ?? ''));
        $name      = trim((string) ($data['name'] ?? ''));
        $slug      = trim((string) ($data['slug'] ?? ''));
        $itemType  = trim((string) ($data['item_type'] ?? self::TYPE_PHYSICAL));
        $status    = trim((string) ($data['status'] ?? self::STATUS_DRAFT));
        $sort      = (int) ($data['sort'] ?? 0);
        $primaryDoc = (int) ($data['primary_document_id'] ?? 0);
        $navId     = max(0, (int) ($data['nav_id'] ?? 0));
        if ($navId < 1 && $id > 0 && !array_key_exists('nav_id', $data)) {
            $navId = (int) (Item::where('id', $id)->value('nav_id') ?? 0);
        }
        if ($status !== self::STATUS_DRAFT && $navId < 1) {
            return ServiceResult::fail('请选择栏目（品项必须挂在栏目上）');
        }
        if ($navId > 0 && !app(SiteNavService::class)->isContentCategoryId($navId)) {
            return ServiceResult::fail('所选分类不可挂载品项（请选文章/产品分类）');
        }
        if ($navId > 0) {
            $deny = app(\app\common\service\admin\AdminTagScopeService::class)->assertCurrentCanManageNav($navId);
            if ($deny !== null) {
                return $deny;
            }
        }
        $extraNavIds = [];
        if (array_key_exists('extra_nav_ids', $data)) {
            $extraNavIds = app(SiteNavService::class)->normalizeExtraNavIds($data['extra_nav_ids'] ?? [], $navId);
            foreach ($extraNavIds as $extraNavId) {
                $deny = app(\app\common\service\admin\AdminTagScopeService::class)->assertCurrentCanManageNav($extraNavId);
                if ($deny !== null) {
                    return $deny;
                }
            }
        } elseif ($id > 0) {
            $extraNavIds = app(SiteNavService::class)->normalizeExtraNavIds(
                app(SiteNavService::class)->listItemExtraNavIds($id),
                $navId
            );
        }
        // Tag 仅聚合：空保持空；禁止因 nav_id 代挂 Tag
        $tagIds = isset($data['tag_ids']) && is_array($data['tag_ids'])
            ? array_values(array_unique(array_map('intval', $data['tag_ids'])))
            : [];
        $now = AppTime::now();

        if ($code === '' || $name === '') {
            return ServiceResult::fail('型号编码与名称不能为空');
        }
        if (!isset($this->typeLabels()[$itemType])) {
            return ServiceResult::fail('品项类型无效');
        }
        if (!isset($this->statusLabels()[$status])) {
            return ServiceResult::fail('状态无效');
        }
        if ($slug === '') {
            $slug = $this->slugify($code !== '' ? $code : $name);
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,118}$/', $slug)) {
            return ServiceResult::fail('slug 仅允许小写字母、数字、-、_');
        }

        $dupCode = Item::where('code', $code);
        $dupSlug = Item::where('slug', $slug);
        if ($id > 0) {
            $dupCode->where('id', '<>', $id);
            $dupSlug->where('id', '<>', $id);
        }
        if ($dupCode->count() > 0) {
            return ServiceResult::fail('型号编码已存在');
        }
        if ($dupSlug->count() > 0) {
            return ServiceResult::fail('slug 已存在');
        }

        $prevFlags = [];
        if ($id > 0) {
            $existing = Item::where('id', $id)->find();
            if ($existing) {
                $rawFlags = $existing['flags'] ?? [];
                $prevFlags = is_array($rawFlags) ? $rawFlags : [];
            }
        }

        $flags = [
            'sellable'       => !empty($data['flag_sellable']) ? 1 : 0,
            'purchasable'    => !empty($data['flag_purchasable']) ? 1 : 0,
            'manufacturable' => !empty($data['flag_manufacturable']) ? 1 : 0,
        ];
        if (array_key_exists('flag_web_visible', $data)) {
            $flags['web_visible'] = !empty($data['flag_web_visible']) ? 1 : 0;
        } elseif (array_key_exists('web_visible', $prevFlags)) {
            $flags['web_visible'] = !empty($prevFlags['web_visible']) ? 1 : 0;
        } else {
            $flags['web_visible'] = $flags['sellable'];
        }
        if (array_key_exists('flag_market_featured', $data)) {
            $flags['market_featured'] = !empty($data['flag_market_featured']) ? 1 : 0;
        } elseif (array_key_exists('market_featured', $prevFlags)) {
            $flags['market_featured'] = !empty($prevFlags['market_featured']) ? 1 : 0;
        }
        $attrs = isset($data['attrs']) && is_array($data['attrs']) ? $data['attrs'] : [];
        // 插件目录筛字段写路径：缺省 product_line/accuracy/output_signal → attrs → EAV
        $attrs = app(\app\common\service\catalog\CatalogFacetPathService::class)
            ->ensurePluginFilterAttrsOnItemAttrs($attrs, $code);
        $attrs = app(\app\common\service\catalog\CatalogFacetPathService::class)
            ->ensureTemplateFilterAttrsOnItemAttrs($attrs, $code);

        $payload = Item::withoutGhostColumns([
            'code'                => mb_substr($code, 0, 64),
            'name'                => mb_substr($name, 0, 200),
            'slug'                => mb_substr($slug, 0, 120),
            'item_type'           => $itemType,
            'status'              => $status,
            'attrs'               => $attrs,
            'flags'               => $flags,
            'primary_document_id' => max(0, $primaryDoc),
            'nav_id'              => $navId,
            'sort'                => $sort,
            'updated_at'          => $now,
        ]);

        Db::startTrans();
        try {
            $prevStatus = '';
            if ($id > 0) {
                $prev = Item::where('id', $id)->find();
                if (!$prev) {
                    Db::rollback();

                    return ServiceResult::fail('品项不存在');
                }
                $prevStatus = (string) ($prev['status'] ?? '');
                Item::where('id', $id)->update($payload);
                $this->syncItemTags($id, $tagIds);
                app(SiteNavService::class)->replaceItemExtraNavs($id, $extraNavIds);
                $this->itemAttrValueService->syncFromAttrs($id, $attrs);
                if ($this->shouldEnsureDefaultVariant($attrs)) {
                    $this->itemVariantService->ensureDefaultForItem($id, $code, $name, $status);
                }
                Db::commit();
                $this->syncFilterFacetsAfterAttrs($id);
                $this->dispatchItemEvents($id, $slug, $status, $prevStatus);
                $fresh = Item::where('id', $id)->find();
                $this->dispatchItemAfterSaveExtensions(
                    $id,
                    false,
                    is_object($fresh) ? $fresh->toArray() : $payload,
                    $prevStatus,
                );

                return ServiceResult::ok(['id' => $id], '保存成功');
            }

            $payload['created_at'] = $now;
            $newId = (int) Item::insertGetId($payload);
            $this->syncItemTags($newId, $tagIds);
            app(SiteNavService::class)->replaceItemExtraNavs($newId, $extraNavIds);
            $this->itemAttrValueService->syncFromAttrs($newId, $attrs);
            if ($this->shouldEnsureDefaultVariant($attrs)) {
                $this->itemVariantService->ensureDefaultForItem($newId, $code, $name, $status);
            }
            Db::commit();
            $this->syncFilterFacetsAfterAttrs($newId);
            $this->dispatchItemEvents($newId, $slug, $status, '');
            $freshNew = Item::where('id', $newId)->find();
            $this->dispatchItemAfterSaveExtensions(
                $newId,
                true,
                is_object($freshNew) ? $freshNew->toArray() : $payload,
                '',
            );

            return ServiceResult::ok(['id' => $newId], '保存成功');
        } catch (\Throwable $e) {
            Db::rollback();

            return ServiceResult::fail('保存失败：' . $e->getMessage());
        }
    }

    /**
     * 文档「产品展示」Tab：仅更新 attrs（型号/状态等仍在产品中心维护）
     *
     * @param array<string, mixed> $attrs
     */
    public function saveAdminAttrs(int $itemId, array $attrs): ServiceResult
    {
        $gate = app(ItemCapabilityGate::class)->guardAdminWrite();
        if ($gate !== null) {
            return $gate;
        }

        if ($itemId < 1) {
            return ServiceResult::fail('品项无效');
        }
        if (!Item::where('id', $itemId)->find()) {
            return ServiceResult::fail('品项不存在');
        }

        $clean = [];
        foreach ($attrs as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $clean[$key] = is_scalar($value) ? trim((string) $value) : '';
        }

        try {
            Db::startTrans();
            Item::where('id', $itemId)->update([
                'attrs'      => $clean,
                'updated_at' => AppTime::now(),
            ]);
            $this->itemAttrValueService->syncFromAttrs($itemId, $clean);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();

            return ServiceResult::fail('参数保存失败：' . $e->getMessage());
        }
        $this->syncFilterFacetsAfterAttrs($itemId);

        return ServiceResult::ok(['id' => $itemId], 'ok');
    }

    /**
     * 文档「产品展示」Tab：类型 + 参数值；无绑定品项时按文档标题自动建主品项
     *
     * @param array<string, mixed> $postSnapshot 产品 Tab POST 快照（扩展点 dispatch，L1 不解析插件字段）
     */
    public function persistProductTabForDocument(
        int $documentId,
        int $itemId,
        string $itemType,
        array $attrs,
        string $documentTitle,
        string $itemName = '',
        string $itemCode = '',
        array $postSnapshot = [],
    ): ServiceResult {
        return app(ItemDocumentProductPersistService::class)->persistTab(
            $this,
            $documentId,
            $itemId,
            $itemType,
            $attrs,
            $documentTitle,
            $itemName,
            $itemCode,
            $postSnapshot,
        );
    }

    /**
     * 文档产品 Tab · 多规格：1 品项 + 多行 item_variants（spec_map 存参数值）
     *
     * @param list<array<string, mixed>> $variants
     * @param array<string, mixed> $postSnapshot
     */
    public function persistProductMultiSpecForDocument(
        int $documentId,
        int $itemId,
        string $itemType,
        array $variants,
        string $documentTitle,
        array $postSnapshot = [],
    ): ServiceResult {
        return app(ItemDocumentProductPersistService::class)->persistMultiSpec(
            $this,
            $documentId,
            $itemId,
            $itemType,
            $variants,
            $documentTitle,
            $postSnapshot,
        );
    }

    /**
     * 详情文档尚无主品项时，按文档标题创建并绑定
     *
     * @return ServiceResult data: id, created(bool)
     */
    public function ensurePrimaryItemForDocument(int $documentId, string $title, string $itemType): ServiceResult
    {
        if ($documentId < 1) {
            return ServiceResult::fail('文档无效');
        }

        $existing = $this->primaryItemRowForDocument($documentId);
        if (is_array($existing)) {
            return ServiceResult::ok(
                ['id' => (int) ($existing['id'] ?? 0), 'created' => false],
                '已有主品项',
            );
        }

        $name = trim($title) !== '' ? trim($title) : ('详情文档 #' . $documentId);
        $code = 'pv-doc-' . $documentId;
        if (Item::where('code', $code)->count() > 0) {
            $code = 'pv-doc-' . $documentId . '-' . substr(md5(AppTime::now()), 0, 4);
        }

        $navId = (int) (Document::where('id', $documentId)
            ->whereNull('deleted_at')
            ->value('nav_id') ?: 0);

        $saved = $this->saveAdmin([
            'code'                => $code,
            'name'                => $name,
            'item_type'           => $itemType,
            'status'              => self::STATUS_ACTIVE,
            'primary_document_id' => $documentId,
            'nav_id'              => $navId,
            'flag_sellable'       => 1,
            'flag_web_visible'    => 1,
            'attrs'               => [],
        ]);
        if (!$saved->isOk()) {
            return $saved;
        }

        $newId = (int) ($saved->dataArray()['id'] ?? 0);

        return ServiceResult::ok(['id' => $newId, 'created' => true], '已创建主品项');
    }

    /**
     * 按详情文档反查主品项（primary_document_id 优先，否则 document_item_refs 首项）
     *
     * @return array<string, mixed>|null
     */
    public function primaryItemRowForDocument(int $documentId): ?array
    {
        if ($documentId < 1) {
            return null;
        }

        $primary = Item::where('primary_document_id', $documentId)->find();
        if ($primary) {
            return is_array($primary) ? $primary : $primary->toArray();
        }

        $ref = DocumentItemRef::where('document_id', $documentId)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->find();
        if (!$ref) {
            return null;
        }
        $itemId = (int) ($ref['item_id'] ?? 0);
        if ($itemId < 1) {
            return null;
        }
        $row = Item::where('id', $itemId)->find();

        return $row ? (is_array($row) ? $row : $row->toArray()) : null;
    }

    /**
     * 在售品项绑定的详情文档 ID（导入时常与品项同名，站内搜索文章区应排除以免与品项卡重复）
     *
     * @return list<int>
     */
    public static function activePrimaryDocumentIds(): array
    {
        $ids = Item::where('status', self::STATUS_ACTIVE)
            ->where('primary_document_id', '>', 0)
            ->column('primary_document_id');

        return array_values(array_unique(array_filter(
            array_map('intval', is_array($ids) ? $ids : []),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * @return ServiceResult
     */
    public function deleteAdmin(int $id): ServiceResult
    {
        $gate = app(ItemCapabilityGate::class)->guardAdminWrite();
        if ($gate !== null) {
            return $gate;
        }

        if ($id < 1 || !Item::where('id', $id)->find()) {
            return ServiceResult::fail('品项不存在');
        }
        $row = Item::where('id', $id)->find()?->toArray();
        $slug = is_array($row) ? trim((string) ($row['slug'] ?? '')) : '';

        Db::startTrans();
        try {
            ItemTag::where('item_id', $id)->delete();
            $this->itemAttrValueService->deleteForItem($id);
            $this->itemVariantService->deleteForItem($id);
            DocumentItemRef::where('item_id', $id)->delete();
            Item::where('id', $id)->delete();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();

            return ServiceResult::fail('删除失败：' . $e->getMessage());
        }

        app(EventBusService::class)->dispatch('item.deleted', [
            'item_id' => $id,
            'slug'    => $slug,
        ]);

        return ServiceResult::ok(null, '删除成功');
    }

    /**
     * @param list<int> $ids
     */
    public function batchDeleteAdmin(array $ids): ServiceResult
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return ServiceResult::fail('请选择品项');
        }
        $deleted = 0;
        foreach ($ids as $id) {
            if ($this->deleteAdmin($id)->isOk()) {
                $deleted++;
            }
        }
        if ($deleted < 1) {
            return ServiceResult::fail('没有可删除的品项');
        }

        return ServiceResult::ok(['count' => $deleted], "已删除 {$deleted} 条品项");
    }

    /**
     * @param list<int> $ids
     */
    public function batchStatusAdmin(array $ids, string $status): ServiceResult
    {
        if (!isset($this->statusLabels()[$status])) {
            return ServiceResult::fail('状态无效');
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return ServiceResult::fail('请选择品项');
        }
        $now = AppTime::now();
        $n   = Item::whereIn('id', $ids)->update([
            'status'     => $status,
            'updated_at' => $now,
        ]);
        foreach ($ids as $id) {
            $row = Item::where('id', $id)->find()?->toArray();
            if (!$row) {
                continue;
            }
            app(EventBusService::class)->dispatch('item.status_changed', [
                'item_id' => $id,
                'slug'    => (string) ($row['slug'] ?? ''),
                'status'  => $status,
            ]);
        }

        return ServiceResult::ok(['count' => $n], "已更新 {$n} 条品项状态");
    }

    /**
     * @return ServiceResult
     */
    public function duplicateAdmin(int $id): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数错误');
        }
        $row = Item::where('id', $id)->find();
        if (!$row) {
            return ServiceResult::fail('品项不存在');
        }
        $formatted = $this->adminRowPresenter()->formatRow($this, $row->toArray());
        $code = $this->uniqueDuplicateField('code', (string) $formatted['code']);
        $slug = $this->uniqueDuplicateField('slug', (string) $formatted['slug']);
        $name = (string) $formatted['name'];
        if ($name !== '') {
            $name .= ' (副本)';
        }

        $res = $this->saveAdmin([
            'code'                => $code,
            'name'                => $name,
            'slug'                => $slug,
            'item_type'           => (string) $formatted['item_type'],
            'status'              => self::STATUS_DRAFT,
            'sort'                => (int) $formatted['sort'],
            'primary_document_id' => (int) $formatted['primary_document_id'],
            'tag_ids'             => $formatted['tag_ids'],
            'flag_sellable'       => (int) $formatted['flag_sellable'],
            'flag_purchasable'    => (int) $formatted['flag_purchasable'],
            'flag_manufacturable' => (int) $formatted['flag_manufacturable'],
            'attrs'               => $formatted['attrs'],
        ]);
        if ($res->isOk()) {
            $newId = (int) ($res->dataArray()['id'] ?? 0);
            if ($newId > 0) {
                $this->itemVariantService->deleteForItem($newId);
                $this->itemVariantService->copyFromItem($id, $newId, $code);
                if ($this->itemVariantService->countByItemId($newId) === 0) {
                    $this->itemVariantService->ensureDefaultForItem(
                        $newId,
                        $code,
                        $name,
                        self::STATUS_DRAFT,
                    );
                }
            }
        }

        return $res;
    }

    private function uniqueDuplicateField(string $column, string $base): string
    {
        $base = trim($base);
        if ($base === '') {
            $base = 'item';
        }
        $candidate = $base . '-copy';
        $n         = 2;
        while (Item::where($column, $candidate)->find()) {
            $candidate = $base . '-copy-' . $n;
            $n++;
        }

        return mb_substr($candidate, 0, $column === 'code' ? 64 : 120);
    }

    /**
     * @param list<int> $tagIds
     */
    private function syncItemTags(int $itemId, array $tagIds): void
    {
        ItemTag::where('item_id', $itemId)->delete();
        foreach ($tagIds as $tagId) {
            if ($tagId < 1) {
                continue;
            }
            ItemTag::insert([
                'item_id'    => $itemId,
                'tag_id'     => $tagId,
                'created_at' => AppTime::now(),
            ]);
        }
    }

    private function slugify(string $text): string
    {
        $slug = SlugHelper::asciiFromText($text, 'item');

        return $slug !== '' ? $slug : 'item-' . time();
    }


    /** @param array<string, mixed> $attrs */
    private function shouldEnsureDefaultVariant(array $attrs): bool
    {
        $result = PluginOfficialProduct::dispatch(
            'item_should_ensure_default_variant',
            ['attrs' => $attrs],
            null,
        );

        return is_bool($result) ? $result : true;
    }


    /**
     * 列表快捷切换：前台显示 / 可售 / 市场推荐（官方插件品项 → catalog 投影）
     *
     * @return ServiceResult
     */
    public function patchListVisibilityAdmin(int $id, string $field, int $value): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('品项无效');
        }
        $field = trim($field);
        $on    = $value === 1;
        $row   = Item::where('id', $id)->find();
        if (!$row) {
            return ServiceResult::fail('品项不存在');
        }

        $rawFlags = $row['flags'] ?? [];
        $flags    = is_array($rawFlags) ? $rawFlags : [];
        $now      = AppTime::now();

        if ($field === 'front_visible' || $field === 'market_shelf') {
            // market_shelf：官方货架闸 = 品项在售态（与 overlay on_shelf 对齐）；复用 web_visible + status，刷 after_save
            if ((string) ($row['status'] ?? '') === self::STATUS_DISCONTINUED && $on) {
                return ServiceResult::fail(
                    $field === 'market_shelf'
                        ? '已停售型号请先恢复为草稿后再上架'
                        : '已停售型号请先恢复为草稿后再前台显示',
                );
            }
            $flags['web_visible'] = $on ? 1 : 0;
            Item::where('id', $id)->update([
                'status'     => $on ? self::STATUS_ACTIVE : self::STATUS_DRAFT,
                'flags'      => $flags,
                'updated_at' => $now,
            ]);
        } elseif ($field === 'sellable') {
            if (!app(OfferBridgeFacade::class)->enabled()) {
                return ServiceResult::fail('未安装或未启用报价插件');
            }
            $flags['sellable'] = $on ? 1 : 0;
            Item::where('id', $id)->update([
                'flags'      => $flags,
                'updated_at' => $now,
            ]);
            app(OfferBridgeFacade::class)->syncSellableSkuStatusForItem($id, $on);
        } elseif ($field === 'market_featured') {
            $flags['market_featured'] = $on ? 1 : 0;
            Item::where('id', $id)->update([
                'flags'      => $flags,
                'updated_at' => $now,
            ]);
        } else {
            return ServiceResult::fail('不支持的字段');
        }

        $fresh = Item::where('id', $id)->find()?->toArray() ?? [];

        if ($field === 'market_featured' || $field === 'market_shelf') {
            $this->dispatchItemAfterSaveExtensions($id, false, $fresh, (string) ($row['status'] ?? ''));
        }

        $formatted = $this->adminRowPresenter()->formatRow($this, $fresh);
        if ($field === 'market_shelf') {
            $marketMap = $this->adminRowPresenter()->marketplaceListingOverlayByItemIds([$id]);
            if (isset($marketMap[$id])) {
                $formatted['marketplace_listing'] = $marketMap[$id];
            }
            $formatted += $this->adminRowPresenter()->itemOriginMeta($fresh);
        }

        return ServiceResult::ok($formatted, '已更新');
    }

    /**
     * 列表快捷改排序
     *
     * @return ServiceResult
     */
    public function patchListSortAdmin(int $id, int $sort): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('品项无效');
        }
        if ($sort < 0) {
            $sort = 0;
        }
        $row = Item::where('id', $id)->find();
        if (!$row) {
            return ServiceResult::fail('品项不存在');
        }
        Item::where('id', $id)->update([
            'sort'       => $sort,
            'updated_at' => AppTime::now(),
        ]);
        $fresh = Item::where('id', $id)->find()?->toArray() ?? [];

        return ServiceResult::ok($this->adminRowPresenter()->formatRow($this, $fresh), '已更新');
    }

    public function patchMarketplaceListingPriceAdmin(int $id, float $price): ServiceResult
    {
        $result = PluginOfficialProduct::dispatch(
            'patch_listing_price',
            ['item_id' => $id, 'price' => $price],
            null,
        );
        if (!$result instanceof ServiceResult) {
            return ServiceResult::fail('应用市场改价仅 host_only 发行形态可用');
        }
        if (!$result->isOk()) {
            return $result;
        }

        $fresh = Item::where('id', $id)->find()?->toArray() ?? [];
        if ($fresh === []) {
            return ServiceResult::fail('品项不存在');
        }

        $formatted = $this->adminRowPresenter()->formatRow($this, $fresh);
        $marketMap = $this->adminRowPresenter()->marketplaceListingOverlayByItemIds([$id]);
        if (isset($marketMap[$id])) {
            $formatted['marketplace_listing'] = $marketMap[$id];
        }

        return ServiceResult::ok($formatted, (string) ($result->message() ?? '已同步应用市场售价'));
    }


    /** @return list<int> */
    public function itemIdsForDocument(int $documentId): array
    {
        if ($documentId < 1) {
            return [];
        }

        $ids = array_values(array_unique(array_map(
            'intval',
            DocumentItemRef::where('document_id', $documentId)
                ->order('sort', 'asc')
                ->order('id', 'asc')
                ->column('item_id') ?: [],
        )));
        // 兼容仅写了 primary_document_id、尚未写入 document_item_refs 的品项
        $primaryIds = array_values(array_unique(array_map(
            'intval',
            Item::where('primary_document_id', $documentId)
                ->where('status', self::STATUS_ACTIVE)
                ->order('sort', 'asc')
                ->order('id', 'asc')
                ->column('id') ?: [],
        )));
        foreach ($primaryIds as $id) {
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param list<int> $itemIds
     * @return ServiceResult
     */
    public function syncDocumentRefs(int $documentId, array $itemIds): ServiceResult
    {
        if ($documentId < 1) {
            return ServiceResult::fail('文档无效');
        }
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));
        DocumentItemRef::where('document_id', $documentId)->delete();
        $existingIds = [];
        if ($itemIds !== []) {
            $existingIds = array_flip(array_map(
                'intval',
                Item::whereIn('id', $itemIds)->column('id'),
            ));
        }
        $sort = 0;
        foreach ($itemIds as $itemId) {
            if (!isset($existingIds[$itemId])) {
                continue;
            }
            DocumentItemRef::insert([
                'document_id' => $documentId,
                'item_id'     => $itemId,
                'role'        => $sort === 0 ? 'primary' : 'related',
                'sort'        => $sort,
                'created_at'  => AppTime::now(),
            ]);
            $sort++;
        }

        if ($itemIds !== []) {
            $this->syncPrimaryDocumentIdForFirstRef($documentId, $itemIds[0]);
        }

        return ServiceResult::ok(null, 'ok');
    }

    /**
     * 文档关联首项 ↔ 品项 primary_document_id（空或已指向本文档时写入）
     */
    private function syncPrimaryDocumentIdForFirstRef(int $documentId, int $itemId): void
    {
        if ($documentId < 1 || $itemId < 1) {
            return;
        }
        Item::where('id', $itemId)
            ->where(function ($query) use ($documentId): void {
                $query->where('primary_document_id', 0)->whereOr('primary_document_id', $documentId);
            })
            ->update([
                'primary_document_id' => $documentId,
                'updated_at'          => AppTime::now(),
            ]);
    }

    /**
     * 详情文档栏目变更时，主品项 nav_id 跟文档（单主归属）。
     */
    public function syncPrimaryItemNavFromDocument(int $documentId, int $navId): void
    {
        if ($documentId < 1 || $navId < 1) {
            return;
        }
        if (!app(SiteNavService::class)->isContentCategoryId($navId)) {
            return;
        }
        $primary = $this->primaryItemRowForDocument($documentId);
        if (!is_array($primary)) {
            return;
        }
        $itemId = (int) ($primary['id'] ?? 0);
        if ($itemId < 1 || (int) ($primary['nav_id'] ?? 0) === $navId) {
            return;
        }
        Item::where('id', $itemId)->update([
            'nav_id'     => $navId,
            'updated_at' => AppTime::now(),
        ]);
    }

    /**
     * 为品项创建或返回详情文档（产品中心「编辑详情」）
     *
     * @return ServiceResult data: document_id, created(bool)
     */
    public function ensurePrimaryDetailDocument(int $itemId): ServiceResult
    {
        if ($itemId < 1) {
            return ServiceResult::fail('品项无效');
        }
        $row = Item::where('id', $itemId)->find();
        if (!$row) {
            return ServiceResult::fail('品项不存在');
        }

        $existingDocId = (int) ($row['primary_document_id'] ?? 0);
        if ($existingDocId > 0) {
            $alive = Document::where('id', $existingDocId)->whereNull('deleted_at')->find();
            if ($alive) {
                $this->syncDetailDocumentProductLayout($itemId, $existingDocId, $row);
                $this->repairPrimaryDetailDocumentTitle($itemId, $existingDocId, $row);

                return ServiceResult::ok(
                    ['document_id' => $existingDocId, 'created' => false],
                    '已有详情文档',
                );
            }
        }

        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            return ServiceResult::fail('品项名称无效');
        }
        // 详情文档标题 = 品项展示名；货号在「货号」列，禁止拼进标题（编辑/保存会回写污染列表名）
        $title = $name;

        $created = app(DocumentAdminService::class)->saveAdmin([
            'title'    => mb_substr($title, 0, 200),
            'status'   => 0,
            'summary'  => '',
            'content'  => '',
            'tpl_name' => 'view_document.php',
            'item_ids' => [$itemId],
        ]);
        if (!$created->isOk()) {
            return ServiceResult::fail((string) ($created->message() ?? '创建详情文档失败'));
        }

        $documentId = (int) (($created->dataArray() ?? [])['id'] ?? 0);
        if ($documentId < 1) {
            return ServiceResult::fail('创建详情文档失败');
        }

        Item::where('id', $itemId)->update([
            'primary_document_id' => $documentId,
            'updated_at'          => AppTime::now(),
        ]);

        $this->syncDetailDocumentProductLayout($itemId, $documentId, $row);

        return ServiceResult::ok(
            ['document_id' => $documentId, 'created' => true],
            '已创建详情文档',
        );
    }

    /**
     * 去掉标题末尾误拼的货号后缀：`名（code）` / `名 (code)`。
     * 仅当后缀与品项 code 一致时剥离，避免误伤正常括号文案。
     */
    public static function stripTrailingItemCodeFromTitle(string $title, string $itemCode): string
    {
        $title = trim($title);
        $itemCode = trim($itemCode);
        if ($title === '' || $itemCode === '') {
            return $title;
        }
        $quoted = preg_quote($itemCode, '/');
        $stripped = preg_replace('/\s*[（(]\s*' . $quoted . '\s*[）)]\s*$/u', '', $title);
        if (!is_string($stripped)) {
            return $title;
        }
        $stripped = trim($stripped);

        return $stripped !== '' ? $stripped : $title;
    }

    /**
     * @param array<string, mixed>|object $itemRow
     */
    private function repairPrimaryDetailDocumentTitle(int $itemId, int $documentId, array|object $itemRow): void
    {
        if ($documentId < 1 || $itemId < 1) {
            return;
        }
        $rowArr = is_array($itemRow) ? $itemRow : (array) $itemRow;
        $code = trim((string) ($rowArr['code'] ?? ''));
        $name = trim((string) ($rowArr['name'] ?? ''));
        $title = trim((string) (Document::where('id', $documentId)->value('title') ?: ''));
        if ($title === '') {
            return;
        }
        $cleanTitle = self::stripTrailingItemCodeFromTitle($title, $code);
        $cleanName = self::stripTrailingItemCodeFromTitle($name, $code);
        $now = AppTime::now();
        if ($cleanTitle !== $title) {
            Document::where('id', $documentId)->update([
                'title'      => mb_substr($cleanTitle, 0, 200),
                'updated_at' => $now,
            ]);
        }
        if ($cleanName !== '' && $cleanName !== $name) {
            Item::where('id', $itemId)->update([
                'name'       => mb_substr($cleanName, 0, 200),
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @param array<string, mixed>|object $itemRow
     */
    private function syncDetailDocumentProductLayout(int $itemId, int $documentId, array|object $itemRow): void
    {
        if ($documentId < 1 || $itemId < 1) {
            return;
        }
        if (!app(ProductCenterGateService::class)->allowsParams()) {
            return;
        }
        if (!class_exists(\app\common\service\product\ProductService::class)) {
            return;
        }

        $rowArr = is_array($itemRow) ? $itemRow : (array) $itemRow;
        $variantCount = $this->itemVariantService->countByItemId($itemId);
        $layoutMode   = $variantCount > 1 ? 'multi_spec' : 'single';

        \app\common\service\product\ProductService::syncDocumentProductSettings($documentId, $layoutMode);
    }

    /** @return list<array{id:int,label:string}> */
    public function optionsForDocumentForm(): array
    {
        $out  = [];
        $pickerQuery = Item::order('sort', 'asc')->order('id', 'desc')->limit(QueryLimit::MEILI_REINDEX_BATCH);
        app(ItemPublicVisibilityService::class)->applyToQuery(
            $pickerQuery,
            ItemPublicVisibilityService::CHANNEL_ADMIN,
        );
        $rows = $pickerQuery->field('id,code,name,item_type')->select()->toArray();
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $code = (string) ($row['code'] ?? '');
            $name = (string) ($row['name'] ?? '');
            $out[] = [
                'id'        => $id,
                'label'     => $code !== '' ? "{$code} · {$name}" : $name,
                'item_type' => (string) ($row['item_type'] ?? self::TYPE_PHYSICAL),
            ];
        }

        return $out;
    }

    /** attrs/EAV 变更后重建 ItemFilterFacet（commit 后；失败不回滚主写） */
    private function syncFilterFacetsAfterAttrs(int $itemId): void
    {
        if ($itemId < 1) {
            return;
        }
        try {
            app(ItemFilterFacetService::class)->syncAfterItemChange($itemId);
        } catch (\Throwable) {
            // facet 物化为派生层，主写已成功
        }
    }

    private function dispatchItemEvents(int $itemId, string $slug, string $status, string $prevStatus): void
    {
        if ($itemId < 1) {
            return;
        }
        $payload = [
            'item_id' => $itemId,
            'slug'    => $slug,
            'status'  => $status,
        ];
        app(EventBusService::class)->dispatch('item.updated', $payload);
        if ($prevStatus !== '' && $prevStatus !== $status) {
            app(EventBusService::class)->dispatch('item.status_changed', $payload);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function dispatchItemAfterSaveExtensions(
        int $itemId,
        bool $isNew,
        array $row,
        string $prevStatus,
    ): void {
        if ($itemId < 1) {
            return;
        }
        app(ItemPersistRegistry::class)->dispatchAfterSave([
            'item_id'     => $itemId,
            'is_new'      => $isNew ? 1 : 0,
            'prev_status' => $prevStatus,
            'row'         => $row,
        ]);
    }

    /**
     * 后台「从插件包导入」三框文档：经 HostRuntimeProbe / PluginOfficialProduct。
     */
    public function reloadOfficialPluginDocsAdmin(int $itemId, string $identifier = ''): ServiceResult
    {
        if ($itemId < 1) {
            return ServiceResult::fail('品项无效');
        }
        $ident = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($identifier))) ?? '';
        if ($ident === '') {
            $row = Item::where('id', $itemId)->find();
            if (!$row) {
                return ServiceResult::fail('品项不存在');
            }
            $attrs = is_array($row['attrs'] ?? null) ? $row['attrs'] : [];
            $catalog = is_array($attrs['official_catalog'] ?? null) ? $attrs['official_catalog'] : [];
            $ident = preg_replace(
                '/[^a-z0-9_-]/',
                '',
                strtolower(trim((string) ($catalog['plugin_identifier'] ?? ''))),
            ) ?? '';
            if ($ident === '') {
                $docs = is_array($attrs['official_plugin_docs'] ?? null) ? $attrs['official_plugin_docs'] : [];
                $ident = preg_replace(
                    '/[^a-z0-9_-]/',
                    '',
                    strtolower(trim((string) ($docs['plugin_identifier'] ?? ''))),
                ) ?? '';
            }
        }
        if ($ident === '') {
            return ServiceResult::fail('缺少插件包标识，请先填写「插件资料」中的标识');
        }

        $result = PluginOfficialProduct::dispatch(
            'plugin_docs_reload',
            ['item_id' => $itemId, 'identifier' => $ident],
            null,
        );
        if ($result instanceof ServiceResult) {
            return $result;
        }
        if (is_array($result) && isset($result['docs'])) {
            return ServiceResult::ok($result, '已从插件包重载文档快照');
        }

        return ServiceResult::fail('宿主插件文档扩展不可用或重载失败');
    }
}
