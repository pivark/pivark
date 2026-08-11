<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

// app/route/api_admin.php — 后台 REST（须在 api/v1 组内 require → /api/v1/admin/*）
use think\facade\Route;

$dispatchConfig = require dirname(__DIR__, 2) . '/config/admin_rest/dispatch.php';
$dispatchRoutes = is_array($dispatchConfig['routes'] ?? null) ? $dispatchConfig['routes'] : [];

/** @var list<array{method:string,path:string,note?:string}> $thinkphpLeafRoutes */
$thinkphpLeafRoutes = require dirname(__DIR__, 2) . '/config/admin_rest/thinkphp_leaf_routes.php';
if (!is_array($thinkphpLeafRoutes)) {
    $thinkphpLeafRoutes = [];
}
$thinkphpLeafKeys = [];
foreach ($thinkphpLeafRoutes as $leaf) {
    if (!is_array($leaf)) {
        continue;
    }
    $method = strtoupper(trim((string) ($leaf['method'] ?? '')));
    $path   = trim(str_replace('\\', '/', (string) ($leaf['path'] ?? '')), '/');
    if ($method === '' || $path === '') {
        continue;
    }
    $thinkphpLeafKeys[] = $method . ':' . $path;
}

Route::group('admin', function () use ($dispatchRoutes, $thinkphpLeafKeys, $thinkphpLeafRoutes) {
    foreach ($dispatchRoutes as $route) {
        if (!is_array($route)) {
            continue;
        }
        $method = strtolower(trim((string) ($route['method'] ?? '')));
        $path   = trim((string) ($route['path'] ?? ''), '/');
        if ($method === '' || $path === '') {
            continue;
        }
        $leafKey = strtoupper($method) . ':' . $path;
        if (in_array($leafKey, $thinkphpLeafKeys, true)) {
            continue;
        }

        $rule = Route::rule($path, 'admin\Gateway@dispatch', $method)->completeMatch();
        if (!empty($route['pattern']) && is_array($route['pattern'])) {
            $rule->pattern($route['pattern']);
        }
        $rule->name('admin_rest_' . strtoupper($method) . '_' . str_replace(['/', '-', ':'], '_', $path));
    }

    foreach ($thinkphpLeafRoutes as $leaf) {
        if (!is_array($leaf)) {
            continue;
        }
        $method = strtolower(trim((string) ($leaf['method'] ?? '')));
        $path   = trim(str_replace('\\', '/', (string) ($leaf['path'] ?? '')), '/');
        if ($method === '' || $path === '') {
            continue;
        }
        if (($leaf['http_bind'] ?? true) === false) {
            continue;
        }
        Route::rule($path, 'admin\Gateway@dispatch', $method)
            ->completeMatch()
            ->name(
                'admin_rest_'
                . strtoupper($method)
                . '_'
                . str_replace(['/', '-', ':'], '_', $path)
                . '_leaf'
            );
    }
})->middleware([
    \app\api\middleware\AdminApiAuthCheck::class,
    \app\api\middleware\AdminApiCsrfCheck::class,
    \app\admin\middleware\IdempotencyCheck::class,
    \app\admin\middleware\SensitiveConfirmCheck::class,
]);
