<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai;

use app\common\service\config\AiConfigService;
use app\common\service\search\SmartSearchConfigService;

/** KnowledgeSearchService AI/意图/向量依赖包（冗余审计 §2 batch 7） */
final class KnowledgeSearchAiDeps
{
    public function __construct(
        public readonly AiConfigService $aiConfig,
        public readonly SmartSearchConfigService $smartSearchConfig,
        public readonly KnowledgeSearchIntentService $intent,
        public readonly ChunkVectorSearchService $chunkVector,
        public readonly LlmChatService $llmChat,
    ) {
    }
}
