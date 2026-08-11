<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\storage;

use app\common\service\config\ConfigService;
use app\common\support\OpsLog;

/** Enterprise 直传：SDK 就绪探测 + 回调后对象存在性校验（无 SDK 或 stub 密钥时跳过）。 */
final class EnterpriseUploadDirectBridge
{

    public function ossCredentialsConfigured(): bool
    {
        $secret = trim((string) app(ConfigService::class)->get('upload_direct_oss_secret_key', ''));
        $key    = trim((string) app(ConfigService::class)->get('upload_direct_oss_access_key', ''));

        return $secret !== ''
            && $key !== ''
            && $secret !== 'OSS_STUB_SECRET_KEY'
            && $key !== 'OSS_STUB_ACCESS_KEY';
    }

    public function cosCredentialsConfigured(): bool
    {
        $secret = trim((string) app(ConfigService::class)->get('upload_direct_cos_secret_key', ''));
        $id     = trim((string) app(ConfigService::class)->get('upload_direct_cos_secret_id', ''));

        return $secret !== ''
            && $id !== ''
            && $secret !== 'COS_STUB_SECRET_KEY'
            && $id !== 'COS_STUB_SECRET_ID';
    }

    public function ossSdkReady(): bool
    {
        return app(EnterpriseStorageSdkBridge::class)->aliyunOssAvailable() && $this->ossCredentialsConfigured();
    }

    public function cosSdkReady(): bool
    {
        return app(EnterpriseStorageSdkBridge::class)->tencentCosAvailable() && $this->cosCredentialsConfigured();
    }

    public function s3CredentialsConfigured(): bool
    {
        $bucket = trim((string) app(ConfigService::class)->get('upload_direct_s3_bucket', ''));
        $key    = trim((string) app(ConfigService::class)->get('upload_direct_s3_access_key', ''));
        $secret = trim((string) app(ConfigService::class)->get('upload_direct_s3_secret_key', ''));

        return $bucket !== '' && $key !== '' && $secret !== '';
    }

    public function s3SdkReady(): bool
    {
        return app(EnterpriseStorageSdkBridge::class)->awsS3Available() && $this->s3CredentialsConfigured();
    }

    public function assertOssObjectExists(string $objectKey): bool
    {
        if (!$this->ossSdkReady()) {
            return true;
        }
        $bucket = trim((string) app(ConfigService::class)->get('upload_direct_oss_bucket', ''));
        $key    = trim((string) app(ConfigService::class)->get('upload_direct_oss_access_key', ''));
        $secret = trim((string) app(ConfigService::class)->get('upload_direct_oss_secret_key', ''));
        $host   = trim((string) app(ConfigService::class)->get('upload_direct_oss_host', ''));
        if ($bucket === '' || $objectKey === '') {
            return false;
        }
        try {
            $endpoint = $host !== '' ? parse_url($host, PHP_URL_HOST) : null;
            $client   = new \OSS\OssClient($key, $secret, $endpoint ?: 'oss-cn-hangzhou.aliyuncs.com');

            return $client->doesObjectExist($bucket, ltrim($objectKey, '/'));
        } catch (\Throwable $e) {
            OpsLog::businessWarning('enterprise_upload_oss_object_probe_failed', [
                'key' => $objectKey,
                'msg' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function assertCosObjectExists(string $objectKey): bool
    {
        if (!$this->cosSdkReady()) {
            return true;
        }
        $bucket = trim((string) app(ConfigService::class)->get('upload_direct_cos_bucket', ''));
        $region = trim((string) app(ConfigService::class)->get('upload_direct_cos_region', 'ap-guangzhou'));
        $id     = trim((string) app(ConfigService::class)->get('upload_direct_cos_secret_id', ''));
        $secret = trim((string) app(ConfigService::class)->get('upload_direct_cos_secret_key', ''));
        if ($bucket === '' || $objectKey === '') {
            return false;
        }
        try {
            $client = new \Qcloud\Cos\Client([
                'region'      => $region,
                'schema'      => 'https',
                'credentials' => [
                    'secretId'  => $id,
                    'secretKey' => $secret,
                ],
            ]);

            return $client->doesObjectExist($bucket, ltrim($objectKey, '/'));
        } catch (\Throwable $e) {
            OpsLog::businessWarning('enterprise_upload_cos_object_probe_failed', [
                'key' => $objectKey,
                'msg' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
