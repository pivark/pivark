<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare (strict_types = 1);


// app/route/app.php — 前台路由（REST 风格，唯一入口）
use think\facade\Route;

// ====== 后台路由优先加载 ======
if (is_file(__DIR__ . '/admin.php')) {
    require __DIR__ . '/admin.php';
}

if (is_file(__DIR__ . '/api.php')) {
    require __DIR__ . '/api.php';
}

// 安装向导（须在 :path 通配之前注册，否则 /install 会被前台单页路由抢走）
if (is_file(__DIR__ . '/install.php')) {
    require __DIR__ . '/install.php';
}

// 全站验证码（多场景：admin / home / dealer / oa / plugin.*）
Route::get('captcha/<scene>/status', 'app\common\controller\Captcha@status')
    ->pattern(['scene' => '[a-z][a-z0-9._-]+']);
Route::get('captcha/<scene>', 'app\common\controller\Captcha@image')
    ->pattern(['scene' => '[a-z][a-z0-9._-]+']);
Route::get('debug/captcha/<scene>', 'app\common\controller\Captcha@debugPeek')
    ->pattern(['scene' => '[a-z][a-z0-9._-]+']);

// 首页
Route::get('/', 'app\home\controller\Index@index');

// 内容（列表路由须 completeMatch，否则 /documents/194 会误命中 index 而非 view）
Route::get('documents', 'app\home\controller\Document@index')->completeMatch();
Route::get('documents/:key', 'app\home\controller\Document@view')->pattern(['key' => '[a-zA-Z0-9_\-]+(?:\.html)?']);
Route::get('document/qrcode/:id', 'app\home\controller\Document@qrcode')->pattern(['id' => '\d+']);
Route::get('item/qrcode/:id', 'app\home\controller\ProductItem@qrcode')->pattern(['id' => '\d+']);
Route::get('tags', 'app\home\controller\Document@tags')->completeMatch();
Route::get('tags/:slug', 'app\home\controller\Document@tag')->pattern(['slug' => '[^/]+']);
Route::get('search', 'app\home\controller\Search@index')
    ->middleware(\app\common\middleware\SearchRateLimit::class);

// 品项独立页（须在 :path 兜底之前；无 /products 短链）
Route::get('portal', 'app\home\controller\Page@portal')->completeMatch();
// portal / platform 公开路由由发行受限宿主插件 Plugin::boot 注册
Route::get('items/:slug', 'app\home\controller\ProductItem@view')->pattern(['slug' => '[^/]+']);
Route::get('templates', 'app\home\controller\Front@path')->append(['path' => 'templates'])->completeMatch();

// 插件前台 / 会员 / API 路由由 Plugin::boot → PluginRouteService 注册

// SEO（completeMatch 避免 :path 通配抢走 sitemap.html 等）
Route::get('sitemap.xml', 'app\home\controller\Seo@sitemap')->completeMatch();
Route::get('sitemap.txt', 'app\home\controller\Seo@sitemapTxt')->completeMatch();
Route::get('sitemap.html', 'app\home\controller\Seo@sitemapHtml')->completeMatch();
Route::get('llms.txt', 'app\home\controller\Seo@llmsTxt')->completeMatch();
Route::get('ai-sitemap.txt', 'app\home\controller\Seo@aiSitemap')->completeMatch();
Route::get('robots.txt', 'app\home\controller\Seo@robots')->completeMatch();

// 前台会员（GET 与支付回调在组外；POST 写操作防重复提交）
// /member 无子路径时进入口（禁落成栏目 404；宝塔 error_page 会盖成 nginx 壳页）
Route::get('member', 'app\home\controller\Member@entry')->completeMatch();
Route::get('login', 'app\home\controller\Member@login')->completeMatch();
Route::get('register', 'app\home\controller\Member@register')->completeMatch();
Route::get('member/login', 'app\home\controller\Member@login');
Route::get('member/forgot-password', 'app\home\controller\Member@forgotPassword');
Route::get('member/forgot-username', 'app\home\controller\Member@forgotUsername');
Route::get('member/reset-password', 'app\home\controller\Member@resetPassword');
Route::get('member/register', 'app\home\controller\Member@register');
Route::get('member/logout', 'app\home\controller\Member@logoutForm');
Route::get('member/enter-as', 'app\home\controller\Member@enterAsForm');
Route::get('member/center', 'app\home\controller\Member@center');
Route::get('member/profile', 'app\home\controller\Member@profile');
Route::get('member/verify-email', 'app\home\controller\Member@verifyEmail');
Route::get('member/purchases', 'app\home\controller\Member@purchases');
Route::get('member/points', 'app\home\controller\Member@points');
Route::get('member/consumption', 'app\home\controller\Member@consumption');
Route::get('member/balance', 'app\home\controller\Member@balance');
Route::get('member/recharge', 'app\home\controller\Member@recharge');
Route::get('member/pay/return', 'app\home\controller\Member@payReturn');
Route::any('pay/notify/alipay', 'PayNotify@alipay')
    ->middleware(\app\common\middleware\PayNotifyGuard::class);
Route::any('pay/notify/wechat', 'PayNotify@wechat')
    ->middleware(\app\common\middleware\PayNotifyGuard::class);
Route::any('pay/notify/:channel', 'PayNotify@channel')
    ->pattern(['channel' => '[a-z][a-z0-9_]{0,47}'])
    ->middleware(\app\common\middleware\PayNotifyGuard::class);
Route::get('member/security', 'app\home\controller\Member@security');
Route::get('member/documents', 'app\home\controller\Member@documents');
Route::get('member/document/create', 'app\home\controller\Member@documentCreate');
Route::get('member/document/edit/:id', 'app\home\controller\Member@documentEdit')->pattern(['id' => '\d+']);
Route::get('member/oauth/:provider/redirect', 'app\home\controller\Member@oauthRedirect')
    ->pattern(['provider' => '[a-z][a-z0-9_-]+']);
Route::get('member/oauth/:provider/callback', 'app\home\controller\Member@oauthCallback')
    ->pattern(['provider' => '[a-z][a-z0-9_-]+']);

// 插件会员扩展页：GET HTML；POST 表单（含 multipart 上传）走同路径，AJAX 另见 /api/v1/member/*
Route::get('member/:memberPath', 'app\home\controller\Member@pluginMemberPage')
    ->pattern(['memberPath' => '[a-z][a-z0-9_-]*(?:/[a-z0-9_-]+)*']);
Route::post('member/:memberPath', 'app\home\controller\Member@pluginMemberPagePost')
    ->pattern(['memberPath' => '[a-z][a-z0-9_-]*(?:/[a-z0-9_-]+)*']);

// 自定义路径分页（path 风格：/xinwen/2 或 /xinwen/2.html）
// member/*、portal/* 多段路径由插件/会员扩展路由处理，禁止误吞为单页分页
Route::get(':path/:page', 'app\home\controller\Front@pathPaged')->pattern([
    'path' => '(?!member(?:\.html)?$)(?!portal(?:\.html)?$)[a-z0-9_\-]+(?:\.html)?',
    // 分页 list_N / 文档 html_name / 参数组筛伪静态（可含中文，如 免费_官方自营_文档扩展）
    'page' => '(?!product|cart|checkout|market|listing)[^/\s?#]+(?:\.html)?',
]);
// 单页 / 标签 / 文章根路径（须放在固定路由之后）
Route::get(':path', 'app\home\controller\Front@path')->pattern(['path' => '[a-z0-9_\-]+(?:\.html)?']);
