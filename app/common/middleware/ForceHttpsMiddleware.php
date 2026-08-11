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

/** 全站 HTTP → HTTPS 301（site_force_https；本地四站 / Host≠公网站址不跳） */
class ForceHttpsMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        if (!SiteUrl::shouldForceHttpsRedirect($request)) {
            return $next($request);
        }

        $response = redirect(self::httpsUrl($request), 301);
        $response->header(['Cache-Control' => 'no-store']);

        return $response;
    }

    private static function httpsUrl(Request $request): string
    {
        $host = $request->host();
        $port = (int) $request->port();
        if ($port > 0 && $port !== 443 && $port !== 80) {
            $host .= ':' . $port;
        }

        return 'https://' . $host . $request->url();
    }
}
