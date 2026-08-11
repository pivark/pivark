<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\package;

/** 插件 zip 包 HMAC 签名（Phase 8.4 MVP） */
final class PluginPackageSignatureService
{
    public const SIG_FILENAME = '.pivark-package.sig';

    public function signingKey(): string
    {
        return trim((string) env('PIVARK_PLUGIN_SIGN_KEY', ''));
    }

    public function signRequired(): bool
    {
        return strtolower(trim((string) env('PIVARK_PLUGIN_SIGN_REQUIRED', '0'))) === '1';
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function signPayload(string $identifier, array $manifest): string
    {
        $key = $this->signingKey();
        if ($key === '') {
            return '';
        }
        $payload = json_encode([
            'identifier' => strtolower(trim($identifier)),
            'version'    => (string) ($manifest['version'] ?? ''),
            'package'    => (string) ($manifest['package'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', (string) $payload, $key);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function writeSidecar(string $zipPath, string $identifier, array $manifest): bool
    {
        $sig = $this->signPayload($identifier, $manifest);
        if ($sig === '') {
            return true;
        }
        $body = json_encode([
            'identifier' => strtolower(trim($identifier)),
            'version'    => (string) ($manifest['version'] ?? ''),
            'signature'  => $sig,
            'algo'       => 'hmac-sha256',
        ], JSON_UNESCAPED_UNICODE);

        return file_put_contents($zipPath . '.' . self::SIG_FILENAME, (string) $body) !== false;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function verifyUploaded(string $zipPath, string $identifier, array $manifest): array
    {
        $sidecar = $zipPath . '.' . self::SIG_FILENAME;
        $key     = $this->signingKey();
        $required = $this->signRequired();

        if (!is_file($sidecar)) {
            return $required ? ['插件包缺少签名文件（.' . self::SIG_FILENAME . '）'] : [];
        }
        if ($key === '') {
            return $required ? ['服务端未配置 PIVARK_PLUGIN_SIGN_KEY，无法验签'] : [];
        }

        $raw = json_decode((string) file_get_contents($sidecar), true);
        if (!is_array($raw)) {
            return ['签名文件格式无效'];
        }
        $expect = $this->signPayload($identifier, $manifest);
        $got    = trim((string) ($raw['signature'] ?? ''));
        if ($expect === '' || $got === '' || !hash_equals($expect, $got)) {
            return ['插件包签名校验失败，可能被篡改'];
        }

        return [];
    }
}
