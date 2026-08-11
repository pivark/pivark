<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\middleware;

use app\common\support\AdminApiResponse;
use app\common\support\InstallGate;
use think\Request;
use think\Response;

/** 安装向导：install.lock 存在时阻断 POST 等写操作（CodeArts #39 纵深防御） */
final class InstallWizardGuardMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        if (!InstallGate::isInstalled() || $request->isGet()) {
            return $next($request);
        }

        return AdminApiResponse::forbidden(
            '系统已安装；重装请删除 data/install.lock 后访问 /install',
            403,
        );
    }
}
