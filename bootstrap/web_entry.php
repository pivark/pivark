<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2025 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
// [ HTTP 真入口 · 由根目录 index.php 薄壳引入；勿对外直访 /bootstrap/ ]
// +----------------------------------------------------------------------

namespace think;

// 站点根（本文件在 bootstrap/，上一级才是 ROOT）
define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);

// 定义应用目录
define('APP_PATH', ROOT_PATH . 'app/');

// 定义运行时目录（可写数据，与 data/backups 同属 data/）
define('RUNTIME_PATH', ROOT_PATH . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR);

// 定义配置目录
define('CONFIG_PATH', ROOT_PATH . 'config/');

// 定义VENDOR目录
define('VENDOR_PATH', ROOT_PATH . 'vendor/');

// 弱验证：PHP 版本软门（须在任何 PHP8 语法文件之前）
$pivarkPhpGate = ROOT_PATH . 'install' . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'php_version_gate.php';
if (is_file($pivarkPhpGate)) {
    require_once $pivarkPhpGate;
}

// 框架启动前预检：缺 mbstring 等时打开许可→环境指引页（勿进 vendor/ThinkPHP 白屏）
$pivarkPreflight = ROOT_PATH . 'install' . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'preflight.php';
if (is_file($pivarkPreflight)) {
    require_once $pivarkPreflight;
    pivark_preflight_abort_if_unmet(ROOT_PATH);
}

// 加载基础文件
require VENDOR_PATH . 'autoload.php';
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'install\\')) {
        return;
    }
    $rel  = str_replace('\\', '/', substr($class, strlen('install\\')));
    $file = ROOT_PATH . 'install/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});
\app\common\support\ProjectPaths::removeLegacyRootRuntime();

\app\common\support\SiteEnv::injectIntoProcessEnv(ROOT_PATH);

// 动态 URL 去尾斜杠（Tengine 上 /news/ 常不进本入口；进了也统一 301）
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$pathOnly = parse_url($requestUri, PHP_URL_PATH);
if (is_string($pathOnly) && \strlen($pathOnly) > 1 && str_ends_with($pathOnly, '/')) {
    $rel = trim($pathOnly, '/');
    $dir = ROOT_PATH . str_replace('/', \DIRECTORY_SEPARATOR, $rel);
    if ($rel !== '' && !is_dir($dir)) {
        $dest = '/' . $rel;
        $query = parse_url($requestUri, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $dest .= '?' . $query;
        }
        header('Location: ' . $dest, true, 301);
        exit;
    }
}

// 主题静态：SSOT 为 template/{theme}/assets/，URL 仍为 /static/theme/{theme}/
if (\app\common\support\ThemeStaticAsset::tryServeHttpAsset($requestUri)) {
    exit;
}
if (\app\common\support\AdminStaticAsset::tryServeHttpAsset($requestUri)) {
    exit;
}
if (\app\common\support\WeappPublicAsset::tryServeHttpAsset($requestUri)) {
    exit;
}
$docsStaticServe = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__) . DIRECTORY_SEPARATOR) . 'docs/_serve/DocsStaticAsset.php';
if (is_file($docsStaticServe)) {
    require_once $docsStaticServe;
    if (\DocsServe\DocsStaticAsset::tryServeHttpAsset($requestUri)) {
        exit;
    }
}

// 执行应用
$app = new App();
$app->setRuntimePath(RUNTIME_PATH);
$http = $app->http;
$http->setRoutePath(APP_PATH . 'route' . DIRECTORY_SEPARATOR);
$app->initialize();

date_default_timezone_set((string) config('app.default_timezone', 'Asia/Shanghai'));

$uri = $_SERVER['REQUEST_URI'] ?? '';

// 未安装站点：尽早跳转 /install，避免 AdminEntryAlias/ConfigService 在未配库时连库致命错误
if (!\app\common\support\InstallGate::shouldBypass($uri)) {
    if (!\app\common\support\InstallGate::isInstalled()) {
        header('Location: /install');
        exit;
    }
}
if (\app\common\support\InstallGate::isInstalled() && \app\common\support\InstallGate::isInstallUri($uri)) {
    header('Location: ' . \app\common\support\SiteUrl::adminSpa('/auth/login'));
    exit;
}

// 系统运行模式（configs.site_mode）；须在 initialize 之后读库
app(\app\common\service\site\SiteModeService::class)->bootstrapFromApp($app);
// cache_driver=file|redis（与 Session 分离；代登录令牌等 Cache:: 须与后台一致）
app(\app\common\service\infra\CacheConfigService::class)->bootstrapFromApp($app);

// 处理 /admin/ 直接跳转到后台首页（仅已安装站读别名配置）
if (\app\common\support\InstallGate::isInstalled()) {
    if ($uri === '/admin' || $uri === '/admin/' || str_starts_with($uri, '/admin?') || str_starts_with($uri, '/admin/?')) {
        if (app(\app\common\service\site\AdminEntryAliasService::class)->isActive()) {
            http_response_code(404);
            exit;
        }
        header('Location: ' . \app\common\support\SiteUrl::adminSpa());
        exit;
    }
    $aliasBase = app(\app\common\service\site\AdminEntryAliasService::class)->publicBasePath();
    if ($aliasBase !== '/admin') {
        if ($uri === $aliasBase || $uri === $aliasBase . '/' || str_starts_with($uri, $aliasBase . '?') || str_starts_with($uri, $aliasBase . '/?')) {
            header('Location: ' . \app\common\support\SiteUrl::adminSpa());
            exit;
        }
    }
}

// 站点状态检查（前台关闭、核心升级维护锁、后台/API 可进）
try {
    $bypassClose = app(\app\common\service\site\SiteStatusService::class)->shouldBypassClose($uri);
    $frontBlocked = !$bypassClose && (
        !app(\app\common\service\site\SiteStatusService::class)->isFrontOpen()
        || app(\app\common\service\release\CoreUpdateMaintenanceService::class)->isActive()
    );
    if ($frontBlocked) {
        app(\app\common\service\site\SiteStatusService::class)->renderClosedResponse();
    }
} catch (\Throwable $e) {
    // 未安装或读库失败时不阻断（安装向导仍需可访问）
}

$response = $http->run();
$response->send();
$http->end($response);
