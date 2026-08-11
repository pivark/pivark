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

use app\common\service\audit\AuditLogService;
use app\common\service\admin\AdminSpaMemberFormMetaCacheService;
use app\common\service\front\FrontAuthService;
use app\common\service\mail\MailService;
use app\common\service\plugin\extension\PluginDocumentEditorService;
use app\common\service\plugin\registry\PluginExtensionRegistry;
use app\common\service\plugin\extension\PluginEditorSurfaceService;
use app\common\service\plugin\PluginService;
use app\common\service\config\ConfigService;
use app\common\model\Config;
use app\common\support\HtmlSanitizer;
use app\common\support\SiteUrl;

/** 会员中心功能配置（configs.member_*） */
class MemberConfigService
{

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly ConfigService $configService,
        private readonly PluginExtensionRegistry $pluginExtensionRegistry,
        private readonly PluginService $pluginService,
        private readonly PluginEditorSurfaceService $pluginEditorSurfaceService,
        private readonly MailService $mailService,
        private readonly AdminSpaMemberFormMetaCacheService $memberFormMetaCache,
    ) {
    }

    private function frontAuth(): FrontAuthService
    {
        return app(FrontAuthService::class);
    }

    private function pluginDocumentEditor(): PluginDocumentEditorService
    {
        return app(PluginDocumentEditorService::class);
    }

    public const REGISTER_VERIFY = ['none', 'admin', 'email', 'sms'];

    public const PASSWORD_RECOVER = ['none', 'email', 'sms', 'both'];

    public const LOGIN_REDIRECT = ['current', 'home', 'center', 'custom'];

    /** @return list<string> */
    public function keys(): array
    {
        return [
            'member_center_open',
            'member_register_open',
            'member_enterprise_register_open',
            'member_register_verify',
            'member_password_recover',
            'member_register_forbidden',
            'member_register_ip_limit',
            'member_register_agreement',
            'member_cancel_open',
            'member_login_session_ttl',
            'member_login_redirect',
            'member_login_redirect_url',
            'member_mobile_quick_login',
            'member_document_publish_open',
            'members_api_public',
        ];
    }

    /** @return list<string> */
    public function pointsKeys(): array
    {
        return [
            'member_points_enabled',
            'member_points_name',
            'member_register_gift_points',
            'member_points_signin_enabled',
            'member_points_signin_amount',
            'member_points_consume_enabled',
            'member_points_consume_per_yuan',
            'member_points_login_enabled',
            'member_points_login_amount',
        ];
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $out = [];
        foreach ($this->keys() as $key) {
            $out[$key] = (string) $this->configService->get($key, $this->defaultFor($key));
        }

        return $out;
    }

    /** @return array<string, string> */
    public function pointsAll(): array
    {
        $out = [];
        foreach ($this->pointsKeys() as $key) {
            $out[$key] = (string) $this->configService->get($key, $this->defaultFor($key));
        }

        return $out;
    }

    public function defaultFor(string $key): string
    {
        return match ($key) {
            'member_center_open', 'member_register_open', 'member_enterprise_register_open',
            'member_points_enabled', 'member_cancel_open',
            'member_document_publish_open' => '1',
            'member_register_verify' => 'none',
            'member_password_recover' => 'none',
            'member_register_forbidden' => 'www,bbs,ftp,mail,user,users,admin,administrator',
            'member_register_ip_limit' => '24',
            'member_login_session_ttl' => '3600',
            'member_login_redirect' => 'current',
            'member_mobile_quick_login' => '0',
            'member_register_gift_points' => '0',
            'member_points_signin_amount' => '3',
            'member_points_consume_per_yuan' => '1',
            'member_points_login_amount' => '1',
            'member_points_name' => '积分',
            'member_points_signin_enabled', 'member_points_consume_enabled',
            'member_points_login_enabled' => '0',
            'members_api_public'          => '0',
            default => '',
        };
    }

    public function isCenterOpen(): bool
    {
        return (string) $this->configService->get('member_center_open', '1') === '1';
    }

    public function isRegisterOpen(): bool
    {
        return $this->isCenterOpen()
            && (string) $this->configService->get('member_register_open', '1') === '1';
    }

    /** 是否开放「企业注册」入口（须同时开放普通注册） */
    public function isEnterpriseRegisterOpen(): bool
    {
        return $this->isRegisterOpen()
            && (string) $this->configService->get('member_enterprise_register_open', '1') === '1';
    }

    public function isPointsEnabled(): bool
    {
        return (string) $this->configService->get('member_points_enabled', '1') === '1';
    }

    public function pointsLabel(): string
    {
        $name = trim((string) $this->configService->get('member_points_name', $this->defaultFor('member_points_name')));

        return $name !== '' ? $name : '积分';
    }

    public function isCancelOpen(): bool
    {
        return $this->isCenterOpen()
            && (string) $this->configService->get('member_cancel_open', '1') === '1';
    }

    public function isDocumentPublishOpen(): bool
    {
        return $this->isCenterOpen()
            && (string) $this->configService->get('member_document_publish_open', '1') === '1';
    }

    private const DOCUMENT_PLUGINS_KEY = 'member_document_plugins';

    /** @return list<string> */
    public function documentPluginIdentifiers(): array
    {
        $ids = [];
        foreach ($this->pluginExtensionRegistry->documentAddonBridgeIdentifiers() as $id) {
            if ($id !== '' && $this->pluginDocumentEditor()->hasSpaComponent($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, bool>
     */
    public function documentPluginMap(): array
    {
        $raw = $this->configService->get(self::DOCUMENT_PLUGINS_KEY, '');
        $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
        if (!is_array($decoded)) {
            $decoded = [];
        }

        $out = [];
        foreach ($this->documentPluginIdentifiers() as $id) {
            if (array_key_exists($id, $decoded)) {
                $out[$id] = (int) $decoded[$id] === 1;
            } else {
                $out[$id] = true;
            }
        }

        return $out;
    }

    public function isDocumentPluginOpenForMember(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return false;
        }
        if (!$this->pluginDocumentEditor()->hasSpaComponent($identifier)) {
            return false;
        }

        $map = $this->documentPluginMap();

        return !empty($map[$identifier]);
    }

    /**
     * @return list<array{identifier:string,label:string,enabled:int}>
     */
    public function documentPluginOptionsForAdmin(): array
    {
        $map = $this->documentPluginMap();
        $out = [];
        foreach ($this->documentPluginIdentifiers() as $id) {
            $manifest = $this->pluginService->readManifest($id) ?? [];
            $editor   = $this->pluginEditorSurfaceService->manifestEditor($id);
            $label    = trim((string) ($editor['tab_label'] ?? ($manifest['name'] ?? $id)));
            $out[]    = [
                'identifier' => $id,
                'label'      => $label !== '' ? $label : $id,
                'enabled'    => !empty($map[$id]) ? 1 : 0,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function applyDocumentPluginsFromAdmin(array $data): void
    {
        $raw = $data['member_document_plugins'] ?? null;
        if (!is_array($raw)) {
            return;
        }
        $allowed = array_flip($this->documentPluginIdentifiers());
        $payload = [];
        foreach ($raw as $id => $on) {
            $key = strtolower(trim((string) $id));
            if ($key === '' || !isset($allowed[$key])) {
                continue;
            }
            $payload[$key] = (int) $on === 1 ? 1 : 0;
        }
        Config::setValue(self::DOCUMENT_PLUGINS_KEY, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public function isMobileQuickLoginEnabled(): bool
    {
        return $this->isCenterOpen()
            && (string) $this->configService->get('member_mobile_quick_login', '0') === '1';
    }

    public function registerVerifyMode(): string
    {
        $mode = strtolower(trim((string) $this->configService->get('member_register_verify', 'none')));

        return in_array($mode, self::REGISTER_VERIFY, true) ? $mode : 'none';
    }

    public function registerNeedsAdminActivation(): bool
    {
        return $this->registerVerifyMode() === 'admin';
    }

    public function passwordRecoverMode(): string
    {
        $mode = strtolower(trim((string) $this->configService->get('member_password_recover', 'none')));

        return in_array($mode, self::PASSWORD_RECOVER, true) ? $mode : 'none';
    }

    public function isPasswordRecoverViaEmail(): bool
    {
        return in_array($this->passwordRecoverMode(), ['email', 'both'], true);
    }

    /** @return list<string> */
    public function forbiddenUsernames(): array
    {
        $raw = (string) $this->configService->get('member_register_forbidden', '');
        $parts = preg_split('/[\s,，]+/u', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $name = strtolower(trim($part));
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return array_values(array_unique($out));
    }

    public function isUsernameForbidden(string $username): bool
    {
        $username = strtolower(trim($username));
        if ($username === '') {
            return false;
        }
        foreach ($this->forbiddenUsernames() as $forbidden) {
            if ($username === $forbidden) {
                return true;
            }
        }

        return false;
    }

    public function registerIpLimitHours(): int
    {
        return max(0, (int) $this->configService->get('member_register_ip_limit', 0));
    }

    public function loginSessionTtl(): int
    {
        return max(300, (int) $this->configService->get('member_login_session_ttl', 3600));
    }

    public function loginRedirectMode(): string
    {
        $mode = strtolower(trim((string) $this->configService->get('member_login_redirect', 'current')));

        return in_array($mode, self::LOGIN_REDIRECT, true) ? $mode : 'current';
    }

    public function loginRedirectUrl(): string
    {
        return trim((string) $this->configService->get('member_login_redirect_url', ''));
    }

    public function resolveLoginRedirect(string $postedRedirect = ''): string
    {
        $posted = trim($postedRedirect);
        if ($posted !== '' && $this->frontAuth()->isSafeRedirect($posted)
            && $this->loginRedirectMode() === 'current') {
            return $posted;
        }

        return match ($this->loginRedirectMode()) {
            'home'   => SiteUrl::home(),
            'center' => SiteUrl::frontAccountHub(),
            'custom' => $this->loginRedirectUrl() !== '' ? $this->loginRedirectUrl() : SiteUrl::frontAccountHub(),
            default  => $posted !== '' && $this->frontAuth()->isSafeRedirect($posted)
                ? $posted
                : SiteUrl::frontAccountHub(),
        };
    }

    public function pointsDisplayName(): string
    {
        $name = HtmlSanitizer::cleanPlainText(
            (string) $this->configService->get('member_points_name', '积分'),
            20
        );

        return $name !== '' ? $name : '积分';
    }

    public function registerGiftPoints(): int
    {
        return max(0, (int) $this->configService->get('member_register_gift_points', 0));
    }

    public function isSigninGiftEnabled(): bool
    {
        return $this->isPointsEnabled()
            && (string) $this->configService->get('member_points_signin_enabled', '0') === '1';
    }

    public function signinGiftPoints(): int
    {
        return max(0, (int) $this->configService->get('member_points_signin_amount', 0));
    }

    public function isConsumeGiftEnabled(): bool
    {
        return $this->isPointsEnabled()
            && (string) $this->configService->get('member_points_consume_enabled', '0') === '1';
    }

    public function consumePointsPerYuan(): int
    {
        return max(0, (int) $this->configService->get('member_points_consume_per_yuan', 0));
    }

    public function isLoginGiftEnabled(): bool
    {
        return $this->isPointsEnabled()
            && (string) $this->configService->get('member_points_login_enabled', '0') === '1';
    }

    public function loginGiftPoints(): int
    {
        return max(0, (int) $this->configService->get('member_points_login_amount', 0));
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $verify = strtolower(trim((string) ($data['member_register_verify'] ?? 'none')));
        if (!in_array($verify, self::REGISTER_VERIFY, true)) {
            return ServiceResult::fail('注册验证方式无效');
        }
        if ($verify === 'email' && !$this->mailService->isConfigured()) {
            return ServiceResult::fail('邮件验证需先配置 SMTP（系统设置 → 邮件）');
        }
        if ($verify === 'sms') {
            return ServiceResult::fail('手机验证尚未对接，请选择其他验证方式');
        }
        $recover = strtolower(trim((string) ($data['member_password_recover'] ?? 'none')));
        if (in_array($recover, ['email', 'both'], true) && !$this->mailService->isConfigured()) {
            return ServiceResult::fail('找回密码邮件方式需先配置 SMTP');
        }
        if (!in_array($recover, self::PASSWORD_RECOVER, true)) {
            return ServiceResult::fail('找回密码方式无效');
        }
        $redirect = strtolower(trim((string) ($data['member_login_redirect'] ?? 'current')));
        if (!in_array($redirect, self::LOGIN_REDIRECT, true)) {
            return ServiceResult::fail('登录跳转方式无效');
        }

        $payload = [
            'member_register_open'            => (int) ($data['member_register_open'] ?? 0) === 1 ? '1' : '0',
            'member_enterprise_register_open' => (int) ($data['member_enterprise_register_open'] ?? 0) === 1 ? '1' : '0',
            'member_register_verify'          => $verify,
            'member_password_recover'      => $recover,
            'member_register_forbidden'    => HtmlSanitizer::cleanPlainText(
                (string) ($data['member_register_forbidden'] ?? ''),
                500
            ),
            'member_register_ip_limit'     => (string) max(0, (int) ($data['member_register_ip_limit'] ?? 0)),
            'member_register_agreement'    => (string) ($data['member_register_agreement'] ?? ''),
            'member_cancel_open'           => (int) ($data['member_cancel_open'] ?? 0) === 1 ? '1' : '0',
            'member_login_session_ttl'     => (string) max(300, (int) ($data['member_login_session_ttl'] ?? 3600)),
            'member_login_redirect'        => $redirect,
            'member_login_redirect_url'    => trim((string) ($data['member_login_redirect_url'] ?? '')),
            'member_mobile_quick_login'    => (int) ($data['member_mobile_quick_login'] ?? 0) === 1 ? '1' : '0',
            'member_document_publish_open' => (int) ($data['member_document_publish_open'] ?? 0) === 1 ? '1' : '0',
            'members_api_public'           => (int) ($data['members_api_public'] ?? 0) === 1 ? '1' : '0',
        ];

        foreach ($payload as $key => $val) {
            Config::setValue($key, $val);
        }
        $this->applyDocumentPluginsFromAdmin($data);
        $this->configService->forgetRequestCache();
        app(\app\common\service\admin\AdminSpaMenuRouteCacheService::class)->bustAll();
        $this->auditLogService->operate('保存会员功能配置', 'admin.member.config', []);

        return ServiceResult::ok(null, '配置已保存');
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function savePointsAdmin(array $data): ServiceResult
    {
        $name = HtmlSanitizer::cleanPlainText((string) ($data['member_points_name'] ?? ''), 20);
        if ($name === '') {
            return ServiceResult::fail('请填写积分名称');
        }

        $payload = [
            'member_points_enabled'          => (int) ($data['member_points_enabled'] ?? 0) === 1 ? '1' : '0',
            'member_points_name'             => $name,
            'member_register_gift_points'    => (string) max(0, (int) ($data['member_register_gift_points'] ?? 0)),
            'member_points_signin_enabled'   => (int) ($data['member_points_signin_enabled'] ?? 0) === 1 ? '1' : '0',
            'member_points_signin_amount'    => (string) max(0, (int) ($data['member_points_signin_amount'] ?? 0)),
            'member_points_consume_enabled'  => (int) ($data['member_points_consume_enabled'] ?? 0) === 1 ? '1' : '0',
            'member_points_consume_per_yuan' => (string) max(0, (int) ($data['member_points_consume_per_yuan'] ?? 0)),
            'member_points_login_enabled'    => (int) ($data['member_points_login_enabled'] ?? 0) === 1 ? '1' : '0',
            'member_points_login_amount'     => (string) max(0, (int) ($data['member_points_login_amount'] ?? 0)),
        ];

        foreach ($payload as $key => $val) {
            Config::setValue($key, $val);
        }
        $this->configService->forgetRequestCache();
        $this->auditLogService->operate('保存积分规则配置', 'admin.member.points', []);
        $this->memberFormMetaCache->bust();

        return ServiceResult::ok(null, '积分设置已保存');
    }
}
