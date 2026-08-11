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
use app\common\service\favorite\FavoritePublicGateway;
use app\common\support\ApiResponse;
use app\common\support\AppService;
use app\common\support\ServiceResult;
use think\facade\Request;
use think\response\Json;

/** 点赞收藏 API（内核） */
class Favorite
{
    private function favorites(): FavoritePublicGateway
    {
        /** @var FavoritePublicGateway $gw */
        $gw = AppService::make(FavoritePublicGateway::class);

        return $gw;
    }

    public function stats(int $document_id = 0): Json
    {
        $id = (int) ($document_id ?: Request::param('document_id', 0));

        return ApiResponse::success($this->favorites()->stats($id));
    }

    public function like(): Json
    {
        $id = (int) Request::post('document_id', 0);

        return self::toggleResponse($this->favorites()->toggleLike($id));
    }

    public function collect(): Json
    {
        $id = (int) Request::post('document_id', 0);

        return self::toggleResponse($this->favorites()->toggleCollect($id));
    }

    private static function toggleResponse(ServiceResult $result): Json
    {
        if ($result->isOk()) {
            return ApiResponse::success($result->dataArray()['stats'] ?? null);
        }

        return ApiResponse::failCode(ApiErrorCode::VALIDATION, (string) ($result->message() ?: '操作失败'));
    }
}
