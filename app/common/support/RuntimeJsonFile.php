<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/** runtime 目录 JSON 读写（排他锁，防多 worker 覆盖） */
final class RuntimeJsonFile
{
    /**
     * @param array<string, mixed> $default
     * @return array<string, mixed>
     */
    public static function read(string $path, array $default = []): array
    {
        if (!is_file($path)) {
            return $default;
        }
        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) ? $json : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function write(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            LocalFile::mkdirIfMissing($dir);
        }

        $handle = fopen($path, 'c+');
        if ($handle === false) {
            LocalFile::putContents(
                $path,
                json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            );

            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }
            $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (!is_string($payload)) {
                return;
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $payload);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * 在排他锁内读→改→写，避免 load/save TOCTOU。
     *
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     * @param array<string, mixed> $default
     * @return array<string, mixed>
     */
    public static function update(string $path, callable $mutator, array $default = []): array
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            LocalFile::mkdirIfMissing($dir);
        }

        $handle = fopen($path, 'c+');
        if ($handle === false) {
            $data = is_file($path) ? self::read($path, $default) : $default;
            $next = $mutator($data);
            if (!is_array($next)) {
                $next = $default;
            }
            self::write($path, $next);

            return $next;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return $default;
            }
            rewind($handle);
            $raw  = stream_get_contents($handle);
            $data = is_string($raw) && $raw !== ''
                ? (json_decode($raw, true) ?: $default)
                : $default;
            if (!is_array($data)) {
                $data = $default;
            }
            $next = $mutator($data);
            if (!is_array($next)) {
                $next = $default;
            }
            $payload = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (is_string($payload)) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, $payload);
                fflush($handle);
            }
            flock($handle, LOCK_UN);

            return $next;
        } finally {
            fclose($handle);
        }
    }
}
