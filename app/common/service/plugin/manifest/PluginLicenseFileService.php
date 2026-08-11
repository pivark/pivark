<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\manifest;


use app\common\support\AppTime;
use app\common\support\ServiceResult;
use app\common\service\plugin\entitlement\EntitlementService;

use app\common\service\config\ConfigService;
use app\common\service\release\PivarkEditionService;
use app\common\service\site\SiteKeyService;
use think\facade\Request;

/** 离线授权文件（业务连续性：不依赖 Pivark 云端） */
final class PluginLicenseFileService
{
    public function __construct(
        private readonly PivarkEditionService $pivarkEditionService,
        private readonly ConfigService $configService,
        private readonly EntitlementService $entitlementService,
        private readonly SiteKeyService $siteKeyService,
    ) {
    }

    public const SCHEMA = 'pivark-plugin-license/v1';

    /**
     * @param list<string>          $identifiers
     * @param array<string, mixed> $options perpetual, note, site_bind
     * @return ServiceResult
     */
    public function export(array $identifiers, array $options = []): ServiceResult
    {
        if (!$this->pivarkEditionService->allowsOfflineLicenseFile()) {
            return ServiceResult::fail('开源版不支持离线授权文件签发');
        }

        $secret = $this->licenseSecret();
        if ($secret === '') {
            return ServiceResult::fail('未配置 PIVARK_PLUGIN_LICENSE_SECRET，无法签发离线授权');
        }

        $plugins = [];
        foreach ($identifiers as $id) {
            $id = strtolower(trim($id));
            if ($id === '') {
                continue;
            }
            $expireAt = $options['expire_at'] ?? null;
            if (empty($options['perpetual']) && (!is_string($expireAt) || $expireAt === '')) {
                $maxDays  = max(1, (int) config('plugin.commercial.offline_license_max_days', 365));
                $expireAt = AppTime::format('Y-m-d H:i:s', time() + $maxDays * 86400);
            }
            $plugins[] = [
                'identifier'   => $id,
                'license_type' => (string) ($options['license_type'] ?? 'bundled'),
                'expire_at'    => $expireAt,
                'valid_until'  => $expireAt,
            ];
        }
        if ($plugins === []) {
            return ServiceResult::fail('未指定插件');
        }

        $siteBind = !empty($options['site_bind']);
        $siteKey  = $this->siteKeyService->ensure();
        $payload  = [
            'schema'     => self::SCHEMA,
            'issued_at'  => AppTime::format('c'),
            'valid_until'=> $this->resolveFileValidUntil($plugins, $options),
            'continuity' => [
                'offline_only' => true,
                'perpetual'    => !empty($options['perpetual']),
                'note'         => (string) ($options['note'] ?? '本地化授权；供应商停运后已安装版本可继续使用'),
            ],
            // 运行身份 = site_key；url/fingerprint 仅辅助展示，验绑以 key 为准
            'site'       => $siteBind ? [
                'site_key'    => $siteKey,
                'url'         => (string) $this->configService->get('site_url', ''),
                'fingerprint' => $this->siteFingerprint(),
            ] : null,
            'plugins'    => $plugins,
        ];
        $payload['signature'] = $this->sign($payload, $secret);
        $json                 = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            return ServiceResult::fail('序列化失败');
        }

        $name = 'pivark-license-' . AppTime::format('Ymd') . '.json';

