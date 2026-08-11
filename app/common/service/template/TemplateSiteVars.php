<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);


namespace app\common\service\template;

use app\common\service\plugin\extension\PluginOfficialProduct;

use app\common\service\plugin\extension\PluginPortalInvoke;

use app\common\service\plugin\extension\DocumentAddonBridgeAccess;

use app\common\service\product\ProductCatalogSidebarService;
use app\common\service\theme\ThemeService;
use app\common\support\ProjectPaths;
use app\common\support\OpsLog;
use app\common\support\SiteDomainContext;
use app\common\support\PvPublicAsset;
use app\common\support\SiteUrl;
use app\common\service\front\FrontCsrfService;
use app\common\service\front\FrontScriptUrlMap;
use app\common\service\front\FrontAuthService;
use app\common\service\site\FloatContactTemplateTagService;
use app\common\service\release\CoreUpdateRemoteService;
use app\common\service\site\SiteBrandService;
use app\common\service\site\SiteOverlayAdService;
/**
 * 前台 {pv:*} 标签模板引擎（纯标签解析，不使用 eval）
 */

/** 站点/会员/SEO 模板变量 */
class TemplateSiteVars
{

    /** @var array<string, mixed>|null */
    private static ?array $siteVarsCache = null;

    private static ?string $loadedThemeSiteVarsLib = null;

    /** 每 HTTP 请求开头调用，避免 PHP-FPM worker 内会员态/CSRF 串号 */
    public function forgetRequestCache(): void
    {
        self::$siteVarsCache = null;
        self::$loadedThemeSiteVarsLib = null;
            app(FloatContactTemplateTagService::class)->forgetRequestCache();
    }

    /**
     * parse / 页缓存命中时仅需注入的每请求变量（CSRF 等）
     *
     * @return array<string, mixed>
     */
    public function volatileSiteVars(): array
    {
        return [
            'front_csrf_token' => app(FrontCsrfService::class)->token(),
            'front_csrf_field' => app(FrontCsrfService::class)->fieldName(),
        ];
    }

    /** @return array<string, mixed> */
    private function overlayAdSiteVars(): array
    {
        try {
            $html = app(SiteOverlayAdService::class)->renderForCurrentPage();

            return [
                'site_overlay_ads_html'    => $html,
                'site_overlay_ads_enabled' => $html !== '' ? 1 : 0,
            ];
        } catch (\Throwable $e) {
            $this->logOptionalDegrade('overlay_ad', $e);

            return [
                'site_overlay_ads_html'    => '',
                'site_overlay_ads_enabled' => 0,
            ];
        }
    }

