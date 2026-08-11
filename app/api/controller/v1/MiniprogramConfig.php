<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\api\controller\v1;

use app\common\service\channel\MiniprogramConfigPublicGateway;
use app\common\support\ApiResponse;
use app\common\support\AppService;
use think\response\Json;

class MiniprogramConfig
{
    private function gateway(): MiniprogramConfigPublicGateway
    {
        /** @var MiniprogramConfigPublicGateway $gw */
        $gw = AppService::make(MiniprogramConfigPublicGateway::class);

        return $gw;
    }

    /** GET /api/v1/config/miniprogram/bootstrap — 启动聚合（含 channel 配置） */
    public function bootstrap(): Json
    {
        return ApiResponse::success($this->gateway()->bootstrapPayload());
    }
}
