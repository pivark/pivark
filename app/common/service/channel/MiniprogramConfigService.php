<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\channel;

use app\common\support\ServiceResult;
use app\common\service\channel\MiniprogramPageConfigService;

use app\common\service\auth\SocialAuthCapabilityRegistry;
use app\common\service\auth\SocialAuthService;
use app\common\service\config\ConfigService;
use app\common\service\payment\PaymentUrl;
use app\common\support\SiteUrl;
use app\common\service\channel\MiniprogramChannelRegistry;
use app\common\service\channel\MiniprogramChannelService;

/** 小程序渠道配置（configs.mp_wechat_*）；hub/社交登录能力由 Registry 发现 */
class MiniprogramConfigService
{

    public function __construct(
        private readonly PaymentUrl $paymentUrl,
    ) {
    }

    private function miniprogramChannelService(): MiniprogramChannelService
    {
        return app(MiniprogramChannelService::class);
    }

    public const EDITION_CONTENT = 'content';
    public const EDITION_SHOP    = 'shop';

    /** @return list<string> */
    public function keys(): array
    {
        return [
            'mp_wechat_channel_open',
            'mp_wechat_edition',
            'mp_wechat_api_base',
            'mp_wechat_login_open',
        ];
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $out = [];
        foreach ($this->keys() as $key) {
            $out[$key] = (string) app(ConfigService::class)->get($key, $this->defaultFor($key));
        }

        return $out;
    }

    public function defaultFor(string $key): string
    {
        return match ($key) {
            'mp_wechat_channel_open' => '0',
            'mp_wechat_edition'      => self::EDITION_CONTENT,
            'mp_wechat_api_base'     => '',
            'mp_wechat_login_open'   => '1',
            default                  => '',
        };
    }

    public function isWechatChannelOpen(): bool
    {
        return (int) app(ConfigService::class)->get('mp_wechat_channel_open', 0) === 1;
    }

    public function apiBase(): string
    {
        $custom = rtrim(trim((string) app(ConfigService::class)->get('mp_wechat_api_base', '')), '/');
        if ($custom !== '') {
            return $custom;
        }

        return rtrim($this->paymentUrl->absolute('/api/v1'), '/');
    }

    public function edition(): string
    {
        $edition = (string) app(ConfigService::class)->get('mp_wechat_edition', self::EDITION_CONTENT);

        return in_array($edition, [self::EDITION_CONTENT, self::EDITION_SHOP], true)
            ? $edition
            : self::EDITION_CONTENT;
    }

    /** @return array<string, mixed> */
    public function socialAuthWechatMini(): array
    {
        $appId     = trim((string) app(ConfigService::class)->get('social_auth_mp_wechat_app_id', ''));
        $enabled   = (int) app(ConfigService::class)->get('social_auth_mp_wechat_enabled', 0) === 1;
        $hasSecret = trim((string) app(ConfigService::class)->get('social_auth_mp_wechat_app_secret', '')) !== '';
        $providers = app(SocialAuthService::class)->getEnabledProviders();
        $installed = app(SocialAuthCapabilityRegistry::class)->hasActiveProvider() || $providers !== [];

        return [
            'installed'     => $installed ? 1 : 0,
            'enabled'       => $enabled ? 1 : 0,
            'configured'    => ($appId !== '' && $hasSecret) ? 1 : 0,
            'app_id'        => $appId,
            'app_secret'    => trim((string) app(ConfigService::class)->get('social_auth_mp_wechat_app_secret', '')),
            'has_app_secret'=> $hasSecret ? 1 : 0,
            'settings_path' => app(SocialAuthCapabilityRegistry::class)->settingsRoute(),
            'provider'      => app(SocialAuthCapabilityRegistry::class)->activeIdentifier(),
        ];
    }

    /** 小程序端 bootstrap（不含 Secret） */
    public function publicWechatPayload(): array
    {
        $auth = $this->socialAuthWechatMini();

        return [
            'platform'       => 'wechat',
            'edition'        => $this->edition(),
            'enabled'        => $this->miniprogramChannelService()->isChannelActive() ? 1 : 0,
            'licensed'       => $this->miniprogramChannelService()->isLicensed() ? 1 : 0,
            'api_base'       => $this->apiBase(),
            'login_open'     => (int) app(ConfigService::class)->get('mp_wechat_login_open', 1) === 1 ? 1 : 0,
            'app_id'         => (string) ($auth['app_id'] ?? ''),
            'oauth_callback' => SiteUrl::memberOAuthCallback('mp_wechat'),
        ];
    }

