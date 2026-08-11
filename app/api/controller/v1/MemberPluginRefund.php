<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\api\controller\v1;

use app\common\enum\ApiErrorCode;
use app\common\service\member\MemberAuthPublicGateway;
use app\common\service\plugin\commerce\PluginRefundRequestService;
use app\common\support\ApiResponse;
use think\facade\Request;
use think\response\Json;

/** 会员自助：插件授权订单退款申请 */
class MemberPluginRefund
{
    public function __construct(
        private readonly MemberAuthPublicGateway $memberAuth,
        private readonly PluginRefundRequestService $pluginRefund,
    ) {
    }

    /** GET /api/v1/member/plugin-refund/requests */
    public function requests(): Json
    {
        $userId = $this->memberAuth->requireUserId();
        if ($userId < 1) {
            return ApiResponse::httpFailCode(401, ApiErrorCode::AUTH_REQUIRED, '请先登录');
        }

        return ApiResponse::success([
            'list' => $this->pluginRefund->listForUser($userId),
        ]);
    }

    /** POST /api/v1/member/plugin-refund/request { order_no, reason } */
    public function submit(): Json
    {
        $userId = $this->memberAuth->requireUserId();
        if ($userId < 1) {
            return ApiResponse::httpFailCode(401, ApiErrorCode::AUTH_REQUIRED, '请先登录');
        }

        $orderNo = trim((string) Request::post('order_no', ''));
        $reason  = trim((string) Request::post('reason', ''));
        $result  = $this->pluginRefund->request($orderNo, $userId, $reason);
        if (!$result->isOk()) {
            return ApiResponse::httpFailCode(400, ApiErrorCode::VALIDATION_FAILED, (string) ($result->message() ?? '提交失败'));
        }

        return ApiResponse::success($result->dataArray(), (string) ($result->message() ?? '已提交'));
    }

    /** GET /api/v1/member/plugin-refund/status/:order_no */
    public function status(string $order_no = ''): Json
    {
        $userId = $this->memberAuth->requireUserId();
        if ($userId < 1) {
            return ApiResponse::httpFailCode(401, ApiErrorCode::AUTH_REQUIRED, '请先登录');
        }

        $orderNo = trim($order_no !== '' ? $order_no : (string) Request::param('order_no', ''));
        $row     = $this->pluginRefund->statusForOrder($orderNo, $userId);
        if ($row === null) {
            return ApiResponse::httpFailCode(404, ApiErrorCode::NOT_FOUND, '暂无退款申请记录');
        }

        return ApiResponse::success($row);
    }
}
