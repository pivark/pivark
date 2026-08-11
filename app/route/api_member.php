<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

// app/route/api_member.php — 会员 REST（须在 api/v1 组内 require → /api/v1/member/*）
use think\facade\Route;

$dispatchConfig = require dirname(__DIR__, 2) . '/config/member_rest_dispatch.php';
$v1Direct       = is_array($dispatchConfig['v1_direct'] ?? null) ? $dispatchConfig['v1_direct'] : [];
$dispatchRoutes = is_array($dispatchConfig['routes'] ?? null) ? $dispatchConfig['routes'] : [];

Route::group('member', function () use ($v1Direct, $dispatchRoutes) {
    foreach ($v1Direct as $route) {
        if (!is_array($route)) {
            continue;
        }
        $method  = strtolower(trim((string) ($route['method'] ?? '')));
        $path    = trim((string) ($route['path'] ?? ''), '/');
        $handler = trim((string) ($route['handler'] ?? ''));
        if ($method === '' || $path === '' || $handler === '') {
            continue;
        }

        $rule = Route::rule($path, $handler, $method);
        if (!str_contains($path, ':')) {
            $rule->completeMatch(true);
        }
        if (!empty($route['pattern']) && is_array($route['pattern'])) {
            $rule->pattern($route['pattern']);
        }
        $rule->name('member_v1_' . str_replace(['/', '-', ':'], '_', $path));
    }

    foreach ($dispatchRoutes as $route) {
        if (!is_array($route)) {
            continue;
        }
        $method = strtolower(trim((string) ($route['method'] ?? '')));
        $path   = trim((string) ($route['path'] ?? ''), '/');
        if ($method === '' || $path === '') {
            continue;
        }

        $rule = Route::rule($path, 'member\Gateway@dispatch', $method);
        if (!str_contains($path, ':')) {
            $rule->completeMatch(true);
        }
        if (!empty($route['pattern']) && is_array($route['pattern'])) {
            $rule->pattern($route['pattern']);
        }
        $rule->name('member_rest_' . str_replace(['/', '-', ':'], '_', $path));
    }
});
