<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\release;

use app\common\support\ServiceResult;

/** 核心升级包 RSA-SHA256 验签（对 manifest 中的 sha256 十六进制串签名） */
final class CoreUpdateSignatureService
{

    public function hasPublicKey(): bool
    {
        return $this->publicKeyPem() !== '';
    }

    public function isRequireSignature(): bool
    {
        if (!(bool) config('release.signing.require_signature')) {
            return false;
        }

        return $this->hasPublicKey();
    }

    public function publicKeyPem(): string
    {
        $pem = trim((string) config('release.signing.public_key_pem', ''));

        return str_contains($pem, 'BEGIN PUBLIC KEY') ? $pem : '';
    }

    /**
     * @param string $sha256Hex 64 位十六进制 SHA256（与 updates.json 一致）
     * @param string $signatureBase64 RSA 签名（Base64）
     */
    public function verifySha256Hex(string $sha256Hex, string $signatureBase64): ServiceResult
    {
        $sha256Hex = strtolower(trim($sha256Hex));
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256Hex)) {
            return ServiceResult::fail('SHA256 格式无效，无法验签');
        }

        $pem = $this->publicKeyPem();
        if ($pem === '') {
            return ServiceResult::fail('未配置升级包验签公钥');
        }

        $signature = base64_decode(trim($signatureBase64), true);
        if ($signature === false || $signature === '') {
            return ServiceResult::fail('升级包 RSA 签名格式无效');
        }

        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            return ServiceResult::fail('升级包验签公钥无效');
        }

        $ok = openssl_verify($sha256Hex, $signature, $key, OPENSSL_ALGO_SHA256);
        if (PHP_VERSION_ID < 80000 && is_resource($key)) {
            openssl_free_key($key);
        }

        if ($ok !== 1) {
            return ServiceResult::fail('升级包 RSA 签名校验失败');
        }

        return ServiceResult::ok(null, 'RSA 签名校验通过');
    }

    /**
     * 发行端工具：对 sha256 十六进制串签名（私钥仅发布机持有，不进客户包）
     */
    public function signSha256HexForPublish(string $sha256Hex, string $privateKeyPem): ServiceResult
    {
        $sha256Hex = strtolower(trim($sha256Hex));
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256Hex)) {
            return ServiceResult::fail('SHA256 格式无效');
        }

        $privateKeyPem = trim($privateKeyPem);
        if ($privateKeyPem === '' || !str_contains($privateKeyPem, 'PRIVATE KEY')) {
            return ServiceResult::fail('私钥无效');
        }

        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            return ServiceResult::fail('无法加载私钥');
        }

        $signature = '';
        if (!openssl_sign($sha256Hex, $signature, $key, OPENSSL_ALGO_SHA256)) {
            if (PHP_VERSION_ID < 80000 && is_resource($key)) {
                openssl_free_key($key);
            }

            return ServiceResult::fail('RSA 签名失败');
        }
        if (PHP_VERSION_ID < 80000 && is_resource($key)) {
            openssl_free_key($key);
        }

        return ServiceResult::ok(['signature' => base64_encode($signature)], '签名成功');
    }
}
