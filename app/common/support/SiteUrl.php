<?php

/**

 * 元舟 PivArk — 企业全域原子化数字资产中枢

 * (c) 2024-2026 pivark.com. All rights reserved.

 * Author: Angelo

 * 未经允许，不可用于商业用途。

 */

declare (strict_types = 1);



namespace app\common\support;

use think\facade\Request;
use think\Request as HttpRequest;

use app\common\service\config\ConfigService;
use app\common\service\front\FrontUrlBuilder;
use app\common\service\theme\ThemeService;

use app\common\service\tag\TagService;



/** 前台 URL 门面（模板/控制器统一调用） */

class SiteUrl

{

    /** Vue SPA 入口（物理脚本；无伪静态可开） */
    public static function adminHome(): string
    {
        return app(\app\common\service\site\AdminEntryAliasService::class)->scriptEntryPath();
    }

    /**
     * 后台 Vue 页面完整路径（History：/admin/index.php/...），用于登录跳转、旧 Layui URL 302 等。
     * 不依赖宝塔伪静态粘贴；有伪静态时短链 /admin/... 仍可进。
     */
    public static function adminSpa(string $spaPath = '/dashboard/welcome'): string
    {
        $spaPath = '/' . ltrim(trim($spaPath), '/');
        if ($spaPath === '/') {
            $spaPath = '/dashboard/welcome';
        }

        return rtrim(self::adminHome(), '/') . $spaPath;
    }

    /** 后台 REST API 绝对路径（浏览器 img / 探针直链 · 无伪静态走 index.php PATH_INFO） */
    public static function adminRestApiPath(string $relativePath = ''): string
    {
        $relativePath = ltrim(trim($relativePath), '/');
        $base = app(\app\common\service\site\AdminEntryAliasService::class)->apiBasePath();

        return $base . ($relativePath !== '' ? '/' . $relativePath : '');
    }

    public static function home(): string
    {
        return app(FrontUrlBuilder::class)->home();
    }

    /**
     * 基本设置 site_url；未配置时回退前台首页路径。
     * 已带协议时必须与 publicScheme()（强制 HTTPS）对齐，避免 absolute 仍吐 http。
     */
    public static function configuredPublicHome(): string
    {
        $url = trim((string) app(ConfigService::class)->get('site_url', ''));
        if ($url === '') {
            return self::home();
        }
        if (preg_match('#^https?://#i', $url)) {
            $home = rtrim($url, '/') . '/';

            return self::withHttpScheme($home, self::publicScheme() === 'https');
        }
        if (str_starts_with($url, '/')) {
            return $url;
        }
        if (preg_match('#^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(/.*)?$#i', $url)) {
            return self::publicScheme() . '://' . ltrim($url, '/');
        }

        return '/' . ltrim($url, '/');
    }

    /**
     * 站点对外 URL 协议（http | https）。
     * 优先级：site_force_https → site_url 明示协议 → 当前请求 TLS → CLI 环境变量 → http。
     * 与 ForceHttpsMiddleware / 后台「强制 HTTPS」开关对齐；强制开时不以 site_url 的 http:// 盖过。
     */
    public static function publicScheme(): string
    {
        if ((string) app(ConfigService::class)->get('site_force_https', '0') === '1') {
            return 'https';
        }
        $siteUrl = trim((string) app(ConfigService::class)->get('site_url', ''));
        if (preg_match('#^(https?)://#i', $siteUrl, $m)) {
            return strtolower((string) $m[1]);
        }
        if (PHP_SAPI !== 'cli' && Request::isSsl()) {
            return 'https';
        }
        if (PHP_SAPI === 'cli' && getenv('PIVARK_PUBLIC_HTTPS') === '1') {
            return 'https';
        }

        return 'http';
    }

    /**
     * 当前请求是否允许明文 HTTP。
     * SSOT：ForceHttpsMiddleware 与 AdminHttpsRequiredMiddleware 共用。
     *
     * 放行：显式本地 env / 开发模式 / loopback /
     *       公网协议已是 http（site_url 未强制 https）/
     *       请求 Host ≠ 配置的公网站址（本地镜像运营库）。
     */
    public static function allowsPlainHttp(?HttpRequest $request = null): bool
    {
        $env = strtolower(trim((string) env('PIVARK_ENV', '')));
        if ($env !== '' && in_array($env, ['dev', 'www-local', 'demo-local', 'local', 'test'], true)) {
            return true;
        }

        try {
            if (app(\app\common\service\site\SiteModeService::class)->isDev()) {
                return true;
            }
        } catch (\Throwable) {
            // bootstrap 早期无 DB 时忽略
        }

        // 站点对外协议本身是 http：dig 等本地站即使 site_mode=运营 也允许明文后台
        if (self::publicScheme() === 'http') {
            return true;
        }

        if ($request === null) {
            $resolved = request();
            $request = $resolved instanceof HttpRequest ? $resolved : null;
        }

        $host = self::normalizeRequestHost($request !== null ? (string) $request->host() : (string) Request::host());
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        // 运营库镜像公网 https site_url（Host ≠ www.pivark.cn）：本地 b 等可明文
        if ($request !== null && !self::requestIsPublicSiteHost($request)) {
            return true;
        }

        return false;
    }

