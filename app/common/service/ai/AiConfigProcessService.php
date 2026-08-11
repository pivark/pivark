<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai;

use app\common\model\AiConfigProcess;
use app\common\model\Document;
use app\common\service\config\AiConfigService;
use app\common\support\ServiceResult;

/** L1 ai_config：提取正文 → 分块 →（可选）元数据 */
class AiConfigProcessService
{

    public function __construct(
        private readonly AiConfigService $aiConfigService,
        private readonly DocumentIngestService $documentIngestService,
        private readonly AIMetadataService $aIMetadataService,
        private readonly ChunkService $chunkService,
        private readonly OcrService $ocrService,
    ) {
    }

    private static bool $running = false;

    /**
     * @return ServiceResult
     */
    public function processDocument(int $documentId, bool $runMetadata = true, bool $onlyEmptyMeta = true): ServiceResult
    {
        if (!$this->aiConfigService->isEnabled()) {
            return ServiceResult::fail('AI 未启用');
        }
        if ($documentId < 1) {
            return ServiceResult::fail('参数错误');
        }
        if (self::$running) {
            return ServiceResult::fail('处理中');
        }

        $row = Document::where('id', $documentId)->whereNull('deleted_at')->find()?->toArray();
        if (!$row) {
            return ServiceResult::fail('文档不存在');
        }

        self::$running = true;
        $this->setStatus($documentId, 'processing', 0, 0, null);

        try {
            $plain = $this->documentIngestService->collectPlainText($row, $documentId);
            $plainChars = mb_strlen($plain);

            if ($plain === '') {
                $this->setStatus($documentId, 'extract_failed', 0, 0, '正文与附件均未提取到文字');
                return ServiceResult::fail('正文与附件均未提取到文字（扫描件请启用 OCR）');
            }

            if (!$this->aiConfigService->autoChunkOnSave()) {
                $this->setStatus($documentId, 'ok', $plainChars, 0, null);
                if ($runMetadata && $this->aiConfigService->autoMetadataOnSave()) {
                    $this->aIMetadataService->applyForDocument($documentId, $onlyEmptyMeta);
                }
                return ServiceResult::ok(['plain_chars' => $plainChars, 'chunk_count' => 0, 'status' => 'ok'], '已跳过自动分块');
            }

            $chunkRes = $this->chunkService->rebuildForDocument($documentId, $plain);
            if (!$chunkRes->isOk()) {
                $this->setStatus($documentId, 'chunk_failed', $plainChars, 0, (string) ($chunkRes->message() ?? ''));
                return ServiceResult::fail((string) ($chunkRes->message() ?? '分块失败'));
            }

            $count = (int) ($chunkRes['count'] ?? 0);
            $this->setStatus($documentId, 'ok', $plainChars, $count, null);

            if ($runMetadata && $this->aiConfigService->autoMetadataOnSave()) {
                $meta = $this->aIMetadataService->applyForDocument($documentId, $onlyEmptyMeta);
                if (!$meta->isOk() && ($meta->message() ?? '') !== '无需更新（字段已有内容）') {
                    $this->setStatus($documentId, 'meta_failed', $plainChars, $count, (string) ($meta->message() ?? ''));
                }
            }

            return ServiceResult::ok(['chunk_count' => $count, 'plain_chars' => $plainChars, 'status' => 'ok'], '处理完成，分块 ' . $count . ' 段');
        } catch (\Throwable $e) {
            $this->setStatus($documentId, 'extract_failed', 0, 0, $e->getMessage());

            return ServiceResult::fail($e->getMessage());
        } finally {
            self::$running = false;
        }
    }

    /**
     * @return ServiceResult
     */
    public function testExtractFile(string $absolutePath, string $mime = '', string $originalName = ''): ServiceResult
    {
        $text = $this->documentIngestService->extractFile($absolutePath, $mime, $originalName);
        if ($text === '') {
            return ServiceResult::fail('未提取到文字（扫描 PDF/图片请启用 OCR）');
        }

        return ServiceResult::ok(['text' => mb_substr($text, 0, 2000)], 'ok');
    }

    /**
     * @return ServiceResult
     */
    public function testOcrFile(string $absolutePath, string $mime = ''): ServiceResult
    {
        $res = $this->ocrService->recognize($absolutePath, $mime);
        if (!$res->isOk()) {
            return ServiceResult::fail((string) ($res->message() ?? 'OCR 失败'));
        }

        return ServiceResult::ok(['text' => mb_substr((string) ($res['text'] ?? ''), 0, 2000)], 'ok');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function statusForDocument(int $documentId): ?array
    {
        if ($documentId < 1) {
            return null;
        }
        $row = AiConfigProcess::where('document_id', $documentId)->find();

        return is_array($row) ? $row : null;
    }

    private function setStatus(int $documentId, string $status, int $plainChars, int $chunkCount, ?string $error): void
    {
        $now = time();
        $data = [
            'document_id' => $documentId,
            'status'      => $status,
            'plain_chars' => $plainChars,
            'chunk_count' => $chunkCount,
            'error_msg'   => $error !== null && $error !== '' ? mb_substr($error, 0, 500) : null,
            'updated_at'  => $now,
        ];
        $exists = AiConfigProcess::where('document_id', $documentId)->find();
        if ($exists) {
            AiConfigProcess::where('document_id', $documentId)->update($data);
        } else {
            AiConfigProcess::insert($data);
        }
    }
}
