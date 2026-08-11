<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\license;

use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\extension\HostRuntimeProbe;
use app\common\support\LocalFile;
use app\common\support\ServiceResult;

final class LicenseRemoteClientService
{

    public function __construct(
        private readonly EntitlementService $entitlementService,
        private readonly LicenseHmacService $licenseHmacService,
    ) {
    }

    public function platformUrl(): string
    {
        $url = rtrim(trim((string) config('pivark.license_platform_url', '')), '/');
        if ($url !== '') {
            return $url;
        }
        if (HostRuntimeProbe::isAnyHostRuntimeActive()) {
            return '';
        }
        $edition = strtolower(trim((string) config('pivark.edition', 'community')));
        if ($edition !== 'community') {
            return '';
        }
        $fallback = rtrim(trim((string) config('pivark.license_platform_url_default', 'https://pivark.cn')), '/');

        return preg_match('#^https?://#i', $fallback) === 1 ? $fallback : '';
    }

    public function isConfigured(): bool
    {
        $url = $this->platformUrl();

        return $url !== '' && preg_match('#^https?://#i', $url) === 1;
    }

    /**
     * @return ServiceResult|null null=未配置远程
     */
    public function activate(string $siteKey, string $licenseCode, string $siteUrl, string $coreVersion): ?ServiceResult
    {
        if (!$this->isConfigured() || HostRuntimeProbe::isAnyHostRuntimeActive()) {
            return null;
        }

        $payload = $this->postJson('/api/v1/license/activate', [
            'site_key'     => $siteKey,
            'license_code' => $licenseCode,
            'site_url'     => $siteUrl,
            'version'      => $coreVersion,
        ]);

        return $this->mapRemotePayload($payload, '无法连接授权平台', '远程激活失败', '授权已生效');
    }

    /**
     * @param list<string> $plugins
     * @param array<string, mixed> $telemetry
     */
    public function heartbeat(
        string $siteKey,
        string $siteUrl,
        string $coreVersion,
        array $plugins = [],
        array $telemetry = []
    ): void {
        if (!$this->isConfigured() || HostRuntimeProbe::isAnyHostRuntimeActive()) {
            return;
        }

        $this->postJson('/api/v1/license/heartbeat', array_merge([
            'site_key' => $siteKey,
            'site_url' => $siteUrl,
            'version'  => $coreVersion,
            'plugins'  => $plugins,
        ], $telemetry !== [] ? ['telemetry' => $telemetry] : []));
    }

    /**
     * @return ServiceResult|null null=未配置远程
     */
    public function sync(string $siteKey, string $siteUrl, string $coreVersion): ?ServiceResult
    {
        if (!$this->isConfigured() || HostRuntimeProbe::isAnyHostRuntimeActive()) {
            return null;
        }

        $payload = $this->postJson('/api/v1/license/sync', [
            'site_key' => $siteKey,
            'site_url' => $siteUrl,
            'version'  => $coreVersion,
        ]);

        return $this->mapRemotePayload($payload, '无法连接授权平台', '同步失败', 'ok');
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function mapRemotePayload(
        ?array $payload,
        string $connectMsg,
        string $failMsg,
        string $successMsg
    ): ServiceResult {
        if ($payload === null) {
            return ServiceResult::fail($connectMsg);
        }
        if (isset($payload['error']) && is_array($payload['error'])) {
            $msg = trim((string) ($payload['error']['message'] ?? ''));

            return ServiceResult::fail($msg !== '' ? $msg : $failMsg);
        }
        if (array_key_exists('data', $payload)) {
            $data = is_array($payload['data']) ? $payload['data'] : [];
            $msg  = trim((string) ($payload['meta']['message'] ?? $payload['message'] ?? ''));

            return ServiceResult::ok($data, $msg !== '' ? $msg : $successMsg);
        }
        if ((int) ($payload['code'] ?? 0) !== 1) {
            $msg = trim((string) ($payload['msg'] ?? $payload['message'] ?? ''));

            return ServiceResult::fail($msg !== '' ? $msg : $failMsg);
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $msg  = trim((string) ($payload['msg'] ?? $payload['message'] ?? ''));

        return ServiceResult::ok($data, $msg !== '' ? $msg : $successMsg);
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    public function grantLocallyFromRemote(array $entry, string $licenseCode): array
    {
        $entry   = app(LicenseBundledEnhancementService::class)->mergePluginsForEntry($entry);
        $plugins = $entry['plugins'] ?? [];
        if (!is_array($plugins)) {
            $plugins = [];
        }

        $code    = strtoupper(trim($licenseCode));
        $ref     = $code !== '' ? 'license:' . $code : 'license:DOMAIN';
        $expire  = isset($entry['expire_at']) && $entry['expire_at'] !== ''
            ? (string) $entry['expire_at']
            : null;
        // 授权平台可能回 commercial；本机枚举经 grant() normalize → paid 等
        $type    = (string) ($entry['license_type'] ?? 'paid');

        $bundledSvc    = app(LicenseBundledEnhancementService::class);
        $enhancementSet = array_fill_keys($bundledSvc->enhancementPackIdentifiers(), true);

        $granted = $bundledSvc->grantProPlusBundledEnhancements($entry, $licenseCode);

        foreach ($plugins as $pluginId) {
            $pluginId = strtolower(trim((string) $pluginId));
            if ($pluginId === '' || isset($enhancementSet[$pluginId])) {
                continue;
            }
            if ($this->entitlementService->grantWithSkuApply($pluginId, $expire, $ref, $type)) {
                $granted[] = $pluginId;
            }
        }

        return array_values(array_unique($granted));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private function postJson(string $path, array $body): ?array
    {
        $url     = $this->platformUrl() . $path;
        $content = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($content)) {
            return null;
        }
        $timeout = max(2, (int) config('pivark.license_remote_timeout', 8));
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: PivArk-LicenseClient/1.0',
            'Connection: close',
        ];
        if ($this->licenseHmacService->isEnabled()) {
            [$ts, $sig] = $this->licenseHmacService->signBody($content);
            $headers[]  = LicenseHmacService::HEADER_TIMESTAMP . ': ' . $ts;
            $headers[]  = LicenseHmacService::HEADER_SIGNATURE . ': ' . $sig;
        }
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'timeout'       => $timeout,
                'header'        => implode("\r\n", $headers) . "\r\n",
                'content'       => $content,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);
        $httpResponseHeader = [];
        $raw = LocalFile::getContents($url, false, $ctx, $httpResponseHeader);
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        if ($this->licenseHmacService->isEnabled()) {
            $respHeaders = $this->licenseHmacService->parseHttpResponseHeaders($httpResponseHeader);
            if (!$this->licenseHmacService->verifyFromHeaders($respHeaders, $raw)) {
                return null;
            }
        }
        $parsed = json_decode($raw, true);

        return is_array($parsed) ? $parsed : null;
    }
}
