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

/** 面向终端用户的异常文案（生产隐藏内部细节） */
final class ClientErrorMessage
{
    public static function fromThrowable(\Throwable $e, string $fallback = '操作失败，请稍后重试'): string
    {
        if (filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN)) {
            return $e->getMessage();
        }

        Log::error($e->getMessage(), [
            'exception' => $e::class,
            'file'      => $e->getFile() . ':' . $e->getLine(),
        ]);

        return $fallback;
    }
}
