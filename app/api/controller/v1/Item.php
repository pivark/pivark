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
use app\common\service\item\ItemPublicGateway;
use app\common\service\product\DocumentProductFacade;
use app\common\support\ApiResponse;
use app\common\support\AppService;
use think\facade\Request;
use think\response\Json;

class Item
{
    private function items(): ItemPublicGateway
    {
        /** @var ItemPublicGateway $gw */
        $gw = AppService::make(ItemPublicGateway::class);

        return $gw;
    }

    private function products(): DocumentProductFacade
    {
        return app(DocumentProductFacade::class);
    }

    public function read(string $slug = ''): Json
    {
        $slug = trim((string) ($slug ?: Request::param('slug', '')));
        if ($slug === '') {
            return ApiResponse::failCode(ApiErrorCode::VALIDATION, '参数错误');
        }
        $row = $this->items()->findPublicBySlug($slug);
        if ($row === null) {
            return ApiResponse::failCode(ApiErrorCode::NOT_FOUND, '品项不存在');
        }

        return ApiResponse::success($this->products()->enrichItemReadIfOpen($row));
    }

    /** GET /api/v1/items/compare?ids=1,2,3 */
    public function compare(): Json
    {
        $gate = $this->products()->compareGate();
        if ($gate !== null) {
            return ApiResponse::failCode(
                ApiErrorCode::PERMISSION_DENIED,
                (string) ($gate->message() ?: '产品展示不可用'),
            );
        }
        $ids = [];
        foreach (preg_split('/[,\s|]+/', trim((string) Request::get('ids', ''))) ?: [] as $part) {
            $id = (int) trim((string) $part);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return ApiResponse::failCode(ApiErrorCode::VALIDATION, 'ids 无效');
        }

        return ApiResponse::success($this->products()->compareItems($ids));
    }
}
