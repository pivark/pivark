<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\home\controller;

use app\common\service\plugin\extension\HostRuntimeProbe;

use app\common\support\AdminApiResponse;
use app\common\support\ServiceResult;
use app\common\service\document\satellite\DocumentPaymentService;
use app\common\service\infra\BreadcrumbService;
use app\common\service\auth\CaptchaService;
use app\common\service\config\ConfigService;
use app\common\service\document\DocumentAdminService;
use app\common\service\plugin\extension\DocumentAddonBridgeAccess;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\boot\PluginBootService;
use app\common\service\front\FrontAuthService;
use app\common\service\front\FrontCsrfService;
use app\common\service\member\MemberAccountKind;
use app\common\service\member\MemberEnterpriseProfileService;
use app\common\service\member\MemberBalanceService;
use app\common\service\member\MemberCancelService;
use app\common\service\member\MemberCenterPageRegistry;
use app\common\service\member\MemberConfigService;
use app\common\service\member\MemberConsumptionService;
use app\common\service\member\MemberFieldService;
use app\common\service\member\MemberLevelService;
use app\common\service\member\MemberOrderService;
use app\common\service\member\MemberPointGiftService;
use app\common\service\member\MemberPointService;
use app\common\service\member\MemberRegisterVerifyService;
use app\common\service\member\MemberUxService;
use app\common\service\member\MemberPluginNavService;
use app\common\service\member\MemberSidebarNavService;
use app\common\service\member\MemberRechargeService;
use app\common\service\payment\PaymentConfigService;
use app\common\service\member\MemberService;
use app\common\service\member\MemberViewAsService;
use app\common\service\user\PasswordResetService;
use app\common\service\plugin\extension\PluginDocumentSaveService;
use app\common\service\auth\SocialAuthService;
use app\common\service\upload\UploadService;
use app\common\support\SiteUrl;
use think\facade\Request;
use think\Response;

class Member extends Base
{
    /**
     * @return list<array{identifier:string,label:string,url:string,route:string,icon:string,is_active:int}>
     */
    private function pluginNavFor(string $navActive = ''): array
    {
        $list = app(MemberPluginNavService::class)->listForMemberCenter();
        foreach ($list as &$row) {
            $route            = trim((string) ($row['route'] ?? ''));
            $row['is_active'] = ($navActive !== '' && $navActive === $route) ? 1 : 0;
        }
        unset($row);

        return $list;
    }

    private function redirectMemberCenterUnavailable(string $featureLabel): Response
    {
        $msg = '「' . $featureLabel . '」功能未开通，如有疑问请联系站点管理员';

        return redirect(SiteUrl::memberCenter() . '?msg=' . rawurlencode($msg));
    }

    private function dispatchPluginMemberPage(string $identifier, string $action): Response
    {
        $member = $this->requireMember();
        if ($member instanceof Response) {
            return $member;
        }
        app(PluginBootService::class)->bootstrapEnabled();
        $host = [
            'member' => $member,
            'render' => fn (
                string $template,
                string $navActive,
                string $pageTitle,
                string $breadcrumbScene,
                array $vars = [],
            ): Response => $this->renderMemberPage($member, $template, $navActive, $pageTitle, $breadcrumbScene, $vars),
            'redirect_unavailable' => fn (string $label): Response => $this->redirectMemberCenterUnavailable($label),
        ];
        $result = app(MemberCenterPageRegistry::class)->dispatch($identifier, $action, $host);
        if ($result instanceof Response) {
            return $result;
        }

        return $this->redirectMemberCenterUnavailable('该功能');
    }

    /** 插件会员页（GET · MemberCenterPageRegistry + host 账户路由表） */
    public function pluginMemberPage(): Response
    {
        $path = strtolower(ltrim(str_replace('\\', '/', (string) Request::pathinfo()), '/'));
        // 须先 boot：Registry 由插件 boot 填充；先 resolve 再 boot 会导致永远 miss、回落平行壳
        app(PluginBootService::class)->bootstrapEnabled();
        $resolved = app(MemberCenterPageRegistry::class)->resolveHttpRoute('get', $path);
        if ($resolved !== null) {
            return $this->dispatchPluginMemberPage($resolved['identifier'], $resolved['action']);
        }

        $hostRoute = app(MemberCenterPageRegistry::class)->dispatchHostMemberAccountRoute('get', $path);
        if ($hostRoute instanceof Response) {
            return $hostRoute;
        }

        return $this->error('页面不存在');
    }

    /** 插件会员页（POST · MemberCenterPageRegistry + host 账户路由表） */
    public function pluginMemberPagePost(): Response
    {
        $path = strtolower(ltrim(str_replace('\\', '/', (string) Request::pathinfo()), '/'));
        app(PluginBootService::class)->bootstrapEnabled();
        $resolved = app(MemberCenterPageRegistry::class)->resolveHttpRoute('post', $path);
        if ($resolved !== null) {
            return $this->dispatchPluginMemberPage($resolved['identifier'], $resolved['action']);
        }

        $hostRoute = app(MemberCenterPageRegistry::class)->dispatchHostMemberAccountRoute('post', $path);
        if ($hostRoute instanceof Response) {
            return $hostRoute;
        }

        return $this->error('页面不存在');
    }

    /** 会员中心关闭时的友好页（禁止冒充「页面不存在/已移除」） */
    private function respondMemberCenterClosed(): Response
    {
        return $this->error('会员功能暂未开放，如需开通请联系站点管理员', SiteUrl::home(), 403);
    }

    /**
     * @return array<string, mixed>|null 已登录会员；未登录或中心关闭时返回 Response
     */
    private function requireMember(): array|Response
    {
        if (!app(MemberConfigService::class)->isCenterOpen()) {
            return $this->respondMemberCenterClosed();
        }
        $member = app(FrontAuthService::class)->current();
        if ($member === null) {
            return redirect(SiteUrl::memberLogin((string) Request::url(true)));
        }

        return $member;
    }

    /**
     * @param array<string, mixed> $member
     * @return array<string, mixed>
     */
    private function enrichProfile(array $member): array
    {
        $profile = app(MemberService::class)->profile((int) $member['id']);
        if ($profile === null) {
            app(FrontAuthService::class)->logout();

            return [];
        }
        $levelId = (int) ($profile['member_level_id'] ?? 0);
        $level   = $levelId > 0 ? app(MemberLevelService::class)->findAdmin($levelId) : null;
        $profile['member_level_name']  = (string) ($level['name'] ?? '普通会员');
        $profile['member_balance']     = app(MemberBalanceService::class)->balance((int) $member['id']);
        $profile['member_balance_text'] = number_format((float) $profile['member_balance'], 2);
        $expireAt                      = $profile['member_level_expire_at'] ?? null;
        $expireStr                     = is_string($expireAt) && $expireAt !== '' ? $expireAt : '';
        $daysLeft                      = app(MemberService::class)->levelDaysLeft($expireStr !== '' ? $expireStr : null);
        $profile['member_level_expire_text'] = $expireStr !== ''
            ? substr($expireStr, 0, 10) . ' 到期'
            : '';
        $profile['member_level_days_left']   = $daysLeft;
        $profile['member_level_has_expire']  = $expireStr !== '' ? 1 : 0;
        $profile['member_growth']            = (int) ($profile['member_growth'] ?? 0);
        $profile['member_growth_text']       = (string) $profile['member_growth'];
        $profile['member_signin_today']      = app(MemberPointGiftService::class)->hasSigninToday((int) ($member['id'] ?? 0)) ? 1 : 0;
        $kind = MemberAccountKind::normalize($profile['account_kind'] ?? MemberAccountKind::PERSONAL);
        $profile['account_kind']       = $kind;
        $profile['account_kind_label'] = MemberAccountKind::label($kind);
        $profile['is_enterprise']      = $kind === MemberAccountKind::ENTERPRISE ? 1 : 0;
        $ent = app(MemberEnterpriseProfileService::class)->findByUserId((int) $member['id']);
        $profile['enterprise'] = $ent ?? [
            'company_name'  => '',
            'contact_name'  => '',
            'contact_phone' => '',
            'usci'          => '',
            'job_title'     => '',
            'company_email' => '',
        ];

        return $profile;
    }

