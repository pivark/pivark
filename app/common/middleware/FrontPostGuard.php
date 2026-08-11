<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 前台写操作短时防重复提交（会员表单、询价、API POST 共用）
 */
declare(strict_types=1);

namespace app\common\middleware;

use app\common\support\AdminApiResponse;
use app\common\service\infra\IdempotencyService;
use think\Request;
use think\Response;

class FrontPostGuard
{
    /**
     * 高频写遥测 / 无业务侧效路径：不走指纹防重（否则 beacon 连点会 422 污染控制台）。
     * 仍受 ApiRateLimit 分桶约束。
     *
     * @var list<string>
     */
    private const PATH_EXEMPT = [
        'api/v1/stats/beacon',
        'api/v1/search/click',
        'api/v1/system/csp-report',
    ];

    public function handle(Request $request, \Closure $next): Response
    {
        if (!in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        $path = strtolower(trim(str_replace('\\', '/', (string) $request->pathinfo()), '/'));
        // 后台 REST 已有 IdempotencyCheck；FrontPostGuard 再拦会误杀批 step / 升级分块连拍
        if ($this->isAdminRestPath($path)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }
        if (in_array($path, self::PATH_EXEMPT, true)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        $actorId = app(IdempotencyService::class)->frontActorId($request);
        $key     = app(IdempotencyService::class)->extractKey($request);
        $fingerprint = '';

        if ($key !== '') {
            $cached = app(IdempotencyService::class)->getCachedResponse($actorId, $key);
            if ($cached !== null && is_array($cached)) {
                return AdminApiResponse::replayJson($cached);
            }
        } else {
            $fingerprint = app(IdempotencyService::class)->fingerprint($request, $actorId);
            if (!app(IdempotencyService::class)->tryAcquireFingerprint($actorId, $fingerprint)) {
                return AdminApiResponse::fail('请勿重复提交，请稍后再试');
            }
        }

        /** @var Response $response */
        $response = $next($request);

        if ($key !== '' && $response instanceof Response) {
            $content = $response->getContent();
            if (is_string($content) && $content !== '') {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    app(IdempotencyService::class)->cacheResponse($actorId, $key, $data);
                }
            }
        } elseif ($fingerprint !== '' && $response instanceof Response && !self::isSuccessResponse($response)) {
            app(IdempotencyService::class)->releaseFingerprint($actorId, $fingerprint);
        }

        return $response;
    }

    /** pathinfo 可能是 api/v1/admin/…，或分组裁切后的 admin/…，或带 index.php/ 入口 */
    private function isAdminRestPath(string $path): bool
    {
        $path = strtolower(trim(str_replace('\\', '/', $path), '/'));
        if (str_starts_with($path, 'index.php/')) {
            $path = substr($path, strlen('index.php/'));
        }
        if (str_starts_with($path, 'api/v1/admin/') || $path === 'api/v1/admin') {
            return true;
        }
        if (str_starts_with($path, 'admin/') || $path === 'admin') {
            return true;
        }
        $uri = strtolower((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''));
        $uri = trim(str_replace('\\', '/', $uri), '/');

        return str_contains($uri, 'api/v1/admin/') || str_ends_with($uri, 'api/v1/admin');
    }

    private static function isSuccessResponse(Response $response): bool
    {
        $content = $response->getContent();
        if (!is_string($content) || $content === '') {
            return false;
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return false;
        }
        if (isset($data['error'])) {
            return false;
        }
        if (array_key_exists('data', $data)) {
            return true;
        }
        if (isset($data['success'])) {
            return (bool) $data['success'];
        }

        return false;
    }
}
