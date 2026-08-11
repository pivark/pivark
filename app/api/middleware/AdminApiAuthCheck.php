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
use app\common\service\export\AdminDataAccessAuditService;
use app\common\service\user\PermissionService;
use app\common\service\user\UserPasswordService;
use app\common\support\AdminApiResponse;
use app\common\support\MemberPublishAccess;
use app\common\support\SiteUrl;
use think\facade\Session;
use think\Request;
use think\Response;

/** /api/v1/admin 鉴权（路径 SSOT：AdminRestRouteRegistry） */
class AdminApiAuthCheck
{
    public function __construct(
        private readonly AdminRestRouteRegistry $adminRestRoute,
        private readonly PermissionService $permission,
        private readonly AdminDataAccessAuditService $adminDataAccessAudit,
        private readonly UserPasswordService $userPassword,
    ) {
    }

    public function handle(Request $request, \Closure $next): Response
    {
        $relative = $this->adminRestRoute->relativePathFromRequest((string) $request->pathinfo());
        $route    = $this->adminRestRoute->match($request->method(), $relative);

        if (!$this->adminRestRoute->isPublic($route)) {
            $adminUser = Session::get('admin_user');
            if (empty($adminUser['id'])) {
                if (MemberPublishAccess::allowsRequest($request)) {
                    return $next($request);
                }

                return AdminApiResponse::authExpired(
                    '登录已过期，请重新登录',
                    SiteUrl::adminSpa('/auth/login'),
                );
            }

            $request->adminUser = $adminUser;

            if ($this->mustChangePassword($adminUser) && !$this->adminRestRoute->allowsPasswordChange($route)) {
                return AdminApiResponse::forbidden('请先修改初始密码', 403, [
                    'redirect'             => SiteUrl::adminSpa('/profile?tab=password'),
                    'must_change_password' => 1,
                ]);
            }

            [$controller, $action] = $this->adminRestRoute->permissionKey($route);
            $required = $this->permission->resolveRequiredPermission($controller, $action);
            if ($required !== null && !$this->permission->can((int) $adminUser['id'], $required)) {
                if ($this->adminDataAccessAudit->isDataAccessAction($action)) {
                    $this->adminDataAccessAudit->logDeniedAccess(
                        $required,
                        $controller,
                        $action,
                        ['query_keys' => array_keys($request->get())],
                    );
                }

                return AdminApiResponse::fail('无操作权限');
            }
        }

        return $next($request);
    }

    /** @param array<string, mixed> $adminUser */
    private function mustChangePassword(array $adminUser): bool
    {
        $uid = (int) ($adminUser['id'] ?? 0);
        if ($uid < 1) {
            return false;
        }
        $must = $this->userPassword->mustChange($uid);
        $sessionFlag = !empty($adminUser['must_change_password']);
        if ($must !== $sessionFlag) {
            $adminUser['must_change_password'] = $must ? 1 : 0;
            Session::set('admin_user', $adminUser);
        }

        return $must;
    }
}
