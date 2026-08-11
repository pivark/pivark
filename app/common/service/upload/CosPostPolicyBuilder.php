<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\upload;

use app\common\service\config\ConfigService;

/** 腾讯云 COS POST Policy 构建（无 SDK 依赖；与 UploadDirectSignService cos_stub 配套）。 */
final class CosPostPolicyBuilder
{

    /**
     * @return array<string, mixed>
     */
    public function build(
        string $objectKey,
        int $expiresAt,
        int $maxSize,
        string $scene,
        string $issueSignature,
        string $callbackUrl = '/api/v1/upload/oss-callback',
    ): array {
        $bucket    = trim((string) app(ConfigService::class)->get('upload_direct_cos_bucket', 'pivark-cos-stub'));
        $region    = trim((string) app(ConfigService::class)->get('upload_direct_cos_region', 'ap-guangzhou'));
        $host      = trim((string) app(ConfigService::class)->get('upload_direct_cos_host', ''));
        if ($host === '') {
            $host = 'https://' . $bucket . '.cos.' . $region . '.myqcloud.com';
        }
        $secretId  = trim((string) app(ConfigService::class)->get('upload_direct_cos_secret_id', 'COS_STUB_SECRET_ID'));
        $secretKey = trim((string) app(ConfigService::class)->get('upload_direct_cos_secret_key', 'COS_STUB_SECRET_KEY'));
        $dir       = dirname($objectKey);
        if ($dir !== '.') {
            $dir .= '/';
        } else {
            $dir = '';
        }

        $policyDoc = [
            'expiration' => gmdate('Y-m-d\TH:i:s\Z', $expiresAt),
            'conditions' => [
                ['bucket' => $bucket],
                ['q-sign-algorithm' => 'sha1'],
                ['q-ak' => $secretId],
                ['starts-with', '$key', $dir],
                ['content-length-range', 0, max(1, $maxSize)],
            ],
        ];
        $policyJson = json_encode($policyDoc, JSON_UNESCAPED_SLASHES);
        $policyB64  = base64_encode($policyJson !== false ? $policyJson : '{}');
        $sign       = base64_encode(hash_hmac('sha1', $policyB64, $secretKey, true));

        return [
            'host'                  => $host,
            'bucket'                => $bucket,
            'region'                => $region,
            'dir'                   => $dir,
            'key'                   => $objectKey,
            'policy'                => $policyB64,
            'signature'             => $sign,
            'access_key_id'         => $secretId,
            'success_action_status' => '200',
            'callback'              => base64_encode(json_encode([
                'callbackUrl'      => $callbackUrl,
                'callbackBody'     => 'object_key=${object}&scene=' . rawurlencode($scene)
                    . '&expires_at=' . $expiresAt
                    . '&signature=' . rawurlencode($issueSignature)
                    . '&file_size=${size}&mime=${mimeType}',
                'callbackBodyType' => 'application/x-www-form-urlencoded',
            ], JSON_UNESCAPED_SLASHES) ?: '{}'),
        ];
    }

    public function publicUrlForObjectKey(string $objectKey): string
    {
        $host = rtrim(trim((string) app(ConfigService::class)->get('upload_direct_cos_host', '')), '/');
        if ($host === '') {
            $bucket = trim((string) app(ConfigService::class)->get('upload_direct_cos_bucket', 'pivark-cos-stub'));
            $region = trim((string) app(ConfigService::class)->get('upload_direct_cos_region', 'ap-guangzhou'));
            $host   = 'https://' . $bucket . '.cos.' . $region . '.myqcloud.com';
        }

        return $host . '/' . ltrim($objectKey, '/');
    }

    /** 校验 COS 回调 Authorization；密钥未配时仅 dev 环境放行 */
    public function verifyCosCallbackAuthorization(string $authorization, string $callbackBody): bool
    {
        $authorization = trim($authorization);
        if ($authorization === '' || $callbackBody === '') {
            return false;
        }
        $secretKey = trim((string) app(ConfigService::class)->get('upload_direct_cos_secret_key', ''));
        if ($secretKey === '' || $secretKey === UploadDirectSecurity::DEFAULT_COS_SECRET_KEY) {
            return app(UploadDirectSecurity::class)->allowsDevDefaults();
        }
        $decoded = base64_decode($authorization, true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return false;
        }
        [$secretId, $cosSign] = explode(':', $decoded, 2);
        $expectedSecretId = trim((string) app(ConfigService::class)->get('upload_direct_cos_secret_id', ''));
        if ($expectedSecretId !== '' && !hash_equals($expectedSecretId, $secretId)) {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha1', $callbackBody, $secretKey, true));

        return hash_equals($expected, $cosSign);
    }
}
