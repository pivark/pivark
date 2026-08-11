<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\api\controller\v1\member;

use app\common\service\member\MemberRestDispatchService;
use think\Response;

/** /api/v1/member/* REST 网关 */
class Gateway
{
    public function __construct(
        private readonly MemberRestDispatchService $dispatch,
    ) {
    }

    /**
     * 禁止方法参数名 path：ThinkPHP 会把 ?path= 查询参数注入进来，
     * 相对路径一律从 pathinfo 解析（与 admin Gateway 同因）。
     */
    public function dispatch(): Response
    {
        return $this->dispatch->dispatch(null);
    }
}
