<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/** 项目路径 SSOT（运行时目录等） */
final class ProjectPaths
{
    public const RUNTIME_REL = 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR;

    public const ROUTE_REL = 'app' . DIRECTORY_SEPARATOR . 'route' . DIRECTORY_SEPARATOR;

    public static function runtimeDir(): string
    {
        $root = defined('ROOT_PATH')
            ? ROOT_PATH
            : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;

        return $root . self::RUNTIME_REL;
    }

    public static function routeDir(): string
    {
        $root = defined('ROOT_PATH')
            ? ROOT_PATH
            : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;

        return $root . self::ROUTE_REL;
    }

    public static function defineRuntimeConstant(): void
    {
        if (!defined('RUNTIME_PATH')) {
            define('RUNTIME_PATH', self::runtimeDir());
        }
    }

    /** file session 目录（SSOT：data/runtime/session，禁止根 runtime/session） */
    public static function sessionDir(): string
    {
        return self::runtimeDir() . 'session' . DIRECTORY_SEPARATOR;
    }

    /** ThinkPHP 默认误用的废弃路径：{root}/runtime/ */
    public static function legacyRootRuntimeDir(): string
    {
        return rtrim(self::root(), '/\\') . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR;
    }

    /**
     * 校正 App 运行态目录（new App() 构造时会先指向根 runtime/，须在 initialize 前调用）
     */
    public static function applyAppRuntimePath(\think\App $app): void
    {
        self::defineRuntimeConstant();
        $app->setRuntimePath(RUNTIME_PATH);
    }

    /**
     * 移除误建的根 runtime/（幂等；仅删目录树，不碰 data/runtime）
     */
    public static function removeLegacyRootRuntime(): void
    {
        $legacy = rtrim(self::legacyRootRuntimeDir(), '/\\');
        if (!is_dir($legacy)) {
            return;
        }
        self::removeDirRecursive($legacy);
    }

    /**
     * @return non-empty-string
     */
    public static function productVersion(): string
    {
        if (defined('PIVARK_VERSION') && (string) PIVARK_VERSION !== '') {
            return (string) PIVARK_VERSION;
        }
        $dbConfig = self::root() . 'config' . DIRECTORY_SEPARATOR . 'database.php';
        if (is_file($dbConfig)) {
            $src = (string) file_get_contents($dbConfig);
            if (preg_match("/define\s*\(\s*'PIVARK_VERSION'\s*,\s*'([^']+)'\s*\)/", $src, $m)) {
                return $m[1];
            }
        }

        return '1.6.0';
    }