    /**
     * 当前请求 Host 是否等于（或 apex↔www）配置的公网站址 Host。
     * site_url 无 Host 时视为「未声明公网域」→ true（不靠本条放行明文）。
     */
    public static function requestIsPublicSiteHost(?HttpRequest $request = null): bool
    {
        $siteUrl = trim((string) app(ConfigService::class)->get('site_url', ''));
        if ($siteUrl === '' || preg_match('#^https?://#i', $siteUrl) !== 1) {
            return true;
        }
        $publicHost = self::normalizeRequestHost((string) (parse_url($siteUrl, PHP_URL_HOST) ?? ''));
        if ($publicHost === '') {
            return true;
        }

        if ($request === null) {
            $resolved = request();
            $request = $resolved instanceof HttpRequest ? $resolved : null;
        }
        if ($request === null) {
            return true;
        }

        $reqHost = self::normalizeRequestHost((string) $request->host());

        return self::hostsMatchPublic($reqHost, $publicHost);
    }

    /**
     * 是否应对本请求做 HTTP→HTTPS 301（site_force_https=1 时）。
     * 本地 lane / Host 与公网站址不一致时不跳——避免 b 镜像公网 https site_url 后本地 http 打不开。
     */
    public static function shouldForceHttpsRedirect(?HttpRequest $request = null): bool
    {
        if ((string) app(ConfigService::class)->get('site_force_https', '0') !== '1') {
            return false;
        }

        $siteUrl = strtolower(trim((string) app(ConfigService::class)->get('site_url', '')));
        if ($siteUrl !== '' && str_starts_with($siteUrl, 'http://')) {
            return false;
        }

        if ($request === null) {
            $request = request();
        }
        if (!($request instanceof HttpRequest)) {
            return false;
        }
        if ($request->isSsl() || self::allowsPlainHttp($request)) {
            return false;
        }

        $publicHost = '';
        if ($siteUrl !== '' && preg_match('#^https?://#i', $siteUrl) === 1) {
            $publicHost = self::normalizeRequestHost((string) (parse_url($siteUrl, PHP_URL_HOST) ?? ''));
        }
        if ($publicHost === '') {
            return true;
        }

        $reqHost = self::normalizeRequestHost((string) $request->host());

        return self::hostsMatchPublic($reqHost, $publicHost);
    }

