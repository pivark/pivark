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
 * 后台静态资源直出（/static/admin/… → public/static/admin/…）
 * 用于 IIS 或未配置 public 前缀重写时，避免图片/脚本 404。
 */
final class AdminStaticAsset
{
    public static function tryServeHttpAsset(string $uri): bool
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, '/static/admin/')) {
            return false;
        }

        $relative = ltrim(substr($path, strlen('/static/admin/')), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            return false;
        }

        $file = ROOT_PATH . 'public/static/admin/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($file)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not Found';

            return true;
        }

        self::emitPublicFile($file);

        return true;
    }

    /** 生成可直链的绝对 URL（用于后台说明 HTML 中的插图） */
    public static function url(string $relativePath): string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $path         = '/static/admin/' . $relativePath;

        return rtrim(SiteUrl::configuredPublicHome(), '/') . $path;
    }

    public static function emitPublicFile(string $file): void
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

        // 宝塔等用 error_page 404 内部转到 index.php 时，若不显式 200，浏览器会拿到「有正文但状态 404」并拒跑 ES module → 后台白屏。
        // 注：部分面板会在 nginx 层锁死 404，此时仍须站点根 /static→public/static 软链或伪静态 alias。
        if (!headers_sent()) {
            header('HTTP/1.1 200 OK', true, 200);
        }
        http_response_code(200);
        header('Content-Type: ' . ($map[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . (string) $size);
        header('Last-Modified: ' . $last);
        header('ETag: ' . $etag);
        header('Cache-Control: ' . self::cacheControlForFile($file));

        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            return;
        }

        readfile($file);
    }

    /**
     * dist 带内容 hash 的产物可长期 immutable；入口壳（index / _app.config）必须短缓存。
     */
    public static function cacheControlForFile(string $file): string
    {
        $norm = str_replace('\\', '/', $file);
        $base = basename($norm);

        if ($base === 'index.html' || $base === '_app.config.js') {
            return 'no-cache, no-store, must-revalidate';
        }

        // Vite 产物：name-HASH.ext 或 name-HASH.js.br
        if (preg_match('/-[A-Za-z0-9_-]{6,}\.(?:js|css|mjs|woff2?|ttf|svg|png|jpe?g|gif|webp|ico)(?:\.(?:br|gz))?$/i', $base)) {
            return 'public, max-age=31536000, immutable';
        }

        // 其它 /static/admin/ 资源（说明图等）
        return 'public, max-age=86400';
    }
}
