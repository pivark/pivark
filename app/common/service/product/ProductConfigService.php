<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);


namespace app\common\service\product;

use app\common\service\plugin\extension\PluginOfficialProduct;

use app\common\service\item\ItemService;
use app\common\service\item\ItemVariantCodePolicy;
use app\common\service\weapp\WeappPluginGateway;
use app\common\model\Item;
use app\common\model\ProductParamDef;
use app\common\service\product\ProductCenterGateService;
use app\common\service\config\ConfigService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\boot\PluginBootService;
use app\common\service\plugin\extension\PluginOfferBridgeRegistry;
use app\common\service\plugin\weapp\WeappPluginSaveSupport;
use app\common\support\ServiceResult;
use think\facade\Db;

/** 产品中心全局配置 */
class ProductConfigService
{
    /** @return list<string> */
    public static function keys(): array
    {
        return [
            'product_open',
            'product_document_editor_slot',
            'product_sitemap_include_discontinued',
            'product_sitemap_include_items_tpl',
            'product_variant_code_mode',
            'product_variant_code_prefix',
            'product_variant_code_suffix',
            'product_variant_code_separator',
            'product_variant_code_case',
        ];
    }

    /** @return list<string> */
    public static function variantNamingKeys(): array
    {
        return [
            'product_variant_code_mode',
            'product_variant_code_prefix',
            'product_variant_code_suffix',
            'product_variant_code_separator',
            'product_variant_code_case',
        ];
    }

    /** host_only 发行宿主：官方品项 Tab / 同步 / 分类配置（Community 发行版关闭） */
    public static function officialProductCenterAdminEnabled(): bool
    {
        return PluginOfficialProduct::productCenterAdminEnabled();
    }

    /** @return list<array{key:string,label:string,plugin_kind_filter?:bool}> */
    public static function catalogLinesForAdmin(): array
    {
        $lines = PluginOfficialProduct::dispatch('catalog_lines_for_admin', [], null);

        return is_array($lines) ? $lines : [];
    }

    /** @return array<string, mixed> */
    public static function catalogLinesMeta(): array
    {
        $meta = PluginOfficialProduct::dispatch('catalog_lines_meta', [], null);
        if (is_array($meta)) {
            return $meta;
        }

        return [
            'enabled'      => false,
            'lines'        => [],
            'plugin_kinds' => [],
            'default_line' => '',
            'source'       => 'community',
        ];
    }

    /**
     * 后台产品中心 UI 门禁（Item meta / SPA product-meta）
     *
     * @return array{catalog_tabs:bool,settings_tab:bool,settings_route:string,virtual_code_prefix:string}
     */
    public static function adminUiPayload(): array
    {
        $payload = PluginOfficialProduct::dispatch('admin_ui_payload', [], null);
        if (is_array($payload)) {
            return [
                'catalog_tabs'        => !empty($payload['catalog_tabs']),
                'settings_tab'        => !empty($payload['settings_tab']),
                'settings_route'      => trim((string) ($payload['settings_route'] ?? '')),
                'virtual_code_prefix' => trim((string) ($payload['virtual_code_prefix'] ?? '')),
            ];
        }

        return [
            'catalog_tabs'        => false,
            'settings_tab'        => false,
            'settings_route'      => '',
            'virtual_code_prefix' => '',
        ];
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        $cfg = app(ConfigService::class);
        $out = [];
        foreach (self::keys() as $key) {
            $out[$key] = (string) $cfg->get($key, self::defaultFor($key));
        }
        $out['product_document_editor_slot'] = app(WeappPluginGateway::class)->pluginEditorResolveSlot('product');

        return $out;
    }

    public static function defaultFor(string $key): string
    {
        return match ($key) {
            'product_open' => '1',
            'product_document_editor_slot' => app(WeappPluginGateway::class)->pluginEditorManifestDefaultSlot('product'),
            'product_sitemap_include_discontinued' => '0',
            'product_sitemap_include_items_tpl' => '1',
            'product_variant_code_mode' => ItemVariantCodePolicy::MODE_ITEM_SPEC,
            'product_variant_code_prefix' => '',
            'product_variant_code_suffix' => '',
            'product_variant_code_separator' => '-',
            'product_variant_code_case' => ItemVariantCodePolicy::CASE_KEEP,
            default => '',
        };
    }

