<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/**
 * 受信 shell 子进程唯一入口（禁止业务层裸 exec/passthru）。
 * 调用方须自行 escapeshellarg；本类只做能力检测与 MySQL 凭据临时文件。
 */
final class TrustedShellRunner
{
    /**
     * @return array{exit_code:int,output:string}
     */
    public static function execCaptured(string $cmd): array
    {
        if (!ShellExec::isAvailable()) {
            return [
                'exit_code' => 127,
                'output'    => ShellExec::unavailableMessage('Shell'),
            ];
        }
        $lines    = [];
        $exitCode = 1;
        \exec($cmd, $lines, $exitCode);

        return [
            'exit_code' => (int) $exitCode,
            'output'    => implode("\n", $lines),
        ];
    }

    public static function passthru(string $cmd): int
    {
        if (!self::canPassthru()) {
            return 127;
        }
        $exitCode = 1;
        \passthru($cmd, $exitCode);

        return (int) $exitCode;
    }

    public static function canPassthru(): bool
    {
        return CliProcessRunner::canShellFunction('passthru');
    }

    /**
     * PATH 解析二进制（仅 dev 等场景；生产请用 PIVARK_*_PATH + resolveTrustedBinary）。
     */
    public static function resolveBinaryOnPath(string $bin): ?string
    {
        if (!ShellExec::isAvailable()) {
            return null;
        }
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $cmd       = $isWindows ? 'where ' . $bin . ' 2>nul' : 'which ' . $bin . ' 2>/dev/null';
        $result    = self::execCaptured($cmd);
        if ($result['exit_code'] !== 0 || $result['output'] === '') {
            return null;
        }
        $path = trim(explode("\n", $result['output'])[0] ?? '');
        if ($path === '') {
            return null;
        }
        if ($isWindows && !is_file($path)) {
            return null;
        }

        return $path;
    }

    /**
     * @param list<string> $allowedBasenames 如 mysqldump / mysqldump.exe
     */
    public static function resolveTrustedBinary(mixed $configuredPath, array $allowedBasenames, ?string $pathFallback = null): ?string
    {
        $configuredPath = is_string($configuredPath) ? trim($configuredPath) : '';
        if ($configuredPath !== '') {
            $real = realpath($configuredPath);
            if ($real !== false && is_file($real)) {
                $base = strtolower(basename($real));
                foreach ($allowedBasenames as $allowed) {
                    if ($base === strtolower($allowed)) {
                        return $real;
                    }
                }
            }
        }
        if ($pathFallback === null || $pathFallback === '') {
            return null;
        }

        return self::resolveBinaryOnPath($pathFallback);
    }

    /**
     * 用 --defaults-extra-file 传 MySQL 凭据（避免 MYSQL_PWD / 命令行 -p）。
     *
     * @param array<string, scalar|null> $clientSection host/port/user/password 等
     * @param callable(string $cnfPath): mixed $runner
     */
    public static function withMysqlDefaultsExtraFile(array $clientSection, callable $runner): mixed
    {
        $cnfPath = self::writeMysqlDefaultsCnf($clientSection);
        try {
            return $runner($cnfPath);
        } finally {
            LocalFile::unlinkIfExists($cnfPath);
        }
    }

    /**
     * @param array<string, scalar|null> $clientSection
     */
    private static function writeMysqlDefaultsCnf(array $clientSection): string
    {
        $lines = ['[client]'];
        foreach ($clientSection as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                continue;
            }
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $text = trim((string) ($value ?? ''));
            if ($text === '') {
                continue;
            }
            $lines[] = $key . '=' . self::cnfEscape($text);
        }
        $path = tempnam(sys_get_temp_dir(), 'pv_mycnf_');
        if ($path === false) {
            throw new \RuntimeException('无法创建 MySQL 凭据临时文件');
        }
        @chmod($path, 0600);
        if (file_put_contents($path, implode("\n", $lines) . "\n") === false) {
            LocalFile::unlinkIfExists($path);
            throw new \RuntimeException('无法写入 MySQL 凭据临时文件');
        }

        return $path;
    }

    private static function cnfEscape(string $value): string
    {
        if (preg_match('/[\s#"\';=\\\\]/', $value)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        return $value;
    }
}
