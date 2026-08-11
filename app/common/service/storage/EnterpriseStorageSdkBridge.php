<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\storage;

use app\common\support\OpsLog;

/** Enterprise 可选 SDK 探测（开源版不 require；生产 `composer require` 后自动启用）。 */
final class EnterpriseStorageSdkBridge
{

    public function awsS3Available(): bool
    {
        return class_exists(\Aws\S3\S3Client::class);
    }

    public function aliyunOssAvailable(): bool
    {
        return class_exists(\OSS\OssClient::class);
    }

    public function tencentCosAvailable(): bool
    {
        return class_exists(\Qcloud\Cos\Client::class);
    }

    /**
     * @return array{aws_s3:bool,aliyun_oss:bool,tencent_cos:bool,recommended:list<string>}
     */
    public function capabilities(): array
    {
        $recommended = [];
        if (!$this->awsS3Available()) {
            $recommended[] = 'composer require aws/aws-sdk-php';
        }
        if (!$this->aliyunOssAvailable()) {
            $recommended[] = 'composer require aliyuncs/oss-sdk-php';
        }
        if (!$this->tencentCosAvailable()) {
            $recommended[] = 'composer require qcloud/cos-sdk-v5';
        }

        return [
            'aws_s3'      => $this->awsS3Available(),
            'aliyun_oss'  => $this->aliyunOssAvailable(),
            'tencent_cos' => $this->tencentCosAvailable(),
            'recommended' => $recommended,
        ];
    }

    public function buildPluginMarketPresignedGetUrl(
        string $bucket,
        string $objectKey,
        string $region,
        string $accessKey,
        string $secretKey,
        string $endpoint,
        int $ttlSeconds,
    ): ?string {
        if (!$this->awsS3Available() || $secretKey === '') {
            return null;
        }
        try {
            $config = [
                'version'     => 'latest',
                'region'      => $region,
                'credentials' => [
                    'key'    => $accessKey,
                    'secret' => $secretKey,
                ],
            ];
            if ($endpoint !== '' && parse_url($endpoint, PHP_URL_SCHEME)) {
                $config['endpoint'] = $endpoint;
            }
            $client  = new \Aws\S3\S3Client($config);
            $command = $client->getCommand('GetObject', [
                'Bucket' => $bucket,
                'Key'    => $objectKey,
            ]);
            $request = $client->createPresignedRequest($command, '+' . $ttlSeconds . ' seconds');

            return (string) $request->getUri();
        } catch (\Throwable $e) {
            OpsLog::businessWarning('s3_presign_failed', ['msg' => $e->getMessage()]);

            return null;
        }
    }
}
