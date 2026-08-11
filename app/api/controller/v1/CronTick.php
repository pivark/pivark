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
use app\common\service\cron\CronTickPublicGateway;
use app\common\support\ApiResponse;
use app\common\support\AppService;
use think\facade\Request;
use think\response\Json;

class CronTick
{
    private function gateway(): CronTickPublicGateway
    {
        /** @var CronTickPublicGateway $gw */
        $gw = AppService::make(CronTickPublicGateway::class);

        return $gw;
    }

    /** GET /api/v1/system/cron-tick?token=… */
    public function tick(): Json
    {
        $gw    = $this->gateway();
        $token = trim((string) Request::param('token', ''));
        if ($token === '' || !hash_equals($gw->webhookToken(), $token)) {
            return ApiResponse::httpFailCode(403, ApiErrorCode::PERMISSION_DENIED, 'forbidden');
        }

        $limited = $gw->guardWebhookRate((string) Request::ip());
        if ($limited !== null) {
            return ApiResponse::httpFailCode(
                429,
                ApiErrorCode::RATE_LIMITED,
                (string) ($limited['msg'] ?? '请求过于频繁'),
            );
        }

        return ApiResponse::success($gw->runDueJobs(false));
    }
}
