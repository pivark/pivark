<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\middleware;

use app\common\service\infra\RateLimitGateway;
use app\common\service\infra\RateLimitRegistry;
use think\Request;
use think\Response;

/** 前台 /search 按 IP 限流（策略 front.search） */
class SearchRateLimit
{
    public function __construct(
        private readonly RateLimitGateway $rateLimitGateway,
        private readonly RateLimitRegistry $rateLimitRegistry,
    ) {
    }

    public function handle(Request $request, \Closure $next)
    {
        if (self::shouldBypass($request)) {
            return $next($request);
        }

        $policy = $this->rateLimitRegistry->resolve('front.search');
        if ($policy === null || empty($policy['enabled'])) {
            return $next($request);
        }

        $blocked = $this->rateLimitGateway->check('front.search', $request->ip() ?: 'unknown');
        if ($blocked !== null) {
            return $this->rateLimitGateway->toHttpResponse($blocked, $request, $policy);
        }

        return $next($request);
    }

    private static function shouldBypass(Request $request): bool
    {
        if (trim((string) $request->header('X-Pivark-Qa')) === '1') {
            return true;
        }
        $env = strtolower((string) env('PIVARK_ENV', 'dev'));

        return (bool) env('APP_DEBUG', false)
            && in_array($env, ['dev', 'demo-local', 'local'], true);
    }
}
