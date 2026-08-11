<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller;

use app\common\support\PivarkVueRoute;
use app\common\support\SiteUrl;
use think\facade\Request;
use think\Response;

trait RendersView
{
    /**
     * 后台视图：302 到 Vue SPA
     *
     * @param array<string, mixed> $vars
     */
    protected function renderView(string $view, array $vars = []): Response
    {
        $query   = Request::get();
        $spaPath = PivarkVueRoute::spaPathFromView($view, $vars, is_array($query) ? $query : []);

        return redirect(
            $spaPath !== null ? SiteUrl::adminSpa($spaPath) : SiteUrl::adminSpa()
        );
    }
}
