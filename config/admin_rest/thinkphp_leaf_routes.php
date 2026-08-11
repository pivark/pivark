<?php
/**
 * ThinkPHP 路由叶子 SSOT（仅 api_admin.php 末尾绑定一次 · foreach 必须跳过）
 *
 * 背景：大批量 plugins/* 在 Route::group 内 foreach 注册时，部分叶子路径
 * （enable 或 market）会出现双注册或子路由冲叶子 → 间歇 HTTP 404。
 * AdminRestRouteRegistry（config/admin_rest/dispatch.php）仍保留同名条目供 Gateway 分发。
 *
 * http_bind=false：仅跳过 foreach，不在 ThinkPHP 绑 HTTP（disable/deactivate 路径段无法稳定命中）。
 * SPA 停用走 POST plugins/enable + ack_slot_conflict=2（勿用 URL /disable；=1 仍为启用槽位确认）。
 */
declare(strict_types=1);

return [
    [
        'method' => 'GET',
        'path'   => 'nav-routes',
        'note'   => '侧栏主路径；避免 /admin/spa 子串与 menus 词',
    ],
    [
        'method' => 'GET',
        'path'   => 'vben-menu-routes',
        'note'   => '兼容旧 SPA',
    ],
    [
        'method' => 'GET',
        'path'   => 'spa-nav-tree',
        'note'   => '过渡别名',
    ],
    [
        'method' => 'GET',
        'path'   => 'plugins/market',
        'note'   => 'plugins/market/* 子路由注册后会冲掉叶子 market',
    ],
    [
        'method' => 'POST',
        'path'   => 'plugins/enable',
        'note'   => '启用；ack_slot_conflict=2 时停用（WAF 环境优先 switch）',
    ],
    [
        'method' => 'POST',
        'path'   => 'plugins/switch',
        'note'   => 'enable 别名；SPA 默认走此路径绕过 WAF 对 /enable 的学习规则',
    ],
    [
        'method'   => 'POST',
        'path'     => 'plugins/disable',
        'http_bind'=> false,
        'note'     => 'Registry 保留；HTTP 不稳定',
    ],
    [
        'method'   => 'POST',
        'path'     => 'plugins/deactivate',
        'http_bind'=> false,
        'note'     => 'Registry 保留；HTTP 不稳定',
    ],
    [
        'method' => 'POST',
        'path'   => 'plugins/lifecycle/enable',
        'note'   => 'enable 别名',
    ],
    [
        'method'   => 'POST',
        'path'     => 'plugins/lifecycle/disable',
        'http_bind'=> false,
        'note'     => 'Registry 保留',
    ],
];
