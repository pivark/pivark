<?php
/**
 * 后台 REST 路由表 SSOT（/api/v1/admin/*）
 *
 * 每项：method, path, handler[class, method], options
 * options: public, password_change, permission_controller, permission_action
 *
 * 真源目录：`config/admin_rest/`；根目录 `admin_rest_dispatch.php` 为 Think 配置名 shim。
 */
declare(strict_types=1);

$metaRoutes = require __DIR__ . '/routes/meta.php';
if (!is_array($metaRoutes)) {
    $metaRoutes = [];
}

$contentRoutes = require __DIR__ . '/routes/content.php';
if (!is_array($contentRoutes)) {
    $contentRoutes = [];
}

$systemRoutes = require __DIR__ . '/routes/system.php';
if (!is_array($systemRoutes)) {
    $systemRoutes = [];
}

$memberRoutes = require __DIR__ . '/routes/member.php';
if (!is_array($memberRoutes)) {
    $memberRoutes = [];
}

$seoRoutes = require __DIR__ . '/routes/seo.php';
if (!is_array($seoRoutes)) {
    $seoRoutes = [];
}

$paymentRoutes = require __DIR__ . '/routes/payment.php';
if (!is_array($paymentRoutes)) {
    $paymentRoutes = [];
}

$searchRoutes = require __DIR__ . '/routes/search.php';
if (!is_array($searchRoutes)) {
    $searchRoutes = [];
}

$siteRoutes = require __DIR__ . '/routes/site.php';
if (!is_array($siteRoutes)) {
    $siteRoutes = [];
}

$pluginRoutes = require __DIR__ . '/routes/plugin.php';
if (!is_array($pluginRoutes)) {
    $pluginRoutes = [];
}

$opsRoutes = require __DIR__ . '/routes/ops.php';
if (!is_array($opsRoutes)) {
    $opsRoutes = [];
}

$weappRoutes = require __DIR__ . '/routes/weapp.php';
if (!is_array($weappRoutes)) {
    $weappRoutes = [];
}

$spaRoutes = require __DIR__ . '/routes/spa.php';
if (!is_array($spaRoutes)) {
    $spaRoutes = [];
}

return [
    'routes' => array_merge([
        [
            'method'  => 'GET',
            'path'    => 'session/bootstrap',
            'handler' => [\app\admin\controller\Spa::class, 'bootstrap'],
            'options' => ['public' => true],
        ],
        [
            'method'  => 'GET',
            'path'    => 'session/user',
            'handler' => [\app\admin\controller\Spa::class, 'user'],
            'options' => ['password_change' => true, 'permission_controller' => 'spa', 'permission_action' => 'user'],
        ],
        [
            'method'  => 'GET',
            'path'    => 'session/permission-codes',
            'handler' => [\app\admin\controller\Spa::class, 'codes'],
            'options' => ['permission_controller' => 'spa', 'permission_action' => 'codes'],
        ],
        [
            'method'  => 'GET',
            // 侧栏主路径：勿含 /admin/spa 子串；勿用 menus（避免与菜单 API 混淆）
            'path'    => 'nav-routes',
            'handler' => [\app\admin\controller\Spa::class, 'menus'],
            'options' => ['permission_controller' => 'spa', 'permission_action' => 'menus'],
        ],
        [
            'method'  => 'GET',
            // 兼容旧 SPA / 探针
            'path'    => 'vben-menu-routes',
            'handler' => [\app\admin\controller\Spa::class, 'menus'],
            'options' => ['permission_controller' => 'spa', 'permission_action' => 'menus'],
        ],
        [
            'method'  => 'GET',
            // 过渡别名（含 spa- 子串，仅兼容；新前端走 nav-routes）
            'path'    => 'spa-nav-tree',
            'handler' => [\app\admin\controller\Spa::class, 'menus'],
            'options' => ['permission_controller' => 'spa', 'permission_action' => 'menus'],
        ],
        [
            'method'  => 'GET',
            'path'    => 'auth/captcha',
            'handler' => [\app\admin\controller\Login::class, 'captcha'],
            'options' => ['public' => true],
        ],
        [
            'method'  => 'GET',
            'path'    => 'auth/status',
            'handler' => [\app\admin\controller\Login::class, 'status'],
            'options' => ['public' => true],
        ],
        [
            'method'  => 'POST',
            'path'    => 'auth/login',
            'handler' => [\app\admin\controller\Login::class, 'do_login'],
            'options' => ['public' => true],
        ],
        [
            'method'  => 'POST',
            'path'    => 'auth/logout',
            'handler' => [\app\admin\controller\Login::class, 'logout'],
            'options' => ['password_change' => true, 'permission_controller' => 'login', 'permission_action' => 'logout'],
        ],
        [
            'method'  => 'PATCH',
            'path'    => 'account/password',
            'handler' => [\app\admin\controller\Spa::class, 'changePassword'],
            'options' => ['password_change' => true, 'permission_controller' => 'spa', 'permission_action' => 'changepassword'],
        ],
        [
            'method'  => 'POST',
            'path'    => 'account/password',
            'handler' => [\app\admin\controller\Spa::class, 'changePassword'],
            'options' => ['password_change' => true, 'permission_controller' => 'spa', 'permission_action' => 'changepassword'],
        ],
    ], $metaRoutes, $contentRoutes, $systemRoutes, $memberRoutes, $seoRoutes, $paymentRoutes, $searchRoutes, $siteRoutes, $pluginRoutes, $opsRoutes, $spaRoutes, $weappRoutes),
];
