<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\api\middleware;

use app\common\enum\ApiErrorCode;
use app\common\service\auth\CsrfService;
use app\common\service\member\MemberAuthPublicGateway;
use app\common\support\ApiResponse;
use app\common\support\AppService;
use think\facade\Session;
use think\Request;
use think\Response;

/**
 * API 写操作 CSRF：Session 会员须带 FrontCsrf；admin Session 须带 CsrfService；Bearer Token 客户端豁免。
 */
class ApiMemberWriteGuard
{
    /** @var list<string> 前缀豁免（OSS 回调、系统 tick、授权平台 S2S 等） */
    private const PATH_PREFIX_EXEMPT = [
        'api/v1/upload/oss-callback',
        'api/v1/system/cron-tick',
        // 客户站 LicenseRemoteClient → 官网：无浏览器 Origin；HMAC（可选）在 License 控制器校验
        'api/v1/license/activate',
        'api/v1/license/heartbeat',
        'api/v1/license/sync',
    ];

    private const UPLOAD_WRITE_PATH = 'api/v1/upload';

    private function auth(): MemberAuthPublicGateway
    {
        /** @var MemberAuthPublicGateway $gw */
        $gw = AppService::make(MemberAuthPublicGateway::class);

        return $gw;
    }

    public function handle(Request $request, \Closure $next): Response
    {
        if (!in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        $path = strtolower(trim($request->pathinfo(), '/'));
        if ($path === 'api/v1/admin' || str_starts_with($path, 'api/v1/admin/')) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }
        foreach (self::PATH_PREFIX_EXEMPT as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                /** @var Response $response */
                $response = $next($request);

                return $response;
            }
        }

        if ($path === self::UPLOAD_WRITE_PATH) {
            $deny = $this->guardUploadWriteCsrf($request);
            if ($deny !== null) {
                return $deny;
            }
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        $auth = $this->auth();
        if ($auth->resolveUserId() > 0) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        if (!$auth->frontIsLoggedIn()) {
            if (!$this->isSameSiteWrite($request)) {
                return ApiResponse::httpFailCode(403, ApiErrorCode::PERMISSION_DENIED, '跨站写操作被拒绝');
            }
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        $body   = (string) $request->post($auth->csrfFieldName(), '');
        $header = (string) ($request->header('X-CSRF-Token') ?? $request->header('x-csrf-token') ?? '');
        if ($auth->validateCsrfRequest($body, $header !== '' ? $header : null)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        return ApiResponse::httpFailCode(403, ApiErrorCode::PERMISSION_DENIED, 'CSRF 校验失败，请刷新页面后重试');
    }

    /** /api/v1/upload：admin Session → CsrfService；会员 Session → FrontCsrf；Bearer 豁免 */
    private function guardUploadWriteCsrf(Request $request): ?Response
    {
        if ($this->auth()->resolveUserId() > 0) {
            return null;
        }

        $admin = Session::get('admin_user');
        if (is_array($admin) && !empty($admin['id'])) {
            $csrf   = app(CsrfService::class);
            $body   = (string) $request->post($csrf->fieldName(), '');
            $header = (string) ($request->header('X-CSRF-Token') ?? $request->header('x-csrf-token') ?? '');
            if ($csrf->validateRequest($body, $header !== '' ? $header : null)) {
                return null;
            }

            return ApiResponse::httpFailCode(403, ApiErrorCode::PERMISSION_DENIED, 'CSRF 校验失败，请刷新页面后重试');
        }

        if ($this->auth()->frontIsLoggedIn()) {
            $auth   = $this->auth();
            $body   = (string) $request->post($auth->csrfFieldName(), '');
            $header = (string) ($request->header('X-CSRF-Token') ?? $request->header('x-csrf-token') ?? '');
            if ($auth->validateCsrfRequest($body, $header !== '' ? $header : null)) {
                return null;
            }

            return ApiResponse::httpFailCode(403, ApiErrorCode::PERMISSION_DENIED, 'CSRF 校验失败，请刷新页面后重试');
        }

        if (!$this->isSameSiteWrite($request)) {
            return ApiResponse::httpFailCode(403, ApiErrorCode::PERMISSION_DENIED, '跨站写操作被拒绝');
        }

        return null;
    }

    /** 匿名写操作：要求 Origin/Referer 与当前 Host 同源（Bearer 已在上方豁免） */
    private function isSameSiteWrite(Request $request): bool
    {
        $host = strtolower(trim((string) $request->host()));
        if ($host === '') {
            return false;
        }

        foreach (['origin', 'referer'] as $header) {
            $value = trim((string) ($request->header($header) ?? ''));
            if ($value === '') {
                continue;
            }
            $parsed = parse_url($value);
            if (!is_array($parsed)) {
                continue;
            }
            if (strtolower((string) ($parsed['host'] ?? '')) === $host) {
                return true;
            }
        }

        return false;
    }
}
