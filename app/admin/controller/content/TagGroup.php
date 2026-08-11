<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\content;

use app\common\service\auth\CsrfService;
use app\common\support\ServiceResult;
use app\common\support\AdminApiResponse;
use app\common\service\tag\TagGroupService;
use think\facade\Request;

class TagGroup extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly TagGroupService $tagGroup,
    ) {
        parent::__construct($csrf);
    }

    public function meta()
    {
        return AdminApiResponse::fromResult(ServiceResult::ok([
                'plugins' => $this->tagGroup->listPluginOptionsForEntitlementSlots(),
            ]));;
    }

    public function index()
    {
        if (Request::isAjax()) {
            $paged = $this->tagGroup->listAdminPaged(Request::get());

            return AdminApiResponse::list(['total' => $paged['total'],
                'list'  => $paged['list'],
                'page'  => $paged['page'],
                'limit' => $paged['limit']]);;
        }

        return $this->renderView('tag_group/index');
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        return AdminApiResponse::admin($this->tagGroup->saveAdmin(Request::post()));
    }

    public function sort()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        return AdminApiResponse::admin($this->tagGroup->updateSortAdmin(
            (int) Request::post('id', 0),
            (int) Request::post('sort', 0)
        ));
    }

    public function status()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        return AdminApiResponse::admin($this->tagGroup->updateStatusAdmin(
            (int) Request::post('id', 0),
            (int) Request::post('status', 0)
        ));
    }

    public function delete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        return AdminApiResponse::admin($this->tagGroup->deleteAdmin((int) Request::post('id', 0)));
    }
}
