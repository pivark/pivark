<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\service\hook\HookService;

/** SmartSearchOrchestratorService 辅助能力依赖包（冗余审计 §2 batch 7） */
final class SmartSearchAssistDeps
{
    public function __construct(
        public readonly SmartSearchSuggestService $suggest,
        public readonly SmartSearchSessionService $session,
        public readonly SmartSearchCacheService $cache,
        public readonly SmartSearchSynonymService $synonyms,
        public readonly SmartSearchAnswerFormatter $answerFormatter,
        public readonly SmartSearchAnalyticsService $analytics,
        public readonly HookService $hooks,
    ) {
    }
}
