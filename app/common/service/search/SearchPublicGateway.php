<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\service\content\ContentSearchService;
use app\common\support\QueryLimit;
use app\common\support\ServiceResult;

/**
 * 超级搜索 v1 API 可注入门面（api/home 唯一注入名 · search-ssot）。
 */
final class SearchPublicGateway
{
    public function __construct(
        private readonly SearchConfigService $searchConfig,
        private readonly ContentSearchService $contentSearch,
        private readonly SmartSearchOrchestratorService $orchestrator,
        private readonly SmartSearchSuggestService $suggestService,
        private readonly SmartSearchClickService $clicks,
        private readonly SearchDegradedGuard $degradedGuard,
    ) {
    }

    public function guardKeyword(string $keyword): ?ServiceResult
    {
        return $this->searchConfig->guardKeyword($keyword);
    }

    public function normalizeKeyword(string $keyword): string
    {
        return $this->contentSearch->normalizeKeyword($keyword);
    }

    /** @return array<string, mixed> */
    public function search(string $keyword, int $limit = QueryLimit::FRONT_LIST): array
    {
        return $this->orchestrator->apiSearch($keyword, $limit);
    }

    /** @return list<mixed> */
    public function suggest(string $prefix, int $limit = QueryLimit::STATS_TOP_SMALL): array
    {
        return $this->suggestService->suggestions($prefix, $limit);
    }

    public function logClick(string $keyword, string $type, int $id, string $url): void
    {
        $this->clicks->log($keyword, $type, $id, $url);
    }

    public function isDegraded(): bool
    {
        return $this->degradedGuard->isDegraded();
    }

    public function markExternalUnavailable(): void
    {
        $this->degradedGuard->markExternalUnavailable();
    }

    public function shouldRejectSqlFallback(string $keyword): bool
    {
        return $this->degradedGuard->shouldRejectSqlFallback($keyword);
    }

    public function prepareKeyword(string $keyword): string
    {
        return $this->orchestrator->prepareKeyword($keyword);
    }

    /** @return array<string, mixed> */
    public function buildPageContext(string $keyword, bool $keywordPrepared = false, int $productPage = 1): array
    {
        return $this->orchestrator->buildPageContext($keyword, $keywordPrepared, $productPage);
    }

    /** @return array{documents: array<string, mixed>, tags: list<mixed>, pages: list<mixed>} */
    public function searchPublicDocuments(string $keyword, int $page, int $limit, array $options = []): array
    {
        return $this->contentSearch->searchPublic($keyword, $page, $limit, $options);
    }

    /** @param array<string, mixed> $smart */
    public function recordPageOutcome(string $keyword, int $docTotal, int $productTotal, array $smart = []): void
    {
        $this->orchestrator->recordPageOutcome($keyword, $docTotal, $productTotal, $smart);
    }
}
