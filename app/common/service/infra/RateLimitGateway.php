<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\infra;

use app\common\enum\ApiErrorCode;
use app\common\service\member\MemberAuthPublicGateway;
use app\common\support\ApiResponse;
use app\common\support\RateLimitGuard;
use app\common\support\ServiceResult;
use think\facade\Cache;
use think\Request;
use think\Response;

/**
 * 限流唯一入口：策略解析 → RateLimitGuard 计数 → 统一响应
 */
final class RateLimitGateway
{
    public function __construct(
        private readonly RateLimitRegistry $registry,
        private readonly MemberAuthPublicGateway $memberAuthPublic,
    ) {
    }

    /**
     * @param array{user_id?:int,scene?:string,request?:Request} $context
     */
    public function check(string $policyId, string $subject, array $context = []): ?ServiceResult
    {
        $policy = $this->registry->resolve($policyId);
        if ($policy === null || empty($policy['enabled'])) {
            return null;
        }

        if (($policy['mode'] ?? 'window') === 'cooldown') {
            return $this->checkCooldown($policyId, $policy, $subject);
        }

        $key     = $this->buildCacheKey($policyId, $policy, $subject, $context);
        $message = (string) ($policy['message'] ?? '请求过于频繁，请稍后再试');

        return RateLimitGuard::guard(
            $key,
            (int) $policy['max_requests'],
            (int) $policy['window_seconds'],
            $message,
        );
    }

    public function checkRequest(Request $request): ?ServiceResult
    {
        $path = strtolower(trim($request->pathinfo(), '/'));
        if ($this->shouldBypassRequest($request, $path)) {
            return null;
        }

        if (str_starts_with($path, 'search') || $path === 'search') {
            return $this->check('front.search', $request->ip() ?: 'unknown');
        }

        if ($this->isApiPath($path)) {
            $policyId = $this->resolveApiPolicy($request);
            if ($policyId === null) {
                return null;
            }
            $context = ['request' => $request, 'user_id' => $this->resolveApiUserId($request, $policyId)];

            return $this->check($policyId, $request->ip() ?: 'unknown', $context);
        }

        if (!str_starts_with($path, 'admin') && !str_starts_with($path, 'api/')) {
            return $this->check('front.cc', $request->ip() ?: 'unknown');
        }

        return null;
    }

    public function resolveApiPolicy(Request $request): ?string
    {
        $path = $this->normalizeApiPath($request);
        if (str_starts_with($path, 'admin/') || $path === 'admin') {
            return null;
        }

        $order = config('rate_limit.api_match_order', []);
        if (!is_array($order)) {
            return 'api.default';
        }

        foreach ($order as $policyId) {
            if ($policyId === 'api.default') {
                continue;
            }
            $policy = $this->registry->resolve((string) $policyId);
            if ($policy === null || empty($policy['enabled'])) {
                continue;
            }
            foreach ($policy['patterns'] ?? [] as $pattern) {
                $pattern = strtolower(trim((string) $pattern));
                if ($pattern !== '' && str_contains($path, $pattern)) {
                    return (string) $policyId;
                }
            }
        }

        $default = $this->registry->resolve('api.default');

        return ($default !== null && !empty($default['enabled'])) ? 'api.default' : null;
    }

    /**
     * @param array{user_id?:int,scene?:string} $context
     */
    public function clearPolicy(string $policyId, string $subject, array $context = []): void
    {
        $policy = $this->registry->resolve($policyId);
        if ($policy === null) {
            return;
        }
        Cache::delete($this->buildCacheKey($policyId, $policy, $subject, $context));
    }

    public function clearAll(): void
    {
        foreach (['cc_rl:', 'api_rl:', 'ip_rl:', 'captcha_img:', 'pw_reset_', 'bulk_replace_exec_'] as $prefix) {
            $this->clearCacheByPrefix($prefix);
        }
    }

    public function markCooldown(string $policyId, string $subject, array $context = []): void
    {
        $policy = $this->registry->resolve($policyId);
        if ($policy === null) {
            return;
        }
        $cooldown = max(1, (int) ($policy['cooldown_seconds'] ?? 60));
        $key      = $this->buildCacheKey($policyId, $policy, $subject, $context);
        Cache::set($key, time(), $cooldown * 2);
    }

    public function toHttpResponse(ServiceResult $blocked, Request $request, ?array $policy = null): Response
    {
        $responseMode = (string) ($policy['response'] ?? 'json_api');
        $message      = (string) ($blocked->msg ?? '请求过于频繁，请稍后再试');
        $retryAfter   = max(1, (int) ($policy['lock_seconds'] ?? $policy['window_seconds'] ?? 60));

        return match ($responseMode) {
            'html_plain' => response($message, 429)->header(['Retry-After' => (string) $retryAfter]),
            'html_page'  => Response::create(
                '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>429</title></head><body>'
                . '<p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                . '<p><a href="/">返回首页</a></p></body></html>',
                'html',
                429,
            )->header(['Retry-After' => (string) $retryAfter]),
            default      => ApiResponse::httpFailCode(429, ApiErrorCode::RATE_LIMITED, $message),
        };
    }

