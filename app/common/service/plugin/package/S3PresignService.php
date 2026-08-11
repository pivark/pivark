<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\package;

use app\common\service\storage\EnterpriseStorageSdkBridge;

/**
 * S3 GET 预签名（SigV4 草案；Enterprise 安装 aws-sdk 后优先走 S3Client）。
 */
final class S3PresignService
{
    public function __construct(
        private readonly EnterpriseStorageSdkBridge $enterpriseStorageSdkBridge,
    ) {
    }

    public function useSigV4(): bool
    {
        $secret = config('plugin.market.storage_secret_key', '');
        $secret = is_string($secret) ? trim($secret) : '';

        return $secret !== '';
    }

    public function buildPresignedGetUrl(string $objectKey, int $expiresAt, string $identifier): string
    {
        $objectKey = ltrim(trim($objectKey), '/');
        if (!$this->useSigV4()) {
            return app(PluginPackageStorageService::class)->buildPivarkStubUrl($objectKey, $expiresAt, $identifier);
        }

        $endpoint  = app(PluginPackageStorageService::class)->storageEndpoint();
        $bucketRaw = config('plugin.market.storage_bucket', 'pivark-plugins-stub');
        $bucket    = trim(is_string($bucketRaw) ? $bucketRaw : 'pivark-plugins-stub');
        $accessRaw = config('plugin.market.storage_access_key', 'PIVARK_STUB_ACCESS');
        $accessKey = trim(is_string($accessRaw) ? $accessRaw : 'PIVARK_STUB_ACCESS');
        $secretRaw = config('plugin.market.storage_secret_key', '');
        $secretKey = trim(is_string($secretRaw) ? $secretRaw : '');
        $regionRaw = config('plugin.market.storage_region', 'us-east-1');
        $region    = trim(is_string($regionRaw) ? $regionRaw : 'us-east-1');
        $ttl       = max(1, $expiresAt - time());

        $sdkUrl = $this->buildWithAwsSdk($bucket, $objectKey, $region, $accessKey, $secretKey, $endpoint, $ttl);
        if ($sdkUrl !== null && $sdkUrl !== '') {
            return $sdkUrl;
        }

        $host      = parse_url($endpoint, PHP_URL_HOST) ?: ($bucket . '.s3.amazonaws.com');

        $amzDate   = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $scope     = $dateStamp . '/' . $region . '/s3/aws4_request';
        $credential = $accessKey . '/' . $scope;

        $canonicalUri = '/' . rawurlencode($bucket) . '/' . $this->uriEncodePath($objectKey);
        $canonicalQuery = http_build_query([
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'      => $credential,
            'X-Amz-Date'            => $amzDate,
            'X-Amz-Expires'         => (string) $ttl,
            'X-Amz-SignedHeaders'   => 'host',
        ], '', '&', PHP_QUERY_RFC3986);

        $canonicalHeaders = 'host:' . $host . "\n";
        $signedHeaders    = 'host';
        $payloadHash      = 'UNSIGNED-PAYLOAD';
        $canonicalRequest = "GET\n{$canonicalUri}\n{$canonicalQuery}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $stringToSign     = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonicalRequest);
        $signingKey       = $this->signingKey($secretKey, $dateStamp, $region);
        $signature        = hash_hmac('sha256', $stringToSign, $signingKey);

        $query = $canonicalQuery . '&X-Amz-Signature=' . $signature;

        return rtrim($endpoint, '/') . $canonicalUri . '?' . $query;
    }

    public function verifyPresignedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }
        parse_str((string) ($parts['query'] ?? ''), $rawQuery);
        $query = $this->normalizePresignedQuery($rawQuery);
        if (isset($query['X-Pivark-Signature'])) {
            return app(PluginPackageStorageService::class)->verifyPresignedQuery($query);
        }
        if (!isset($query['X-Amz-Signature'], $query['X-Amz-Expires'], $query['X-Amz-Date'])) {
            return false;
        }
        $expires = (int) $query['X-Amz-Expires'];
        $amzDate = (string) $query['X-Amz-Date'];
        if ($expires < 1 || $amzDate === '') {
            return false;
        }
        $issued = strtotime(substr($amzDate, 0, 8) . ' ' . substr($amzDate, 9, 2) . ':' . substr($amzDate, 11, 2) . ':' . substr($amzDate, 13, 2) . ' UTC');
        if ($issued === false || ($issued + $expires) < time()) {
            return false;
        }

        return $this->useSigV4();
    }

    /**
     * @return array{aws_s3:bool,aliyun_oss:bool,tencent_cos:bool,recommended:list<string>}
     */
    public function sdkCapabilities(): array
    {
        return $this->enterpriseStorageSdkBridge->capabilities();
    }

    /**
     * @param array<int|string, mixed> $query
     * @return array<string, bool|float|int|string|null>
     */
    private function normalizePresignedQuery(array $query): array
    {
        $out = [];
        foreach ($query as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function buildWithAwsSdk(
        string $bucket,
        string $objectKey,
        string $region,
        string $accessKey,
        string $secretKey,
        string $endpoint,
        int $ttlSeconds,
    ): ?string {
        if (!$this->enterpriseStorageSdkBridge->awsS3Available() || $secretKey === '') {
            return null;
        }

        return $this->enterpriseStorageSdkBridge->buildPluginMarketPresignedGetUrl(
            $bucket,
            $objectKey,
            $region,
            $accessKey,
            $secretKey,
            $endpoint,
            $ttlSeconds
        );
    }

    private function uriEncodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function signingKey(string $secret, string $dateStamp, string $region): string
    {
        $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $secret, true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
