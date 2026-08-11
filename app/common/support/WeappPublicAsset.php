<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use think\facade\Request;

/**
 * 插件包内静态资源（/weapp/{identifier}/assets/… → weapp/{identifier}/assets/…）
 *
 * 插图 SSOT：weapp/{id}/assets/guide/。Docsify 知识库不进内核运行态；
 * 开发仓插图同步用 npm run docs:sync-weapp-svgs。
 */
final class WeappPublicAsset
{
    public static function tryServeHttpAsset(string $uri): bool
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || !preg_match('#^/weapp/([a-z][a-z0-9_-]{0,49})/(.+)$#', $path, $m)) {
            return false;
        }

        $identifier = $m[1];
        $relative   = $m[2];
        if ($relative === '' || str_contains($relative, '..')) {
            return false;
        }

        $root = defined('ROOT_PATH') ? ROOT_PATH : (dirname(__DIR__, 3) . DIRECTORY_SEPARATOR);
        $file = $root . 'weapp/' . $identifier . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($file)) {
            return false;
        }

        AdminStaticAsset::emitPublicFile($file);

        return true;
    }

    /** @return string 站点可访问 URL（含 scheme+host，避免后台 Vite 端口下 /weapp 裂图） */
    public static function url(string $identifier, string $relativePath): string
    {
        $identifier   = preg_replace('/[^a-z0-9_-]/', '', strtolower($identifier)) ?? '';
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

        return self::absoluteAssetUrl('/weapp/' . $identifier . '/' . $relativePath);
    }

    /** 根路径资源 → 绝对 URL（后台 SPA / Vite dev 跨端口可加载；协议跟 site_url / site_force_https） */
    public static function absoluteAssetUrl(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }
        $schemeIn = strtolower((string) (parse_url($path, PHP_URL_SCHEME) ?? ''));
        if ($schemeIn === 'http' || $schemeIn === 'https') {
            return $path;
        }
        if (str_starts_with($path, '//')) {
            return SiteUrl::publicScheme() . ':' . $path;
        }

        $path = '/' . ltrim($path, '/');

        if (PHP_SAPI !== 'cli') {
            $requestHost = strtolower(trim((string) (Request::host() ?: '')));
            if ($requestHost !== '') {
                $configuredHost = '';
                $home = trim(SiteUrl::configuredPublicHome());
                if ($home !== '' && preg_match('#^https?://([^/]+)#i', $home, $m)) {
                    $configuredHost = strtolower((string) ($m[1] ?? ''));
                }
                // 非 canonical 主机（本地 lane / Vite 反代）：跟当前请求主机+协议。
                // 禁拿 site_url 的 https 盖到仅有 http 的 staging（否则图标裂成 https://b... 不可达）。
                if ($configuredHost !== '' && $configuredHost !== $requestHost) {
                    $scheme = Request::isSsl() ? 'https' : 'http';

                    return $scheme . '://' . $requestHost . $path;
                }
            }
        }

        $abs = SiteUrl::absolute($path);
        if (PHP_SAPI === 'cli') {
            $schemeIn = strtolower((string) (parse_url($abs, PHP_URL_SCHEME) ?? ''));
            if ($schemeIn !== 'http' && $schemeIn !== 'https') {
                $host = trim((string) (getenv('PIVARK_PUBLIC_HOST') ?: 'localhost'));

                return SiteUrl::publicScheme() . '://' . $host . $path;
            }
        }

        return $abs;
    }

    /**
     * 功能介绍 / 前台调用说明 HTML 内插图路径规范化。
     * 相对文件名 → 绝对 URL（/weapp/{id}/assets/guide/{file}）
     */
    public static function normalizeDocHtml(string $html, string $identifier): string
    {
        if ($html === '' || $identifier === '') {
            return $html;
        }

        $identifier = preg_replace('/[^a-z0-9_-]/', '', strtolower($identifier)) ?? '';
        if ($identifier === '') {
            return $html;
        }

        if (str_contains($html, '{{GUIDE_IMG_BASE}}')) {
            $html = str_replace('{{GUIDE_IMG_BASE}}', self::url($identifier, 'assets/guide/'), $html);
        }

        return (string) preg_replace_callback(
            '/(<img\b[^>]*\ssrc\s*=\s*)(["\'])([^"\']+)\2/i',
            static function (array $m) use ($identifier): string {
                $src = (string) ($m[3] ?? '');
                $resolved = self::resolveGuideImgSrc($identifier, $src);
                if ($resolved === $src) {
                    return $m[0];
                }

                return $m[1] . $m[2] . $resolved . $m[2];
            },
            $html
        );
    }

    /**
     * 解析 guide 插图 src 为绝对 URL。
     * 只认 /weapp/{id}/… 与相对文件名；其它站点根路径原样绝对化（历史知识库链接不再改写/拷贝）。
     */
    public static function resolveGuideImgSrc(string $identifier, string $src): string
    {
        $src = trim($src);
        if ($src === '' || str_starts_with($src, 'data:')) {
            return $src;
        }

        // 绝对链：只剥本站 /weapp/{id}/… 路径；http 与 https 均认（协议不写死）
        $scheme = strtolower((string) (parse_url($src, PHP_URL_SCHEME) ?? ''));
        $urlPath = parse_url($src, PHP_URL_PATH);
        if ($scheme === 'http' || $scheme === 'https') {
            if (is_string($urlPath)
                && preg_match('#^/weapp/' . preg_quote($identifier, '#') . '/.+$#i', $urlPath)
            ) {
                $src = $urlPath;
            } else {
                return $src;
            }
        }

        $file = '';
        if (preg_match('#^/weapp/' . preg_quote($identifier, '#') . '/assets/guide/(.+)$#i', $src, $m)) {
            $file = ltrim(str_replace('\\', '/', (string) ($m[1] ?? '')), '/');
        } elseif (!str_starts_with($src, '/')) {
            $file = ltrim(str_replace('\\', '/', $src), './');
        } else {
            return self::absoluteAssetUrl($src);
        }

        if ($file === '' || str_contains($file, '..')) {
            return $src;
        }

        return self::absoluteAssetUrl('/weapp/' . $identifier . '/assets/guide/' . $file);
    }

    /**
     * 落库用：插图只存站点根相对路径 /weapp/{id}/…，禁止写死绝对域名。
     */
    public static function toSiteRelativeDocHtml(string $html, string $identifier): string
    {
        if ($html === '' || $identifier === '') {
            return $html;
        }
        $identifier = preg_replace('/[^a-z0-9_-]/', '', strtolower($identifier)) ?? '';
        if ($identifier === '') {
            return $html;
        }

        return (string) preg_replace_callback(
            '/(<img\b[^>]*\ssrc\s*=\s*)(["\'])([^"\']+)\2/i',
            static function (array $m) use ($identifier): string {
                $src = trim((string) ($m[3] ?? ''));
                if ($src === '' || str_starts_with($src, 'data:')) {
                    return $m[0];
                }
                $scheme = strtolower((string) (parse_url($src, PHP_URL_SCHEME) ?? ''));
                $urlPath = parse_url($src, PHP_URL_PATH);
                if (($scheme === 'http' || $scheme === 'https')
                    && is_string($urlPath)
                    && preg_match('#^/weapp/' . preg_quote($identifier, '#') . '/.+$#i', $urlPath)
                ) {
                    $src = $urlPath;
                } elseif ($scheme === 'http' || $scheme === 'https') {
                    return $m[0];
                } elseif (!str_starts_with($src, '/')) {
                    $file = ltrim(str_replace('\\', '/', $src), './');
                    if ($file !== '' && !str_contains($file, '..')) {
                        $src = '/weapp/' . $identifier . '/assets/guide/' . $file;
                    }
                }

                return $m[1] . $m[2] . $src . $m[2];
            },
            $html
        );
    }
}
