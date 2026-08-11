<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\config;
use app\common\service\config\ConfigService;


use app\common\service\ai\AiProviderCatalog;

/** AI 服务配置：后台 configs 表优先，.env 可覆盖（本地开发） */
class AiConfigService
{

    public function __construct(
        private readonly AiProviderCatalog $aiProviderCatalog,
        private readonly ConfigService $configService,
    ) {
    }

    /** @return list<string> */
    public function configKeys(): array
    {
        $keys = [
            'ai_enabled',
            'ai_provider',
            'ai_auto_metadata_on_save',
            'ai_auto_chunk_on_save',
            'ai_extract_driver',
            'ai_chunk_max_chars',
            'ai_chunk_overlap',
            'ai_ocr_driver',
            'ai_tesseract_path',
            'ai_ocr_baidu_api_key',
            'ai_ocr_baidu_secret_key',
            'ai_process_attachments_on_save',
        ];
        foreach ($this->aiProviderCatalog->ids() as $id) {
            $keys[] = $id . '_api_key';
            $keys[] = $id . '_model';
            $keys[] = $id . '_base_url';
        }

        return $keys;
    }

    public function isEnabled(): bool
    {
        $env = trim((string) env('AI_ENABLED', ''));
        if ($env !== '') {
            return filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }

        return (string) $this->configService->get('ai_enabled', '0') === '1';
    }

    public function provider(): string
    {
        $env = strtolower(trim((string) env('AI_PROVIDER', '')));
        if ($env !== '' && $this->aiProviderCatalog->exists($env)) {
            return $env;
        }
        $cfg = strtolower(trim((string) $this->configService->get('ai_provider', 'deepseek')));

        return $this->aiProviderCatalog->exists($cfg) ? $cfg : 'deepseek';
    }

    public function providerApiKey(?string $provider = null): string
    {
        $provider = $provider ?? $this->provider();
        $envKey   = strtoupper($provider) . '_API_KEY';
        $env      = trim((string) env($envKey, ''));
        if ($env !== '') {
            return $env;
        }
        if ($provider === 'deepseek') {
            $legacy = trim((string) env('DEEPSEEK_API_KEY', ''));
            if ($legacy !== '') {
                return $legacy;
            }
        }

        return trim((string) $this->configService->get($provider . '_api_key', ''));
    }

    public function providerModel(?string $provider = null): string
    {
        $provider = $provider ?? $this->provider();
        $def      = $this->aiProviderCatalog->get($provider);
        $envKey   = strtoupper($provider) . '_MODEL';
        $env      = trim((string) env($envKey, ''));
        if ($env !== '') {
            return $env;
        }
        if ($provider === 'deepseek') {
            $legacy = trim((string) env('DEEPSEEK_MODEL', ''));
            if ($legacy !== '') {
                return $legacy;
            }
        }
        $cfg = trim((string) $this->configService->get($provider . '_model', ''));

        return $cfg !== '' ? $cfg : $def['default_model'];
    }

    public function providerBaseUrl(?string $provider = null): string
    {
        $provider = $provider ?? $this->provider();
        $def      = $this->aiProviderCatalog->get($provider);
        $envKey   = strtoupper($provider) . '_BASE_URL';
        $env      = rtrim(trim((string) env($envKey, '')), '/');
        if ($env !== '') {
            return $env;
        }
        if ($provider === 'deepseek') {
            $legacy = rtrim(trim((string) env('DEEPSEEK_BASE_URL', '')), '/');
            if ($legacy !== '') {
                return $legacy;
            }
        }
        $cfg = rtrim(trim((string) $this->configService->get($provider . '_base_url', '')), '/');

        return $cfg !== '' ? $cfg : rtrim($def['default_base_url'], '/');
    }

    public function providerConfigured(?string $provider = null): bool
    {
        $provider = $provider ?? $this->provider();
        if ($this->providerApiKey($provider) === '') {
            return false;
        }
        // 自定义须同时有地址与模型，避免选了「自定义」却仍走空默认
        if ($provider === 'custom') {
            return $this->providerBaseUrl('custom') !== '' && $this->providerModel('custom') !== '';
        }

        return true;
    }

    public function activeProviderConfigured(): bool
    {
        return $this->isEnabled() && $this->providerConfigured();
    }

    /** @return list<array<string, string>> */
    public function providersForAdmin(): array
    {
        $list = [];
        foreach ($this->aiProviderCatalog->all() as $id => $def) {
            $list[] = [
                'id'               => $id,
                'name'             => $def['name'],
                'model'            => $this->providerModel($id),
                'base_url'         => $this->providerBaseUrl($id),
                'configured'       => $this->providerConfigured($id) ? '1' : '0',
                'default_model'    => $def['default_model'],
                'default_base_url' => $def['default_base_url'],
                'hint'             => (string) ($def['hint'] ?? ''),
            ];
        }

        return $list;
    }

    public function deepseekApiKey(): string
    {
        return $this->providerApiKey('deepseek');
    }

    public function deepseekModel(): string
    {
        return $this->providerModel('deepseek');
    }

