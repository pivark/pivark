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
use app\common\support\ServiceResult;
use app\common\service\upload\OssPostPolicyBuilder;
use app\common\service\upload\CosPostPolicyBuilder;
use app\common\service\upload\UploadDirectSignService;
use app\common\service\upload\UploadDirectSecurity;
use app\common\service\upload\UploadService;

use app\common\service\config\ConfigService;
use app\common\service\media\MediaAssetService;
use app\common\service\storage\EnterpriseUploadDirectBridge;

/**
 * OSS 直传回调验签 + 写 media_assets（Enterprise 落盘前占位；本地 SSOT 仍可用 POST /upload）。
 */
final class UploadDirectCallbackService
{

    public function __construct(
        private readonly UploadDirectSecurity $uploadDirectSecurity,
        private readonly UploadDirectSignService $uploadDirectSignService,
        private readonly CosPostPolicyBuilder $cosPostPolicyBuilder,
        private readonly OssPostPolicyBuilder $ossPostPolicyBuilder,
        private readonly EnterpriseUploadDirectBridge $enterpriseUploadDirectBridge,
        private readonly MediaAssetService $mediaAssetService,
    ) {
    }

    public function verifyIssueSignature(
        string $objectKey,
        int $expiresAt,
        string $scene,
        string $signature,
    ): bool {
        if ($objectKey === '' || $scene === '' || $expiresAt < time() || $signature === '') {
            return false;
        }
        if ($this->uploadDirectSecurity->usesDefaultSignSecret() && !$this->uploadDirectSecurity->allowsDevDefaults()) {
            return false;
        }
        $secret  = $this->uploadDirectSecurity->signSecret();
        $payload = $objectKey . '|' . $expiresAt . '|' . $scene;

        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    public function publicUrlForObjectKey(string $objectKey): string
    {
        if ($this->uploadDirectSignService->isCosProvider()) {
            return $this->cosPostPolicyBuilder->publicUrlForObjectKey($objectKey);
        }

        return $this->ossPostPolicyBuilder->publicUrlForObjectKey($objectKey);
    }

    /**
     * @param array<string, mixed> $params
     * @param string $rawBody 原始 POST body（Aliyun Authorization 验签用）
     */
    public function handle(array $params, string $rawBody = '', string $authorization = ''): ServiceResult
    {
        if (!$this->uploadDirectSignService->enabled()) {
            return ServiceResult::fail('直传未启用');
        }
        if (!$this->uploadDirectSecurity->callbackAllowed()) {
            return ServiceResult::fail('直传回调未启用或配置不安全');
        }

        $objectKey = trim((string) ($params['object_key'] ?? ''));
        $scene     = trim((string) ($params['scene'] ?? ''));
        $expiresAt = (int) ($params['expires_at'] ?? 0);
        $signature = trim((string) ($params['signature'] ?? ''));

        if (!$this->verifyIssueSignature($objectKey, $expiresAt, $scene, $signature)) {
            return ServiceResult::fail('签名无效或已过期');
        }

        if ($this->uploadDirectSignService->isOssAliyunProvider()) {
            if ($rawBody === '' || trim($authorization) === '') {
                return ServiceResult::fail('缺少 OSS 回调 Authorization');
            }
            if (!$this->ossPostPolicyBuilder->verifyAliyunCallbackAuthorization($authorization, $rawBody)) {
                return ServiceResult::fail('OSS Authorization 无效');
            }
        } elseif ($this->uploadDirectSignService->isCosProvider()) {
            if ($rawBody === '' || trim($authorization) === '') {
                return ServiceResult::fail('缺少 COS 回调 Authorization');
            }
            if (!$this->cosPostPolicyBuilder->verifyCosCallbackAuthorization($authorization, $rawBody)) {
                return ServiceResult::fail('COS Authorization 无效');
            }
        }

        if ($this->uploadDirectSignService->isCosProvider()) {
            if (!$this->enterpriseUploadDirectBridge->assertCosObjectExists($objectKey)) {
                return ServiceResult::fail('COS 对象不存在或未落盘');
            }
        } elseif ($this->uploadDirectSignService->isOssAliyunProvider()) {
            if (!$this->enterpriseUploadDirectBridge->assertOssObjectExists($objectKey)) {
                return ServiceResult::fail('OSS 对象不存在或未落盘');
            }
        }

        $filename = trim((string) ($params['filename'] ?? ''));
        if ($filename === '') {
            $filename = basename($objectKey);
        }

        try {
            UploadService::scene($scene);
            foreach (array_unique([$filename, basename($objectKey)]) as $name) {
                if (pathinfo($name, PATHINFO_EXTENSION) === '') {
                    continue;
                }
                UploadService::scene($scene)->assertFilenameExtension($name);
            }
        } catch (UploadException|\InvalidArgumentException $e) {
            return ServiceResult::fail($e->getMessage());
        }

        $fileSize = max(0, (int) ($params['file_size'] ?? 0));
        $hash     = $this->mediaAssetService->normalizeHash((string) ($params['content_hash'] ?? ''));
        if ($hash === '') {
            $hash = hash('sha256', $objectKey . '|' . $fileSize);
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $url = $this->publicUrlForObjectKey($objectKey);

        $mediaId = $this->mediaAssetService->registerWithAlias([
            'content_hash'  => $hash,
            'file_size'     => $fileSize,
            'mime'          => (string) ($params['mime'] ?? ''),
            'ext'           => $ext,
            'scene'         => $scene,
            'path'          => 'oss://' . ltrim($objectKey, '/'),
            'url'           => $url,
            'original_name' => $filename,
        ]);

        $client = [
            'url'       => $url,
            'path'      => 'oss://' . ltrim($objectKey, '/'),
            'filename'  => $filename,
            'media_id'  => $mediaId,
            'object_key'=> $objectKey,
            'direct'    => true,
        ];

        return ServiceResult::ok($client, '直传回调登记成功');
    }
}
