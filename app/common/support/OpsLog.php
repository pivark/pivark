<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use think\facade\Log;

/** 业务 / 慢查询独立日志通道（config/log.php business + slow_query） */
final class OpsLog
{
    /**
     * @param array<string, mixed> $context
     */
    public static function businessInfo(string $message, array $context = []): void
    {
        Log::channel('business')->info($message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function businessWarning(string $message, array $context = []): void
    {
        Log::channel('business')->warning($message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function slowQuery(string $message, array $context = []): void
    {
        Log::channel('slow_query')->warning($message, $context);
    }
}