    /** @return array<string, mixed> */
    public function siteVars(): array
    {
        if (self::$siteVarsCache !== null) {
            return self::$siteVarsCache;
        }
        $cfg = app(\app\common\service\config\ConfigService::class)->getAll();
        $theme = app(\app\common\service\theme\ThemeService::class)->getCurrentTheme();
        $currentPath = app(\app\common\service\site\SiteNavService::class)->currentPath();
        $flags = $this->themeSiteVarsFlags($theme, $currentPath);
        $useLite = (bool) ($flags['use_lite'] ?? false);
        if (!empty($flags['skip_home_slides'])) {
            $slides = [];
            $hero   = null;
        } else {
            $slides = app(\app\common\service\site\SiteSlideService::class)->listPublic(\app\common\service\site\SiteSlideService::SLOT_HOME_CAROUSEL);
            $hero   = app(\app\common\service\site\SiteSlideService::class)->findPublicSingle(\app\common\service\site\SiteSlideService::SLOT_HOME_HERO);
        }
        $navTree = app(\app\common\service\site\SiteNavService::class)->listPublicTreeWithActive($currentPath);
        $footerNavTree = $this->themeSiteVarsFooterNav($theme) ?? [];
        $siteThemes = $this->themeSiteVarsSiteThemes($theme, $currentPath)
            ?? $this->siteThemesForHome($currentPath);
        $urlOverrides = $this->themeSiteVarsUrlOverrides($theme);
        $memberLoggedIn = app(FrontAuthService::class)->isLoggedIn();
        $productFrontOpen = \app\common\service\product\ProductCenterGateService::publicSurfaceOpen();
        $productSidebar = !$productFrontOpen
            ? [
                'url_product_catalog'         => '',
                'product_catalog_tags'        => [],
                'product_catalog_tag_tree'    => [],
                'product_catalog_nav_html'    => '',
                'product_catalog_tags_empty'  => 1,
                'tag_slug'                    => '',
            ]
            : (!empty($flags['product_sidebar_lite'])
                ? ($this->themeSiteVarsProductSidebar($theme) ?? app(ProductCatalogSidebarService::class)->sidebarVars())
                : app(ProductCatalogSidebarService::class)->sidebarVars());
        $homeSeoExtras = $this->themeSiteVarsHomeSeo($theme, $currentPath);
        $homeBlocks = app(HomeBlockSiteVars::class)->vars($cfg);

        return self::$siteVarsCache = array_merge([
            'site_name'        => app(\app\common\service\site\SiteBrandService::class)->resolveSiteNameForTemplate(
                (string) ($cfg['site_name'] ?? ''),
                (string) ($cfg['site_title'] ?? '')
            ),
            'site_title'       => $cfg['site_title'] ?? '',
            'site_logo'        => $cfg['site_logo'] ?? '',
            'site_logo_hero'   => $cfg['site_logo_hero'] ?? '',
            'site_url'         => $cfg['site_url'] ?? '',
            'site_keywords'    => $cfg['site_keywords'] ?? '',
            'site_description' => $cfg['site_description'] ?? '',
            'site_copyright'   => $this->resolveSiteCopyrightSafe($cfg),
            'pivark_brand_required' => $this->pivarkBrandRequiredSafe(),
            'pivark_powered_by'     => $this->pivarkPoweredBySafe(),
            'pivark_version'        => app(CoreUpdateRemoteService::class)->currentVersion(),
            'pivark_version_label'  => 'v' . app(CoreUpdateRemoteService::class)->currentVersion(),
            'pivark_release'        => defined('PIVARK_RELEASE') ? trim((string) PIVARK_RELEASE) : '',
            'site_icp'         => $cfg['site_icp'] ?? '',
            'site_police'      => $cfg['site_police'] ?? '',
            'site_third_code'  => $cfg['site_third_code'] ?? '',
            'home_url'         => SiteUrl::home(),
            'admin_url'        => SiteUrl::adminHome(),
            'search_url'       => SiteUrl::search(),
            ...$homeBlocks,
            'url_commerce'          => SiteUrl::commerceMall(),
            'url_commerce_cart'     => SiteUrl::commerceCart(),
            'url_commerce_checkout' => SiteUrl::commerceCheckout(),
            'url_commerce_market'   => SiteUrl::commerceMarket(),
            'url_gallery'      => '/doc_gallery',
            'url_video_plaza'  => DocumentAddonBridgeAccess::frontPlazaPath(),
            'documents_url'     => SiteUrl::documents(),
            'tags_url'         => SiteUrl::tags(),
            'page_urls'        => app(\app\common\service\site\SitePageService::class)->urlMapByTpl(),
            'url_about'        => SiteUrl::pageByTpl('about'),
            'url_devkit'       => app(\app\common\service\site\SitePageService::class)->urlByTpl('list_page_devkit', 'devkit'),
            'url_contact'      => SiteUrl::pageByTpl('contact'),
            'url_culture'      => SiteUrl::pageByTpl('culture'),
            'url_faq'          => SiteUrl::pageByTpl('faq'),
            'url_careers'      => SiteUrl::pageByTpl('careers'),
            'url_partners'     => SiteUrl::pageByTpl('partners'),
            'url_honors'       => SiteUrl::pageByTpl('honors'),
            'url_download'            => SiteUrl::communityDownload(),
            'url_download_external'   => SiteUrl::communityDownloadIsExternal() ? 1 : 0,
            'url_changelog'           => SiteUrl::communityChangelog(),
            'url_partner'      => $urlOverrides['url_partner']
                ?? app(\app\common\service\site\SitePageService::class)->urlByTpl('partner', 'partner'),
            'url_demo'              => SiteUrl::onlineDemo(),
            'url_demo_external'     => SiteUrl::onlineDemoIsExternal() ? 1 : 0,
            'url_templates'    => app(\app\common\service\site\SitePageService::class)->urlByTpl('templates', 'templates'),
            'url_quickstart'   => app(\app\common\service\site\SitePageService::class)->urlByTpl('quickstart', 'quickstart'),
            'url_features'     => $urlOverrides['url_features']
                ?? app(\app\common\service\site\SitePageService::class)->urlByTpl('features', 'features'),
            'url_solutions'    => $urlOverrides['url_solutions']
                ?? app(\app\common\service\site\SitePageService::class)->urlByTpl('solutions', 'solutions'),
            'url_pricing'      => app(\app\common\service\site\SitePageService::class)->urlByTpl('pricing', 'pricing'),
            'url_privacy'      => $this->resolveExistingSitePageUrl(
                ['list_page_privacy', 'privacy'],
                ['privacy', 'yinsi', 'yinsibaohu']
            ),
            'url_terms'        => $this->resolveExistingSitePageUrl(
                ['list_page_terms', 'terms'],
                ['terms', 'falv', 'falvshenming']
            ),
            'url_compliance'   => $this->resolveExistingSitePageUrl(
                ['list_page_compliance', 'compliance'],
                ['compliance']
            ),
            'url_products'     => $urlOverrides['url_products']
                ?? app(\app\common\service\site\SitePageService::class)->urlByTpl('products', 'products'),
            'url_apps_plugins' => (static function () {
                $host = PluginOfficialProduct::dispatch('site_url_apps_plugins', [], null);
                if (is_string($host) && $host !== '') {
                    return $host;
                }

                return app(\app\common\service\site\SitePageService::class)->urlByTpl('list_product_plugins', '/plugins');
            })(),
            'url_apps_miniprogram' => (static function () {
                $host = PluginOfficialProduct::dispatch('site_url_apps_miniprogram', [], null);
                if (is_string($host) && $host !== '') {
                    return $host;
                }

                return app(\app\common\service\site\SitePageService::class)->urlByTpl('list_product_miniprogram', '/miniprogram');
            })(),
            'url_portal'       => app(\app\common\service\site\SitePageService::class)->portalFrontUrl(),
            'url_docs'         => $this->siteUrlFromCustomConfig(
                $cfg,
                'docs_url',
                SiteUrl::docsReader()
            ),
            'url_docs_hub'     => app(\app\common\service\site\SitePageService::class)->urlByTpl('docs', SiteUrl::docsReader()),
            'site_download_community_url' => $urlOverrides['site_download_community_url']
                ?? $this->siteUrlFromCustomConfig(
                    $cfg,
                    'download_community_url',
                    SiteUrl::communityDownload()
                ),
            'site_download_gitee_url' => $urlOverrides['site_download_gitee_url']
                ?? $this->siteUrlFromCustomConfig(
                    $cfg,
                    'download_gitee_url',
                    ''
                ),
            'site_phone'       => $urlOverrides['site_phone']
                ?? $this->contactConfigValue($cfg, 'phone', 'site_phone', ''),
            'site_email'       => $urlOverrides['site_email']
                ?? $this->contactConfigValue($cfg, 'email', 'site_email', ''),
            'site_address'     => $urlOverrides['site_address']
                ?? $this->contactConfigValue($cfg, 'address', 'site_address', ''),
            'site_wechat_qr'   => $this->contactConfigValue($cfg, 'wechat_qr', 'site_wechat_qr', ''),
            'site_wechat_mp_qr'=> $this->contactConfigValue($cfg, 'wechat_mp_qr', 'site_wechat_mp_qr', ''),
            'site_nav'              => $navTree,
            'nav_current_path'      => $currentPath,
            'site_nav_html'         => app(\app\common\service\site\SiteNavService::class)->renderNavbarHtml($navTree, $currentPath),
            'site_footer_nav'       => $footerNavTree,
            'site_footer_nav_empty' => $footerNavTree === [] ? 1 : 0,
            'friend_links'     => app(\app\common\service\site\SiteLinkService::class)->listPublic(),
            'product_front_open' => $productFrontOpen ? 1 : 0,
            'site_slides'       => $slides,
            'site_slides_empty' => $slides === [] ? 1 : 0,
            'site_themes'       => $siteThemes,
            'site_themes_empty' => $siteThemes === [] ? 1 : 0,
            ...$this->themeSiteVarsMerge($theme, $currentPath),
            'site_hero'         => $hero ?? [],
            'site_hero_empty'   => $hero === null ? 1 : 0,
            // tag_nav_groups 已退役；侧栏栏目用 {pv:nav}
            'tag_nav_groups'   => [],
            'site_tag_group_id'=> SiteDomainContext::tagGroupId(),
            'theme_id'         => $theme,
            'theme_asset'      => app(\app\common\service\theme\ThemeService::class)->themeAssetUrlPrefix($theme),
            'theme_asset_ver'  => app(\app\common\service\theme\ThemeService::class)->assetVersion($theme),
            'stats_enabled'    => app(\app\common\service\access\AccessStatsService::class)->isEnabled() ? 1 : 0,
            'front_script_urls_json' => app(FrontScriptUrlMap::class)->jsonForTemplate(),
        ], PvPublicAsset::templateVars(), $productSidebar, $homeSeoExtras, $useLite ? $this->siteMapVarsEmpty() : app(\app\common\service\site\SiteMapService::class)->templateVars(), $this->floatContactSiteVars($useLite), $useLite ? $this->overlayAdSiteVarsEmpty() : $this->overlayAdSiteVars(), ($useLite && !$memberLoggedIn) ? app(MemberFrontSiteVars::class)->guestLite() : app(MemberFrontSiteVars::class)->loggedIn(), $useLite ? app(MemberFrontSiteVars::class)->portalAuthLite() : app(MemberFrontSiteVars::class)->portalAuth(), app(\app\common\service\tag\TagService::class)->templateUrlVars(), \app\common\support\FrontVendorAsset::templateVars($theme));
    }

