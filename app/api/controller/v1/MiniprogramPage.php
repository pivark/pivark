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
use think\facade\Request;
use think\response\Json;

class MiniprogramPage
{
    private function gateway(): MiniprogramConfigPublicGateway
    {
        /** @var MiniprogramConfigPublicGateway $gw */
        $gw = AppService::make(MiniprogramConfigPublicGateway::class);

        return $gw;
    }

    /** GET /api/v1/config/miniprogram/page/home */
    public function home(): Json
    {
        return ApiResponse::success($this->gateway()->homePagePayload());
    }

    /** GET /api/v1/config/miniprogram/page/tags */
    public function tags(): Json
    {
        return ApiResponse::success($this->gateway()->tagsPagePayload());
    }

    /** GET /api/v1/config/miniprogram/page/products */
    public function products(): Json
    {
        return ApiResponse::success($this->gateway()->productsPagePayload());
    }

    /** GET /api/v1/config/miniprogram/page/mine */
    public function mine(): Json
    {
        return ApiResponse::success($this->gateway()->minePagePayload());
    }

    /** GET /api/v1/config/miniprogram/slides?slot=home_carousel */
    public function slides(): Json
    {
        return ApiResponse::success($this->gateway()->slides(trim((string) Request::get('slot', ''))));
    }
}
