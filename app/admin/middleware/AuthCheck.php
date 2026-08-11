<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\middleware;

use app\common\service\export\AdminDataAccessAuditService;
use app\common\service\user\PermissionService;
use app\common\support\AdminApiResponse;
use app\common\support\MemberPublishAccess;
use think\facade\Session;
use think\Request;
use think\Response;

/**
 * 后台路由组默认鉴权（见 app/route/admin.php），未登录跳转登录页。
 * 新增控制器无需 extends Base 即可受保护。
 */
class AuthCheck
{
    /** @var list<string> controller/action 小写，免登录 */
    private const EXEMPT = [
        'login/index',
        'spa/bootstrap',
        'memberpublish/bootstrap',
        'memberpublish/documentformmeta',
        'debug/captcha',
        // Vue History 壳（/admin/auth/login、/admin/dashboard/... 等）
        'index/index',
        'index/spafallback',
    ];

    /** @var list<string> 须改密时仍允许访问 */
    private const PASSWORD_CHANGE_ALLOWED = [
        'spa/bootstrap',
        'spa/user',
        'spa/changepassword',
        'index/index',
        'index/spafallback',
    ];

    public function handle(Request $request, \Closure $next)
    {
        if (!self::isExempt($request)) {
            $adminUser = Session::get('admin_user');
            if (empty($adminUser['id'])) {
                if (MemberPublishAccess::allowsRequest($request)) {
                    return $next($request);
                }
                $loginUrl = \app\common\support\SiteUrl::adminSpa('/auth/login');
                if (self::wantsJson($request)) {
                    return AdminApiResponse::authExpired('登录已过期，请重新登录', $loginUrl);
                }

                return self::topLoginRedirect($loginUrl);
            }

            $request->adminUser = $adminUser;

            if (self::mustChangePassword($adminUser) && !self::isPasswordChangeAllowed($request)) {
                $changeUrl = \app\common\support\SiteUrl::adminSpa('/profile?tab=password');
                if (self::wantsJson($request)) {
                    return AdminApiResponse::forbidden('请先修改初始密码', 403, [
                        'redirect' => $changeUrl,
                        'must_change_password' => 1,
                    ]);
                }

                return self::topLoginRedirect($changeUrl);
            }

            $controllerRaw = (string) $request->controller();
            $controller    = app(PermissionService::class)->normalizeControllerKey($controllerRaw);
            $action        = strtolower((string) $request->action());
            $required      = app(PermissionService::class)->resolveRequiredPermission($controllerRaw, $action);
            if ($controller === 'document' && $action === 'save') {
                $docId = (int) $request->post('id', 0);
                $required = $docId > 0 ? 'admin.document.edit' : 'admin.document.create';
            }

            if ($required !== null && !app(PermissionService::class)->can((int) $adminUser['id'], $required)) {
                if (app(AdminDataAccessAuditService::class)->isDataAccessAction($action)) {
                    app(AdminDataAccessAuditService::class)->logDeniedAccess(
                        $required,
                        $controller,
                        $action,
                        ['query_keys' => array_keys($request->get())],
                    );
                }
                if ($request->isAjax() || $request->isPost()) {
                    return AdminApiResponse::fail('无操作权限');
                }
                return Response::create('无操作权限', 'html', 403);
            }
        }

        return $next($request);
    }

    public static function isExempt(Request $request): bool
    {
        $key = app(PermissionService::class)->normalizeControllerKey((string) $request->controller())
            . '/' . strtolower((string) $request->action());
        return in_array($key, self::EXEMPT, true);
    }

    /** 旧 Layui iframe 壳内 302 无效，用 top 跳转登录 */
    private static function topLoginRedirect(string $url): Response
    {
        $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $body = '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<script>top.location.replace(' . json_encode($url, JSON_UNESCAPED_SLASHES) . ');</script>'
            . '</head><body><p>正在跳转登录…</p><p><a href="' . $safe . '">点此继续</a></p></body></html>';

        return Response::create($body, 'html', 200);
    }

    private static function wantsJson(Request $request): bool
    {
        if ($request->isAjax() || $request->isPost()) {
            return true;
        }
        $accept = strtolower((string) $request->header('accept', ''));
        if (str_contains($accept, 'application/json')) {
            return true;
        }

        return strtolower((string) $request->header('x-requested-with', '')) === 'xmlhttprequest';
    }

    /** @param array<string, mixed> $adminUser */
    private static function mustChangePassword(array $adminUser): bool
    {
        $uid = (int) ($adminUser['id'] ?? 0);
        if ($uid < 1) {
            return false;
        }
        // 以库为准；避免重装/改库后 Session 残留 must_change=1 导致登录页白屏
        $must = app(\app\common\service\user\UserPasswordService::class)->mustChange($uid);
        $sessionFlag = !empty($adminUser['must_change_password']);
        if ($must !== $sessionFlag) {
            $adminUser['must_change_password'] = $must ? 1 : 0;
            Session::set('admin_user', $adminUser);
        }

        return $must;
    }

    private static function isPasswordChangeAllowed(Request $request): bool
    {
        $key = app(PermissionService::class)->normalizeControllerKey((string) $request->controller())
            . '/' . strtolower((string) $request->action());

        return in_array($key, self::PASSWORD_CHANGE_ALLOWED, true);
    }
}
