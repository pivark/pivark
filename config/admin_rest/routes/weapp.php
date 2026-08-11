<?php
/**
 * 后台 REST weapp 插件线（/api/v1/admin/weapp/:plugin/:action）
 *
 * 动作名与插件 AdminController 方法一一对应；零兼容迁路径，不改插件 PHP 方法名。
 */
declare(strict_types=1);

use app\admin\controller\plugin\Plugin;

/** @return array{method:string,path:string,handler:array{0:class-string,1:string},options:array<string,mixed>,pattern?:array<string,string>,handler_params?:list<string>} */
$ww = static function (string $method, string $path): array {
    return [
        'method'         => $method,
        'path'           => $path,
        'handler'        => [\app\admin\controller\Weapp::class, 'dispatch'],
        'handler_params' => ['plugin', 'action'],
        'pattern'        => [
            'plugin' => '[a-zA-Z0-9_-]+',
            'action' => '[a-zA-Z0-9_]+',
        ],
        'options'        => [
            'permission_controller' => 'weapp',
            'permission_action'     => 'dispatch',
        ],
    ];
};

return [
    [
        'method'         => 'GET',
        'path'           => 'weapp/:plugin/lists/:action',
        'handler'        => [Plugin::class, 'weappList'],
        'pattern'        => [
            'plugin' => '[a-z0-9_-]+',
            'action' => '[a-z0-9_-]+',
        ],
        'handler_params' => ['plugin', 'action'],
        'options'        => [
            'permission_controller' => 'plugin',
            'permission_action'     => 'weapplist',
        ],
    ],
    $ww('GET', 'weapp/:plugin/:action'),
    $ww('POST', 'weapp/:plugin/:action'),
];
