<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\user;

use app\common\service\infra\RateLimitGateway;
use app\common\support\AppTime;

use app\common\support\ServiceResult;
use app\common\model\PasswordResetToken;

use app\common\service\audit\AuditLogService;
use app\common\service\mail\MailService;
use app\common\service\member\MemberConfigService;
use app\common\service\member\MemberService;
use app\common\service\config\ConfigService;
use app\common\model\User;
use app\common\support\SiteUrl;
use app\common\support\OpsLog;
use think\facade\Db;

class PasswordResetService
{

    public function __construct(
        private readonly MemberConfigService $memberConfigService,
        private readonly MailService $mailService,
        private readonly MemberService $memberService,
        private readonly ConfigService $configService,
        private readonly AuditLogService $auditLogService,
        private readonly RateLimitGateway $rateLimitGateway,
    ) {
    }

    private const TOKEN_TTL = 3600;

    /**
     * @return ServiceResult
     */
    public function requestByEmail(string $email, string $ip = ''): ServiceResult
    {
        if (!$this->memberConfigService->isPasswordRecoverViaEmail()) {
            return ServiceResult::fail('暂未开放邮件找回密码');
        }
        if (!$this->mailService->isConfigured()) {
            return ServiceResult::fail('邮件服务未配置，请联系管理员');
        }

        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ServiceResult::fail('请输入有效邮箱');
        }

        $blocked = $this->rateLimitGateway->check('member.password_reset', $ip);
        if ($blocked !== null) {
            return ServiceResult::fail((string) ($blocked->msg ?? '操作过于频繁，请稍后再试'));
        }

        $user = User::where('email', $email)->where('status', 1)->find();
        if (!$user || !$this->memberService->hasMemberRole((int) $user->id)) {
            return ServiceResult::ok(null, '若该邮箱已注册，将收到重置邮件，请查收');
        }

        $token = bin2hex(random_bytes(32));
        $now   = AppTime::now();
        PasswordResetToken::insert([
            'user_id'    => (int) $user->id,
            'email'      => $email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => AppTime::format('Y-m-d H:i:s', time() + self::TOKEN_TTL),
            'request_ip' => substr($ip, 0, 64),
            'created_at' => $now,
        ]);

        $resetUrl = SiteUrl::memberPasswordReset($token);
        $siteName = (string) $this->configService->get('site_name', 'PivArk');
        $subject  = "【{$siteName}】重置您的登录密码";
        $html     = '<p>您好，' . htmlspecialchars((string) ($user->nickname ?: $user->username), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>我们收到了重置密码的请求。请点击下方链接（1 小时内有效）：</p>'
            . '<p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '</a></p>'
            . '<p>如非本人操作，请忽略此邮件。</p>';
        $text = "请打开以下链接重置密码（1 小时内有效）：\n{$resetUrl}";

        $sent = $this->mailService->send($email, $subject, $html, $text);
        if (!$sent->isOk()) {
            return ServiceResult::fail((string) ($sent->message() ?? '邮件发送失败'));
        }

        return ServiceResult::ok(null, '若该邮箱已注册，将收到重置邮件，请查收');
    }

    /**
     * 邮件提醒登录名（与找回密码同开关；防枚举：未命中也返回成功文案）。
     *
     * @return ServiceResult
     */
    public function remindUsernameByEmail(string $email, string $ip = ''): ServiceResult
    {
        if (!$this->memberConfigService->isPasswordRecoverViaEmail()) {
            return ServiceResult::fail('暂未开放邮件找回登录名');
        }
        if (!$this->mailService->isConfigured()) {
            return ServiceResult::fail('邮件服务未配置，请联系管理员');
        }

        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ServiceResult::fail('请输入有效邮箱');
        }

        $blocked = $this->rateLimitGateway->check('member.password_reset', $ip);
        if ($blocked !== null) {
            return ServiceResult::fail((string) ($blocked->msg ?? '操作过于频繁，请稍后再试'));
        }

