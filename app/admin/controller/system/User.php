<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare (strict_types = 1);

namespace app\admin\controller\system;

use app\common\service\auth\CsrfService;
use app\common\service\admin\AdminSpaMetaService;
use app\common\service\export\AdminDataExportSupport;
use app\common\service\export\AdminDataImportSupport;
use app\common\service\export\ExportImportService;
use app\common\support\AdminApiResponse;
use app\common\support\ServiceResult;
use app\common\service\admin\AdminTagScopeService;
use app\common\service\user\RoleService;
use app\common\service\user\UserService;
use think\facade\Request;
use think\facade\Session;
use think\Response;

// app/admin/controller/User.php — 用户管理

class User extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly UserService $user,
        private readonly RoleService $role,
        private readonly AdminTagScopeService $adminTagScope,
        private readonly ExportImportService $exportImport,
        private readonly AdminDataExportSupport $adminDataExport,
        private readonly AdminDataImportSupport $adminDataImport,
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
            $list = $this->user->getList($page, $limit, $keyword);
            $rows = [];
            foreach ($list->items() as $item) {
                $rows[] = is_object($item) && method_exists($item, 'toArray')
                    ? $item->toArray()
                    : (array) $item;
            }
            $rows = $this->user->enrichListRowsWithRoleLabels($rows);

            return AdminApiResponse::list(['total' => (int) $list->total(),
                'list'  => $rows]);
        }

        $list = $this->user->getList($page, 15, $keyword);
        return $this->renderView('user/index', compact('list'));
    }

    /** GET — 用户详情（REST · 原 Spa::userDetail） */
    public function detail(): Response
    {
        $row = $this->spaMeta->userDetail((int) Request::get('id', 0));
        if ($row === null) {
            return AdminApiResponse::fromResult(ServiceResult::notFound('用户不存在'));
        }

        return AdminApiResponse::fromResult(ServiceResult::ok($row));
    }

    public function create()
    {
        $roles = $this->role->getAll();
        $userRoleIds = [];
        return $this->renderView('user/form', [
            'roles'        => $roles,
            'userRoleIds'  => $userRoleIds,
            'tagPicker'    => $this->adminTagScope->listTagsForAdminPicker(),
            'tagScopeIds'  => [],
        ]);
    }

    public function edit()
    {
        $id = (int) Request::get('id', 0);
        $info = $this->user->findForForm($id);
        if (!$info) {
            return redirect('/admin/user/index');
        }
        $roles = $this->role->getAll();
        $userRoleIds = $this->user->getRoleIdsForUser($id);
        $admin = Session::get('admin_user');
        $isSelfEdit = is_array($admin) && (int) ($admin['id'] ?? 0) === $id;
        return $this->renderView('user/form', [
            'roles'        => $roles,
            'userRoleIds'  => $userRoleIds,
            'info'         => $info,
            'isSelfEdit'   => $isSelfEdit,
            'tagPicker'    => $this->adminTagScope->listTagsForAdminPicker(),
            'tagScopeIds'  => $this->user->getTagScopeIdsForUser($id),
        ]);
    }

    public function save()
    {
        if (!Request::isPost()) return AdminApiResponse::fail('请求方式错误');
        $id = (int) Request::post('id', 0);
        $data = Request::post();
        if ($id > 0) {
            $admin = Session::get('admin_user');
            $isSelfEdit = is_array($admin) && (int) ($admin['id'] ?? 0) === $id;
            if (!$isSelfEdit) {
                $data['role_ids'] = Request::post('role_ids/a', Request::post('role_ids', []));
            }
        } else {
            $data['role_ids'] = Request::post('role_ids/a', Request::post('role_ids', []));
        }
        $data['tag_scope_ids'] = Request::post('tag_scope_ids/a', []);
        $res = $id ? $this->user->update($id, $data) : $this->user->create($data);
        if ($res->isOk()) {
            $payload = ['url' => '/admin/user/index'];
            $idFromRes = (int) ($res->dataArray()['id'] ?? 0);
            if ($idFromRes > 0) {
                $payload['id'] = $idFromRes;
            }

            return AdminApiResponse::admin(ServiceResult::ok($payload, $res->message()));
        }

        return AdminApiResponse::admin($res);
    }

    public function delete()
    {
        if (!Request::isPost()) return AdminApiResponse::fail('请求方式错误');
        return AdminApiResponse::admin($this->user->delete((int) Request::post('id', 0)));
    }

    public function export()
    {
        $pack = $this->exportImport->exportUsersCsv();

        return $this->adminDataExport->respondPack(
            $pack,
            'text/csv; charset=UTF-8',
            'admin.user.export',
            ['export_scope' => 'all'],
            '导出 CSV',
            'user.list',
        );
    }

    public function import()
    {
        $text = $this->adminDataImport->readUpload('file');
        if ($text instanceof Response) {
            return $text;
        }
        $blocked = $this->adminDataImport->assertImportAllowed('user.import', $this->adminDataImport->estimateCsvRowCount($text));
        if ($blocked !== null) {
            return $blocked;
        }
        $result = $this->exportImport->importUsersCsv($text);
        if ($result->isOk()) {
            $this->adminDataImport->auditImport('admin.user.import', ['profile' => 'user.import']);
        }

        return AdminApiResponse::admin($result);
    }
}