    /**
     * 订货号命名策略（供 suggest / 设置页 / item meta）。
     *
     * @return array{
     *   mode:string,
     *   prefix:string,
     *   suffix:string,
     *   separator:string,
     *   case:string,
     *   modes:list<array{value:string,label:string,hint:string}>,
     *   preview_example:string
     * }
     */
    public static function variantNamingPayload(): array
    {
        $policy = self::variantNamingPolicy();
        $preview = ItemVariantCodePolicy::build(
            $policy,
            'DJ-810',
            '红色 / 220V / 50W',
            1,
            ['颜色' => '红', '电压' => '220V', '功率' => '50W'],
        );

        return [
            'mode'            => $policy['mode'],
            'prefix'          => $policy['prefix'],
            'suffix'          => $policy['suffix'],
            'separator'       => $policy['separator'],
            'case'            => $policy['case'],
            'modes'           => ItemVariantCodePolicy::modeOptions(),
            'preview_example' => $preview,
        ];
    }

    /**
     * @return array{mode:string,prefix:string,suffix:string,separator:string,case:string}
     */
    public static function variantNamingPolicy(): array
    {
        $cfg = app(ConfigService::class);

        return [
            'mode'      => ItemVariantCodePolicy::normalizeMode(
                (string) $cfg->get('product_variant_code_mode', self::defaultFor('product_variant_code_mode')),
            ),
            'prefix'    => trim((string) $cfg->get(
                'product_variant_code_prefix',
                self::defaultFor('product_variant_code_prefix'),
            )),
            'suffix'    => trim((string) $cfg->get(
                'product_variant_code_suffix',
                self::defaultFor('product_variant_code_suffix'),
            )),
            'separator' => ItemVariantCodePolicy::normalizeSeparator(
                (string) $cfg->get('product_variant_code_separator', self::defaultFor('product_variant_code_separator')),
            ),
            'case'      => ItemVariantCodePolicy::normalizeCase(
                (string) $cfg->get('product_variant_code_case', self::defaultFor('product_variant_code_case')),
            ),
        ];
    }

    public static function isOpen(): bool
    {
        return (string) app(ConfigService::class)->get('product_open', '1') === '1';
    }

    public static function sitemapIncludeDiscontinued(): bool
    {
        return (string) app(ConfigService::class)->get('product_sitemap_include_discontinued', '0') === '1';
    }

    public static function sitemapIncludeItemsTpl(): bool
    {
        return (string) app(ConfigService::class)->get('product_sitemap_include_items_tpl', '1') === '1';
    }

    /** @return array{total:int,active:int,param_defs:int} */
    public static function statsAdmin(): array
    {
        $total = (int) Item::count();
        $active = (int) Item::where('status', 'active')->count();
        $defs   = 0;
        try {
            $defs = (int) ProductParamDef::count();
        } catch (\Throwable) {
            // 参数定义表未就绪时 param_defs=0
        }

        return ['total' => $total, 'active' => $active, 'param_defs' => $defs];
    }

