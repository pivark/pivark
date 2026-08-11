<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\support\MoneyMath;
use app\common\service\member\MemberService;
use app\common\service\member\MemberConfigService;
use app\common\service\member\MemberPointService;
use app\common\service\member\MemberBalanceService;
use app\common\service\member\MemberLevelService;

use app\common\service\document\satellite\DocumentPaymentService;
use app\common\service\front\FrontAuthService;
use app\common\service\mail\MailService;
use app\common\service\sms\SmsService;
use app\common\support\SiteUrl;
use think\facade\Request;

class MemberUxService
{

    public function __construct(
        private readonly MailService $mailService,
        private readonly DocumentPaymentService $documentPaymentService,
        private readonly FrontAuthService $frontAuthService,
        private readonly MemberLevelService $memberLevelService,
        private readonly MemberConfigService $memberConfigService,
        private readonly SmsService $smsService,
    ) {
    }

    private function memberBalance(): MemberBalanceService
    {
        return app(MemberBalanceService::class);
    }

    private function memberPoint(): MemberPointService
    {
        return app(MemberPointService::class);
    }

    private function member(): MemberService
    {
        return app(MemberService::class);
    }

    /** @return array{mail_ready:bool,payment_ready:bool,email_verify_ready:bool,sms_ready:bool,signin_ready:bool,consume_ready:bool,login_daily:bool} */
    public function adminCapabilityHints(): array
    {
        return [
            'mail_ready'          => $this->mailService->isConfigured(),
            'payment_ready'       => $this->documentPaymentService->enabled(),
            'email_verify_ready'  => $this->mailService->isConfigured(),
            'sms_ready'           => $this->smsService->isConfigured(),
            'signin_ready'        => true,
            'consume_ready'       => true,
            'login_daily'         => true,
        ];
    }

    /**
     * @param array<string, mixed> $docRow
     * @return array{hint:string,cta_url:string,cta_label:string,logged_in:int}
     */
    public function documentPermissionCta(array $docRow, bool $memberLoggedIn = false): array
    {
        $loginRequired = (int) ($docRow['read_perm'] ?? 0) === 1;
        if (!$loginRequired) {
            return ['hint' => '', 'cta_url' => '', 'cta_label' => '', 'logged_in' => $memberLoggedIn ? 1 : 0];
        }

        $canRead = $memberLoggedIn
            ? $this->frontAuthService->canReadDocument($docRow)
            : $this->memberLevelService->canReadDocument($docRow, null);
        if ($canRead) {
            return ['hint' => '', 'cta_url' => '', 'cta_label' => '', 'logged_in' => $memberLoggedIn ? 1 : 0];
        }

        $levelId   = (int) ($docRow['read_level_id'] ?? 0);
        $levelName = $levelId > 0 ? $this->memberLevelService->getName($levelId) : '';

        if (!$memberLoggedIn) {
            return [
                'hint'       => $levelName !== ''
                    ? "本文需 {$levelName} 及以上会员阅读，请先登录。"
                    : '本文需登录后阅读全文。',
                'cta_url'    => SiteUrl::memberLogin((string) Request::server('REQUEST_URI', '')),
                'cta_label'  => '登录后阅读',
                'logged_in'  => 0,
            ];
        }

        return [
            'hint'      => $levelName !== ''
                ? "当前等级无法阅读，升级至 {$levelName} 及以上即可查看全文。"
                : '当前账号暂无阅读权限，可升级会员等级或联系管理员。',
            'cta_url'   => SiteUrl::memberRecharge(),
            'cta_label' => '去充值升级',
            'logged_in' => 1,
        ];
    }

    public function registerSuccessMessage(bool $adminPending, bool $emailPending): string
    {
        if ($emailPending) {
            return '注册成功！验证邮件已发送至您的邮箱，请点击链接激活后再登录。';
        }
        if ($adminPending) {
            return '注册成功！账号需管理员审核通过后方可登录，请耐心等待。';
        }

        return '注册成功！已为您自动登录，可在个人中心完善资料。';
    }

