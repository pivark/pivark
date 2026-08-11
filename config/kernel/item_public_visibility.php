<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 *
 * 品项前台渠道可见性（flags.web_visible + 渠道规则）
 */
declare(strict_types=1);

return [
    /** @var list<string> */
    'flag_keys' => [
        'sellable',
        'purchasable',
        'manufacturable',
        'web_visible',
    ],

    /**
     * 渠道规则（listPublic / 品项页 / Sitemap 走 www；后台文档 picker 走 admin）
     *
     * @var array<string, array<string, mixed>>
     */
    'channels' => [
        'www' => [
            'label'                      => '对外展示',
            'require_status'             => ['active'],
            'require_web_visible'        => true,
            'legacy_infer_from_sellable' => true,
            'require_primary_document'   => false,
            'require_slug'               => false,
        ],
        'commerce' => [
            'label'                      => '在线报价',
            'require_status'             => ['active'],
            'require_web_visible'        => true,
            'require_sellable'           => true,
            'legacy_infer_from_sellable' => true,
        ],
        'admin' => [
            'label'                      => '后台文档关联',
            'require_status'             => ['active'],
            'require_web_visible'        => false,
        ],
    ],

    'default_channel' => 'www',

    /** 后台「对外展示」勾选项文案 */
    'admin' => [
        'web_visible' => [
            'label'    => '前台显示',
            'hint'     => '与文档发布类似：开启后可在产品目录与详情页展示；关闭为草稿态。',
            'overview' => '装报价插件后仍可单独控制「可售」；不可售时前台隐藏购买入口。',
        ],
    ],
];
