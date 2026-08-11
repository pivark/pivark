<?php

/**
 * 插件 identifier 命名黑名单 SSOT（weapp/{id}/ · plugin.json identifier）
 *
 * 校验入口：PluginReservedIdentifierService（脚手架/上传/安装/工作台/SPA 预检）
 * 文档：docs/06-插件/插件市场与命名规范.md §3.3
 *
 * tier kernel 由本文件 + config/kernel/l1_modules.php + pivark.core_merged_identifiers 运行时合并
 */
return [
    /** 门禁：合并后黑名单总数下限（防 SSOT 被误删） */
    'min_count' => 80,

    'tier_labels' => [
        'kernel'          => '系统内核/L1 模块',
        'platform_module' => '平台模块（manifest needs）',
        'route'           => '系统路由/URL 保留',
        'legacy'          => '历史废弃名',
        'meta'            => '框架元词语',
        'official_weapp'  => '官方发行插件标识',
    ],

    /**
     * L1 Platform Module（config/kernel/modules.php 键；与 kernel tier 部分重叠，单独标注 needs 语义）
     *
     * @var list<string>
     */
    'platform_module' => [
        'analytics',
        'enterprise_resource',
        'event_bus',
        'insights',
        'items',
        'organization',
        'payment',
    ],

    /**
     * 前台 URL、后台 SPA/API 路由段、站点入口别名等
     *
     * @var list<string>
     */
    'route' => [
        'about',
        'admin',
        'ads',
        'api',
        'articles',
        'backup',
        'captchaconfig',
        'catalog',
        'captcha',
        'channelsconfig',
        'config',
        'contact',
        'content',
        'cron',
        'dashboard',
        'debug',
        'docs',
        'document',
        'documents',
        'domain',
        'health',
        'home',
        'index',
        'inquiry',
        'install',
        'license',
        'link',
        'log',
        'login',
        'mail',
        'mailconfig',
        'media',
        'member-publish',
        'member_center',
        'member_level',
        'menu',
        'miniprogram',
        'miniprogramconfig',
        'nav',
        'page',
        'pages',
        'pay',
        'paymentconfig',
        'portal',
        'products',
        'profile',
        'public',
        'robots',
        'role',
        'searchconfig',
        'searchquerylog',
        'seo',
        'site',
        'sms',
        'smsconfig',
        'spa',
        'static',
        'system',
        'tag',
        'tags',
        'template',
        'upload',
        'uploads',
        'user',
    ],

    /**
     * 整改已废弃或官方已改名的 identifier（禁止第三方复用）
     *
     * @var list<string>
     */
    'legacy' => [
        'ai_document',
        'comment',
        'download',
        'gallery',
        'item',
        'siteform',
        'smart_search',
        'thumb',
        'video',
    ],

    /**
     * 与插件框架/分发体系同名的元词语
     *
     * @var list<string>
     */
    'meta' => [
        'core',
        'host',
        'kernel',
        'pivark',
        'platform-host',
        'plugin',
        'vendor',
        'weapp',
    ],
];
