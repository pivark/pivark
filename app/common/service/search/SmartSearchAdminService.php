<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\support\ServiceResult;

use app\common\service\ai\AiProviderCatalog;
use app\common\service\ai\EmbeddingService;
use app\common\service\ai\KnowledgeSearchIntentService;
use app\common\service\config\AiConfigService;
use app\common\service\config\ConfigService;

/** 超级搜索 · 后台业务配置（与 AI 基础设施配置分离） */
final class SmartSearchAdminService
{

    public function __construct(
        private readonly SmartSearchConfigService $smartConfig,
        private readonly ConfigService $config,
        private readonly SearchConfigService $searchConfig,
        private readonly AiConfigService $aiConfig,
        private readonly EmbeddingService $embedding,
        private readonly KnowledgeSearchIntentService $guidedIntent,
        private readonly AiProviderCatalog $aiCatalog,
        private readonly SearchDriverFactory $driverFactory,
    ) {
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_merge(
            $this->smartConfig->keys(),
            [
                'search_ai_answer_on',
                'search_ai_doc_limit',
            ],
        );
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $out = [];
        foreach ($this->keys() as $key) {
            if (in_array($key, $this->smartConfig->keys(), true)) {
                $out[$key] = (string) $this->config->get($key, $this->smartConfig->defaultFor($key));
            } else {
                $out[$key] = (string) $this->config->get($key, $this->searchConfig->defaultFor($key));
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function metaForAdmin(): array
    {
        return [
            'cfg'             => $this->all(),
            'ai'              => $this->aiStatusPanel(),
            'guided_enabled'  => $this->guidedIntent->isEnabled() ? '1' : '0',
            'guided_rules'    => $this->guidedIntent->rulesForAdmin(),
            'synonyms'        => $this->smartConfig->synonyms(),
            'example_queries' => $this->smartConfig->exampleQueries(),
        ];
    }

    /**
     * AI 基础设施状态（密钥等在「AI 配置」维护）
     *
     * @return array<string, mixed>
     */
    public function aiStatusPanel(): array
    {
        $provider  = $this->aiConfig->provider();
        $catalog   = $this->aiCatalog->all()[$provider] ?? [];
        $embedding = $this->embedding->coverageForAdmin();

        return [
            'installed'       => 1,
            'enabled'         => $this->aiConfig->isEnabled() ? 1 : 0,
            'configured'      => $this->aiConfig->activeProviderConfigured() ? 1 : 0,
            'provider'        => $provider,
            'provider_name'   => (string) ($catalog['name'] ?? $provider),
            'model'           => $this->aiConfig->providerModel($provider),
            'settings_path'   => '/system/ai-config',
            'embedding'       => $embedding,
            'vector_enabled'  => (int) ($this->smartConfig->vectorEnabled() ? 1 : 0),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $smartPayload = $this->smartConfig->extractSavePayload($data);
        foreach ($smartPayload as $key => $value) {
            $this->config->set($key, $value);
        }

        if (array_key_exists('search_ai_answer_on', $data)) {
            $this->config->set(
                'search_ai_answer_on',
                !empty($data['search_ai_answer_on']) ? '1' : '0',
            );
        }
        if (array_key_exists('search_ai_doc_limit', $data)) {
            $this->config->set(
                'search_ai_doc_limit',
                (string) max(3, min(20, (int) ($data['search_ai_doc_limit'] ?? 8))),
            );
        }

        if (array_key_exists('ai_search_guided_enabled', $data)
            || array_key_exists('ai_search_guided_rules', $data)
            || array_key_exists('ai_search_guided_rules_json', $data)) {
            $guidedSave = $this->guidedIntent->saveAdmin($data);
            if (!$guidedSave->isOk()) {
                return $guidedSave;
            }
        }

        $this->config->forgetRequestCache();
        $this->driverFactory->reset();

        return ServiceResult::ok(null, '搜索配置已保存');
    }
}
