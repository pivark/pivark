<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/** 对称加密（configs 密钥、备份等）；明文以 pivenc1: 前缀存储 */
final class AppCipher
{
    private const PREFIX = 'pivenc1:';

    public static function isEnabled(): bool
    {
        return self::key() !== '';
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '' || str_starts_with($plain, self::PREFIX)) {
            return $plain;
        }
        $key = self::key();
        if ($key === '') {
            return $plain;
        }
        $iv  = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('加密失败');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        if ($stored === '' || !str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }
        $key = self::key();
        if ($key === '') {
            return $stored;
        }
        $bin = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($bin === false || strlen($bin) < 28) {
            return '';
        }
        $iv     = substr($bin, 0, 12);
        $tag    = substr($bin, 12, 16);
        $cipher = substr($bin, 28);
        $plain  = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return is_string($plain) ? $plain : '';
    }

    public static function encryptFile(string $source, string $target): void
    {
        $plain = (string) file_get_contents($source);
        if ($plain === '') {
            throw new \RuntimeException('源文件为空');
        }
        $payload = self::encrypt($plain);
        if (file_put_contents($target, $payload) === false) {
            throw new \RuntimeException('无法写入加密文件');
        }
    }

    public static function decryptFile(string $source): string
    {
        $raw = (string) file_get_contents($source);
        if ($raw === '') {
            return '';
        }
        if (!str_starts_with($raw, self::PREFIX)) {
            return $raw;
        }

        return self::decrypt($raw);
    }

    private static function key(): string
    {
        $raw = trim((string) env('PIVARK_CIPHER_KEY', env('APP_KEY', '')));
        if ($raw === '') {
            return '';
        }

        return hash('sha256', $raw, true);
    }
}
