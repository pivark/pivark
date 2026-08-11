<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\middleware;

use app\common\support\AdminApiResponse;
use app\common\service\auth\CsrfService;
use app\common\service\user\PermissionService;
use app\common\service\front\FrontCsrfService;
use app\common\support\MemberPublishAccess;
use think\Request;
use think\Response;

class CsrfCheck
{
    /** @var list<string> controller/action 小写，免 CSRF */
    private const EXEMPT = [
        'login/index',
        'spa/bootstrap',
    ];

    public function handle(Request $request, \Closure $next)
    {
        if (!$this->shouldVerify($request)) {
            app(CsrfService::class)->token();
            return $next($request);
        }

        $body = (string) $request->post(app(CsrfService::class)->fieldName(), '');
        if ($body === '') {
            $body = self::tokenFromJsonBody($request);
        }
        $header = (string) ($request->header('X-CSRF-Token') ?? $request->header('x-csrf-token') ?? '');
        if (!app(CsrfService::class)->validateRequest($body, $header !== '' ? $header : null)) {
            if (self::validateMemberPublishCsrf($request, $body, $header)) {
                return $next($request);
            }
            if ($request->isAjax() || $request->isPost()) {
                return AdminApiResponse::fail('CSRF 校验失败，请刷新页面后重试');
            }
            return Response::create('CSRF 校验失败', 'html', 403);
        }

        return $next($request);
    }

    private function shouldVerify(Request $request): bool
    {
        if (!in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }
        $key = app(PermissionService::class)->normalizeControllerKey((string) $request->controller())
            . '/' . strtolower((string) $request->action());
        return !in_array($key, self::EXEMPT, true);
    }

    private static function tokenFromJsonBody(Request $request): string
    {
        $contentType = strtolower((string) $request->header('content-type', ''));
        if ($contentType !== '' && !str_contains($contentType, 'application/json')) {
            return '';
        }
        $raw = $request->getContent();
        if ($raw === '') {
            return '';
        }
        $json = json_decode($raw, true);
        if (!is_array($json) || !isset($json[app(CsrfService::class)->fieldName()])) {
            return '';
        }

        return (string) $json[app(CsrfService::class)->fieldName()];
    }

    private static function validateMemberPublishCsrf(Request $request, string $body, string $header): bool
    {
        if (!MemberPublishAccess::allowsRequest($request)) {
            return false;
        }
        $frontBody = $body;
        if ($frontBody === '') {
            $frontBody = (string) $request->post(app(FrontCsrfService::class)->fieldName(), '');
        }

        return app(FrontCsrfService::class)->validateRequest(
            $frontBody,
            $header !== '' ? $header : null,
        );
    }
}
