<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai;
use app\common\service\ai\OcrService;
use app\common\service\ai\DocumentExtractService;
use app\common\service\ai\DocumentAttachmentSourceService;
use app\common\service\ai\DocumentPlainTextService;

use app\common\service\config\AiConfigService;

/**
 * 后台入库：正文 HTML + 附件解析 + 必要时 OCR
 * 前台仅通过 ai_chunks / 搜索 API 检索，不执行解析
 */
class DocumentIngestService
{

    public function __construct(
        private readonly DocumentPlainTextService $documentPlainTextService,
        private readonly AiConfigService $aiConfigService,
        private readonly DocumentAttachmentSourceService $documentAttachmentSourceService,
        private readonly DocumentExtractService $documentExtractService,
        private readonly OcrService $ocrService,
    ) {
    }

    /**
     * @param array<string, mixed> $documentRow
     */
    public function collectPlainText(array $documentRow, int $documentId): string
    {
        $parts = [];
        $body  = $this->documentPlainTextService->fromDocumentRow($documentRow);
        if ($body !== '') {
            $parts[] = $body;
        }

        if (!$this->aiConfigService->processAttachmentsOnSave()) {
            return trim(implode("\n\n", $parts));
        }

        foreach ($this->documentAttachmentSourceService->listForDocument($documentId) as $file) {
            $extracted = $this->extractFile($file['path'], $file['mime'], $file['name']);
            if ($extracted !== '') {
                $parts[] = '【附件 ' . $file['name'] . "】\n" . $extracted;
            }
        }

        return trim(implode("\n\n", $parts));
    }

    public function extractFile(string $absolutePath, string $mime, string $originalName): string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $res = $this->documentExtractService->extractFile($absolutePath, $mime, $originalName);
        $text = $res->isOk() ? trim((string) ($res['text'] ?? '')) : '';

        if ($this->ocrService->needsOcr($mime, $ext, $text)) {
            $ocr = $this->ocrService->recognize($absolutePath, $mime);
            if ($ocr->isOk()) {
                $ocrText = trim((string) ($ocr['text'] ?? ''));
                if ($ocrText !== '') {
                    $text = $text === '' ? $ocrText : $text . "\n" . $ocrText;
                }
            }
        }

        return $text;
    }
}