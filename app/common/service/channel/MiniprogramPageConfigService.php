<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * 小程序页面装修 Facade
 */
declare(strict_types=1);

namespace app\common\service\channel;
use app\common\service\channel\MiniprogramPageCoreService;
use app\common\service\channel\MiniprogramPageDefaultsService;

use app\common\support\ServiceResult;

class MiniprogramPageConfigService
{

    public function __construct(
        private readonly MiniprogramPageDefaultsService $miniprogramPageDefaultsService,
        private readonly MiniprogramPageCoreService $miniprogramPageCoreService,
    ) {
    }

    public const PAGE_HOME = 'home';
    public const PAGE_TAGS = 'tags';
    public const PAGE_PRODUCTS = 'products';
    public const PAGE_MINE = 'mine';

    public function blockCategories(): array {
        return $this->miniprogramPageDefaultsService->blockCategories();
    }

    public function decorPages(): array {
        return $this->miniprogramPageDefaultsService->decorPages();
    }

    public function defaultTheme(): array {
        return $this->miniprogramPageDefaultsService->defaultTheme();
    }

    public function defaultHomeBlocks(): array {
        return $this->miniprogramPageDefaultsService->defaultHomeBlocks();
    }

    public function defaultShopHomeBlocks(): array {
        return $this->miniprogramPageDefaultsService->defaultShopHomeBlocks();
    }

    public function defaultProductsBlocks(): array {
        return $this->miniprogramPageDefaultsService->defaultProductsBlocks();
    }

    public function defaultMineBlocks(): array {
        return $this->miniprogramPageDefaultsService->defaultMineBlocks();
    }

    public function theme(): array {
        return $this->miniprogramPageCoreService->theme();
    }

    public function homeBlocks(): array {
        return $this->miniprogramPageCoreService->homeBlocks();
    }

    public function shopHomeBlocks(): array {
        return $this->miniprogramPageCoreService->shopHomeBlocks();
    }

    public function defaultBlocksForPage(string $page): array {
        return $this->miniprogramPageDefaultsService->defaultBlocksForPage($page);
    }

    public function productsBlocks(): array {
        return $this->miniprogramPageCoreService->productsBlocks();
    }

    public function mineBlocks(): array {
        return $this->miniprogramPageCoreService->mineBlocks();
    }

    public function defaultTagsBlocks(): array {
        return $this->miniprogramPageDefaultsService->defaultTagsBlocks();
    }

    public function tagsBlocks(): array {
        return $this->miniprogramPageCoreService->tagsBlocks();
    }

    public function defaultTabBar(): array {
        return $this->miniprogramPageDefaultsService->defaultTabBar();
    }

    public function defaultShopTabBar(): array {
        return $this->miniprogramPageDefaultsService->defaultShopTabBar();
    }

    public function tabBar(): array {
        return $this->miniprogramPageCoreService->tabBar();
    }

    public function normalizeTabBar(array $in): array {
        return $this->miniprogramPageCoreService->normalizeTabBar($in);
    }

    public function tabBarForPublic(): array {
        return $this->miniprogramPageCoreService->tabBarForPublic();
    }

    public function blocksForPage(string $page): array {
        return $this->miniprogramPageCoreService->blocksForPage($page);
    }

    public function publicHomePayload(): array {
        return $this->miniprogramPageCoreService->publicHomePayload();
    }

    public function publicTagsPayload(): array {
        return $this->miniprogramPageCoreService->publicTagsPayload();
    }

    public function publicProductsPayload(): array {
        return $this->miniprogramPageCoreService->publicProductsPayload();
    }

    public function publicMinePayload(): array {
        return $this->miniprogramPageCoreService->publicMinePayload();
    }

    public function contentFlowStatus(): array {
        return $this->miniprogramPageCoreService->contentFlowStatus();
    }

    public function adminMeta(): array {
        return $this->miniprogramPageCoreService->adminMeta();
    }

    public function allDefaultProps(): array {
        return $this->miniprogramPageDefaultsService->allDefaultProps();
    }

    public function defaultPropsForType(string $type): array {
        return $this->miniprogramPageDefaultsService->defaultPropsForType($type);
    }

    public function parseAdminSavePayload(array $post = [], string $rawBody = ''): array {
        return $this->miniprogramPageCoreService->parseAdminSavePayload($post, $rawBody);
    }

    public function saveAdmin(array $data): ServiceResult {
        return $this->miniprogramPageCoreService->saveAdmin($data);
    }

    public function blockCatalog(): array {
        return $this->miniprogramPageDefaultsService->blockCatalog();
    }

    public function normalizeTheme(array $in): array {
        return $this->miniprogramPageCoreService->normalizeTheme($in);
    }

    public function normalizeBlocks(array $rows, string $page = self::PAGE_HOME): array {
        return $this->miniprogramPageCoreService->normalizeBlocks($rows, $page);
    }

    public function buildAdminPreview(array $themeIn, array $blocksIn, string $page = self::PAGE_HOME): array {
        return $this->miniprogramPageCoreService->buildAdminPreview($themeIn, $blocksIn, $page);
    }
}
