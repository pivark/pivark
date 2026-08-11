<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\infra;
use app\common\service\front\FrontAuthService;
use app\common\service\member\MemberApiTokenService;
use app\common\service\user\PermissionService;

use think\facade\Cache;
use think\facade\Session;
use think\Request;

/** 后台 POST 幂等 / 短时防重复提交（Redis 共享或 Session） */
class IdempotencyService
{

    public function __construct(
        private readonly FrontAuthService $frontAuthService,
        private readonly MemberApiTokenService $memberApiTokenService,
        private readonly PermissionService $permissionService,
        private readonly CacheConfigService $cacheConfigService,
    ) {
    }

    public const HEADER_NAME = 'X-Idempotency-Key';
    public const FIELD_NAME  = '__idempotency_key';

    private const SESSION_KEY      = 'pv_admin_idempotency';
    private const FINGERPRINT_TTL  = 3;
    private const KEY_CACHE_TTL    = 120;
    private const MAX_KEY_ENTRIES  = 80;

    /**
     * @return string POST 字段名
     */
    public function fieldName(): string
    {
        return self::FIELD_NAME;
    }

    /**
     * @return string 请求中的幂等键，无则空字符串
     */
    public function extractKey(Request $request): string
    {
        $header = trim((string) ($request->header(self::HEADER_NAME) ?? $request->header('x-idempotency-key') ?? ''));
        if ($header !== '' && $this->isValidKey($header)) {
            return $header;
        }

        $body = trim((string) $request->post(self::FIELD_NAME, ''));
        if ($body !== '' && $this->isValidKey($body)) {
            return $body;
        }

        $json = $this->jsonBody($request);
        if ($json !== null) {
            $fromJson = trim((string) ($json[self::FIELD_NAME] ?? ''));
            if ($fromJson !== '' && $this->isValidKey($fromJson)) {
                return $fromJson;
            }
        }

        return '';
    }

    /**
     * 读取已缓存的幂等响应
     *
     * @return array<string, mixed>|null
     */
    public function getCachedResponse(int $userId, string $key): ?array
    {
        if ($userId === 0 || $key === '') {
            return null;
        }
        $this->purgeExpired($userId);
        $bucket = $this->bucket($userId);
        $entry  = $bucket['keys'][$key] ?? null;
        if (!is_array($entry) || !isset($entry['response'], $entry['exp']) || (int) $entry['exp'] < time()) {
            return null;
        }

        return is_array($entry['response']) ? $entry['response'] : null;
    }

    /**
     * 缓存幂等键对应 JSON 响应
     *
     * @param array<string, mixed> $response
     */
    public function cacheResponse(int $userId, string $key, array $response): void
    {
        if ($userId === 0 || $key === '') {
            return;
        }
        $this->purgeExpired($userId);
        $bucket = $this->bucket($userId);
        $bucket['keys'][$key] = [
            'response' => $response,
            'exp'      => time() + self::KEY_CACHE_TTL,
        ];
        if (count($bucket['keys']) > self::MAX_KEY_ENTRIES) {
            uasort($bucket['keys'], static fn ($a, $b) => ((int) ($a['exp'] ?? 0)) <=> ((int) ($b['exp'] ?? 0)));
            $bucket['keys'] = array_slice($bucket['keys'], -self::MAX_KEY_ENTRIES, null, true);
        }
        $this->saveBucket($userId, $bucket);
    }

    /** 前台会员 id；未登录时为负的 IP 指纹（与后台 admin id 区分） */
    public function frontActorId(\think\Request $request): int
    {
        $current = $this->frontAuthService->current();
        $memberId = (int) ($current['id'] ?? 0);
        if ($memberId > 0) {
            return $memberId;
        }

        $tokenUserId = $this->memberApiTokenService->resolveUserId();
        if ($tokenUserId > 0) {
            return $tokenUserId;
        }

        $ip = trim((string) $request->ip());
        $ua = trim((string) ($request->header('user-agent') ?? ''));

        return -1 * (int) sprintf('%u', crc32($ip . '|' . $ua));
    }

