<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 *
 * 运行态 CLI / 迁移引导（Community 发行包入口）
 *
 * 用法:
 *   require ROOT_PATH . 'app/bootstrap/cli.php';
 *   $app = pivark_app();
 */
declare(strict_types=1);

if (defined('PIVARK_APP_CLI_BOOTSTRAP')) {
    return;
}
define('PIVARK_APP_CLI_BOOTSTRAP', true);

(function (): void {
    $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    if (!defined('ROOT_PATH')) {
        define('ROOT_PATH', $root);
    }
    if (!defined('APP_PATH')) {
        define('APP_PATH', ROOT_PATH . 'app/');
    }
    if (!defined('CONFIG_PATH')) {
        define('CONFIG_PATH', ROOT_PATH . 'config/');
    }
    require_once ROOT_PATH . 'vendor/autoload.php';
    \app\common\support\SiteEnv::injectIntoProcessEnv(ROOT_PATH);
    $tpHelper = ROOT_PATH . 'vendor/topthink/framework/src/helper.php';
    if (is_file($tpHelper)) {
        require_once $tpHelper;
    }
    \app\common\support\ProjectPaths::defineRuntimeConstant();
    \app\common\support\ProjectPaths::removeLegacyRootRuntime();
})();

if (!function_exists('pivark_app')) {
    function pivark_app(bool $initialize = true): \think\App
    {
        static $bootstrapped = false;

        try {
            $existing = \think\App::getInstance();
            if ($existing instanceof \think\App) {
                \app\common\support\ProjectPaths::applyAppRuntimePath($existing);
                $pivarkCfg = $existing->config->get('pivark');
                if (!\is_array($pivarkCfg) || \count($pivarkCfg) < 5) {
                    $existing->initialize();
                }
                if ($initialize && !$bootstrapped) {
                    pivark_app_bootstrap_services($existing);
                    $bootstrapped = true;
                }

                return $existing;
            }
        } catch (\Throwable) {
            // 尚未实例化，继续创建
        }

        static $app = null;
        if ($app instanceof \think\App) {
            \app\common\support\ProjectPaths::applyAppRuntimePath($app);
            if ($initialize && !$bootstrapped) {
                pivark_app_bootstrap_services($app);
                $bootstrapped = true;
            }

            return $app;
        }

        $app = new \think\App(ROOT_PATH);
        \app\common\support\ProjectPaths::applyAppRuntimePath($app);
        $app->http->setRoutePath(APP_PATH . 'route' . DIRECTORY_SEPARATOR);
        if ($initialize) {
            $app->initialize();
            pivark_app_bootstrap_services($app);
            $bootstrapped = true;
        }

        return $app;
    }

    function pivark_app_bootstrap_services(\think\App $app): void
    {
        try {
            app(\app\common\service\site\SiteModeService::class)->bootstrapFromApp($app);
        } catch (\Throwable) {
            // site_mode 读库失败时继续，缓存驱动仍须就绪
        }
        app(\app\common\service\infra\CacheConfigService::class)->bootstrapFromApp($app);
    }
}
