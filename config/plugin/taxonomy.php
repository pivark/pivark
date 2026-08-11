<?php
/**
 * 元舟 PivArk — 产品分层与经营域 SSOT（代码侧）
 *
 * 三轴：架构 kind（L0/L1/L2）· 商业 commercial_tier（B0/B2/B3/B4）· 经营域 business_domain（E/M/C/X/—）
 * 文档 SSOT：docs/06-插件/产品分层与经营域.md
 */
declare(strict_types=1);

return [
    'commercial_tiers' => [
        'B0' => ['label' => '内核', 'desc' => 'L0 Core · 非插件'],
        'B2' => ['label' => 'Community 文档增强', 'desc' => 'document-addon · 文档前台增强'],
        'B3' => ['label' => 'Platform 通道', 'desc' => 'platform · 支付/登录/小程序等'],
        'B4' => ['label' => 'Commercial 经营应用', 'desc' => 'application · 买断/订阅'],
    ],

    'business_domains' => [
        'E' => ['label' => '内经营', 'desc' => 'Enterprise 五件套 · 侧栏 960'],
        'M' => ['label' => '外经营·市场', 'desc' => 'GEO/品项对外 · 侧栏 966'],
        'C' => ['label' => '成交/触点', 'desc' => '商城/小程序 · 侧栏 966'],
        'X' => ['label' => '贯通', 'desc' => '内资料→对外交付 · 侧栏 966'],
        /** 前台/市场卡片勿展示此域标签（与 kind「文档扩展」重复）；筛选可保留 */
        '-' => ['label' => '文档增强', 'desc' => 'B2 addon · 不进经营侧栏（文档图集/视频等）'],
    ],

    /** kind 默认值（registry 未声明时） */
    'kind_defaults' => [
        'document-addon' => [
            'commercial_tier'  => 'B2',
            'business_domain'  => '-',
            'editor_audience'  => 'external',
            'nav_bucket'       => 'none',
        ],
        'platform' => [
            'commercial_tier'  => 'B3',
            'business_domain'  => null,
            'editor_audience'  => 'none',
            'nav_bucket'       => 'none',
        ],
        'application' => [
            'commercial_tier'  => 'B4',
            'business_domain'  => null,
            'editor_audience'  => 'none',
            'nav_bucket'       => 'market',
        ],
    ],

    /**
     * 内核中枢 registry（官方 weapp 插件改 plugin.json pivark_policy.taxonomy）
     *
     * nav_bucket: enterprise | market | none
     * layout_trigger: 是否触发 NavProfile=enterprise（仅 E 域五件套）
     */
    'plugins' => [
        'product'  => ['commercial_tier' => 'B2', 'business_domain' => 'M', 'editor_audience' => 'external', 'nav_bucket' => 'none'],

        'payment'       => ['commercial_tier' => 'B3', 'business_domain' => 'C', 'editor_audience' => 'none', 'nav_bucket' => 'none'],

        'oa'  => ['commercial_tier' => 'B4', 'business_domain' => 'E', 'editor_audience' => 'none', 'nav_bucket' => 'enterprise', 'layout_trigger' => true],
        'crm' => ['commercial_tier' => 'B4', 'business_domain' => 'E', 'editor_audience' => 'none', 'nav_bucket' => 'enterprise', 'layout_trigger' => true],
        'erp' => ['commercial_tier' => 'B4', 'business_domain' => 'E', 'editor_audience' => 'none', 'nav_bucket' => 'enterprise', 'layout_trigger' => true],
        'plm' => ['commercial_tier' => 'B4', 'business_domain' => 'E', 'editor_audience' => 'none', 'nav_bucket' => 'enterprise', 'layout_trigger' => true],
        'mes' => ['commercial_tier' => 'B4', 'business_domain' => 'E', 'editor_audience' => 'none', 'nav_bucket' => 'enterprise', 'layout_trigger' => true],

        'geo'     => ['commercial_tier' => 'B4', 'business_domain' => 'M', 'editor_audience' => 'none', 'nav_bucket' => 'market'],
    ],
];
