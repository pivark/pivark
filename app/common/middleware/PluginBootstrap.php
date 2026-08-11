<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\middleware;

use app\common\service\admin\AdminPluginRouteRegistry;
use app\common\service\kernel\KernelBootstrapService;
use app\common\service\plugin\boot\PluginBootstrapPolicyService;
use app\common\service\plugin\registry\PluginRouteService;
use app\common\service\plugin\PluginService;
use app\common\service\template\TemplateEngine;
use app\common\support\InstallGate;
use think\Request;

/** 应用启动后加载已启用插件（须在 http->run 之后） */
class PluginBootstrap
{
    public function handle(Request $request, \Closure $next)
    {
        if (!InstallGate::isInstalled()) {
            return $next($request);
        }

        app(TemplateEngine::class)->beginRequest();
        if (!app(PluginBootstrapPolicyService::class)->shouldSkipPluginBoot($request)) {
            // 插件 bootstrap 会 resetExtensionTags()，内核标签（自定表单等）须在之后注册
            app(PluginService::class)->bootstrapEnabled();
            app(PluginRouteService::class)->applyOnce();
            app(AdminPluginRouteRegistry::class)->applyOnce();
        }
        app(KernelBootstrapService::class)->boot();

        return $next($request);
    }
}
