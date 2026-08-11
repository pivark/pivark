<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\static;
use app\common\support\ServiceResult;

/** 静态 HTML 分批生成后台（DI 入口） */
final class StaticHtmlBatchAdminGateway
{

    public function __construct(
        private readonly StaticHtmlBatchService $batch,
    ) {
    }

    /** @return array<string, mixed> */
    public function homeInfo(): array
    {
        return $this->batch->homeInfo();
    }

    /** @param array<string, mixed> $params */
    public function start(array $params): ServiceResult
    {
        return $this->batch->start($params);
    }

    public function step(string $jobId): ServiceResult
    {
        return $this->batch->step($jobId);
    }
}
