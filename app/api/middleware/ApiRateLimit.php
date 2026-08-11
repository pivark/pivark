<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\api\middleware;

use app\common\service\infra\RateLimitGateway;
use app\common\service\infra\RateLimitRegistry;
use think\Request;
use think\Response;

/** 公开 API 按策略分桶限流（后台 admin/* 豁免） */
class ApiRateLimit
{
    public function __construct(
        private readonly RateLimitGateway $rateLimitGateway,
        private readonly RateLimitRegistry $rateLimitRegistry,
    ) {
    }

    public function handle(Request $request, \Closure $next): Response
    {
        if (!$this->rateLimitRegistry->isGloballyEnabled()) {
            return $next($request);
        }

        $policyId = $this->rateLimitGateway->resolveApiPolicy($request);
        if ($policyId === null) {
            return $next($request);
        }

        $policy  = $this->rateLimitRegistry->resolve($policyId);
        $blocked = $this->rateLimitGateway->check(
            $policyId,
            $request->ip() ?: 'unknown',
            ['request' => $request],
        );
        if ($blocked !== null) {
            return $this->rateLimitGateway->toHttpResponse($blocked, $request, $policy);
        }

        return $next($request);
    }
}
