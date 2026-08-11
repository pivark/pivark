<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\product;

use app\common\service\media\MediaUrlService;
use app\common\service\plugin\extension\DocumentAddonBridgeAccess;
use app\common\service\theme\ThemeService;

/**
 * 品项封面 URL（可接缩略图扩展）。
 * /uploads 前台输出跟 media_url_mode（MediaUrlService）；禁止再强制拼 site_url。
 */
final class ProductCoverService
{
    public static function resolveUrl(string $pathOrUrl, string $title = ''): string
    {
        $pathOrUrl = trim($pathOrUrl);
        if ($pathOrUrl === '') {
            return '';
        }

        // 已是完整 URL：外链原样；本站 uploads 按 media_url_mode 归一
        if (preg_match('#^https?://#i', $pathOrUrl)) {
            return app(MediaUrlService::class)->formatForStorage($pathOrUrl);
        }

        $path = $pathOrUrl[0] === '/' ? $pathOrUrl : '/' . $pathOrUrl;
        $path = app(ThemeService::class)->remapPublicThemeStaticUrl($path);

        // 前台主题静态资源保持相对路径，避免 site_url 指错 lane 时跨域 404
        if (str_starts_with($path, '/static/')) {
            $abs = rtrim((string) (defined('ROOT_PATH') ? ROOT_PATH : ''), '/\\')
                . DIRECTORY_SEPARATOR . 'public'
                . str_replace('/', DIRECTORY_SEPARATOR, $path);
            // 缺文件勿回假 URL（后台 el-image「加载失败」）；空串由列表占位
            if (!is_file($abs)) {
                return '';
            }
            $renderableId = DocumentAddonBridgeAccess::documentRenderableBridgeIdentifier();
            if ($renderableId !== null) {
                $thumb = (string) DocumentAddonBridgeAccess::invokeOr('', $renderableId, 'resolveProductCoverUrl', [$path, $title]);
                if ($thumb !== '') {
                    return self::normalizeResolved($thumb);
                }
            }

            return $path;
        }

        $renderableId = DocumentAddonBridgeAccess::documentRenderableBridgeIdentifier();
        $thumb = $renderableId !== null
            ? (string) DocumentAddonBridgeAccess::invokeOr('', $renderableId, 'resolveProductCoverUrl', [$path, $title])
            : '';
        if ($thumb !== '') {
            return self::normalizeResolved($thumb);
        }

        return app(MediaUrlService::class)->formatForStorage($path);
    }

    private static function normalizeResolved(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) || str_contains($url, '/uploads/')) {
            return app(MediaUrlService::class)->formatForStorage($url);
        }

        return $url[0] === '/' ? $url : '/' . $url;
    }
}
