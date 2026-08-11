<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\middleware;

use think\Request;
use think\Response;

/** HEAD：状态与头与 GET 一致，响应体为空（RFC 9110 §9.3.2） */
class HeadResponseMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        $response = $next($request);
        if (!$request->isHead()) {
            return $response;
        }

        return $response->content('');
    }
}
