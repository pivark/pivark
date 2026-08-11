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
use app\common\service\member\MemberRechargeGateway;
use app\common\service\payment\PaymentConfigGateway;
use app\common\service\payment\PaymentPublicGateway;
use app\common\support\ApiResponse;
use app\common\support\AppService;
use think\facade\Request;
use think\response\Json;

class MemberRecharge
{
    private function auth(): MemberAuthPublicGateway
    {
        /** @var MemberAuthPublicGateway $gw */
        $gw = AppService::make(MemberAuthPublicGateway::class);

        return $gw;
    }

    private function paymentConfig(): PaymentConfigGateway
    {
        /** @var PaymentConfigGateway $gw */
        $gw = AppService::make(PaymentConfigGateway::class);

        return $gw;
    }

    private function recharge(): MemberRechargeGateway
    {
        /** @var MemberRechargeGateway $gw */
        $gw = AppService::make(MemberRechargeGateway::class);

        return $gw;
    }

    private function payments(): PaymentPublicGateway
    {
        /** @var PaymentPublicGateway $gw */
        $gw = AppService::make(PaymentPublicGateway::class);

        return $gw;
    }

    /** GET /api/v1/member/recharge/packages */
    public function packages(): Json
    {
        $userId   = $this->auth()->resolveUserId();
        $recharge = $this->recharge();
        $packages = $recharge->listPublic();
        if ($userId > 0) {
            $packages = $recharge->enrichPublicForUser($packages, $userId);
        }
        $out = [];
        foreach ($packages as $pkg) {
            $out[] = [
                'id'                  => (int) ($pkg['id'] ?? 0),
                'title'               => (string) ($pkg['title'] ?? ''),
                'package_type'        => (string) ($pkg['package_type'] ?? ''),
                'package_type_label'  => (string) ($pkg['package_type_label'] ?? ''),
                'price'               => (float) ($pkg['price'] ?? 0),
                'price_text'          => (string) ($pkg['price_text'] ?? number_format((float) ($pkg['price'] ?? 0), 2, '.', '')),
                'description'         => (string) ($pkg['description'] ?? ''),
                'benefit_text'        => (string) ($pkg['benefit_text'] ?? $recharge->benefitSummary($pkg)),
                'level_id'            => (int) ($pkg['level_id'] ?? 0),
                'points'              => (int) ($pkg['points'] ?? 0),
                'days'                => (int) ($pkg['days'] ?? 0),
            ];
        }

        $flags = $this->paymentConfig()->frontPaymentFlags();

        return ApiResponse::success([
            'list'            => $out,
            'payment_open'    => $this->payments()->paymentEnabled() ? 1 : 0,
            'payment_demo'    => $this->paymentConfig()->shouldUseDemo(PaymentConfigGateway::CHANNEL_WECHAT) ? 1 : 0,
            'wechat_ready'    => $flags['wechat'],
            'alipay_ready'    => $flags['alipay'],
            'channels'        => $flags['channels'],
        ]);
    }

    /** POST /api/v1/member/recharge/pay { package_id } */
    public function pay(): Json
    {
        $userId = $this->auth()->requireUserId();
        if ($userId < 1) {
            return ApiResponse::httpFailCode(401, ApiErrorCode::AUTH_REQUIRED, '请先登录');
        }
        $packageId = (int) Request::param('package_id', 0);
        $openid    = $this->auth()->openidForUser($userId);
        $payCfg    = $this->paymentConfig();
        if (!$payCfg->isFrontChannelAllowed(PaymentConfigGateway::CHANNEL_WECHAT)) {
            return ApiResponse::failCode(ApiErrorCode::PAYMENT_FAILED, '微信支付未配置');
        }
        if ($openid === '' && !$payCfg->shouldUseDemo(PaymentConfigGateway::CHANNEL_WECHAT)) {
            return ApiResponse::failCode(ApiErrorCode::AUTH_REQUIRED, '未绑定微信 openid，请重新登录');
        }

        $result = $this->recharge()->createPaymentOrder($userId, $packageId, PaymentConfigGateway::CHANNEL_WECHAT, [
            'openid' => $openid,
            'client' => 'miniprogram',
        ]);
        if (!$result->isOk()) {
            return ApiResponse::failCode(ApiErrorCode::PAYMENT_FAILED, $result->message() !== '' ? $result->message() : '下单失败');
        }

        $pay = $result->dataArray();
        $payload = [
            'order_no' => (string) ($pay['order_no'] ?? ''),
            'type'     => (string) ($pay['type'] ?? ''),
            'demo'     => (int) ($pay['demo'] ?? 0),
        ];
        if (!empty($pay['pay_params']) && is_array($pay['pay_params'])) {
            $payload['pay_params'] = $pay['pay_params'];
        }
        if (($pay['type'] ?? '') === 'demo') {
            $payload['member'] = $this->auth()->publicMember($userId);
        }

        return ApiResponse::success($payload);
    }

    /** GET /api/v1/member/recharge/order/:order_no */
    public function orderStatus(string $orderNo = ''): Json
    {
        $userId = $this->auth()->requireUserId();
        if ($userId < 1) {
            return ApiResponse::httpFailCode(401, ApiErrorCode::AUTH_REQUIRED, '请先登录');
        }
        $orderNo = trim((string) ($orderNo ?: Request::param('order_no', '')));
        if ($orderNo === '') {
            return ApiResponse::failCode(ApiErrorCode::VALIDATION, '缺少订单号');
        }
        $order = $this->payments()->findByOrderNo($orderNo);
        if ($order === null || (int) ($order['user_id'] ?? 0) !== $userId) {
            return ApiResponse::failCode(ApiErrorCode::NOT_FOUND, '订单不存在');
        }

        if (!$this->payments()->isPaid($order)) {
            $this->payments()->trySyncPaidFromGateway($orderNo, $userId);
            $order = $this->payments()->findByOrderNo($orderNo);
        }

        $paid = $this->payments()->isPaid($order);

        return ApiResponse::success([
            'order_no' => $orderNo,
            'status'   => (string) ($order['status'] ?? ''),
            'paid'     => $paid ? 1 : 0,
            'amount'   => (string) ($order['amount'] ?? ''),
            'member'   => $paid ? $this->auth()->publicMember($userId) : null,
        ]);
    }
}
