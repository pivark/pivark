<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\route;

use think\Route;
use think\route\RuleItem;

/**
 * HTTP 语义：凡注册 GET 的资源同时接受 HEAD（RFC 9110）。
 * ThinkPHP 默认 Route::get 只挂 GET，导致全站 HEAD → 404。
 */
class PivarkRoute extends Route
{
    public function get(string $rule, $route): RuleItem
    {
        return $this->rule($rule, $route, 'GET|HEAD');
    }
}
