<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\member;

use app\common\service\auth\CsrfService;
use app\common\service\admin\AdminSpaMetaService;
use app\common\support\AdminApiResponse;
use app\common\service\member\MemberLevelService;
use app\common\support\ServiceResult;
use think\facade\Request;
use think\Response;

class MemberLevel extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly MemberLevelService $memberLevel,
        private readonly AdminSpaMetaService $spaMeta,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        if (Request::isAjax()) {
            $paged = $this->memberLevel->listAdminPaged(Request::get());

            return AdminApiResponse::list(['total' => $paged['total'],
                'list'  => $paged['list'],
                'page'  => $paged['page'],
                'limit' => $paged['limit']]);
        }

        return $this->renderView('member_level/index', [
            'list' => $this->memberLevel->listAdmin(),
        ]);
    }

    public function create()
    {
        return $this->renderView('member_level/form');
    }

    public function edit()
    {
        $id = (int) Request::get('id', 0);
        $info = $this->memberLevel->findAdmin($id);
        if (!$info) {
            return redirect('/admin/member_level/index');
        }

        return $this->renderView('member_level/form', ['info' => $info]);
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $res = $this->memberLevel->saveAdmin(Request::post());

        return AdminApiResponse::fromResult($res);
    }

    public function delete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->memberLevel->deleteAdmin((int) Request::post('id', 0)));
    }

    /** GET — 会员级别详情（REST · 原 Spa::memberLevelDetail） */
    public function detail(): Response
    {
        $row = $this->spaMeta->memberLevelDetail((int) Request::get('id', 0));
        if ($row === null) {
            return AdminApiResponse::fromResult(ServiceResult::notFound('级别不存在'));
        }

        return AdminApiResponse::fromResult(ServiceResult::ok($row));
    }
}
