<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\upload;

use app\common\exception\UploadException;
use app\common\support\AppTime;
use app\common\service\upload\CosPostPolicyBuilder;
use app\common\service\upload\OssPostPolicyBuilder;
use app\common\service\upload\S3PresignBuilder;
use app\common\service\upload\UploadDirectSecurity;
use app\common\service\upload\UploadService;

use app\common\service\config\ConfigService;
use app\common\service\storage\EnterpriseUploadDirectBridge;

/**
 * 直传签名（本地仍 SSOT；Enterprise OSS/COS POST Policy + 回调闭环）。
 */
final class UploadDirectSignService
{

    public function __construct(
        private readonly ConfigService $configService,
        private readonly EnterpriseUploadDirectBridge $enterpriseUploadDirectBridge,
        private readonly UploadService $uploadService,
        private readonly UploadDirectSecurity $uploadDirectSecurity,
        private readonly CosPostPolicyBuilder $cosPostPolicyBuilder,
        private readonly OssPostPolicyBuilder $ossPostPolicyBuilder,
        private readonly S3PresignBuilder $s3PresignBuilder,
    ) {
    }

    public function enabled(): bool
    {
        return (string) $this->configService->get('upload_direct_enabled', '0') === '1';
    }

    public function provider(): string
    {
        $v = strtolower(trim((string) $this->configService->get('upload_direct_provider', 'oss_stub')));

        return in_array($v, ['oss_stub', 'oss_aliyun', 'cos_stub', 's3_aws'], true) ? $v : 'oss_stub';
    }

    public function isCosProvider(): bool
    {
        return $this->provider() === 'cos_stub';
    }

    public function isOssAliyunProvider(): bool
    {
        return $this->provider() === 'oss_aliyun';
    }

    public function isS3Provider(): bool
    {
        return $this->provider() === 's3_aws';
    }

    public function sdkBacked(): bool
    {
        if ($this->isCosProvider()) {
            return $this->enterpriseUploadDirectBridge->cosSdkReady();
        }
        if ($this->isS3Provider()) {
            return $this->enterpriseUploadDirectBridge->s3SdkReady();
        }

        return $this->isOssAliyunProvider() && $this->enterpriseUploadDirectBridge->ossSdkReady();
    }

    /**
     * @return array<string, mixed>
     */
    public function issue(string $scene, string $filename = '', int $fileSize = 0): array
    {
        $meta = $this->uploadService->sceneMeta($scene);
        if (!$this->enabled()) {
            return [
                'mode'       => 'local',
                'upload_url' => '/api/v1/upload',
                'scene'      => $scene,
                'scene_meta' => $meta,
                'msg'        => '本地上传 SSOT；直传未启用（upload_direct_enabled=0）',
            ];
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename) ?: 'file';
        // 无扩展名（空文件名 → file）不挡签；有扩展名则与本地上传白名单一致
        if (pathinfo($safeName, PATHINFO_EXTENSION) !== '') {
            try {
                UploadService::scene($scene)->assertFilenameExtension($safeName);
            } catch (UploadException|\InvalidArgumentException $e) {
                return [
                    'mode'       => 'local',
                    'upload_url' => '/api/v1/upload',
                    'scene'      => $scene,
                    'scene_meta' => $meta,
                    'msg'        => $e->getMessage(),
                ];
            }
        }

        $objectKey = trim($scene, '/') . '/' . AppTime::format('Y/m/d') . '/' . bin2hex(random_bytes(8)) . '-' . $safeName;
        $expiresAt = time() + 900;
        $maxSize   = (int) round(((float) ($meta['max_size_mb'] ?? 10)) * 1024 * 1024);
        if ($fileSize > 0) {
            $maxSize = min($maxSize, $fileSize);
        }
        $this->uploadDirectSecurity->assertDirectUploadReady();
        $secret    = $this->uploadDirectSecurity->signSecret();
        $payload   = $objectKey . '|' . $expiresAt . '|' . $scene;
        $signature = hash_hmac('sha256', $payload, $secret);

        if ($this->isS3Provider()) {
            $presign = $this->s3PresignBuilder->buildPutUrl($objectKey, $expiresAt, $maxSize);
            if (!$presign->isOk()) {
                return [
                    'mode'       => 'local',
                    'upload_url' => '/api/v1/upload',
                    'scene'      => $scene,
                    'scene_meta' => $meta,
                    'msg'        => (string) ($presign->message() ?? 'S3 直传不可用'),
                ];
            }

            return [
                'mode'                => 'direct_s3',
                'provider'            => $this->provider(),
                'upload_url'          => (string) ($presign['upload_url'] ?? ''),
                'method'              => (string) ($presign['method'] ?? 'PUT'),
                'headers'             => is_array($presign['headers'] ?? null) ? $presign['headers'] : [],
                'fallback_upload_url' => '/api/v1/upload',
                'scene'               => $scene,
                'scene_meta'          => $meta,
                'object_key'          => $objectKey,
                'expires_at'          => $expiresAt,
                'max_size'            => max(1, (int) ($presign['max_size'] ?? $maxSize)),
                'signature'           => $signature,
                'callback_url'        => '/api/v1/upload/oss-callback',
                'callback_params'     => [
                    'object_key' => $objectKey,
                    'scene'      => $scene,
                    'expires_at' => $expiresAt,
                    'signature'  => $signature,
                ],
                'sdk_backed'          => $this->sdkBacked(),
                'msg'                 => 'S3 预签名 PUT + 回调验签；降级 POST /api/v1/upload',
            ];
        }

        $cosMode = $this->isCosProvider();
        $post    = $cosMode
            ? $this->cosPostPolicyBuilder->build($objectKey, $expiresAt, $maxSize, $scene, $signature)
            : $this->ossPostPolicyBuilder->build($objectKey, $expiresAt, $maxSize, $scene, $signature);

        return [
            'mode'                => $cosMode ? 'direct_cos' : 'direct_oss',
            'provider'            => $this->provider(),
            'upload_url'          => (string) ($post['host'] ?? ''),
            'fallback_upload_url' => '/api/v1/upload',
            'scene'               => $scene,
            'scene_meta'          => $meta,
            'object_key'          => $objectKey,
            'expires_at'          => $expiresAt,
            'max_size'            => $maxSize,
            'signature'           => $signature,
            'oss_post'            => $post,
            'callback_url'        => '/api/v1/upload/oss-callback',
            'callback_params'     => [
                'object_key' => $objectKey,
                'scene'      => $scene,
                'expires_at' => $expiresAt,
                'signature'  => $signature,
            ],
            'sdk_backed'          => $this->sdkBacked(),
            'msg'                 => ($cosMode ? 'COS' : 'OSS') . ' POST Policy + 回调验签；降级 POST /api/v1/upload',
        ];
    }
}
