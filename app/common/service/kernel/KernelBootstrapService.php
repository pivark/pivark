<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\kernel;

use app\common\service\plugin\extension\DocumentAddonBridgeAccess;
use app\common\service\plugin\registry\PluginExtensionRegistry;
use app\common\service\ai\AiConfigHookService;
use app\common\service\ai\AiConfigPublicApi;
use app\common\service\product\ProductEventService;
use app\common\service\product\ProductPublicApi;
use app\common\service\product\ProductService;
use app\common\service\product\ProductSmartSearchService;
use app\common\service\site\FloatContactPublicApi;
use app\common\service\enterprise\EnterpriseResourceNav;
use app\common\service\enterprise\EnterpriseAssetBackendRegistry;
use app\common\service\enterprise\EnterpriseAssetKernelService;

/** 内核能力启动（非插件） */
class KernelBootstrapService
{

    public function __construct(
        private readonly KernelBootstrapCoreDeps $core,
        private readonly KernelBootstrapSurfaceDeps $surface,
    ) {
    }

    private static bool $booted = false;
    private static bool $hooksRegistered = false;

    /** 内核钩子（含 template_tag_register）仅注册一次；ensureExtensionTags 须在 fire 前调用 */
    public function registerKernelHooksOnce(): void
    {
        if (self::$hooksRegistered) {
            return;
        }
        self::$hooksRegistered = true;
        $this->registerKernelHooks();
    }

    /** 注册 L1 内核能力（统计、表单、点赞收藏、品项标签等） */
    public function boot(): void
    {
        if (!self::$booted) {
            self::$booted = true;
            app(EnterpriseAssetBackendRegistry::class)->register('kernel', EnterpriseAssetKernelService::class);
            $this->core->dbOpsLogService->boot();
            $this->core->accessStatsService->boot();
            $this->surface->templateFragmentHookService->boot();
            $this->registerKernelHooksOnce();
            $this->registerKernelApis();
        }
        // 插件 bootstrap 会 resetExtensionTags()，L1 标签经 registerKernelTag 须每请求重新 register
        $this->core->favoriteService->boot();
        $this->core->siteFormService->boot();
        $this->surface->itemTemplateTagService->boot();
        ProductService::boot();
        $this->surface->floatContactTemplateTagService->boot();
        $this->surface->frontAssetTagService->boot();
        $this->surface->memberListTagService->boot();
        EnterpriseResourceNav::register();
    }

    private function registerKernelHooks(): void
    {
        $this->core->eventBusService->listen('document.after_save', static function (array $payload): void {
            app(AiConfigHookService::class)->onDocumentAfterSave($payload);
        });
        $this->core->eventBusService->listen('item.updated', static function (array $payload): void {
            ProductEventService::onItemUpdated($payload);
        });
        $this->core->eventBusService->listen('item.deleted', static function (array $payload): void {
            ProductEventService::onItemDeleted($payload);
        });
        $this->core->eventBusService->listen('item.status_changed', static function (array $payload): void {
            ProductEventService::onItemStatusChanged($payload);
        });
        $this->surface->hookService->on('search.enhance.ai', static function (array &$payload): void {
            app(AiConfigHookService::class)->onSearchEnhance($payload);
        });
        $this->surface->hookService->on('search.enhance.product', static function (array &$payload): void {
            ProductSmartSearchService::onSearchEnhanceHook($payload);
        });
        $this->surface->hookService->on('search.parse_query', static function (array &$payload): void {
            ProductSmartSearchService::onParseQueryHook($payload);
        });
        $this->surface->hookService->on('template_tag_register', static function (array $payload): void {
            foreach (app(PluginExtensionRegistry::class)->enabledDocumentAddonBridges() as $bridge) {
                $id = $bridge->identifier();
                if ($id !== '' && method_exists($bridge, 'bootTemplateTag')) {
                    DocumentAddonBridgeAccess::invoke($id, 'bootTemplateTag', []);
                }
            }
        });
    }

    private function registerKernelApis(): void
    {
        $this->surface->pluginApiRegistry->register('FloatContactPublic', new FloatContactPublicApi());
        $this->surface->pluginApiRegistry->register('AiConfigPublic', new AiConfigPublicApi());
        $this->surface->pluginApiRegistry->register('ProductPublic', new ProductPublicApi());
    }
}
