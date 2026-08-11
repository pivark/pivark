<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\support\ServiceResult;
use app\common\service\member\MemberConfigService;
use app\common\service\member\MemberService;

use app\common\service\audit\AuditLogService;
use app\common\service\front\FrontAuthService;
use app\common\model\User;
use app\common\support\SiteUrl;
use think\facade\Cache;
use think\facade\Session;

/** 后台管理员代登录前台会员（短时一次性令牌） */
class MemberViewAsService
{

    public function __construct(
        private readonly MemberConfigService $memberConfigService,
        private readonly FrontAuthService $frontAuthService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    private function member(): MemberService
    {
        return app(MemberService::class);
    }

    private const CACHE_PREFIX = 'member_view_as:';
    private const TTL_SECONDS = 120;

    /** 为指定会员签发「进入前台个人中心」链接（仅后台调用） */
    public function issueEnterCenterUrl(int $userId, int $adminUserId = 0): ?string
    {
        if ($userId < 1 || !$this->member()->hasMemberRole($userId)) {
            return null;
        }
        $user = User::find($userId);
        if (!$user) {
            return null;
        }

        if ($adminUserId < 1) {
            $admin = Session::get('admin_user');
            $adminUserId = is_array($admin) ? (int) ($admin['id'] ?? 0) : 0;
        }

        $token = bin2hex(random_bytes(16));
        Cache::set(self::CACHE_PREFIX . $token, [
            'user_id'  => $userId,
            'admin_id' => $adminUserId,
        ], self::TTL_SECONDS);

        return SiteUrl::memberEnterAs($token);
    }

    /**
     * 消费令牌并建立前台会话
     *
     * @return ServiceResult
     */
    public function consumeEnterToken(string $token): ServiceResult
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            return ServiceResult::fail('链接无效');
        }

        $key = self::CACHE_PREFIX . $token;
        $payload = Cache::get($key);
        Cache::delete($key);
        if (!is_array($payload)) {
            return ServiceResult::fail('链接已失效，请返回后台重新打开');
        }

        $userId = (int) ($payload['user_id'] ?? 0);
        $adminId = (int) ($payload['admin_id'] ?? 0);
        if ($userId < 1 || !$this->member()->hasMemberRole($userId)) {
            return ServiceResult::fail('会员不存在');
        }

        $user = User::find($userId);
        if (!$user instanceof User) {
            return ServiceResult::fail('会员不存在');
        }

        if (!$this->memberConfigService->isCenterOpen()) {
            return ServiceResult::fail('会员中心已关闭');
        }

        $this->frontAuthService->establishSession($user);

        $this->auditLogService->operate('代登录查看会员中心', 'admin.member', [
            'member_user_id' => $userId,
            'member_username' => (string) $user->username,
            'admin_user_id' => $adminId,
        ]);

        return ServiceResult::ok(['redirect' => $this->resolveEnterCenterRedirect()], 'ok');
    }

    /** platform 宿主进 host_only 账户中心；Community 客户站进 /member/center */
    private function resolveEnterCenterRedirect(): string
    {
        return SiteUrl::memberCenter();
    }
}
