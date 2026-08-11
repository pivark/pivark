<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\home\controller;

use think\facade\Request;

use app\common\service\front\FrontAuthService;
use app\common\service\front\FrontCsrfService;
use app\common\service\hook\HookService;
use app\common\service\plugin\boot\PluginBootService;
use app\common\service\site\SiteModeService;
use app\common\service\template\TemplateEngine;
use app\common\service\template\TemplateParseCacheService;
use app\common\support\FrontErrorPageResponse;
use app\common\support\FrontSecurityHeaders;
use think\Response;

abstract class Base
{
    /**
     * 命中运营整页缓存则直接响应（在装正文/相邻文等重查询之前调用）。
     * 键仅依赖 URI 身份，见 SiteModeService::pageCachePath。
     */
    protected function tryPageCacheResponse(string $template = '', array $vars = []): ?Response
    {
        $siteMode = app(SiteModeService::class);
        if (!$siteMode->isPageCacheEnabled()) {
            return null;
        }
        $cached = $siteMode->getPageCache($template, $vars);
        if ($cached === null) {
            return null;
        }
        $html = app(TemplateParseCacheService::class)->restoreVolatileFromCache($cached, [
            'front_csrf_token' => app(FrontCsrfService::class)->token(),
        ]);
        $html = app(\app\common\service\template\TemplateMetaService::class)->sanitizeOutput($html);

        return $this->withSecurityHeaders(Response::create($html, 'html', 200));
    }

    protected function render(string $template, array $vars = []): Response
    {
        // 品项/单页等入口未必单独 boot；扩展标签（如 {pv:shop}）依赖已启用插件
        app(PluginBootService::class)->bootstrapEnabled();

        $vars['front_member_logged_in'] = app(FrontAuthService::class)->isLoggedIn() ? 1 : 0;
        $siteMode   = app(SiteModeService::class);
        $parseCache = app(TemplateParseCacheService::class);

        $cachedResponse = $this->tryPageCacheResponse($template, $vars);
        if ($cachedResponse !== null) {
            return $cachedResponse;
        }

        app(HookService::class)->fire('front.page', [
            'path'        => (string) Request::server('REQUEST_URI', '/'),
            'referer'     => (string) Request::header('referer', ''),
            'object_type' => 'page',
            'object_id'   => 0,
            'template'    => $template,
        ]);
        $html = app(TemplateEngine::class)->render($template, $vars);
        $html = app(\app\common\service\template\TemplateMetaService::class)->sanitizeOutput($html);

        if ($siteMode->isPageCacheEnabled() && $html !== '' && !str_contains($html, 'template not found')) {
            $siteMode->setPageCache(
                $template,
                $vars,
                $parseCache->neutralizeVolatileForCache($html, [
                    'front_csrf_token' => app(FrontCsrfService::class)->token(),
                ])
            );
        }

        return $this->withSecurityHeaders(Response::create($html, 'html', 200));
    }

    protected function error(string $msg, string $url = '', int $status = 404): Response
    {
        return FrontErrorPageResponse::create($status, $msg, $url);
    }

    /** 输出 Raw HTML（不走模板引擎与页面缓存） */
    protected function renderRawHtml(string $html, int $status = 200): Response
    {
        return $this->withSecurityHeaders(Response::create($html, 'html', $status));
    }

    protected function withSecurityHeaders(Response $response): Response
    {
        return FrontSecurityHeaders::apply($response);
    }
}
