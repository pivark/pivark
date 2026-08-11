<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

use app\common\service\auth\CaptchaService;
use app\common\service\front\FrontAuthService;
use app\common\service\front\FrontCsrfService;
use app\common\service\member\MemberBalanceService;
use app\common\service\member\MemberCenterPageRegistry;
use app\common\service\member\MemberConfigService;
use app\common\service\member\MemberLevelService;
use app\common\service\member\MemberPointService;
use app\common\service\member\MemberService;
use app\common\service\site\SiteNavService;
use app\common\service\theme\ThemeService;
use app\common\support\MoneyMath;
use app\common\support\OpsLog;
use app\common\support\SiteUrl;

/** 前台会员 / 门户相关模板变量（从 TemplateSiteVars 拆出） */
final class MemberFrontSiteVars
{
    /** @return array<string, mixed> */
    public function guestLite(): array
    {
        return array_merge(app(ThemeService::class)->memberTemplateAssetVars(), [
            'front_member'                   => [],
            'front_member_logged_in'         => 0,
            'front_member_nickname'          => '',
            'member_login_url'               => SiteUrl::memberLogin(),
            'member_register_url'            => SiteUrl::memberRegister(),
            'member_center_url'              => SiteUrl::frontAccountHub(),
            'member_logout_url'              => SiteUrl::memberLogout(),
            'member_login_post_url'          => SiteUrl::memberApi('login'),
            'member_register_post_url'       => SiteUrl::memberApi('register'),
            'member_forgot_password_post_url'=> SiteUrl::memberApi('forgot-password'),
            'member_forgot_username_post_url'=> SiteUrl::memberApi('forgot-username'),
            'member_reset_password_post_url' => SiteUrl::memberApi('reset-password'),
            'member_document_save_url'       => SiteUrl::memberApi('document/save'),
            'member_password_url'            => SiteUrl::memberApi('password'),
            'member_cancel_url'              => SiteUrl::memberApi('cancel'),
            'member_profile_url'             => SiteUrl::memberApi('profile'),
            'member_recharge_purchase_url'   => SiteUrl::memberApi('recharge/balance'),
            'member_forgot_url'              => SiteUrl::memberForgotPassword(),
            'member_forgot_username_url'     => SiteUrl::memberForgotUsername(),
            'member_downloads_url'           => SiteUrl::memberDownloads(),
            'member_purchases_url'           => SiteUrl::memberPurchases(),
            'member_points_url'              => SiteUrl::memberPoints(),
            'member_balance_url'             => SiteUrl::memberBalance(),
            'member_security_url'            => SiteUrl::memberSecurity(),
            'member_recharge_url'            => SiteUrl::memberRecharge(),
            'member_document_publish_open'   => 0,
            'member_points_enabled'          => 0,
            'member_points_name'             => app(MemberConfigService::class)->pointsLabel(),
            'member_center_open'             => app(MemberConfigService::class)->isCenterOpen() ? 1 : 0,
            'front_csrf_token'               => app(FrontCsrfService::class)->token(),
            'front_csrf_field'               => app(FrontCsrfService::class)->fieldName(),
            'portal_member_header_on'        => 0,
            'portal_member_avatar_empty'     => 1,
            'portal_member_display_name'     => '',
            'portal_member_username'         => '',
            'portal_member_avatar'           => '',
            'portal_member_avatar_letter'    => '',
            'portal_member_level_name'       => '',
            'portal_member_points_text'      => '0',
            'portal_member_balance_text'     => '0.00',
        ], app(MemberCenterPageRegistry::class)->templateMemberUrlVars());
    }

    /** @return array<string, mixed> */
    public function loggedIn(): array
    {
        $member = app(FrontAuthService::class)->current();
        $loggedIn = $member !== null;

        return array_merge(app(ThemeService::class)->memberTemplateAssetVars(), [
            'front_member'              => $member ?? [],
            'front_member_logged_in'    => $loggedIn ? 1 : 0,
            'front_member_nickname'     => $loggedIn ? (string) ($member['nickname'] ?? '') : '',
            'member_login_url'          => SiteUrl::memberLogin(),
            'member_register_url'       => SiteUrl::memberRegister(),
            'member_center_url'         => SiteUrl::frontAccountHub(),
            'member_logout_url'         => SiteUrl::memberLogout(),
            'member_login_post_url'     => SiteUrl::memberApi('login'),
            'member_register_post_url'  => SiteUrl::memberApi('register'),
            'member_forgot_password_post_url' => SiteUrl::memberApi('forgot-password'),
            'member_forgot_username_post_url' => SiteUrl::memberApi('forgot-username'),
            'member_reset_password_post_url'  => SiteUrl::memberApi('reset-password'),
            'member_document_save_url'  => SiteUrl::memberApi('document/save'),
            'member_password_url'       => SiteUrl::memberApi('password'),
            'member_cancel_url'         => SiteUrl::memberApi('cancel'),
            'member_profile_url'        => SiteUrl::memberApi('profile'),
            'member_recharge_purchase_url' => SiteUrl::memberApi('recharge/balance'),
            'member_forgot_url'         => SiteUrl::memberForgotPassword(),
            'member_forgot_username_url'=> SiteUrl::memberForgotUsername(),
            'member_downloads_url'      => SiteUrl::memberDownloads(),
            'member_purchases_url'      => SiteUrl::memberPurchases(),
            'member_points_url'         => SiteUrl::memberPoints(),
            'member_balance_url'        => SiteUrl::memberBalance(),
            'member_security_url'       => SiteUrl::memberSecurity(),
            'member_recharge_url'       => SiteUrl::memberRecharge(),
            'member_document_publish_open' => app(MemberConfigService::class)->isDocumentPublishOpen() ? 1 : 0,
            'member_category_options'   => app(SiteNavService::class)->listContentCategoryOptionsForPublish(),
            'member_points_enabled'     => app(MemberConfigService::class)->isPointsEnabled() ? 1 : 0,
            'member_points_name'        => app(MemberConfigService::class)->pointsLabel(),
            'member_center_open'        => app(MemberConfigService::class)->isCenterOpen() ? 1 : 0,
            'front_csrf_token'          => app(FrontCsrfService::class)->token(),
            'front_csrf_field'          => app(FrontCsrfService::class)->fieldName(),
        ], app(MemberCenterPageRegistry::class)->templateMemberUrlVars(), $this->portalMemberHeaderVars());
    }

