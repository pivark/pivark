<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\home\controller;

use app\common\service\seo\RobotsService;
use app\common\service\seo\SitemapService;
use think\Response;

class Seo extends Base
{
    public function sitemap(): Response
    {
        if (!app(SitemapService::class)->isTypeEnabled('xml')) {
            return response('Not Found', 404);
        }

        return response(app(SitemapService::class)->generateXml(), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function sitemapTxt(): Response
    {
        if (!app(SitemapService::class)->isTypeEnabled('txt')) {
            return response('Not Found', 404);
        }

        return response(app(SitemapService::class)->generateTxt(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemapHtml(): Response
    {
        if (!app(SitemapService::class)->isTypeEnabled('html')) {
            return response('Not Found', 404);
        }

        return response(app(SitemapService::class)->generateHtml(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function llmsTxt(): Response
    {
        if (!app(SitemapService::class)->isTypeEnabled('llms')) {
            return response('Not Found', 404);
        }

        return response(app(SitemapService::class)->generateLlmsTxt(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function aiSitemap(): Response
    {
        if (!app(SitemapService::class)->isTypeEnabled('ai')) {
            return response('Not Found', 404);
        }

        return response(app(SitemapService::class)->generateAiIndex(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function robots(): Response
    {
        return response(app(RobotsService::class)->content(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
