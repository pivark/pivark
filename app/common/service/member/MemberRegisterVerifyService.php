<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\support\AppTime;

use app\common\support\ServiceResult;
use app\common\service\member\MemberPointService;
use app\common\service\member\MemberConfigService;

use app\common\model\User;
use app\common\service\config\ConfigService;
use app\common\service\mail\MailService;
use app\common\service\payment\PaymentUrl;
use think\facade\Db;

class MemberRegisterVerifyService
{

    public function __construct(
        private readonly MemberConfigService $memberConfigService,
        private readonly MailService $mailService,
        private readonly PaymentUrl $paymentUrl,
        private readonly ConfigService $configService,
        private readonly MemberRoleCheckService $memberRoleCheckService,
        private readonly MemberPointService $memberPointService,
    ) {
    }

    public function isEmailModeActive(): bool
    {
        return $this->memberConfigService->registerVerifyMode() === 'email'
            && $this->mailService->isConfigured();
    }

    /** @return ServiceResult */
    public function sendForUser(int $userId, string $email): ServiceResult
    {
        $email = trim($email);
        if ($userId < 1 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ServiceResult::fail('邮箱无效');
        }
        if (!$this->mailService->isConfigured()) {
            return ServiceResult::fail('邮件服务未配置，请先在系统设置中配置 SMTP');
        }

        $token = bin2hex(random_bytes(16));
        User::where('id', $userId)->update([
            'email'                 => $email,
            'email_verify_token'    => $token,
            'email_verify_sent_at'  => AppTime::now(),
            'email_verified_at'     => null,
            'status'                => 0,
        ]);

        $link = $this->paymentUrl->absolute('/member/verify-email?token=' . rawurlencode($token));
        $siteName = (string) ($this->configService->get('site_name', '本站'));
        $subject  = "【{$siteName}】请验证您的注册邮箱";
        $html     = '<p>您好，请点击以下链接完成邮箱验证（24 小时内有效）：</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</a></p>'
            . '<p>如非本人操作请忽略本邮件。</p>';

        $sent = $this->mailService->send($email, $subject, $html);
        if (!$sent->isOk()) {
            return ServiceResult::fail((string) ($sent->message() ?? '验证邮件发送失败'));
        }

        return ServiceResult::ok(null, '验证邮件已发送，请查收后点击链接激活账号');
    }

    /** @return ServiceResult */
    public function verifyToken(string $token): ServiceResult
    {
        $token = trim($token);
        if ($token === '' || strlen($token) < 16) {
            return ServiceResult::fail('验证链接无效');
        }

        $user = User::where('email_verify_token', $token)->find();
        if (!$user instanceof User || !$this->memberRoleCheckService->hasMemberRole((int) $user->id)) {
            return ServiceResult::fail('验证链接无效或已使用');
        }

        $sentAt = (string) ($user->email_verify_sent_at ?? '');
        if ($sentAt !== '' && strtotime($sentAt) < time() - 86400) {
            return ServiceResult::fail('验证链接已过期，请重新注册或联系管理员');
        }

        $userId = (int) $user->id;
        User::where('id', $userId)->update([
            'status'              => 1,
            'email_verify_token'  => null,
            'email_verified_at'   => AppTime::now(),
        ]);
        if ($this->memberConfigService->isPointsEnabled()) {
            $gift = $this->memberConfigService->registerGiftPoints();
            if ($gift > 0) {
                $this->memberPointService->grant($userId, $gift, '注册赠送');
            }
        }

        return ServiceResult::ok(null, '邮箱验证成功，请登录会员中心');
    }
}
