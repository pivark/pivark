<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 *
 * @return array<string, mixed>
 */
declare(strict_types=1);

return [
    'sellable_codes' => [
        'HY-810',
        'HY-820',
        'HY-900',
        'HY-630',
        'SP-DIAPHRAGM',
        'SP-RS485-KIT',
        'SVC-EMS',
    ],
    'related_docs' => [
        [
            'product_html' => 'pv-demo-product-01',
            'related_html' => ['pv-demo-news-001', 'pv-demo-news-002', 'pv-demo-gallery-01', 'pv-demo-video-01'],
        ],
        [
            'product_html' => 'pv-demo-product-02',
            'related_html' => ['pv-demo-news-003', 'pv-demo-gallery-02'],
        ],
        [
            'product_html' => 'pv-demo-product-03',
            'related_html' => ['pv-demo-news-005', 'pv-demo-video-02', 'pv-demo-gallery-03'],
        ],
        [
            'product_html' => 'pv-demo-product-05',
            'related_html' => ['pv-demo-news-008', 'pv-demo-gallery-05'],
        ],
        [
            'product_html' => 'pv-demo-product-07',
            'related_html' => ['pv-demo-news-010', 'pv-demo-news-011'],
        ],
        [
            'product_html' => 'pv-demo-product-09',
            'related_html' => ['pv-demo-news-012', 'pv-demo-gallery-06'],
        ],
        [
            'product_html' => 'pv-demo-product-13',
            'related_html' => ['pv-demo-news-004', 'pv-demo-gallery-04'],
        ],
        [
            'product_html' => 'pv-demo-product-15',
            'related_html' => ['pv-demo-news-006', 'pv-demo-video-03'],
        ],
    ],
    'variants' => [
        [
            'item_code' => 'HY-810',
            'extra' => [
                [
                    'variant_code' => 'HY-810',
                    'spec_label'   => '0~1.6 MPa · HART',
                    'spec_map'     => ['range' => '0~1.6 MPa', 'output_signal' => 'HART'],
                    'is_default'   => true,
                    'sort'         => 0,
                ],
                [
                    'variant_code' => 'HY-810-10',
                    'spec_label'   => '0~10 MPa · HART',
                    'spec_map'     => ['range' => '0~10 MPa', 'output_signal' => 'HART'],
                    'sort'         => 1,
                ],
                [
                    'variant_code' => 'HY-810-MA',
                    'spec_label'   => '0~1.6 MPa · 4~20 mA',
                    'spec_map'     => ['range' => '0~1.6 MPa', 'output_signal' => '4~20 mA'],
                    'sort'         => 2,
                ],
            ],
        ],
        [
            'item_code' => 'HY-900',
            'extra' => [
                [
                    'variant_code' => 'HY-900-12',
                    'spec_label'   => '12 路无纸记录仪',
                    'spec_map'     => ['channels' => '12'],
                    'is_default'   => true,
                    'sort'         => 0,
                ],
                [
                    'variant_code' => 'HY-900-24',
                    'spec_label'   => '24 路无纸记录仪',
                    'spec_map'     => ['channels' => '24'],
                    'sort'         => 1,
                ],
                [
                    'variant_code' => 'HY-900-48',
                    'spec_label'   => '48 路无纸记录仪',
                    'spec_map'     => ['channels' => '48'],
                    'sort'         => 2,
                ],
            ],
        ],
        [
            'item_code' => 'HY-820',
            'extra' => [
                [
                    'variant_code' => 'HY-820-STD',
                    'spec_label'   => '标准量程 · HART',
                    'spec_map'     => ['range' => '标准', 'output_signal' => 'HART'],
                    'is_default'   => true,
                    'sort'         => 0,
                ],
                [
                    'variant_code' => 'HY-820-HP',
                    'spec_label'   => '高压量程 · HART',
                    'spec_map'     => ['range' => '高压', 'output_signal' => 'HART'],
                    'sort'         => 1,
                ],
            ],
        ],
        [
            'item_code' => 'SVC-EMS',
            'extra' => [
                [
                    'variant_code' => 'SVC-EMS-1Y',
                    'spec_label'   => '平台授权 · 1 年',
                    'spec_map'     => ['license' => '1年'],
                    'is_default'   => true,
                    'sort'         => 0,
                ],
                [
                    'variant_code' => 'SVC-EMS-3Y',
                    'spec_label'   => '平台授权 · 3 年',
                    'spec_map'     => ['license' => '3年'],
                    'sort'         => 1,
                ],
            ],
        ],
    ],
    'shop' => [
        [
            'item_code' => 'HY-810',
            'spec_defs' => [
                ['name' => '量程', 'options' => ['0~1.6 MPa', '0~10 MPa'], 'sort' => 0],
                ['name' => '输出', 'options' => ['HART', '4~20 mA'], 'sort' => 1],
            ],
            'skus' => [
                [
                    'sku_code'   => 'HY-810-16-H',
                    'spec_label' => '0~1.6 MPa / HART',
                    'spec_map'   => ['量程' => '0~1.6 MPa', '输出' => 'HART'],
                    'price'      => 2899.00,
                    'stock'      => 48,
                    'sort'       => 0,
                ],
                [
                    'sku_code'   => 'HY-810-10-H',
                    'spec_label' => '0~10 MPa / HART',
                    'spec_map'   => ['量程' => '0~10 MPa', '输出' => 'HART'],
                    'price'      => 3299.00,
                    'stock'      => 32,
                    'sort'       => 1,
                ],
                [
                    'sku_code'   => 'HY-810-16-M',
                    'spec_label' => '0~1.6 MPa / 4~20 mA',
                    'spec_map'   => ['量程' => '0~1.6 MPa', '输出' => '4~20 mA'],
                    'price'      => 2599.00,
                    'stock'      => 60,
                    'sort'       => 2,
                ],
            ],
        ],
        [
            'item_code' => 'HY-900',
            'spec_defs' => [
                ['name' => '通道数', 'options' => ['12 路', '24 路', '48 路'], 'sort' => 0],
            ],
            'skus' => [
                [
                    'sku_code'   => 'HY-900-12',
                    'spec_label' => '12 路',
                    'spec_map'   => ['通道数' => '12 路'],
                    'price'      => 12800.00,
                    'stock'      => 8,
                    'sort'       => 0,
                ],
                [
                    'sku_code'   => 'HY-900-24',
                    'spec_label' => '24 路',
                    'spec_map'   => ['通道数' => '24 路'],
                    'price'      => 18600.00,
                    'stock'      => 5,
                    'sort'       => 1,
                ],
                [
                    'sku_code'   => 'HY-900-48',
                    'spec_label' => '48 路',
                    'spec_map'   => ['通道数' => '48 路'],
                    'price'      => 26800.00,
                    'stock'      => 3,
                    'sort'       => 2,
                ],
            ],
        ],
        [
            'item_code' => 'HY-820',
            'spec_defs' => [
                ['name' => '量程', 'options' => ['标准', '高压'], 'sort' => 0],
            ],
            'skus' => [
                [
                    'sku_code'   => 'HY-820-STD',
                    'spec_label' => '标准量程',
                    'spec_map'   => ['量程' => '标准'],
                    'price'      => 3199.00,
                    'stock'      => 20,
                    'sort'       => 0,
                ],
                [
                    'sku_code'   => 'HY-820-HP',
                    'spec_label' => '高压量程',
                    'spec_map'   => ['量程' => '高压'],
                    'price'      => 3899.00,
                    'stock'      => 12,
                    'sort'       => 1,
                ],
            ],
        ],
        [
            'item_code' => 'SP-DIAPHRAGM',
            'skus' => [
                [
                    'sku_code'   => 'SP-DIAPHRAGM',
                    'spec_label' => '膜片备件套装',
                    'price'      => 399.00,
                    'stock'      => 200,
                    'sort'       => 0,
                ],
            ],
        ],
        [
            'item_code' => 'SP-RS485-KIT',
            'skus' => [
                [
                    'sku_code'   => 'SP-RS485-KIT',
                    'spec_label' => 'RS485 通信套件',
                    'price'      => 199.00,
                    'stock'      => 150,
                    'sort'       => 0,
                ],
            ],
        ],
        [
            'item_code' => 'SVC-EMS',
            'spec_defs' => [
                ['name' => '授权年限', 'options' => ['1 年', '3 年'], 'sort' => 0],
            ],
            'skus' => [
                [
                    'sku_code'   => 'SVC-EMS-1Y',
                    'spec_label' => '1 年授权',
                    'spec_map'   => ['授权年限' => '1 年'],
                    'price'      => 9800.00,
                    'stock'      => -1,
                    'sort'       => 0,
                ],
                [
                    'sku_code'   => 'SVC-EMS-3Y',
                    'spec_label' => '3 年授权',
                    'spec_map'   => ['授权年限' => '3 年'],
                    'price'      => 25800.00,
                    'stock'      => -1,
                    'sort'       => 1,
                ],
            ],
        ],
    ],
];
