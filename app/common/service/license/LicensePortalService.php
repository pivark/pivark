<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\license;
use app\common\service\license\LicenseRemoteClientService;

use app\common\service\config\ConfigService;
use app\common\service\site\SiteKeyService;

final class LicensePortalService
{

    public function __construct(
        private readonly LicenseRemoteClientService $licenseRemoteClientService,
        private readonly ConfigService $configService,
        private readonly SiteKeyService $siteKeyService,
    ) {
    }

    public function portalBaseUrl(): string
    {
        $platform = $this->licenseRemoteClientService->platformUrl();
        if ($platform !== '') {
            return $platform;
        }

        $site = trim((string) $this->configService->get('site_url', ''));
        if ($site !== '' && preg_match('#^https?://#i', $site) === 1) {
            return rtrim($site, '/');
        }

        return '';
    }

    public function siteDomain(): string
    {
        $url = trim((string) $this->configService->get('site_url', ''));

        return $this->normalizeDomain($url);
    }

    /**
     * @param array<string, scalar|null> $extra
     */
    public function manageUrl(?string $returnUrl = null, array $extra = []): string
    {
        $base = $this->portalBaseUrl();
        if ($base === '') {
            return '';
        }

        $this->siteKeyService->ensure();
        $path = trim((string) config('pivark.license_portal_path', '/portal/licenses'), '/');
        $params = array_filter(array_merge([
            'site_key' => $this->siteKeyService->get(),
            'site_url' => trim((string) $this->configService->get('site_url', '')),
            'domain'   => $this->siteDomain(),
            'return'   => $returnUrl ?? $this->defaultAdminReturnUrl(),
        ], $extra), static fn ($v): bool => $v !== null && $v !== '');

        return rtrim($base, '/') . '/' . $path . '?' . http_build_query($params);
    }

    /**
     * 客户站后台「前往授权平台购买」· 直达业务开通（携带 site_key / 域名）
     *
     * @param array<string, scalar|null> $extra
     */
    public function purchaseUrl(?string $returnUrl = null, ?string $productId = null, array $extra = []): string
    {
        $this->siteKeyService->ensure();
        $params = array_filter(array_merge([
            'site_key' => $this->siteKeyService->get(),
            'site_url' => trim((string) $this->configService->get('site_url', '')),
            'domain'   => $this->siteDomain(),
            'return'   => $returnUrl ?? $this->defaultAdminReturnUrl(),
        ], $extra), static fn ($v): bool => $v !== null && $v !== '');

        $productId = trim((string) ($productId ?? ''));
        if ($productId !== '') {
            $params['product'] = $productId;
        }

        $query = http_build_query($params);
        $path  = '/member/activate?' . $query;
        $base  = $this->portalBaseUrl();
        if ($base === '') {
            return $path;
        }

        return rtrim($base, '/') . $path;
    }

    /**
     * @param array<string, scalar|null> $extra
     */
    public function manageLicensesUrl(?string $returnUrl = null, array $extra = []): string
    {
        $base = $this->portalBaseUrl();
        if ($base === '') {
            return '';
        }

        $this->siteKeyService->ensure();
        $params = array_filter(array_merge([
            'site_key' => $this->siteKeyService->get(),
            'site_url' => trim((string) $this->configService->get('site_url', '')),
            'domain'   => $this->siteDomain(),
            'return'   => $returnUrl ?? $this->defaultAdminReturnUrl(),
        ], $extra), static fn ($v): bool => $v !== null && $v !== '');

        return rtrim($base, '/') . '/member/licenses?' . http_build_query($params);
    }

    /**
     * 授权平台购买单插件（绑定授权域名）· 绝对 URL
     */
    public function activatePluginUrl(string $identifier, string $skuId = ''): string
    {
        $path = $this->activatePluginPath($identifier, $skuId);
        if ($path === '') {
            return '';
        }

        $base = $this->portalBaseUrl();
        if ($base === '') {
            return $path;
        }

        $parsed = parse_url($path);
        $query  = [];
        if (is_array($parsed) && !empty($parsed['query'])) {
            parse_str((string) $parsed['query'], $query);
        }

        return $this->purchaseUrl(null, isset($query['product']) ? (string) $query['product'] : null);
    }

