<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use think\Request;

/** API 请求是否期望 JSON 响应（ExceptionHandler / 中间件共用） */
final class ApiRequestDetect
{
    public static function wantsJsonFromRequest(Request $request): bool
    {
        return self::wantsJson(
            (string) $request->pathinfo(),
            (string) $request->header('accept', ''),
            (string) $request->header('x-requested-with', '')
        );
    }

    public static function wantsJson(string $pathinfo, string $accept = '', string $xhr = ''): bool
    {
        $path = strtolower(trim(str_replace('\\', '/', $pathinfo), '/'));
        if (str_starts_with($path, 'api/v1')) {
            return true;
        }

        if (stripos($accept, 'application/json') !== false) {
            return true;
        }

        return strtolower($xhr) === 'xmlhttprequest';
    }
}
