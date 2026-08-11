<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\home\controller;

use app\common\service\site\SitePageService;
use app\common\service\site\SiteUrlModeService;
use app\common\service\theme\ThemeService;
use think\facade\Request;
use think\Response;

/** 企业站单页（URL 以后台配置为准；无旧短链 301） */
class Page extends Base
{
    /** 企业门户单页（path=portal 为保留字，走专属路由 + allowReserved） */
    public function portal(): Response
    {
        $pages = app(SitePageService::class);
        $page = $pages->findByPathAllowReserved('portal')
            ?? $pages->findByTpl('list_page_portal');
        if ($page === null) {
            // Tag SSOT：整站频道已迁 product-site；旧 /portal 链 301 到标签公开路径
            $fallback = $pages->portalFrontUrl();
            $fallbackPath = trim((string) (parse_url($fallback, PHP_URL_PATH) ?: ''), '/');
            if ($fallbackPath !== '' && $fallbackPath !== 'portal') {
                return redirect($fallback, 301);
            }

            return $this->error('页面不存在');
        }

        return $this->renderSitePage($page);
    }

    private function renderConfiguredTpl(string $tpl): Response
    {
        $page = app(SitePageService::class)->findByTpl($tpl);
        if ($page === null) {
            return $this->error('页面不存在');
        }
        $url = (string) ($page['url'] ?? '');
        if (!$this->requestMatchesPageUrl($url, $tpl)) {
            return $this->error('页面不存在');
        }

        return $this->renderSitePage($page);
    }

    private function requestMatchesPageUrl(string $canonicalUrl, string $tpl): bool
    {
        $mode = app(SiteUrlModeService::class);
        $req = $mode->stripSuffix(trim(parse_url((string) Request::server('REQUEST_URI', '/'), PHP_URL_PATH) ?: '', '/'));
        $canon = $mode->stripSuffix(trim($canonicalUrl, '/'));
        if ($req === '' || $canon === '') {
            return false;
        }
        if ($req === $canon) {
            return true;
        }
        $path = app(SitePageService::class)->normalizePath($tpl);
        if ($path !== '' && $req === $path) {
            return true;
        }
        if (str_ends_with($canon, '/index') && str_starts_with($canon, $req . '/')) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $page
     */
    private function renderSitePage(array $page): Response
    {
        $cached = $this->tryPageCacheResponse();
        if ($cached !== null) {
            return $cached;
        }

        $tplName = app(SitePageService::class)->normalizeTpl((string) ($page['tpl_name'] ?? ''));
        $tplFile = app(SitePageService::class)->resolveThemeTemplateFile($tplName);
        $theme = app(ThemeService::class)->getCurrentTheme();
        if (!app(ThemeService::class)->siteTemplateExists($theme, $tplFile . '.php')
            && !app(ThemeService::class)->siteTemplateExists('default', $tplFile . '.php')) {
            return $this->error('页面模板不存在：' . $tplName);
        }

        $payload = app(\app\common\service\front\FrontRenderService::class)->sitePagePayload($page);
        if ($payload === null) {
            return $this->error('页面不存在');
        }

        return $this->render($payload['template'], $payload['vars']);
    }
}
