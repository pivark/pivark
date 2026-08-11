<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\middleware;

use app\common\service\config\ConfigService;
use think\Request;
use think\Response;

/** HTML 响应 GZIP（配置 gzip_enabled=1；静态资源仍建议 Nginx 压缩） */
class ResponseGzipMiddleware
{
    private const MIN_BYTES = 512;

    public function handle(Request $request, \Closure $next): Response
    {
        $response = $next($request);
        if ((string) app(ConfigService::class)->get('gzip_enabled', '0') !== '1') {
            return $response;
        }
        if (!str_contains(strtolower((string) $request->header('accept-encoding', '')), 'gzip')) {
            return $response;
        }
        if (!function_exists('gzencode')) {
            return $response;
        }

        $type = strtolower((string) $response->getHeader('Content-Type'));
        if ($type !== '' && !str_contains($type, 'text/html') && !str_contains($type, 'application/json')) {
            return $response;
        }

        $body = (string) $response->getContent();
        if (strlen($body) < self::MIN_BYTES) {
            return $response;
        }

        $compressed = gzencode($body, 6);
        if ($compressed === false) {
            return $response;
        }

        return $response->content($compressed)->header([
            'Content-Encoding' => 'gzip',
            'Vary'             => 'Accept-Encoding',
        ]);
    }
}
