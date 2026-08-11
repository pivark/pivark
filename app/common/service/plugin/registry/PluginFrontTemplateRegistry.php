<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\registry;

use app\common\service\theme\ThemeService;

/** 插件前台/会员模板根目录（L1 只解析路径，不含业务） */
final class PluginFrontTemplateRegistry
{
    /** @var array<string, string> plugin id => absolute root with trailing slash */
    private static array $rootsByIdentifier = [];

    public function reset(): void
    {
        self::$rootsByIdentifier = [];
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        unset(self::$rootsByIdentifier[$identifier]);
    }

    public function register(string $identifier, string $absoluteRoot): void
    {
        $identifier = strtolower(trim($identifier));
        $absoluteRoot = rtrim(str_replace('\\', '/', $absoluteRoot), '/') . '/';
        if ($identifier === '' || $absoluteRoot === '/') {
            return;
        }
        self::$rootsByIdentifier[$identifier] = $absoluteRoot;
    }

    /** @return list<string> absolute roots with trailing slash */
    public function registeredRoots(): array
    {
        return array_values(self::$rootsByIdentifier);
    }

    /** 站点主题模板：view_shop_* 等 */
    public function resolveSiteTemplate(string $relativePhp, ?string $theme = null): string
    {
        $relativePhp = ltrim(str_replace('\\', '/', $relativePhp), '/');
        if ($relativePhp === '' || str_contains($relativePhp, '..')) {
            return '';
        }
        foreach (self::$rootsByIdentifier as $root) {
            foreach ($this->themeService()->siteTemplateSearchThemes($theme) as $themeId) {
                foreach (['pc', 'm', 'mobile', ''] as $viewport) {
                    $candidates = [];
                    if ($viewport !== '') {
                        $candidates[] = $root . 'template/site/' . $themeId . '/' . $viewport . '/' . $relativePhp;
                    }
                    $candidates[] = $root . 'template/site/' . $themeId . '/' . $relativePhp;
                    $candidates[] = $root . 'template/site/default/pc/' . $relativePhp;
                    foreach ($candidates as $path) {
                        if (is_file($path)) {
                            return $path;
                        }
                    }
                }
            }
        }

        return '';
    }

    /** 会员中心：member/shop_orders → shop_orders.php */
    public function resolveMemberTemplate(string $logicalTemplate, ?string $memberTheme = null): string
    {
        $logical = trim(str_replace('\\', '/', $logicalTemplate), '/');
        if (str_starts_with($logical, 'member/')) {
            $logical = substr($logical, 7);
        }
        if ($logical === '' || str_contains($logical, '..')) {
            return '';
        }
        $file = $logical . '.php';
        $memberTheme = $memberTheme ?? $this->themeService()->getCurrentMemberTheme();
        foreach (self::$rootsByIdentifier as $root) {
            $candidates = [
                $root . 'template/member/' . $memberTheme . '/pc/' . $file,
                $root . 'template/member/default/pc/' . $file,
            ];
            foreach ($candidates as $path) {
                if (is_file($path)) {
                    return $path;
                }
            }
        }

        return '';
    }

    /** 门户账户中心：仅 account/* → {pluginRoot}/view/account/{rel}.php（禁止 basename 误吞 developer/layout） */
    public function resolvePortalAccountTemplate(string $view): string
    {
        $view = trim(str_replace('\\', '/', $view), '/');
        if ($view === '' || str_contains($view, '..') || !str_starts_with($view, 'account/')) {
            return '';
        }
        $rel = substr($view, strlen('account/'));
        if ($rel === '' || str_contains($rel, '..')) {
            return '';
        }
        $file = str_replace('/', DIRECTORY_SEPARATOR, $rel) . '.php';
        foreach (self::$rootsByIdentifier as $identifier => $root) {
            $candidates = [
                $root . 'view/account/' . $file,
                $root . 'template/' . $identifier . '/user/pc/account/' . $file,
                $root . 'template/user/pc/account/' . $file,
            ];
            foreach ($candidates as $path) {
                if (is_file($path)) {
                    return $path;
                }
            }
        }

        return '';
    }

    private function themeService(): ThemeService
    {
        return app(ThemeService::class);
    }
}