        return ServiceResult::ok(['content' => $json, 'filename' => $name], 'ok');
    }

    /**
     * @return ServiceResult
     */
    public function import(string $json): ServiceResult
    {
        if (!$this->pivarkEditionService->allowsOfflineLicenseFile()) {
            return ServiceResult::fail('开源版不支持导入离线授权文件');
        }

        $secret = $this->licenseSecret();
        if ($secret === '') {
            return ServiceResult::fail('未配置 PIVARK_PLUGIN_LICENSE_SECRET');
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ServiceResult::fail('授权文件 JSON 无效');
        }
        if (($data['schema'] ?? '') !== self::SCHEMA) {
            return ServiceResult::fail('schema 不匹配');
        }
        $sig = (string) ($data['signature'] ?? '');
        unset($data['signature']);
        if ($sig === '' || !hash_equals($this->sign($data, $secret), $sig)) {
            // soft-fail：不撤销本机已有授权；提示重下文件 / 联网同步 / 找支持
            return ServiceResult::fail(
                '签名校验失败，未改动本机已有授权。请重新下载离线授权文件，或联网「同步授权平台」。',
                data: ['soft_fail' => true, 'kept_entitlements' => true]
            );
        }

        $siteBindErr = $this->assertSiteBind($data['site'] ?? null);
        if ($siteBindErr !== null) {
            return ServiceResult::fail($siteBindErr, data: ['soft_fail' => true, 'kept_entitlements' => true]);
        }

        $fileValidUntil = trim((string) ($data['valid_until'] ?? ''));
        if ($fileValidUntil !== '' && strtotime($fileValidUntil) !== false && strtotime($fileValidUntil) < time()) {
            return ServiceResult::fail(
                '授权文件已过期（valid_until=' . $fileValidUntil . '），未改动本机已有授权。',
                data: ['soft_fail' => true, 'kept_entitlements' => true]
            );
        }

        $granted = 0;
        foreach ($data['plugins'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = strtolower(trim((string) ($row['identifier'] ?? '')));
            if ($id === '') {
                continue;
            }
            $expire = $row['valid_until'] ?? ($row['expire_at'] ?? null);
            $expire = is_string($expire) && $expire !== '' ? $expire : null;
            if ($expire !== null && strtotime($expire) !== false && strtotime($expire) < time()) {
                continue;
            }
            $this->entitlementService->grant(
                $id,
                $expire,
                'license_file',
                (string) ($row['license_type'] ?? 'bundled')
            );
            $granted++;
        }

        if ($granted === 0) {
            return ServiceResult::fail(
                '授权文件内无有效插件（可能均已过期），未改动本机已有授权。',
                data: ['soft_fail' => true, 'kept_entitlements' => true]
            );
        }

        return ServiceResult::ok(['granted' => $granted], '已导入 ' . $granted . ' 项授权');
    }

    /**
     * site_bind：优先认 site_key；旧文件仅 fingerprint 时兼容比对（fingerprint 已含 site_key）
     *
     * @param mixed $site
     */
    private function assertSiteBind(mixed $site): ?string
    {
        if (!is_array($site)) {
            return null;
        }
        $boundKey = trim((string) ($site['site_key'] ?? ''));
        $localKey = $this->siteKeyService->ensure();
        if ($boundKey !== '') {
            if (!hash_equals($boundKey, $localKey)) {
                return '授权文件与当前安装 ID（site_key）不匹配，未改动本机已有授权。请向授权平台重新签发绑定本站的文件。';
            }

            return null;
        }
        $fp = trim((string) ($site['fingerprint'] ?? ''));
        if ($fp !== '' && !hash_equals($fp, $this->siteFingerprint())) {
            return '授权文件与当前站点不匹配（旧版 fingerprint），未改动本机已有授权。请重新签发（绑定 site_key）。';
        }

        return null;
    }

    /**
     * @param list<array{identifier:string,license_type:string,expire_at:?string,valid_until:?string}> $plugins
     * @param array<string, mixed> $options
     */
    private function resolveFileValidUntil(array $plugins, array $options): ?string
    {
        if (!empty($options['perpetual'])) {
            return null;
        }

        $max = null;
        foreach ($plugins as $row) {
            $until = $row['valid_until'] ?? ($row['expire_at'] ?? null);
            if (!is_string($until) || $until === '') {
                continue;
            }
            if ($max === null || strtotime($until) > strtotime($max)) {
                $max = $until;
            }
        }

        return $max;
    }

    public function licenseSecret(): string
    {
        return trim((string) config('plugin.commercial.license_secret', ''));
    }

    public function siteFingerprint(): string
    {
        $url = trim((string) $this->configService->get('site_url', ''));
        if ($url === '') {
            $url = (string) (Request::host() ?: 'local');
        }

        return hash('sha256', $url . '|' . $this->siteKeyService->ensure());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sign(array $payload, string $secret): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', (string) $json, $secret);
    }
}