    public function deepseekBaseUrl(): string
    {
        return $this->providerBaseUrl('deepseek');
    }

    public function autoMetadataOnSave(): bool
    {
        return $this->isEnabled() && (string) $this->configService->get('ai_auto_metadata_on_save', '0') === '1';
    }

    public function autoChunkOnSave(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $v = (string) $this->configService->get('ai_auto_chunk_on_save', '');

        return $v === '' ? true : $v === '1';
    }

    public function extractDriver(): string
    {
        $v = trim((string) $this->configService->get('ai_extract_driver', ''));

        return $v !== '' ? strtolower($v) : 'php';
    }

    public function chunkMaxChars(): int
    {
        $v = (int) $this->configService->get('ai_chunk_max_chars', '1200');

        return $v > 0 ? $v : 1200;
    }

    public function chunkOverlap(): int
    {
        return max(0, (int) $this->configService->get('ai_chunk_overlap', '100'));
    }

    public function ocrDriver(): string
    {
        $v = strtolower(trim((string) $this->configService->get('ai_ocr_driver', '')));

        return $v !== '' ? $v : 'none';
    }

    public function tesseractPath(): string
    {
        return trim((string) $this->configService->get('ai_tesseract_path', ''));
    }

    public function baiduOcrApiKey(): string
    {
        return trim((string) $this->configService->get('ai_ocr_baidu_api_key', ''));
    }

    public function baiduOcrSecretKey(): string
    {
        return trim((string) $this->configService->get('ai_ocr_baidu_secret_key', ''));
    }

    public function processAttachmentsOnSave(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $v = (string) $this->configService->get('ai_process_attachments_on_save', '');

        return $v === '' ? true : $v === '1';
    }

    /** 后台保存用：密钥留空表示不修改 */
    public function defaultFor(string $key): string
    {
        if (preg_match('/^([a-z]+)_(model|base_url)$/', $key, $m) && $this->aiProviderCatalog->exists($m[1])) {
            $def = $this->aiProviderCatalog->get($m[1]);

            return $m[2] === 'model' ? $def['default_model'] : $def['default_base_url'];
        }

        return match ($key) {
            'ai_enabled' => '0',
            'ai_provider' => 'deepseek',
            'ai_auto_metadata_on_save' => '0',
            'ai_auto_chunk_on_save' => '1',
            'ai_extract_driver' => 'php',
            'ai_chunk_max_chars' => '1200',
            'ai_chunk_overlap' => '100',
            'ai_ocr_driver' => 'none',
            'ai_tesseract_path' => '',
            'ai_ocr_baidu_api_key' => '',
            'ai_ocr_baidu_secret_key' => '',
            'ai_process_attachments_on_save' => '1',
            default => '',
        };
    }

    /** @return array<string, mixed> */
    public function publicMeta(): array
    {
        $active = $this->provider();

        return [
            'ai_enabled'                     => $this->isEnabled() ? '1' : '0',
            'ai_provider'                    => $active,
            'ai_provider_name'               => $this->aiProviderCatalog->get($active)['name'],
            'ai_provider_configured'         => $this->providerConfigured($active) ? '1' : '0',
            'providers'                      => $this->providersForAdmin(),
            'ai_auto_metadata_on_save'       => $this->autoMetadataOnSave() ? '1' : '0',
            'ai_auto_chunk_on_save'          => $this->autoChunkOnSave() ? '1' : '0',
            'ai_extract_driver'              => $this->extractDriver(),
            'ai_chunk_max_chars'             => (string) $this->chunkMaxChars(),
            'ai_chunk_overlap'               => (string) $this->chunkOverlap(),
            'ai_ocr_driver'                  => $this->ocrDriver(),
            'ai_tesseract_path'              => $this->tesseractPath(),
            'ai_ocr_baidu_configured'        => ($this->baiduOcrApiKey() !== '' && $this->baiduOcrSecretKey() !== '') ? '1' : '0',
            'ai_process_attachments_on_save'   => $this->processAttachmentsOnSave() ? '1' : '0',
            // 兼容旧前端字段
            'deepseek_model'                 => $this->providerModel('deepseek'),
            'deepseek_base_url'              => $this->providerBaseUrl('deepseek'),
            'deepseek_configured'            => $this->providerConfigured('deepseek') ? '1' : '0',
        ];
    }

    /**
     * AI 应用插件后台概览：安装/启用/密钥状态（供 tender 等）
     *
     * @return array<string, mixed>
     */
    public function panelForAdmin(): array
    {
        $provider = $this->provider();
        $catalog  = $this->aiProviderCatalog->all()[$provider] ?? [];

        return [
            'installed'     => 1,
            'enabled'       => $this->isEnabled() ? 1 : 0,
            'configured'    => $this->activeProviderConfigured() ? 1 : 0,
            'provider'      => $provider,
            'provider_name' => (string) ($catalog['name'] ?? $provider),
            'model'         => $this->providerModel($provider),
            'settings_path' => '/system/ai-config',
            'demo_fallback' => (!$this->isEnabled() || !$this->activeProviderConfigured()) ? 1 : 0,
        ];
    }
}
