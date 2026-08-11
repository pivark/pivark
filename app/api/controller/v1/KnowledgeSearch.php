<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\api\controller\v1;

use app\common\service\ai\KnowledgeSearchPublicGateway;
use app\common\support\ApiResponse;
use app\common\support\AppService;
use think\facade\Request;
use think\response\Json;

/** 前台知识搜索 API（超级搜索） */
class KnowledgeSearch
{
    private function gateway(): KnowledgeSearchPublicGateway
    {
        /** @var KnowledgeSearchPublicGateway $gw */
        $gw = AppService::make(KnowledgeSearchPublicGateway::class);

        return $gw;
    }

    public function search(): Json
    {
        $keyword = trim((string) Request::post('q', Request::get('q', '')));

        return ApiResponse::fromServiceResult($this->gateway()->searchPayload($keyword));
    }
}
