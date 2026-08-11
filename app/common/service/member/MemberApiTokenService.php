<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\support\AppTime;
use app\common\model\MemberApiToken;

use think\facade\Request;

/** 小程序等无头客户端 Bearer Token */
class MemberApiTokenService
{

    public function __construct(
        private readonly MemberService $members,
    ) {
    }

    public const CLIENT_MINIPROGRAM = 'miniprogram';
    private const TTL_DAYS = 30;

    /**
     * @return array{token:string,expires_at:string}
     */
    public function issue(int $userId, string $client = self::CLIENT_MINIPROGRAM): array
    {
        $userId = max(0, $userId);
        if ($userId < 1 || !$this->members->hasMemberRole($userId)) {
            throw new \InvalidArgumentException('会员无效');
        }
        $client     = $this->normalizeClient($client);
        $token      = 'mpv_' . bin2hex(random_bytes(24));
        $expiresAt  = AppTime::format('Y-m-d H:i:s', time() + self::TTL_DAYS * 86400);
        $now        = AppTime::now();

        MemberApiToken::insert([
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $token),
            'client'     => $client,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    public function revokeCurrentRequest(): void
    {
        $token = $this->extractBearerToken();
        if ($token === '') {
            return;
        }
        MemberApiToken::where('token_hash', hash('sha256', $token))->delete();
    }

    public function revokeAllForUser(int $userId): void
    {
        if ($userId < 1) {
            return;
        }
        MemberApiToken::where('user_id', $userId)->delete();
    }

    public function resolveUserId(?string $token = null): int
    {
        $token = $token ?? $this->extractBearerToken();
        if ($token === '') {
            return 0;
        }
        $row = MemberApiToken::where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', AppTime::now())
            ->find();
        if (!$row) {
            return 0;
        }
        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId < 1 || !$this->members->hasMemberRole($userId)) {
            return 0;
        }
        MemberApiToken::where('id', (int) $row['id'])->update([
            'last_used_at' => AppTime::now(),
        ]);

        return $userId;
    }

    public function requireUserId(): int
    {
        $userId = $this->resolveUserId();

        return $userId > 0 ? $userId : 0;
    }

    public function extractBearerToken(): string
    {
        foreach ($this->authorizationHeaderCandidates() as $header) {
            if (preg_match('/^Bearer\s+(\S+)/i', $header, $m)) {
                $token = trim((string) ($m[1] ?? ''));

                return $token !== '' ? $token : '';
            }
        }

        return '';
    }

    /** @return list<string> */
    private function authorizationHeaderCandidates(): array
    {
        $out   = [];
        $push  = static function (string $value) use (&$out): void {
            $value = trim($value);
            if ($value !== '') {
                $out[] = $value;
            }
        };

        $push((string) Request::header('Authorization', ''));
        $push((string) Request::header('X-Authorization', ''));

        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'HTTP_X_AUTHORIZATION'] as $key) {
            $header = Request::server($key);
            if (is_string($header) && $header !== '') {
                $push($header);
            }
        }

        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (!is_string($name) || !is_string($value)) {
                        continue;
                    }
                    if (strcasecmp($name, 'Authorization') === 0 || strcasecmp($name, 'X-Authorization') === 0) {
                        $push($value);
                    }
                }
            }
        }

        return $out;
    }

    private function normalizeClient(string $client): string
    {
        $client = strtolower(trim($client));
        if ($client === '') {
            return self::CLIENT_MINIPROGRAM;
        }

        return preg_match('/^[a-z0-9_\-]{1,32}$/', $client) ? $client : self::CLIENT_MINIPROGRAM;
    }
}
