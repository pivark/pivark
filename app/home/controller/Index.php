<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\home\controller;

use app\common\service\config\ConfigService;
use app\common\service\tag\TagService;
use app\common\support\SiteDomainContext;
use app\common\support\SiteUrl;

class Index extends Base
{
    public function index()
    {
        app(\app\common\service\plugin\boot\PluginBootService::class)->bootstrapEnabled();
        $early = app(\app\common\service\weapp\WeappFrontGateway::class)->frontTryEarlyResponse();
        if ($early !== null) {
            return $early;
        }

        $defaultSlug = SiteDomainContext::defaultTagSlug();
        if ($defaultSlug !== '') {
            $tagRow = app(TagService::class)->findRowBySlug($defaultSlug);
            if ($tagRow !== null) {
                return redirect(SiteUrl::tagFromRow($tagRow), 302);
            }
        }

        $siteCfg = app(ConfigService::class)->getAll();

        return $this->render('home', [
            'page_title'        => '首页',
            'seo_title'         => trim((string) ($siteCfg['site_title'] ?? '')),
            'seo_title_context' => 'home',
        ]);
    }
}
