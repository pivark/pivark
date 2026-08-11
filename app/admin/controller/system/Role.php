<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\system;

use app\common\service\auth\CsrfService;
use app\common\service\admin\AdminSpaMetaService;
use app\common\support\AdminApiResponse;
use app\common\service\user\RoleService;
use app\common\support\ServiceResult;
use think\facade\Request;
use think\Response;

class Role extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly RoleService $role,
        private readonly AdminSpaMetaService $spaMeta,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        $page    = (int) Request::get('page', 1);
        $limit   = min(max((int) Request::get('limit', 15), 1), 100);
        $keyword = (string) Request::get('keyword', '');
        if (Request::isAjax()) {
            $list = $this->role->getList($page, $limit, $keyword);
            $rows = [];
            foreach ($list->items() as $item) {
                $row = is_object($item) && method_exists($item, 'toArray')
                    ? $item->toArray()
                    : (array) $item;
                $row['can_delete'] = $this->role->isRoleDeletableForAdmin($row) ? 1 : 0;
                $rows[] = $row;
            }
            $rows = $this->role->enrichRowsWithPermissionCounts($rows);

            return AdminApiResponse::list(['total' => (int) $list->total(),
                'list'  => $rows]);
        }

        $list = $this->role->getList($page, 15, $keyword);
        return $this->renderView('role/index', compact('list'));
    }

    /** GET — 角色详情（REST · 原 Spa::roleDetail） */
    public function detail(): Response
    {
        $row = $this->spaMeta->roleDetail((int) Request::get('id', 0));
        if ($row === null) {
            return AdminApiResponse::fromResult(ServiceResult::notFound('角色不存在'));
        }

        return AdminApiResponse::fromResult(ServiceResult::ok($row));
    }

    public function create()
    {
        $permissionGroups = $this->role->permissionsGroupedForForm();
        return $this->renderView('role/form', compact('permissionGroups'));
    }

    public function edit()
    {
        $id = (int) Request::get('id', 0);
        $info = $this->role->findForForm($id);
        if (!$info) {
            return redirect('/admin/role/index');
        }
        $permissionGroups = $this->role->permissionsGroupedForForm();
        $hasPermIds       = $this->role->getPermissionIds($id);
        return $this->renderView('role/form', compact('permissionGroups', 'info', 'hasPermIds'));
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $id   = (int) Request::post('id', 0);
        $data = Request::post();
        $data['permissions'] = Request::post('permissions/a', []);
        $res  = $id ? $this->role->update($id, $data) : $this->role->create($data);
        return AdminApiResponse::fromResult($res);
    }

    public function delete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        return AdminApiResponse::admin($this->role->delete((int) Request::post('id', 0)));
    }
}
