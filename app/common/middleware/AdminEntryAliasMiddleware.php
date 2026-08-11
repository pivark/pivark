<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\middleware;

use app\common\service\site\AdminEntryAliasService;
use app\common\support\InstallGate;
use think\Request;
use think\Response;

/** 自定义后台入口：别名内部转发；启用后禁止直接访问 /admin */
class AdminEntryAliasMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        if (!InstallGate::isInstalled()) {
            return $next($request);
        }

        $pathinfo = (string) $request->pathinfo();

        if (app(AdminEntryAliasService::class)->isBlockedAdminPath($pathinfo)) {
            $alias = app(AdminEntryAliasService::class)->publicBasePath();
            $path  = trim($pathinfo, '/');
            $suffix = $path === 'admin' ? '' : substr($path, strlen('admin/'));
            $target = rtrim($alias, '/') . ($suffix !== '' ? '/' . $suffix : '/');
            $query  = (string) $request->server('QUERY_STRING', '');
            if ($query !== '') {
                $target .= '?' . $query;
            }

            return redirect($target, 302);
        }

        $rewritten = app(AdminEntryAliasService::class)->rewritePathinfo($pathinfo);
        if ($rewritten !== null) {
            $request->setPathinfo($rewritten);
        }

        return $next($request);
    }
}
