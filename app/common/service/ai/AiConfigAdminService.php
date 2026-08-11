<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai;

use app\common\support\ServiceResult;

use app\common\service\ai\AiProviderCatalog;
use app\common\service\config\AiConfigService;
use app\common\service\config\ConfigService;

/** L1 ai_config — 后台可编辑配置（configs 表；.env 可覆盖） */
class AiConfigAdminService
{

    public function __construct(
        private readonly AiConfigService $aiConfigService,
        private readonly AiProviderCatalog $aiProviderCatalog,
        private readonly ConfigService $configService,
    ) {
    }

    /** @return array<string, mixed> */
    public function allForAdmin(): array
    {
        $meta = $this->aiConfigService->publicMeta();
        foreach ($this->aiProviderCatalog->ids() as $id) {
            $meta[$id . '_api_key'] = '';
        }
        return $meta;
    }

    /** @return list<string> */
    public function envOverrideKeys(): array
    {
        $keys = [];
        if (trim((string) env('AI_ENABLED', '')) !== '') {
            $keys[] = 'ai_enabled';
        }
        if (trim((string) env('AI_PROVIDER', '')) !== '') {
            $keys[] = 'ai_provider';
        }
        foreach ($this->aiProviderCatalog->ids() as $id) {
            $upper = strtoupper($id);
            if (trim((string) env($upper . '_API_KEY', '')) !== '') {
                $keys[] = $id . '_api_key';
            }
            if (trim((string) env($upper . '_MODEL', '')) !== '') {
                $keys[] = $id . '_model';
            }
            if (trim((string) env($upper . '_BASE_URL', '')) !== '') {
                $keys[] = $id . '_base_url';
            }
        }
        if (trim((string) env('DEEPSEEK_API_KEY', '')) !== '') {
            $keys[] = 'deepseek_api_key';
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $provider = strtolower(trim((string) ($data['ai_provider'] ?? 'deepseek')));
        if (!$this->aiProviderCatalog->exists($provider)) {
            $provider = 'deepseek';
        }

        $payload = [
            'ai_enabled'                     => !empty($data['ai_enabled']) ? '1' : '0',
            'ai_auto_metadata_on_save'       => !empty($data['ai_auto_metadata_on_save']) ? '1' : '0',
            'ai_auto_chunk_on_save'          => !empty($data['ai_auto_chunk_on_save']) ? '1' : '0',
            'ai_extract_driver'              => strtolower(trim((string) ($data['ai_extract_driver'] ?? 'php'))) ?: 'php',
            'ai_chunk_max_chars'             => (string) max(400, (int) ($data['ai_chunk_max_chars'] ?? 1200)),
            'ai_chunk_overlap'               => (string) max(0, (int) ($data['ai_chunk_overlap'] ?? 100)),
            'ai_ocr_driver'                  => strtolower(trim((string) ($data['ai_ocr_driver'] ?? 'none'))) ?: 'none',
            'ai_tesseract_path'              => trim((string) ($data['ai_tesseract_path'] ?? '')),
            'ai_process_attachments_on_save' => !empty($data['ai_process_attachments_on_save']) ? '1' : '0',
            'ai_provider'                    => $provider,
        ];

        foreach ($this->aiProviderCatalog->all() as $id => $def) {
            $model = trim((string) ($data[$id . '_model'] ?? ''));
            $base  = rtrim(trim((string) ($data[$id . '_base_url'] ?? '')), '/');
            if ($id === 'custom') {
                $payload[$id . '_model']    = $model;
                $payload[$id . '_base_url'] = $base;
            } else {
                $payload[$id . '_model']    = $model !== '' ? $model : $def['default_model'];
                $payload[$id . '_base_url'] = $base !== '' ? $base : $def['default_base_url'];
            }
            $newKey = trim((string) ($data[$id . '_api_key'] ?? ''));
            if ($newKey !== '') {
                $payload[$id . '_api_key'] = $newKey;
            }
        }

        if ($provider === 'custom') {
            $customModel = (string) ($payload['custom_model'] ?? '');
            $customBase  = (string) ($payload['custom_base_url'] ?? '');
            $customKey   = trim((string) ($data['custom_api_key'] ?? ''));
            if ($customKey === '') {
                $customKey = $this->aiConfigService->providerApiKey('custom');
            }
            if ($customBase === '' || $customModel === '' || $customKey === '') {
                return ServiceResult::fail('自定义服务商须填写 API 地址、模型名与 API Key');
            }
        }

        $baiduKey = trim((string) ($data['ai_ocr_baidu_api_key'] ?? ''));
        if ($baiduKey !== '') {
            $payload['ai_ocr_baidu_api_key'] = $baiduKey;
        }
        $baiduSecret = trim((string) ($data['ai_ocr_baidu_secret_key'] ?? ''));
        if ($baiduSecret !== '') {
            $payload['ai_ocr_baidu_secret_key'] = $baiduSecret;
        }

        foreach ($payload as $key => $value) {
            $this->configService->set($key, $value);
        }

        $this->configService->forgetRequestCache();

        return ServiceResult::ok(null, '配置已保存');
    }
}
