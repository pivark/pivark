<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\api\middleware;

use think\Request;
use think\Response;

/**
 * 可选 CORS：未配置 API_CORS_ORIGINS 时不输出跨域头（同源前台 / 小程序不受影响）。
 * SSOT: config/cross.php
 */
class ApiCorsMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        $cors = config('cross');
        if (!is_array($cors)) {
            return $next($request);
        }

        $allowOrigin = trim((string) ($cors['allow_origin'] ?? ''));
        if ($allowOrigin === '') {
            return $next($request);
        }

        $resolvedOrigin = $this->resolveOrigin($allowOrigin, trim((string) $request->header('origin', '')));
        if ($resolvedOrigin === null) {
            if (strtoupper($request->method()) === 'OPTIONS') {
                return response('', 403);
            }

            return $next($request);
        }

        if (strtoupper($request->method()) === 'OPTIONS') {
            return $this->applyHeaders(response('', 204), $cors, $resolvedOrigin);
        }

        return $this->applyHeaders($next($request), $cors, $resolvedOrigin);
    }

    /**
     * @param array<string, mixed> $cors
     */
    private function applyHeaders(Response $response, array $cors, string $origin): Response
    {
        $headers = [
            'Access-Control-Allow-Origin'      => $origin,
            'Access-Control-Allow-Methods'     => (string) ($cors['allow_methods'] ?? 'GET,POST,PUT,PATCH,DELETE,OPTIONS'),
            'Access-Control-Allow-Headers'     => (string) ($cors['allow_headers'] ?? ''),
            'Access-Control-Max-Age'           => (string) ($cors['max_age'] ?? 1800),
            'Access-Control-Allow-Credentials' => (string) ($cors['allow_credentials'] ?? 'false'),
        ];

        $expose = trim((string) ($cors['expose_headers'] ?? ''));
        if ($expose !== '') {
            $headers['Access-Control-Expose-Headers'] = $expose;
        }
        if ($origin !== '*') {
            $headers['Vary'] = 'Origin';
        }

        return $response->header($headers);
    }

    private function resolveOrigin(string $allowOrigin, string $requestOrigin): ?string
    {
        if ($allowOrigin === '*') {
            return '*';
        }

        $allowed = array_values(array_filter(array_map('trim', explode(',', $allowOrigin))));
        if ($allowed === []) {
            return null;
        }
        if ($requestOrigin === '') {
            return null;
        }
        if (in_array($requestOrigin, $allowed, true)) {
            return $requestOrigin;
        }

        return null;
    }
}
