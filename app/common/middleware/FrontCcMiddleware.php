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

/** 前台 CC 防护：委托 RateLimitGateway（策略 front.cc） */
class FrontCcMiddleware
{
    public function __construct(
        private readonly RateLimitGateway $rateLimitGateway,
        private readonly RateLimitRegistry $rateLimitRegistry,
    ) {
    }

    public function handle(Request $request, \Closure $next): Response
    {
        $path = strtolower(trim(str_replace('\\', '/', (string) $request->pathinfo()), '/'));
        // 安装向导迁移会连打 /install/runStep（数百次），不得计入前台 CC
        if ($path === 'install'
            || str_starts_with($path, 'install/')
            || str_starts_with($path, 'admin')
            || str_starts_with($path, 'api/')) {
            return $next($request);
        }

        $policy  = $this->rateLimitRegistry->resolve('front.cc');
        if ($policy === null || empty($policy['enabled'])) {
            return $next($request);
        }

        $blocked = $this->rateLimitGateway->check('front.cc', $request->ip() ?: 'unknown');
        if ($blocked !== null) {
            return $this->rateLimitGateway->toHttpResponse($blocked, $request, $policy);
        }

        return $next($request);
    }
}
