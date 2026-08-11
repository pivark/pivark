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
use app\common\support\AdminApiResponse;
use app\common\support\ServiceResult;
use app\common\service\member\MemberCancelService;
use app\common\service\member\MemberConfigService;
use app\common\service\member\MemberFieldService;
use app\common\service\member\MemberLevelService;
use app\common\service\member\MemberBalanceService;
use app\common\service\member\MemberConsumptionService;
use app\common\service\member\MemberOrderService;
use app\common\service\member\MemberPointService;
use app\common\service\member\MemberRechargeService;
use think\facade\Request;
use think\facade\Session;

class MemberCenter extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly MemberFieldService $memberField,
        private readonly MemberConfigService $memberConfig,
        private readonly MemberPointService $memberPoint,
        private readonly MemberBalanceService $memberBalance,
        private readonly MemberConsumptionService $memberConsumption,
        private readonly MemberOrderService $memberOrder,
        private readonly MemberRechargeService $memberRecharge,
        private readonly MemberLevelService $memberLevel,
        private readonly MemberCancelService $memberCancel,
    ) {
        parent::__construct($csrf);
    }

    public function field()
    {
        return $this->renderView('member_center/field_index', [
            'navKey' => 'field',
            'list'   => $this->memberField->listAdmin(),
        ]);
    }

    public function fieldForm()
    {
        $id = (int) Request::get('id', 0);
        $info = $id > 0 ? $this->memberField->findAdmin($id) : null;
        if ($id > 0 && !$info) {
            return redirect('/admin/member_center/field');
        }

        return $this->renderView('member_center/field_form', [
            'navKey' => 'field',
            'info'   => $info,
        ]);
    }

    public function fieldSave()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $res = $this->memberField->saveAdmin(Request::post());

        return AdminApiResponse::fromResult($res);
    }

    public function fieldDelete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->memberField->deleteAdmin((int) Request::post('id', 0)));
    }

    public function config()
    {
        return $this->renderView('member_center/config', [
            'navKey' => 'config',
            'cfg'    => $this->memberConfig->all(),
        ]);
    }

    public function configSave()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->memberConfig->saveAdmin(Request::post()));
    }

    public function points()
    {
        $userId = max(0, (int) Request::get('user_id', 0));
        $page   = max(1, (int) Request::get('page', 1));
        $limit  = min(max((int) Request::get('limit', 20), 1), 100);
        $keyword = trim((string) Request::get('keyword', Request::get('q', '')));
        $result  = $this->memberPoint->listAdmin($page, $limit, $userId, $keyword);

        if (Request::isAjax()) {
            return AdminApiResponse::list(['total' => (int) ($result['total'] ?? 0),
                'list'  => $result['list'] ?? []]);
        }

        return $this->renderView('member_center/points', [
            'navKey' => 'points',
            'list'   => $result['list'],
            'total'  => $result['total'],
            'userId' => $userId,
            'page'   => max(1, (int) Request::get('page', 1)),
        ]);
    }

    public function pointsConfigSave()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->memberConfig->savePointsAdmin(Request::post()));
    }

    public function pointsAdjust()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $admin = Session::get('admin_user') ?? [];

        return AdminApiResponse::admin($this->memberPoint->adjust(
            (int) Request::post('user_id', 0),
            (int) Request::post('delta', 0),
            (string) Request::post('reason', ''),
            (int) ($admin['id'] ?? 0)
        ));
    }

    public function balance()
    {
        $userId  = max(0, (int) Request::get('user_id', 0));
        $page    = max(1, (int) Request::get('page', 1));
        $limit   = min(max((int) Request::get('limit', 20), 1), 100);
        $keyword = trim((string) Request::get('keyword', Request::get('q', '')));
        $result  = $this->memberBalance->listAdmin($page, $limit, $userId, $keyword);

        if (Request::isAjax()) {
            return AdminApiResponse::list(['total' => (int) ($result['total'] ?? 0),
                'list'  => $result['list'] ?? []]);
        }

        return $this->renderView('member_center/balance', [
            'navKey' => 'balance',
            'list'   => $result['list'],
            'total'  => $result['total'],
            'userId' => $userId,
            'page'   => $page,
        ]);
    }

    public function balanceAdjust()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $admin = Session::get('admin_user') ?? [];

        return AdminApiResponse::admin($this->memberBalance->adjust(
            (int) Request::post('user_id', 0),
            (float) Request::post('delta', 0),
            (string) Request::post('reason', ''),
            (int) ($admin['id'] ?? 0)
        ));
    }

    public function consumption()
    {
        $userId  = max(0, (int) Request::get('user_id', 0));
        $page    = max(1, (int) Request::get('page', 1));
        $limit   = min(max((int) Request::get('limit', 20), 1), 100);
        $bizType = (string) Request::get('biz_type', '');
        $keyword = trim((string) Request::get('keyword', Request::get('q', '')));
        $result  = $this->memberConsumption->listAdmin($page, $limit, $userId, $bizType, $keyword);

        if (Request::isAjax()) {
            return AdminApiResponse::list(['total' => (int) ($result['total'] ?? 0),
                'list'  => $result['list'] ?? []]);
        }

        return $this->renderView('member_center/consumption', [
            'navKey' => 'consumption',
            'list'   => $result['list'],
            'total'  => $result['total'],
            'userId' => $userId,
            'page'   => $page,
        ]);
    }

    public function orders()
    {
        $page    = max(1, (int) Request::get('page', 1));
        $limit   = min(max((int) Request::get('limit', 20), 1), 100);
        $filters = [
            'status'  => trim((string) Request::get('status', '')),
            'channel' => trim((string) Request::get('channel', '')),
            'keyword' => trim((string) Request::get('keyword', Request::get('q', ''))),
            'scene'   => trim((string) Request::get('scene', '')),
            'user_id' => max(0, (int) Request::get('user_id', 0)),
        ];
        $result = $this->memberOrder->listAdmin($filters, $page, $limit);

        if (Request::isAjax()) {
            return AdminApiResponse::list([
                'total'         => (int) ($result['total'] ?? 0),
                'list'          => $result['list'] ?? [],
                'stats'         => $result['stats'] ?? ['total' => 0, 'pending' => 0, 'paid' => 0],
                'scene_options' => $result['scene_options'] ?? [],
            ]);
        }

        return $this->renderView('member_center/orders', [
            'navKey' => 'orders',
            'list'   => $result['list'],
            'total'  => $result['total'],
            'stats'  => $result['stats'],
            'page'   => $page,
        ]);
    }

    public function orderDetail()
    {
        $orderNo = trim((string) Request::get('order_no', ''));
        $detail  = $this->memberOrder->detailAdmin($orderNo);
        if ($detail === null) {
            return AdminApiResponse::fail('订单不存在', 404);
        }

        return AdminApiResponse::admin(ServiceResult::ok(['order' => $detail]));
    }

    public function recharge()
    {
        return $this->renderView('member_center/recharge_index', [
            'navKey' => 'recharge',
            'list'   => $this->memberRecharge->listAdmin(),
            'levels' => $this->memberLevel->listActive(),
        ]);
    }

    public function rechargeForm()
    {
        $id = (int) Request::get('id', 0);
        $info = $id > 0 ? $this->memberRecharge->findAdmin($id) : null;
        if ($id > 0 && !$info) {
            return redirect('/admin/member_center/recharge');
        }

        return $this->renderView('member_center/recharge_form', [
            'navKey' => 'recharge',
            'info'   => $info,
            'levels' => $this->memberLevel->listActive(),
        ]);
    }

    public function rechargeSave()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $res = $this->memberRecharge->saveAdmin(Request::post());

        return AdminApiResponse::fromResult($res);
    }

    public function rechargeDelete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->memberRecharge->deleteAdmin((int) Request::post('id', 0)));
    }

    public function rechargeSort()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->memberRecharge->updateSortAdmin(
            (int) Request::post('id', 0),
            (int) Request::post('sort', 0),
        ));
    }

    public function cancel()
    {
        $status = Request::get('status', '');
        $statusFilter = $status === '' ? -1 : (int) $status;
        $result = $this->memberCancel->listAdmin(
            max(1, (int) Request::get('page', 1)),
            20,
            $statusFilter
        );

        return $this->renderView('member_center/cancel', [
            'navKey'       => 'cancel',
            'list'         => $result['list'],
            'total'        => $result['total'],
            'statusFilter' => $statusFilter,
            'page'         => max(1, (int) Request::get('page', 1)),
        ]);
    }

    public function cancelHandle()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $admin = Session::get('admin_user') ?? [];
        $action = (int) Request::post('action', 0);

        return AdminApiResponse::admin($this->memberCancel->handleAdmin(
            (int) Request::post('id', 0),
            $action,
            (string) Request::post('remark', ''),
            (int) ($admin['id'] ?? 0)
        ));
    }
}
