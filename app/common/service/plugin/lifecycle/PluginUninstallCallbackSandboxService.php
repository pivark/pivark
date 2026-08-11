<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\lifecycle;

use app\common\service\plugin\PluginService;
use app\common\service\plugin\boot\PluginBootRollbackService;
use app\common\service\plugin\boot\PluginBootService;
use app\common\support\LocalFile;
use app\common\support\ProjectPaths;
use app\common\support\ServiceResult;
use app\common\support\TrustedShellRunner;

/** 卸载回调隔离执行：捕获异常并回滚 Registry，降低恶意 uninstall() 破坏面 */
final class PluginUninstallCallbackSandboxService
{
    public function invoke(string $identifier): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return ServiceResult::fail('插件标识无效');
        }

        $manifest = app(PluginService::class)->readManifest($identifier);
        if (is_array($manifest) && app(PluginDeclarativeUninstallService::class)->isDeclarative($manifest)) {
            return app(PluginDeclarativeUninstallService::class)->skipPhpCallback($manifest);
        }

        if ($this->shouldUseSubprocess()) {
            $sub = $this->invokeSubprocess($identifier);
            if ($sub !== null) {
                return $sub;
            }
            if ($this->subprocessRequired()) {
                return ServiceResult::fail('隔离子进程不可用，已阻断卸载（请检查 PHP CLI 与 runtime 目录可写）');
            }
        }

        PluginService::registerAutoloadPublic($identifier);

        try {
            $plugin = app(PluginBootService::class)->loadPluginClass($identifier);
            if ($plugin === null) {
                return ServiceResult::ok(null, '无 uninstall 回调');
            }

            ob_start();
            try {
                $plugin->uninstall();
            } finally {
                ob_end_clean();
            }

            return ServiceResult::ok(null, '卸载回调已执行');
        } catch (\Throwable $e) {
            return ServiceResult::fail($e->getMessage());
        } finally {
            app(PluginBootRollbackService::class)->purgeForIdentifier($identifier);
            app(PluginBootService::class)->resetBooted();
        }
    }

    private function shouldUseSubprocess(): bool
    {
        if (!(bool) config('plugin.security.uninstall_callback_sandbox', true)) {
            return false;
        }

        $appEnv = strtolower(trim((string) env('APP_ENV', '')));
        $isProd = in_array($appEnv, ['production', 'prod'], true);

        return (bool) config('plugin.security.uninstall_callback_subprocess', $isProd);
    }

    private function subprocessRequired(): bool
    {
        return $this->shouldUseSubprocess();
    }

    private function invokeSubprocess(string $identifier): ?ServiceResult
    {
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $script = $this->subprocessRunnerPath();
        if (!is_file($script)) {
            return null;
        }

        $cmd = escapeshellarg($php)
            . $this->subprocessPhpIniFlags()
            . ' ' . escapeshellarg($script)
            . ' ' . escapeshellarg($identifier)
            . ' 2>&1';
        $run    = TrustedShellRunner::execCaptured($cmd);
        $code   = (int) ($run['exit_code'] ?? 1);
        $stdout = trim((string) ($run['output'] ?? ''));
        if ($code === 0) {
            if (str_starts_with($stdout, 'SKIP:')) {
                return app(PluginDeclarativeUninstallService::class)->skipPhpCallback(
                    app(PluginService::class)->readManifest($identifier) ?? []
                );
            }

            return ServiceResult::ok(null, '隔离子进程卸载回调已执行');
        }

        $err = $stdout !== '' ? $stdout : '隔离子进程退出码 ' . $code;

        return ServiceResult::fail($err);
    }

    private function subprocessRunnerPath(): string
    {
        $path = rtrim(ProjectPaths::runtimeDir(), '/\\') . DIRECTORY_SEPARATOR . 'plugin_uninstall_isolated_runner.php';
        if (is_file($path)) {
            return $path;
        }

        $bootstrap = rtrim(ProjectPaths::root(), '/\\') . DIRECTORY_SEPARATOR . 'devtools'
            . DIRECTORY_SEPARATOR . 'daily' . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'cli.php';
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$identifier = strtolower(trim((string) ($argv[1] ?? '')));
if ($identifier === '') { fwrite(STDERR, "usage\n"); exit(2); }
require '%BOOTSTRAP%';
pivark_app();
use app\common\service\plugin\boot\PluginBootRollbackService;
use app\common\service\plugin\boot\PluginBootService;
use app\common\service\plugin\lifecycle\PluginDeclarativeUninstallService;
use app\common\service\plugin\PluginService;
try {
    $manifest = app(PluginService::class)->readManifest($identifier);
    if (is_array($manifest) && app(PluginDeclarativeUninstallService::class)->isDeclarative($manifest)) { echo "SKIP:declarative\n"; exit(0); }
    PluginService::registerAutoloadPublic($identifier);
    $plugin = app(PluginBootService::class)->loadPluginClass($identifier);
    if ($plugin === null) { echo "OK:no_callback\n"; exit(0); }
    ob_start(); try { $plugin->uninstall(); } finally { ob_end_clean(); }
    echo "OK:done\n"; exit(0);
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
finally { app(PluginBootRollbackService::class)->purgeForIdentifier($identifier); app(PluginBootService::class)->resetBooted(); }
PHP;
        $code = str_replace('%BOOTSTRAP%', addcslashes(str_replace('\\', '/', $bootstrap), "'\\"), $code);
        LocalFile::putContents($path, $code);

        return $path;
    }

    private function subprocessPhpIniFlags(): string
    {
        $disable = 'exec,system,shell_exec,passthru,popen,proc_open,pcntl_exec,dl';
        $root    = rtrim(ProjectPaths::root(), '/\\');
        $basedir = implode(PATH_SEPARATOR, array_unique(array_filter([
            $root,
            $root . DIRECTORY_SEPARATOR . 'weapp',
            rtrim(ProjectPaths::runtimeDir(), '/\\'),
            rtrim((string) sys_get_temp_dir(), '/\\'),
        ])));

        return ' -d disable_functions=' . escapeshellarg($disable)
            . ' -d open_basedir=' . escapeshellarg($basedir);
    }
}
