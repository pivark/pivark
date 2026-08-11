<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use app\common\service\admin\AdminRestRouteRegistry;
use app\common\service\front\FrontAuthService;
use app\common\service\member\MemberConfigService;
use app\common\service\user\PermissionService;
use think\Request;

class MemberPublishAccess
{
    /** 旧 HTML admin 路由 controller/action（AuthCheck / CsrfCheck） */
    public const ALLOWED_LEGACY_ROUTES = [
        'memberpublish/bootstrap',
        'memberpublish/documentformmeta',
        'upload/config',
        'upload/check',
        'upload/image',
        'upload/file',
        'upload/chunkinit',
        'upload/chunkstatus',
        'upload/chunk',
        'upload/chunkcomplete',
        'document/upload',
        'media/list',
        'spa/documenttagpicker',
    ];

    /** @deprecated 兼容旧引用 */
    public const ALLOWED_ROUTES = self::ALLOWED_LEGACY_ROUTES;

    /** Admin REST 相对路径（METHOD:path）· 活跃会员可穿透 admin 登录 */
    private const ALLOWED_REST_ROUTES = [
        'GET:member-publish/bootstrap',
        'GET:member-publish/document-form-meta',
        'GET:meta/document-tag-picker',
        'GET:upload/config',
        'GET:media/list',
        'POST:upload/check',
        'POST:upload/image',
        'POST:upload/file',
        'POST:upload/chunk-init',
        'POST:upload/chunk-status',
        'POST:upload/chunk',
        'POST:upload/chunk-complete',
        'POST:documents/upload',
    ];

    /** 写操作须 FrontCsrf（AdminApiCsrfCheck 放行） */
    private const REST_WRITE_ROUTES = [
        'POST:upload/check',
        'POST:upload/image',
        'POST:upload/file',
        'POST:upload/chunk-init',
        'POST:upload/chunk-status',
        'POST:upload/chunk',
        'POST:upload/chunk-complete',
        'POST:documents/upload',
    ];

    public static function isActiveMember(): bool
    {
        if (!app(MemberConfigService::class)->isDocumentPublishOpen()) {
            return false;
        }
        $member = app(FrontAuthService::class)->current();

        return $member !== null && (int) ($member['id'] ?? 0) > 0;
    }

    public static function routeKey(Request $request): string
    {
        return app(PermissionService::class)->normalizeControllerKey((string) $request->controller())
            . '/' . strtolower((string) $request->action());
    }

    public static function restRouteKey(Request $request): string
    {
        $registry = app(AdminRestRouteRegistry::class);
        $relative = $registry->relativePathFromRequest((string) $request->pathinfo());

        return strtoupper($request->method()) . ':' . $relative;
    }

    public static function matchesLegacyRequest(Request $request): bool
    {
        return in_array(self::routeKey($request), self::ALLOWED_LEGACY_ROUTES, true);
    }

    public static function matchesAdminRestRequest(Request $request): bool
    {
        return in_array(self::restRouteKey($request), self::ALLOWED_REST_ROUTES, true);
    }

    public static function isAdminRestWriteRequest(Request $request): bool
    {
        return in_array(self::restRouteKey($request), self::REST_WRITE_ROUTES, true);
    }

    public static function allowsRequest(Request $request): bool
    {
        if (!self::isActiveMember()) {
            return false;
        }
        $path = strtolower(trim((string) $request->pathinfo(), '/'));
        if (str_starts_with($path, 'api/v1/admin')) {
            return self::matchesAdminRestRequest($request);
        }

        return self::matchesLegacyRequest($request);
    }
}
