<?php
/**
 * 小程序渠道注册表（内核空壳）
 *
 * hub / market_skus 由 weapp 插件 boot 经 WeappMiniprogramGateway 注入。
 * 本文件仅保留可选覆盖位（站点自定义 SKU 行可写 market_skus）。
 */
return [
    /** 站点强制 hub（须已 registerHub）；空 = 用已注册 hub 的第一个 */
    'hub_plugin' => '',

    /** 内核读 SPA 路径兜底（优先用 hub 注册的 guide_route） */
    'admin_spa' => [
        'hub_guide'            => '',
        'social_auth_settings' => '/weapp/social-auth/settings',
    ],

    /** 可选：站点级自定义 SKU；官方微信 SKU 由 mp-wechat 插件注册 */
    'market_skus' => [],
];