    /**
     * 授权平台购买单插件 · 站内相对路径（platform 宿主前台用）
     */
    public function activatePluginPath(string $identifier, string $skuId = ''): string
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || !preg_match('/^[a-z0-9_-]+$/', $identifier)) {
            return '';
        }

        $product = 'plugin:' . $identifier;
        $skuId   = trim($skuId);
        if ($skuId !== '') {
            $product .= ':' . $skuId;
        }

        return '/member/activate?' . http_build_query(['product' => $product]);
    }

    public function accountLicensesPath(): string
    {
        return '/member/licenses';
    }

    public function normalizeDomain(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }

    /**
     * 登记行展示/比对用主机名：优先 verified_domain，否则从 site_url 解析。
     *
     * @param array<string, mixed> $registryRow
     */
    public function registryRowHost(array $registryRow): string
    {
        $verified = trim((string) ($registryRow['verified_domain'] ?? ''));
        if ($verified !== '') {
            return $this->normalizeDomain($verified);
        }

        return $this->normalizeDomain((string) ($registryRow['site_url'] ?? ''));
    }

    /**
     * 授权域是否覆盖客户站主机：精确相等，或客户站为其下级任意级子域。
     * 例：授权 xt119.com → 覆盖 c.xt119.com、www.xt119.com、a.b.xt119.com；不覆盖 notxt119.com。
     */
    public function authorizedHostCoversSiteHost(string $authorizedHost, string $siteHost): bool
    {
        $authorizedHost = $this->normalizeDomain($authorizedHost);
        $siteHost = $this->normalizeDomain($siteHost);
        if ($authorizedHost === '' || $siteHost === '') {
            return false;
        }
        if ($authorizedHost === $siteHost) {
            return true;
        }

        return str_ends_with($siteHost, '.' . $authorizedHost);
    }

    /**
     * 两主机是否同属一个授权作用域（互为覆盖：父↔子，或相同）。
     */
    public function hostsInLicenseRelation(string $a, string $b): bool
    {
        return $this->authorizedHostCoversSiteHost($a, $b)
            || $this->authorizedHostCoversSiteHost($b, $a);
    }

    /**
     * 检索作用域：本机 + 父域链（至少保留 example.com 两段），用于 SQL 命中授权登记。
     *
     * @return list<string>
     */
    public function domainScopeCandidates(string $host): array
    {
        $host = $this->normalizeDomain($host);
        if ($host === '') {
            return [];
        }
        $parts = explode('.', $host);
        $out = [$host];
        while (count($parts) > 2) {
            array_shift($parts);
            $out[] = implode('.', $parts);
        }

        return array_values(array_unique($out));
    }

    /**
     * 可注册主域（apex）：example.com；多段后缀如 foo.com.cn → foo.com.cn。
     * 用于运营列表「一级域名下挂二级」族树，不改变授权计费。
     */
    public function apexHost(string $host): string
    {
        $host = $this->normalizeDomain($host);
        if ($host === '') {
            return '';
        }
        $parts = explode('.', $host);
        $n     = count($parts);
        if ($n <= 2) {
            return $host;
        }

        static $multiSuffix = [
            'com.cn', 'net.cn', 'org.cn', 'gov.cn', 'edu.cn', 'ac.cn',
            'co.uk', 'org.uk', 'ac.uk', 'com.hk', 'com.tw',
        ];
        $last2 = $parts[$n - 2] . '.' . $parts[$n - 1];
        if (in_array($last2, $multiSuffix, true) && $n >= 3) {
            return $parts[$n - 3] . '.' . $last2;
        }

        return $parts[$n - 2] . '.' . $parts[$n - 1];
    }

    /**
     * 按授权作用域检索登记：本机、父域、以及本机的下级子域（二级/多级均覆盖）。
     *
     * @param \think\db\BaseQuery $query
     */
    public function applyRegistryHostLookupWhere($query, string $host): void
    {
        $host = $this->normalizeDomain($host);
        $scope = $this->domainScopeCandidates($host);
        if ($host === '' || $scope === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($q) use ($host, $scope): void {
            $q->whereIn('verified_domain', $scope)
                ->whereOr('verified_domain', 'like', '%.' . $host);
            foreach ($scope as $h) {
                foreach (['https://', 'http://'] as $scheme) {
                    $root = $scheme . $h;
                    $q->whereOr('site_url', $root)
                        ->whereOr('site_url', 'like', $root . '/%')
                        ->whereOr('site_url', 'like', $scheme . '%.' . $h)
                        ->whereOr('site_url', 'like', $scheme . '%.' . $h . '/%');
                }
            }
        });
    }

    private function defaultAdminReturnUrl(): string
    {
        $site = rtrim(trim((string) $this->configService->get('site_url', '')), '/');
        if ($site === '') {
            return '/admin/#/system/config';
        }

        return $site . '/admin/#/system/config';
    }
}