        $user = User::where('email', $email)->where('status', 1)->find();
        if (!$user || !$this->memberService->hasMemberRole((int) $user->id)) {
            return ServiceResult::ok(null, '若该邮箱已绑定会员账号，将收到登录名提醒邮件');
        }

        $username = trim((string) ($user->username ?? ''));
        if ($username === '') {
            return ServiceResult::ok(null, '若该邮箱已绑定会员账号，将收到登录名提醒邮件');
        }

        $siteName = (string) $this->configService->get('site_name', 'PivArk');
        $loginUrl = SiteUrl::memberLogin();
        $display  = htmlspecialchars((string) ($user->nickname ?: $username), ENT_QUOTES, 'UTF-8');
        $safeUser = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $safeLogin = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
        $subject  = "【{$siteName}】您的登录名提醒";
        $html     = '<p>您好，' . $display . '</p>'
            . '<p>您申请了登录名提醒。绑定本邮箱的登录名为：</p>'
            . '<p style="font-size:18px;font-weight:700;letter-spacing:0.02em;">' . $safeUser . '</p>'
            . '<p>请前往登录：<a href="' . $safeLogin . '">' . $safeLogin . '</a></p>'
            . '<p>如非本人操作，请忽略此邮件。</p>';
        $text = "您的登录名：{$username}\n登录地址：{$loginUrl}";

        $sent = $this->mailService->send($email, $subject, $html, $text);
        if (!$sent->isOk()) {
            return ServiceResult::fail((string) ($sent->message() ?? '邮件发送失败'));
        }

        $this->auditLogService->operate('会员邮件提醒登录名', 'member.username_remind', [
            'user_id' => (int) $user->id,
        ]);

        return ServiceResult::ok(null, '若该邮箱已绑定会员账号，将收到登录名提醒邮件');
    }

    /**
     * @return ServiceResult
     */
    public function resetWithToken(string $token, string $password, string $confirm): ServiceResult
    {
        if (!$this->memberConfigService->isPasswordRecoverViaEmail()) {
            return ServiceResult::fail('暂未开放邮件找回密码');
        }

        $token = trim($token);
        if (strlen($token) < 32) {
            return ServiceResult::fail('重置链接无效');
        }
        if (strlen($password) < 8) {
            return ServiceResult::fail('密码至少 8 位');
        }
        if (!preg_match('/[a-zA-Z]/', $password) || !preg_match('/\d/', $password)) {
            return ServiceResult::fail('密码须包含字母与数字');
        }
        if ($password !== $confirm) {
            return ServiceResult::fail('两次密码不一致');
        }

        $hash = hash('sha256', $token);
        $row  = PasswordResetToken::where('token_hash', $hash)
            ->whereNull('used_at')
            ->where('expires_at', '>', AppTime::now())
            ->order('id', 'desc')
            ->find();
        if (!$row) {
            return ServiceResult::fail('链接已失效，请重新申请');
        }

        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId < 1 || !$this->memberService->hasMemberRole($userId)) {
            return ServiceResult::fail('账号无效');
        }

        Db::startTrans();
        try {
            User::where('id', $userId)->update([
                'password' => password_hash($password, PASSWORD_BCRYPT),
            ]);
            PasswordResetToken::where('id', (int) $row['id'])->update([
                'used_at' => AppTime::now(),
            ]);
            PasswordResetToken::where('user_id', $userId)
                ->whereNull('used_at')
                ->where('id', '<>', (int) $row['id'])
                ->update(['used_at' => AppTime::now()]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            OpsLog::businessWarning('password_reset_apply_failed', ['msg' => $e->getMessage()]);

            return ServiceResult::fail('重置失败，请稍后重试');
        }

        app(\app\common\service\member\MemberApiTokenService::class)->revokeAllForUser($userId);
        $this->auditLogService->operate('会员邮件重置密码', 'member.password_reset', ['user_id' => $userId]);

        return ServiceResult::ok(null, '密码已重置，请使用新密码登录');
    }
}
