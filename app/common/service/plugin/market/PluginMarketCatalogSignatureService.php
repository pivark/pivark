<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\market;

/** catalog.json / blocklist.json HMAC-SHA256 签验（供应链防篡改） */
final class PluginMarketCatalogSignatureService
{
    public const CATALOG_SCHEMA = 'pivark-market-catalog/v1';

    public const BLOCKLIST_SCHEMA = 'pivark-market-blocklist/v1';

    /**
     * @param array<string, mixed> $catalog
     * @return array<string, mixed>
     */
    public function attachCatalogSignature(array $catalog): array
    {
        $secret = $this->catalogSecret();
        if ($secret === '') {
            unset($catalog['signature'], $catalog['signature_schema']);

            return $catalog;
        }

        unset($catalog['signature'], $catalog['signature_schema']);
        $catalog['signature_schema'] = self::CATALOG_SCHEMA;
        $catalog['signature']        = $this->sign($catalog, $secret);

        return $catalog;
    }

    /**
     * @param array<string, mixed> $catalog
     */
    public function verifyCatalog(array $catalog): ?string
    {
        $secret = $this->catalogSecret();
        if ($secret === '') {
            return null;
        }

        $sig = (string) ($catalog['signature'] ?? '');
        if ($sig === '') {
            return 'catalog 缺少签名字段';
        }

        $payload = $catalog;
        unset($payload['signature']);
        if (!hash_equals($this->sign($payload, $secret), $sig)) {
            return 'catalog 签名校验失败';
        }

        return null;
    }

    /**
     * @param array{identifiers:array<string,array<string,mixed>>} $data
     * @return array{identifiers:array<string,array<string,mixed>>,signature?:string,signature_schema?:string}
     */
    public function attachBlocklistSignature(array $data): array
    {
        $secret = $this->blocklistSecret();
        if ($secret === '') {
            unset($data['signature'], $data['signature_schema']);

            return $data;
        }

        unset($data['signature'], $data['signature_schema']);
        $data['signature_schema'] = self::BLOCKLIST_SCHEMA;
        $data['signature']        = $this->sign($data, $secret);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function verifyBlocklist(array $data): ?string
    {
        $secret = $this->blocklistSecret();
        if ($secret === '') {
            return null;
        }

        $sig = (string) ($data['signature'] ?? '');
        if ($sig === '') {
            return 'blocklist 缺少签名字段';
        }

        $payload = $data;
        unset($payload['signature']);
        if (!hash_equals($this->sign($payload, $secret), $sig)) {
            return 'blocklist 签名校验失败';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sign(array $payload, string $secret): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash_hmac('sha256', is_string($json) ? $json : '', $secret);
    }

    private function catalogSecret(): string
    {
        $own = trim((string) config('plugin.market.catalog_sign_secret', ''));
        if ($own !== '') {
            return $own;
        }

        return trim((string) config('plugin.commercial.license_secret', ''));
    }

    private function blocklistSecret(): string
    {
        $own = trim((string) config('plugin.security.blocklist_sign_secret', ''));
        if ($own !== '') {
            return $own;
        }

        return $this->catalogSecret();
    }
}
