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
 * 主题静态资源直出（/static/theme/… → template/{theme}/…）
 * index.php 在 App 启动前调用，须零 DI 依赖。
 */
final class ThemeStaticAsset
{
    private const THEME_DIR = 'template';

    private const MEMBER_THEME_DIR = 'template/member';

    public static function tryServeHttpAsset(string $uri): bool
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }

        if (preg_match('#^/static/theme/member/([a-z0-9][a-z0-9_-]{0,49})/(.+)$#', $path, $m)) {
            $file = self::resolveMemberHttpAssetFile($m[1], rawurldecode($m[2]));
            if ($file === null) {
                return false;
            }
            self::emitStaticFile($file);

            return true;
        }

        if (!preg_match('#^/static/theme/([a-z0-9][a-z0-9_-]{0,49})/(.+)$#', $path, $m)) {
            return false;
        }

        $file = self::resolveAssetAbsolutePath($m[1], rawurldecode($m[2]));
        if ($file === null) {
            return false;
        }

        self::emitStaticFile($file);

        return true;
    }

    public static function resolveAssetAbsolutePath(string $theme, string $relative): ?string
    {
        if (!self::isValidThemeId($theme)) {
            return null;
        }
        $relative = self::normalizeAssetRelative($relative);
        if ($relative === '') {
            return null;
        }

        // 与模板链一致：当前主题 → www → default（www 主题专页资源在非 www 主题下可回退）
        foreach (array_values(array_unique([$theme, 'www', 'default'])) as $themeId) {
            if (!self::isValidThemeId($themeId)) {
                continue;
            }
            $file = self::resolveAssetInTheme($themeId, $relative);
            if ($file !== null) {
                return $file;
            }
        }

        return null;
    }

    private static function resolveAssetInTheme(string $theme, string $relative): ?string
    {
        $viewport = self::detectViewport();
        $base     = ROOT_PATH . self::THEME_DIR . '/' . $theme;
        $roots    = [
            $base . '/' . $viewport . '/assets',
            $base . '/' . ClientViewport::PC . '/assets',
            $base . '/assets',
            ROOT_PATH . 'public/static/theme/' . $theme,
        ];

        return self::firstExistingAsset($roots, $relative);
    }

    private static function resolveMemberHttpAssetFile(string $pack, string $tail): ?string
    {
        $pack = self::validateMemberThemePack($pack);
        $tail = str_replace('\\', '/', $tail);
        $tail = ltrim($tail, '/');
        if ($tail === '' || str_contains($tail, '..')) {
            return null;
        }

        return self::firstExistingAsset([
            ROOT_PATH . self::MEMBER_THEME_DIR . '/' . $pack,
            ROOT_PATH . 'public/static/theme/member/' . $pack,
        ], $tail);
    }

    /** @param list<string> $roots */
    private static function firstExistingAsset(array $roots, string $relative): ?string
    {
        foreach ($roots as $root) {
            $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($candidate)) {
                continue;
            }
            $realFile = realpath($candidate);
            $realRoot = realpath($root);
            if ($realFile === false || $realRoot === false) {
                continue;
            }
            $prefix = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (str_starts_with($realFile, $prefix)) {
                return $realFile;
            }
        }

        return null;
    }

    private static function validateMemberThemePack(string $pack): string
    {
        $pack = trim($pack);
        if ($pack === '' || !self::isValidThemeId($pack)) {
            return 'default';
        }
        if (!is_dir(ROOT_PATH . self::MEMBER_THEME_DIR . '/' . $pack)) {
            return 'default';
        }

        return $pack;
    }

    private static function isValidThemeId(string $id): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,49}$/', $id);
    }

    private static function normalizeAssetRelative(string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        $relative = ltrim($relative, '/');
        if ($relative === '' || str_contains($relative, '..')) {
            return '';
        }

        return $relative;
    }

    private static function detectViewport(): string
    {
        $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($ua !== '' && preg_match('/mobile|android|iphone|ipod|webos|blackberry|iemobile|opera mini/i', $ua)) {
            return ClientViewport::M;
        }

        return ClientViewport::PC;
    }

    private static function emitStaticFile(string $file): void
    {
        $size  = filesize($file) ?: 0;
        $mtime = filemtime($file) ?: time();
        $etag  = '"' . dechex($mtime) . '-' . dechex((int) $size) . '"';
        $last  = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        $ext   = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        $map = [
            'css'  => 'text/css; charset=utf-8',
            'js'   => 'application/javascript; charset=utf-8',
            'svg'  => 'image/svg+xml',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'ico'  => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2'=> 'font/woff2',
            'ttf'  => 'font/ttf',
            'map'  => 'application/json; charset=utf-8',
        ];

        // 与 AdminStaticAsset 同因：error_page 404→PHP 时须显式 200，否则 CSS/字体状态码仍 404。
        if (!headers_sent()) {
            header('HTTP/1.1 200 OK', true, 200);
        }
        http_response_code(200);
        header('Content-Type: ' . ($map[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . (string) $size);
        header('Last-Modified: ' . $last);
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=31536000, immutable');

        $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            http_response_code(304);

            return;
        }

        $ifModifiedSince = (string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
        if ($ifModifiedSince !== '' && strtotime($ifModifiedSince) !== false && strtotime($ifModifiedSince) >= $mtime) {
            http_response_code(304);

            return;
        }

        readfile($file);
    }
}
