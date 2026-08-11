<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\weapp;

/** 后台「插件中心」模块内 Tab 导航（SSOT） */
class PluginNav
{
    /**
     * @return list<array{key:string,title:string,route:string,ready:bool,hint?:string}>
     */
    public function items(): array
    {
        return [
            ['key' => 'mine', 'title' => '已安装插件', 'route' => '/admin/plugin/mine', 'ready' => true],
            ['key' => 'cloud', 'title' => '应用市场', 'route' => '/admin/plugin/cloud', 'ready' => true],
            ['key' => 'workbench', 'title' => '开发者工作台', 'route' => '/admin/plugin/workbench', 'ready' => true],
            ['key' => 'purchased', 'title' => '已授权插件', 'route' => '/admin/plugin/purchased', 'ready' => true],
        ];
    }
}
