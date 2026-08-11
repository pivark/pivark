<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappAiGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\support\ServiceResult;
use app\common\service\ai\DocumentExtractService;
use app\common\service\ai\DocumentIngestService;
use app\common\service\ai\LlmChatService;
use app\common\service\ai\OcrService;
use app\common\service\config\AiConfigService;

final class WeappAiGateway
{

    public function __construct(
        private readonly AiConfigService $aiConfig,
        private readonly OcrService $ocr,
        private readonly LlmChatService $llmChat,
        private readonly DocumentIngestService $documentIngest,
        private readonly DocumentExtractService $documentExtract,
    ) {
    }

    public function aiConfigIsEnabled(): bool
    {
        return $this->aiConfig->isEnabled();
    }

    public function aiConfigActiveProviderConfigured(): bool
    {
        return $this->aiConfig->activeProviderConfigured();
    }

    /** @return array<string, mixed> */
    public function aiConfigPanelForAdmin(): array
    {
        return $this->aiConfig->panelForAdmin();
    }

    public function aiConfigOcrDriver(): string
    {
        return $this->aiConfig->ocrDriver();
    }

    public function aiConfigOcrEnabled(): bool
    {
        return $this->ocr->isEnabled();
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public function llmChat(array $messages, ?string $model = null, float $temperature = 0.3, ?string $provider = null): ServiceResult
    {
        return $this->llmChat->chat($messages, $model, $temperature, $provider);
    }

    public function aiDocumentIngestExtractFile(string $absolutePath, string $mime, string $originalName): string
    {
        return $this->documentIngest->extractFile($absolutePath, $mime, $originalName);
    }

    public function aiDocumentExtractFile(string $absolutePath, string $mime, string $originalName): ServiceResult
    {
        return $this->documentExtract->extractFile($absolutePath, $mime, $originalName);
    }
}
