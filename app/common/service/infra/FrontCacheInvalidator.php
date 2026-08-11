<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\infra;

use app\common\service\admin\AdminDashboardService;
use app\common\service\audit\AuditLogService;
use app\common\service\catalog\CatalogFacetStatsService;
use app\common\service\catalog\CatalogListCacheService;
use app\common\service\document\DocumentListMaterializedService;
use app\common\service\document\DocumentPublicService;
use app\common\service\item\ItemFacetCacheService;
use app\common\service\item\ItemListCacheService;
use app\common\service\site\SiteModeService;
use app\common\service\site\SiteNavService;
use app\common\service\tag\TagDocumentSync;
use app\common\service\tag\TagPublicService;
use app\common\service\tag\TagSlugIndexService;
use app\common\service\template\TemplateBlockCacheService;
use app\common\service\template\TemplateEngineState;
use app\common\service\template\TemplateFragmentCacheService;
use app\common\service\template\TemplateFragmentInvalidationMap;
use app\common\service\template\TemplateTagdocumentsBatchService;
use app\common\service\template\TemplateTagDataWarmupService;
use think\facade\Cache;

final class FrontCacheInvalidator
{

    /** 缓存失效枢纽：多数下游 ctor 依赖本类，出站统一 app() 延迟解析破环（ADR DI-ZERO-001）。 */
    private const GENERATION_KEY = 'pv_front_cache_generation';

    public function generation(): int
    {
        $gen = $this->sharedCache()->get(self::GENERATION_KEY);

        return max(1, (int) ($gen === null || $gen === false ? 1 : $gen));
    }

    public function bumpGeneration(): int
    {
        $next = $this->generation() + 1;
        $this->sharedCache()->set(self::GENERATION_KEY, $next, 86400 * 365);

        return $next;
    }

    /** 多机可见：Redis 时用 redis store，否则回退默认 file（单节点） */
    private function sharedCache(): \think\cache\Driver
    {
        if ($this->cacheConfig()->effectiveDriver() === CacheConfigService::DRIVER_REDIS) {
            return Cache::store('redis');
        }

        return Cache::store();
    }

    /** 全量：页/块/列表物化/元数据版本 + 可选清空 Think 缓存文件 */
    public function invalidateAll(bool $purgeThinkCacheFiles = false): void
    {
        $this->bumpGeneration();
        $this->documentListMaterialized()->bumpGeneration();
        $this->clearPageAndBlockFiles();
        $this->forgetRequestLayers();
        $this->metaSqlCache()->clearFrontMeta();
        $this->hotCache()->forgetPrefix('tag_public_list');
        $this->tagSlugIndex()->forgetCaches();
        if ($purgeThinkCacheFiles) {
            $this->siteMode()->purgeThinkCacheDirectory();
        }
        $this->templateTagDataWarmup()->scheduleAfterInvalidate();
        $this->pageCacheWarmup()->scheduleDefault();
    }

    /** 品项目录：list / facet / catalog 域缓存 */
    public function invalidateItemCatalog(): void
    {
        $this->itemListCache()->bump();
        $this->itemFacetCache()->bump();
        $this->catalogListCache()->bump('items');
        $this->invalidateTemplateFragments($this->templateFragmentInvalidationMap()->namesForItemCatalog());
        if (class_exists(CatalogFacetStatsService::class)) {
            app(CatalogFacetStatsService::class)->bump();
        }
    }

    /** 文档/标签关联变更 */
    public function invalidateDocuments(): void
    {
        $this->bumpGeneration();
        $this->documentListMaterialized()->bumpGeneration();
        $this->invalidateItemCatalog();
        $this->clearPageAndBlockFiles();
        $this->forgetRequestLayers();
        $this->tagSlugIndex()->forgetCaches();
        Cache::delete(AdminDashboardService::CACHE_OVERVIEW);
        Cache::delete(AdminDashboardService::CACHE_DOC_TREND_PREFIX . '15');
        Cache::delete(AdminDashboardService::CACHE_INQUIRY_TREND_PREFIX . '15');
        Cache::delete(AdminDashboardService::CACHE_MEMBER_TREND_PREFIX . '15');
        Cache::delete(AdminDashboardService::CACHE_ACTIVITY_PREFIX . '16');
        $this->templateTagDataWarmup()->scheduleAfterInvalidate();
        $this->pageCacheWarmup()->scheduleDefault();
    }

    /** 标签结构变更（slug/合并/删除） */
    public function invalidateTags(): void
    {
        $this->invalidateTemplateFragments($this->templateFragmentInvalidationMap()->namesForTagStructure());
        $this->invalidateDocuments();
    }

