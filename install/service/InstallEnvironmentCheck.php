<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace install\service;

use app\common\support\InstallGate;
use app\common\support\LocalFile;

/** 装站一次性：环境检测（自动识别宝塔/Nginx/Apache/IIS）；装完可随 install/ 删除 */
final class InstallEnvironmentCheck
{

    public const PANEL_BAOTA = 'baota';

    /** @return array{panel:string,label:string,baota_detected:bool,server?:string} */
    public function detectPanel(): array
    {
        $preflight = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'preflight.php';
        if (is_file($preflight)) {
            require_once $preflight;
        }
        if (function_exists('pivark_preflight_detect_env')) {
            $env = pivark_preflight_detect_env();

            return [
                'panel'          => (string) ($env['panel'] ?? 'generic'),
                'label'          => (string) ($env['label'] ?? '通用环境'),
                'baota_detected' => (($env['panel'] ?? '') === self::PANEL_BAOTA),
                'server'         => (string) ($env['server'] ?? 'generic'),
            ];
        }

        return [
            'panel'          => 'generic',
            'label'          => '通用 Linux / Windows',
            'baota_detected' => false,
            'server'         => 'generic',
        ];
    }

    /**
     * @return array{
     *   checks:list<array<string,mixed>>,
     *   meta:array<string,mixed>,
     *   baota_steps:list<string>,
     *   missing_required:list<string>
     * }
     */
    public function report(): array
    {
        $panel  = $this->detectPanel();
        $checks = $this->buildChecks();
        $missingRequired = [];
        $missingRecommended = [];
        foreach ($checks as $row) {
            if ($row['ok']) {
                continue;
            }
            if (($row['level'] ?? '') === 'required') {
                $missingRequired[] = (string) ($row['label'] ?? '');
            } else {
                $missingRecommended[] = (string) ($row['label'] ?? '');
            }
        }

        $canInstall = $missingRequired === [];
        $guideSteps = $this->guideStepsForMissing($checks, (string) $panel['panel']);

        return [
            'checks'              => $checks,
            'meta'                => [
                'php_version'       => PHP_VERSION,
                'php_sapi'          => php_sapi_name(),
                'php_ini'           => (string) (php_ini_loaded_file() ?: ''),
                'php_ini_scanned'   => (string) (php_ini_scanned_files() ?: ''),
                'panel'             => $panel['panel'],
                'panel_label'       => $panel['label'],
                'baota_detected'    => $panel['baota_detected'],
                'server'            => $panel['server'] ?? 'generic',
                'can_install'       => $canInstall,
                'missing_required'  => $missingRequired,
                'missing_recommended' => $missingRecommended,
            ],
            // 兼容旧前端字段名 baota_steps（现为「当前环境指引」）
            'baota_steps'         => $guideSteps,
            'guide_steps'         => $guideSteps,
            'missing_required'    => $missingRequired,
        ];
    }

