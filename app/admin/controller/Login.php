<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare (strict_types = 1);

namespace app\admin\controller;

use app\common\enum\ApiErrorCode;
use app\common\support\ServiceResult;
use app\common\support\AdminApiResponse;
use app\common\service\auth\CaptchaService;
use app\common\service\auth\CsrfService;
use app\common\service\audit\AuditLogService;
use app\common\support\LoginGuard;
use app\common\support\SessionGuard;
use app\common\support\SiteUrl;
use app\common\service\auth\LoginAttemptService;
use app\common\service\member\MemberService;
use app\common\service\user\PermissionService;
use app\common\service\user\UserPasswordService;
use app\common\service\user\UserService;
use think\facade\Session;
use think\facade\Request;
use think\Response;
use think\response\Redirect;

class Login
{
    public function __construct(
        private readonly CaptchaService $captcha,
        private readonly LoginAttemptService $loginAttempt,
        private readonly PermissionService $permission,
        private readonly MemberService $member,
        private readonly UserPasswordService $userPassword,
        private readonly UserService $user,
        private readonly CsrfService $csrf,
        private readonly AuditLogService $auditLog,
    ) {
    }

    /** 旧书签 /admin/login* → Vue 登录页（API 见 /api/v1/admin/auth/*） */
    public function index()
    {
        if (Session::has('admin_user')) {
            return redirect(SiteUrl::adminSpa());
        }

        return redirect(SiteUrl::adminSpa('/auth/login'));
    }

    public function status(): Response
    {
        $captcha = $this->captcha->forScene('admin');
        return Response::create(json_encode([
            'on'    => $captcha->isEnabled(),
            'scene' => 'admin',
            'url'   => $captcha->imageUrl(),
        ], JSON_UNESCAPED_SLASHES), 'json', 200)
            ->header(['Content-Type' => 'application/json']);
    }

    /** REST：GET /api/v1/admin/auth/captcha（等价 GET /captcha/admin） */
    public function captcha(): Response
    {
        return $this->captcha->forScene('admin')->create();
    }

    public function do_login()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $username = Request::post('username', '');
        $password = Request::post('password', '');
        $captcha  = Request::post('captcha', '');

        if (empty($username) || empty($password)) {
            return AdminApiResponse::fail('请输入用户名和密码');
        }

        $ip = Request::ip();
        $locked = $this->loginAttempt->guard($username, $ip);
        if ($locked !== null) {
            return AdminApiResponse::admin($locked);
        }

        $capErr = $this->captcha->forScene('admin')->guardLogin($captcha);
        if ($capErr !== null) {
            return AdminApiResponse::fail($capErr->message() !== '' ? $capErr->message() : '验证码错误');
        }

        $user = LoginGuard::verifyCredentials($username, $password);
        if (!$user) {
            $this->loginAttempt->recordFailure($username, $ip);
            return AdminApiResponse::fail('用户名或密码错误');
        }
        if (!$this->permission->hasBackofficeRole((int) $user->id)) {
            $this->loginAttempt->recordFailure($username, $ip);
            if ($this->member->hasMemberRole((int) $user->id)) {
                $memberLoginUrl = SiteUrl::memberLogin();

                return AdminApiResponse::fromResult(ServiceResult::fail(
                    '该账号为前台会员账号，请在前台会员中心登录',
                    ApiErrorCode::VALIDATION,
                    ['member_login_url' => $memberLoginUrl, 'redirect' => $memberLoginUrl],
                ));
            }

            return AdminApiResponse::fail('该账号无权登录后台');
        }

        $this->loginAttempt->clear($username, $ip);

        SessionGuard::regenerateAfterLogin();

        $mustChange = $this->userPassword->mustChange((int) $user->id);
        $rbac = $this->permission->buildSessionPayload((int) $user->id);
        Session::set('admin_user', array_merge([
            'id'              => $user->id,
            'username'        => $user->username,
            'realname'        => $user->nickname ?? $user->username,
            'last_login_time' => $user->last_login_time,
            'must_change_password' => $mustChange ? 1 : 0,
        ], $rbac));

        $this->user->recordLogin((int) $user->id, $ip);
        $this->csrf->regenerate();

        $this->auditLog->operate('登录后台', 'admin.login', ['username' => $user->username], true);

        $home = $mustChange ? SiteUrl::adminSpa('/profile?tab=password') : SiteUrl::adminSpa();

        return AdminApiResponse::fromResult(ServiceResult::ok(['msg'                  => $mustChange ? '登录成功，请先修改初始密码' : '登录成功',
            'redirect' => $home,
            'must_change_password' => $mustChange ? 1 : 0]));
    }

    public function logout(): Redirect|Response
    {
        Session::delete('admin_user');
        $this->csrf->regenerate();

        $loginUrl = SiteUrl::adminSpa('/auth/login') . '?logout=1';

        if (
            Request::isPost()
            && (Request::isAjax() || Request::header('X-Requested-With') === 'XMLHttpRequest')
        ) {
            return AdminApiResponse::fromResult(ServiceResult::ok(['redirect' => $loginUrl], '已退出登录'));
        }

        return redirect($loginUrl);
    }
}