    /** 配置/导航/单页等元数据 */
    public function invalidateMeta(): void
    {
        $this->bumpGeneration();
        $this->metaSqlCache()->clearFrontMeta();
        $this->tagSlugIndex()->forgetCaches();
        $this->clearPageAndBlockFiles();
        $this->invalidateTemplateFragments($this->templateFragmentInvalidationMap()->namesForMetaSave());
        Cache::delete(SiteNavService::ADMIN_FLAT_CACHE_KEY);
        $this->templateTagDataWarmup()->scheduleAfterInvalidate();
        $this->pageCacheWarmup()->scheduleDefault();
    }

    /**
     * 按 {pv:cache name} 精细失效（不 bump 全站代际）
     *
     * @param list<string> $names
     */
    public function invalidateTemplateFragments(array $names): void
    {
        if ($names === []) {
            return;
        }
        $this->templateFragmentCache()->forgetNames($names);
    }

    public function clearPageAndBlockFiles(): void
    {
        $this->clearPageAndBlockFilesSafe('page_search', function (): void {
            $this->siteMode()->clearPageAndSearchFiles();
        });
        $this->clearPageAndBlockFilesSafe('block_cache', function (): void {
            $this->templateBlockCache()->clearAll();
        });
        // 模板编译：代际 bump 已使旧 compile 条目失效；避免每次 meta 变更清空整目录
    }

    private function clearPageAndBlockFilesSafe(string $layer, \Closure $clear): void
    {
        try {
            $clear();
        } catch (\Throwable $e) {
            $this->auditLog()->write(
                'system',
                'front_cache_clear_failed',
                'cache',
                ['layer' => $layer, 'error' => $e->getMessage()],
                false
            );
            error_log('[PivArk front cache clear] ' . $layer . ': ' . $e->getMessage());
        }
    }

    private function forgetRequestLayers(): void
    {
        $this->tagDocumentSync()->forgetRequestCache();
        $this->documentPublic()->forgetListPublicRequestCache();
        $this->templateEngineState()->resetTagdocumentsPrefetch();
        $this->templateTagdocumentsBatch()->reset();
        $this->tagPublic()->forgetTemplateUrlVarsCache();
    }

    private function cacheConfig(): CacheConfigService
    {
        return app(CacheConfigService::class);
    }

    private function documentListMaterialized(): DocumentListMaterializedService
    {
        return app(DocumentListMaterializedService::class);
    }

    private function metaSqlCache(): MetaSqlCacheService
    {
        return app(MetaSqlCacheService::class);
    }

    private function hotCache(): HotCacheService
    {
        return app(HotCacheService::class);
    }

    private function tagSlugIndex(): TagSlugIndexService
    {
        return app(TagSlugIndexService::class);
    }

    private function siteMode(): SiteModeService
    {
        return app(SiteModeService::class);
    }

    private function pageCacheWarmup(): PageCacheWarmupService
    {
        return app(PageCacheWarmupService::class);
    }

    private function templateTagDataWarmup(): TemplateTagDataWarmupService
    {
        return app(TemplateTagDataWarmupService::class);
    }

    private function itemListCache(): ItemListCacheService
    {
        return app(ItemListCacheService::class);
    }

    private function itemFacetCache(): ItemFacetCacheService
    {
        return app(ItemFacetCacheService::class);
    }

    private function catalogListCache(): CatalogListCacheService
    {
        return app(CatalogListCacheService::class);
    }

    private function templateFragmentInvalidationMap(): TemplateFragmentInvalidationMap
    {
        return app(TemplateFragmentInvalidationMap::class);
    }

    private function templateFragmentCache(): TemplateFragmentCacheService
    {
        return app(TemplateFragmentCacheService::class);
    }

    private function templateBlockCache(): TemplateBlockCacheService
    {
        return app(TemplateBlockCacheService::class);
    }

    private function auditLog(): AuditLogService
    {
        return app(AuditLogService::class);
    }

    private function tagDocumentSync(): TagDocumentSync
    {
        return app(TagDocumentSync::class);
    }

    private function documentPublic(): DocumentPublicService
    {
        return app(DocumentPublicService::class);
    }

    private function templateEngineState(): TemplateEngineState
    {
        return app(TemplateEngineState::class);
    }

    private function templateTagdocumentsBatch(): TemplateTagdocumentsBatchService
    {
        return app(TemplateTagdocumentsBatchService::class);
    }

    private function tagPublic(): TagPublicService
    {
        return app(TagPublicService::class);
    }
}
