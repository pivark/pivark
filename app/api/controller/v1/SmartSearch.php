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
use app\common\service\search\SearchPublicGateway;
use app\common\support\ApiResponse;
use app\common\support\AppService;
use think\facade\Request;
use think\response\Json;

/** 超级搜索开放 API（P5） */
class SmartSearch
{
    private function gateway(): SearchPublicGateway
    {
        /** @var SearchPublicGateway $gw */
        $gw = AppService::make(SearchPublicGateway::class);

        return $gw;
    }

    public function query(): Json
    {
        $gw      = $this->gateway();
        $keyword = $gw->normalizeKeyword((string) Request::post('q', Request::get('q', '')));
        $blocked = $gw->guardKeyword($keyword);
        if ($blocked !== null) {
            return ApiResponse::failCode(ApiErrorCode::VALIDATION, $blocked->message());
        }
        $limit = max(1, min(30, (int) Request::param('limit', 12)));
        $data  = $gw->search($keyword, $limit);

        return ApiResponse::success($data);
    }

    public function suggest(): Json
    {
        $prefix = trim((string) Request::get('q', ''));
        $list   = $this->gateway()->suggest($prefix, 10);

        return ApiResponse::success(['list' => $list]);
    }

    public function click(): Json
    {
        $gw      = $this->gateway();
        $keyword = $gw->normalizeKeyword((string) Request::post('q', Request::get('q', '')));
        $gw->logClick(
            $keyword,
            (string) Request::post('type', Request::get('type', 'link')),
            (int) Request::post('id', Request::get('id', 0)),
            (string) Request::post('url', Request::get('url', '')),
        );

        return ApiResponse::success(null);
    }
}
