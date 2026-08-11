<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);


namespace app\common\service\plugin\market;

use app\common\service\plugin\extension\PluginOfficialProduct;

use app\common\service\plugin\PluginService;
use app\common\service\plugin\market\PluginMarketCatalogSignatureService;
use app\common\service\plugin\package\PluginPackageStorageService;
use app\common\service\config\ConfigService;
use app\common\service\release\PivarkEditionService;
use app\common\support\LocalFile;
use app\common\support\OpsLog;
use app\common\support\ProjectPaths;

/** 货架目录加载器：browse/load/签验/缓存（原 RemoteCatalog；非价真源） */
final class PluginMarketShelfDirectory
{
    private const CACHE_FILE = 'plugin_market_remote.json';

    private static string $lastCatalogOrigin = '';

    /** @var array{ok:bool,source:string,updated_at:string,plugins:list<array<string,mixed>>,security?:array<string,mixed>}|null */
    private static ?array $memoryLoadCache = null;

    /**
     * 相对 package_url / icon 解析为官方 CDN 绝对地址（/static/market/…）
     */
    public function officialAssetAbsoluteUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '' || !str_starts_with($path, '/')) {
            return $path;
        }

        $base = $this->officialAssetBaseUrl();
        if ($base === '') {
            return $path;
        }

        return rtrim($base, '/') . $path;
    }

    public function officialAssetBaseUrl(): string
    {
        if (self::$lastCatalogOrigin !== '') {
            return self::$lastCatalogOrigin;
        }
        if ($this->usesInternalMarketLane()) {
            $internal = $this->internalMarketAssetBaseUrl();
            if ($internal !== '') {
                return $internal;
            }
        }
        foreach ((array) config('plugin.market.official_asset_hosts', []) as $host) {
            $host = rtrim(trim((string) $host), '/');
            if ($host !== '') {
                return $host;
            }
        }

        return '';
    }

    /** 官方平台站：走本地或平台 market，不兜底公网 pivark.cn catalog */
    private function usesInternalMarketLane(): bool
    {
        if (filter_var(env('PIVARK_PLUGIN_MARKET_SKIP_OFFICIAL', false), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }
        if (trim((string) config('plugin.market.remote_catalog_url', '')) !== '') {
            return true;
        }
        if (rtrim(trim((string) env('PIVARK_LICENSE_PLATFORM_URL', '')), '/') !== '') {
            return true;
        }
        $edition = app(PivarkEditionService::class);

        return $edition->isDev() || $edition->isPlatform();
    }

    private function internalMarketAssetBaseUrl(): string
    {
        foreach ([
            trim((string) config('plugin.market.remote_catalog_url', '')),
            rtrim(trim((string) env('PIVARK_LICENSE_PLATFORM_URL', '')), '/'),
            trim((string) app(ConfigService::class)->get('site_url', '')),
        ] as $candidate) {
            $origin = $this->originFromUrl($candidate);
            if ($origin !== '') {
                return $origin;
            }
        }

        return '';
    }

    /**
     * 取平台 origin：保留 http/https；协议相对返回 //host（禁止默认写成 https://）。
     */
    private function originFromUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '//')) {
            $parts = parse_url('http:' . $url);
            $host  = strtolower(trim((string) ($parts['host'] ?? '')));

            return $host !== '' ? '//' . $host : '';
        }
        if (!preg_match('#^https?://#i', $url)) {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        return $scheme . '://' . strtolower((string) $parts['host']);
    }

    /**
     * 服务端 HTTP 传输用：将 // 展开为带协议的绝对 URL 候选（不写死 https 为唯一标准）。
     *
     * @return list<string>
     */
    public function transportUrlCandidates(string $url): array
    {
        return $this->transport()->transportUrlCandidates($url);
    }

    /**
     * @return list<string>
     */
    private function remoteCatalogUrlCandidates(): array
    {
        $out   = [];
        $override = trim((string) config('plugin.market.remote_catalog_url', ''));
        if ($override !== '') {
            $out[] = $override;
        }
        $feedPath = $this->resolveRemoteFeedPath();
        $licensePlatform = rtrim(trim((string) env('PIVARK_LICENSE_PLATFORM_URL', '')), '/');
        if ($licensePlatform !== '' && $feedPath !== '') {
            // 装包/索引仍可拉 Feed；逛市场走 browse（见 browseRemotePage）。禁 catalog.json 主路径。
            $out[] = $licensePlatform . '/' . ltrim($feedPath, '/');
        }
        if ($this->usesInternalMarketLane()) {
            $internal = $this->internalMarketAssetBaseUrl();
            if ($internal !== '' && $feedPath !== '') {
                array_unshift($out, $internal . '/' . ltrim($feedPath, '/'));
            }
        } else {
            // Community 客户站零配置：config 里 official_catalog_urls → pivark.cn（禁止只靠本机 weapp 用 identifier 当标题）
            foreach ((array) config('plugin.market.official_catalog_urls', []) as $url) {
                $url = trim((string) $url);
                if ($url !== '') {
                    $out[] = $url;
                }
            }
            if ($licensePlatform !== '') {
                $out[] = $licensePlatform . '/static/market/catalog.json';
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * 品项 SSOT Feed 相对路径：配置 → 宿主注入（禁「第一个 host id」静默拼路径 · J31；禁内核写死插件 id）。
     */
    private function resolveRemoteFeedPath(): string
    {
        $configured = trim((string) config('plugin.market.remote_feed_path', ''));
        if ($configured !== '') {
            return $configured;
        }
        $fromHost = PluginOfficialProduct::dispatch('plugin_market_feed_path', [], null);
        if (is_string($fromHost)) {
            $fromHost = trim($fromHost);
            if ($fromHost !== '') {
                return $fromHost;
            }
        }

        return '';
    }

    /** browse / updates 相对路径：配置 → 宿主 → 由 feed_path 推导 */
    private function resolveRemoteBrowsePath(): string
    {
        $configured = trim((string) config('plugin.market.remote_browse_path', ''));
        if ($configured !== '') {
            return $configured;
        }
        $fromHost = PluginOfficialProduct::dispatch('plugin_market_browse_path', [], null);
        if (is_string($fromHost) && trim($fromHost) !== '') {
            return trim($fromHost);
        }
        $feed = $this->resolveRemoteFeedPath();
        if ($feed !== '' && str_ends_with($feed, '/feed')) {
            return substr($feed, 0, -strlen('/feed')) . '/browse';
        }

        return '';
    }

    private function resolveRemoteUpdatesPath(): string
    {
        $configured = trim((string) config('plugin.market.remote_updates_path', ''));
        if ($configured !== '') {
            return $configured;
        }
        $fromHost = PluginOfficialProduct::dispatch('plugin_market_updates_path', [], null);
        if (is_string($fromHost) && trim($fromHost) !== '') {
            return trim($fromHost);
        }
        $feed = $this->resolveRemoteFeedPath();
        if ($feed !== '' && str_ends_with($feed, '/feed')) {
            return substr($feed, 0, -strlen('/feed')) . '/updates';
        }

        return '';
    }

    private function platformOriginForMarketApi(): string
    {
        if ($this->usesInternalMarketLane()) {
            $internal = $this->internalMarketAssetBaseUrl();
            if ($internal !== '') {
                return $internal;
            }
        }

        return rtrim(trim((string) env('PIVARK_LICENSE_PLATFORM_URL', '')), '/');
    }

    /**
     * 客户站逛市场：打远程 browse（失败返回 null，由 CatalogService 走本机 discover）
     *
     * @param array<string, string> $paramFilters
     * @return array{
     *   list:list<array<string,mixed>>,
     *   total:int,
     *   has_more:int,
     *   next_cursor:string,
     *   meta:array<string,mixed>,
     *   source:string
     * }|null
     */
    public function browseRemotePage(
        string $keyword,
        int $limit,
        int $offset,
        array $paramFilters = []
    ): ?array {
        $origin = $this->platformOriginForMarketApi();
        $path   = $this->resolveRemoteBrowsePath();
        if ($origin === '' || $path === '') {
            return null;
        }
        $query = [
            'keyword' => $keyword,
            'limit'   => max(1, min(48, $limit)),
            'offset'  => max(0, $offset),
        ];
        foreach ($paramFilters as $k => $v) {
            $key = trim((string) $k);
            $val = trim((string) $v);
            if ($key === '' || $val === '') {
                continue;
            }
            $query[str_starts_with($key, 'filter_') ? $key : ('filter_' . $key)] = $val;
        }
        $url = $origin . '/' . ltrim($path, '/') . '?' . http_build_query($query);
        if (!$this->isAllowedPlatformUrl($url)) {
            return null;
        }
        $body = null;
        foreach ($this->transport()->expandProtocolRelativeFetchUrls($url) as $fetchUrl) {
            $body = $this->transport()->httpGet($fetchUrl);
            if ($body !== null && trim($body) !== '') {
                break;
            }
            $body = null;
        }
        if ($body === null) {
            return null;
        }
        $parsed = json_decode($body, true);
        if (!is_array($parsed) || !is_array($parsed['list'] ?? null)) {
            return null;
        }
        $list = [];
        foreach ($parsed['list'] as $row) {
            if (is_array($row)) {
                $list[] = $row;
            }
        }

        return [
            'list'        => $list,
            'total'       => (int) ($parsed['total'] ?? count($list)),
            'has_more'    => (int) ($parsed['has_more'] ?? 0),
            'next_cursor' => (string) ($parsed['next_cursor'] ?? ''),
            'meta'        => is_array($parsed['meta'] ?? null) ? $parsed['meta'] : [],
            'source'      => (string) ($parsed['source'] ?? 'item_ssot'),
        ];
    }

    /**
     * @param list<array{identifier:string,version:string}> $installed
     * @return list<array{identifier:string,local_version:string,remote_version:string,package_url:string}>|null
     */
    public function fetchRemoteUpdates(array $installed): ?array
    {
        $origin = $this->platformOriginForMarketApi();
        $path   = $this->resolveRemoteUpdatesPath();
        if ($origin === '' || $path === '' || $installed === []) {
            return null;
        }
        $url  = $origin . '/' . ltrim($path, '/');
        if (!$this->isAllowedPlatformUrl($url)) {
            return null;
        }
        $json = json_encode(['plugins' => $installed], JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return null;
        }
        $body = null;
        foreach ($this->transport()->expandProtocolRelativeFetchUrls($url) as $fetchUrl) {
            $body = $this->transport()->httpPostJson($fetchUrl, $json);
            if ($body !== null && trim($body) !== '') {
                break;
            }
            $body = null;
        }
        if ($body === null) {
            return null;
        }
        $parsed = json_decode($body, true);
        if (!is_array($parsed) || !is_array($parsed['list'] ?? null)) {
            return null;
        }
        $out = [];
        foreach ($parsed['list'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = strtolower(trim((string) ($row['identifier'] ?? '')));
            if ($id === '') {
                continue;
            }
            $out[] = [
                'identifier'     => $id,
                'local_version'  => trim((string) ($row['local_version'] ?? '')),
                'remote_version' => trim((string) ($row['remote_version'] ?? '')),
                'package_url'    => trim((string) ($row['package_url'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * 客户站无宿主/无 FEED_PATH：catalog.json 若带 meta.feed_path 则升试 Feed（J66）。
     *
     * @param array{ok:bool,source:string,updated_at:string,plugins:list<array<string,mixed>>,security?:array<string,mixed>,feed_meta?:array<string,mixed>} $remote
     * @return array{ok:bool,source:string,updated_at:string,plugins:list<array<string,mixed>>,security?:array<string,mixed>,feed_meta?:array<string,mixed>}|null
     */
    private function preferFeedFromDocumentMeta(array $remote, string $fetchedUrl): ?array
    {
        if (str_contains($fetchedUrl, '/plugin-market/feed')) {
            return null;
        }
        if ((string) ($remote['source'] ?? '') === 'item_ssot') {
            return null;
        }
        $meta = is_array($remote['feed_meta'] ?? null) ? $remote['feed_meta'] : [];
        $feedPath = $this->normalizeDiscoveredFeedPath((string) ($meta['feed_path'] ?? ''));
        if ($feedPath === '') {
            return null;
        }
        $origin = $this->originFromUrl($fetchedUrl);
        if ($origin === '') {
            return null;
        }
        $feedUrl = rtrim($origin, '/') . '/' . ltrim($feedPath, '/');
        if (!$this->isAllowedPlatformUrl($feedUrl)) {
            return null;
        }
        $feed = $this->fetchRemote($feedUrl);
        if ($feed === null || !(bool) ($feed['ok'] ?? false)) {
            return null;
        }
        $this->rememberCatalogOrigin($feedUrl);

        return $feed;
    }

    /** @return non-empty-string|'' */
    private function normalizeDiscoveredFeedPath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, '..') || !str_starts_with($path, '/')) {
            return '';
        }
        if (!preg_match('#^/api/v1/plugins/[a-z0-9_-]+/plugin-market/feed$#', $path)) {
            return '';
        }

        return $path;
    }

    private function rememberCatalogOrigin(string $catalogUrl): void
    {
        $origin = $this->originFromUrl($catalogUrl);
        if ($origin !== '') {
            self::$lastCatalogOrigin = $origin;
        }
    }

    private function rememberInternalCatalogOrigin(): void
    {
        if (!$this->usesInternalMarketLane() || self::$lastCatalogOrigin !== '') {
            return;
        }
        $internal = $this->internalMarketAssetBaseUrl();
        if ($internal !== '') {
            self::$lastCatalogOrigin = $internal;
        }
    }

    /**
     * 平台绝对 URL 主机是否允许（根域 + 登记一级子域；禁任意多级）。
     */
    public function isAllowedPlatformUrl(string $url): bool
    {
        $origin = $this->originFromUrl($url);
        if ($origin === '') {
            return false;
        }
        $parts = parse_url($origin);
        $host  = strtolower(trim((string) ($parts['host'] ?? '')));

        return $host !== '' && $this->isAllowedPlatformHost($host);
    }

    public function isAllowedPlatformHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return false;
        }
        // 本地开发：仅 Dev 发行允许 loopback（不进正式白名单文案）
        if (in_array($host, ['127.0.0.1', 'localhost'], true)) {
            return app(PivarkEditionService::class)->isDev();
        }

        $roots = array_values(array_filter(array_map(
            static fn ($d): string => strtolower(trim((string) $d)),
            (array) config('plugin.market.platform_allowed_root_domains', ['pivark.com', 'pivark.cn'])
        )));
        $subs = array_values(array_filter(array_map(
            static fn ($d): string => strtolower(trim((string) $d)),
            (array) config('plugin.market.platform_allowed_subdomains', [])
        )));

        foreach ($roots as $root) {
            if ($host === $root) {
                return true;
            }
            if (!str_ends_with($host, '.' . $root)) {
                continue;
            }
            $prefix = substr($host, 0, -strlen('.' . $root));
            // 仅一层子域；a.b.root 直接拒绝
            if ($prefix === '' || str_contains($prefix, '.')) {
                return false;
            }

            return in_array($prefix, $subs, true);
        }

        return false;
    }

    /**
     * @return array{
     *   ok:bool,
     *   source:string,
     *   updated_at:string,
     *   plugins:list<array<string,mixed>>,
     *   security?:array<string,mixed>,
     *   feed_meta?:array<string,mixed>
     * }
     */
    public function load(): array
    {
        if (self::$memoryLoadCache !== null) {
            return self::$memoryLoadCache;
        }

        $empty = [
            'ok'         => false,
            'source'     => 'none',
            'updated_at' => '',
            'plugins'    => [],
        ];

        $localPath = $this->localCatalogPath();
        if ($this->shouldPreferLocalCatalog($localPath)) {
            $parsed = $this->parseJsonFile($localPath);
            if ($parsed !== null) {
                $this->rememberInternalCatalogOrigin();
                self::$memoryLoadCache = [
                    'ok'         => true,
                    'source'     => 'local_file',
                    'updated_at' => (string) ($parsed['updated_at'] ?? ''),
                    'plugins'    => $this->normalizePlugins($parsed),
                    'security'   => is_array($parsed['security'] ?? null) ? $parsed['security'] : [],
                    'feed_meta'  => is_array($parsed['meta'] ?? null) ? $parsed['meta'] : [],
                ];

                return self::$memoryLoadCache;
            }
        }

        foreach ($this->remoteCatalogUrlCandidates() as $remoteUrl) {
            if (!$this->isAllowedPlatformUrl($remoteUrl)) {
                OpsLog::businessWarning('plugin_market_reject_host', ['url' => $remoteUrl]);
                continue;
            }
            $remote = $this->fetchRemote($remoteUrl);
            if ($remote !== null) {
                $upgraded = $this->preferFeedFromDocumentMeta($remote, $remoteUrl);
                if ($upgraded !== null) {
                    $remote = $upgraded;
                }
                $this->rememberCatalogOrigin($remoteUrl);
                self::$memoryLoadCache = $remote;

                return self::$memoryLoadCache;
            }
        }

        // 客户站已配授权平台：远程失败禁止静默吃本机旧 catalog.json（R3 · 冷备仅平台投影写口）
        if (rtrim(trim((string) env('PIVARK_LICENSE_PLATFORM_URL', '')), '/') !== '') {
            OpsLog::businessWarning('plugin_market_remote_miss_no_local_fallback', [
                'candidates' => count($this->remoteCatalogUrlCandidates()),
            ]);
            self::$memoryLoadCache = $empty;

            return self::$memoryLoadCache;
        }

        if ($localPath !== '' && is_readable($localPath)) {
            $parsed = $this->parseJsonFile($localPath);
            if ($parsed !== null) {
                $this->rememberInternalCatalogOrigin();
                self::$memoryLoadCache = [
                    'ok'         => true,
                    'source'     => 'local_file',
                    'updated_at' => (string) ($parsed['updated_at'] ?? ''),
                    'plugins'    => $this->normalizePlugins($parsed),
                    'security'   => is_array($parsed['security'] ?? null) ? $parsed['security'] : [],
                    'feed_meta'  => is_array($parsed['meta'] ?? null) ? $parsed['meta'] : [],
                ];

                return self::$memoryLoadCache;
            }
        }

        self::$memoryLoadCache = $empty;

        return self::$memoryLoadCache;
    }

    private function shouldPreferLocalCatalog(string $localPath): bool
    {
        if ($localPath === '' || !is_readable($localPath)) {
            return false;
        }

        // 显式 remote_catalog_url → 走远程候选（含 Feed）
        if (trim((string) config('plugin.market.remote_catalog_url', '')) !== '') {
            return false;
        }

        // 客户站配了授权平台：必须先试 Feed/远程，本地 catalog 仅兜底（ITEM-SSOT-002）
        if (rtrim(trim((string) env('PIVARK_LICENSE_PLATFORM_URL', '')), '/') !== '') {
            return false;
        }

        // host_only / 经营源站：同样先试本站 Feed（品项实时），catalog.json 仅兜底
        // 否则改品项名后 admin 应用市场仍读旧投影 + plugin.json 名（ITEM-SSOT-002）
        if ($this->usesInternalMarketLane() && $this->internalMarketAssetBaseUrl() !== '') {
            return false;
        }

        return true;
    }

    /** 当前 worker 是否已加载过 catalog（供前台热路径跳过冷启动） */
    public function isLoaded(): bool
    {
        return self::$memoryLoadCache !== null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function indexByIdentifier(): array
    {
        $load = $this->load();
        $out  = [];
        foreach ($load['plugins'] as $row) {
            $id = strtolower(trim((string) ($row['identifier'] ?? '')));
            if ($id !== '') {
                $out[$id] = $row;
            }
        }

        return $out;
    }

    public function packageUrl(string $identifier): string
    {
        $row = $this->indexByIdentifier()[strtolower(trim($identifier))] ?? null;
        if (!is_array($row)) {
            return '';
        }
        $catalogUrl = trim((string) ($row['package_url'] ?? ''));
        if ($catalogUrl === '') {
            return '';
        }

        return (string) app(PluginPackageStorageService::class)->resolvePackageUrl($identifier, $catalogUrl)['url'];
    }

    /**
     * @return array{mode:string,url:string,object_key?:string,bucket?:string,expires_at?:int,signature?:string,msg?:string}
     */
    public function packageUrlMeta(string $identifier): array
    {
        $row = $this->indexByIdentifier()[strtolower(trim($identifier))] ?? null;
        if (!is_array($row)) {
            return ['mode' => 'none', 'url' => '', 'msg' => '插件不在 catalog'];
        }
        $catalogUrl = trim((string) ($row['package_url'] ?? ''));

        return app(PluginPackageStorageService::class)->resolvePackageUrl($identifier, $catalogUrl);
    }

    public function remoteVersion(string $identifier): string
    {
        $row = $this->indexByIdentifier()[strtolower(trim($identifier))] ?? null;
        if (!is_array($row)) {
            return '';
        }

        return trim((string) ($row['version'] ?? ''));
    }

    public function packageSha256(string $identifier): string
    {
        $row = $this->indexByIdentifier()[strtolower(trim($identifier))] ?? null;
        if (!is_array($row)) {
            return '';
        }

        return strtolower(trim((string) ($row['package_sha256'] ?? '')));
    }

    /**
     * catalog.plugins[].versions[]；缺省时仅返回当前 version + package_url 一档。
     *
     * @return list<array{version:string,package_url:string,package_sha256:string}>
     */
    public function versions(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        $row = $this->indexByIdentifier()[$identifier] ?? null;
        if (!is_array($row)) {
            return [];
        }

        $out = [];
        $seen = [];
        $raw = is_array($row['versions'] ?? null) ? $row['versions'] : [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $ver = trim((string) ($item['version'] ?? ''));
            $url = trim((string) ($item['package_url'] ?? ''));
            if ($ver === '' || $url === '' || isset($seen[$ver])) {
                continue;
            }
            $seen[$ver] = true;
            $resolved = (string) app(PluginPackageStorageService::class)->resolvePackageUrl($identifier, $url)['url'];
            $out[] = [
                'version'        => $ver,
                'package_url'    => $resolved !== '' ? $resolved : $url,
                'package_sha256' => strtolower(trim((string) ($item['package_sha256'] ?? ''))),
            ];
        }

        if ($out === []) {
            $ver = trim((string) ($row['version'] ?? ''));
            $url = $this->packageUrl($identifier);
            if ($ver !== '' && $url !== '') {
                $out[] = [
                    'version'        => $ver,
                    'package_url'    => $url,
                    'package_sha256' => $this->packageSha256($identifier),
                ];
            }
        }

        usort($out, static fn (array $a, array $b): int => version_compare($a['version'], $b['version']));

        return $out;
    }

    /**
     * @return list<array{identifier:string,local_version:string,remote_version:string,package_url:string}>
     */
    public function checkUpdates(): array
    {
        $installed = [];
        try {
            $rows = \app\common\model\Plugin::where('installed', 1)
                ->field('identifier,version')
                ->select()
                ->toArray();
        } catch (\Throwable) {
            $rows = [];
        }
        $payload = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = strtolower(trim((string) ($row['identifier'] ?? '')));
            if ($id === '') {
                continue;
            }
            $localVer = trim((string) ($row['version'] ?? ''));
            $manifest = app(PluginService::class)->readManifest($id);
            $diskVer = is_array($manifest) ? trim((string) ($manifest['version'] ?? '')) : '';
            if ($localVer === '') {
                $localVer = $diskVer;
            } elseif ($diskVer !== '' && version_compare($diskVer, $localVer, '<')) {
                // 库表超前磁盘：按磁盘真源检出待升（否则 remote==db 会假绿「无更新」）
                $localVer = $diskVer;
            }
            if ($localVer === '') {
                continue;
            }
            $installed[$id] = $localVer;
            $payload[] = ['identifier' => $id, 'version' => $localVer];
        }
        if ($payload === []) {
            return [];
        }

        $remoteList = $this->fetchRemoteUpdates($payload);
        // 官方 item_ssot updates 只覆盖品项投影；catalog.json 第三方/联测包须仍能检出升级
        $catalogList = $this->checkUpdatesAgainstCatalogIndex($installed);
        if (!is_array($remoteList)) {
            return $catalogList;
        }
        if ($remoteList === []) {
            return $catalogList;
        }
        if ($catalogList === []) {
            return $remoteList;
        }

        $merged = [];
        foreach ($remoteList as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = strtolower(trim((string) ($row['identifier'] ?? '')));
            if ($id === '') {
                continue;
            }
            $merged[$id] = $row;
        }
        foreach ($catalogList as $row) {
            $id = strtolower(trim((string) ($row['identifier'] ?? '')));
            if ($id === '' || isset($merged[$id])) {
                continue;
            }
            $merged[$id] = $row;
        }

        return array_values($merged);
    }

    /**
     * 本机已装 vs 已缓存 Feed/catalog 索引（不为此再拉全库）
     *
     * @param array<string, string> $installed identifier => local version
     * @return list<array{identifier:string,local_version:string,remote_version:string,package_url:string}>
     */
    private function checkUpdatesAgainstCatalogIndex(array $installed): array
    {
        // Feed/item_ssot 不含 catalog.json 第三方包；须并入 MARKET_URL/本地 catalog 文档
        $remote = $this->indexByIdentifier();
        foreach ($this->indexDocumentCatalogByIdentifier() as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $docVer = trim((string) ($row['version'] ?? ''));
            $feedVer = isset($remote[$id]) ? trim((string) ($remote[$id]['version'] ?? '')) : '';
            if ($docVer === '') {
                continue;
            }
            if ($feedVer === '' || $this->versionNewer($docVer, $feedVer)) {
                $remote[$id] = $row;
            }
        }
        if ($remote === []) {
            return [];
        }
        $out = [];
        foreach ($installed as $id => $localVer) {
            if (!isset($remote[$id])) {
                continue;
            }
            $remoteVer = trim((string) ($remote[$id]['version'] ?? ''));
            if ($remoteVer === '' || $localVer === '' || !$this->versionNewer($remoteVer, $localVer)) {
                continue;
            }
            $out[] = [
                'identifier'     => $id,
                'local_version'  => $localVer,
                'remote_version' => $remoteVer,
                'package_url'    => $this->packageUrl($id) !== ''
                    ? $this->packageUrl($id)
                    : trim((string) ($remote[$id]['package_url'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * 读 MARKET_URL / 本地 catalog.json 文档（不升 Feed），供 updates 检出第三方包。
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexDocumentCatalogByIdentifier(): array
    {
        $out = [];
        $urls = [];
        $marketUrl = trim((string) env('PIVARK_PLUGIN_MARKET_URL', ''));
        if ($marketUrl !== '') {
            $urls[] = $marketUrl;
        }
        foreach ($urls as $url) {
            if (!$this->isAllowedPlatformUrl($url)) {
                continue;
            }
            $remote = $this->fetchRemote($url);
            if ($remote === null || !(bool) ($remote['ok'] ?? false)) {
                continue;
            }
            foreach ((array) ($remote['plugins'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = strtolower(trim((string) ($row['identifier'] ?? '')));
                if ($id === '') {
                    continue;
                }
                $out[$id] = $row;
            }
            if ($out !== []) {
                return $out;
            }
        }

        $localPath = $this->localCatalogPath();
        if ($localPath === '') {
            return [];
        }
        $parsed = $this->parseJsonFile($localPath);
        if ($parsed === null) {
            return [];
        }
        foreach ($this->normalizePlugins($parsed) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = strtolower(trim((string) ($row['identifier'] ?? '')));
            if ($id === '') {
                continue;
            }
            $out[$id] = $row;
        }

        return $out;
    }

    public function versionNewer(string $remote, string $local): bool
    {
        $remote = ltrim(trim($remote), 'vV');
        $local  = ltrim(trim($local), 'vV');
        if ($remote === '' || $local === '') {
            return false;
        }
        if ($remote === $local) {
            return false;
        }

        return version_compare($remote, $local, '>');
    }

    /**
     * @return array{ok:bool,source:string,updated_at:string,plugins:list<array<string,mixed>>}|null
     */
    private function fetchRemote(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $url) && !str_starts_with($url, '//')) {
            return null;
        }
        if (!$this->isAllowedPlatformUrl($url)) {
            return null;
        }

        $ttl = max(60, (int) config('plugin.market.remote_cache_ttl', 3600));
        $cachePath = $this->cachePath();
        if (is_file($cachePath) && (time() - (int) filemtime($cachePath)) < $ttl) {
            $fromDisk = $this->readDiskCacheForUrl($cachePath, $url);
            if ($fromDisk !== null) {
                return $fromDisk;
            }
        }

        $body = null;
        foreach ($this->transport()->expandProtocolRelativeFetchUrls($url) as $fetchUrl) {
            $body = $this->transport()->httpGet($fetchUrl);
            if ($body !== null && trim($body) !== '') {
                break;
            }
            $body = null;
        }
        if ($body === null || trim($body) === '') {
            if (is_file($cachePath)) {
                $fromDisk = $this->readDiskCacheForUrl($cachePath, $url, true);
                if ($fromDisk !== null) {
                    return $fromDisk;
                }
            }

            return null;
        }

        $parsed = json_decode($body, true);
        if (!is_array($parsed)) {
            return null;
        }

        if (!$this->acceptCatalogPayload($parsed)) {
            return null;
        }

        $this->writeDiskCache($cachePath, $url, $parsed);

        return [
            'ok'         => true,
            'source'     => (string) ($parsed['source'] ?? 'remote'),
            'updated_at' => (string) ($parsed['updated_at'] ?? ''),
            'plugins'    => $this->normalizePlugins($parsed),
            'security'   => is_array($parsed['security'] ?? null) ? $parsed['security'] : [],
            'feed_meta'  => is_array($parsed['meta'] ?? null) ? $parsed['meta'] : [],
        ];
    }

    public function invalidateCache(): void
    {
        self::$memoryLoadCache = null;
        $path = $this->cachePath();
        if (is_file($path)) {
            LocalFile::unlinkIfExists($path);
        }
        // 品项改名刷 catalog 后，admin 应用市场进程内列表也必须失效
        PluginMarketCatalogService::clearListMemoryOnly();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJsonFile(string $path): ?array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return null;
        }

        return $this->acceptCatalogPayload($json) ? $json : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function acceptCatalogPayload(array $payload): bool
    {
        $err = app(PluginMarketCatalogSignatureService::class)->verifyCatalog($payload);
        if ($err === null) {
            return true;
        }

        OpsLog::businessWarning('plugin_market_catalog_signature_invalid', [
            'error' => $err,
        ]);

        return !(bool) config('plugin.market.verify_catalog_signature', true);
    }

    private function localCatalogPath(): string
    {
        $root = rtrim(ProjectPaths::root(), '/\\');

        $configured = trim((string) config('plugin.market.local_catalog_path', ''));
        if ($configured !== '') {
            $path = str_starts_with($configured, '/')
                ? $configured
                : $root . '/' . ltrim(str_replace('\\', '/', $configured), '/');
            if (is_file($path)) {
                return $path;
            }
        }

        $candidates = [
            $root . '/public/static/market/catalog.json',
        ];
        // 禁 tools/devtools 影子 catalog 抢读（R16）；冷备只认站点 public/static/market
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private function cachePath(): string
    {
        $dir = ProjectPaths::runtimeDir();
        if (!is_dir($dir)) {
            LocalFile::mkdirIfMissing($dir);
        }

        return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . self::CACHE_FILE;
    }

    /**
     * 磁盘缓存绑 URL：Feed 与 catalog.json 不得互盖（J83）。
     *
     * @return array{ok:bool,source:string,updated_at:string,plugins:list<array<string,mixed>>,security?:array<string,mixed>,feed_meta?:array<string,mixed>}|null
     */
    private function readDiskCacheForUrl(string $cachePath, string $url, bool $stale = false): ?array
    {
        $cached = json_decode((string) file_get_contents($cachePath), true);
        if (!is_array($cached)) {
            return null;
        }
        $document = $cached;
        if (isset($cached['cache_for_url'], $cached['document']) && is_array($cached['document'])) {
            if ((string) $cached['cache_for_url'] !== $url) {
                return null;
            }
            $document = $cached['document'];
        } elseif (!isset($cached['plugins'])) {
            return null;
        }
        // 旧裸 catalog 无 URL：仅允许 stale 回退，禁止 TTL 内冒充任意候选
        if (!isset($cached['cache_for_url']) && !$stale) {
            return null;
        }

        return [
            'ok'         => true,
            'source'     => $stale
                ? (string) ($document['source'] ?? 'remote_stale_cache')
                : (string) ($document['source'] ?? 'remote_cache'),
            'updated_at' => (string) ($document['updated_at'] ?? ''),
            'plugins'    => $this->normalizePlugins($document),
            'security'   => is_array($document['security'] ?? null) ? $document['security'] : [],
            'feed_meta'  => is_array($document['meta'] ?? null) ? $document['meta'] : [],
        ];
    }

    /** @param array<string, mixed> $document */
    private function writeDiskCache(string $cachePath, string $url, array $document): void
    {
        $wrapped = [
            'cache_for_url' => $url,
            'document'      => $document,
        ];
        $json = json_encode($wrapped, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json) && $json !== '') {
            LocalFile::putContents($cachePath, $json);
        }
    }

    /**
     * @param array<string, mixed> $parsed
     * @return list<array<string, mixed>>
     */
    private function normalizePlugins(array $parsed): array
    {
        $rows = $parsed['plugins'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = strtolower(trim((string) ($row['identifier'] ?? '')));
            if ($id === '') {
                continue;
            }
            $row['identifier'] = $id;
            $out[]             = $row;
        }

        return $out;
    }

    private function transport(): PluginMarketRemoteTransport
    {
        return app(PluginMarketRemoteTransport::class);
    }
}
