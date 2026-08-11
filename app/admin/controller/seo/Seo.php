<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\seo;

use app\common\service\auth\CsrfService;
use app\common\support\AdminApiResponse;
use app\common\service\seo\RobotsService;
use app\common\service\seo\SeoConfigService;
use app\common\service\seo\SitemapService;
use app\common\service\static\StaticHtmlBatchAdminGateway;
use app\common\service\static\StaticHtmlAdminGateway;
use app\common\support\AdminSpa;
use think\facade\Request;

class Seo extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly StaticHtmlAdminGateway $staticHtml,
        private readonly StaticHtmlBatchAdminGateway $staticHtmlBatch,
        private readonly SeoConfigService $seoConfig,
        private readonly SitemapService $sitemap,
        private readonly RobotsService $robots,
    ) {
        parent::__construct($csrf);
    }

    public function url()
    {
        return AdminSpa::respond();
    }

    public function urlSave()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        try {
            return AdminApiResponse::admin($this->seoConfig->saveUrlAdmin(Request::post()));
        } catch (\InvalidArgumentException $e) {
            return AdminApiResponse::fail($e->getMessage());
        }
    }

    public function staticGenerate()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->staticHtml->generateAdmin(
            (string) Request::post('scope', ''),
            (int) Request::post('id', 0)
        ));
    }

    public function static()
    {
        return AdminSpa::respond();
    }

    public function staticBatchStart()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->staticHtmlBatch->start(Request::post()));
    }

    public function staticBatchStep()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->staticHtmlBatch->step((string) Request::post('job_id', '')));
    }

    public function sitemap()
    {
        return AdminSpa::respond();
    }

    public function sitemapSave()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->sitemap->saveAdmin(Request::post()));
    }

    public function sitemapRebuild()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->sitemap->rebuildAdmin());
    }

    public function baiduPushBatch()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->sitemap->pushBatchAdmin((int) Request::post('limit', 30)));
    }

    public function robots()
    {
        return AdminSpa::respond();
    }

    public function robotsSave()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->robots->saveAdmin(
            (string) Request::post('content', ''),
            (string) Request::post('preset', 'custom')
        ));
    }

    public function robotsReset()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->robots->resetDefault());
    }
}
