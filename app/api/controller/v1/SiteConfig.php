<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\api\controller\v1;

use app\common\service\config\SiteConfigPublicGateway;
use app\common\support\ApiResponse;
use app\common\support\AppService;
use think\response\Json;

class SiteConfig
{
    private function gateway(): SiteConfigPublicGateway
    {
        /** @var SiteConfigPublicGateway $gw */
        $gw = AppService::make(SiteConfigPublicGateway::class);

        return $gw;
    }

    public function site(): Json
    {
        return ApiResponse::success($this->gateway()->sitePublic());
    }
}
