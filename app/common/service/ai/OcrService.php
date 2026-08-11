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

use app\common\service\config\AiConfigService;
use app\common\service\ai\ocr\BaiduOcrDriver;
use app\common\service\ai\ocr\NoneOcrDriver;
use app\common\service\ai\ocr\OcrDriverInterface;
use app\common\service\ai\ocr\TesseractOcrDriver;

class OcrService
{

    public function __construct(
        private readonly AiConfigService $aiConfig,
    ) {
    }

    public function driver(): OcrDriverInterface
    {
        return match ($this->aiConfig->ocrDriver()) {
            'tesseract' => new TesseractOcrDriver(),
            'baidu'     => new BaiduOcrDriver(),
            default     => new NoneOcrDriver(),
        };
    }

    public function isEnabled(): bool
    {
        return $this->aiConfig->ocrDriver() !== 'none';
    }

    /**
     * @return ServiceResult
     */
    public function recognize(string $absolutePath, string $mime = ''): ServiceResult
    {
        if (!$this->isEnabled()) {
            return ServiceResult::fail('OCR 未启用');
        }
        if ($mime === '') {
            $mime = (string) mime_content_type($absolutePath);
        }

        return $this->driver()->recognize($absolutePath, strtolower($mime));
    }

    public function needsOcr(string $mime, string $ext, string $extractedText): bool
    {
        $mime = strtolower($mime);
        $ext  = strtolower($ext);
        if (str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
            return true;
        }
        if (($mime === 'application/pdf' || $ext === 'pdf') && mb_strlen(trim($extractedText)) < 40) {
            return true;
        }

        return false;
    }
}