<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\upload;

use app\common\support\ServiceResult;

use app\common\service\config\ConfigService;
use app\common\service\storage\EnterpriseStorageSdkBridge;

/** AWS S3 预签名 PUT（需 composer require aws/aws-sdk-php） */
final class S3PresignBuilder
{

  /**
   * @return ServiceResult
   */
    public function buildPutUrl(string $objectKey, int $expiresAt, int $maxSize): ServiceResult
    {
        if (!app(EnterpriseStorageSdkBridge::class)->awsS3Available()) {
            return ServiceResult::fail('未安装 aws/aws-sdk-php');
        }

        $bucket = trim((string) app(ConfigService::class)->get('upload_direct_s3_bucket', ''));
        $region = trim((string) app(ConfigService::class)->get('upload_direct_s3_region', 'us-east-1'));
        $key    = trim((string) app(ConfigService::class)->get('upload_direct_s3_access_key', ''));
        $secret = trim((string) app(ConfigService::class)->get('upload_direct_s3_secret_key', ''));
        $endpoint = trim((string) app(ConfigService::class)->get('upload_direct_s3_endpoint', ''));
        if ($bucket === '' || $key === '' || $secret === '' || $objectKey === '') {
            return ServiceResult::fail('S3 直传未配置完整');
        }

        try {
            $config = [
                'version'     => 'latest',
                'region'      => $region,
                'credentials' => [
                    'key'    => $key,
                    'secret' => $secret,
                ],
            ];
            if ($endpoint !== '') {
                $config['endpoint'] = $endpoint;
            }

            $client = new \Aws\S3\S3Client($config);
            $cmd    = $client->getCommand('PutObject', [
                'Bucket'      => $bucket,
                'Key'         => ltrim($objectKey, '/'),
                'ContentType' => 'application/octet-stream',
            ]);
            $ttl = max(60, min(3600, $expiresAt - time()));
            $request = $client->createPresignedRequest($cmd, '+' . $ttl . ' seconds');
            $url     = (string) $request->getUri();

            return ServiceResult::ok(['upload_url' => $url, 'method' => 'PUT', 'headers' => [
                    'Content-Type' => 'application/octet-stream',
                ], 'max_size' => max(1, $maxSize)], 'ok');
        } catch (\Throwable $e) {
            return ServiceResult::fail('S3 预签名失败：' . $e->getMessage());
        }
    }
}
