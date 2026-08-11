<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\middleware;

use app\common\support\InstallGate;
use app\common\support\SiteDomainContext;
use Closure;
use think\Request;
use think\Response;

/** 按 HTTP Host 解析同站多域上下文 */
class SiteDomainBootstrap
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (InstallGate::isInstalled()) {
            SiteDomainContext::bootstrap();
        }

        return $next($request);
    }
}
