<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);


namespace app\common\service\admin;

use app\common\service\plugin\extension\PluginEditorSurfaceService;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\weapp\WeappPluginChangelogService;
use app\common\service\plugin\weapp\WeappPluginDocService;
use app\common\service\product\ProductL1Access;
use app\common\support\ServiceResult;

/** Vue 后台 SPA meta / list 端点（Registry 分发 · 无 per-L2-plugin 内核方法；product 为 L1 中枢见 buildProductMeta） */
class AdminSpaPluginMetaService
{

    public function spaMeta(string $pluginId): ServiceResult
    {
        $pluginId = self::normalizePluginId($pluginId);
        if ($pluginId === '') {
            return ServiceResult::notFound('未知插件');
        }

        if (ProductL1Access::isKernel($pluginId)) {
            return $this->metaIfEnabled(
                $pluginId,
                self::disabledMessageFor($pluginId),
                fn (): array => $this->buildProductMeta(),
            );
        }

        if (!app(AdminSpaMetaRegistry::class)->hasBucket($pluginId)) {
            return ServiceResult::notFound('插件未注册 SPA meta');
        }

        return $this->metaIfEnabled(
            $pluginId,
            self::disabledMessageFor($pluginId),
            fn (): array => app(AdminSpaMetaRegistry::class)->collectFirst($pluginId) ?? [],
        );
    }

    /**
     * @param array<string, mixed> $query
     */
    public function spaPluginList(string $pluginId, string $action, array $query): ServiceResult
    {
        $pluginId = self::normalizePluginId($pluginId);
        $action   = self::normalizeAction($action);
        if ($pluginId === '' || $action === '') {
            return ServiceResult::notFound('未知插件列表');
        }
        if (!app(AdminSpaPluginListRegistry::class)->has($pluginId, $action)) {
            return ServiceResult::notFound('未知插件列表 action');
        }

        return $this->metaIfEnabled(
            $pluginId,
            self::disabledMessageFor($pluginId),
            fn (): array => app(AdminSpaPluginListRegistry::class)->invoke($pluginId, $action, $query) ?? [],
        );
    }

    /** @return array<string, mixed> */
    public function productMeta(): array
    {
        return $this->buildProductMeta();
    }

    /** @return array<string, mixed> */
    public function weappUsage(string $plugin, string $section): array
    {
        $plugin  = self::normalizePluginId($plugin);
        $section = trim($section);
        if ($plugin === '') {
            throw new \InvalidArgumentException('缺少 plugin 参数');
        }

        if ($section === 'changelog') {
            $content = app(WeappPluginChangelogService::class)->html($plugin);
            if ($content === '') {
                $content = '作者尚未提供版本更新说明。';
            }

            return [
                'plugin'  => $plugin,
                'section' => 'changelog',
                'content' => $content,
            ];
        }

        $content = app(WeappPluginDocService::class)->html(
            $plugin,
            $section === 'guide'
                ? WeappPluginDocService::SECTION_GUIDE
                : WeappPluginDocService::SECTION_USAGE
        );
        if ($content === '') {
            $content = '请参考插件文档与模板标签说明。';
        }

        return [
            'plugin'  => $plugin,
            'content' => $content,
        ];
    }

    public function pluginInfo(string $plugin): ServiceResult
    {
        $plugin = self::normalizePluginId($plugin);
        if ($plugin === '' || !app(AdminSpaPluginAccessService::class)->isEnabledForAdmin($plugin)) {
            return ServiceResult::notFound('插件未启用或不存在');
        }
        $info = app(PluginService::class)->presentationForAdmin($plugin);
        if ($info === null) {
            return ServiceResult::notFound('插件不存在');
        }

        return ServiceResult::ok($info, '');
    }

    /** @return list<string> */
    public static function spaPluginMetaIds(): array
    {
        $ids = [];
        foreach (app(PluginService::class)->listAdmin(true) as $row) {
            $id = strtolower(trim((string) ($row['identifier'] ?? '')));
            if ($id !== '' && app(AdminSpaMetaRegistry::class)->hasBucket($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param callable(): array<string, mixed> $payload
     */
    private function metaIfEnabled(string $pluginId, string $disabledMessage, callable $payload): ServiceResult
    {
        if (!app(AdminSpaPluginAccessService::class)->isEnabledForAdmin($pluginId)) {
            return ServiceResult::notFound($disabledMessage);
        }

        return ServiceResult::ok($payload(), '');
    }

    /** @return array<string, mixed> */
    private function buildProductMeta(): array
    {
        $catalogLines    = [];
        $officialCatalog = ['enabled' => false, 'lines' => [], 'plugin_kinds' => []];
        if (\app\common\service\product\ProductConfigService::officialProductCenterAdminEnabled()) {
            $catalogLines    = \app\common\service\product\ProductConfigService::catalogLinesForAdmin();
            $officialCatalog = \app\common\service\product\ProductConfigService::catalogLinesMeta();
        }

        return [
            'cfg'                => \app\common\service\product\ProductConfigService::all(),
            'stats'              => \app\common\service\product\ProductConfigService::statsAdmin(),
            'health'             => \app\common\service\product\ProductConfigService::healthCheckAdmin(),
            'variantNaming'      => \app\common\service\product\ProductConfigService::variantNamingPayload(),
            'slotCards'          => app(PluginEditorSurfaceService::class)->surfaceSlotCards('product'),
            'editorSlot'         => app(PluginEditorSurfaceService::class)->resolveSlot('product'),
            'plugin'             => app(PluginService::class)->presentationForAdmin('product'),
            'catalogLines'       => $catalogLines,
            'officialCatalog'    => $officialCatalog,
            'hostOps'              => \app\common\service\product\ProductConfigService::hostOpsMeta(),
            'productCenterAdmin' => \app\common\service\product\ProductConfigService::adminUiPayload(),
        ];
    }

    private static function normalizePluginId(string $pluginId): string
    {
        $pluginId = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($pluginId))) ?? '';

        return \app\common\support\WeappIdentifierAlias::normalize($pluginId);
    }

    private static function normalizeAction(string $action): string
    {
        return preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($action))) ?? '';
    }

    /** 插件未启用提示：名称来自 manifest，内核不写死 identifier */
    private static function disabledMessageFor(string $pluginId): string
    {
        $manifest = app(PluginService::class)->readManifest($pluginId);
        $name = trim((string) ($manifest['name'] ?? ''));
        if ($name === '') {
            return '插件未启用';
        }

        return $name . '未启用';
    }
}
