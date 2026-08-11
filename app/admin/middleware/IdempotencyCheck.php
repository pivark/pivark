<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\middleware;

use app\common\service\admin\AdminRestRouteRegistry;
use app\common\service\infra\IdempotencyService;
use app\common\service\user\PermissionService;
use app\common\support\AdminApiResponse;
use think\facade\Session;
use think\Request;
use think\Response;

/** 后台写操作幂等键 / 短时防重复提交 */
class IdempotencyCheck
{
    /** @var list<string> controller/action 小写，免校验 */
    private const EXEMPT = [
        'login/index',
        'upload/chunkinit',
        'upload/chunkupload',
        'upload/chunkmerge',
        'seo/staticgenerate',
        'seo/staticbatchstart',
        'seo/staticbatchstep',
        'spa/miniprogrampagepreview',
    ];

    /** @var list<string> REST relative path（api/v1/admin/…），分块/轮询步进免短时指纹 */
    private const EXEMPT_REST = [
        'upgrade/core-step/download',
        'upgrade/core-step/migrate',
        'upgrade/core-step/status',
        // 静态 HTML 批生成：step 同 job_id 连拍（间隔 ≪ 指纹 TTL），须与 classic EXEMPT 对称
        'seo/static/generate',
        'seo/static/batch/start',
        'seo/static/batch/step',
    ];

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AdminRestRouteRegistry $adminRestRoute,
        private readonly PermissionService $permission,
    ) {
    }

    public function handle(Request $request, \Closure $next)
    {
        if (!$this->shouldCheck($request)) {
            return $next($request);
        }

        $userId = (int) (Session::get('admin_user.id') ?? 0);
        $key    = $this->idempotency->extractKey($request);
        $fingerprint = '';

        if ($key !== '') {
            $cached = $this->idempotency->getCachedResponse($userId, $key);
            if ($cached !== null && is_array($cached)) {
                return AdminApiResponse::replayJson($cached);
            }
            if ($cached !== null) {
                return AdminApiResponse::fail('缓存无效，请刷新页面后重试');
            }
        } else {
            $fingerprint = $this->idempotency->fingerprint($request, $userId);
            if (!$this->idempotency->tryAcquireFingerprint($userId, $fingerprint)) {
                return AdminApiResponse::fail('请勿重复提交，请稍后再试');
            }
        }

        $response = $next($request);

        if ($key !== '' && $response instanceof Response) {
            $content = $response->getContent();
            if (is_string($content) && $content !== '') {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $this->idempotency->cacheResponse($userId, $key, $data);
                }
            }
        } elseif ($fingerprint !== '' && $response instanceof Response && !self::isSuccessResponse($response)) {
            $this->idempotency->releaseFingerprint($userId, $fingerprint);
        }

        return $response;
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

    private function shouldCheck(Request $request): bool
    {
        if (!in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        $pathinfo = trim(str_replace('\\', '/', (string) $request->pathinfo()), '/');
        // 与 AdminApiAuthCheck 同口径：相对路径在多应用下可能已裁掉 api/v1/admin 前缀；
        // 须先按相对路径命中 EXEMPT_REST，不能只认完整 api/v1/admin/… 前缀。
        $relative = $this->adminRestRoute->relativePathFromRequest($pathinfo);
        if (in_array($relative, self::EXEMPT_REST, true)) {
            return false;
        }

        if (str_starts_with($pathinfo, 'api/v1/admin/') || $pathinfo === 'api/v1/admin') {
            $route = $this->adminRestRoute->match($request->method(), $relative);
            if ($this->adminRestRoute->isPublic($route)) {
                return false;
            }
            if (in_array($relative, ['auth/login', 'auth/logout'], true)) {
                return false;
            }

            return true;
        }

        $key = $this->permission->normalizeControllerKey((string) $request->controller())
            . '/' . strtolower((string) $request->action());

        return !in_array($key, self::EXEMPT, true);
    }
}
