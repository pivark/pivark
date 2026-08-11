<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\api\middleware;

use app\common\service\admin\AdminRestRouteRegistry;
use app\common\service\auth\CsrfService;
use app\common\service\front\FrontCsrfService;
use app\common\support\AdminApiResponse;
use app\common\support\MemberPublishAccess;
use think\Request;
use think\Response;

/** /api/v1/admin CSRF（公开读接口免校验） */
class AdminApiCsrfCheck
{
    public function __construct(
        private readonly AdminRestRouteRegistry $adminRestRoute,
        private readonly CsrfService $csrf,
        private readonly FrontCsrfService $frontCsrf,
    ) {
    }

    public function handle(Request $request, \Closure $next): Response
    {
        $relative = $this->adminRestRoute->relativePathFromRequest((string) $request->pathinfo());
        $route    = $this->adminRestRoute->match($request->method(), $relative);

        if (!$this->shouldVerify($request, $route)) {
            $this->csrf->token();

            return $next($request);
        }

        $body = (string) $request->post($this->csrf->fieldName(), '');
        if ($body === '') {
            $raw = (string) $request->getContent();
            if ($raw !== '') {
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    $body = (string) ($json[$this->csrf->fieldName()] ?? '');
                }
            }
        }
        $header = (string) ($request->header('X-CSRF-Token') ?? $request->header('x-csrf-token') ?? '');
        if (!$this->csrf->validateRequest($body, $header !== '' ? $header : null)) {
            if ($this->validateMemberPublishFrontCsrf($request, $body, $header)) {
                return $next($request);
            }

            return AdminApiResponse::fail('CSRF 校验失败，请刷新页面后重试');
        }

        return $next($request);
    }

    /** @param array<string,mixed>|null $route */
    private function shouldVerify(Request $request, ?array $route): bool
    {
        if (!in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }
        if ($this->adminRestRoute->isPublic($route)) {
            return false;
        }

        return true;
    }

    private function validateMemberPublishFrontCsrf(Request $request, string $body, string $header): bool
    {
        if (!MemberPublishAccess::isActiveMember() || !MemberPublishAccess::isAdminRestWriteRequest($request)) {
            return false;
        }
        $frontBody = $body;
        if ($frontBody === '') {
            $frontBody = (string) $request->post($this->frontCsrf->fieldName(), '');
        }

        return $this->frontCsrf->validateRequest(
            $frontBody,
            $header !== '' ? $header : null,
        );
    }
}
