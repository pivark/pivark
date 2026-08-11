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
use app\common\service\member\MemberMpWechatPublicGateway;
use app\common\support\ApiResponse;
use app\common\support\AppService;
use think\facade\Request;
use think\response\Json;

class MemberMpWechat
{
    private function auth(): MemberAuthPublicGateway
    {
        /** @var MemberAuthPublicGateway $gw */
        $gw = AppService::make(MemberAuthPublicGateway::class);

        return $gw;
    }

    private function gateway(): MemberMpWechatPublicGateway
    {
        /** @var MemberMpWechatPublicGateway $gw */
        $gw = AppService::make(MemberMpWechatPublicGateway::class);

        return $gw;
    }

    /** POST /api/v1/member/mp-wechat/login { code } */
    public function login(): Json
    {
        $code   = trim((string) Request::param('code', ''));
        $result = $this->gateway()->loginByCode($code);
        if (!$result->isOk()) {
            return ApiResponse::failCode(ApiErrorCode::AUTH_REQUIRED, (string) ($result->message() ?: '登录失败'));
        }

        $data = $result->dataArray();
        $msg  = $result->message();
        $meta = ($msg !== '' && $msg !== 'ok') ? ['message' => $msg] : null;

        return ApiResponse::success([
            'token'      => (string) ($data['token'] ?? ''),
            'expires_at' => (string) ($data['expires_at'] ?? ''),
            'openid'     => (string) ($data['openid'] ?? ''),
            'member'     => $data['member'] ?? [],
        ], $meta);
    }

    /** GET /api/v1/member/me */
    public function me(): Json
    {
        $userId = $this->auth()->requireUserId();
        if ($userId < 1) {
            return ApiResponse::httpFailCode(401, ApiErrorCode::AUTH_REQUIRED, '请先登录');
        }

        return ApiResponse::success($this->gateway()->publicMember($userId));
    }

    /** POST /api/v1/member/mp-wechat/logout */
    public function logout(): Json
    {
        $this->gateway()->revokeCurrentToken();

        return ApiResponse::success(null, ['message' => '已退出']);
    }
}
