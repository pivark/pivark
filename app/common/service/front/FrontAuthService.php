<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/** 前台会员会话（阅读权限、受限文章） */
declare(strict_types=1);

namespace app\common\service\front;

use app\common\support\ServiceResult;

use think\facade\Request;

use app\common\middleware\PivarkSessionInit;
use app\common\model\Document;
use app\common\model\Tag;
use app\common\service\auth\LoginAttemptService;
use app\common\service\document\satellite\DocumentPreviewService;
use app\common\service\member\MemberApiTokenService;
use app\common\service\member\MemberConfigService;
use app\common\service\member\MemberLevelService;
use app\common\service\member\MemberPointGiftService;
use app\common\service\user\PermissionService;
use app\common\service\member\MemberService;
use app\common\model\User;
use app\common\service\config\ConfigService;
use app\common\support\LoginGuard;
use app\common\support\SessionGuard;
use app\common\support\SiteUrl;
use think\facade\Session;

class FrontAuthService
{

    public function __construct(
        private readonly MemberLevelService $memberLevelService,
        private readonly DocumentPreviewService $documentPreviewService,
        private readonly LoginAttemptService $loginAttemptService,
        private readonly MemberPointGiftService $memberPointGiftService,
    ) {
    }

    public const SESSION_KEY = 'front_member';

    /** 是否已登录前台会员（不含后台管理员会话） */
    public function canReadRestricted(): bool
    {
        return $this->current() !== null;
    }

    /**
     * 前台文档是否可读（按会员等级；后台管理员默认与游客一致，避免误测）
     *
     * @param array<string, mixed>|Document $document
     */
    public function canReadDocument(array|Document $document): bool
    {
        if ($document instanceof Document) {
            $document = $document->toArray();
        }
        if ($this->allowAdminPreview((int) ($document['id'] ?? 0))) {
            return true;
        }

        return $this->memberLevelService->canReadDocument($document, $this->current());
    }

    /**
     * @param array<string, mixed>|Tag $tag
     */
    public function canReadTag(array|Tag $tag): bool
    {
        if ($tag instanceof Tag) {
            $tag = $tag->toArray();
        }
        if ($this->allowAdminPreview()) {
            return true;
        }

        return $this->memberLevelService->canReadTag($tag, $this->current());
    }

    /**
     * 后台预览受限正文：signed preview_token；legacy preview=1 仅超管
     */
    public function allowAdminPreview(int $documentId = 0): bool
    {
        $token = trim((string) Request::get('preview_token', ''));
        if ($token !== '') {
            return $this->documentPreviewService->validateToken($token, $documentId);
        }

        if ((string) Request::get('preview', '') !== '1') {
            return false;
        }

        if (!$this->sessionReadable()) {
            return false;
        }

        $admin = Session::get('admin_user');
        if (!is_array($admin) || empty($admin['id'])) {
            return false;
        }

        return app(PermissionService::class)->isSuperAdmin((int) $admin['id']);
    }

