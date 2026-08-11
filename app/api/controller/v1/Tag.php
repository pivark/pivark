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
use app\common\service\document\DocumentPublicService;
use app\common\service\tag\TagPublicGateway;
use app\common\support\ApiResponse;
use app\common\support\AppService;
use think\facade\Request;
use think\response\Json;

class Tag
{
    private function tags(): TagPublicGateway
    {
        /** @var TagPublicGateway $gw */
        $gw = AppService::make(TagPublicGateway::class);

        return $gw;
    }

    private function documentPublic(): DocumentPublicService
    {
        /** @var DocumentPublicService $svc */
        $svc = AppService::make(DocumentPublicService::class);

        return $svc;
    }

    public function index(): Json
    {
        $page  = (int) Request::get('page', 1);
        $limit = (int) Request::get('limit', 50);
        $result = $this->tags()->listPublic($page, $limit);

        return ApiResponse::paginate(
            $result['list'],
            $result['total'],
            $result['page'],
            $result['limit']
        );
    }

    public function documents(string $slug = ''): Json
    {
        $slug = (string) ($slug ?: Request::param('slug', ''));
        if ($slug === '') {
            return ApiResponse::failCode(ApiErrorCode::VALIDATION, '参数错误');
        }
        if ($this->tags()->findBySlug($slug) === null) {
            return ApiResponse::failCode(ApiErrorCode::NOT_FOUND, '标签不存在');
        }
        $params         = Request::get();
        $params['tags'] = $slug;
        $result         = $this->documentPublic()->listPublic($params);

        return ApiResponse::paginate(
            $result['list'],
            $result['total'],
            $result['page'],
            $result['limit']
        );
    }
}
