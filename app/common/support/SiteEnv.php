<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

final class SiteEnv
{
    public const REL = 'data' . DIRECTORY_SEPARATOR . 'site.env';

    public const LEGACY_REL = '.env';

    public static function filePath(string $root): string
    {
        return rtrim(str_replace('\\', '/', $root), '/') . '/' . str_replace('\\', '/', self::REL);
    }

    /** 优先 data/site.env，其次根 .env（仅 dev 兼容） */
    public static function resolvePath(string $root): ?string
    {
        $site = self::filePath($root);
        if (is_file($site)) {
            return $site;
        }
        $legacy = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . self::LEGACY_REL;
        if (is_file($legacy)) {
            return $legacy;
        }

        return null;
    }

    /**
     * 须在 new think\App() 之前调用（Env 构造器快照 $_ENV）。
     */
    public static function injectIntoProcessEnv(string $root): void
    {
        $path = self::resolvePath($root);
        if ($path !== null) {
            $parsed = parse_ini_file($path, true, INI_SCANNER_RAW);
            if (is_array($parsed)) {
                foreach ($parsed as $key => $value) {
                    if (!is_string($key) || is_array($value)) {
                        continue;
                    }
                    $key = strtoupper($key);
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                    if (\function_exists('putenv')) {
                        \putenv($key . '=' . $value);
                    }
                    // 与 config/pivark.php「常量优先」对齐：site.env 写入后同步 define，避免 edition 仍落 community
                    if ($key === 'PIVARK_EDITION' && is_string($value) && $value !== '' && !defined('PIVARK_EDITION')) {
                        define('PIVARK_EDITION', strtolower(trim($value)));
                    }
                }
            }
        }

        ExtendBootstrap::loadUserFunctions($root);
    }

    /**
     * @param array<string, string> $db
     */
    public static function writeCommunityInstall(string $root, array $db): void
    {
        $path = self::filePath($root);
        $dir  = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建 data 目录');
        }

        $lines = [
            'DB_HOST=' . self::escapeValue($db['db_host'] ?? '127.0.0.1'),
            'DB_PORT=' . self::escapeValue($db['db_port'] ?? '3306'),
            'DB_NAME=' . self::escapeValue($db['db_name'] ?? ''),
            'DB_USER=' . self::escapeValue($db['db_user'] ?? ''),
            'DB_PASS=' . self::escapeValue($db['db_pass'] ?? ''),
            'DB_PREFIX=' . self::escapeValue($db['db_prefix'] ?? 'pv_'),
            '',
            'PIVARK_EDITION=community',
            'APP_DEBUG=0',
            'PIVARK_PLUGIN_EMERGENCY_TOKEN=' . self::escapeValue(bin2hex(random_bytes(16))),
        ];

        if (file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('无法写入 ' . self::REL);
        }

        $legacy = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . self::LEGACY_REL;
        if (is_file($legacy)) {
            @unlink($legacy);
        }
    }

    private static function escapeValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/[\s#="\']/', $value) === 1) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        return $value;
    }

    public static function upsertKey(string $root, string $key, string $value): void
    {
        $path = self::filePath($root);
        if (!is_file($path)) {
            throw new \RuntimeException('无法更新 ' . self::REL . '：文件不存在');
        }

        $key = strtoupper(trim($key));
        $line = $key . '=' . self::escapeValue($value);
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException('无法读取 ' . self::REL);
        }

        $found = false;
        foreach ($lines as $i => $row) {
            if (str_starts_with(trim((string) $row), $key . '=')) {
                $lines[$i] = $line;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $lines[] = $line;
        }

        if (file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('无法写入 ' . self::REL);
        }
    }
}
