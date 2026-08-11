<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * 静态 HTML Facade — 委托 StaticHtmlGeneratorService / StaticHtmlManifestService
 */
declare(strict_types=1);

namespace app\common\service\static;
use app\common\service\static\StaticHtmlManifestService;
use app\common\service\static\StaticHtmlGeneratorService;

use app\common\service\site\SiteUrlModeService;
use app\common\support\ServiceResult;

class StaticHtmlService
{

    public function __construct(
        private readonly SiteUrlModeService $siteUrlModeService,
        private readonly StaticHtmlGeneratorService $staticHtmlGeneratorService,
        private readonly StaticHtmlManifestService $staticHtmlManifestService,
    ) {
    }

    public function enabled(): bool
    {
        return $this->siteUrlModeService->isStatic();
    }

    /**
     * @return array{written:int,skipped:int,deleted:int,errors:list<string>}
     */
    public function rebuildAll(bool $purgeFirst = true): array
    {
        return $this->staticHtmlGeneratorService->rebuildAll($purgeFirst);
    }

    public function syncAfterConfigChange(): void
    {
        $this->staticHtmlGeneratorService->syncAfterConfigChange();
    }

    public function syncAfterGlobalEmbedChange(): void
    {
        $this->staticHtmlGeneratorService->syncAfterGlobalEmbedChange();
    }

    public function syncAfterArticleChange(int $id, string $scene = 'publish'): void
    {
        $this->staticHtmlGeneratorService->syncAfterArticleChange($id, $scene);
    }

    /**
     * @return ServiceResult
     */
    public function generateAdmin(string $scope, int $id = 0): ServiceResult
    {
        return $this->staticHtmlGeneratorService->generateAdmin($scope, $id);
    }

    public function syncAfterArticleDelete(int $id, ?array $row = null): void
    {
        $this->staticHtmlGeneratorService->syncAfterArticleDelete($id, $row);
    }

    public function syncAfterSitePageChange(int $id, ?string $oldPath = null): void
    {
        $this->staticHtmlGeneratorService->syncAfterSitePageChange($id, $oldPath);
    }

    public function syncAfterSitePageDelete(?array $row): void
    {
        $this->staticHtmlGeneratorService->syncAfterSitePageDelete($row);
    }

    public function syncAfterTagChange(int $id): void
    {
        $this->staticHtmlGeneratorService->syncAfterTagChange($id);
    }

    public function syncAfterTagDelete(?array $row): void
    {
        $this->staticHtmlGeneratorService->syncAfterTagDelete($row);
    }

    public function syncAfterNavChange(): void
    {
        $this->staticHtmlGeneratorService->syncAfterNavChange();
    }

    public function syncAfterSlideChange(): void
    {
        $this->staticHtmlGeneratorService->syncAfterSlideChange();
    }

    /** @param array<string,mixed> $row @return list<int> */
    public function tagPageNumbers(array $row): array
    {
        return $this->staticHtmlGeneratorService->tagPageNumbers($row);
    }

    /**
     * @param array{t:string,id?:int,page?:int,k?:string} $item
     * @param array{written:int,skipped:int,deleted:int,errors:list<string>} $stats
     */
    public function runWorkItem(array $item, array &$stats): void
    {
        $this->staticHtmlGeneratorService->runWorkItem($item, $stats);
    }

    public function publicUrlForRelative(string $relativePath): string
    {
        return $this->staticHtmlManifestService->publicUrlForRelative($relativePath);
    }

    /**
     * @param array{written:int,skipped:int,deleted:int,errors:list<string>}|null $stats
     */
    public function purgeAll(?array &$stats = null): void
    {
        $this->staticHtmlManifestService->purgeAll($stats);
    }
}
