<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\release;
use app\common\support\AppTime;

use app\common\service\config\ConfigService;
use app\common\service\license\LicensePortalService;
use app\common\service\plugin\market\PluginMarketShelfDirectory;
use app\common\service\release\PivarkEditionService;
use app\common\service\site\SiteCoreLicenseService;
use app\common\support\InstallGate;
use app\common\support\LocalFile;
use app\common\support\ProjectPaths;

final class CoreUpdateRemoteService
{

    public function __construct(
        private readonly PluginMarketShelfDirectory $pluginMarketRemoteCatalog,
        private readonly SiteCoreLicenseService $siteCoreLicenseService,
        private readonly ConfigService $configService,
    ) {
    }

    private const CACHE_FILE = 'core_updates_remote.json';

    /** @var array<string, array<string, mixed>> 单次 HTTP 请求内 memo（cached / refresh 各一份） */
    private static array $checkMemo = [];

    public function currentVersion(): string
    {
        if (defined('PIVARK_VERSION')) {
            $version = trim((string) PIVARK_VERSION);
            if ($version !== '') {
                return $version;
            }
        }

        $releasePath = ProjectPaths::root() . 'RELEASE.json';
        if (is_readable($releasePath)) {
            $data = json_decode((string) file_get_contents($releasePath), true);
            if (is_array($data)) {
                $version = trim((string) ($data['version'] ?? ''));
                if ($version !== '') {
                    return $version;
                }
            }
        }

        $lockVersion = trim((string) (InstallGate::readLockData()['release_version'] ?? ''));
        if ($lockVersion !== '') {
            return $lockVersion;
        }

        return '1.0.0';
    }

    /**
     * @return array{
     *   ok:bool,
     *   source:string,
     *   product:string,
     *   current:string,
     *   latest:string,
     *   has_update:bool,
     *   urgent:bool,
     *   changelog_url:string,
     *   download_url:string,
     *   sha256:string,
     *   signature:string,
     *   published_at:string,
     *   releases:list<array<string,mixed>>
     * }
     */
    public function check(bool $refresh = false): array
    {
        $memoKey = $refresh ? 'refresh' : 'cached';
        if (isset(self::$checkMemo[$memoKey])) {
            return self::$checkMemo[$memoKey];
        }

        return self::$checkMemo[$memoKey] = $this->computeCheck($refresh);
    }

    /** 阶梯连升每档后清 memo，避免仍读到升前版本 */
    public function clearCheckMemo(): void
    {
        self::$checkMemo = [];
    }

