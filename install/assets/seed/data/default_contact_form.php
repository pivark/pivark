<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 元舟 PivArk — 默认「在线留言」表单 fixture（SSOT · 仅种子导入/安装，运行时不在代码里写死）
 */
declare(strict_types=1);

return [
    'slug'   => 'contact',
    'title'  => '在线留言',
    'status' => 1,
    'sort'   => 0,
    'fields' => [
        ['key' => 'name', 'label' => '您的姓名', 'type' => 'text', 'required' => true],
        ['key' => 'company', 'label' => '公司名称', 'type' => 'text', 'required' => false],
        ['key' => 'phone', 'label' => '联系电话', 'type' => 'tel', 'required' => true],
        ['key' => 'email', 'label' => '电子邮箱', 'type' => 'email', 'required' => false],
        [
            'key'      => 'inquiry_type',
            'label'    => '咨询类型',
            'type'     => 'select',
            'required' => false,
            'options'  => ['产品选型咨询', '方案与报价', '现场校准/售后', '渠道合作', '其它'],
        ],
        ['key' => 'product_interest', 'label' => '感兴趣产品/型号', 'type' => 'text', 'required' => false],
        ['key' => 'message', 'label' => '留言内容', 'type' => 'textarea', 'required' => true],
    ],
    'settings' => [
        'captcha'     => false,
        'success_msg' => '提交成功，应用工程师将在 1 个工作日内与您联系。',
        'invoke_mode' => 'tag',
    ],
];
