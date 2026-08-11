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
use app\common\service\access\StatsPublicGateway;
use app\common\support\ApiResponse;
use app\common\support\AppService;
use think\response\Json;

class Stats
{
    private function gateway(): StatsPublicGateway
    {
        /** @var StatsPublicGateway $gw */
        $gw = AppService::make(StatsPublicGateway::class);

        return $gw;
    }

    /** POST 行为信标（停留、跳出、点击热力） */
    public function beacon(): Json
    {
        $payload = request()->post();
        $result  = $this->gateway()->recordBeacon(is_array($payload) ? $payload : []);

        if (!$result->isOk()) {
            return ApiResponse::failCode(ApiErrorCode::VALIDATION, (string) ($result->message() ?: 'fail'));
        }

        $msg = $result->message();

        return ApiResponse::success(null, ['message' => $msg !== '' ? $msg : 'ok']);
    }
}
