<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappSearchGateway — 插件侧搜索（LIKE 模式 + 超级搜索配置/会话/同义词/点击加权）
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\service\content\ContentSearchService;
use app\common\service\search\DocumentAddonPlainTextCollector;
use app\common\service\search\SearchTextSanitizer;
use app\common\service\search\SmartSearchClickService;
use app\common\service\search\SmartSearchConfigService;
use app\common\service\search\SmartSearchSessionService;
use app\common\service\search\SmartSearchSynonymService;

final class WeappSearchGateway
{

    public function __construct(
        private readonly ContentSearchService $contentSearch,
        private readonly DocumentAddonPlainTextCollector $addonPlainText,
        private readonly SearchTextSanitizer $textSanitizer,
        private readonly SmartSearchConfigService $smartSearchConfig,
        private readonly SmartSearchSessionService $smartSearchSession,
        private readonly SmartSearchSynonymService $smartSearchSynonym,
        private readonly SmartSearchClickService $smartSearchClick,
    ) {
    }

    public function searchLikePattern(string $keyword): string
    {
        return $this->contentSearch->likePattern($keyword);
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    public function searchDocumentAddonPlainTextLines(array $lines, int $minLen = 2, bool $dedupe = false): array
    {
        return $this->addonPlainText->linesFromPayload($lines, $minLen, $dedupe);
    }

    /** @return list<string> */
    public function searchDocumentAddonPlainTextFromPayload(mixed $payload, int $minLen = 2, bool $dedupe = false): array
    {
        return $this->addonPlainText->linesFromPayload($payload, $minLen, $dedupe);
    }

    public function searchMaskContactLine(string $line): string
    {
        return $this->textSanitizer->maskLine($line);
    }

    public function smartSearchLang(): string
    {
        return $this->smartSearchConfig->lang();
    }

    public function smartSearchRelaxMode(): string
    {
        return $this->smartSearchConfig->relaxMode();
    }

    public function smartSearchFallbackEnabled(): bool
    {
        return $this->smartSearchConfig->fallbackEnabled();
    }

    public function smartSearchParamLogic(): string
    {
        return $this->smartSearchConfig->paramLogic();
    }

    /** @return array<string, int> */
    public function smartSearchScoreWeights(): array
    {
        return $this->smartSearchConfig->scoreWeights();
    }

    /** @return array<string, mixed> */
    public function smartSearchSessionMergeKeyword(string $keyword): array
    {
        return $this->smartSearchSession->mergeKeyword($keyword);
    }

    /** @param array<string, mixed> $parsed */
    public function smartSearchSessionPersist(string $keyword, array $parsed): void
    {
        $this->smartSearchSession->persist($keyword, $parsed);
    }

    public function smartSearchSynonymExpand(string $keyword): string
    {
        return $this->smartSearchSynonym->expand($keyword);
    }

    /**
     * @param array<string, mixed> $parsed
     * @param array<string, mixed> $defs
     *
     * @return array<string, mixed>
     */
    public function smartSearchSynonymApplyToParsed(array $parsed, array $defs): array
    {
        return $this->smartSearchSynonym->applyToParsed($parsed, $defs);
    }

    /** @return array<string, float> */
    public function smartSearchProductClickScores(string $phrase): array
    {
        return $this->smartSearchClick->productClickScores($phrase);
    }
}
