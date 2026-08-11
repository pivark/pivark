<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare (strict_types = 1);


namespace app\api\controller\v1;

use app\common\service\document\DocumentPublicService;
use app\common\enum\ApiErrorCode;
use app\common\support\ApiResponse;
use app\common\support\AppService;
use think\facade\Request;
use think\response\Json;

class Document
{
    private function documents(): DocumentPublicService
    {
        /** @var DocumentPublicService $svc */
        $svc = AppService::make(DocumentPublicService::class);

        return $svc;
    }

    public function index(): Json
    {
        $result = $this->documents()->listPublic(Request::get());
        $extraMeta = [];
        if (isset($result['next_cursor_id'])) {
            $extraMeta['next_cursor_id'] = (int) $result['next_cursor_id'];
        }

        return ApiResponse::paginate(
            $result['list'],
            $result['total'],
            $result['page'],
            $result['limit'],
            $extraMeta,
        );
    }

    public function read(int $id = 0): Json
    {
        $id = (int) ($id ?: Request::param('id', 0));
        if ($id < 1) {
            return ApiResponse::failCode(ApiErrorCode::VALIDATION, '参数错误');
        }
        $detail = $this->documents()->getPublicDetail($id);
        if ($detail === null) {
            return ApiResponse::failCode(ApiErrorCode::NOT_FOUND, '文档不存在');
        }
        if (!empty($detail['auth_required']) && trim((string) ($detail['content'] ?? '')) === '') {
            return ApiResponse::httpFailCode(
                401,
                ApiErrorCode::AUTH_REQUIRED,
                '需要登录后阅读全文',
                $detail,
                ['meta' => ['auth_required' => true]],
            );
        }

        return ApiResponse::success($detail);
    }
}