    /**
     * @return string 无幂等键时的请求指纹
     */
    public function fingerprint(Request $request, int $userId): string
    {
        $route = $this->permissionService->normalizeControllerKey((string) $request->controller())
            . '/' . strtolower((string) $request->action());
        $raw   = $request->getContent();
        if ($raw === '') {
            $raw = json_encode($request->post(), JSON_UNESCAPED_UNICODE) ?: '';
        }

        return hash('sha256', $userId . '|' . $route . '|' . md5($raw));
    }

    /**
     * @return bool 是否允许执行（false=短时重复提交）
     */
    public function tryAcquireFingerprint(int $userId, string $fingerprint): bool
    {
        if ($userId === 0 || $fingerprint === '') {
            return true;
        }
        $this->purgeExpired($userId);
        $bucket = $this->bucket($userId);
        $now    = time();
        if (isset($bucket['fingerprints'][$fingerprint]) && ($now - (int) $bucket['fingerprints'][$fingerprint]) < self::FINGERPRINT_TTL) {
            return false;
        }
        $bucket['fingerprints'][$fingerprint] = $now;
        $this->saveBucket($userId, $bucket);

        return true;
    }

    /** 失败请求释放指纹，避免校验失败后 3 秒内无法重试 */
    public function releaseFingerprint(int $userId, string $fingerprint): void
    {
        if ($userId === 0 || $fingerprint === '') {
            return;
        }
        $this->purgeExpired($userId);
        $bucket = $this->bucket($userId);
        unset($bucket['fingerprints'][$fingerprint]);
        $this->saveBucket($userId, $bucket);
    }

    private function isValidKey(string $key): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9._-]{8,64}$/', $key);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonBody(Request $request): ?array
    {
        $contentType = strtolower((string) $request->header('content-type', ''));
        if ($contentType !== '' && !str_contains($contentType, 'application/json')) {
            return null;
        }
        $raw = $request->getContent();
        if ($raw === '') {
            return null;
        }
        $json = json_decode($raw, true);

        return is_array($json) ? $json : null;
    }

    private function useSharedStore(): bool
    {
        return $this->cacheConfigService->effectiveDriver() === CacheConfigService::DRIVER_REDIS;
    }

    private function sharedBucketKey(int $userId): string
    {
        return 'pv_admin_idem:' . $userId;
    }

    /**
     * @return array{keys: array<string, mixed>, fingerprints: array<string, int>}
     */
    private function bucket(int $userId): array
    {
        if ($this->useSharedStore()) {
            $raw = Cache::store('redis')->get($this->sharedBucketKey($userId));
            $bucket = is_array($raw) ? $raw : [];
        } else {
            $all = Session::get(self::SESSION_KEY, []);
            if (!is_array($all)) {
                $all = [];
            }
            $bucket = $all[$userId] ?? [];
            if (!is_array($bucket)) {
                $bucket = [];
            }
        }

        return [
            'keys'         => is_array($bucket['keys'] ?? null) ? $bucket['keys'] : [],
            'fingerprints' => is_array($bucket['fingerprints'] ?? null) ? $bucket['fingerprints'] : [],
        ];
    }

    /**
     * @param array{keys: array<string, mixed>, fingerprints: array<string, int>} $bucket
     */
    private function saveBucket(int $userId, array $bucket): void
    {
        if ($this->useSharedStore()) {
            Cache::store('redis')->set($this->sharedBucketKey($userId), $bucket, self::KEY_CACHE_TTL);

            return;
        }
        $all = Session::get(self::SESSION_KEY, []);
        if (!is_array($all)) {
            $all = [];
        }
        $all[$userId] = $bucket;
        Session::set(self::SESSION_KEY, $all);
    }

    private function purgeExpired(int $userId): void
    {
        $bucket = $this->bucket($userId);
        $now    = time();
        foreach ($bucket['keys'] as $key => $entry) {
            if (!is_array($entry) || (int) ($entry['exp'] ?? 0) < $now) {
                unset($bucket['keys'][$key]);
            }
        }
        foreach ($bucket['fingerprints'] as $fp => $ts) {
            if ($now - (int) $ts >= self::FINGERPRINT_TTL) {
                unset($bucket['fingerprints'][$fp]);
            }
        }
        $this->saveBucket($userId, $bucket);
    }
}
