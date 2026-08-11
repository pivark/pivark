<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\service\config\AiConfigService;
use app\common\service\content\ContentSearchService;

/** SmartSearchOrchestratorService 核心配置/检索依赖包（冗余审计 §2 batch 7） */
final class SmartSearchStackDeps
{
    public function __construct(
        public readonly SmartSearchConfigService $smartConfig,
        public readonly SearchConfigService $searchConfig,
        public readonly ContentSearchService $contentSearch,
        public readonly AiConfigService $aiConfig,
    ) {
    }
}
