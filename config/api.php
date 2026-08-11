<?php
/**
 * API 模块配置
 */
return [
    'rate_limit' => [
        'enabled'        => true,
        'window_seconds' => 60,
        // 策略 SSOT 已迁至 config/rate_limit.php；此处保留兼容读取，勿再增桶
        'default'        => [
            'max_requests' => 120,
        ],
        // 按路径片段匹配（先匹配先生效）
        'buckets'        => [
            'auth'   => [
                'max_requests' => 20,
                'patterns'     => [
                    'member/mp-wechat/login',
                    'member/mp-wechat/logout',
                    'member/logout',
                ],
            ],
            'pay'    => [
                'max_requests' => 30,
                'patterns'     => [
                    'member/recharge/pay',
                    'member/recharge/order/',
                ],
            ],
            'search' => [
                'max_requests' => 60,
                'patterns'     => [
                    'search/smart',
                    'search/suggest',
                    'search/click',
                ],
            ],
            'favorite' => [
                'max_requests' => 20,
                'patterns'     => [
                    'favorite/like',
                    'favorite/collect',
                ],
            ],
            'write'  => [
                'max_requests' => 40,
                'patterns'     => [
                    'upload',
                    'forms/submit',
                    'stats/beacon',
                ],
            ],
        ],
    ],
];
