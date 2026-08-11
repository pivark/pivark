<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai;

use app\common\service\config\ConfigService;
use app\common\service\content\ContentSearchService;
use app\common\service\document\DocumentPublicService;
use app\common\service\plugin\PluginService;
use app\common\service\search\SearchConfigService;
use app\common\service\search\SmartSearchOrchestratorService;

/** KnowledgeSearchService 站内召回依赖包（冗余审计 §2 batch 7） */
final class KnowledgeSearchRecallDeps
{
    public function __construct(
        public readonly SmartSearchOrchestratorService $smartSearchOrchestrator,
        public readonly SearchConfigService $searchConfig,
        public readonly ContentSearchService $contentSearch,
        public readonly ConfigService $config,
        public readonly PluginService $plugins,
        public readonly DocumentPublicService $documents,
    ) {
    }
}