    /** @return array<string, mixed> */
    public function portalAuthLite(): array
    {
        $captcha = app(CaptchaService::class)->forScene('home');

        return [
            'home_captcha_on'        => $captcha->isEnabled() ? 1 : 0,
            'home_captcha_url'       => $captcha->imageUrl(),
            'member_forgot_open'     => 0,
            'portal_social_login_on' => 0,
        ];
    }

    /** @return array<string, mixed> */
    public function portalAuth(): array
    {
        $captcha = app(CaptchaService::class)->forScene('home');

        return [
            'home_captcha_on'  => $captcha->isEnabled() ? 1 : 0,
            'home_captcha_url' => $captcha->imageUrl(),
            'member_forgot_open' => (app(MemberConfigService::class)->isPasswordRecoverViaEmail()
                && app(\app\common\service\mail\MailService::class)->isConfigured()) ? 1 : 0,
            'member_forgot_url' => SiteUrl::memberForgotPassword(),
            'member_forgot_username_url' => SiteUrl::memberForgotUsername(),
            'portal_social_login_on' => $this->portalSocialLoginEnabled(),
        ];
    }

    /** @return array<string, mixed> */
    private function portalMemberHeaderVars(): array
    {
        $empty = [
            'portal_member_header_on'      => 0,
            'portal_member_avatar_empty'   => 1,
            'portal_member_display_name'   => '',
            'portal_member_username'       => '',
            'portal_member_avatar'         => '',
            'portal_member_avatar_letter'  => '',
            'portal_member_level_name'     => '',
            'portal_member_points_text'    => '0',
            'portal_member_balance_text'   => '0.00',
        ];
        if (!app(MemberConfigService::class)->isCenterOpen()) {
            return $empty;
        }
        $member = app(FrontAuthService::class)->current();
        if ($member === null) {
            return $empty;
        }
        $profile = app(MemberService::class)->profile((int) ($member['id'] ?? 0));
        if ($profile === null) {
            return $empty;
        }

        $userId   = (int) ($member['id'] ?? 0);
        $nick     = trim((string) ($profile['nickname'] ?? ''));
        $username = trim((string) ($profile['username'] ?? ''));
        $display  = $nick !== '' ? $nick : ($username !== '' ? $username : '会员');
        $avatar   = trim((string) ($profile['avatar'] ?? ''));
        $levelId  = (int) ($profile['member_level_id'] ?? $member['member_level_id'] ?? 0);
        $level    = $levelId > 0 ? app(MemberLevelService::class)->findAdmin($levelId) : null;
        $levelName = trim((string) ($level['name'] ?? ''));
        if ($levelName === '') {
            $levelName = '普通会员';
        }

        return [
            'portal_member_header_on'      => 1,
            'portal_member_avatar_empty'   => $avatar === '' ? 1 : 0,
            'portal_member_display_name'   => $display,
            'portal_member_username'       => $username,
            'portal_member_avatar'         => $avatar,
            'portal_member_avatar_letter'  => mb_strtoupper(mb_substr($display, 0, 1)),
            'portal_member_level_name'     => $levelName,
            'portal_member_points_text'    => (string) app(MemberPointService::class)->balance($userId),
            'portal_member_balance_text'   => MoneyMath::formatPlain(app(MemberBalanceService::class)->balance($userId)),
        ];
    }

    private function portalSocialLoginEnabled(): int
    {
        try {
            return app(\app\common\service\auth\SocialAuthService::class)->getEnabledProviders() !== [] ? 1 : 0;
        } catch (\Throwable $e) {
            OpsLog::businessWarning('member_front_site_vars_degrade', [
                'context' => 'social_auth_providers',
                'msg'     => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
