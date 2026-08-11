<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

use app\common\support\AdminApiResponse;
use app\common\support\SiteUrl;
use app\common\support\ServiceResult;
use app\common\service\admin\AdminTotpService;

use app\common\model\User;
use app\common\support\ConfigSensitiveKeys;
use app\common\service\user\PermissionService;
use think\facade\Session;
use think\Request;
use think\Response;

/** 后台敏感操作二次确认（管理员密码重验，MFA 前置） */
final class AdminSensitiveConfirmService
{

    public function __construct(
        private readonly AdminTotpService $adminTotpService,
        private readonly PermissionService $permissionService,
        private readonly AdminRestRouteRegistry $adminRestRouteRegistry,
    ) {
    }

    public const FIELD_NAME = 'admin_confirm_password';

    private const SESSION_KEY = 'admin_sensitive_confirm_until';
    private const CONFIRM_TTL = 300;

    /**
     * controller/action 小写（与 AdminRestRouteRegistry::permissionKey 对齐；
     * REST 经 Gateway@dispatch 时须先解析 leaf，见 resolveRouteKey）
     *
     * @var list<string>
     */
    private const ROUTES_ALWAYS = [
        'log/purgeall',
        'payment/refundorder',
        'payment/importwechatcerts',
        'payment/importalipaykeys',
        'backup/delete',
        'backup/restore',
        'sqlconsole/execute',
        'sqlconsole/import',
        'upgrade/coreapply',
        'upgrade/coreladderapply',
        'upgrade/corestepprepare',
        'upgrade/corerestore',
        'cron/eventdlqdelete',
        'cron/eventdlqreplay',
        'plugin/uninstall',
        'plugin/revokeentitlement',
        'plugin/grant',
        'media/enterpriseentityarchive',
        'media/enterpriseentitydelete',
        'media/enterpriseentityconsolidate',
        'media/enterprisedelete',
        'media/enterprisebatchdelete',
    ];

    public function guard(Request $request): ?Response
    {
        if (!in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return null;
        }
        if (!$this->requiresConfirm($request)) {
            return null;
        }

        $admin = Session::get('admin_user');
        $userId = is_array($admin) ? (int) ($admin['id'] ?? 0) : 0;
        if ($userId < 1) {
            return AdminApiResponse::authExpired('请先登录', SiteUrl::adminSpa('/auth/login'), 401);
        }

        if ($this->isRecentlyConfirmed($userId)) {
            return null;
        }

        if ($this->adminTotpService->isEnabled($userId)) {
            return $this->verifyTotpStep($request, $userId);
        }

        $password = trim((string) $request->post(self::FIELD_NAME, ''));
        if ($password === '') {
            $json = $this->jsonBody($request);
            if (is_array($json)) {
                $password = trim((string) ($json[self::FIELD_NAME] ?? ''));
            }
        }
        if ($password === '') {
            return AdminApiResponse::fromResult(ServiceResult::ok(['sensitive_confirm_required' => 1], '敏感操作需输入当前管理员密码确认'));
        }
        if (!$this->verifyPassword($userId, $password)) {
            return AdminApiResponse::fail('管理员密码不正确');
        }
        $this->markConfirmed($userId);

        return null;
    }

    public function markConfirmed(int $userId): void
    {
        if ($userId < 1) {
            return;
        }
        Session::set(self::SESSION_KEY, [
            'user_id' => $userId,
            'until'   => time() + self::CONFIRM_TTL,
        ]);
    }

    public function isRecentlyConfirmed(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }
        $raw = Session::get(self::SESSION_KEY);
        if (!is_array($raw) || (int) ($raw['user_id'] ?? 0) !== $userId) {
            return false;
        }

        return (int) ($raw['until'] ?? 0) > time();
    }

    public function verifyPassword(int $userId, string $password): bool
    {
        if ($userId < 1 || $password === '') {
            return false;
        }
        $user = User::where('id', $userId)->field('password')->find();
        if (!$user) {
            return false;
        }

        return password_verify($password, (string) $user->password);
    }

    private function verifyTotpStep(Request $request, int $userId): ?Response
    {
        $code = trim((string) $request->post(AdminTotpService::FIELD_NAME, ''));
        if ($code === '') {
            $json = $this->jsonBody($request);
            if (is_array($json)) {
                $code = trim((string) ($json[AdminTotpService::FIELD_NAME] ?? ''));
            }
        }
        if ($code === '') {
            return AdminApiResponse::fromResult(ServiceResult::ok(['sensitive_totp_required' => 1], '敏感操作需输入 Authenticator 6 位验证码'));
        }
        if (!$this->adminTotpService->verifyForUser($userId, $code)) {
            return AdminApiResponse::fail('两步验证码不正确');
        }
        $this->markConfirmed($userId);

        return null;
    }

    public function isSensitiveConfigKey(string $key): bool
    {
        return ConfigSensitiveKeys::isAdminConfirmKey($key);
    }

    private function requiresConfirm(Request $request): bool
    {
        $routeKey = $this->resolveRouteKey($request);
        if (in_array($routeKey, self::ROUTES_ALWAYS, true)) {
            return true;
        }
        // 支付配置：仅当请求触达密钥/模式等敏感键时二次确认；通道总开关等可直接保存
        if ($routeKey === 'payment/configsave' || $routeKey === 'config/save') {
            return $this->requestTouchesSensitiveConfig($request);
        }
        if (app(AdminSensitiveConfirmRouteRegistry::class)->matchesRequestPath(
            (string) (parse_url($request->url(), PHP_URL_PATH) ?: $request->pathinfo())
        )) {
            return true;
        }

        return false;
    }

    /** REST Gateway@dispatch → leaf permission_controller/action；否则用 Think 控制器名 */
    private function resolveRouteKey(Request $request): string
    {
        $controller = $this->permissionService->normalizeControllerKey((string) $request->controller());
        $action = strtolower((string) $request->action());
        $routeKey = $controller . '/' . $action;
        if ($controller !== 'gateway' || $action !== 'dispatch') {
            return $routeKey;
        }

        $path = (string) (parse_url($request->url(), PHP_URL_PATH) ?: $request->pathinfo());
        $relative = $this->adminRestRouteRegistry->relativePathFromRequest($path);
        $route = $this->adminRestRouteRegistry->match($request->method(), $relative);
        if ($route === null) {
            return $routeKey;
        }
        [$leafController, $leafAction] = $this->adminRestRouteRegistry->permissionKey($route);

        return $this->permissionService->normalizeControllerKey($leafController)
            . '/' . strtolower($leafAction);
    }

    private function requestTouchesSensitiveConfig(Request $request): bool
    {
        foreach ($request->post() as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if ($this->isSensitiveConfigKey($key)) {
                return true;
            }
        }
        $json = $this->jsonBody($request);
        if (is_array($json)) {
            foreach ($json as $key => $value) {
                if (is_string($key) && $this->isSensitiveConfigKey($key)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonBody(Request $request): ?array
    {
        $contentType = strtolower((string) $request->header('content-type', ''));
        if ($contentType !== '' && !str_contains($contentType, 'application/json')) {
            return null;
        }
        $raw = $request->getContent();
        if ($raw === '') {
            return null;
        }
        $json = json_decode($raw, true);

        return is_array($json) ? $json : null;
    }
}
