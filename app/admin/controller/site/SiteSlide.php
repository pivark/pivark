<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 站点广告管理（表 site_slides，兼容旧幻灯片接口）
 */
declare(strict_types=1);

namespace app\admin\controller\site;

use app\common\service\auth\CsrfService;
use app\common\support\AdminApiResponse;
use app\common\service\site\SiteAdSlotService;
use app\common\service\site\SiteSlideService;
use think\facade\Request;

class SiteSlide extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly SiteSlideService $siteSlide,
        private readonly SiteAdSlotService $siteAdSlot,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        if (Request::isAjax()) {
            $slot = trim((string) Request::param('slot', ''));
            $type = trim((string) Request::param('creative_type', ''));
            $list = $this->siteSlide->listAdmin(
                $slot !== '' ? $slot : null,
                $type !== '' ? $type : null,
            );

            return AdminApiResponse::list(['total' => count($list),
                'list'  => $list,
                'meta'  => [
                    'slots'          => $this->siteAdSlot->listAdmin(),
                    'creative_types'        => $this->siteSlide->creativeTypeOptions(),
                    'creative_type_catalog' => $this->siteSlide->creativeTypeCatalog(),
                ]]);
        }

        return $this->renderView('site_slide/index');
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->siteSlide->saveAdmin(Request::post()));
    }

    public function sort()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->siteSlide->updateSortAdmin(
            (int) Request::post('id', 0),
            (int) Request::post('sort', 0)
        ));
    }

    public function status()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->siteSlide->updateStatusAdmin(
            (int) Request::post('id', 0),
            (int) Request::post('status', 0)
        ));
    }

    public function delete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->siteSlide->deleteAdmin((int) Request::post('id', 0)));
    }
}
