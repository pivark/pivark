<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappContentGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\service\content\ClipboardUrlInsightService;
use app\common\service\content\ContentSearchService;

final class WeappContentGateway
{

    public function __construct(
        private readonly ClipboardUrlInsightService $clipboardInsight,
        private readonly ContentSearchService $contentSearch,
    ) {
    }

    public function contentClipboardIsFetchableUrl(string $url): bool
    {
        return $this->clipboardInsight->isFetchableUrl($url);
    }

    /** @return array<string, mixed> */
    public function contentClipboardAnalyze(string $url, string $raw = ''): array
    {
        return $this->clipboardInsight->analyze($url, $raw);
    }

    public function contentSearchLikePattern(string $keyword): string
    {
        return $this->contentSearch->likePattern($keyword);
    }

    public function contentSearchNormalizeKeyword(string $keyword): string
    {
        return $this->contentSearch->normalizeKeyword($keyword);
    }

    /** @return array<string, mixed> */
    public function contentSearchPublic(string $keyword, int $page, int $limit): array
    {
        return $this->contentSearch->searchPublic($keyword, $page, $limit);
    }
}
