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
use app\common\service\ai\extract\DocumentExtractDriverInterface;
use app\common\service\ai\extract\PhpBuiltinExtractDriver;
use app\common\support\ServiceResult;

class DocumentExtractService
{

    public function __construct(
        private readonly AiConfigService $aiConfig,
    ) {
    }

    public function driver(): DocumentExtractDriverInterface
    {
        $id = strtolower(trim($this->aiConfig->extractDriver()));

        return match ($id) {
            'php', '' => new PhpBuiltinExtractDriver(),
            default => new PhpBuiltinExtractDriver(),
        };
    }

    public function extractFile(string $absolutePath, string $mime = '', string $originalName = ''): ServiceResult
    {
        return $this->driver()->extract($absolutePath, $mime, $originalName);
    }
}