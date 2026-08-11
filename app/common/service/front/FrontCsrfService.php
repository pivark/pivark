<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 元舟 PivArk — 前台表单 CSRF
 */
declare(strict_types=1);

namespace app\common\service\front;

use app\common\middleware\PivarkSessionInit;
use app\common\support\ServiceResult;
use think\facade\Request;
use think\facade\Session;

/** 前台公开表单 CSRF（与后台 Session 键隔离；游客可无 Session 的 HMAC token） */
class FrontCsrfService
{
    private const SESSION_PAYLOAD_KEY = 'front_csrf_payload';
    private const FIELD_NAME          = '__token';
    private const STATELESS_PREFIX    = 'pv1.';

    public function fieldName(): string
    {
        return self::FIELD_NAME;
    }

    public function token(): string
    {
        if ($this->sessionAvailable()) {
            $payload = $this->readPayload();
            if ($payload !== null && !$this->isExpired($payload)) {
                return (string) $payload['token'];
            }

            return $this->regenerate();
        }

        return $this->issueStatelessToken();
    }

    public function regenerate(): string
    {
        if (!$this->sessionAvailable()) {
            return $this->issueStatelessToken();
        }

        $token = bin2hex(random_bytes(32));
        Session::set(self::SESSION_PAYLOAD_KEY, [
            'token'     => $token,
            'expire_at' => time() + $this->ttlSeconds(),
        ]);

        return $token;
    }

    public function rotateAfterSuccess(): void
    {
        $this->regenerate();
    }

    /**
     * @return array{csrf_token:string,csrf_field:string}
     */
    public function clientMeta(): array
    {
        return [
            'csrf_token' => $this->token(),
            'csrf_field' => $this->fieldName(),
        ];
    }

    public function attachRotatedMeta(ServiceResult $result): ServiceResult
    {
        if (!$result->isOk()) {
            return $result;
        }
        $this->rotateAfterSuccess();
        $meta = array_merge($result->meta() ?? [], $this->clientMeta());

        return ServiceResult::ok($result->data(), $result->message(), $meta);
    }

    public function validate(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }
        if (str_starts_with($token, self::STATELESS_PREFIX)) {
            return $this->validateStateless($token);
        }

        $payload = $this->readPayload();
        if ($payload === null || $this->isExpired($payload)) {
            return false;
        }

        return hash_equals((string) $payload['token'], $token);
    }

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

    public function ttlSeconds(): int
    {
        return max(300, (int) config('pivark.front_csrf_ttl_seconds', 1800));
    }

    /** 当前请求是否已挂载可用 Session（游客延迟 Session 时为 false） */
    public function sessionAvailable(): bool
    {
        try {
            $req = Request::instance();
            if (is_object($req) && isset($req->{PivarkSessionInit::REQUEST_FLAG})) {
                return (bool) $req->{PivarkSessionInit::REQUEST_FLAG};
            }
        } catch (\Throwable) {
            // Request 不可用时按已挂载 Session 处理
        }

        // CLI / 未走中间件：保持原 Session 行为
        return true;
    }

    /**
     * @return array{token:string,expire_at:int}|null
     */
    private function readPayload(): ?array
    {
        if (!$this->sessionAvailable()) {
            return null;
        }
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

    private function issueStatelessToken(): string
    {
        $expireAt = time() + $this->ttlSeconds();
        $nonce    = bin2hex(random_bytes(16));
        $body     = $expireAt . '.' . $nonce;
        $sig      = hash_hmac('sha256', $body, $this->hmacKey());

        return self::STATELESS_PREFIX . $body . '.' . $sig;
    }

    private function validateStateless(string $token): bool
    {
        $raw = substr($token, strlen(self::STATELESS_PREFIX));
        $parts = explode('.', $raw);
        if (count($parts) !== 3) {
            return false;
        }
        [$expireRaw, $nonce, $sig] = $parts;
        if (!ctype_digit($expireRaw) || $nonce === '' || $sig === '') {
            return false;
        }
        $expireAt = (int) $expireRaw;
        if ($expireAt < 1 || time() >= $expireAt) {
            return false;
        }
        $body = $expireRaw . '.' . $nonce;
        $expect = hash_hmac('sha256', $body, $this->hmacKey());

        return hash_equals($expect, $sig);
    }

    private function hmacKey(): string
    {
        $key = trim((string) env('PIVARK_CIPHER_KEY', env('APP_KEY', '')));
        if ($key === '') {
            $key = 'pivark-front-csrf';
        }

        return hash('sha256', 'front-csrf|' . $key, true);
    }
}
