<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\auth;

use app\common\service\config\ConfigService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\PluginService;

/** 社交登录能力提供者（插件 boot 注册 · 内核不写 social-auth 字面量） */
final class SocialAuthCapabilityRegistry
{

    public function __construct(
        private readonly ConfigService $configService,
        private readonly PluginService $pluginService,
        private readonly EntitlementService $entitlementService,
    ) {
    }

    /** @var array<string, array{label:string, settings_route:string}> */
    private static array $providers = [];

    public function reset(): void
    {
        self::$providers = [];
    }

    /**
     * @param array{label?:string, settings_route?:string} $entry
     */
    public function register(string $identifier, array $entry): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        $label = trim((string) ($entry['label'] ?? $identifier));
        $route = trim((string) ($entry['settings_route'] ?? ''));
        if ($label === '' || $route === '') {
            return;
        }
        self::$providers[$identifier] = [
            'label'          => $label,
            'settings_route' => $route,
        ];
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        unset(self::$providers[$identifier]);
    }

    public function isRegistered(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));

        return $identifier !== '' && isset(self::$providers[$identifier]);
    }

    public function activeIdentifier(): string
    {
        $selected = strtolower(trim((string) $this->configService->get('social_auth_provider_plugin', '')));
        if ($selected !== '' && $this->isUsable($selected)) {
            return $selected;
        }
        foreach (array_keys(self::$providers) as $id) {
            if ($this->isUsable($id)) {
                return $id;
            }
        }

        return 'social-auth';
    }

    public function hasActiveProvider(): bool
    {
        $id = $this->activeIdentifier();

        return $id !== '' && $this->isUsable($id);
    }

    public function settingsRoute(?string $identifier = null): string
    {
        $id = strtolower(trim($identifier ?? $this->activeIdentifier()));
        $route = trim((string) (self::$providers[$id]['settings_route'] ?? ''));
        if ($route !== '') {
            return $route;
        }

        return (string) config('miniprogram_channels.admin_spa.social_auth_settings', '/weapp/social-auth/settings');
    }

    /** @return list<array{id:string, label:string, settings_route:string, enabled:bool}> */
    public function optionsForAdmin(): array
    {
        $out = [];
        foreach (self::$providers as $id => $entry) {
            $out[] = [
                'id'              => $id,
                'label'           => (string) ($entry['label'] ?? $id),
                'settings_route'  => (string) ($entry['settings_route'] ?? ''),
                'enabled'         => $this->pluginService->isEnabled($id),
            ];
        }

        return $out;
    }

    private function isUsable(string $identifier): bool
    {
        if (!$this->isRegistered($identifier)) {
            return false;
        }

        return $this->pluginService->isEnabled($identifier)
            && $this->entitlementService->can($identifier);
    }
}
