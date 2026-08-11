<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 *
 * 后台布局：内容站 / 企业应用（企业应用在对应插件启用后切换）
 * 经营域分类见 config/plugin/taxonomy.php
 */
declare(strict_types=1);

return [
    'profiles' => [
        'content' => [
            'content_menu_id'     => 10,
            'content_menu_title'  => '内容发布',
            'market_commerce_group' => [
                'title'   => '商城',
                'icon'    => 'fa fa-shopping-cart',
                'sort'    => 28,
            ],
        ],
        'enterprise' => [
            'content_menu_id'     => 10,
            'content_menu_title'  => '数字资产',
            'enterprise_group'    => [
                'menu_id' => 960,
                'title'   => '企业经营',
                'icon'    => 'fa fa-building',
                'sort'    => 12,
            ],
            'market_commerce_group' => [
                'menu_id' => 966,
                'title'   => '市场与成交',
                'icon'    => 'fa fa-rocket',
                'sort'    => 11,
            ],
            'default_home' => [
                'oa'       => '/weapp/oa/workbench',
                'fallback' => '/dashboard/welcome',
            ],
        ],
    ],

    /**
     * 触发企业应用布局的插件标识（经营域应用；商城/营销类插件单独启用不改变整站布局）
     *
     * @var list<string>
     */
    'layout_triggers' => [
        'oa',
        'crm',
        'erp',
        'plm',
        'mes',
    ],

    /**
     * E 域 · 侧栏「企业经营」(960)
     *
     * @var array<string, array{title:string,route:string,permission:string,sort:int,icon?:string}>
     */
    'enterprise_apps' => [
        'oa' => [
            'title'      => '协同办公',
            'route'      => '/weapp/host/oa/index',
            'permission' => 'plugin.oa.use',
            'sort'       => 1,
            'icon'       => 'fa fa-sitemap',
        ],
        'crm' => [
            'title'      => '客户关系',
            'route'      => '/weapp/host/crm/index',
            'permission' => 'plugin.crm.use',
            'sort'       => 2,
            'icon'       => 'fa fa-handshake-o',
        ],
        'erp' => [
            'title'      => '进销存',
            'route'      => '/weapp/host/erp/index',
            'permission' => 'plugin.erp.use',
            'sort'       => 3,
            'icon'       => 'fa fa-cubes',
        ],
        'plm' => [
            'title'      => '研发 PLM',
            'route'      => '/weapp/host/plm/index',
            'permission' => 'plugin.plm.use',
            'sort'       => 4,
            'icon'       => 'fa fa-pencil-square-o',
        ],
        'mes' => [
            'title'      => '制造执行',
            'route'      => '/weapp/host/mes/index',
            'permission' => 'plugin.mes.use',
            'sort'       => 5,
            'icon'       => 'fa fa-industry',
        ],
    ],

    /**
     * M/C/X 域 · 侧栏「市场与成交」(966)
     *
     * @var array<string, array{title:string,route:string,permission:string,sort:int,icon?:string}>
     */
    'market_commerce_apps' => [
        'geo' => [
            'title'      => 'GEO 优化',
            'route'      => '/weapp/host/geo/index',
            'permission' => 'plugin.geo.use',
            'sort'       => 1,
            'icon'       => 'fa fa-globe',
        ],
        'shop' => [
            'title'          => '商城',
            'route'          => '/product/shop-config',
            'route_gate'     => 'product_center',
            'route_fallback' => '/weapp/host/shop/shop-config',
            'permission'     => 'plugin.shop.use',
            'sort'           => 2,
            'icon'           => 'fa fa-shopping-cart',
        ],
        'tender' => [
            'title'      => 'AI 标书',
            'route'      => '/weapp/host/tender/settings',
            'permission' => 'plugin.tender.use',
            'sort'       => 10,
            'icon'       => 'fa fa-file-text-o',
        ],
    ],
];
