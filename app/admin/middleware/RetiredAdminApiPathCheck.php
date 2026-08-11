<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\middleware;

use app\common\service\admin\AdminRetiredApiPathRegistry;
use app\common\support\AdminApiResponse;
use app\common\support\ServiceResult;
use think\Request;
use think\Response;

/** 在控制器执行前拦截已退役的后台 API 前缀（覆盖 opcache 中残留的旧路由） */
class RetiredAdminApiPathCheck
{
    private static bool $kernelPathsRegistered = false;

    public function handle(Request $request, \Closure $next): Response
    {
        self::ensureKernelRetiredPathsRegistered();

        $candidates = [(string) $request->pathinfo()];
        $uriPath    = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (is_string($uriPath) && $uriPath !== '') {
            $candidates[] = ltrim(str_replace('\\', '/', $uriPath), '/');
        }
        foreach ($candidates as $candidate) {
            $match = app(AdminRetiredApiPathRegistry::class)->match($candidate);
            if ($match['blocked']) {
                return AdminApiResponse::fromResult(ServiceResult::notFound($match['message']));
            }
        }

        return $next($request);
    }

    private static function ensureKernelRetiredPathsRegistered(): void
    {
        if (self::$kernelPathsRegistered) {
            return;
        }
        self::$kernelPathsRegistered = true;

        $paths = config('admin.retired_api_paths');
        if (!is_array($paths)) {
            return;
        }

        $registry = app(AdminRetiredApiPathRegistry::class);
        foreach ($paths as $prefix => $message) {
            if (!is_string($prefix) || !is_string($message)) {
                continue;
            }
            $registry->registerPrefix($prefix, $message);
        }
    }
}
