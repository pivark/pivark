<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 *
 * 后台侧栏受众身份接口（CMS 默认；企业/经营域扩展由 pack 与插件按需合并）。
 */
declare(strict_types=1);

return [
    'version' => '2.0',
    'mode'    => 'interface_only',

    /**
     * @var array<string, array{label:string,description:string}>
     */
    'personas' => [
        'system_admin' => [
            'label'       => '系统管理员',
            'description' => '运维、权限、插件与系统配置',
        ],
        'content_ops' => [
            'label'       => '内容运营',
            'description' => '数字资产与站点内容维护',
        ],
    ],

    /** @var list<string> */
    'resolve_priority' => [
        'system_admin',
        'content_ops',
    ],

    /**
     * @var array<string, array<string, mixed>>
     */
    'resolve' => [
        'system_admin' => [
            'super_admin'     => true,
            'role_codes'      => ['super_admin', 'admin'],
            'any_permissions' => [
                'admin.config',
                'admin.role',
                'admin.user',
                'admin.plugin.manage',
            ],
        ],
        'content_ops' => [
            'role_codes'      => ['content_editor', 'website_ops', 'editor'],
            'any_permissions' => [
                'admin.document.list',
                'admin.document.create',
                'admin.document.edit',
                'admin.site',
                'admin.media.list',
                'admin.item.list',
            ],
        ],
    ],

    /**
     * @var array<string, int>
     */
    'dynamic_group_roots' => [
        'enterprise'      => -96000,
        'market_commerce' => -96600,
    ],

    /**
     * @var array<string, array<string, mixed>>
     */
    'rules' => [
        'content_ops' => [
            'hide_menu_ids' => [
                2, 5, 6, 19, 3, 4,
                20, 15, 17, 29, 71, 73, 91, 95,
            ],
            'hide_route_prefixes' => [
                '/admin/user/',
                '/admin/role/',
                '/admin/config/',
            ],
        ],
    ],

    /**
     * @var array<string, array<string, bool>>
     */
    'document_editor' => [
        'system_admin' => [
            'show_seo_tab'           => true,
            'show_more_tab'           => true,
            'show_external_surfaces'  => true,
            'show_item_picker'        => true,
        ],
        'content_ops' => [
            'show_seo_tab'           => true,
            'show_more_tab'           => true,
            'show_external_surfaces'  => true,
            'show_item_picker'        => true,
        ],
    ],

    /**
     * @var array<string, array<string, mixed>>
     */
    'item_admin' => [
        'system_admin' => [
            'page_title'        => '产品中心',
            'page_desc'         => '维护品项型号、规格与状态；前台按渠道规则展示。',
            'list_columns'      => ['id', 'code', 'name', 'slug', 'cover', 'tags', 'www_visible', 'variant_count', 'marketplace_price', 'offer_shelf', 'offer_stock', 'offer_price', 'item_type', 'status', 'sort'],
            'form_fields'       => ['code', 'name', 'slug', 'cover', 'item_type', 'status', 'sort', 'tags', 'primary_document', 'flags', 'attrs'],
            'capability_flags'  => ['sellable', 'purchasable', 'manufacturable', 'web_visible'],
            'ui_modules_keep'   => null,
            'read_only_form'    => false,
            'field_hints_style' => 'default',
        ],
        'content_ops' => [
            'page_title'        => '产品管理',
            'page_desc'         => '维护对外展示的型号、封面与关联文档。',
            'list_columns'      => ['id', 'code', 'name', 'slug', 'cover', 'tags', 'www_visible', 'marketplace_price', 'offer_shelf', 'offer_stock', 'offer_price', 'item_type', 'status', 'sort'],
            'form_fields'       => ['code', 'name', 'slug', 'cover', 'item_type', 'status', 'sort', 'tags', 'primary_document', 'flag_sellable', 'flag_web_visible', 'attrs'],
            'capability_flags'  => ['sellable', 'web_visible'],
            'ui_modules_keep'   => ['itemList', 'variantPanel', 'variantCountColumn', 'paramAttrs', 'marketplaceListingPrice'],
            'read_only_form'    => false,
            'field_hints_style' => 'default',
        ],
    ],

    /** @var array<string, array<string, array<string, string>>> */
    'item_field_hint_styles' => [],
];