    /**
     * @return array<string, mixed>
     */
    private function computeCheck(bool $refresh): array
    {
        $current = $this->currentVersion();
        $empty   = $this->emptyResult($current);

        $manifest = $this->loadManifest($refresh);
        if ($manifest === null) {
            return $empty;
        }

        $product = trim((string) ($manifest['product'] ?? 'community'));
        $latest  = trim((string) ($manifest['latest'] ?? ''));
        if ($latest === '') {
            $releases = is_array($manifest['releases'] ?? null) ? $manifest['releases'] : [];
            $first    = is_array($releases[0] ?? null) ? $releases[0] : [];
            $latest   = trim((string) ($first['version'] ?? ''));
        }

        $latestRelease = $this->findRelease($manifest, $latest);
        $hasUpdate     = $latest !== '' && $this->pluginMarketRemoteCatalog->versionNewer($latest, $current);
        $downloadUrl   = $this->resolveDownloadUrl(trim((string) ($latestRelease['download_url'] ?? '')));
        $minVersion    = trim((string) ($latestRelease['min_version'] ?? ''));

        return $this->presentUpdateCheck($this->applyUpgradeLicenseGate([
            'ok'             => true,
            'source'         => (string) ($manifest['_source'] ?? 'remote'),
            'product'        => $product,
            'current'        => $current,
            'latest'         => $latest,
            'has_update'     => $hasUpdate,
            'urgent'         => !empty($latestRelease['urgent']),
            'min_version'    => $minVersion,
            'changelog_url'  => trim((string) ($manifest['changelog_url'] ?? ($latestRelease['changelog_url'] ?? ''))),
            'download_url'   => $downloadUrl,
            'sha256'         => trim((string) ($latestRelease['sha256'] ?? '')),
            'signature'      => trim((string) ($latestRelease['signature'] ?? '')),
            'published_at'   => trim((string) ($manifest['published_at'] ?? ($latestRelease['published_at'] ?? ''))),
            'releases'       => is_array($manifest['releases'] ?? null) ? $manifest['releases'] : [],
        ]));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadManifest(bool $refresh): ?array
    {
        $remoteUrl = trim((string) config('pivark.update_check_url', ''));
        if ($remoteUrl !== '') {
            if (!$refresh) {
                $cached = $this->readCache();
                if ($cached !== null) {
                    return $cached;
                }
            } elseif ($refresh) {
                $remote = $this->fetchRemote($remoteUrl);
                if ($remote !== null) {
                    $this->writeCache($remote);

                    return $remote;
                }
            }
        }

        $localPath = $this->localManifestPath();
        if ($localPath !== '' && is_readable($localPath)) {
            $parsed = $this->parseJsonFile($localPath);
            if ($parsed !== null) {
                $parsed['_source'] = 'local_file';

                return $parsed;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function findRelease(array $manifest, string $version): array
    {
        $releases = is_array($manifest['releases'] ?? null) ? $manifest['releases'] : [];
        foreach ($releases as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string) ($row['version'] ?? '')) === $version) {
                return $row;
            }
        }

        return is_array($releases[0] ?? null) ? $releases[0] : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(string $current): array
    {
        return $this->presentUpdateCheck($this->applyUpgradeLicenseGate([
            'ok'             => false,
            'source'         => 'none',
            'product'        => 'community',
            'current'        => $current,
            'latest'         => $current,
            'has_update'     => false,
            'urgent'         => false,
            'changelog_url'  => '',
            'download_url'   => '',
            'sha256'         => '',
            'signature'      => '',
            'published_at'   => '',
            'releases'       => [],
        ]));
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function applyUpgradeLicenseGate(array $result): array
    {
        $allowed = $this->siteCoreLicenseService->allowsUpdateDownload();
        $result['upgrade_license_required'] = !$allowed && !empty($result['has_update']);

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function presentUpdateCheck(array $result): array
    {
        $hasUpdate = !empty($result['has_update']);
        $current   = trim((string) ($result['current'] ?? ''));
        $minVersion = trim((string) ($result['min_version'] ?? ''));
        $minBlocked = $hasUpdate
            && $minVersion !== ''
            && $this->pluginMarketRemoteCatalog->versionNewer($minVersion, $current);

        $canApply  = $hasUpdate
            && !$minBlocked
            && $this->siteCoreLicenseService->allowsOnlineCoreApply()
            && trim((string) ($result['download_url'] ?? '')) !== '';

        $result['min_version_blocked'] = $minBlocked;
        $result['can_apply']            = $canApply;
        $result['notify_only']            = $hasUpdate && !$canApply;
        $result['opensource_repo_url']    = $this->opensourceRepoUrl();
        $result['manual_download_url']    = $this->manualDownloadUrl($result);
        $result['purchase_portal_url']    = app(LicensePortalService::class)->purchaseUrl();
        $result['edition_label']          = $this->editionLabel();
        $result['upgrade_notice']         = $this->upgradeNotice($result, $canApply);

        if (app(PivarkEditionService::class)->isDev()) {
            $result['has_update']  = false;
            $result['notify_only'] = false;
            $result['can_apply']   = false;
            $result['upgrade_notice'] = '';
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function manualDownloadUrl(array $result): string
    {
        $download = trim((string) ($result['download_url'] ?? ''));
        if ($download !== '') {
            return $download;
        }

        return $this->opensourceRepoUrl();
    }

    private function editionLabel(): string
    {
        if (!$this->siteCoreLicenseService->allowsOnlineCoreApply()) {
            return '开源版';
        }

        $label = trim((string) ($this->siteCoreLicenseService->marketPreflight()['domain_tier_label'] ?? ''));
        if ($label === '' || str_contains($label, '开源版')) {
            return '已授权版';
        }

        return $label;
    }

    private function opensourceRepoUrl(): string
    {
        $url = trim((string) config('pivark.opensource_repo_url', ''));
        if ($url !== '') {
            return $url;
        }

        return 'https://gitee.com/pivark/pivark/releases';
    }

    /**
     * @param array<string, mixed> $result
     */
    private function upgradeNotice(array $result, bool $canApply): string
    {
        if (empty($result['has_update'])) {
            return '';
        }
        $current = trim((string) ($result['current'] ?? ''));
        $latest  = trim((string) ($result['latest'] ?? ''));
        if (!empty($result['min_version_blocked'])) {
            $minVersion = trim((string) ($result['min_version'] ?? ''));

            return '发现新版本 V' . $latest . '，但当前 V' . $current
                . ' 低于最低升级阶梯 V' . $minVersion . '，请先升级到中间版本后再继续。';
        }
        if ($canApply) {
            return '尊敬的用户，您当前使用的是元舟 PivArk '
                . $this->editionLabel()
                . ' V'
                . $current
                . '，最新版本为 V'
                . $latest
                . '。'
                . "\n"
                . '您已购买域名授权，可点击下方「立即升级」一键完成（自动备份并执行数据库迁移，建议在业务低峰期操作）。';
        }

        return '尊敬的用户，您当前使用的是元舟 PivArk 开源版 V'
            . $current
            . '，最新版本为 V'
            . $latest
            . '，建议尽快升级以获得更好的稳定性与安全性。'
            . "\n"
            . '购买基础版及以上授权后，可在后台一键在线升级。'
            . "\n"
            . '若暂不购买，请下载免费升级包并按文档手动升级（请保留 .env 与 data/ 目录）。';
    }

    private function localManifestPath(): string
    {
        $configured = trim((string) config('pivark.update_check_local', ''));
        if ($configured !== '') {
            return $configured;
        }

        return ProjectPaths::root() . 'public/static/release/updates.json';
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
     * @return array<string, mixed>|null
     */
    private function readCache(): ?array
    {
        $path = $this->cachePath();
        if (!is_file($path)) {
            return null;
        }
        $ttl = max(60, (int) config('pivark.update_check_cache_ttl', 3600));
        if ((AppTime::timestamp() - (int) filemtime($path)) >= $ttl) {
            return null;
        }
        $parsed = json_decode((string) file_get_contents($path), true);

        return is_array($parsed) ? $parsed : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeCache(array $payload): void
    {
        LocalFile::putContents(
            $this->cachePath(),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRemote(string $url): ?array
    {
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout'       => 3,
                'header'        => "Accept: application/json\r\nUser-Agent: PivArk-CoreUpdate/1.0\r\nConnection: close\r\n",
                'ignore_errors' => true,
            ],
        ]);
        $raw = LocalFile::getContents($url, false, $ctx);
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            return null;
        }
        $parsed['_source'] = 'remote';

        return $parsed;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJsonFile(string $path): ?array
    {
        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $parsed = json_decode($raw, true);

        return is_array($parsed) ? $parsed : null;
    }

    public function resolveDownloadUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $remote = trim((string) config('pivark.update_check_url', ''));
        if ($remote !== '' && preg_match('#^https?://#i', $remote)) {
            $parts = parse_url($remote);
            if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
                $base = $parts['scheme'] . '://' . $parts['host'];
                if (!empty($parts['port'])) {
                    $base .= ':' . $parts['port'];
                }

                return $base . (str_starts_with($url, '/') ? $url : '/' . $url);
            }
        }

        // 发行相对路径（updates / fingerprints / zip）：未配 UPDATE_CHECK_URL 时走官方货源，
        // 禁止拼客户站 site_url（否则比对官方指纹会 404 →「未找到官方文件指纹」）
        $path = str_starts_with($url, '/') ? $url : '/' . $url;
        if (str_starts_with($path, '/static/release/')) {
            $official = trim((string) config('pivark.license_platform_url', ''));
            if ($official === '') {
                $official = trim((string) config('pivark.license_platform_url_default', 'https://pivark.cn'));
            }
            if ($official !== '' && preg_match('#^https?://#i', $official)) {
                return rtrim($official, '/') . $path;
            }
        }

        $site = trim((string) $this->configService->get('site_url', ''));
        if ($site !== '' && preg_match('#^https?://#i', $site)) {
            return rtrim($site, '/') . $path;
        }

        return $url;
    }
}
