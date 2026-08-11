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
use app\common\service\site\FormPublicGateway;
use app\common\support\ApiResponse;
use app\common\support\AppService;
use think\facade\Request;
use think\response\Json;

class Form
{
    private function gateway(): FormPublicGateway
    {
        /** @var FormPublicGateway $gw */
        $gw = AppService::make(FormPublicGateway::class);

        return $gw;
    }

    public function schema(string $slug = ''): Json
    {
        $slug = trim((string) ($slug ?: Request::param('slug', '')));
        $form = $this->gateway()->schemaBySlug($slug);
        if ($form === null) {
            return ApiResponse::failCode(ApiErrorCode::NOT_FOUND, '表单不存在');
        }

        return ApiResponse::success($form);
    }

    public function submit(): Json
    {
        $result = $this->gateway()->submitPublic(Request::post());
        if (!$result->isOk()) {
            return ApiResponse::failCode(ApiErrorCode::VALIDATION, $result->message());
        }

        return ApiResponse::success(null, ['message' => $result->message()]);
    }
}