    private function navItemClass(string $navActive, string $key): string
    {
        return $navActive === $key ? ' active' : '';
    }

    private function consumptionBizFilterHtml(string $bizType, string $pointsName): string
    {
        $options = app(MemberConsumptionService::class)->bizFilterOptions($pointsName);
        $html = '<select name="biz_type" class="form-select form-select-sm">';
        foreach ($options as $value => $label) {
            $sel   = $bizType === $value ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $html .= '</select>';

        return $html;
    }

    /** @return array<string, mixed> */
    private function memberPaymentShellVars(): array
    {
        $payCfg = app(PaymentConfigService::class);

        return [
            'member_payment_enabled'          => app(DocumentPaymentService::class)->enabled() ? 1 : 0,
            'member_payment_demo'             => $payCfg->isDemoMode() ? 1 : 0,
            'member_pay_wechat_visible'       => $payCfg->frontWechatVisible() ? 1 : 0,
            'member_pay_alipay_visible'       => $payCfg->frontAlipayVisible() ? 1 : 0,
            'member_payment_online'           => $payCfg->frontOnlineChannels() !== [] ? 1 : 0,
            'member_payment_channels'         => $payCfg->frontOnlineChannels(),
            'member_pay_channel_options'      => $payCfg->frontPayChannelOptionsFor('member_recharge'),
            'member_pay_channel_custom_options' => $payCfg->frontPayChannelOptionsFor('member_custom'),
            'member_pay_channels_fingerprint' => implode(',', $payCfg->frontOnlineChannels()),
            'member_pay_hint'                 => $payCfg->frontOnlinePayHint(),
            'member_pay_create_url'           => SiteUrl::memberApi('pay/create'),
        ];
    }

    /**
     * @param array<string, mixed> $member
     * @return array<string, mixed>
     */
    private function memberShellVars(array $member, string $navActive): array
    {
        $userId      = (int) $member['id'];
        $points      = app(MemberPointService::class)->balance($userId);
        $balance     = app(MemberBalanceService::class)->balance($userId);
        $nickname    = trim((string) ($member['nickname'] ?? ''));
        $displayName = $nickname !== '' ? $nickname : (string) ($member['username'] ?? '会员');
        $pluginNav   = $this->pluginNavFor($navActive);
        $docPublishOpen = app(MemberConfigService::class)->isDocumentPublishOpen() ? 1 : 0;
        $memberCenter = app(MemberCenterPageRegistry::class);
        if ($memberCenter->hostHideDocumentPublish()) {
            $docPublishOpen = 0;
        }
        $homeNavKey = $memberCenter->hostHomePath();
        if ($homeNavKey === '') {
            $homeNavKey = 'center';
        }
        $pluginNavGroupLabel = $memberCenter->hostPluginNavGroupLabel();
        $navCtx      = [
            'document_publish_open' => $docPublishOpen,
            'plugin_nav_group_label' => $pluginNavGroupLabel,
            'points_enabled'        => app(MemberConfigService::class)->isPointsEnabled() ? 1 : 0,
            'points_label'          => app(MemberConfigService::class)->pointsLabel(),
            'document_create_url'   => SiteUrl::memberDocumentCreate(),
            'documents_url'         => SiteUrl::memberDocuments(),
            'points_url'            => SiteUrl::memberPoints(),
            'balance_url'           => SiteUrl::memberBalance(),
            'recharge_url'          => SiteUrl::memberRecharge(),
            'purchases_url'         => SiteUrl::memberPurchases(),
            'consumption_url'       => SiteUrl::memberConsumption(),
            'security_url'          => SiteUrl::memberSecurity(),
            'profile_page_url'      => SiteUrl::memberProfilePage(),
        ];

        return [
            'member_nav_active'        => $navActive,
            'member_nav_class_center'      => $this->navItemClass($navActive, $homeNavKey),
            'member_nav_groups'            => app(MemberSidebarNavService::class)->groups($navActive, $navCtx, $pluginNav),
            'member_plugin_nav'            => $pluginNav,
            'member_document_publish_open' => $docPublishOpen,
            'member_documents_url'     => SiteUrl::memberDocuments(),
            'member_document_create_url' => SiteUrl::memberDocumentCreate(),
            'member_display_name'      => $displayName,
            'member_username_text'     => (string) ($member['username'] ?? ''),
            'member_points'            => $points,
            'member_points_text'       => (string) $points,
            'member_points_enabled'    => app(MemberConfigService::class)->isPointsEnabled(),
            'member_points_name'       => app(MemberConfigService::class)->pointsLabel(),
            'member_balance'           => $balance,
            'member_balance_text'      => number_format($balance, 2),
            'member_cancel_open'       => app(MemberConfigService::class)->isCancelOpen(),
            'member_center_url'        => SiteUrl::memberCenter(),
            'member_downloads_url'     => SiteUrl::memberDownloads(),
            'member_points_url'        => SiteUrl::memberPoints(),
            'member_purchases_url'     => SiteUrl::memberPurchases(),
            'member_consumption_url'   => SiteUrl::memberConsumption(),
            'member_balance_url'       => SiteUrl::memberBalance(),
            'member_recharge_url'      => SiteUrl::memberRecharge(),
            'member_security_url'      => SiteUrl::memberSecurity(),
            'member_profile_page_url'  => SiteUrl::memberProfilePage(),
            'member_avatar_upload_url' => SiteUrl::memberApi('upload/image'),
            'member_avatar_letter'     => mb_strtoupper(mb_substr($displayName, 0, 1)),
            'front_csrf_token'         => app(FrontCsrfService::class)->token(),
            'member_signin_enabled'    => app(MemberConfigService::class)->isSigninGiftEnabled() ? 1 : 0,
            'member_signin_url'        => SiteUrl::memberApi('signin'),
            'member_flash_msg'         => trim((string) Request::get('msg', '')),
        ];
    }

    /**
     * @param array<string, mixed> $member
     * @param array<string, mixed> $vars
     */
    private function renderMemberPage(
        array $member,
        string $template,
        string $navActive,
        string $pageTitle,
        string $breadcrumbScene,
        array $vars = [],
    ): Response {
        $profile = $this->enrichProfile($member);
        if ($profile === []) {
            return redirect(SiteUrl::memberLogin());
        }
        $siteCfg  = app(ConfigService::class)->getAll();
        $page     = max(1, (int) ($vars['ledger_page'] ?? Request::get('page', 1)));
        $pages    = max(1, (int) ($vars['ledger_pages'] ?? 1));
        $nick     = trim((string) ($profile['nickname'] ?? ''));
        $username = trim((string) ($profile['username'] ?? ''));
        $shell    = $this->memberShellVars($member, $navActive);
        $shell['member_display_name']  = $nick !== '' ? $nick : ($username !== '' ? $username : '会员');
        $shell['member_username_text'] = $username;
        $userId                        = (int) ($member['id'] ?? 0);
        $uxCommon                      = array_merge(
            app(MemberUxService::class)->rechargeMemberStatus($userId),
            app(MemberUxService::class)->levelExpireNotice($userId),
            $this->memberPaymentShellVars(),
        );

        return $this->render($template, array_merge(
            $shell,
            $uxCommon,
            [
                'page_title'       => $pageTitle,
                'seo_title'        => $pageTitle,
                'seo_keywords'     => (string) ($siteCfg['site_keywords'] ?? ''),
                'seo_description'  => (string) ($siteCfg['site_description'] ?? ''),
                'breadcrumbs'      => app(BreadcrumbService::class)->forMember($breadcrumbScene, $pageTitle),
                'member_profile'   => $profile,
                'ledger_page_prev' => max(1, $page - 1),
                'ledger_page_next' => min($pages, $page + 1),
                'ledger_has_prev'   => $page > 1 ? 1 : 0,
                'ledger_has_next'   => $page < $pages ? 1 : 0,
                'ledger_show_pager' => $pages > 1 ? 1 : 0,
            ],
            $vars,
        ));
    }

    /** GET /member — 已登录进中心，否则进登录（completeMatch，避免裸路径 404） */
    public function entry(): Response
    {
        if (!app(MemberConfigService::class)->isCenterOpen()) {
            return $this->respondMemberCenterClosed();
        }
        if (app(FrontAuthService::class)->isLoggedIn()) {
            return redirect(SiteUrl::memberCenter());
        }

        return redirect(SiteUrl::memberLogin());
    }

    public function login()
    {
        if (!app(MemberConfigService::class)->isCenterOpen()) {
            return $this->respondMemberCenterClosed();
        }
        if (app(FrontAuthService::class)->isLoggedIn()) {
            return redirect(SiteUrl::memberCenter());
        }

        $redirect = trim((string) Request::get('redirect', ''));
        $siteCfg  = app(ConfigService::class)->getAll();
        $captcha  = app(CaptchaService::class)->forScene('home');

        return $this->render('member/login', [
            'page_title'        => '会员登录',
            'seo_title'         => '会员登录',
            'seo_keywords'      => (string) ($siteCfg['site_keywords'] ?? ''),
            'seo_description'   => (string) ($siteCfg['site_description'] ?? ''),
            'breadcrumbs'       => app(BreadcrumbService::class)->forMember('login'),
            'member_redirect'   => $redirect,
            'member_register_url' => SiteUrl::memberRegister($redirect),
            'front_csrf_token'  => app(FrontCsrfService::class)->token(),
            'social_providers'  => app(MemberConfigService::class)->isMobileQuickLoginEnabled()
                ? app(SocialAuthService::class)->getEnabledProviders()
                : [],
            'home_captcha_on'   => $captcha->isEnabled() ? 1 : 0,
            'home_captcha_url'  => $captcha->imageUrl(),
            'member_forgot_open' => (app(MemberConfigService::class)->isPasswordRecoverViaEmail()
                && app(\app\common\service\mail\MailService::class)->isConfigured()) ? 1 : 0,
            'member_forgot_url'  => SiteUrl::memberForgotPassword(),
            'member_forgot_username_url' => SiteUrl::memberForgotUsername(),
        ]);
    }

    public function forgotPassword()
    {
        if (!app(MemberConfigService::class)->isCenterOpen()) {
            return $this->respondMemberCenterClosed();
        }
        if (!app(MemberConfigService::class)->isPasswordRecoverViaEmail()) {
            return redirect(SiteUrl::memberLogin());
        }
        if (app(FrontAuthService::class)->isLoggedIn()) {
            return redirect(SiteUrl::memberCenter());
        }

        $siteCfg = app(ConfigService::class)->getAll();

        return $this->render('member/forgot_password', [
            'page_title'       => '找回密码',
            'seo_title'        => '找回密码',
            'seo_keywords'     => (string) ($siteCfg['site_keywords'] ?? ''),
            'seo_description'  => (string) ($siteCfg['site_description'] ?? ''),
            'breadcrumbs'      => app(BreadcrumbService::class)->forMember('login'),
            'front_csrf_token' => app(FrontCsrfService::class)->token(),
            'member_login_url' => SiteUrl::memberLogin(),
            'member_forgot_username_url' => SiteUrl::memberForgotUsername(),
        ]);
    }

    public function forgotUsername()
    {
        if (!app(MemberConfigService::class)->isCenterOpen()) {
            return $this->respondMemberCenterClosed();
        }
        if (!app(MemberConfigService::class)->isPasswordRecoverViaEmail()) {
            return redirect(SiteUrl::memberLogin());
        }
        if (app(FrontAuthService::class)->isLoggedIn()) {
            return redirect(SiteUrl::memberCenter());
        }

        $siteCfg = app(ConfigService::class)->getAll();

        return $this->render('member/forgot_username', [
            'page_title'       => '忘记登录名',
            'seo_title'        => '忘记登录名',
            'seo_keywords'     => (string) ($siteCfg['site_keywords'] ?? ''),
            'seo_description'  => (string) ($siteCfg['site_description'] ?? ''),
            'breadcrumbs'      => app(BreadcrumbService::class)->forMember('login'),
            'front_csrf_token' => app(FrontCsrfService::class)->token(),
            'member_login_url' => SiteUrl::memberLogin(),
            'member_forgot_url' => SiteUrl::memberForgotPassword(),
        ]);
    }

    public function doForgotPassword(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误', 405);
        }
        if (!app(MemberConfigService::class)->isPasswordRecoverViaEmail()) {
            return AdminApiResponse::fail('暂未开放邮件找回密码');
        }

        $token = (string) Request::post(app(FrontCsrfService::class)->fieldName(), '');
        if (!app(FrontCsrfService::class)->validateRequest($token, Request::header('X-CSRF-Token'))) {
            return AdminApiResponse::fail('表单已过期，请刷新后重试');
        }

        $result = app(PasswordResetService::class)->requestByEmail(
            (string) Request::post('email', ''),
            (string) Request::ip()
        );
        if ($result->isOk()) {
            app(FrontCsrfService::class)->rotateAfterSuccess();
        }

        return AdminApiResponse::admin($result);
    }