    /** @internal */
    public static function normalizeRequestHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '[::1]') {
            return '::1';
        }
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            // host:port（非 IPv6）
            $host = explode(':', $host, 2)[0];
        }

        return $host;
    }

    /** apex ↔ www 视为同一公网站 */
    private static function hostsMatchPublic(string $requestHost, string $publicHost): bool
    {
        if ($requestHost === '' || $publicHost === '') {
            return false;
        }
        if ($requestHost === $publicHost) {
            return true;
        }
        if ($requestHost === 'www.' . $publicHost || $publicHost === 'www.' . $requestHost) {
            return true;
        }

        return false;
    }

    /**
     * 改写绝对 URL 的 http/https；无协议或空串原样返回。
     */
    public static function withHttpScheme(string $url, bool $https): string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return $https
            ? (preg_replace('#^http://#i', 'https://', $url) ?? $url)
            : (preg_replace('#^https://#i', 'http://', $url) ?? $url);
    }

    /**
     * 保存配置时让 site_url 协议与 site_force_https 一致（SSOT）。
     * - 只改开关：按开关改写已有网址
     * - 只改网址：按网址协议推导开关
     * - 同时改：以开关为准改写网址（与后台联动 UI 一致）
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function syncForceHttpsPatch(array $data, string $existingUrl, string $existingForce): array
    {
        $touchUrl   = array_key_exists('site_url', $data);
        $touchForce = array_key_exists('site_force_https', $data);
        if (!$touchUrl && !$touchForce) {
            return $data;
        }

        $url = $touchUrl ? trim((string) $data['site_url']) : trim($existingUrl);
        $force = $touchForce
            ? (((string) $data['site_force_https'] === '1') ? '1' : '0')
            : (((string) $existingForce === '1') ? '1' : '0');

        if ($touchUrl && !$touchForce && preg_match('#^(https?)://#i', $url, $m) === 1) {
            $force = strtolower((string) $m[1]) === 'https' ? '1' : '0';
        }

        if (preg_match('#^https?://#i', $url) === 1) {
            $data['site_url']          = self::withHttpScheme($url, $force === '1');
            $data['site_force_https']  = $force;
        } elseif ($touchForce) {
            $data['site_force_https'] = $force;
            if ($touchUrl) {
                $data['site_url'] = $url;
            }
        }

        return $data;
    }

    /**
     * 把站内路径变成可分享的绝对 URL（二维码 / OG / 支付回调等）
     * 优先 site_url；未配时回退当前请求 Host。
     * 不受 media_url_mode 影响——收录与回调需要绝对。
     */
    public static function absolute(string $pathOrUrl): string
    {
        $pathOrUrl = trim($pathOrUrl);
        if ($pathOrUrl === '') {
            return self::configuredPublicHome();
        }
        if (preg_match('#^https?://#i', $pathOrUrl)) {
            $parts = parse_url($pathOrUrl);
            if (!is_array($parts) || empty($parts['host'])) {
                return $pathOrUrl;
            }
            $host = strtolower((string) $parts['host']);
            // 本站绝对地址跟强制 HTTPS；外链原样
            if (app(\app\common\service\media\MediaUrlService::class)->isLocalMediaHost($host)) {
                return self::withHttpScheme($pathOrUrl, self::publicScheme() === 'https');
            }

            return $pathOrUrl;
        }

        $path = str_starts_with($pathOrUrl, '/') ? $pathOrUrl : '/' . $pathOrUrl;
        $home = rtrim(self::configuredPublicHome(), '/');
        if ($home !== '' && preg_match('#^https?://#i', $home)) {
            return $home . $path;
        }

        $scheme = self::publicScheme();
        $host   = trim((string) Request::host());
        if ($host !== '') {
            return $scheme . '://' . $host . $path;
        }

        return $path;
    }

    /**
     * 前台/列表出站 URL：跟 media_url_mode（相对=路径；绝对=带 site_url）。
     * 外站域名原样。OG/sitemap/二维码/支付请用 absolute()。
     */
    public static function public(string $pathOrUrl): string
    {
        $pathOrUrl = trim($pathOrUrl);
        if ($pathOrUrl === '' || $pathOrUrl === '#') {
            return $pathOrUrl;
        }

        $media = app(\app\common\service\media\MediaUrlService::class);

        if (str_starts_with($pathOrUrl, '//')) {
            $pathOrUrl = self::publicScheme() . ':' . $pathOrUrl;
        }

        if (preg_match('#^https?://#i', $pathOrUrl) === 1) {
            $parts = parse_url($pathOrUrl);
            if (!is_array($parts) || empty($parts['host'])) {
                return $pathOrUrl;
            }
            $host = strtolower((string) $parts['host']);
            if (!$media->isLocalMediaHost($host)) {
                return $pathOrUrl;
            }
            $path  = (string) ($parts['path'] ?? '/');
            if ($path === '') {
                $path = '/';
            }
            $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
            $frag  = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';
            $local = $path . $query . $frag;

            return $media->isAbsolute() ? self::absolute($local) : $local;
        }

        $path = str_starts_with($pathOrUrl, '/') ? $pathOrUrl : '/' . $pathOrUrl;

        return $media->isAbsolute() ? self::absolute($path) : $path;
    }

    public static function documents(int $page = 1, string $tagSlug = ''): string
    {
        return self::public(app(FrontUrlBuilder::class)->documents($page, $tagSlug));
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function documentFromRow(array $row, ?array $prefetchedTags = null): string
    {
        return self::public(app(FrontUrlBuilder::class)->documentFromRow($row, $prefetchedTags));
    }

    public static function document(int $id, string $htmlName = ''): string
    {
        return self::public(app(FrontUrlBuilder::class)->document($id, $htmlName));
    }

    public static function tags(int $page = 1): string
    {
        return self::public(app(FrontUrlBuilder::class)->tags($page));
    }

    public static function tag(string $slug, int $page = 1): string
    {
        return self::public(app(FrontUrlBuilder::class)->tag($slug, $page));
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function tagFromRow(array $row, int $page = 1): string
    {
        return self::public(app(FrontUrlBuilder::class)->tagFromRow($row, $page));
    }

    public static function pageByTpl(string $tplName): string
    {
        return self::public(app(FrontUrlBuilder::class)->pageByTpl($tplName));
    }

    public static function search(string $keyword = '', int $page = 1, int $productPage = 1): string
    {
        return self::public(app(FrontUrlBuilder::class)->search($keyword, $page, $productPage));
    }

    public static function commerceMall(
        int $page = 1,
        string $keyword = '',
        int $merchantId = 0,
        string $tag = '',
        string $itemType = '',
        string $sort = ''
    ): string {
        return self::public(app(FrontUrlBuilder::class)->commerceMall($page, $keyword, $merchantId, $tag, $itemType, $sort));
    }

    public static function commerceCart(): string
    {
        return self::public(app(FrontUrlBuilder::class)->commerceCart());
    }

    public static function commerceCheckout(): string
    {
        return self::public(app(FrontUrlBuilder::class)->commerceCheckout());
    }

    public static function commerceMarket(): string
    {
        return self::public(app(FrontUrlBuilder::class)->commerceMarket());
    }

    public static function productItem(string $slug): string
    {
        return self::public(app(FrontUrlBuilder::class)->productItem($slug));
    }

    public static function memberLogin(string $redirect = ''): string
    {
        return self::public(app(FrontUrlBuilder::class)->memberLogin($redirect));
    }

    public static function memberRegister(string $redirect = ''): string
    {
        return self::public(app(FrontUrlBuilder::class)->memberRegister($redirect));
    }

    public static function memberForgotPassword(): string
    {
        return self::public(app(FrontUrlBuilder::class)->memberForgotPassword());
    }

    public static function memberForgotUsername(): string
    {
        return self::public(app(FrontUrlBuilder::class)->memberForgotUsername());
    }

    public static function memberPasswordReset(string $token): string
    {
        return self::public(app(FrontUrlBuilder::class)->memberPasswordReset($token));
    }

    public static function memberCenter(): string
    {
        return self::public(app(FrontUrlBuilder::class)->memberCenter());
    }

    /** host_only 账户中心（/member/* 子路径；首页走 Member@center 分叉） */
    public static function platformAccountUrl(string $sub = ''): string
    {
        $sub = trim($sub, '/');
        if ($sub === '' || $sub === 'center') {
            return self::memberCenter();
        }

        return self::public('/member/' . $sub);
    }

    /** @deprecated 使用 platformAccountUrl('') */
    public static function portalAccount(): string
    {
        return self::platformAccountUrl('');
    }

    /**
     * 前台登录后默认「账户中心」入口：platform 宿主 → /member/center，Community → /member/center。
     * 密码/资料等仍用 memberCenter()；platform 首页由 Member@center 分叉渲染 template/user。
     */
    public static function frontAccountHub(): string
    {
        return self::memberCenter();
    }

    public static function memberOAuthRedirect(string $provider, string $redirect = ''): string
    {
        return self::public(app(FrontUrlBuilder::class)->memberOAuthRedirect($provider, $redirect));
    }

    /** OAuth 回调须完整 URL（开放平台 redirect_uri / 后台粘贴） */
    public static function memberOAuthCallback(string $provider): string
    {
        return self::absolute(app(FrontUrlBuilder::class)->memberOAuthCallback($provider));
    }

    public static function memberPurchases(): string
    {
        return self::public(app(FrontUrlBuilder::class)->memberPurchases());
    }

    public static function memberViewing(): string
    {
        return self::public(app(FrontUrlBuilder::class)->memberViewing());
    }

    public static function memberDownloads(): string
    {
        return self::public(app(FrontUrlBuilder::class)->memberDownloads());
    }

    public static function memberPoints(): string
    {
        return self::public(app(FrontUrlBuilder::class)->memberPoints());
    }

    public static function memberConsumption(): string
    {
        return self::public(app(FrontUrlBuilder::class)->memberConsumption());
    }

    public static function memberBalance(): string
    {
        return self::public(app(FrontUrlBuilder::class)->memberBalance());
    }

    public static function memberRecharge(): string
    {
        return self::public(app(FrontUrlBuilder::class)->memberRecharge());
    }

    public static function memberSecurity(): string
    {
        return self::public(app(FrontUrlBuilder::class)->memberSecurity());
    }

    public static function memberProfilePage(): string
    {
        return self::public(app(FrontUrlBuilder::class)->memberProfilePage());
    }

    public static function memberDocuments(): string
    {
        return self::public(app(FrontUrlBuilder::class)->memberDocuments());
    }

    public static function memberDocumentCreate(): string
    {
        return self::public(app(FrontUrlBuilder::class)->memberDocumentCreate());
    }

    public static function memberDocumentEdit(int $id): string
    {
        return self::public(app(FrontUrlBuilder::class)->memberDocumentEdit($id));
    }

    public static function memberEnterAs(string $token): string
    {
        return self::public(app(FrontUrlBuilder::class)->memberEnterAs($token));
    }

    public static function memberLogout(): string
    {
        return self::public(app(FrontUrlBuilder::class)->memberLogout());
    }

    /** 会员 AJAX REST 根（/api/v1/member/*） */
    public static function memberApi(string $suffix = ''): string
    {
        $suffix = ltrim(str_replace('\\', '/', $suffix), '/');

        return $suffix === '' ? '/api/v1/member' : '/api/v1/member/' . $suffix;
    }

    /** Docsify 阅读器入口（主题 lib/docs_manifest.php 或默认 /docs/） */
    public static function docsReader(): string
    {
        $theme = app(\app\common\service\theme\ThemeService::class)->getCurrentTheme();
        if (preg_match('/^[a-z][a-z0-9_-]*$/', $theme)) {
            $lib = ProjectPaths::root() . 'template/' . $theme . '/lib/docs_manifest.php';
            if (is_file($lib)) {
                require_once $lib;
                if (function_exists('theme_docs_reader_url')) {
                    return theme_docs_reader_url();
                }
            }
        }

        return '/docs/#/';
    }

    /** 开源版下载（主题 lib 可覆盖为安装包 URL / 外链；默认走下载单页） */
    public static function communityDownload(): string
    {
        $override = self::themeSiteUrlString('community_download');
        if ($override !== null) {
            return $override;
        }

        return self::pageByTpl('download');
    }

    public static function communityDownloadIsExternal(): bool
    {
        return self::themeSiteUrlBool('community_download_external') ?? false;
    }

    /** Community 更新说明页（与 communityDownload 安装包入口分家） */
    public static function communityChangelog(): string
    {
        $override = self::themeSiteUrlString('community_changelog');
        if ($override !== null && $override !== '') {
            return $override;
        }
        try {
            $fromTpl = self::pageByTpl('list_page_changelog');
            if (is_string($fromTpl) && $fromTpl !== '' && $fromTpl !== '/') {
                return $fromTpl;
            }
        } catch (\Throwable) {
        }

        return '/changelog';
    }

    /** 门户导航/marketing 外链：download·demo 等 tpl 名，非插件 id */
    public static function resolveMarketingNavTarget(string $target): ?string
    {
        $target = strtolower(trim($target));
        if ($target === 'download' && self::communityDownloadIsExternal()) {
            return self::communityDownload();
        }
        if ($target === 'demo' && self::onlineDemoIsExternal()) {
            return self::onlineDemo();
        }

        return null;
    }

    /** 在线演示（主题 lib 可覆盖为外链；默认走演示单页） */
    public static function onlineDemo(): string
    {
        $override = self::themeSiteUrlString('online_demo');
        if ($override !== null) {
            return $override;
        }

        return self::pageByTpl('demo');
    }

    public static function onlineDemoIsExternal(): bool
    {
        return self::themeSiteUrlBool('online_demo_external') ?? false;
    }

    private static function themeSiteUrlString(string $key): ?string
    {
        $flags = self::themeSiteUrlFlags();
        if (!isset($flags[$key]) || !is_string($flags[$key])) {
            return null;
        }
        $value = trim($flags[$key]);

        return $value !== '' ? $value : null;
    }

    private static function themeSiteUrlBool(string $key): ?bool
    {
        $flags = self::themeSiteUrlFlags();
        if (!array_key_exists($key, $flags)) {
            return null;
        }

        return (bool) $flags[$key];
    }

    /** @return array<string, mixed> */
    private static function themeSiteUrlFlags(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }
        $theme = app(ThemeService::class)->getCurrentTheme();
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $theme)) {
            return $cache = [];
        }
        $lib = ProjectPaths::root() . 'template/' . $theme . '/lib/site_vars.php';
        if (!is_file($lib)) {
            return $cache = [];
        }
        require_once $lib;

        return $cache = function_exists('theme_site_url_flags')
            ? theme_site_url_flags()
            : [];
    }

}