    /** @param array<string, mixed> $policy */
    private function checkCooldown(string $policyId, array $policy, string $subject): ?ServiceResult
    {
        $cooldown = max(1, (int) ($policy['cooldown_seconds'] ?? 60));
        $key      = $this->buildCacheKey($policyId, $policy, $subject, []);
        $last     = (int) Cache::get($key, 0);
        if ($last > 0 && (time() - $last) < $cooldown) {
            $wait = $cooldown - (time() - $last);
            $msg  = (string) ($policy['message'] ?? '操作过于频繁，请稍后再试');
            if (str_contains($msg, '全库替换')) {
                return ServiceResult::rateLimited($msg . '，请 ' . $wait . ' 秒后再执行全库替换');
            }

            return ServiceResult::rateLimited($msg . '，请 ' . $wait . ' 秒后再试');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $policy
     * @param array{user_id?:int,scene?:string,request?:Request} $context
     */
    private function buildCacheKey(string $policyId, array $policy, string $subject, array $context): string
    {
        $dimension = (string) ($policy['dimension'] ?? 'ip');

        return match ($policyId) {
            'front.cc' => 'cc_rl:' . md5($subject),
            'captcha.image' => 'captcha_img:' . md5(($context['scene'] ?? '') . '|' . $subject),
            'member.password_reset' => 'pw_reset_' . md5($subject),
            'admin.bulk_replace' => 'bulk_replace_exec_' . max(0, (int) ($context['user_id'] ?? (int) $subject)),
            default => $this->buildDefaultCacheKey($policyId, $policy, $subject, $context, $dimension),
        };
    }

    /**
     * @param array<string, mixed> $policy
     * @param array{user_id?:int,scene?:string} $context
     */
    private function buildDefaultCacheKey(
        string $policyId,
        array $policy,
        string $subject,
        array $context,
        string $dimension,
    ): string {
        if (str_starts_with($policyId, 'api.')) {
            $bucket = (string) ($policy['api_bucket'] ?? str_replace('api.', '', $policyId));
            $userId = (int) ($context['user_id'] ?? 0);
            if ($userId > 0 && ($policy['rate_by_user'] ?? $dimension === 'user_id')) {
                return 'api_rl:' . $bucket . ':u' . $userId;
            }

            return 'api_rl:' . $bucket . ':ip' . md5($subject);
        }

        $scope = (string) ($policy['ip_scope'] ?? $policyId);

        return 'ip_rl:' . $scope . ':' . md5($subject);
    }

    private function resolveApiUserId(Request $request, string $policyId): int
    {
        $policy = $this->registry->resolve($policyId);
        if ($policy === null) {
            return 0;
        }
        $useUser = (bool) ($policy['rate_by_user'] ?? (($policy['dimension'] ?? '') === 'user_id'));
        if (!$useUser) {
            return 0;
        }
        try {
            return $this->memberAuthPublic->resolveUserId();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function normalizeApiPath(Request $request): string
    {
        $path = strtolower(trim($request->pathinfo(), '/'));
        if (str_starts_with($path, 'api/v1/')) {
            return substr($path, 7);
        }
        if (str_starts_with($path, 'v1/')) {
            return substr($path, 3);
        }

        return $path;
    }

    private function isApiPath(string $path): bool
    {
        return str_starts_with($path, 'api/v1/') || str_starts_with($path, 'v1/');
    }

    private function shouldBypassRequest(Request $request, string $path): bool
    {
        if (trim((string) $request->header('X-Pivark-Qa')) === '1') {
            return true;
        }
        $env = strtolower((string) env('PIVARK_ENV', 'dev'));
        if ((bool) env('APP_DEBUG', false) && in_array($env, ['dev', 'demo-local', 'local'], true)) {
            return true;
        }
        if (str_starts_with($path, 'admin/') || str_starts_with($path, 'api/v1/admin/')) {
            return true;
        }

        return false;
    }

    private function clearCacheByPrefix(string $prefix): void
    {
        try {
            $store = Cache::store();
            if (method_exists($store, 'handler')) {
                $handler = $store->handler();
                if ($handler instanceof \Redis) {
                    $keys = $handler->keys($prefix . '*');
                    if (is_array($keys) && $keys !== []) {
                        $handler->del(...$keys);
                    }

                    return;
                }
            }
        } catch (\Throwable) {
            // fall through
        }
        Cache::clear();
    }
}
