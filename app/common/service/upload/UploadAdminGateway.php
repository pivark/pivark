<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\upload;
use app\common\support\ServiceResult;

/** 后台上传配置与去重（DI 入口） */
final class UploadAdminGateway
{

    public function __construct(
        private readonly UploadService $uploads,
    ) {
    }

    /** @return array<string, mixed> */
    public function clientUploadConfig(): array
    {
        return $this->uploads->clientUploadConfig();
    }

    public function checkDuplicate(string $hash, int $fileSize = 0): ServiceResult
    {
        return $this->uploads->checkDuplicate($hash, $fileSize);
    }
}
