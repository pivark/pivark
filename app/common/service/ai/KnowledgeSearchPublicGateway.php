<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai;

use app\common\enum\ApiErrorCode;

use app\common\support\ServiceResult;


use app\common\service\config\AiConfigService;

/**
 * 前台知识搜索（RAG）v1 API 可注入门面。
 */
final class KnowledgeSearchPublicGateway
{

    public function __construct(
        private readonly AiConfigService $aiConfig,
        private readonly KnowledgeSearchService $knowledgeSearch,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->aiConfig->isEnabled();
    }

    public function smartSearch(string $keyword): ServiceResult
    {
        return $this->knowledgeSearch->smartSearch($keyword);
    }

    public function searchPayload(string $keyword): ServiceResult
    {
        if (!$this->isEnabled()) {
            return ServiceResult::fail('AI 未启用', ApiErrorCode::VALIDATION, null);
        }

        $result = $this->smartSearch(trim($keyword));
        if (!$result->isOk()) {
            return $result;
        }

        $data = $result->dataArray();

        return ServiceResult::ok([
            'answer'          => (string) ($data['answer'] ?? ''),
            'mode'            => (string) ($data['mode'] ?? ''),
            'sources'         => $data['sources'] ?? [],
            'sources_grouped' => $data['sources_grouped'] ?? [],
            'parsed'          => $data['parsed'] ?? [],
            'filter_chips'    => $data['filter_chips'] ?? [],
            'catalog_url'     => (string) ($data['catalog_url'] ?? ''),
            'chunk_citations' => $data['chunk_citations'] ?? [],
            'fallback'        => (int) ($data['fallback'] ?? 0),
        ], (string) ($result->message() ?? ''));
    }
}
