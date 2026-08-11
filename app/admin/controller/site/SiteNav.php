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
use app\common\service\site\SiteNavService;
use think\facade\Request;

class SiteNav extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly SiteNavService $siteNav,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        if (Request::isAjax()) {
            $list = $this->siteNav->listAdminFlat();
            return AdminApiResponse::list(['total' => count($list),
                'list'  => $list,
                'data'  => $list]);
        }

        return $this->renderView('site_nav/index', [
            'typeLabels' => $this->siteNav->typeLabels(),
        ]);
    }

    public function options()
    {
        $exclude = (int) Request::get('exclude_id', 0);
        return AdminApiResponse::fromResult(ServiceResult::ok($this->siteNav->listParentOptions($exclude)));
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->siteNav->saveAdmin(Request::post()));
    }

    public function sort()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->siteNav->updateSortAdmin(
            (int) Request::post('id', 0),
            (int) Request::post('sort', 0)
        ));
    }

    public function status()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->siteNav->updateStatusAdmin(
            (int) Request::post('id', 0),
            (int) Request::post('status', 0)
        ));
    }

    public function delete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->siteNav->deleteAdmin((int) Request::post('id', 0)));
    }
}