    /**
     * 运营完成度检查（设置页清单）
     *
     * @return array{
     *   checks:list<array{key:string,label:string,count:int,severity:string,hint:string,route:string}>,
     *   score:int
     * }
     */
    public static function healthCheckAdmin(): array
    {
        $checks = [];
        $active = (int) Item::where('status', ItemService::STATUS_ACTIVE)->count();
        $defs   = 0;
        try {
            $defs = (int) ProductParamDef::count();
        } catch (\Throwable) {
            // 参数定义表未就绪时 defs=0
        }

        $pfx     = (string) config('database.connections.mysql.prefix');
        $noCover = (int) (Db::query(
            "SELECT COUNT(*) AS c FROM `{$pfx}items` i
             WHERE i.status = ?
             AND (
               i.primary_document_id <= 0
               OR NOT EXISTS (
                 SELECT 1 FROM `{$pfx}documents` d
                 WHERE d.id = i.primary_document_id AND d.status = 1
                   AND d.litpic IS NOT NULL AND d.litpic <> ''
               )
             )",
            [ItemService::STATUS_ACTIVE],
        )[0]['c'] ?? 0);
        $checks[] = [
            'key'      => 'no_cover',
            'label'    => '在售品项缺封面',
            'count'    => $noCover,
            'severity' => $noCover > 0 ? 'warning' : 'ok',
            'hint'     => '请在产品中心上传封面，或绑定带头图的详情文档',
            'route'    => '/product/item',
        ];

        $noDetail = (int) Item::where('status', ItemService::STATUS_ACTIVE)
            ->where('primary_document_id', '<=', 0)
            ->count();
        $checks[] = [
            'key'      => 'no_detail_doc',
            'label'    => '在售品项未绑详情文档',
            'count'    => $noDetail,
            'severity' => $noDetail > 0 ? 'warning' : 'ok',
            'hint'     => '绑定已发布文档后，列表可显示「阅读详细介绍」',
            'route'    => '/product/item',
        ];

        $checks[] = [
            'key'      => 'no_param_defs',
            'label'    => '未配置筛选参数',
            'count'    => $defs === 0 ? 1 : 0,
            'severity' => $defs === 0 ? 'info' : 'ok',
            'hint'     => '建议至少配置颜色/规格等 param_defs',
            'route'    => '/product/params',
        ];

        $draft = (int) Item::where('status', ItemService::STATUS_DRAFT)->count();
        $checks[] = [
            'key'      => 'draft_items',
            'label'    => '草稿品项',
            'count'    => $draft,
            'severity' => $draft > 0 ? 'info' : 'ok',
            'hint'     => '发布后前台与独立页才可访问',
            'route'    => '/product/item',
        ];

        $checks[] = [
            'key'      => 'active_items',
            'label'    => '在售品项',
            'count'    => $active,
            'severity' => $active > 0 ? 'ok' : 'warning',
            'hint'     => $active > 0 ? '可对外展示' : '请先创建并发布品项',
            'route'    => '/product/item',
        ];

        $penalty = 0;
        foreach ($checks as $c) {
            if (($c['severity'] ?? '') === 'warning' && (int) ($c['count'] ?? 0) > 0) {
                $penalty += 15;
            }
            if (($c['key'] ?? '') === 'no_param_defs' && (int) ($c['count'] ?? 0) > 0) {
                $penalty += 10;
            }
        }
        $score = max(0, 100 - $penalty);

        return ['checks' => $checks, 'score' => $score];
    }

