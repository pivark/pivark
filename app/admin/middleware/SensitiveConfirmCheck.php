<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\middleware;

use app\common\service\admin\AdminSensitiveConfirmService;
use think\Request;
use think\Response;

/** 敏感后台 POST 需管理员密码二次确认 */
class SensitiveConfirmCheck
{
    public function handle(Request $request, \Closure $next): Response
    {
        $blocked = app(AdminSensitiveConfirmService::class)->guard($request);
        if ($blocked instanceof Response) {
            return $blocked;
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
