<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\boot;

use app\common\support\OpsLog;

/** 插件运行时调用故障兜底（单插件异常不拖死调用方） */
final class PluginRuntimeFaultGuard
{
    /**
     * @param callable(): mixed $fn
     * @param array<string, scalar|null> $context
     */
    public static function invoke(callable $fn, string $channel, array $context, mixed $default = null): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            self::log($channel, $context, $e);

            return $default;
        }
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public static function log(string $channel, array $context, \Throwable $e): void
    {
        OpsLog::businessWarning($channel, $context + [
            'error'     => $e->getMessage(),
            'exception' => $e::class,
        ]);
        error_log('[PivArk ' . $channel . '] ' . json_encode($context, JSON_UNESCAPED_UNICODE) . ': ' . $e->getMessage());
    }
}
