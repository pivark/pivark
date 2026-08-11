<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\site;

use app\common\service\auth\CsrfService;
use app\common\support\ServiceResult;
use app\common\support\AdminApiResponse;
use app\common\service\site\SiteAdSlotService;
use app\common\service\site\SiteSlideService;
use think\facade\Request;

/** 站点广告位 */
class SiteAdSlot extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly SiteAdSlotService $siteAdSlot,
        private readonly SiteSlideService $siteSlide,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        if (!Request::isAjax()) {
            return $this->renderView('site_ad_slot/index');
        }

        $list = $this->siteAdSlot->listAdmin();

        return AdminApiResponse::list(['total' => count($list),
            'list'  => $list,
            'meta'  => [
                'creative_types'        => $this->siteSlide->creativeTypeOptions(),
                'creative_type_catalog' => $this->siteSlide->creativeTypeCatalog(),
                'default_inner'         => $this->siteAdSlot->defaultInnerTpl(),
            ]]);
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->siteAdSlot->saveAdmin(Request::post()));
    }

    public function delete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->siteAdSlot->deleteAdmin((int) Request::post('id', 0)));
    }

    public function suggestCode()
    {
        $name = trim((string) Request::param('name', ''));

        return AdminApiResponse::fromResult(ServiceResult::ok(['code' => $this->siteAdSlot->codeFromName($name)], ''));
    }
}