    public function doForgotUsername(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误', 405);
        }
        if (!app(MemberConfigService::class)->isPasswordRecoverViaEmail()) {
            return AdminApiResponse::fail('暂未开放邮件找回登录名');
        }

        $token = (string) Request::post(app(FrontCsrfService::class)->fieldName(), '');
        if (!app(FrontCsrfService::class)->validateRequest($token, Request::header('X-CSRF-Token'))) {
            return AdminApiResponse::fail('表单已过期，请刷新后重试');
        }

        $result = app(PasswordResetService::class)->remindUsernameByEmail(
            (string) Request::post('email', ''),
            (string) Request::ip()
        );
        if ($result->isOk()) {
            app(FrontCsrfService::class)->rotateAfterSuccess();
        }

        return AdminApiResponse::admin($result);
    }

    public function resetPassword()
    {
        if (!app(MemberConfigService::class)->isPasswordRecoverViaEmail()) {
            return redirect(SiteUrl::memberLogin());
        }
        if (app(FrontAuthService::class)->isLoggedIn()) {
            return redirect(SiteUrl::memberCenter());
        }

        $token = trim((string) Request::get('token', ''));
        $siteCfg = app(ConfigService::class)->getAll();

        return $this->render('member/reset_password', [
            'page_title'       => '重置密码',
            'seo_title'        => '重置密码',
            'seo_keywords'     => (string) ($siteCfg['site_keywords'] ?? ''),
            'seo_description'  => (string) ($siteCfg['site_description'] ?? ''),
            'breadcrumbs'      => app(BreadcrumbService::class)->forMember('login'),
            'front_csrf_token' => app(FrontCsrfService::class)->token(),
            'reset_token'      => $token,
            'member_login_url' => SiteUrl::memberLogin(),
        ]);
    }

    public function doResetPassword(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误', 405);
        }
        if (!app(MemberConfigService::class)->isPasswordRecoverViaEmail()) {
            return AdminApiResponse::fail('暂未开放邮件找回密码');
        }

        $token = (string) Request::post(app(FrontCsrfService::class)->fieldName(), '');
        if (!app(FrontCsrfService::class)->validateRequest($token, Request::header('X-CSRF-Token'))) {
            return AdminApiResponse::fail('表单已过期，请刷新后重试');
        }

        $result = app(PasswordResetService::class)->resetWithToken(
            (string) Request::post('reset_token', ''),
            (string) Request::post('password', ''),
            (string) Request::post('password_confirm', '')
        );
        if ($result->isOk()) {
            app(FrontCsrfService::class)->rotateAfterSuccess();
        }

        return AdminApiResponse::admin($result);
    }

    public function doLogin(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误', 405);
        }

        $token = (string) Request::post(app(FrontCsrfService::class)->fieldName(), '');
        if (!app(FrontCsrfService::class)->validateRequest($token, Request::header('X-CSRF-Token'))) {
            return AdminApiResponse::fail('表单已过期，请刷新后重试');
        }

        $capErr = app(CaptchaService::class)->forScene('home')->guardLogin((string) Request::post('captcha', ''));
        if ($capErr !== null) {
            return AdminApiResponse::admin($capErr);
        }

        $result = app(FrontAuthService::class)->login(
            (string) Request::post('username', ''),
            (string) Request::post('password', '')
        );
        if (!$result->isOk()) {
            return AdminApiResponse::admin($result);
        }

        app(FrontCsrfService::class)->rotateAfterSuccess();
        $redirect = app(MemberConfigService::class)->resolveLoginRedirect((string) Request::post('redirect', ''));

        return AdminApiResponse::fromResult(ServiceResult::ok(
            ['msg' => $result->message(), 'redirect' => $redirect],
            $result->message(),
            app(FrontCsrfService::class)->clientMeta(),
        ));
    }

    public function register()
    {
        if (!app(MemberConfigService::class)->isCenterOpen()) {
            return $this->respondMemberCenterClosed();
        }
        if (app(FrontAuthService::class)->isLoggedIn()) {
            return redirect(SiteUrl::memberCenter());
        }

        if (!app(MemberConfigService::class)->isRegisterOpen()) {
            $siteCfg = app(ConfigService::class)->getAll();

            return $this->render('member/register_closed', [
                'page_title'       => '注册暂未开放',
                'seo_title'        => '注册暂未开放',
                'seo_keywords'     => (string) ($siteCfg['site_keywords'] ?? ''),
                'seo_description'  => (string) ($siteCfg['site_description'] ?? ''),
                'breadcrumbs'      => app(BreadcrumbService::class)->forMember('register'),
                'member_login_url' => SiteUrl::memberLogin(),
            ]);
        }

        $redirect = trim((string) Request::get('redirect', ''));
        $siteCfg  = app(ConfigService::class)->getAll();
        $regFields = app(MemberFieldService::class)->listActiveForRegister();
        $kind = MemberAccountKind::normalize(Request::get('kind', Request::get('account_kind', MemberAccountKind::PERSONAL)));
        $enterpriseOpen = app(MemberConfigService::class)->isEnterpriseRegisterOpen();
        if ($kind === MemberAccountKind::ENTERPRISE && !$enterpriseOpen) {
            $kind = MemberAccountKind::PERSONAL;
        }
        $regBase = SiteUrl::memberRegister($redirect);
        $regSep  = str_contains($regBase, '?') ? '&' : '?';

        return $this->render('member/register', [
            'page_title'            => $kind === MemberAccountKind::ENTERPRISE ? '企业注册' : '会员注册',
            'seo_title'             => $kind === MemberAccountKind::ENTERPRISE ? '企业注册' : '会员注册',
            'seo_keywords'          => (string) ($siteCfg['site_keywords'] ?? ''),
            'seo_description'       => (string) ($siteCfg['site_description'] ?? ''),
            'breadcrumbs'           => app(BreadcrumbService::class)->forMember('register'),
            'member_redirect'       => $redirect,
            'member_login_url'      => SiteUrl::memberLogin($redirect),
            'front_csrf_token'      => app(FrontCsrfService::class)->token(),
            'member_fields_html'    => app(MemberFieldService::class)->renderFrontFieldsHtml($regFields),
            'member_register_agreement' => (string) app(MemberConfigService::class)->all()['member_register_agreement'],
            'member_check_username_url' => SiteUrl::memberApi('check-username'),
            'member_account_kind'       => $kind,
            'member_kind_is_personal'   => $kind === MemberAccountKind::PERSONAL ? 1 : 0,
            'member_kind_is_enterprise' => $kind === MemberAccountKind::ENTERPRISE ? 1 : 0,
            'member_enterprise_register_open' => $enterpriseOpen ? 1 : 0,
            'member_register_personal_url'    => $regBase . $regSep . 'kind=personal',
            'member_register_enterprise_url'  => $regBase . $regSep . 'kind=enterprise',
            'member_forgot_open' => (app(MemberConfigService::class)->isPasswordRecoverViaEmail()
                && app(\app\common\service\mail\MailService::class)->isConfigured()) ? 1 : 0,
            'member_forgot_url' => SiteUrl::memberForgotPassword(),
            'member_forgot_username_url' => SiteUrl::memberForgotUsername(),
        ]);
    }

    /** GET /api/v1/member/check-username?username= — 注册页实时占用校验 */
    public function checkUsername(): Response
    {
        $username = trim((string) Request::get('username', Request::param('username', '')));
        $result = app(MemberService::class)->checkUsernameAvailability($username);

        return AdminApiResponse::fromResult($result);
    }

    public function doRegister(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误', 405);
        }

        $token = (string) Request::post(app(FrontCsrfService::class)->fieldName(), '');
        if (!app(FrontCsrfService::class)->validateRequest($token, Request::header('X-CSRF-Token'))) {
            return AdminApiResponse::fail('表单已过期，请刷新后重试');
        }

        $result = app(MemberService::class)->registerPublic(Request::post());
        if (!$result->isOk()) {
            return AdminApiResponse::admin($result);
        }

        $pending = (int) ($result['pending'] ?? 0) === 1;
        $emailPending = (int) ($result['email_pending'] ?? 0) === 1;
        if (!$pending && !$emailPending) {
            $user = \app\common\model\User::find((int) ($result['user_id'] ?? 0));
            if ($user) {
                app(FrontAuthService::class)->establishSession($user);
            }
        }
        app(FrontCsrfService::class)->rotateAfterSuccess();

        $redirect = ($pending || $emailPending)
            ? SiteUrl::memberLogin((string) Request::post('redirect', ''))
            : app(MemberConfigService::class)->resolveLoginRedirect((string) Request::post('redirect', ''));

        return AdminApiResponse::fromResult(ServiceResult::ok(['redirect' => $redirect], (string) ($result->message() ?? '注册成功')));
    }

    public function logoutForm(): Response
    {
        if (!app(FrontAuthService::class)->isLoggedIn()) {
            return redirect(SiteUrl::memberLogin());
        }

        return $this->render('member/logout_confirm', [
            'seo_title'          => '确认退出',
            'front_csrf_token'   => app(FrontCsrfService::class)->token(),
            'front_csrf_field'   => app(FrontCsrfService::class)->fieldName(),
            'member_logout_action' => SiteUrl::memberApi('logout'),
        ]);
    }

    public function doLogout(): Response
    {
        $token = (string) Request::post(app(FrontCsrfService::class)->fieldName(), '');
        if (!app(FrontCsrfService::class)->validateRequest($token, Request::header('X-CSRF-Token'))) {
            return redirect(SiteUrl::memberLogin() . '?msg=' . rawurlencode('请求无效，请重试'));
        }
        app(FrontAuthService::class)->logout();
        app(FrontCsrfService::class)->rotateAfterSuccess();

        return redirect(SiteUrl::home());
    }

    /** 后台签发的一次性令牌：确认页 + POST 消费，防 GET CSRF */
    public function enterAsForm()
    {
        $token = trim((string) Request::get('token', ''));
        if ($token === '') {
            return redirect(SiteUrl::memberLogin());
        }

        return $this->render('member/enter_as_confirm', [
            'seo_title'        => '确认进入会员中心',
            'enter_as_token'   => $token,
            'front_csrf_token' => app(FrontCsrfService::class)->token(),
            'front_csrf_field' => app(FrontCsrfService::class)->fieldName(),
            'enter_as_action'  => SiteUrl::memberApi('enter-as'),
        ]);
    }

    public function doEnterAs()
    {
        $token = trim((string) Request::post('token', ''));
        if ($token === '') {
            return redirect(SiteUrl::memberLogin());
        }
        $csrf = (string) Request::post(app(FrontCsrfService::class)->fieldName(), '');
        if (!app(FrontCsrfService::class)->validateRequest($csrf, Request::header('X-CSRF-Token'))) {
            return redirect(SiteUrl::memberLogin() . '?msg=' . rawurlencode('请求无效，请重试'));
        }

        $result = app(MemberViewAsService::class)->consumeEnterToken($token);
        app(FrontCsrfService::class)->rotateAfterSuccess();
        if (!$result->isOk()) {
            return redirect(SiteUrl::memberLogin() . '?msg=' . rawurlencode((string) ($result->message() ?? '无法进入')));
        }

        return redirect((string) ($result->extra()['redirect'] ?? SiteUrl::memberCenter()));
    }

    public function center()
    {
        $member = $this->requireMember();
        if ($member instanceof Response) {
            return $member;
        }
        // 与 pluginMemberPage 一致：先 boot 插件扩展，否则 host Account 接管永远 miss → 内核个人中心无「我的店铺」
        app(PluginBootService::class)->bootstrapEnabled();
        $handler = \app\common\service\plugin\extension\HostRuntimeProbe::firstActiveHostRuntimeHandler();
        if ($handler !== null) {
            $account = $handler?->memberCenterAccountControllerClass();
            if (is_string($account) && $account !== '') {
                return app($account)->index();
            }
        }
        $hostHome = app(MemberCenterPageRegistry::class)->hostHomePath();
        if ($hostHome !== '' && $hostHome !== 'center') {
            return redirect('/member/' . $hostHome);
        }
        $userId = (int) $member['id'];

        $recommended = app(MemberRechargeService::class)->recommendForUser($userId, 3);

        return $this->renderMemberPage($member, 'member/center', 'center', '个人中心', 'center', [
            'member_recommended_packages'   => $recommended,
            'member_recommended_empty'      => $recommended === [] ? 1 : 0,
            'member_show_asset_bar'         => 1,
        ]);
    }

    /** GET /member/profile — 基本资料 */
    public function profile()
    {
        $member = $this->requireMember();
        if ($member instanceof Response) {
            return $member;
        }
        $userId = (int) $member['id'];
        $profileFields = app(MemberFieldService::class)->listActiveForProfile();
        $fieldValues   = app(MemberFieldService::class)->valuesMapForUser($userId);
        $kind = MemberAccountKind::normalize(
            \app\common\model\User::where('id', $userId)->value('account_kind') ?? MemberAccountKind::PERSONAL
        );
        $enterpriseOpen = app(MemberConfigService::class)->isEnterpriseRegisterOpen();

        return $this->renderMemberPage($member, 'member/profile', 'profile', '基本资料', 'profile', [
            'member_fields_html'    => app(MemberFieldService::class)->renderFrontFieldsHtml($profileFields, $fieldValues),
            'member_show_asset_bar' => 1,
            'member_enterprise_register_open' => $enterpriseOpen ? 1 : 0,
            'member_can_upgrade_enterprise' => ($kind === MemberAccountKind::PERSONAL && $enterpriseOpen) ? 1 : 0,
        ]);
    }

    public function purchases()
    {
        $member = $this->requireMember();
        if ($member instanceof Response) {
            return $member;
        }
        $page   = max(1, (int) Request::get('page', 1));
        $limit  = 20;
        $status = (string) Request::get('status', '');
        $result = app(MemberOrderService::class)->listForUser((int) $member['id'], $page, $limit, $status);
        $statusQuery = $status !== '' ? 'status=' . rawurlencode($status) : '';

        return $this->renderMemberPage($member, 'member/purchases', 'purchases', '我的购买', 'purchases', [
            'purchase_list'       => $result['list'],
            'ledger_total'      => (int) $result['total'],
            'ledger_page'       => $page,
            'ledger_limit'      => $limit,
            'ledger_pages'      => max(1, (int) ceil(((int) $result['total']) / $limit)),
            'ledger_empty'      => ($result['list'] ?? []) === [] ? 1 : 0,
            'purchase_status'   => $status,
            'purchase_status_query' => $statusQuery,
            'member_pay_return_url' => '/member/pay/return',
        ]);
    }

    public function points()
    {
        $member = $this->requireMember();
        if ($member instanceof Response) {
            return $member;
        }
        if (!app(MemberConfigService::class)->isPointsEnabled()) {
            return redirect(SiteUrl::memberCenter());
        }
        $page   = max(1, (int) Request::get('page', 1));
        $limit  = 20;
        $result = app(MemberPointService::class)->listForUser((int) $member['id'], $page, $limit);

        return $this->renderMemberPage($member, 'member/points', 'points', '我的积分', 'points', [
            'member_show_asset_bar' => 1,
            'ledger_list'   => $result['list'],
            'ledger_total'  => (int) $result['total'],
            'ledger_page'   => $page,
            'ledger_limit'  => $limit,
            'ledger_pages'  => max(1, (int) ceil(((int) $result['total']) / $limit)),
            'ledger_empty'  => ($result['list'] ?? []) === [] ? 1 : 0,
        ]);
    }

    public function consumption()
    {
        $member = $this->requireMember();
        if ($member instanceof Response) {
            return $member;
        }
        $page    = max(1, (int) Request::get('page', 1));
        $limit   = 20;
        $bizType = (string) Request::get('biz_type', '');
        $result  = app(MemberConsumptionService::class)->listForUser((int) $member['id'], $page, $limit, $bizType);

        $bizQuery = $bizType !== '' ? 'biz_type=' . rawurlencode($bizType) : '';
        $pointsName = app(MemberConfigService::class)->pointsLabel();

        return $this->renderMemberPage($member, 'member/consumption', 'consumption', '消费记录', 'consumption', [
            'ledger_list'                  => $result['list'],
            'ledger_total'                 => (int) $result['total'],
            'ledger_page'                  => $page,
            'ledger_limit'                 => $limit,
            'ledger_pages'                 => max(1, (int) ceil(((int) $result['total']) / $limit)),
            'ledger_biz_type'              => $bizType,
            'ledger_biz_query'             => $bizQuery,
            'ledger_empty'                 => ($result['list'] ?? []) === [] ? 1 : 0,
            'consumption_biz_filter_html'  => $this->consumptionBizFilterHtml($bizType, $pointsName),
        ]);
    }

    public function balance()
    {
        $member = $this->requireMember();
        if ($member instanceof Response) {
            return $member;
        }
        $page   = max(1, (int) Request::get('page', 1));
        $limit  = 20;
        $result = app(MemberBalanceService::class)->listForUser((int) $member['id'], $page, $limit);

        $balance = app(MemberBalanceService::class)->balance((int) $member['id']);

        return $this->renderMemberPage($member, 'member/balance', 'balance', '余额流水', 'balance', [
            'ledger_list'           => $result['list'],
            'ledger_total'          => (int) $result['total'],
            'ledger_page'           => $page,
            'ledger_limit'          => $limit,
            'ledger_pages'          => max(1, (int) ceil(((int) $result['total']) / $limit)),
            'ledger_empty'          => ($result['list'] ?? []) === [] ? 1 : 0,
            'member_balance_low_show' => $balance < 10 ? 1 : 0,
            'member_show_asset_bar'   => 1,
        ]);
    }

    public function recharge()
    {
        $member = $this->requireMember();
        if ($member instanceof Response) {
            return $member;
        }
        $userId   = (int) $member['id'];
        $packages = app(MemberRechargeService::class)->enrichPublicForUser(
            app(MemberRechargeService::class)->listPublic(),
            $userId
        );
        $grouped    = app(MemberRechargeService::class)->groupPublicByType($packages);
        $customMeta = app(MemberRechargeService::class)->customRechargeMeta();
        $statusVars = app(MemberUxService::class)->rechargeMemberStatus($userId);
        $successMsg = '';
        if (trim((string) Request::get('recharged', '')) === '1') {
            $successMsg = app(MemberUxService::class)->rechargeSuccessMessage(
                $userId,
                trim((string) Request::get('order_no', ''))
            );
        }

        $hasM = $grouped[MemberRechargeService::TYPE_MEMBERSHIP] !== [];
        $hasP = $grouped[MemberRechargeService::TYPE_POINTS] !== [] || $customMeta['points_enabled'] === 1;
        $hasB = true;
        $tabPoints = $hasP ? 1 : 0;
        $tabBalance = 1;
        $tabMembership = $hasM ? 1 : 0;
        $firstTab = $hasM ? 'membership' : ($hasP ? 'points' : 'balance');

        return $this->renderMemberPage($member, 'member/recharge', 'recharge', '充值中心', 'recharge', array_merge($statusVars, [
            'member_show_asset_bar'          => 1,
            'member_recharge_packages'            => $packages,
            'member_recharge_packages_membership' => $grouped[MemberRechargeService::TYPE_MEMBERSHIP],
            'member_recharge_packages_points'     => $grouped[MemberRechargeService::TYPE_POINTS],
            'member_recharge_packages_balance'    => $grouped[MemberRechargeService::TYPE_BALANCE],
            'member_recharge_has_membership'      => $tabMembership,
            'member_recharge_has_points'          => $tabPoints,
            'member_recharge_has_balance'         => $tabBalance,
            'member_recharge_custom_points'       => $customMeta['points_enabled'],
            'member_recharge_custom_balance'      => $customMeta['balance_enabled'],
            'member_recharge_custom_min'          => number_format($customMeta['min'], 2, '.', ''),
            'member_recharge_custom_max'          => number_format($customMeta['max'], 2, '.', ''),
            'member_recharge_points_per_yuan'     => (string) $customMeta['points_per_yuan'],
            'member_recharge_tab_membership_class'  => $firstTab === 'membership' ? ' active' : '',
            'member_recharge_tab_points_class'      => $firstTab === 'points' ? ' active' : '',
            'member_recharge_tab_balance_class'     => $firstTab === 'balance' ? ' active' : '',
            'member_recharge_pane_membership_class' => $firstTab === 'membership' ? ' show active' : '',
            'member_recharge_pane_points_class'     => $firstTab === 'points' ? ' show active' : '',
            'member_recharge_pane_balance_class'    => $firstTab === 'balance' ? ' show active' : '',
            'member_recharge_success_msg'         => $successMsg,
            'member_recharge_success_show'        => $successMsg !== '' ? 1 : 0,
            'member_recharge_packages_empty'      => 0,
            'member_recharge_balance_url' => SiteUrl::memberApi('recharge/balance'),
            'member_pay_create_url'      => SiteUrl::memberApi('pay/create'),
        ]));
    }

    public function payStatus(): Response
    {
        $member = app(FrontAuthService::class)->current();
        if ($member === null) {
            return AdminApiResponse::authExpired('请先登录', SiteUrl::memberLogin());
        }
        $orderNo = trim((string) Request::get('order_no', ''));
        if ($orderNo === '') {
            return AdminApiResponse::fail('缺少订单号');
        }
        if (!app(DocumentPaymentService::class)->enabled()) {
            return AdminApiResponse::fail('在线支付未开启');
        }

        $userId = (int) ($member['id'] ?? 0);
        $order  = app(\app\common\service\payment\PaymentOrderService::class)->findByOrderNo($orderNo);
        if ($order === null || (int) ($order['user_id'] ?? 0) !== $userId) {
            return AdminApiResponse::fail('订单不存在');
        }

        if ((string) ($order['status'] ?? '') !== 'paid') {
            app(\app\common\service\payment\PaymentOrderService::class)->trySyncPaidFromGateway($orderNo, $userId);
            $order = app(\app\common\service\payment\PaymentOrderService::class)->findByOrderNo($orderNo);
        }

        $paid = is_array($order) && (string) ($order['status'] ?? '') === 'paid';
        $reload = '';
        if ($paid) {
            $reload = (is_array($order) && (string) ($order['scene'] ?? '') === \app\common\service\payment\PaymentOrderService::SCENE_RECHARGE)
                ? app(MemberUxService::class)->rechargeReturnUrl($orderNo)
                : '/member/pay/return?order_no=' . rawurlencode($orderNo);
        }

        return AdminApiResponse::fromResult(ServiceResult::ok([
            'paid'     => $paid ? 1 : 0,
            'status'   => is_array($order) ? (string) ($order['status'] ?? '') : '',
            'order_no' => $orderNo,
            'reload'   => $reload,
            'member'   => $paid ? app(MemberUxService::class)->rechargeMemberSnapshot($userId) : null,
        ]));
    }

    public function payCreate(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误', 405);
        }
        $member = app(FrontAuthService::class)->current();
        if ($member === null) {
            return AdminApiResponse::authExpired('请先登录', SiteUrl::memberLogin());
        }
        $token = (string) Request::post(app(FrontCsrfService::class)->fieldName(), '');
        if (!app(FrontCsrfService::class)->validateRequest($token, Request::header('X-CSRF-Token'))) {
            return AdminApiResponse::fail('表单已过期，请刷新后重试');
        }
        $packageId    = (int) Request::post('package_id', 0);
        $customAmount = round((float) Request::post('custom_amount', 0), 2);
        $rechargeType = trim((string) Request::post('recharge_type', ''));
        $raw          = trim((string) Request::post('channel', ''));
        $channel      = app(PaymentConfigService::class)->resolveFrontChannel($raw);
        if ($channel === '' || !app(PaymentConfigService::class)->isFrontChannelAllowed($channel)) {
            $available = app(PaymentConfigService::class)->frontOnlineChannels();

            return AdminApiResponse::fail($available === []
                    ? app(PaymentConfigService::class)->channelUnavailableMessage($raw)
                    : '该支付方式未开启，请刷新页面后重试');
        }
        $payOptions = [
            'custom_amount' => $customAmount,
            'custom_type'   => $rechargeType,
        ];
        if ($channel === PaymentConfigService::CHANNEL_WECHAT) {
            $payOptions = array_merge(
                $payOptions,
                app(MemberRechargeService::class)->buildWechatPayExtras(
                    (int) $member['id'],
                    Request::ip(),
                    (string) Request::header('User-Agent', '')
                )
            );
        }
        $result = app(MemberRechargeService::class)->createPaymentOrder((int) $member['id'], $packageId, $channel, $payOptions);
        if ($result->isOk() && (($result['type'] ?? '') === 'demo' || !empty($result['demo']))) {
            app(FrontCsrfService::class)->rotateAfterSuccess();
        }

        return AdminApiResponse::admin($result);
    }

    public function payReturn()
    {
        $member = $this->requireMember();
        if ($member instanceof Response) {
            return $member;
        }
        $orderNo = trim((string) Request::get('order_no', ''));
        $order   = null;
        if ($orderNo !== '' && app(DocumentPaymentService::class)->enabled()) {
            app(\app\common\service\payment\PaymentOrderService::class)->trySyncPaidFromGateway(
                $orderNo,
                (int) ($member['id'] ?? 0)
            );
            $order = app(\app\common\service\payment\PaymentOrderService::class)->findByOrderNo($orderNo);
        }
        $paid = is_array($order) && (string) ($order['status'] ?? '') === 'paid';

        $watchUrl = '';
        if ($paid && is_array($order)) {
            $watchUrl = DocumentAddonBridgeAccess::watchUrlForPaidOrder(is_array($order) ? $order : null);
        }

        $panel = app(MemberUxService::class)->payReturnPanel(
            $paid,
            $orderNo,
            is_array($order) ? (string) ($order['amount'] ?? '') : '',
            is_array($order) ? $order : null
        );
        if ($watchUrl !== '') {
            $panel['cta_url']   = $watchUrl;
            $panel['cta_label'] = '立即观看';
            $panel['body']      = ($panel['body'] ?? '') . ' 点击按钮进入文档页播放。';
        }

        return $this->renderMemberPage($member, 'member/pay_return', 'recharge', '支付结果', 'recharge', [
            'pay_order_no'        => $orderNo,
            'pay_status_poll_url' => SiteUrl::memberApi('pay/status'),
            'pay_order_paid'      => $paid ? 1 : 0,
            'pay_order_amount'    => is_array($order) ? (string) ($order['amount'] ?? '') : '',
            'pay_watch_url'       => $watchUrl,
            'member_recharge_url' => SiteUrl::memberRecharge(),
            'pay_panel_title'     => $panel['title'],
            'pay_panel_body'      => $panel['body'],
            'pay_panel_cta_url'   => $panel['cta_url'],
            'pay_panel_cta_label' => $panel['cta_label'],
            'pay_auto_redirect'   => (string) ($panel['auto_redirect'] ?? ''),
        ]);
    }

    public function doSignin(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误', 405);
        }
        $member = app(FrontAuthService::class)->current();
        if ($member === null) {
            return AdminApiResponse::authExpired('请先登录', SiteUrl::memberLogin());
        }
        $token = (string) Request::post(app(FrontCsrfService::class)->fieldName(), '');
        if (!app(FrontCsrfService::class)->validateRequest($token, Request::header('X-CSRF-Token'))) {
            return AdminApiResponse::fail('表单已过期，请刷新后重试');
        }
        $result = app(MemberPointGiftService::class)->tryGrantSignin((int) $member['id']);
        if ($result->isOk()) {
            app(FrontCsrfService::class)->rotateAfterSuccess();
            $result['member'] = app(MemberUxService::class)->rechargeMemberStatus((int) $member['id']);
        }

        return AdminApiResponse::admin($result);
    }

    public function verifyEmail()
    {
        if (!app(MemberConfigService::class)->isCenterOpen()) {
            return $this->respondMemberCenterClosed();
        }
        $token  = trim((string) Request::get('token', ''));
        $result = app(MemberRegisterVerifyService::class)->verifyToken($token);
        $siteCfg = app(ConfigService::class)->getAll();
        $ok = $result->isOk();

        return $this->render('member/verify_email', [
            'page_title'       => $ok ? '邮箱验证成功' : '邮箱验证失败',
            'seo_title'        => $ok ? '邮箱验证成功' : '邮箱验证失败',
            'seo_keywords'     => (string) ($siteCfg['site_keywords'] ?? ''),
            'seo_description'  => (string) ($siteCfg['site_description'] ?? ''),
            'verify_ok'        => $ok ? 1 : 0,
            'verify_message'   => (string) ($result->message() ?? ''),
            'member_login_url' => SiteUrl::memberLogin(),
        ]);
    }

    public function purchaseRecharge(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误', 405);
        }
        $member = app(FrontAuthService::class)->current();
        if ($member === null) {
            return AdminApiResponse::authExpired('请先登录', SiteUrl::memberLogin());
        }
        $token = (string) Request::post(app(FrontCsrfService::class)->fieldName(), '');
        if (!app(FrontCsrfService::class)->validateRequest($token, Request::header('X-CSRF-Token'))) {
            return AdminApiResponse::fail('表单已过期，请刷新后重试');
        }
        $packageId    = (int) Request::post('package_id', 0);
        $customAmount = round((float) Request::post('custom_amount', 0), 2);
        $rechargeType = trim((string) Request::post('recharge_type', ''));
        $userId       = (int) $member['id'];
        if ($packageId < 1 && $customAmount > 0 && $rechargeType !== '') {
            $result = app(MemberRechargeService::class)->purchaseCustomWithBalance($userId, $rechargeType, $customAmount);
        } else {
            $result = app(MemberRechargeService::class)->purchaseWithBalance($userId, $packageId);
        }
        if ($result->isOk()) {
            app(FrontCsrfService::class)->rotateAfterSuccess();
            $result['member'] = app(MemberUxService::class)->rechargeMemberSnapshot($userId);
        }

        return AdminApiResponse::admin($result);
    }

    public function security()
    {
        $member = $this->requireMember();
        if ($member instanceof Response) {
            return $member;
        }
        $userId = (int) ($member['id'] ?? 0);
        $oauthRows = app(SocialAuthService::class)->memberSecurityRows($userId);

        return $this->renderMemberPage($member, 'member/security', 'security', '账号安全', 'security', [
            'oauth_security_rows'      => $oauthRows,
            'oauth_security_open'      => $oauthRows !== [] ? 1 : 0,
            'member_oauth_unbind_url'  => SiteUrl::memberApi('oauth/unbind'),
        ]);
    }

    public function oauthUnbind()
    {
        $member = $this->requireMember();
        if ($member instanceof Response) {
            return $member;
        }
        $provider = (string) Request::post('provider', '');
        $result = app(SocialAuthService::class)->unbindForMember((int) ($member['id'] ?? 0), $provider);
        if ($result->isOk()) {
            app(FrontCsrfService::class)->rotateAfterSuccess();
        }

        return AdminApiResponse::admin($result);
    }

    public function documents()
    {
        $member = $this->requireMember();
        if ($member instanceof Response) {
            return $member;
        }
        if (!app(MemberConfigService::class)->isDocumentPublishOpen()) {
            return redirect(SiteUrl::memberCenter());
        }
        $page   = max(1, (int) Request::get('page', 1));
        $limit  = 15;
        $result = app(DocumentAdminService::class)->listForMemberAuthor((int) $member['id'], $page, $limit);

        $createUrl = SiteUrl::memberDocumentCreate();

        return $this->renderMemberPage($member, 'member/documents', 'documents', '我的文章', 'documents', [
            'document_list'  => $result['list'],
            'document_total' => (int) $result['total'],
            'ledger_page'    => $page,
            'ledger_limit'   => $limit,
            'ledger_pages'   => max(1, (int) ceil(((int) $result['total']) / $limit)),
            'ledger_empty'   => ($result['list'] ?? []) === [] ? 1 : 0,
            'member_page_lead' => '新建文章默认保存为草稿，审核发布规则以站点配置为准',
            'member_page_toolbar' => '<a href="' . htmlspecialchars($createUrl, ENT_QUOTES, 'UTF-8')
                . '" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>发布文章</a>',
            'member_empty_icon' => 'bi-file-earmark-text',
            'member_empty_title' => '还没有文章',
            'member_empty_cta_url' => $createUrl,
            'member_empty_cta_label' => '发布第一篇文章',
        ]);
    }

    public function documentCreate()
    {
        $member = $this->requireMember();
        if ($member instanceof Response) {
            return $member;
        }
        if (!app(MemberConfigService::class)->isDocumentPublishOpen()) {
            return redirect(SiteUrl::memberCenter());
        }

        return redirect(SiteUrl::memberDocumentCreate());
    }

    public function documentEdit(int $id = 0)
    {
        $member = $this->requireMember();
        if ($member instanceof Response) {
            return $member;
        }
        if (!app(MemberConfigService::class)->isDocumentPublishOpen()) {
            return redirect(SiteUrl::memberCenter());
        }
        $id = max(0, $id);

        return redirect(SiteUrl::memberDocumentEdit($id));
    }

    public function saveDocument(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误', 405);
        }
        $member = app(FrontAuthService::class)->current();
        if ($member === null) {
            return AdminApiResponse::authExpired('请先登录', SiteUrl::memberLogin());
        }
        if (!app(MemberConfigService::class)->isDocumentPublishOpen()) {
            return AdminApiResponse::fail('会员发文功能未开启');
        }
        $token = (string) Request::post(app(FrontCsrfService::class)->fieldName(), '');
        if (!app(FrontCsrfService::class)->validateRequest($token, Request::header('X-CSRF-Token'))) {
            return AdminApiResponse::fail('表单已过期，请刷新后重试');
        }
        $result = app(DocumentAdminService::class)->saveForMember(Request::post(), (int) $member['id']);
        if ($result->isOk()) {
            $savedId = (int) ($result->dataArray()['id'] ?? (int) Request::post('id', 0));
            if ($savedId > 0) {
                $pluginSync = app(PluginDocumentSaveService::class)->syncAfterSave($savedId, true, Request::post());
                if (!$pluginSync->isOk()) {
                    return AdminApiResponse::admin(ServiceResult::fail((string) ($pluginSync->message() ?: '插件数据保存失败')));
                }
            }
            app(FrontCsrfService::class)->rotateAfterSuccess();
            $result = $result->withExtra(['redirect' => SiteUrl::memberDocuments()]);
        }

        return AdminApiResponse::admin($result);
    }

    public function uploadImage(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误', 405);
        }

        $member = app(FrontAuthService::class)->current();
        if ($member === null) {
            return AdminApiResponse::authExpired('请先登录', SiteUrl::memberLogin());
        }

        $token = (string) Request::post(app(FrontCsrfService::class)->fieldName(), '');
        if (!app(FrontCsrfService::class)->validateRequest($token, Request::header('X-CSRF-Token'))) {
            return AdminApiResponse::fail('表单已过期，请刷新后重试');
        }

        $scene = UploadService::normalizeScene((string) Request::param('scene', 'user'));
        if ($scene !== 'user') {
            return AdminApiResponse::fail('不允许的上传场景');
        }
        if (app(UploadService::class)->sceneMeta($scene) === []) {
            return AdminApiResponse::fail('不允许的上传场景');
        }

        try {
            $result = UploadService::scene($scene)->handle(
                request()->file('file'),
                [
                    'content_hash' => (string) Request::post('content_hash', ''),
                    'file_size'    => (int) Request::post('file_size', 0),
                    'force_upload' => in_array(Request::post('force_upload'), ['1', 'true', true], true),
                ]
            );
            return AdminApiResponse::admin($result);
        } catch (\InvalidArgumentException $e) {
            return AdminApiResponse::fail($e->getMessage());
        }
    }

    public function saveProfile(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误', 405);
        }

        $member = app(FrontAuthService::class)->current();
        if ($member === null) {
            return AdminApiResponse::authExpired('请先登录', SiteUrl::memberLogin());
        }

        $token = (string) Request::post(app(FrontCsrfService::class)->fieldName(), '');
        if (!app(FrontCsrfService::class)->validateRequest($token, Request::header('X-CSRF-Token'))) {
            return AdminApiResponse::fail('表单已过期，请刷新后重试');
        }

        $result = app(MemberService::class)->updateProfile((int) $member['id'], Request::post());
        if ($result->isOk()) {
            $fresh = app(MemberService::class)->profile((int) $member['id']);
            if ($fresh) {
                app(FrontAuthService::class)->establishSession(\app\common\model\User::find((int) $member['id']));
            }
            $result = app(FrontCsrfService::class)->attachRotatedMeta($result);
        }

        return AdminApiResponse::admin($result);
    }

    public function changePassword(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误', 405);
        }

        $member = app(FrontAuthService::class)->current();
        if ($member === null) {
            return AdminApiResponse::authExpired('请先登录', SiteUrl::memberLogin());
        }

        $token = (string) Request::post(app(FrontCsrfService::class)->fieldName(), '');
        if (!app(FrontCsrfService::class)->validateRequest($token, Request::header('X-CSRF-Token'))) {
            return AdminApiResponse::fail('表单已过期，请刷新后重试');
        }

        $result = app(MemberService::class)->changePassword(
            (int) $member['id'],
            (string) Request::post('old_password', ''),
            (string) Request::post('new_password', ''),
            (string) Request::post('password_confirm', '')
        );
        if ($result->isOk()) {
            $result = app(FrontCsrfService::class)->attachRotatedMeta($result);
        }

        return AdminApiResponse::admin($result);
    }

    public function requestCancel(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误', 405);
        }

        $member = app(FrontAuthService::class)->current();
        if ($member === null) {
            return AdminApiResponse::authExpired('请先登录', SiteUrl::memberLogin());
        }

        $token = (string) Request::post(app(FrontCsrfService::class)->fieldName(), '');
        if (!app(FrontCsrfService::class)->validateRequest($token, Request::header('X-CSRF-Token'))) {
            return AdminApiResponse::fail('表单已过期，请刷新后重试');
        }

        $result = app(MemberCancelService::class)->requestPublic(
            (int) $member['id'],
            (string) Request::post('reason', '')
        );
        if ($result->isOk()) {
            $result = app(FrontCsrfService::class)->attachRotatedMeta($result);
        }

        return AdminApiResponse::admin($result);
    }

    public function oauthRedirect(string $provider = ''): Response
    {
        $url = app(SocialAuthService::class)->redirectUrl($provider, (string) Request::get('redirect', ''));

        return $url !== null
            ? redirect($url)
            : redirect(SiteUrl::memberLogin());
    }

    public function oauthCallback(string $provider = ''): Response
    {
        $result = app(SocialAuthService::class)->handleCallback($provider);
        if ($result->isOk() && ($result->extra()['redirect'] ?? '') !== '') {
            return redirect((string) $result->extra()['redirect']);
        }

        $msg = trim((string) ($result->message() ?? '第三方登录失败'));
        $login = SiteUrl::memberLogin();
        $sep   = str_contains($login, '?') ? '&' : '?';

        return redirect($login . $sep . 'oauth_error=' . rawurlencode($msg));
    }
}
