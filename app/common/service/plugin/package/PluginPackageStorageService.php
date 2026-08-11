<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\package;
use app\common\service\plugin\market\PluginMarketShelfDirectory;
use app\common\service\plugin\package\S3PresignService;

/**
 * 插件包对象存储（catalog 直链 / Pivark 桩 / S3 SigV4 预签名）。
 */
final class PluginPackageStorageService
{
    public function __construct(
        private readonly S3PresignService $s3PresignService,
        private readonly PluginMarketShelfDirectory $pluginMarketRemoteCatalog,
    ) {
    }

    public const DRIVER_LOCAL   = 'local';
    public const DRIVER_S3_STUB = 's3_stub';
    public const DRIVER_S3      = 's3';

    private const DEFAULT_STUB_SECRET = 'pivark-market-stub';

    public function driver(): string
    {
        $raw = config('plugin.market.storage_driver', self::DRIVER_LOCAL);
        $v   = strtolower(trim(is_string($raw) ? $raw : self::DRIVER_LOCAL));
        $allowed = [self::DRIVER_LOCAL, self::DRIVER_S3_STUB, self::DRIVER_S3];

        return in_array($v, $allowed, true) ? $v : self::DRIVER_LOCAL;
    }

    public function signedUrlTtl(): int
    {
        return max(60, (int) config('plugin.market.signed_url_ttl', 900));
    }

    public function storageEndpoint(): string
    {
        $endpoint = rtrim(trim($this->configString('plugin_market.storage_endpoint', '')), '/');
        if ($endpoint !== '') {
            return $endpoint;
        }
        $bucket = trim($this->configString('plugin_market.storage_bucket', 'pivark-plugins-stub'));

        return 'https://' . $bucket . '.s3.amazonaws.com';
    }

    public function buildPivarkStubUrl(string $objectKey, int $expiresAt, string $identifier): string
    {
        $objectKey  = ltrim(trim($objectKey), '/');
        $identifier = strtolower(trim($identifier));
        $secret     = $this->storageSignSecret(false);
        $payload    = $objectKey . '|' . $expiresAt . '|' . $identifier;
        $signature  = hash_hmac('sha256', $payload, $secret);
        $base       = $this->storageEndpoint() . '/' . $objectKey;
        $query      = http_build_query([
            'X-Pivark-Expires'    => $expiresAt,
            'X-Pivark-Signature'  => $signature,
            'X-Pivark-Identifier' => $identifier,
        ], '', '&', PHP_QUERY_RFC3986);

        return $base . '?' . $query;
    }

    public function buildPresignedUrl(string $objectKey, int $expiresAt, string $identifier): string
    {
        return $this->s3PresignService->buildPresignedGetUrl($objectKey, $expiresAt, $identifier);
    }

