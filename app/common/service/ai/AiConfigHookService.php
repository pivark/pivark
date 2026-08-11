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
use app\common\service\search\SearchConfigService;
use app\common\service\search\SmartSearchConfigService;

/** L1 ai_config Hook：文档分块 + 超级搜索增强 */
class AiConfigHookService
{

    public function __construct(
        private readonly AiConfigService $aiConfigService,
        private readonly AiConfigProcessService $aiConfigProcessService,
        private readonly SearchConfigService $searchConfigService,
        private readonly SmartSearchConfigService $smartSearchConfigService,
        private readonly KnowledgeSearchService $knowledgeSearchService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function onDocumentAfterSave(array $payload): void
    {
        if (!$this->aiConfigService->isEnabled()) {
            return;
        }
        $documentId = (int) ($payload['document_id'] ?? 0);
        if ($documentId < 1) {
            return;
        }
        $this->aiConfigProcessService->processDocument($documentId, true, true);
    }

    /**
     * @param array<string, mixed> $payload 需含 keyword；可写 smart.answer / smart.enabled
     */
    public function onSearchEnhance(array &$payload): void
    {
        if (!$this->searchConfigService->isAiAnswerEnabled() && !$this->smartSearchConfigService->fallbackEnabled()) {
            return;
        }
        if (!$this->aiConfigService->isEnabled()) {
            return;
        }
        $keyword = trim((string) ($payload['keyword'] ?? ''));
        if ($keyword === '') {
            return;
        }
        if ((int) (($payload['smart']['enabled'] ?? 0)) === 1) {
            return;
        }
        $res = $this->knowledgeSearchService->smartSearch($keyword);
        if (!$res->isOk()) {
            return;
        }
        if (!isset($payload['smart']) || !is_array($payload['smart'])) {
            return;
        }
        $payload['smart']['answer']  = (string) ($res['answer'] ?? '');
        $payload['smart']['enabled'] = 1;
        $payload['smart']['mode']    = (string) ($res['mode'] ?? 'keyword_llm');
        $payload['smart']['sources'] = is_array($res['sources'] ?? null) ? $res['sources'] : [];
        $payload['smart']['sources_grouped'] = is_array($res['sources_grouped'] ?? null) ? $res['sources_grouped'] : [];
        $payload['smart']['parsed'] = is_array($res['parsed'] ?? null) ? $res['parsed'] : [];
        $payload['smart']['filter_chips'] = is_array($res['filter_chips'] ?? null) ? $res['filter_chips'] : [];
        $payload['smart']['catalog_url'] = (string) ($res['catalog_url'] ?? '');
        $payload['smart']['fallback'] = (int) ($res['fallback'] ?? 0);
        $payload['smart']['chunk_citations'] = is_array($res['chunk_citations'] ?? null) ? $res['chunk_citations'] : [];
    }
}
