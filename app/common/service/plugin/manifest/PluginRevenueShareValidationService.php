<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\manifest;

use app\common\service\plugin\commerce\PluginSkuCatalogStorageService;

/** manifest commercial.revenue_share 语法校验 + 平台 catalog 备案比例强制 */
final class PluginRevenueShareValidationService
{
    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function syntaxErrors(array $manifest): array
    {
        $commercial = is_array($manifest['commercial'] ?? null) ? $manifest['commercial'] : [];
        $share      = $commercial['revenue_share'] ?? null;
        if ($share === null) {
            return [];
        }
        if (!is_array($share)) {
            return ['commercial.revenue_share 须为对象'];
        }

        $errors = [];
        $sum    = 0;
        foreach (['host_percent' => '平台', 'channel_percent' => '渠道', 'author_percent' => '作者'] as $key => $label) {
            if (!array_key_exists($key, $share)) {
                $errors[] = 'commercial.revenue_share 缺少 ' . $key;

                continue;
            }
            if (!is_int($share[$key]) && !is_float($share[$key]) && !preg_match('/^\d+$/', (string) $share[$key])) {
                $errors[] = 'commercial.revenue_share.' . $key . ' 须为 0–100 整数';

                continue;
            }
            $val = (int) $share[$key];
            if ($val < 0 || $val > 100) {
                $errors[] = 'commercial.revenue_share.' . $key . '（' . $label . '）须在 0–100';

                continue;
            }
            $sum += $val;
        }
        if ($errors === [] && $sum !== 100) {
            $errors[] = 'commercial.revenue_share 三项比例之和须为 100（当前 ' . $sum . '）';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $share
     * @return array{host_percent:int,channel_percent:int,author_percent:int}
     */
    public function normalizeShare(array $share): array
    {
        return [
            'host_percent'    => (int) ($share['host_percent'] ?? 0),
            'channel_percent' => (int) ($share['channel_percent'] ?? 0),
            'author_percent'  => (int) ($share['author_percent'] ?? 0),
        ];
    }

    /**
     * @return array{host_percent:int,channel_percent:int,author_percent:int}|null
     */
    public function registeredShareForIdentifier(string $identifier): ?array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return null;
        }

        $catalog = app(PluginSkuCatalogStorageService::class)->loadCatalog();
        if ($catalog === null || !is_array($catalog['plugins'] ?? null)) {
            return null;
        }

        foreach ($catalog['plugins'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (strtolower(trim((string) ($row['identifier'] ?? ''))) !== $identifier) {
                continue;
            }
            $commercial = is_array($row['commercial'] ?? null) ? $row['commercial'] : [];
            $share      = $commercial['revenue_share'] ?? null;
            if (!is_array($share)) {
                return null;
            }

            $normalized = $this->normalizeShare($share);
            if (!$this->registeredShareSignatureValid($row, $normalized)) {
                return null;
            }

            return $normalized;
        }

        return null;
    }

    public function isDeveloperMarketPlugin(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return false;
        }

        $catalog = app(PluginSkuCatalogStorageService::class)->loadCatalog();
        if ($catalog === null || !is_array($catalog['plugins'] ?? null)) {
            return false;
        }

        foreach ($catalog['plugins'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (strtolower(trim((string) ($row['identifier'] ?? ''))) !== $identifier) {
                continue;
            }

            return (string) ($row['publisher_type'] ?? '') === 'developer'
                || (string) ($row['commercial_profile'] ?? '') === 'developer_listing';
        }

        return false;
    }

    /**
     * @param array<string, mixed> $catalogRow
     * @param array{host_percent:int,channel_percent:int,author_percent:int} $share
     * @return array<string, mixed>
     */
    public function attachShareSignature(array $catalogRow, array $share): array
    {
        $sig = $this->signShare($share);
        if ($sig === '') {
            return $catalogRow;
        }
        $commercial = is_array($catalogRow['commercial'] ?? null) ? $catalogRow['commercial'] : [];
        $commercial['revenue_share']           = $share;
        $commercial['revenue_share_signature'] = $sig;
        $catalogRow['commercial']              = $commercial;

        return $catalogRow;
    }

    /**
     * @param array{host_percent:int,channel_percent:int,author_percent:int} $share
     */
    public function signShare(array $share): string
    {
        $secret = $this->shareSecret();
        if ($secret === '') {
            return '';
        }
        $json = json_encode($this->normalizeShare($share), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash_hmac('sha256', is_string($json) ? $json : '', $secret);
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function enforceAgainstRegistered(array $manifest, string $identifier): array
    {
        $registered = $this->registeredShareForIdentifier($identifier);
        if ($registered === null) {
            return [];
        }

        $commercial = is_array($manifest['commercial'] ?? null) ? $manifest['commercial'] : [];
        $share      = $commercial['revenue_share'] ?? null;
        if (!is_array($share)) {
            return ['已上架插件须在 manifest 声明 commercial.revenue_share，且与平台备案比例一致'];
        }

        if ($this->normalizeShare($share) !== $registered) {
            return [
                'commercial.revenue_share 与平台备案不一致（host/author/channel 须与首次过审版本相同：'
                . 'host=' . $registered['host_percent']
                . ' author=' . $registered['author_percent']
                . ' channel=' . $registered['channel_percent'] . '）',
            ];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{host_percent:int,channel_percent:int,author_percent:int}|null
     */
    public function shareFromManifest(array $manifest): ?array
    {
        $commercial = is_array($manifest['commercial'] ?? null) ? $manifest['commercial'] : [];
        $share      = $commercial['revenue_share'] ?? null;
        if (!is_array($share)) {
            return null;
        }
        if ($this->syntaxErrors($manifest) !== []) {
            return null;
        }

        return $this->normalizeShare($share);
    }

    /**
     * @param array<string, mixed> $catalogPluginRow
     * @param array{host_percent:int,channel_percent:int,author_percent:int} $share
     */
    private function registeredShareSignatureValid(array $catalogPluginRow, array $share): bool
    {
        $commercial = is_array($catalogPluginRow['commercial'] ?? null) ? $catalogPluginRow['commercial'] : [];
        $sig        = trim((string) ($commercial['revenue_share_signature'] ?? ''));
        if ($sig === '') {
            return true;
        }

        return hash_equals($this->signShare($share), $sig);
    }

    private function shareSecret(): string
    {
        $own = trim((string) config('plugin.market.catalog_sign_secret', ''));
        if ($own !== '') {
            return $own;
        }

        return trim((string) config('plugin.commercial.license_secret', ''));
    }
}
