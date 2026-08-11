<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\auth;

use app\common\service\auth\SocialAuthCapabilityRegistry;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\weapp\PluginWeappAccess;
use app\common\support\ServiceResult;

/** OAuth 门面（实现在社交登录插件 weapp 目录） */
class SocialAuthService
{

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly SocialAuthCapabilityRegistry $socialAuthCapabilityRegistry,
    ) {
    }

    private function pluginId(): string
    {
        return $this->socialAuthCapabilityRegistry->activeIdentifier();
    }

    private function canUsePlugin(): bool
    {
        $id = $this->pluginId();

        return $id !== '' && $this->entitlements->can($id);
    }

    /**
     * @return list<string>
     */
    public function getEnabledProviders(): array
    {
        if (!$this->canUsePlugin()) {
            return [];
        }
        $result = PluginWeappAccess::invokeInstance($this->pluginId(), 'SocialAuthService', 'getEnabledProviders', []);

        return is_array($result) ? $result : [];
    }

    public function redirectUrl(string $provider, string $redirectAfter = ''): ?string
    {
        if (!$this->canUsePlugin()) {
            return null;
        }
        $result = PluginWeappAccess::invokeInstance($this->pluginId(), 'SocialAuthService', 'redirectUrl', [$provider, $redirectAfter]);

        return is_string($result) && $result !== '' ? $result : null;
    }

    /**
     * @return ServiceResult
     */
    public function handleCallback(string $provider): ServiceResult
    {
        if (!$this->canUsePlugin()) {
            return ServiceResult::fail('第三方登录插件未启用');
        }
        $result = PluginWeappAccess::invokeInstance($this->pluginId(), 'SocialAuthService', 'handleCallback', [$provider]);
        if ($result instanceof ServiceResult) {
            return $result;
        }

        return ServiceResult::fail('第三方登录插件未安装');
    }

    /**
     * @return list<array{provider:string,label:string,nickname:string,bound:int,bind_url:string}>
     */
    public function memberSecurityRows(int $userId): array
    {
        if (!$this->canUsePlugin() || $userId < 1) {
            return [];
        }
        $result = PluginWeappAccess::invokeInstance($this->pluginId(), 'SocialAuthService', 'memberSecurityRows', [$userId]);

        return is_array($result) ? $result : [];
    }

    public function unbindForMember(int $userId, string $provider): ServiceResult
    {
        if (!$this->canUsePlugin()) {
            return ServiceResult::fail('第三方登录插件未启用');
        }
        $result = PluginWeappAccess::invokeInstance($this->pluginId(), 'SocialAuthService', 'unbindForMember', [$userId, $provider]);
        if ($result instanceof ServiceResult) {
            return $result;
        }

        return ServiceResult::fail('第三方登录插件未安装');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchMpWechatProfileByCode(string $code): ?array
    {
        if (!$this->canUsePlugin()) {
            return null;
        }
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        $result = PluginWeappAccess::invokeStatic(
            $this->pluginId(),
            'SocialAuthMpWechatLoginService',
            'fetchProfileByCode',
            [$code],
        );

        return is_array($result) ? $result : null;
    }
}
