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
use app\common\service\member\MemberPublicGateway;
use app\common\support\ApiResponse;
use app\common\support\AppService;
use think\facade\Request;
use think\response\Json;

class Member
{
    private function gateway(): MemberPublicGateway
    {
        /** @var MemberPublicGateway $gw */
        $gw = AppService::make(MemberPublicGateway::class);

        return $gw;
    }

    private function ensurePublicApiEnabled(): ?Json
    {
        if (!$this->gateway()->enabled()) {
            return ApiResponse::failCode(ApiErrorCode::PERMISSION_DENIED, '会员开放接口未启用');
        }

        return null;
    }

    public function index(): Json
    {
        $deny = $this->ensurePublicApiEnabled();
        if ($deny !== null) {
            return $deny;
        }
        $page     = max(1, (int) Request::get('page', 1));
        $limit    = min(max((int) Request::get('limit', 20), 1), 50);
        $level    = max(0, (int) Request::get('level_id', 0));
        $cursorId = max(0, (int) Request::get('cursor_id', 0));
        $result   = $this->gateway()->listPublic($page, $limit, $level, $cursorId);
        $extraMeta = [];
        if (isset($result['next_cursor_id'])) {
            $extraMeta['next_cursor_id'] = (int) $result['next_cursor_id'];
        }

        return ApiResponse::paginate(
            $result['list'],
            (int) $result['total'],
            (int) $result['page'],
            (int) $result['limit'],
            $extraMeta,
        );
    }

    public function read(int $id = 0): Json
    {
        $deny = $this->ensurePublicApiEnabled();
        if ($deny !== null) {
            return $deny;
        }
        $id  = (int) ($id ?: Request::param('id', 0));
        $row = $this->gateway()->readPublic($id);
        if ($row === null) {
            return ApiResponse::failCode(ApiErrorCode::NOT_FOUND, '会员不存在');
        }

        return ApiResponse::success($row);
    }
}