    private static function removeDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::removeDirRecursive($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public static function root(): string
    {
        return defined('ROOT_PATH')
            ? ROOT_PATH
            : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
    }

    /** 站点 Web 静态根（默认 public/，与 config pivark.static_html_root 一致） */
    public static function publicDir(): string
    {
        $rel = trim((string) config('pivark.static_html_root', 'public'), '/\\');
        if ($rel === '') {
            $rel = 'public';
        }

        return rtrim(self::root(), '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    }

    /**
     * dig 库结构演进脚本目录（完整仓经 optionalWorkspaceTools；客户站无此树）。
     * 客户升级只从升级包解压工作区执行（绑 PIVARK_MIGRATION_SSOT），禁止把 migrate 树当日常 SSOT。
     */
    public static function migrationsDir(): string
    {
        if (\defined('PIVARK_MIGRATION_SSOT')) {
            return dirname((string) PIVARK_MIGRATION_SSOT);
        }

        $viaTools = self::optionalWorkspaceToolsFile(['daily', 'schema', 'migrations']);
        if ($viaTools !== '' && is_dir($viaTools)) {
            return $viaTools;
        }

        // Community 完整包解压后：装机种子仍可读包内引导（非升级日常 SSOT）
        $packDir = self::packagedMigrationsDirIfPresent();
        if ($packDir !== '') {
            return $packDir;
        }

        // 无工具树且无包内引导：占位（升级须另绑工作区）
        return rtrim(self::root(), '/\\') . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR
            . 'runtime' . DIRECTORY_SEPARATOR . 'upgrade_migrations_absent';
    }

    /** 迁移 bootstrap SSOT（优先 _migration_bootstrap.php） */
    public static function migrationsBootstrapFile(): string
    {
        if (\defined('PIVARK_MIGRATION_SSOT')) {
            return (string) PIVARK_MIGRATION_SSOT;
        }

        $dir = self::migrationsDir();
        $bootstrap = $dir . DIRECTORY_SEPARATOR . '_migration_bootstrap.php';
        if (is_readable($bootstrap)) {
            return $bootstrap;
        }

        // 发行包可能仅保留 _migration.php
        $legacy = $dir . DIRECTORY_SEPARATOR . '_migration.php';
        if (is_readable($legacy)) {
            return $legacy;
        }

        return $bootstrap;
    }

    /**
     * 完整包解压残留的引导目录（install/setup 种子用）。无可读 bootstrap 则空串。
     */
    private static function packagedMigrationsDirIfPresent(): string
    {
        $dir = rtrim(self::root(), '/\\') . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR
            . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        if (!is_dir($dir)) {
            return '';
        }
        if (is_readable($dir . DIRECTORY_SEPARATOR . '_migration_bootstrap.php')
            || is_readable($dir . DIRECTORY_SEPARATOR . '_migration.php')) {
            return $dir;
        }

        return '';
    }

    /**
     * 完整开发仓可选工具树绝对路径（发行包通常不存在；不存在则返回空串）。
     */
    public static function optionalWorkspaceToolsDir(): string
    {
        $dir = rtrim(self::root(), '/\\') . DIRECTORY_SEPARATOR . 'devtools';

        return is_dir($dir) ? $dir : '';
    }

    /**
     * 工具树下相对文件绝对路径；工具树不存在时返回空串。
     *
     * @param list<string> $relativeParts
     */
    public static function optionalWorkspaceToolsFile(array $relativeParts): string
    {
        $base = self::optionalWorkspaceToolsDir();
        if ($base === '' || $relativeParts === []) {
            return '';
        }

        return $base . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $relativeParts);
    }

    /** 安装向导建库脚本 SSOT：install/setup（仅 init_*；装完可删整个 install/） */
    public static function installSetupDir(): string
    {
        return self::installDir() . DIRECTORY_SEPARATOR . 'setup';
    }

    /** 安装向导可选演示种子（勾选「导入演示数据」时执行；装完可删 install/assets/） */
    public static function installSeedDir(): string
    {
        return self::installAssetsDir() . DIRECTORY_SEPARATOR . 'seed';
    }

    /** 解析演示种子脚本（仅 install/assets/seed） */
    public static function resolveInstallSeedScript(string $basename): ?string
    {
        $basename = ltrim(str_replace(['/', '\\'], '', $basename));
        if ($basename === '') {
            return null;
        }
        $path = rtrim(self::installSeedDir(), '/\\') . DIRECTORY_SEPARATOR . $basename;

        return is_readable($path) ? $path : null;
    }

    /** Web 安装向导根目录（与 app/ 内核分离；装完可整目录删除，渠道可在此定制） */
    public static function installDir(): string
    {
        $root = rtrim(self::root(), '/\\');

        return $root . DIRECTORY_SEPARATOR . 'install';
    }

    /** 安装专用资源（增强包 zip、演示种子等；装完可删） */
    public static function installAssetsDir(): string
    {
        return self::installDir() . DIRECTORY_SEPARATOR . 'assets';
    }

    /** 安装向导增强包 zip 落盘目录（优先于 public/static/market/plugins） */
    public static function installPackagesDir(): string
    {
        return self::installAssetsDir() . DIRECTORY_SEPARATOR . 'packages';
    }

    public static function installViewFile(string $basename): string
    {
        $basename = ltrim(str_replace(['/', '\\'], '', $basename), '/');

        return self::installDir() . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . $basename;
    }

    /** 渠道定制：install/channel.json（复制 channel.example.json 后修改） */
    public static function installChannelConfigFile(): string
    {
        return self::installDir() . DIRECTORY_SEPARATOR . 'channel.json';
    }

    /**
     * Web/CGI/FPM 场景下 PHP_BINARY 常为 php-cgi / php-fpm；安装/迁移脚本须用 CLI php。
     * 宝塔典型：PHP_BINARY=/www/server/php/82/sbin/php-fpm，且 open_basedir 禁止探测 bin/php。
     */
    public static function cliPhpBinary(): string
    {
        $binary = \defined('PHP_BINARY') && \is_string(PHP_BINARY) ? PHP_BINARY : '';
        if ($binary === '') {
            return 'php';
        }

        $normalized = str_replace('\\', '/', $binary);
        $isWindows = PHP_OS_FAMILY === 'Windows';

        // php-fpm：勿 is_file 探测站外路径（触发 open_basedir 告警/异常）；交 PATH 的 php，失败则由 CliProcessRunner include 回退
        if (preg_match('/php-fpm/i', $normalized) === 1) {
            return 'php';
        }

        // php-cgi → 同目录 php / php.exe
        if (stripos($normalized, 'cgi') !== false) {
            if ($isWindows) {
                $cli = (string) preg_replace('/php-cgi(?:\.exe)?$/i', 'php.exe', $binary);
                if ($cli !== '' && self::isUsablePhpBinary($cli)) {
                    return $cli;
                }
                $candidate = dirname($binary) . DIRECTORY_SEPARATOR . 'php.exe';
                if (self::isUsablePhpBinary($candidate)) {
                    return $candidate;
                }
            } else {
                $cli = (string) preg_replace('/php-cgi$/i', 'php', $binary);
                if ($cli !== '' && self::isUsablePhpBinary($cli)) {
                    return $cli;
                }
            }
        }

        return $binary;
    }

    private static function isUsablePhpBinary(string $path): bool
    {
        if ($path === '' || !self::pathAllowedByOpenBasedir($path)) {
            return false;
        }

        return @is_file($path) && (@is_executable($path) || PHP_OS_FAMILY === 'Windows');
    }

    private static function pathAllowedByOpenBasedir(string $path): bool
    {
        $ob = \ini_get('open_basedir');
        if (!\is_string($ob) || \trim($ob) === '') {
            return true;
        }
        $path = str_replace('\\', '/', $path);
        foreach (\explode(PATH_SEPARATOR, $ob) as $base) {
            $base = \rtrim(str_replace('\\', '/', \trim($base)), '/');
            if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
                return true;
            }
        }

        return false;
    }

    /** Community 等裁剪包：类在 composer 登记但文件未分发时，避免 class_exists 触发 autoload 致命错误 */
    public static function appClassFileExists(string $class): bool
    {
        $class = ltrim($class, '\\');
        if (!str_starts_with($class, 'app\\')) {
            return class_exists($class, false);
        }
        $rel = str_replace('\\', '/', $class) . '.php';

        return is_file(rtrim(self::root(), '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
    }
}