    /**
     * @param array<string, mixed>|null $order
     * @return array{title:string,body:string,cta_url:string,cta_label:string,paid:int,auto_redirect?:string}
     */
    public function payReturnPanel(bool $paid, string $orderNo, string $amount, ?array $order = null): array
    {
        if ($paid) {
            $ctaUrl = SiteUrl::memberCenter();
            $ctaLabel = '进入个人中心';
            if (is_array($order) && (string) ($order['scene'] ?? '') === 'recharge') {
                $ctaUrl   = SiteUrl::memberRecharge();
                $ctaLabel = '返回充值中心';
            }

            return [
                'title'          => '支付成功',
                'body'           => $orderNo !== ''
                    ? ('订单号 ' . $orderNo . ($amount !== '' ? '，金额 ¥' . $amount : '') . '。权益已到账，可在个人中心查看。')
                    : '支付已完成，权益已到账。',
                'cta_url'        => $ctaUrl,
                'cta_label'      => $ctaLabel,
                'paid'           => 1,
                'auto_redirect'  => '',
            ];
        }

        return [
            'title'         => '支付处理中',
            'body'          => '如已完成支付，请稍后刷新本页；若长时间未到账，请保留订单号联系客服或在充值页查看记录。',
            'cta_url'       => SiteUrl::memberRecharge(),
            'cta_label'     => '返回充值中心',
            'paid'          => 0,
            'auto_redirect' => $orderNo !== '' ? SiteUrl::memberRecharge() . '?recharged=1&order_no=' . rawurlencode($orderNo) : '',
        ];
    }

    /** @return array<string, mixed> */
    public function rechargeMemberStatus(int $userId): array
    {
        $balance = $this->memberBalance()->balance($userId);
        $points  = $this->memberPoint()->balance($userId);
        $parts   = ['余额 ' . MoneyMath::formatYuan($balance, true)];
        if ($this->memberConfigService->isPointsEnabled()) {
            $parts[] = $this->memberConfigService->pointsLabel() . ' ' . $points;
        }
        $profile = $this->member()->profile($userId);
        if (is_array($profile)) {
            $levelId = (int) ($profile['member_level_id'] ?? 0);
            if ($levelId > 0) {
                $parts[] = $this->memberLevelService->getName($levelId);
            }
            $expire = trim((string) ($profile['member_level_expire_at'] ?? ''));
            if ($expire !== '' && $expire !== '0000-00-00 00:00:00') {
                $parts[] = '到期 ' . $expire;
            }
        }

        return [
            'member_recharge_status_line' => implode(' · ', $parts),
            'member_balance_text'         => MoneyMath::formatPlain($balance),
            'member_points_text'          => (string) $points,
        ];
    }

    /** @return array<string, mixed> */
    public function rechargeMemberSnapshot(int $userId): array
    {
        return $this->rechargeMemberStatus($userId);
    }

    /** @return array{member_expire_notice_show:int,member_expire_notice_msg:string} */
    public function levelExpireNotice(int $userId): array
    {
        $profile = $this->member()->profile($userId);
        if (!is_array($profile)) {
            return ['member_expire_notice_show' => 0, 'member_expire_notice_msg' => ''];
        }
        $expireStr = trim((string) ($profile['member_level_expire_at'] ?? ''));
        if ($expireStr === '' || $expireStr === '0000-00-00 00:00:00') {
            return ['member_expire_notice_show' => 0, 'member_expire_notice_msg' => ''];
        }
        $expireTs = strtotime($expireStr);
        if ($expireTs === false) {
            return ['member_expire_notice_show' => 0, 'member_expire_notice_msg' => ''];
        }
        $daysLeft = (int) floor(($expireTs - time()) / 86400);
        if ($daysLeft > 7) {
            return ['member_expire_notice_show' => 0, 'member_expire_notice_msg' => ''];
        }
        $levelName = '';
        $levelId   = (int) ($profile['member_level_id'] ?? 0);
        if ($levelId > 0) {
            $levelName = $this->memberLevelService->getName($levelId);
        }
        if ($daysLeft < 0) {
            $msg = ($levelName !== '' ? "您的 {$levelName} " : '会员') . '已过期，续费后可恢复权益。';
        } else {
            $msg = ($levelName !== '' ? "您的 {$levelName} " : '会员') . "将在 {$daysLeft} 天后到期，建议提前续费。";
        }

        return ['member_expire_notice_show' => 1, 'member_expire_notice_msg' => $msg];
    }

    public function rechargeSuccessMessage(int $userId, string $orderNo = ''): string
    {
        $line = $this->rechargeMemberStatus($userId)['member_recharge_status_line'] ?? '';

        return $orderNo !== ''
            ? "支付成功（{$orderNo}）。当前：{$line}"
            : "支付成功。当前：{$line}";
    }

    public function rechargeReturnUrl(string $orderNo): string
    {
        $q = 'recharged=1';
        if ($orderNo !== '') {
            $q .= '&order_no=' . rawurlencode($orderNo);
        }

        return SiteUrl::memberRecharge() . '?' . $q;
    }
}
