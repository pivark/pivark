<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\api\controller\v1\admin;

use app\common\service\admin\AdminRestDispatchService;
use think\Response;

/** /api/v1/admin/* REST 网关 */
class Gateway
{
    public function __construct(
        private readonly AdminRestDispatchService $dispatch,
    ) {
    }

    /**
     * 禁止方法参数名 path：ThinkPHP 会把 ?path= 查询参数注入进来，
     * 导致 media/delete-preview 等接口被误判为「接口不存在：uploads/...」。
     * 相对路径一律从 pathinfo 解析。
     */
    public function dispatch(): Response
    {
        return $this->dispatch->dispatch(null);
    }
}
