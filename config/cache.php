<?php
// 缓存配置
return [
    // 默认缓存驱动
    'default' => 'file',
    // 缓存连接配置
    'stores'  => [
        'file' => [
            // 驱动方式
            'type'       => 'File',
            // 缓存保存目录
            'path'       => '',
            // 缓存前缀
            'prefix'     => env('CACHE_PREFIX', 'pv_'),
            // 缓存有效期 0表示永久缓存
            'expire'     => 0,
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 序列化机制
            'serialize'  => [],
        ],
        'redis' => [
            'type'     => 'redis',
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'port'     => (int) env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', ''),
            'select'   => (int) env('REDIS_SELECT', 0),
        ],
    ],
];