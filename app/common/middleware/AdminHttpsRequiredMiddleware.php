<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\middleware;

use app\common\support\SiteUrl;
use think\Request;
use think\Response;

/** 生产环境后台 /admin 必须 HTTPS（防 bootstrap CSRF 明文泄露） */
class AdminHttpsRequiredMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        $path = strtolower(trim($request->pathinfo(), '/'));
        if ($path !== 'admin' && !str_starts_with($path, 'admin/')) {
            return $next($request);
        }
        if (SiteUrl::allowsPlainHttp($request)) {
            return $next($request);
        }
        if ($request->isSsl()) {
            return $next($request);
        }

        return Response::create(
            '当前站点要求后台使用 HTTPS 访问。本地开发请用本地域名（勿直接用公网站址 Host），'
            . '或将运行模式设为「开发」，或配置 PIVARK_ENV=dev / www-local。',
            'html',
            403
        )->header(['Cache-Control' => 'no-store']);
    }
}
