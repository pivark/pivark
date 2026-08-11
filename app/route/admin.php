<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
// app/route/admin.php — 后台 HTML 壳 only（JSON → /api/v1/admin/*）
use think\facade\Route;

Route::group('admin', function () {

    Route::get('/', 'Index@index');
    Route::get('index', 'Index@index');
    Route::get('login', 'Login@index');
    Route::get('login/index', 'Login@index');
    // login/captcha|status|do_login|logout → config/admin/retired_api_paths.php
    Route::get('index/index', 'Index@index');
    Route::get('index/welcome', 'Index@welcome');

    Route::get('debug/captcha', 'Debug@captcha');

    Route::get('product/item', 'Index@spaFallback');

    \app\common\service\plugin\extension\HostRuntimeProbe::registerLoadTimeAdminRoutes();

    Route::get('<spaPath>', 'Index@spaFallback')->pattern(['spaPath' => '[\w\.\-\/]+']);

})->namespace('app\admin\controller')->middleware([
    \app\admin\middleware\RetiredAdminApiPathCheck::class,
    \app\admin\middleware\AuthCheck::class,
    \app\admin\middleware\CsrfCheck::class,
    \app\admin\middleware\IdempotencyCheck::class,
    \app\admin\middleware\SensitiveConfirmCheck::class,
]);
