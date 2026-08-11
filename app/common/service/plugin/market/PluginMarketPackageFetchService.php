<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\market;

use app\common\service\plugin\package\PluginPackageStorageService;
use app\common\service\plugin\package\PluginPackageAuditService;
use app\common\service\plugin\package\PluginPackageService;
use app\common\service\plugin\PluginService;
use app\common\service\audit\AuditLogService;
use app\common\service\payment\PaymentUrl;
use app\common\support\LocalFile;
use app\common\support\ProjectPaths;
use app\common\support\ServiceResult;

/**
 * 市场下包装包（从 PluginMarketAcquireService 抽出）
 * — 购装主流程/补偿仍在 AcquireService
 */
final class PluginMarketPackageFetchService
{
    public function __construct(
        private readonly PluginService $plugins,
        private readonly PluginMarketBlocklistService $blocklist,
    ) {
    }

    public function ensurePackageOnDisk(string $identifier): ServiceResult
    {
        $root = $this->plugins->weappRoot() . $identifier;
        if (is_file($root . '/plugin.json')) {
            return ServiceResult::ok(['step' => '本地已有插件包'], 'ok');
        }

        $url = app(PluginMarketShelfDirectory::class)->packageUrl($identifier);
        if ($url === '') {
            return ServiceResult::fail('插件目录不存在，且市场目录未提供 package_url');
        }

        $installed = $this->downloadAndInstallPackage($identifier, $url, is_dir($root));

        return $installed->isOk()
            ? ServiceResult::ok(['step' => '已从市场下载插件包'], 'ok')
            : ServiceResult::fail((string) ($installed->message() ?: '下载安装失败'));
    }

    public function downloadAndInstallPackage(string $identifier, string $url, bool $replaceExisting = false): ServiceResult
    {
        $blockMsg = $this->blocklist->guardInstall($identifier);
        if ($blockMsg !== null) {
            return ServiceResult::fail($blockMsg);
        }

        $url = $this->resolvePackageUrl($url);
        if ($url === '') {
            return ServiceResult::fail('package_url 无效');
        }

        $guard = app(PluginPackageStorageService::class)->guardInstallUrl($identifier, $url);
        if ($guard !== null) {
            return ServiceResult::fail($guard);
        }

        $download = $this->downloadPackageToTemp($identifier, $url);
        if (!$download->isOk()) {
            return ServiceResult::fail($download->message());
        }

        $downloadData = $download->dataArray();
        $tmp = (string) ($downloadData['path'] ?? '');
        $result = ServiceResult::fail('远程包安装未完成');
        try {
            $audit = app(PluginPackageAuditService::class)->auditZipFile(
                $tmp,
                PluginPackageAuditService::AUDIT_CONTEXT_MARKET_CATALOG
            );
            $audit = app(PluginPackageAuditService::class)->applyCatalogSha256(
                $audit,
                app(PluginMarketShelfDirectory::class)->packageSha256($identifier)
            );
            if (app(PluginPackageAuditService::class)->shouldBlockInstall($audit)) {
                $preview = array_slice($audit['blocks'], 0, 5);

                return ServiceResult::fail('远程包安全审计未通过：' . implode('；', $preview));
            }

            $result = app(PluginPackageService::class)->installUpload(
                $tmp,
                basename(parse_url($url, PHP_URL_PATH) ?: ($identifier . '.zip')),
                $replaceExisting,
                0,
                null,
                PluginPackageAuditService::AUDIT_CONTEXT_MARKET_CATALOG
            );
        } finally {
            LocalFile::unlinkIfExists($tmp);
        }

        if ($result->isOk()) {
            app(AuditLogService::class)->operate('市场下载插件包', 'admin.plugin', [
                'identifier' => $identifier,
                'url'        => $url,
                'replace'    => $replaceExisting,
            ]);
        }

        return $result;
    }

    public function downloadPackageToTemp(string $identifier, string $url): ServiceResult
    {
        $local = $this->resolveLocalPublicPackagePath($url);
        $url = $this->resolvePackageUrl($url);
        if ($url === '') {
            return ServiceResult::fail('package_url 无效');
        }
        if ($local === null) {
            $local = $this->resolveLocalPublicPackagePath($url);
        }

        $safeId = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($identifier))) ?: 'plugin';
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pivark_market_' . $safeId . '_' . bin2hex(random_bytes(4)) . '.zip';

        if ($local !== null) {
            if (!copy($local, $tmp)) {
                return ServiceResult::fail('无法复制本地市场包');
            }

            return ServiceResult::ok(['path' => $tmp], 'ok');
        }

        $raw = null;
        $code = 0;
        $fetchOk = false;
        foreach (app(PluginMarketShelfDirectory::class)->transportUrlCandidates($url) as $fetchUrl) {
            if (function_exists('curl_init')) {
                $handle = curl_init($fetchUrl);
                if ($handle === false) {
                    continue;
                }
                curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 3,
                    CURLOPT_TIMEOUT        => 120,
                    CURLOPT_CONNECTTIMEOUT => 15,
                ]);
                $body = curl_exec($handle);
                $code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
                curl_close($handle);
                if (is_string($body) && $body !== '' && $code >= 200 && $code < 300) {
                    $raw = $body;
                    $fetchOk = true;
                    break;
                }
            } else {
                $ctx = stream_context_create(['http' => ['timeout' => 120]]);
                $body = LocalFile::getContents($fetchUrl, false, $ctx);
                if (is_string($body) && $body !== '') {
                    $raw = $body;
                    $fetchOk = true;
                    break;
                }
            }
        }
        if (!$fetchOk || !is_string($raw) || $raw === '') {
            return ServiceResult::fail('下载插件包失败（HTTP ' . $code . '）');
        }
        if (!LocalFile::putContents($tmp, $raw)) {
            return ServiceResult::fail('无法写入临时文件');
        }

        return ServiceResult::ok(['path' => $tmp], 'ok');
    }

    public function resolveLocalPublicPackagePath(string $url): ?string
    {
        $path = parse_url(trim($url), PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = str_starts_with(trim($url), '/') ? trim($url) : '';
        }
        if ($path === '' || !str_starts_with($path, '/static/market/')) {
            return null;
        }

        $local = rtrim(ProjectPaths::root(), '/\\')
            . DIRECTORY_SEPARATOR . 'public'
            . str_replace('/', DIRECTORY_SEPARATOR, $path);

        return is_file($local) && is_readable($local) ? $local : null;
    }

    public function resolvePackageUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) || str_starts_with($url, '//')) {
            return $url;
        }
        if (str_starts_with($url, '/static/market/')) {
            $local = $this->resolveLocalPublicPackagePath($url);
            if ($local !== null) {
                return app(PaymentUrl::class)->absolute($url);
            }
            $official = app(PluginMarketShelfDirectory::class)->officialAssetAbsoluteUrl($url);
            if ($official !== '' && (preg_match('#^https?://#i', $official) || str_starts_with($official, '//'))) {
                return $official;
            }

            return app(PaymentUrl::class)->absolute($url);
        }

        return '';
    }
}
