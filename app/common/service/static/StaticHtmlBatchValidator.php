<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\static;

/** 静态 HTML 分批任务入参与尺寸校验（纯逻辑，可单测；StaticHtmlBatchService 委托） */
final class StaticHtmlBatchValidator
{

    public const MAX_BATCH_ADMIN = 100;
    public const MAX_BATCH_CRON  = 500;
    public const LAZY_DOC_THRESHOLD = 2000;

    /** 后台单拍 step 墙钟预算（秒），避免 axios/网关默认超时把整单打断 */
    public const STEP_TIME_BUDGET_SEC = 8.0;

    public function normalizeBatchSize(int $size): int
    {
        return max(1, min(self::MAX_BATCH_ADMIN, $size > 0 ? $size : 20));
    }

    public function normalizeCronBatchSize(int $size): int
    {
        return max(20, min(self::MAX_BATCH_CRON, $size > 0 ? $size : 200));
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hasDocIdRange(array $params): bool
    {
        return (int) ($params['id_from'] ?? 0) > 0 || (int) ($params['id_to'] ?? 0) > 0;
    }

    public function normalizeBuildType(string $type): ?string
    {
        $type = strtolower(trim($type));

        return in_array($type, ['all', 'home', 'tag', 'document'], true) ? $type : null;
    }
}