    /** @return array<string, mixed> */
    public function adminMeta(): array
    {
        $auth      = $this->socialAuthWechatMini();
        $apiBase   = $this->apiBase();
        $requestHost = parse_url($apiBase, PHP_URL_HOST) ?: '';

        return [
            'cfg'              => $this->all(),
            'api_base_default' => rtrim($this->paymentUrl->absolute('/api/v1'), '/'),
            'api_base_current' => $apiBase,
            'request_domain'   => $requestHost,
            'oauth_callback'   => SiteUrl::memberOAuthCallback('mp_wechat'),
            'sdk_project_path' => 'miniprogram/apps/wechat-content',
            'social_auth'      => $auth,
            'editions'         => [
                ['id' => self::EDITION_CONTENT, 'label' => '纯内容版', 'available' => 1],
                ['id' => self::EDITION_SHOP, 'label' => '商城版（即将开放）', 'available' => 0],
            ],
            'mp_wechat'        => $this->miniprogramChannelService()->adminMeta(),
            'miniprogram_hub'  => [
                'selected' => app(MiniprogramChannelRegistry::class)->hubPlugin(),
                'options'  => app(MiniprogramChannelRegistry::class)->hubOptionsForAdmin(),
            ],
            'social_auth_hub'  => [
                'selected' => app(SocialAuthCapabilityRegistry::class)->activeIdentifier(),
                'options'  => app(SocialAuthCapabilityRegistry::class)->optionsForAdmin(),
            ],
            'content_flow'     => app(MiniprogramPageConfigService::class)->contentFlowStatus(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $deny = $this->miniprogramChannelService()->assertLicensed();
        if ($deny !== null) {
            return $deny;
        }

        $edition = (string) ($data['mp_wechat_edition'] ?? self::EDITION_CONTENT);
        if (!in_array($edition, [self::EDITION_CONTENT, self::EDITION_SHOP], true)) {
            $edition = self::EDITION_CONTENT;
        }
        if ($edition === self::EDITION_SHOP) {
            return ServiceResult::fail('商城版小程序尚未开放，请先使用纯内容版');
        }

        $apiBase = rtrim(trim((string) ($data['mp_wechat_api_base'] ?? '')), '/');
        if ($apiBase !== '' && !preg_match('#^https?://#i', $apiBase)) {
            return ServiceResult::fail('API 地址须以 http:// 或 https:// 开头');
        }

        app(ConfigService::class)->set('mp_wechat_channel_open', !empty($data['mp_wechat_channel_open']) ? '1' : '0');
        app(ConfigService::class)->set('mp_wechat_edition', $edition);
        app(ConfigService::class)->set('mp_wechat_api_base', $apiBase);
        app(ConfigService::class)->set('mp_wechat_login_open', !empty($data['mp_wechat_login_open']) ? '1' : '0');

        $hub = strtolower(trim((string) ($data['mp_miniprogram_hub_plugin'] ?? '')));
        if ($hub !== '' && app(MiniprogramChannelRegistry::class)->isRegisteredHub($hub)) {
            app(ConfigService::class)->set('mp_miniprogram_hub_plugin', $hub);
        }

        $socialProvider = strtolower(trim((string) ($data['social_auth_provider_plugin'] ?? '')));
        if ($socialProvider !== '' && app(SocialAuthCapabilityRegistry::class)->isRegistered($socialProvider)) {
            app(ConfigService::class)->set('social_auth_provider_plugin', $socialProvider);
        }

        $appId = trim((string) ($data['mp_wechat_app_id'] ?? ''));
        $appSecret = trim((string) ($data['mp_wechat_app_secret'] ?? ''));
        $loginOpen = !empty($data['mp_wechat_login_open']);
        $existingSecret = trim((string) app(ConfigService::class)->get('social_auth_mp_wechat_app_secret', ''));

        if ($loginOpen && $appId === '') {
            return ServiceResult::fail('启用小程序登录时请填写 AppID');
        }
        if ($loginOpen && $appSecret === '' && $existingSecret === '') {
            return ServiceResult::fail('启用小程序登录时请填写 AppSecret');
        }

        app(ConfigService::class)->set('social_auth_mp_wechat_app_id', $appId);
        if ($appSecret !== '') {
            app(ConfigService::class)->set('social_auth_mp_wechat_app_secret', $appSecret);
        }
        app(ConfigService::class)->set(
            'social_auth_mp_wechat_enabled',
            ($loginOpen && $appId !== '' && ($appSecret !== '' || $existingSecret !== '')) ? '1' : '0'
        );

        return ServiceResult::ok(null, '保存成功');
    }
}
