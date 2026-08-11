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

/** Aliyun OSS POST Policy 构建（无 SDK 依赖；与 UploadDirectSignService 配套）。 */
final class OssPostPolicyBuilder
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
        $bucket    = trim((string) app(ConfigService::class)->get('upload_direct_oss_bucket', 'pivark-stub'));
        $host      = trim((string) app(ConfigService::class)->get('upload_direct_oss_host', ''));
        if ($host === '') {
            $host = 'https://' . $bucket . '.oss-cn-hangzhou.aliyuncs.com';
        }
        $accessKey = trim((string) app(ConfigService::class)->get('upload_direct_oss_access_key', 'OSS_STUB_ACCESS_KEY'));
        $secretKey = trim((string) app(ConfigService::class)->get('upload_direct_oss_secret_key', 'OSS_STUB_SECRET_KEY'));
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
            'dir'                   => $dir,
            'key'                   => $objectKey,
            'policy'                => $policyB64,
            'signature'             => $sign,
            'access_key_id'         => $accessKey,
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
        $host = rtrim(trim((string) app(ConfigService::class)->get('upload_direct_oss_host', '')), '/');
        if ($host === '') {
            $bucket = trim((string) app(ConfigService::class)->get('upload_direct_oss_bucket', 'pivark-stub'));
            $host   = 'https://' . $bucket . '.oss-cn-hangzhou.aliyuncs.com';
        }

        return $host . '/' . ltrim($objectKey, '/');
    }

    /**
     * 校验 Aliyun 回调 Authorization（Base64 `OSS AccessKeyId:signature`）；密钥未配时跳过。
     */
    public function verifyAliyunCallbackAuthorization(string $authorization, string $callbackBody): bool
    {
        $authorization = trim($authorization);
        if ($authorization === '' || $callbackBody === '') {
            return false;
        }
        $secretKey = trim((string) app(ConfigService::class)->get('upload_direct_oss_secret_key', ''));
        if ($secretKey === '' || $secretKey === UploadDirectSecurity::DEFAULT_OSS_SECRET_KEY) {
            return app(UploadDirectSecurity::class)->allowsDevDefaults();
        }
        $decoded = base64_decode($authorization, true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return false;
        }
        [$accessKeyId, $ossSign] = explode(':', $decoded, 2);
        $expectedAccessKey = trim((string) app(ConfigService::class)->get('upload_direct_oss_access_key', ''));
        if ($expectedAccessKey !== '' && !hash_equals($expectedAccessKey, $accessKeyId)) {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha1', $callbackBody, $secretKey, true));

        return hash_equals($expected, $ossSign);
    }
}
