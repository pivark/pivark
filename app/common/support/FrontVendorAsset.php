<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use app\common\service\theme\ThemeService;

/** 前台第三方静态资源：优先主题 vendor 目录，CDN 作回退 */
class FrontVendorAsset
{
    private const BOOTSTRAP_VER = '5.3.8';
    private const ICONS_VER     = '1.11.3';

    public static function bootstrapCss(?string $theme = null): string
    {
        return self::resolve(
            $theme,
            'vendor/bootstrap.min.css',
            'https://cdn.jsdelivr.net/npm/bootstrap@' . self::BOOTSTRAP_VER . '/dist/css/bootstrap.min.css'
        );
    }

    public static function bootstrapJs(?string $theme = null): string
    {
        return self::resolve(
            $theme,
            'vendor/bootstrap.bundle.min.js',
            'https://cdn.jsdelivr.net/npm/bootstrap@' . self::BOOTSTRAP_VER . '/dist/js/bootstrap.bundle.min.js'
        );
    }

    public static function bootstrapIconsCss(?string $theme = null): string
    {
        return self::resolve(
            $theme,
            'vendor/bootstrap-icons.min.css',
            'https://cdn.jsdelivr.net/npm/bootstrap-icons@' . self::ICONS_VER . '/font/bootstrap-icons.min.css'
        );
    }

    /** 当主 href 为 CDN 时，onerror 切换到本地 vendor（若存在） */
    public static function fallbackScript(string $elementId, string $localUrl): string
    {
        if ($localUrl === '' || !str_starts_with($localUrl, '/')) {
            return '';
        }
        $id  = htmlspecialchars($elementId, ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars($localUrl, ENT_QUOTES, 'UTF-8');

        return '<script>(function(){var el=document.getElementById("' . $id . '");'
            . 'if(!el)return;el.onerror=function(){this.onerror=null;this.href="' . $url . '";};})();</script>';
    }

    /** 主资源已是本地路径时不输出 fallback（避免 head 重复 URL） */
    public static function fallbackScriptIfRemote(string $primaryUrl, string $elementId, string $localUrl): string
    {
        if (!self::isRemoteAssetUrl($primaryUrl)) {
            return '';
        }

        return self::fallbackScript($elementId, $localUrl);
    }

    private static function isRemoteAssetUrl(string $url): bool
    {
        return str_starts_with($url, 'https://') || str_starts_with($url, 'http://');
    }

    private static function resolve(?string $theme, string $relative, string $cdn): string
    {
        $theme = $theme !== null && $theme !== '' ? $theme : app(ThemeService::class)->getCurrentTheme();
        $abs   = app(ThemeService::class)->resolveAssetAbsolutePath($theme, $relative);
        if ($abs !== null && is_file($abs)) {
            return app(ThemeService::class)->themeAssetUrlPrefix($theme) . '/' . str_replace('\\', '/', $relative);
        }

        return $cdn;
    }

    private static function resolveMemberPack(?string $packId, string $relative, string $cdn): string
    {
        $packId = app(ThemeService::class)->validateMemberTheme($packId ?? app(ThemeService::class)->getCurrentMemberTheme());
        if (app(ThemeService::class)->resolveMemberAssetAbsolutePath($packId, $relative) !== null) {
            return app(ThemeService::class)->memberAssetUrlPrefix($packId) . '/' . str_replace('\\', '/', $relative);
        }

        return $cdn;
    }

    /** @return array<string, string> 会员中心模板 Bootstrap vendor */
    public static function memberTemplateVars(?string $packId = null): array
    {
        $packId   = app(ThemeService::class)->validateMemberTheme($packId ?? app(ThemeService::class)->getCurrentMemberTheme());
        $prefix   = app(ThemeService::class)->memberAssetUrlPrefix($packId);
        $localCss = $prefix . '/vendor/bootstrap.min.css';
        $localJs  = $prefix . '/vendor/bootstrap.bundle.min.js';
        $localIco = $prefix . '/vendor/bootstrap-icons.min.css';

        $cssUrl  = self::resolveMemberPack($packId, 'vendor/bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@' . self::BOOTSTRAP_VER . '/dist/css/bootstrap.min.css');
        $iconsUrl = self::resolveMemberPack($packId, 'vendor/bootstrap-icons.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@' . self::ICONS_VER . '/font/bootstrap-icons.min.css');

        return [
            'vendor_bootstrap_css'            => $cssUrl,
            'vendor_bootstrap_js'             => self::resolveMemberPack($packId, 'vendor/bootstrap.bundle.min.js', 'https://cdn.jsdelivr.net/npm/bootstrap@' . self::BOOTSTRAP_VER . '/dist/js/bootstrap.bundle.min.js'),
            'vendor_bootstrap_icons'          => $iconsUrl,
            'vendor_bootstrap_css_fallback'   => self::fallbackScriptIfRemote($cssUrl, 'pv-vendor-bs-css', $localCss),
            'vendor_bootstrap_icons_fallback' => self::fallbackScriptIfRemote($iconsUrl, 'pv-vendor-bs-icons', $localIco),
        ];
    }

    /** @return array<string, string> TemplateEngine 全局变量 */
    public static function templateVars(?string $theme = null): array
    {
        $theme    = $theme !== null && $theme !== '' ? $theme : app(ThemeService::class)->getCurrentTheme();
        $prefix   = app(ThemeService::class)->themeAssetUrlPrefix($theme);
        $localCss = $prefix . '/vendor/bootstrap.min.css';
        $localJs  = $prefix . '/vendor/bootstrap.bundle.min.js';
        $localIco = $prefix . '/vendor/bootstrap-icons.min.css';

        $cssUrl   = self::bootstrapCss($theme);
        $iconsUrl = self::bootstrapIconsCss($theme);

        return [
            'vendor_bootstrap_css'   => $cssUrl,
            'vendor_bootstrap_js'    => self::bootstrapJs($theme),
            'vendor_bootstrap_icons' => $iconsUrl,
            'vendor_bootstrap_css_fallback'   => self::fallbackScriptIfRemote($cssUrl, 'pv-vendor-bs-css', $localCss),
            'vendor_bootstrap_icons_fallback' => self::fallbackScriptIfRemote($iconsUrl, 'pv-vendor-bs-icons', $localIco),
        ];
    }
}
