<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

use think\facade\Route;

Route::group('install', function () {
    Route::get('/', 'Index@index');
    Route::get('index', 'Index@index');
    Route::get('check', 'Index@check');
    Route::get('getConfig', 'Index@getConfig');
    Route::get('resolveExternalLinks', 'Index@resolveExternalLinks');
    Route::post('testDb', 'Index@testDb');
    Route::post('runStep', 'Index@runStep');
    Route::post('run', 'Index@run');
})->namespace('install\controller')
    ->middleware(\app\common\middleware\InstallWizardGuardMiddleware::class);
