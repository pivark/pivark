<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
// app/middleware.php — 应用全局中间件
return [
    \app\common\middleware\PluginEmergencyBypassMiddleware::class,
    \app\common\middleware\PivarkSessionInit::class,
    \app\common\middleware\AdminEntryAliasMiddleware::class,
    \app\common\middleware\SiteDomainBootstrap::class,
    \app\common\middleware\ForceHttpsMiddleware::class,
    \app\common\middleware\AdminHttpsRequiredMiddleware::class,
    \app\common\middleware\IpAccessMiddleware::class,
    \app\common\middleware\FrontCcMiddleware::class,
    \app\common\middleware\PluginBootstrap::class,
    \app\common\middleware\ResponseGzipMiddleware::class,
    \app\common\middleware\HeadResponseMiddleware::class,
];
