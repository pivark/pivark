<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/** 本地文件操作（显式判断，替代 @ 抑制符） */
final class LocalFile
{
    public static function unlinkIfExists(string $path): bool
    {
        if ($path === '' || !is_file($path)) {
            return false;
        }

        return unlink($path);
    }

    public static function rmdirIfExists(string $dir): bool
    {
        if ($dir === '' || !is_dir($dir)) {
            return false;
        }

        return rmdir($dir);
    }

    public static function mkdirIfMissing(string $dir, int $mode = 0755): bool
    {
        if ($dir === '' || is_dir($dir)) {
            return is_dir($dir);
        }

        return mkdir($dir, $mode, true) || is_dir($dir);
    }

    public static function putContents(string $path, string $data, int $flags = 0): bool
    {
        if ($path === '') {
            return false;
        }

        return file_put_contents($path, $data, $flags) !== false;
    }

    /**
     * @param resource|null $context
     * @param list<string>|null $httpResponseHeader 传入变量可收回 HTTP 响应头（file_get_contents 语义）
     */
    public static function getContents(
        string $path,
        bool $useIncludePath = false,
        $context = null,
        ?array &$httpResponseHeader = null
    ): ?string {
        if ($path === '') {
            return null;
        }
        $data = $context !== null
            ? file_get_contents($path, $useIncludePath, $context)
            : file_get_contents($path, $useIncludePath);
        if (func_num_args() >= 4) {
            $httpResponseHeader = isset($http_response_header) && is_array($http_response_header)
                ? $http_response_header
                : [];
        }

        return $data === false ? null : $data;
    }

    /**
     * @param resource|null $context
     */
    public static function getContentsLimited(
        string $path,
        int $maxBytes,
        bool $useIncludePath = false,
        $context = null,
        string $logContext = '',
    ): ?string {
        if ($path === '' || $maxBytes < 1) {
            return null;
        }
        $data = $context !== null
            ? file_get_contents($path, $useIncludePath, $context, 0, $maxBytes)
            : file_get_contents($path, $useIncludePath, null, 0, $maxBytes);
        if ($data === false) {
            error_log('[PivArk LocalFile] read failed: ' . $path . ($logContext !== '' ? ' (' . $logContext . ')' : ''));

            return null;
        }

        return $data;
    }

    public static function renameQuiet(string $src, string $dst, string $context = ''): bool
    {
        if ($src === '' || $dst === '' || !is_file($src)) {
            return false;
        }
        if (rename($src, $dst)) {
            return true;
        }
        error_log('[PivArk LocalFile] rename failed: ' . $src . ' -> ' . $dst . ($context !== '' ? ' (' . $context . ')' : ''));

        return false;
    }

    public static function copyQuiet(string $src, string $dst, string $context = ''): bool
    {
        if ($src === '' || $dst === '' || !is_file($src)) {
            return false;
        }
        self::mkdirIfMissing(dirname($dst));
        if (copy($src, $dst)) {
            return true;
        }
        error_log('[PivArk LocalFile] copy failed: ' . $src . ' -> ' . $dst . ($context !== '' ? ' (' . $context . ')' : ''));

        return false;
    }

    public static function unlinkQuiet(string $path, string $context = ''): bool
    {
        if ($path === '' || !is_file($path)) {
            return false;
        }
        if (unlink($path)) {
            return true;
        }
        error_log('[PivArk LocalFile] unlink failed: ' . $path . ($context !== '' ? ' (' . $context . ')' : ''));

        return false;
    }

    public static function rmdirQuiet(string $dir, string $context = ''): bool
    {
        if ($dir === '' || !is_dir($dir)) {
            return false;
        }
        if (rmdir($dir)) {
            return true;
        }
        error_log('[PivArk LocalFile] rmdir failed: ' . $dir . ($context !== '' ? ' (' . $context . ')' : ''));

        return false;
    }

    public static function removeDirRecursive(string $dir, string $context = ''): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            if ($file->isDir()) {
                self::rmdirQuiet($file->getPathname(), $context);
            } else {
                self::unlinkQuiet($file->getPathname(), $context);
            }
        }
        self::rmdirQuiet($dir, $context);
    }
}
