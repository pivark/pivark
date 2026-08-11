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
use app\common\service\export\AdminDataExportSupport;
use app\common\support\AdminApiResponse;
use app\common\support\ServiceResult;
use app\common\service\member\MemberLevelService;
use app\common\service\member\MemberOpsService;
use app\common\service\member\MemberService;
use app\common\support\AdminBatchSupport;
use think\facade\Request;
use think\facade\Session;
use think\Response;

class Member extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly MemberService $member,
        private readonly MemberLevelService $memberLevel,
        private readonly MemberOpsService $memberOps,
        private readonly AdminDataExportSupport $adminDataExport,
        private readonly AdminSpaMetaService $spaMeta,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        $levelFilter = max(0, (int) Request::get('level_id', 0));
        $keyword = trim((string) Request::get('keyword', ''));
        $statusFilter = (int) Request::get('blacklist', Request::get('status_filter', -1));
        $source = trim((string) Request::get('source', ''));
        $dateFrom = trim((string) Request::get('date_from', ''));
        $dateTo = trim((string) Request::get('date_to', ''));
        $listFilter = trim((string) Request::get('filter', ''));
        $mobile = trim((string) Request::get('mobile', ''));
        $accountKind = trim((string) Request::get('account_kind', ''));
        $page = max(1, (int) Request::get('page', 1));
        $limit = min(max((int) Request::get('limit', 15), 1), 100);
        $list = $this->member->listAdmin(
            $page,
            $limit,
            $keyword,
            $levelFilter,
            $statusFilter,
            $source,
            $dateFrom,
            $dateTo,
            $listFilter,
            $mobile,
            $accountKind
        );

        if (Request::isAjax()) {
            $rows = [];
            foreach ($list->items() as $item) {
                $rows[] = is_object($item) && method_exists($item, 'toArray')
                    ? $item->toArray()
                    : (array) $item;
            }
            $rows = $this->member->enrichListRows($rows);
            $payload = [
                'total' => (int) $list->total(),
                'list'  => $rows,
            ];
            if ($page === 1) {
                $payload['member_levels'] = $this->memberLevel->listActiveOptions();
            }

            return AdminApiResponse::list($payload);
        }

        return $this->renderView('member/index', [
            'list'        => $list,
            'keyword'     => $keyword,
            'levelFilter' => $levelFilter,
        ]);
    }

    public function create()
    {
        return $this->renderView('member/form', [
            'memberLevels' => $this->memberLevel->listActive(),
        ]);
    }

    public function edit()
    {
        $id = (int) Request::get('id', 0);
        $info = $this->member->findUserForAdminForm($id);
        if (!$info) {
            return redirect('/admin/member/index');
        }

        return $this->renderView('member/form', [
            'info'         => $info,
            'memberLevels' => $this->memberLevel->listActive(),
        ]);
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $id = (int) Request::post('id', 0);
        $data = Request::post();
        if ($id < 1) {
            $usernames = trim((string) ($data['usernames'] ?? ''));
            if ($usernames !== '') {
                $res = $this->member->createAdminBatch(
                    $usernames,
                    (string) ($data['password'] ?? ''),
                    max(0, (int) ($data['member_level_id'] ?? 0))
                );

                return AdminApiResponse::fromResult($res);
            }
        }
        $res = $id ? $this->member->updateAdmin($id, $data) : $this->member->createAdmin($data);

        return AdminApiResponse::fromResult($res);
    }

    public function batchDelete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $ids = AdminBatchSupport::parsePostIds();

        return AdminApiResponse::admin($this->member->deleteAdminBatch($ids));
    }

    public function delete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->member->deleteAdmin((int) Request::post('id', 0)));
    }

    public function export(): Response
    {
        $ids = $this->adminDataExport->parseExportIdsFromRequest();
        $scope = trim((string) Request::get('export_scope', ''));

        if ($ids !== []) {
            $rows = $this->memberOps->exportRowsByIds($ids);
            $auditExtra = ['ids_count' => count($ids), 'export_scope' => 'selected'];
        } elseif ($scope === 'filter') {
            $rows = $this->memberOps->exportRows([
                'keyword'   => trim((string) Request::get('keyword', '')),
                'level_id'  => max(0, (int) Request::get('level_id', 0)),
                'blacklist' => (int) Request::get('blacklist', Request::get('status_filter', -1)),
                'source'    => trim((string) Request::get('source', '')),
                'date_from' => trim((string) Request::get('date_from', '')),
                'date_to'   => trim((string) Request::get('date_to', '')),
                'filter'    => trim((string) Request::get('filter', '')),
                'mobile'    => trim((string) Request::get('mobile', '')),
            ]);
            $auditExtra = ['export_scope' => 'filter', 'row_count' => count($rows)];
        } else {
            return AdminApiResponse::fail('请选择要导出的会员，或使用筛选导出');
        }

        if ($rows === []) {
            return AdminApiResponse::fail('未找到可导出的会员');
        }

        [$headers, $tableRows] = $this->memberOps->exportTableRows($rows);

        return $this->adminDataExport->respondCsv(
            'members',
            $headers,
            $tableRows,
            'admin.member.export',
            $auditExtra,
            'member.list',
        );
    }

    public function batchAdjust()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $ids = AdminBatchSupport::parsePostIds();
        $admin = Session::get('admin_user', []);
        $adminId = is_array($admin) ? (int) ($admin['id'] ?? 0) : 0;

        return AdminApiResponse::admin($this->memberOps->batchAdjust(
            $ids,
            max(0, (int) Request::post('member_level_id', 0)),
            (int) Request::post('point_delta', 0),
            trim((string) Request::post('point_reason', '')),
            $adminId
        ));
    }

    /** GET — 会员详情（REST · 原 Spa::memberDetail） */
    public function detail(): Response
    {
        $row = $this->spaMeta->memberDetail((int) Request::get('id', 0));
        if ($row === null) {
            return AdminApiResponse::fromResult(ServiceResult::notFound('会员不存在'));
        }

        return AdminApiResponse::fromResult(ServiceResult::ok($row));
    }
}
