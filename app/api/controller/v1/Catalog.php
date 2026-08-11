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
use app\common\service\catalog\CatalogPublicGateway;
use app\common\support\ApiResponse;
use app\common\support\AppService;
use think\facade\Request;
use think\response\Json;

/** 跨域 Catalog 查询 API（items / erp / mes / oa / shop） */
class Catalog
{
    private function catalog(): CatalogPublicGateway
    {
        /** @var CatalogPublicGateway $gw */
        $gw = AppService::make(CatalogPublicGateway::class);

        return $gw;
    }

    /** GET /api/v1/catalog — 已注册业务域清单 */
    public function index(): Json
    {
        return ApiResponse::success([
            'domains' => $this->catalog()->manifest(),
        ]);
    }

    /** GET /api/v1/catalog/:domain — 列表 + 筛选元数据 */
    public function query(string $domain = ''): Json
    {
        $domain = trim((string) ($domain ?: Request::param('domain', '')));
        if ($domain === '') {
            return ApiResponse::failCode(ApiErrorCode::VALIDATION, 'domain 无效');
        }

        $payload = $this->catalog()->query($domain, Request::get());
        if (!empty($payload['unavailable'])) {
            return ApiResponse::failCode(ApiErrorCode::PERMISSION_DENIED, (string) $payload['unavailable']);
        }

        $meta = [
            'domain'    => (string) ($payload['domain'] ?? $domain),
            'page'      => (int) ($payload['page'] ?? 1),
            'limit'     => (int) ($payload['limit'] ?? 20),
            'has_more'  => (int) ($payload['has_more'] ?? 0),
        ];
        if (($payload['total'] ?? -1) >= 0) {
            $meta['total'] = (int) $payload['total'];
        }
        if (!empty($payload['next_cursor'])) {
            $meta['next_cursor'] = (string) $payload['next_cursor'];
        }
        if (!empty($payload['cursor'])) {
            $meta['cursor'] = (string) $payload['cursor'];
        }

        return ApiResponse::success([
            'list'    => $payload['list'] ?? [],
            'filters' => $payload['filters'] ?? [],
        ], $meta);
    }
}
