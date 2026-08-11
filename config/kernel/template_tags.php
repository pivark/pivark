<?php
/**
 * 内核模板标签名 SSOT（registerKernelTag）
 *
 * 同步：TemplateEngineState::kernelDetectTagNames()
 *       docs/07-模板标签/标签引擎内核冻结清单.md §A2
 */
return [
    /** detect / 文档全量名单 */
    'tags' => [
        'member',
        'form',
        'form_open',
        'form_field',
        'form_close',
        'favorite',
        'contact',
        'contacts',
        'floatcontact',
        'frontassets',
        'item',
        'items',
        'products',
        'product',
        'product_related',
        'product_accessories',
        'products_compare',
    ],
    /** 每请求 boot 必注册（FavoriteService / ProductService 门控除外） */
    'always_boot' => [
        'member',
        'form',
        'form_open',
        'form_field',
        'form_close',
        'contact',
        'contacts',
        'floatcontact',
        'frontassets',
        'item',
        'items',
        'products',
        'product_related',
        'product_accessories',
    ],
    /** 条件注册：未满足时 stripRemainingPvTags 剥除即可 */
    'conditional_boot' => [
        'favorite_open' => ['favorite'],
        'product_center_open' => ['product', 'products_compare'],
    ],
];