    /** @return array<string, mixed> */
    private function siteMapVarsEmpty(): array
    {
        return [
            'site_map_enabled'    => 0,
            'site_map_empty'      => 1,
            'site_map_nav_url'    => '',
            'site_map_iframe'     => '',
            'site_map_note'       => '',
            'site_map_note_empty' => 1,
            'site_map_html'       => '',
        ];
    }

    /** @return array<string, mixed> */
    private function overlayAdSiteVarsEmpty(): array
    {
        return [
            'site_overlay_ads_html'    => '',
            'site_overlay_ads_enabled' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function floatContactSiteVars(bool $lite = false): array
    {
        $empty = [
            'float_contact_enabled' => 0,
            'float_contact_html'    => '',
            'float_contact_items'   => [],
        ];
        try {
            $items = $lite ? [] : app(FloatContactTemplateTagService::class)->listItemsForTemplate();
            $html  = app(\app\common\service\site\FloatContactConfigService::class)->isEnabled()
                ? app(\app\common\service\site\FloatContactService::class)->renderWidget()
                : '';

            return [
                'float_contact_enabled' => $html !== '' ? 1 : 0,
                'float_contact_html'    => $html,
                'float_contact_items'   => $items,
            ];
        } catch (\Throwable $e) {
            $this->logOptionalDegrade('float_contact', $e);

            return $empty;
        }
    }

    /**
     * 联系方式：优先自定义变量 cv_{name}_value，其次站点配置键，最后默认值。
     *
     * @param array<string, mixed> $cfg
     */
    private function contactConfigValue(array $cfg, string $cvName, string $siteKey, string $default): string
    {
        $fromCv = trim((string) ($cfg['cv_' . $cvName . '_value'] ?? ''));
        if ($fromCv !== '') {
            return $fromCv;
        }
        $fromSite = trim((string) ($cfg[$siteKey] ?? ''));
        if ($fromSite !== '') {
            return $fromSite;
        }

        return $default;
    }

    /**
     * 仅读自定义变量 cv_{name}_value；无则默认（不读旧站点配置键）。
     *
     * @param array<string, mixed> $cfg
     */
    private function siteUrlFromCustomConfig(
        array $cfg,
        string $customVarName,
        string $default
    ): string {
        $fromCustom = trim((string) ($cfg['cv_' . $customVarName . '_value'] ?? ''));
        if ($fromCustom !== '') {
            return $fromCustom;
        }

        return $default;
    }

    /**
     * 仅当站点页真实存在时返回 URL（tpl 优先，其次 path）；均无则空串。
     *
     * @param list<string> $tplCandidates
     * @param list<string> $pathCandidates
     */
    private function resolveExistingSitePageUrl(array $tplCandidates, array $pathCandidates = []): string
    {
        $svc = app(\app\common\service\site\SitePageService::class);
        foreach ($tplCandidates as $tpl) {
            $tpl = trim((string) $tpl);
            if ($tpl === '') {
                continue;
            }
            $page = $svc->findByTpl($tpl);
            if (is_array($page)) {
                $url = trim((string) ($page['url'] ?? ''));
                if ($url !== '') {
                    return $url;
                }
            }
        }
        foreach ($pathCandidates as $path) {
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }
            $page = $svc->findByPath($path) ?? $svc->findByPathAllowReserved($path);
            if (is_array($page)) {
                $url = trim((string) ($page['url'] ?? ''));
                if ($url !== '') {
                    return $url;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    public function buildSeoMetaVars(array $vars): array
    {
        return app(\app\common\service\seo\SeoTemplateService::class)->buildMetaVars($vars);
    }

    public function stripHtmlComments(string $html): string
    {
        return preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
    }

    /**
     * 门户首页主题条：经 PluginPortalInvoke 调host_only 扩展 ThemeMarketCatalogService（禁内核直引 weapp）。
     *
     * @return list<array<string, mixed>>
     */
    private function siteThemesForHome(string $currentPath): array
    {
        $path = rtrim($currentPath, '/') ?: '/';
        if ($path !== '/') {
            return [];
        }
        if (!PluginPortalInvoke::portalFileExists('ThemeMarketCatalogService')) {
            return [];
        }
        $rows = PluginPortalInvoke::portalInvoke('ThemeMarketCatalogService', 'themesForHome', [6]);

        return is_array($rows) ? $rows : [];
    }

    private function themeSiteVarsLibPath(string $theme): ?string
    {
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $theme)) {
            return null;
        }
        $lib = ProjectPaths::root() . 'template/' . $theme . '/lib/site_vars.php';

        return is_file($lib) ? $lib : null;
    }

    private function ensureThemeSiteVarsLibLoaded(string $theme): bool
    {
        $lib = $this->themeSiteVarsLibPath($theme);
        if ($lib === null) {
            return false;
        }
        if (self::$loadedThemeSiteVarsLib !== $lib) {
            require_once $lib;
            self::$loadedThemeSiteVarsLib = $lib;
        }

        return true;
    }

    /** @return array<string, bool> */
    private function themeSiteVarsFlags(string $theme, string $path): array
    {
        $defaults = [
            'skip_home_slides'     => false,
            'use_lite'             => false,
            'hide_tag_nav'         => false,
            'product_sidebar_lite' => false,
        ];
        if (!$this->ensureThemeSiteVarsLibLoaded($theme)) {
            return $defaults;
        }
        if (!function_exists('theme_site_vars_flags')) {
            return $defaults;
        }
        $flags = theme_site_vars_flags($path);

        return is_array($flags) ? array_merge($defaults, $flags) : $defaults;
    }

    /** @return array<string, string> */
    private function themeSiteVarsUrlOverrides(string $theme): array
    {
        if (!$this->ensureThemeSiteVarsLibLoaded($theme)) {
            return [];
        }

        return function_exists('theme_site_vars_url_overrides')
            ? theme_site_vars_url_overrides()
            : [];
    }

    /** @return list<array<string, mixed>>|null */
    private function themeSiteVarsFooterNav(string $theme): ?array
    {
        if (!$this->ensureThemeSiteVarsLibLoaded($theme)) {
            return null;
        }

        return function_exists('theme_site_vars_footer_nav')
            ? theme_site_vars_footer_nav()
            : null;
    }

    /** @return list<array<string, mixed>>|null */
    private function themeSiteVarsSiteThemes(string $theme, string $path): ?array
    {
        if (!$this->ensureThemeSiteVarsLibLoaded($theme)) {
            return null;
        }

        return function_exists('theme_site_vars_site_themes')
            ? theme_site_vars_site_themes($path)
            : null;
    }

    /** @return array<string, mixed>|null */
    private function themeSiteVarsProductSidebar(string $theme): ?array
    {
        if (!$this->ensureThemeSiteVarsLibLoaded($theme)) {
            return null;
        }

        return function_exists('theme_site_vars_product_sidebar')
            ? theme_site_vars_product_sidebar()
            : null;
    }

    /** @return array<string, mixed> */
    private function themeSiteVarsHomeSeo(string $theme, string $path): array
    {
        if (!$this->ensureThemeSiteVarsLibLoaded($theme)) {
            return ['www_home_seo_hidden_html' => ''];
        }

        return function_exists('theme_site_vars_home_seo')
            ? theme_site_vars_home_seo($path)
            : ['www_home_seo_hidden_html' => ''];
    }

    /** @return array<string, mixed> */
    private function themeSiteVarsMerge(string $theme, string $path): array
    {
        if (!$this->ensureThemeSiteVarsLibLoaded($theme)) {
            return [];
        }

        return function_exists('theme_site_vars_merge')
            ? theme_site_vars_merge($path, $theme)
            : [];
    }

    /** @param array<string, mixed> $cfg */
    private function resolveSiteCopyrightSafe(array $cfg): string
    {
        $fallback = trim((string) ($cfg['site_copyright'] ?? ''));
        try {
            if (class_exists(SiteBrandService::class)) {
                return app(SiteBrandService::class)->resolveSiteCopyright($fallback);
            }
        } catch (\Throwable $e) {
            $this->logOptionalDegrade('site_copyright', $e);
        }

        return $fallback;
    }


    private function pivarkBrandRequiredSafe(): int
    {
        try {
            if (class_exists(SiteBrandService::class)) {
                return app(SiteBrandService::class)->requiresAttribution() ? 1 : 0;
            }
        } catch (\Throwable $e) {
            $this->logOptionalDegrade('brand_required', $e);
        }

        return 0;
    }

    private function pivarkPoweredBySafe(): string
    {
        try {
            if (class_exists(SiteBrandService::class) && app(SiteBrandService::class)->requiresAttribution()) {
                return app(SiteBrandService::class)->attributionText();
            }
        } catch (\Throwable $e) {
            $this->logOptionalDegrade('powered_by', $e);
        }

        return '';
    }

    private function logOptionalDegrade(string $context, \Throwable $e): void
    {
        OpsLog::businessWarning('template_site_vars_degrade', [
            'context' => $context,
            'msg'     => $e->getMessage(),
        ]);
    }
}