    /** @param array<string, mixed> $post @return ServiceResult */
    public static function saveAdmin(array $post): ServiceResult
    {
        $productOpen = !empty($post['product_open']) ? '1' : '0';
        if (app(ProductCenterGateService::class)->allowsAdmin()) {
            $productOpen = '1';
        }
        app(ConfigService::class)->set('product_open', $productOpen);
        if (array_key_exists('product_sitemap_include_discontinued', $post)) {
            if (!self::officialProductCenterAdminEnabled()) {
                return ServiceResult::fail('站点地图配置仅host_only 发行宿主可修改');
            }
            app(ConfigService::class)->set(
                'product_sitemap_include_discontinued',
                !empty($post['product_sitemap_include_discontinued']) ? '1' : '0',
            );
        }
        if (array_key_exists('product_sitemap_include_items_tpl', $post)) {
            if (!self::officialProductCenterAdminEnabled()) {
                return ServiceResult::fail('站点地图配置仅host_only 发行宿主可修改');
            }
            app(ConfigService::class)->set(
                'product_sitemap_include_items_tpl',
                !empty($post['product_sitemap_include_items_tpl']) ? '1' : '0',
            );
        }
        if (array_key_exists('product_document_editor_slot', $post)) {
            $slot = app(ProductCenterGateService::class)->allowsAdmin()
                ? 'tab'
                : (string) ($post['product_document_editor_slot'] ?? '');
            $slotRes = app(WeappPluginGateway::class)->pluginEditorSaveSiteSlot('product', $slot);
            if (!$slotRes->isOk()) {
                return $slotRes;
            }
        }

        $namingTouched = false;
        foreach (self::variantNamingKeys() as $namingKey) {
            if (array_key_exists($namingKey, $post)) {
                $namingTouched = true;
                break;
            }
        }
        if ($namingTouched) {
            $cfg = app(ConfigService::class);
            $mode = ItemVariantCodePolicy::normalizeMode(
                (string) ($post['product_variant_code_mode'] ?? self::defaultFor('product_variant_code_mode')),
            );
            $sep = ItemVariantCodePolicy::normalizeSeparator(
                (string) ($post['product_variant_code_separator'] ?? self::defaultFor('product_variant_code_separator')),
            );
            $case = ItemVariantCodePolicy::normalizeCase(
                (string) ($post['product_variant_code_case'] ?? self::defaultFor('product_variant_code_case')),
            );
            $prefix = mb_substr(trim((string) ($post['product_variant_code_prefix'] ?? '')), 0, 32);
            $suffix = mb_substr(trim((string) ($post['product_variant_code_suffix'] ?? '')), 0, 32);
            $cfg->set('product_variant_code_mode', $mode);
            $cfg->set('product_variant_code_prefix', $prefix);
            $cfg->set('product_variant_code_suffix', $suffix);
            $cfg->set('product_variant_code_separator', $sep);
            $cfg->set('product_variant_code_case', $case);
        }

        if (array_key_exists('catalog_lines', $post)) {
            return ServiceResult::fail('官方产线已固定为配置，不可在后台改分类');
        }

        app(WeappPluginSaveSupport::class)->afterConfigSaved(
            \app\common\service\plugin\weapp\WeappPluginSaveSupport::SCOPE_DOCUMENTS
        );

        return ServiceResult::ok(null, '保存成功');
    }

    /**
     * host_only 运营摘要（设置页可选区块）。无扩展时仅 visible=false。
     *
     * @return array{visible:bool}|array<string, mixed>
     */
    public static function hostOpsMeta(): array
    {
        if (!self::officialProductCenterAdminEnabled()) {
            return ['visible' => false];
        }

        $ops = PluginOfficialProduct::dispatch('host_ops_meta', [], null);
        if (is_array($ops)) {
            return $ops;
        }

        return ['visible' => false];
    }

    /** @return ServiceResult */
    public static function syncOfficialItemsAdmin(string $scope): ServiceResult
    {
        $scope = strtolower(trim($scope));
        if (!in_array($scope, ['all', 'plugins', 'license'], true)) {
            return ServiceResult::fail('scope 无效');
        }
        if (!self::officialProductCenterAdminEnabled()) {
            return ServiceResult::fail('仅平台站可同步官方品项');
        }
        if (!self::officialItemsSyncReady()) {
            return ServiceResult::fail('官方品项同步未就绪，请确认host_only 扩展已启用');
        }

        $result = PluginOfficialProduct::dispatch('sync_official_items', ['scope' => $scope], null);
        if ($result instanceof ServiceResult) {
            return $result;
        }

        return ServiceResult::fail('官方品项同步不可用');
    }

    private static function officialItemsSyncReady(): bool
    {
        if ((bool) PluginOfficialProduct::dispatch('official_items_sync_ready', [], false)) {
            return true;
        }

        return self::offerBridgeReady();
    }

    private static function offerBridgeReady(): bool
    {
        app(PluginBootService::class)->bootstrapEnabled();
        $id = app(PluginOfferBridgeRegistry::class)->identifier();
        if ($id === null || $id === '') {
            return false;
        }

        return app(EntitlementService::class)->can($id);
    }
}
