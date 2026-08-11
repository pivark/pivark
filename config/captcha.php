<?php
/**
 * 元舟 PivArk — 验证码场景配置（全站 SSOT）
 * 内置场景 + 正则扩展（plugin.* / oa.*）+ 运行时 registerScene
 */
declare(strict_types=1);

return [
    // Session 键前缀，完整键为 {prefix}{scene}，如 pv_captcha.admin
    'session_prefix' => 'pv_captcha.',

    // 全局开关对应 configs 表 key；单场景 enabled=null 时继承此项
    'global_enabled_key' => 'captcha_on',

    'expire'   => 180,
    'length'   => 5,
    // numeric | alnum
    'charset'  => 'alnum',

    // 单码最多校验失败次数，超限后清除 Session 须重新出图
    'max_verify_attempts' => 5,

    // 出图频率限制（按 IP + 场景）
    'image_rate_limit' => [
        'enabled'         => true,
        'max'             => 40,
        'window_seconds'  => 60,
    ],

    // 图片尺寸
    'width'    => 132,
    'height'   => 36,

    // 出图渲染（TTF + 干扰，逻辑对齐 think-captcha；Session/校验仍走 CaptchaService）
    'render' => [
        'font'       => 'public/static/common/fonts/captcha.ttf',
        'font_size'  => 18,
        'use_curve'  => true,
        'use_noise'  => true,
        'bg'         => [243, 251, 254],
    ],

    // 内置场景（新增登录端请在此登记）
    // group: login | member | form | reserved
    // show_in_admin: false 时不出现在后台配置页
    // wired: true 表示内核已调用 CaptchaService::forScene(id)
    'scenes' => [
        'admin' => [
            'label'          => '后台管理登录',
            'group'          => 'login',
            'wired'          => true,
            'show_in_admin'  => true,
            'enabled'        => null,
            'bind_client_ip' => true,
        ],
        'home' => [
            'label'          => '前台会员登录',
            'group'          => 'login',
            'wired'          => true,
            'show_in_admin'  => true,
            'enabled'        => null,
            'bind_client_ip' => false,
        ],
        'contact' => [
            'label'          => '联系表单 / 留言',
            'group'          => 'form',
            'wired'          => true,
            'show_in_admin'  => true,
            'enabled'        => true,
            'bind_client_ip' => false,
        ],
        'register' => [
            'label'          => '会员注册',
            'group'          => 'member',
            'wired'          => false,
            'show_in_admin'  => true,
            'hint'           => '注册流程接入后可生效',
            'enabled'        => null,
            'bind_client_ip' => false,
        ],
        'reset_password' => [
            'label'          => '找回密码',
            'group'          => 'member',
            'wired'          => false,
            'show_in_admin'  => true,
            'hint'           => '找回密码流程接入后可生效',
            'enabled'        => null,
            'bind_client_ip' => false,
        ],
        'dealer' => [
            'label'          => '经销商登录',
            'group'          => 'reserved',
            'wired'          => false,
            'show_in_admin'  => false,
            'enabled'        => null,
            'bind_client_ip' => false,
        ],
        'oa' => [
            'label'          => 'OA 办公登录',
            'group'          => 'reserved',
            'wired'          => false,
            'show_in_admin'  => false,
            'enabled'        => null,
            'bind_client_ip' => false,
        ],
    ],

    // 未在 scenes 登记但符合以下正则的场景亦允许（插件、OA 子模块等）
    'scene_patterns' => [
        '/^plugin\.[a-z][a-z0-9_-]{1,31}$/',
        '/^oa\.[a-z][a-z0-9_-]{1,31}$/',
    ],
];
