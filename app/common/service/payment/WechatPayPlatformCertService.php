<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\payment;

use app\common\service\payment\gateway\WechatPayGateway;

class WechatPayPlatformCertService
{

    private const CACHE_TTL = 43200;

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $config
     * @return array{ok:bool,msg?:string}
     */
    public function verifyNotify(array $headers, string $rawBody, array $config): array
    {
        $signature = $this->headerValue($headers, 'Wechatpay-Signature');
        $timestamp = $this->headerValue($headers, 'Wechatpay-Timestamp');
        $nonce     = $this->headerValue($headers, 'Wechatpay-Nonce');
        $serial    = $this->headerValue($headers, 'Wechatpay-Serial');

        if ($signature === '' || $timestamp === '' || $nonce === '' || $serial === '') {
            return ['ok' => false, 'msg' => 'notify headers missing'];
        }

        $ts = (int) $timestamp;
        if ($ts < 1 || abs(time() - $ts) > 300) {
            return ['ok' => false, 'msg' => 'notify timestamp invalid'];
        }

        if ($rawBody === '') {
            return ['ok' => false, 'msg' => 'notify body empty'];
        }

        $publicKey = $this->platformPublicKey($serial, $config);
        if ($publicKey === '') {
            return ['ok' => false, 'msg' => 'platform cert not found'];
        }

        $message = $timestamp . "\n" . $nonce . "\n" . $rawBody . "\n";
        $sigBin  = base64_decode($signature, true);
        if ($sigBin === false) {
            return ['ok' => false, 'msg' => 'signature decode failed'];
        }

        $verified = openssl_verify($message, $sigBin, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            return ['ok' => false, 'msg' => 'signature invalid'];
        }

        return ['ok' => true];
    }

    /**
     * @param array<string, string> $config
     * @return \OpenSSLAsymmetricKey|string
     */
    private function platformPublicKey(string $serial, array $config): \OpenSSLAsymmetricKey|string
    {
        $serial = trim($serial);
        if ($serial === '') {
            return '';
        }

        $map = $this->loadCertificateMap($config);

        return $map[$serial] ?? '';
    }

    /**
     * @param array<string, string> $config
     * @return array<string, \OpenSSLAsymmetricKey|string>
     */
    private function loadCertificateMap(array $config): array
    {
        $cacheFile = runtime_path() . 'payment' . DIRECTORY_SEPARATOR . 'wechat_platform_certs.json';
        $cached    = $this->readCache($cacheFile);
        if ($cached !== []) {
            return $cached;
        }

        $fresh = $this->fetchCertificateMap($config);
        if ($fresh !== []) {
            $this->writeCache($cacheFile, $fresh);
        }

        return $fresh;
    }

    /**
     * @param array<string, string> $config
     * @return array<string, \OpenSSLAsymmetricKey|string>
     */
    private function fetchCertificateMap(array $config): array
    {
        $mchId   = trim((string) ($config['wechat_mch_id'] ?? ''));
        $serial  = trim((string) ($config['wechat_serial_no'] ?? ''));
        $privKey = WechatPayGateway::normalizePrivateKeyForRequest(trim((string) ($config['wechat_private_key'] ?? '')));
        $apiKey  = (string) ($config['wechat_api_v3_key'] ?? '');
        if ($mchId === '' || $serial === '' || $privKey === '' || $apiKey === '') {
            return [];
        }

        $resp = WechatPayGateway::authorizedRequest('GET', '/v3/certificates', '', $mchId, $serial, $privKey);
        if (!$resp->isOk()) {
            return [];
        }

        $data = $resp->dataArray();
        $rows = is_array($data['data'] ?? null) ? $data['data'] : [];
        $map  = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $certSerial = trim((string) ($row['serial_no'] ?? ''));
            $enc        = $row['encrypt_certificate'] ?? null;
            if ($certSerial === '' || !is_array($enc)) {
                continue;
            }
            $pem = $this->decryptCertificate($enc, $apiKey);
            if ($pem === '') {
                continue;
            }
            $pub = openssl_pkey_get_public($pem);
            if ($pub instanceof \OpenSSLAsymmetricKey) {
                $map[$certSerial] = $pub;
            }
        }

        return $map;
    }

    /** @param array<string, mixed> $resource */
    private function decryptCertificate(array $resource, string $apiV3Key): string
    {
        $resource['associated_data'] = (string) ($resource['associated_data'] ?? 'certificate');

        return WechatPayGateway::decryptAeadResource($resource, $apiV3Key);
    }

    /**
     * @param array<string, string> $headers
     */
    private function headerValue(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return trim((string) $value);
            }
        }

        return '';
    }

    /** @return array<string, \OpenSSLAsymmetricKey|string> */
    private function readCache(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            return [];
        }
        $expires = (int) ($raw['expires'] ?? 0);
        if ($expires > 0 && time() > $expires) {
            return [];
        }
        $items = is_array($raw['items'] ?? null) ? $raw['items'] : [];
        $map   = [];
        foreach ($items as $serial => $pem) {
            if (!is_string($pem) || $pem === '') {
                continue;
            }
            $pub = openssl_pkey_get_public($pem);
            if ($pub instanceof \OpenSSLAsymmetricKey) {
                $map[(string) $serial] = $pub;
            }
        }

        return $map;
    }

    /**
     * @param array<string, \OpenSSLAsymmetricKey|string> $map
     */
    private function writeCache(string $path, array $map): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $items = [];
        foreach ($map as $serial => $key) {
            if (!$key instanceof \OpenSSLAsymmetricKey) {
                continue;
            }
            $details = openssl_pkey_get_details($key);
            if (!is_array($details) || !isset($details['key'])) {
                continue;
            }
            $items[(string) $serial] = (string) $details['key'];
        }
        file_put_contents($path, json_encode([
            'expires' => time() + self::CACHE_TTL,
            'items'   => $items,
        ], JSON_UNESCAPED_UNICODE));
    }
}
