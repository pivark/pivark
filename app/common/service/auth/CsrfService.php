<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\auth;

use think\facade\Session;

/** 后台 CSRF Token 生成与校验（带过期与校验后轮换） */
class CsrfService
{

    private const SESSION_PAYLOAD_KEY = 'admin_csrf_payload';
    /** @deprecated 旧版单字符串 Session 键 */
    private const LEGACY_SESSION_KEY    = 'admin_csrf_token';
    private const FIELD_NAME          = '__token';

    /**
     * @return string 表单字段名
     */
    public function fieldName(): string
    {
        return self::FIELD_NAME;
    }

    /**
     * @return string 当前有效 Token（过期则自动轮换）
     */
    public function token(): string
    {
        $this->migrateLegacySession();
        $payload = $this->readPayload();
        if ($payload !== null && !$this->isExpired($payload)) {
            return (string) $payload['token'];
        }
        return $this->regenerate();
    }

    /**
     * @return string 新 Token
     */
    public function regenerate(): string
    {
        $ttl   = $this->ttlSeconds();
        $token = bin2hex(random_bytes(32));
        Session::set(self::SESSION_PAYLOAD_KEY, [
            'token'     => $token,
            'expire_at' => time() + $ttl,
        ]);
        Session::delete(self::LEGACY_SESSION_KEY);
        return $token;
    }

    /**
     * CSRF 校验通过后轮换 Token，降低重放风险
     *
     * @return void
     */
    public function rotateAfterSuccess(): void
    {
        $this->regenerate();
    }

    /**
     * @param string|null $token 待校验 Token
     * @return bool
     */
    public function validate(?string $token): bool
    {
        $this->migrateLegacySession();
        $payload = $this->readPayload();
        if ($payload === null || $this->isExpired($payload)) {
            return false;
        }
        if ($token === null || $token === '') {
            return false;
        }
        return hash_equals((string) $payload['token'], $token);
    }

    /**
     * @param string      $tokenFromBody   POST 字段
     * @param string|null $tokenFromHeader Header X-CSRF-Token
     * @return bool
     */
    public function validateRequest(string $tokenFromBody, ?string $tokenFromHeader): bool
    {
        if ($tokenFromHeader !== null && $tokenFromHeader !== '' && $this->validate($tokenFromHeader)) {
            return true;
        }
        if ($tokenFromBody !== '' && $this->validate($tokenFromBody)) {
            return true;
        }

        return false;
    }

    /**
     * @return int TTL 秒数
     */
    public function ttlSeconds(): int
    {
        $ttl = (int) config('pivark.csrf_ttl_seconds', 7200);
        return max(300, $ttl);
    }

    /**
     * @return array{token:string,expire_at:int}|null
     */
    private function readPayload(): ?array
    {
        $raw = Session::get(self::SESSION_PAYLOAD_KEY);
        if (!is_array($raw) || empty($raw['token'])) {
            return null;
        }
        return [
            'token'     => (string) $raw['token'],
            'expire_at' => (int) ($raw['expire_at'] ?? 0),
        ];
    }

    /**
     * @param array{token:string,expire_at:int} $payload
     */
    private function isExpired(array $payload): bool
    {
        $expireAt = (int) ($payload['expire_at'] ?? 0);
        return $expireAt < 1 || time() >= $expireAt;
    }

    private function migrateLegacySession(): void
    {
        $legacy = Session::get(self::LEGACY_SESSION_KEY);
        if (!is_string($legacy) || $legacy === '' || $this->readPayload() !== null) {
            return;
        }
        Session::set(self::SESSION_PAYLOAD_KEY, [
            'token'     => $legacy,
            'expire_at' => time() + $this->ttlSeconds(),
        ]);
        Session::delete(self::LEGACY_SESSION_KEY);
    }
}
