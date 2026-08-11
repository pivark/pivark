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


use app\common\service\admin\AdminNavPersonaService;
use app\common\service\admin\AdminSpaMetaRegistry;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\extension\PluginOfferBridgeRegistry;
use app\common\service\plugin\PluginService;
use app\common\service\product\OfferBridgeFacade;
use app\common\service\product\ProductCenterGateService;
use app\common\service\product\ProductConfigService;
use app\common\model\ProductParamDef;
use app\common\service\user\PermissionService;
use think\facade\Cache;

/**
 * 产品中心后台 UI 显隐 SSOT（权限 × 插件授权 × 数据就绪）
 */
final class ItemAdminUiService
{

    public const PERM_ITEM_LIST         = 'admin.item.list';
    public const PERM_ITEM_EDIT         = 'admin.item.edit';
    public const PERM_VARIANT_LIST      = 'admin.item.variant.list';
    public const PERM_VARIANT_EDIT      = 'admin.item.variant.edit';

    public function __construct(
        private readonly PermissionService $permissionService,
        private readonly EntitlementService $entitlementService,
        private readonly PluginService $pluginService,
        private readonly ItemVariantService $itemVariantService,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function modules(int $userId): array
    {
        $can = fn (string $code): bool => $this->permissionService->can($userId, $code);
        $erpOn   = $this->entitlementService->can(ItemService::CAPABILITY_PLUGIN_ERP);
        $mesOn   = $this->entitlementService->can(ItemService::CAPABILITY_PLUGIN_MES);
        $offerOn = app(OfferBridgeFacade::class)->enabled();
        $productOn = app(ProductCenterGateService::class)->allowsParams();
        $variantTable = $this->itemVariantService->tableExists();

        $canViewVariant = $variantTable && ($can(self::PERM_VARIANT_LIST) || $can(self::PERM_VARIANT_EDIT));
        $canEditVariant = $variantTable && $can(self::PERM_VARIANT_EDIT);
        $canEditItem    = $can(self::PERM_ITEM_EDIT) && !app(AdminNavPersonaService::class)->itemFormReadOnly($userId);
        $offerPerm      = $this->offerBridgeUsePermission();
        $canOfferManage = $offerOn && ($offerPerm === '' || $can($offerPerm));

        $modules = [
            'itemList' => [
                'visible'  => $can(self::PERM_ITEM_LIST),
                'editable' => $canEditItem,
            ],
            'variantPanel' => [
                'visible'          => $canViewVariant,
                'editable'         => $canEditVariant,
                'requiresItemId'   => true,
                'hint'             => '保存品项后再维护订货规格；订货编码全站唯一。',
                'readOnlyHint'     => '当前账号仅可查看规格，修改需「产品中心 · 规格编辑」权限。',
            ],
            'variantCountColumn' => [
                'visible' => $canViewVariant,
            ],
            'paramAttrs' => [
                'visible'  => $productOn,
                'editable' => $productOn && $canEditItem,
                'hint'     => $productOn
                    ? '技术参数用于筛选与对比，不等于订货规格。'
                    : '专业版及以上可在产品中心维护参数定义与 attrs。',
            ],
            'offerSku' => [
                'visible'        => $offerOn,
                'manageable'     => $canOfferManage,
                'route'          => '/product/item',
                'postKey'        => OfferBridgeFacade::POST_KEY_SINGLE,
                'postKeyJson'    => OfferBridgeFacade::POST_KEY_SINGLE_JSON,
                'permissionCode' => $this->offerBridgeUsePermission(),
                'hint'           => $offerOn
                    ? ($canOfferManage
                        ? '改价在编辑文档 → 产品：多规格每行原价/现价（试用/月/年/终身等档位）；列表市场价仅快捷单项。'
                        : '已装报价插件；配置价格需对应使用权限。')
                    : '未装报价插件时仅需维护订货规格，无需 SKU 价格行。',
            ],
            /** 宿主注入（默认关；禁止内核用 HostRuntimeProbe 硬开业务列） */
            'marketplaceListingPrice' => [
                'visible'  => false,
                'editable' => false,
                'hint'     => '列表快捷改单项市场 listing 价；多档周期/试用请在文档编辑 → 产品多规格维护。',
            ],
            'itemOriginColumn' => [
                'visible' => false,
                'hint'    => '宿主注入：官方自营 / 商家上架 / 手动品项，同一列表管理。',
            ],
            'marketShelfColumn' => [
                'visible' => false,
                'title'   => '上架状态',
                'hint'    => '宿主 listing / 货架上架态；数据来自 item_list_overlay.marketplace_listing。',
            ],
            'reviewStatusColumn' => [
                'visible' => false,
                'title'   => '审核状态',
                'hint'    => '宿主 listing 审核态。',
            ],
            'developerColumn' => [
                'visible' => false,
                'title'   => '开发者',
                'hint'    => '宿主 listing 开发者展示名。',
            ],
            'frontVisibleColumn' => [
                'visible' => true,
                'title'   => '前台显示',
                'hint'    => '渠道 web_visible；宿主打开市场列时可关闭本列以免误解。',
            ],
            'platformListFilters' => [
                'visible' => false,
                'hint'    => '宿主注入：来源 / 产线分类等列表筛选。',
            ],
            'catalogBadge' => [
                'visible' => false,
                'hint'    => '宿主注入：型号旁产线/许可徽章。',
            ],
            'marketFeaturedColumn' => [
                'visible' => false,
                'title'   => '市场推荐',
                'hint'    => '宿主注入：市场推荐开关列。',
            ],
            'itemDeleteGraded' => [
                'visible' => false,
                'title'   => '删除品项',
                'hint'    => '宿主注入：产品中心分级删除确认。',
            ],
            'listPageHint' => [
                'visible' => false,
                'hint'    => '',
            ],
            'erpMaterial' => [
                'visible' => $erpOn,
                'hint'    => '物料号在 ERP 模块映射到订货号（variant_code），不在型号栏填写。',
            ],
            'mesBom' => [
                'visible' => $mesOn,
                'hint'    => 'BOM/工单在 MES 引用订货规格或品项，勾选「可制造」后生效。',
            ],
        ];

        $modules = app(AdminNavPersonaService::class)->applyItemUiModules($modules, $userId);
        $modules = $this->mergeHostItemListUiModules($modules, $userId);

        if (!empty($modules['marketplaceListingPrice']['visible'])) {
            $modules['marketplaceListingPrice']['editable'] = $canEditItem
                && ($modules['marketplaceListingPrice']['editable'] ?? true) !== false;
        }

        return $modules;
    }

    /**
     * 宿主注入品项列表 uiModules（平台宿主 / 商城等；后合并覆盖 persona 关断）。
     *
     * @param array<string, array<string, mixed>> $modules
     * @return array<string, array<string, mixed>>
     */
    private function mergeHostItemListUiModules(array $modules, int $userId): array
    {
        $host = PluginOfficialProduct::dispatch('item_list_ui_modules', [
            'user_id' => $userId,
        ], null);
        if (!is_array($host) || $host === []) {
            return $modules;
        }

        foreach ($host as $key => $cfg) {
            if (!is_string($key) || $key === '' || !is_array($cfg)) {
                continue;
            }
            $base = is_array($modules[$key] ?? null) ? $modules[$key] : [];
            $modules[$key] = array_merge($base, $cfg);
        }

        return $modules;
    }

    /**
     * 品项列表页轻量 meta（跳过 tags/paramGroups/health 等表单专用字段）
     *
     * @return array<string, mixed>
     */
    public function listPageMetaPayload(int $userId): array
    {
        $cacheKey = 'admin_item_meta_list_v4:' . max(0, $userId);
        $cached   = Cache::get($cacheKey);
        if (is_array($cached) && $this->listPageMetaCacheUsable($cached)) {
            return $cached;
        }

        $payload = $this->buildListPageMetaPayload($userId);
        // 宿主已开却缺货架列 = 未 boot / handler 空壳，禁止写入缓存（否则 60s 内列永久回落）
        if ($this->listPageMetaCacheUsable($payload)) {
            Cache::set($cacheKey, $payload, 60);
        }

        return $payload;
    }

    /**
     * 宿主产品中心开启时，list meta 必须带上架列；否则视为不可用缓存。
     *
     * @param array<string, mixed> $payload
     */
    private function listPageMetaCacheUsable(array $payload): bool
    {
        $modules = $payload['uiModules'] ?? null;
        if (!is_array($modules)) {
            return false;
        }
        if (!PluginOfficialProduct::productCenterAdminEnabled()) {
            return true;
        }

        return !empty($modules['marketShelfColumn']['visible']);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildListPageMetaPayload(int $userId): array
    {
        $paramDefCount = 0;
        if (app(ProductCenterGateService::class)->allowsParams()) {
            try {
                $paramDefCount = (int) ProductParamDef::count();
            } catch (\Throwable) {
                // 参数定义表未就绪时 count=0
            }
        }

        return [
            'typeLabels'            => [],
            'statusLabels'          => [],
            'tags'                  => [],
            'uiModules'             => $this->modules($userId),
            'contentProfile'        => app(AdminNavPersonaService::class)->itemAdminProfile($userId),
            'productPluginEntitled' => app(ProductCenterGateService::class)->allowsParams(),
            'paramDefsCount'        => $paramDefCount,
            'officialCatalog'     => $this->officialCatalogMeta(),
            'productCenterAdmin'    => ProductConfigService::adminUiPayload(),
            'offerCenterNav'        => app(AdminSpaMetaRegistry::class)->collectFirst('offerCenterNav', [
                'user_id' => $userId,
                'scope'   => 'product_center_list',
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function officialCatalogMeta(): array
    {
        if (!ProductConfigService::officialProductCenterAdminEnabled()) {
            return ['enabled' => false, 'lines' => [], 'plugin_kinds' => []];
        }
        $meta = PluginOfficialProduct::dispatch('catalog_meta', [], null);
        if (is_array($meta)) {
            return $meta;
        }

        return \app\common\service\product\ProductConfigService::catalogLinesMeta();
    }

    private function offerBridgeUsePermission(): string
    {
        $id = app(PluginOfferBridgeRegistry::class)->identifier();
        if ($id === null || $id === '') {
            return '';
        }
        $manifest = $this->pluginService->readManifest($id);
        $perms    = is_array($manifest['permissions'] ?? null) ? $manifest['permissions'] : [];

        return trim((string) ($perms[0] ?? ''));
    }
}