    /**
     * @return ServiceResult
     */
    public function login(string $username, string $password): ServiceResult
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return ServiceResult::fail('请输入用户名和密码');
        }

        $ip = Request::ip() ?: '';
        $locked = $this->loginAttemptService->guard($username, $ip);
        if ($locked !== null) {
            return $locked;
        }

        $user = LoginGuard::verifyCredentials($username, $password);
        if (!$user) {
            $this->loginAttemptService->recordFailure($username, $ip);
            return ServiceResult::fail('用户名或密码错误');
        }
        if (!app(MemberService::class)->hasMemberRole((int) $user->id)) {
            $this->loginAttemptService->recordFailure($username, $ip);
            return ServiceResult::fail('该账号无前台会员权限，请使用后台登录');
        }
        if (!app(MemberConfigService::class)->isCenterOpen()) {
            return ServiceResult::fail('会员中心已关闭');
        }
        if ((int) ($user->status ?? 0) !== 1) {
            $this->loginAttemptService->recordFailure($username, $ip);
            return ServiceResult::fail('账号未激活或已禁用，请联系管理员');
        }

        $this->loginAttemptService->clear($username, $ip);
        $this->establishSession($user);
        $this->memberPointGiftService->tryGrantLogin((int) $user->id);

        return ServiceResult::ok(null, '登录成功');
    }

    public function establishSession(User $user): void
    {
        SessionGuard::regenerateAfterLogin();

        User::updateLoginInfo((int) $user->id, Request::ip() ?: '');

        Session::set(self::SESSION_KEY, [
            'id'               => (int) $user->id,
            'username'         => (string) $user->username,
            'nickname'         => (string) ($user->nickname ?? $user->username),
            'member_level_id'  => $this->memberLevelService->resolveMemberLevelId((int) $user->id),
        ]);
    }

    public function logout(): void
    {
        Session::delete(self::SESSION_KEY);
    }

    /** 购买套餐等变更后，用数据库最新等级刷新前台会话 */
    public function refreshCurrentMember(): void
    {
        $current = $this->current();
        if ($current === null) {
            return;
        }
        $userId = (int) ($current['id'] ?? 0);
        if ($userId < 1) {
            return;
        }
        $user = User::find($userId);
        if ($user !== null) {
            $this->establishSession($user);
        }
    }

    /** @return array<string, mixed>|null */
    public function current(): ?array
    {
        if ($this->sessionReadable()) {
            $member = Session::get(self::SESSION_KEY);
            if (is_array($member)) {
                return $member;
            }
        }

        return $this->currentFromBearer();
    }

    public function isLoggedIn(): bool
    {
        return $this->current() !== null;
    }

    /** 游客延迟 Session：未启动时禁止读 Session，避免误触发 PHPSESSID */
    private function sessionReadable(): bool
    {
        try {
            $req = Request::instance();
            if (is_object($req) && isset($req->{PivarkSessionInit::REQUEST_FLAG})) {
                return (bool) $req->{PivarkSessionInit::REQUEST_FLAG};
            }
        } catch (\Throwable) {
            // Request 不可用时按已挂载 Session 处理
        }

        return true;
    }

    /** API v1 Bearer Token → 与 Session 等价的会员上下文（阅读权限判定） */
    /** @return array<string, mixed>|null */
    private function currentFromBearer(): ?array
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved === [] ? null : $resolved;
        }

        $userId = app(MemberApiTokenService::class)->resolveUserId();
        if ($userId < 1 || !app(MemberService::class)->hasMemberRole($userId)) {
            $resolved = [];

            return null;
        }

        $user = User::find($userId);
        if ($user === null || (int) ($user->status ?? 0) !== 1) {
            $resolved = [];

            return null;
        }

        $resolved = [
            'id'              => $userId,
            'username'        => (string) $user->username,
            'nickname'        => (string) ($user->nickname ?? $user->username),
            'member_level_id' => $this->memberLevelService->resolveMemberLevelId($userId),
        ];

        return $resolved;
    }

    public function loginRedirectUrl(string $target = ''): string
    {
        $target = trim($target);
        if ($target === '' || !$this->isSafeRedirect($target)) {
            $target = SiteUrl::home();
        }

        return SiteUrl::memberLogin($target);
    }

    public function isSafeRedirect(string $url): bool
    {
        if ($url === '' || str_starts_with($url, '//')) {
            return false;
        }
        if ($url[0] === '/') {
            return !str_starts_with(strtolower($url), '/admin');
        }
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        return in_array(strtolower($host), $this->allowedRedirectHosts(), true);
    }

    /** @return list<string> */
    private function allowedRedirectHosts(): array
    {
        static $hosts = null;
        if ($hosts !== null) {
            return $hosts;
        }

        $hosts = [];
        $extra = config('pivark.allowed_redirect_hosts');
        if (is_array($extra)) {
            foreach ($extra as $item) {
                if (is_string($item) && $item !== '') {
                    $hosts[] = strtolower($item);
                }
            }
        }

        foreach ([(string) app(ConfigService::class)->get('site_url', ''), (string) Request::host()] as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            if (!preg_match('#^https?://#i', $raw)) {
                $parsedHost = parse_url('http://' . ltrim($raw, '/'), PHP_URL_HOST);
            } else {
                $parsedHost = parse_url($raw, PHP_URL_HOST);
            }
            if (is_string($parsedHost) && $parsedHost !== '') {
                $hosts[] = strtolower($parsedHost);
            }
        }

        return $hosts = array_values(array_unique($hosts));
    }
}