    /** @return list<array{key:string,label:string,ok:bool,detail:string,level:string,fix_baota?:string}> */
    public function buildChecks(): array
    {
        $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
        $preflight = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'preflight.php';
        if (is_file($preflight)) {
            require_once $preflight;
        }

        // 必装项 SSOT：install/bootstrap/preflight.php（框架启动前同一套）
        $rows = function_exists('pivark_preflight_critical_checks')
            ? pivark_preflight_critical_checks($root)
            : [];

        $ext = static function (string $ext): bool {
            return extension_loaded($ext);
        };

        $panel = $this->detectPanel();
        $hint = static function (string $key) use ($panel): string {
            return function_exists('pivark_preflight_fix_hint')
                ? pivark_preflight_fix_hint((string) $panel['panel'], $key)
                : '按当前环境安装对应扩展后重启 PHP';
        };

        $rows[] = [
            'key'   => 'gd',
            'label' => 'GD（缩略图）',
            'ok'    => $ext('gd'),
            'detail' => $ext('gd') ? 'ok' : 'missing',
            'level' => 'recommended',
            'fix' => $hint('gd'),
            'fix_baota' => $hint('gd'),
        ];
        $rows[] = [
            'key'   => 'zip',
            'label' => 'Zip（插件包 / 备份）',
            'ok'    => class_exists(\ZipArchive::class),
            'detail' => class_exists(\ZipArchive::class) ? 'ok' : 'missing',
            'level' => 'recommended',
            'fix' => $hint('zip'),
            'fix_baota' => $hint('zip'),
        ];
        $rows[] = [
            'key'   => 'fileinfo',
            'label' => 'fileinfo（上传类型）',
            'ok'    => $ext('fileinfo'),
            'detail' => $ext('fileinfo') ? 'ok' : 'missing',
            'level' => 'recommended',
            'fix' => $hint('fileinfo'),
            'fix_baota' => $hint('fileinfo'),
        ];

        $cliOk = function_exists('pivark_preflight_cli_subprocess_ok')
            ? pivark_preflight_cli_subprocess_ok()
            : true;
        $cliDetail = 'ok';
        if (!$cliOk) {
            $blocked = [];
            foreach (['exec', 'shell_exec', 'proc_open'] as $fn) {
                $allowed = function_exists('pivark_preflight_function_allowed')
                    ? pivark_preflight_function_allowed($fn)
                    : function_exists($fn);
                if (!$allowed) {
                    $blocked[] = $fn;
                }
            }
            $cliDetail = '已禁用：' . ($blocked !== [] ? implode(', ', $blocked) : '子进程函数不可用');
        }
        $rows[] = [
            'key'   => 'cli_subprocess',
            'label' => 'PHP 子进程（升级/部分安装步骤）',
            'ok'    => $cliOk,
            'detail' => $cliDetail,
            'level' => 'recommended',
            'fix' => $hint('cli_subprocess'),
            'fix_baota' => $hint('cli_subprocess'),
        ];

        $sessionType  = strtolower(trim((string) env('SESSION_TYPE', 'file')));
        $sessionStore = trim((string) (env('SESSION_STORE', '') ?? ''));
        $sessionFix = '.env / site.env 设置 SESSION_TYPE=cache SESSION_STORE=redis，并配置 redis 连接与扩展';
        $rows[] = [
            'key'   => 'session_shared',
            'label' => 'Session 多机共享（production 建议 cache+redis）',
            'ok'    => $this->productionSessionSharedOk(
                (string) env('APP_ENV', ''),
                (string) env('SESSION_TYPE', 'file'),
                (string) (env('SESSION_STORE', '') ?? '')
            ),
            'detail' => $sessionType . ($sessionStore !== '' ? ' / ' . $sessionStore : ''),
            'level' => 'recommended',
            'fix' => $sessionFix,
            'fix_baota' => $sessionFix,
        ];

        $secretLeaks = InstallGate::isInstalled()
            ? app(\app\common\service\config\ConfigSecretService::class)->listPlaintextLeaks()
            : [];
        $secretFix = '后台「系统设置 → 凭据」迁移明文配置，或联系运维按发行说明处理';
        $rows[] = [
            'key'   => 'config_secrets',
            'label' => '凭据已迁 config_secrets（无明文 configs）',
            'ok'    => $secretLeaks === [],
            'detail' => $secretLeaks === [] ? 'ok' : count($secretLeaks) . ' 项待迁移',
            'level' => 'recommended',
            'fix' => $secretFix,
            'fix_baota' => $secretFix,
        ];

        return $rows;
    }

    /** production 环境须 cache+redis 共享 Session；非 production 恒通过 */
    public function productionSessionSharedOk(string $appEnv, string $sessionType, string $sessionStore): bool
    {
        $isProduction = strtolower(trim($appEnv)) === 'production';
        if (!$isProduction) {
            return true;
        }

        return strtolower(trim($sessionType)) === 'cache' && trim($sessionStore) !== '';
    }

    /** @return list<string> */
    public function requiredBlockKeys(): array
    {
        $preflight = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'preflight.php';
        if (is_file($preflight)) {
            require_once $preflight;
        }

        return function_exists('pivark_preflight_required_keys')
            ? pivark_preflight_required_keys()
            : ['php', 'pdo', 'json', 'mbstring', 'openssl', 'curl', 'data', 'runtime', 'uploads'];
    }

    /**
     * @param list<array<string,mixed>> $checks
     * @return list<string>
     */
    private function guideStepsForMissing(array $checks, string $panel): array
    {
        $steps = function_exists('pivark_preflight_env_steps')
            ? pivark_preflight_env_steps($panel)
            : [
                '请在本机 php.ini 或服务器面板中安装缺失扩展，修改后重启 PHP-FPM / Apache / IIS。',
                'Web 与 CLI 可能使用不同 php.ini，请以站点实际加载配置为准。',
            ];

        $needExt = [];
        foreach ($checks as $row) {
            if ($row['ok'] ?? false) {
                continue;
            }
            $fix = (string) ($row['fix'] ?? $row['fix_baota'] ?? '');
            if ($fix === '') {
                continue;
            }
            $needExt[] = '• ' . ($row['label'] ?? '') . '：' . $fix;
        }
        if ($needExt !== []) {
            $steps[] = '当前待处理：';
            foreach ($needExt as $line) {
                $steps[] = $line;
            }
        }

        $ini = (string) (php_ini_loaded_file() ?: '');
        if ($ini !== '') {
            $steps[] = '当前 Web PHP 配置文件：' . $ini;
        }

        return $steps;
    }

    private function isWritableDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return LocalFile::mkdirIfMissing($dir);
        }

        return is_writable($dir);
    }
}
