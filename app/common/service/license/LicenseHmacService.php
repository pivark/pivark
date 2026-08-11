<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\license;

/**
 * 授权平台 ↔ 客户站 HMAC（可选）。
 * 两边均配置相同 PIVARK_LICENSE_HMAC_SECRET 后启用；未配置则跳过，保持兼容。
 */
final class LicenseHmacService
{
    public const HEADER_TIMESTAMP = 'X-PivArk-License-Timestamp';
    public const HEADER_SIGNATURE = 'X-PivArk-License-Signature';

    /** 允许的时钟漂移（秒） */
    private const MAX_SKEW = 300;

    public function secret(): string
    {
        return trim((string) config('pivark.license_hmac_secret', ''));
    }

    public function isEnabled(): bool
    {
        return $this->secret() !== '';
    }

    public function sign(string $timestamp, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $this->secret());
    }

    public function verify(string $timestamp, string $body, string $signature): bool
    {
        if ($timestamp === '' || $signature === '' || !$this->isEnabled()) {
            return false;
        }
        if (!ctype_digit($timestamp)) {
            return false;
        }
        $ts = (int) $timestamp;
        if (abs(time() - $ts) > self::MAX_SKEW) {
            return false;
        }

        return hash_equals($this->sign($timestamp, $body), trim($signature));
    }

    /**
     * @return array{0:string,1:string} [timestamp, signature]
     */
    public function signBody(string $body): array
    {
        $timestamp = (string) time();

        return [$timestamp, $this->sign($timestamp, $body)];
    }

    /**
     * @param array<string, string> $headers 小写 header 名 => 值
     */
    public function verifyFromHeaders(array $headers, string $body): bool
    {
        $ts  = $headers[strtolower(self::HEADER_TIMESTAMP)] ?? '';
        $sig = $headers[strtolower(self::HEADER_SIGNATURE)] ?? '';

        return $this->verify($ts, $body, $sig);
    }

    /**
     * 解析 file_get_contents HTTP 响应头列表为小写 map。
     *
     * @param list<string>|null $httpResponseHeader
     * @return array<string, string>
     */
    public function parseHttpResponseHeaders(?array $httpResponseHeader): array
    {
        $out = [];
        foreach ($httpResponseHeader ?? [] as $line) {
            if (!is_string($line) || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $out[strtolower(trim($name))] = trim($value);
        }

        return $out;
    }
}