    /**
     * @param array<string, mixed> $query
     */
    public function verifyPresignedQuery(array $query): bool
    {
        $query = $this->normalizeQueryParams($query);
        $identifier = strtolower(trim($this->queryScalar($query, 'X-Pivark-Identifier')));
        $expiresAt  = (int) $this->queryScalar($query, 'X-Pivark-Expires');
        $signature  = trim($this->queryScalar($query, 'X-Pivark-Signature'));
        if ($identifier === '' || $expiresAt < time() || $signature === '') {
            return false;
        }
        $objectKey = 'weapp/' . $identifier . '.zip';
        $secret    = $this->storageSignSecret(true);
        if ($secret === '') {
            return false;
        }
        $payload  = $objectKey . '|' . $expiresAt . '|' . $identifier;
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    public function verifyPresignedUrl(string $url): bool
    {
        return $this->s3PresignService->verifyPresignedUrl($url);
    }

    /** 生产 + remote driver 时默认要求签名 URL（可用 PIVARK_PLUGIN_REQUIRE_SIGNED_DOWNLOAD 覆盖）。 */
    public function requireSignedDownload(): bool
    {
        $raw = env('PIVARK_PLUGIN_REQUIRE_SIGNED_DOWNLOAD');
        if (is_string($raw) && $raw !== '') {
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
        }

        $appEnv = env('APP_ENV', '');
        $appEnv = is_string($appEnv) ? strtolower($appEnv) : '';

        return $appEnv === 'production'
            && $this->driver() !== self::DRIVER_LOCAL;
    }

    /**
     * 安装前校验签名 URL（remote driver 且 URL 带签名参数时强制验签）。
     */
    public function guardInstallUrl(string $identifier, string $url): ?string
    {
        $identifier = strtolower(trim($identifier));
        $url        = trim($url);
        if ($identifier === '' || $url === '') {
            return 'package_url 无效';
        }
        if ($this->driver() === self::DRIVER_LOCAL) {
            return null;
        }
        $requireSigned = $this->requireSignedDownload();
        $hasSig        = str_contains($url, 'X-Pivark-Signature=') || str_contains($url, 'X-Amz-Signature=');
        if (!$hasSig) {
            return $requireSigned ? '远程存储已启用，须使用签名 package_url' : null;
        }
        if (!$this->verifyPresignedUrl($url)) {
            return '插件包签名 URL 无效或已过期';
        }
        $queryString = parse_url($url, PHP_URL_QUERY);
        parse_str(is_string($queryString) ? $queryString : '', $rawQuery);
        $query = $this->normalizeQueryParams($rawQuery);
        $idFromUrl = strtolower(trim($this->queryScalar($query, 'X-Pivark-Identifier')));
        if ($idFromUrl !== '' && $idFromUrl !== $identifier) {
            return '签名 URL 与插件 identifier 不匹配';
        }

        return null;
    }

    /**
     * @return array{mode:string,url:string,catalog_url?:string,object_key?:string,bucket?:string,expires_at?:int,signature?:string,presign?:string,msg?:string}
     */
    public function resolvePackageUrl(string $identifier, string $catalogUrl = ''): array
    {
        $identifier = strtolower(trim($identifier));
        if ($catalogUrl === '') {
            $row = $this->pluginMarketRemoteCatalog->indexByIdentifier()[$identifier] ?? null;
            $catalogUrl = is_array($row) ? trim((string) ($row['package_url'] ?? '')) : '';
        }
        if ($catalogUrl === '') {
            return ['mode' => 'none', 'url' => '', 'msg' => '无 package_url'];
        }
        if ($this->driver() === self::DRIVER_LOCAL) {
            return [
                'mode' => 'direct',
                'url'  => $catalogUrl,
                'msg'  => 'catalog 直链（开源版默认）',
            ];
        }

        $expires   = time() + $this->signedUrlTtl();
        $objectKey = 'weapp/' . $identifier . '.zip';
        $presigned = $this->buildPresignedUrl($objectKey, $expires, $identifier);
        $presign   = $this->s3PresignService->useSigV4() ? 'sigv4' : 'pivark_stub';

        return [
            'mode'        => 'signed_' . $presign,
            'url'         => $presigned,
            'catalog_url' => $catalogUrl,
            'object_key'  => $objectKey,
            'bucket'      => trim($this->configString('plugin_market.storage_bucket', 'pivark-plugins-stub')),
            'expires_at'  => $expires,
            'presign'     => $presign,
            'msg'         => 'S3 预签名 URL；安装仍支持 catalog 直链降级',
        ];
    }

    private function configString(string $key, string $default = ''): string
    {
        $raw = config($key, $default);

        return is_string($raw) ? $raw : $default;
    }

    /** 非开发车道禁止硬编码默认桩密钥（对齐 UploadDirectSecurity） */
    private function storageSignSecret(bool $forVerify): string
    {
        $secret = trim($this->configString('plugin_market.storage_sign_secret', ''));
        if ($secret === '') {
            $secret = self::DEFAULT_STUB_SECRET;
        }
        $env   = strtolower(trim((string) env('PIVARK_ENV', env('APP_ENV', 'dev'))));
        $isDev = in_array($env, ['dev', 'demo-local', 'local', 'test'], true);
        if (!$isDev && hash_equals(self::DEFAULT_STUB_SECRET, $secret)) {
            if ($forVerify) {
                return '';
            }
            throw new \RuntimeException('生产环境须配置 plugin_market.storage_sign_secret，不可使用默认桩密钥');
        }

        return $secret;
    }

    /**
     * @param array<int|string, mixed> $raw
     * @return array<string, string>
     */
    private function normalizeQueryParams(array $raw): array
    {
        $query = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || is_array($value) || $value === null) {
                continue;
            }
            $query[$key] = (string) $value;
        }

        return $query;
    }

    /**
     * @param array<string, string> $query
     */
    private function queryScalar(array $query, string $key): string
    {
        return $query[$key] ?? '';
    }
}
