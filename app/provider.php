<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 容器显式绑定（仅 ThinkPHP 无法自动推断的项）。
 *
 * Service / *Deps / *Gateway：TP8 按构造函数类型自动解析，无需 Class=>Class 台账。
 * 迁移清单：config/di/waves.php
 */
return [
    'think\exception\Handle' => \app\common\exception\ExceptionHandler::class,
    // Facade Route → 'route'；GET 同时认 HEAD（见 PivarkRoute）
    'route'                  => \app\common\route\PivarkRoute::class,
];
