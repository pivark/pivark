<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\api\controller\v1;

use app\common\support\AppService;
use app\common\service\release\CoreUpdatePublicGateway;
use app\common\support\ApiResponse;
use think\facade\Request;
use think\response\Json;

class Updates
{
    private function gateway(): CoreUpdatePublicGateway
    {
        /** @var CoreUpdatePublicGateway $gw */
        $gw = AppService::make(CoreUpdatePublicGateway::class);

        return $gw;
    }

    /** GET /api/v1/updates/check */
    public function check(): Json
    {
        $refresh = filter_var(Request::param('refresh', false), FILTER_VALIDATE_BOOLEAN);
        $payload = $this->gateway()->check($refresh);
        $payload['site_key'] = trim((string) Request::param('site_key', ''));

        return ApiResponse::success($payload);
    }
}
