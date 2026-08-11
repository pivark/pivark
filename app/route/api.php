<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare (strict_types = 1);


// app/route/api.php — 无头 API（/api/v1）
use think\facade\Route;

Route::group('api/v1', function () {
    Route::get('documents', 'Document@index')->completeMatch();
    Route::get('documents/:id', 'Document@read')->pattern(['id' => '[^/]+']);
    Route::get('items/compare', 'Item@compare');
    Route::get('catalog', 'Catalog@index')->completeMatch();
    Route::get('catalog/:domain', 'Catalog@query')->pattern(['domain' => '[a-z0-9_]+']);
    Route::get('items/:slug', 'Item@read')->pattern(['slug' => '[^/]+']);
    Route::get('members', 'Member@index')->completeMatch();
    Route::get('members/:id', 'Member@read')->pattern(['id' => '\\d+']);
    Route::get('tags', 'Tag@index')->completeMatch();
    Route::get('tags/:slug/documents', 'Tag@documents');
    Route::get('search/smart', 'SmartSearch@query');
    Route::get('search/suggest', 'SmartSearch@suggest');
    Route::post('search/click', 'SmartSearch@click');
    Route::post('knowledge-search', 'KnowledgeSearch@search');
    Route::get('config/site', 'SiteConfig@site');
    Route::get('updates/check', 'Updates@check');
    Route::post('license/activate', 'License@activate');
    Route::post('license/heartbeat', 'License@heartbeat');
    Route::post('license/sync', 'License@sync');
    Route::get('license/status', 'License@status');
    
    Route::get('config/miniprogram/bootstrap', 'MiniprogramConfig@bootstrap');
    Route::get('config/miniprogram/page/home', 'MiniprogramPage@home');
    Route::get('config/miniprogram/page/tags', 'MiniprogramPage@tags');
    Route::get('config/miniprogram/page/products', 'MiniprogramPage@products');
    Route::get('config/miniprogram/page/mine', 'MiniprogramPage@mine');
    Route::get('config/miniprogram/slides', 'MiniprogramPage@slides');
    Route::get('system/cron-tick', 'CronTick@tick');
    Route::get('health', 'Health@index');
    Route::get('site/fingerprint', 'SiteFingerprint@index');
    Route::post('system/csp-report', 'CspReport@store');

    Route::post('stats/beacon', 'Stats@beacon');
    Route::get('forms/:slug', 'Form@schema')->pattern(['slug' => '[^/]+']);
    Route::post('forms/submit', 'Form@submit');

    Route::get('favorite/stats/:document_id', 'Favorite@stats')->pattern(['document_id' => '\\d+']);
    Route::post('favorite/like', 'Favorite@like');
    Route::post('favorite/collect', 'Favorite@collect');

    // 上传与素材（骨架：鉴权见 UploadGate；规则见 UploadService）
    Route::get('upload/policy', 'Upload@policy');
    Route::post('upload/oss-callback', 'Upload@ossCallback');
    Route::post('upload', 'Upload@store');
    Route::get('media', 'Media@index');

    if (is_file(__DIR__ . '/api_member.php')) {
        require __DIR__ . '/api_member.php';
    }
    if (is_file(__DIR__ . '/api_admin.php')) {
        require __DIR__ . '/api_admin.php';
    }
})->namespace('app\api\controller\v1')
    ->middleware(\app\api\middleware\ApiCorsMiddleware::class)
    ->middleware(\app\api\middleware\ApiRateLimit::class)
    ->middleware(\app\api\middleware\ApiMemberWriteGuard::class)
    ->middleware(\app\common\middleware\FrontPostGuard::class);
