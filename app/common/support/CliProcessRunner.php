<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/** 在 exec 被禁用时仍可调用 php.exe 执行安装/迁移脚本（proc_open / Windows COM 回退） */
final class CliProcessRunner
{
    /** @param array<string, string> $extraEnv */
    public static function runPhpScript(string $script, array $extraEnv = []): array
    {
        $php = ProjectPaths::cliPhpBinary();
        $cmd = \escapeshellarg($php) . ' ' . \escapeshellarg($script) . ' 2>&1';
        $cmd = self::prefixEnv($cmd, $extraEnv);

        if (self::canShellFunction('exec')) {
            $lines = [];
            $exitCode = 1;
            \exec($cmd, $lines, $exitCode);

            return [
                'exit_code' => (int) $exitCode,
                'output'    => implode("\n", $lines),
            ];
        }

        if (self::canShellFunction('shell_exec')) {
            $output = \shell_exec($cmd);

            return [
                'exit_code' => is_string($output) ? 0 : 1,
                'output'    => is_string($output) ? trim($output) : '',
            ];
        }

        $proc = self::runViaProcOpen($php, $script, $extraEnv);
        if ($proc !== null) {
            return $proc;
        }

        $com = self::runViaWindowsCom($cmd);
        if ($com !== null) {
            return $com;
        }

        return [
            'exit_code' => 1,
            'output'    => self::UNAVAILABLE_MESSAGE,
        ];
    }

    /** 子进程完全不可用时的稳定文案（调用方勿依赖旧措辞精确匹配） */
    public const UNAVAILABLE_MESSAGE = '无法启动 PHP CLI 子进程（exec/shell_exec/proc_open/COM 均不可用）';

    /** @param array{exit_code?:int,output?:string} $result */
    public static function isUnavailable(array $result): bool
    {
        $out = (string) ($result['output'] ?? '');
        if (str_starts_with($out, '无法启动 PHP CLI 子进程')) {
            return true;
        }
        // 宝塔等环境 PHP_BINARY=php-fpm：子进程只会打印 Usage，应回退到 include
        if (stripos($out, 'Usage: php-fpm') !== false || stripos($out, 'php-fpm [') !== false) {
            return true;
        }

        return false;
    }

    public static function canShellFunction(string $function): bool
    {
        if (!\function_exists($function)) {
            return false;
        }
        $disabled = \ini_get('disable_functions');
        if (!\is_string($disabled) || \trim($disabled) === '') {
            return true;
        }
        $list = \array_map(
            static fn (string $fn): string => \strtolower(\trim($fn)),
            \explode(',', $disabled)
        );

        return !\in_array(\strtolower($function), $list, true);
    }

    /** @param array<string, string> $extraEnv */
    private static function prefixEnv(string $cmd, array $extraEnv): string
    {
        $env = $extraEnv;
        $wizard = \in_array(
            \strtolower(\trim((string) \getenv('PIVARK_INSTALL_WIZARD'))),
            ['1', 'true', 'yes'],
            true
        );
        if ($wizard) {
            $env['PIVARK_INSTALL_WIZARD'] = '1';
        }
        if ($env === []) {
            return $cmd;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $prefix = '';
            foreach ($env as $k => $v) {
                $prefix .= 'set ' . $k . '=' . $v . '&& ';
            }

            return $prefix . $cmd;
        }

        $parts = [];
        foreach ($env as $k => $v) {
            $parts[] = $k . '=' . \escapeshellarg($v);
        }

        return implode(' ', $parts) . ' ' . $cmd;
    }

    /**
     * @param array<string, string> $extraEnv
     * @return array{exit_code:int,output:string}|null
     */
    private static function runViaProcOpen(string $php, string $script, array $extraEnv): ?array
    {
        if (!self::canShellFunction('proc_open')) {
            return null;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = [];
        foreach (\getenv() ?: [] as $k => $v) {
            if (\is_string($k) && (\is_string($v) || \is_numeric($v))) {
                $env[$k] = (string) $v;
            }
        }
        $env = \array_merge($env, $extraEnv);
        $wizard = \in_array(
            \strtolower(\trim((string) ($env['PIVARK_INSTALL_WIZARD'] ?? \getenv('PIVARK_INSTALL_WIZARD') ?: ''))),
            ['1', 'true', 'yes'],
            true
        );
        if ($wizard) {
            $env['PIVARK_INSTALL_WIZARD'] = '1';
        }

        $cmd = [$php, $script];
        $proc = @\proc_open($cmd, $descriptors, $pipes, null, $env);
        if (!\is_resource($proc)) {
            return null;
        }
        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]) ?: '';
        $stderr = \stream_get_contents($pipes[2]) ?: '';
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $code = \proc_close($proc);
        $output = trim($stdout . (($stderr !== '' && $stderr !== $stdout) ? "\n" . $stderr : ''));

        return [
            'exit_code' => (int) $code,
            'output'    => $output,
        ];
    }

    /** @return array{exit_code:int,output:string}|null */
    private static function runViaWindowsCom(string $cmd): ?array
    {
        if (PHP_OS_FAMILY !== 'Windows' || !\class_exists('COM')) {
            return null;
        }
        try {
            $shell = new \COM('WScript.Shell');
            $exec  = $shell->Exec('cmd /c ' . $cmd);
            $output = '';
            while ($exec->Status === 0) {
                \usleep(50000);
            }
            while (!$exec->StdOut->AtEndOfStream) {
                $output .= $exec->StdOut->ReadLine() . "\n";
            }
            if (!$exec->StdErr->AtEndOfStream) {
                while (!$exec->StdErr->AtEndOfStream) {
                    $output .= $exec->StdErr->ReadLine() . "\n";
                }
            }

            return [
                'exit_code' => (int) $exec->ExitCode,
                'output'    => \trim($output),
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
