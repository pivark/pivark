<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\security;

use app\common\service\plugin\gateway\PluginGatewayCallerContext;

/** 第三方插件经 WeappConfigGateway::configSet 的配置键作用域 */
final class PluginConfigAccessGuard
{
    /**
     * 第三方仅可写自有前缀键；官方插件或不在 Gateway 调用栈内时不校验。
     * 内核安全/站点/支付等键对第三方一律禁止（不依赖 enforce 开关）。
     */
    public static function assertCallerMaySet(string $key): void
    {
        $caller = PluginGatewayCallerContext::currentIdentifier();
        if ($caller === null || $caller === '') {
            return;
        }
        if (PluginThirdPartyPolicyService::isOfficialIdentifier($caller)) {
            return;
        }

        $key = strtolower(trim($key));
        if ($key === '') {
            throw new \RuntimeException('第三方插件禁止修改空配置键');
        }

        foreach (self::deniedPrefixes() as $prefix) {
            if ($key === rtrim($prefix, '.') || str_starts_with($key, $prefix)) {
                throw new \RuntimeException('第三方插件禁止修改配置：' . $key);
            }
        }

        $slug   = str_replace('-', '_', $caller);
        $hyphen = str_replace('_', '-', $caller);
        foreach ([
            'weapp_' . $slug . '_',
            $slug . '_',
            $hyphen . '_',
            strtolower($caller) . '_',
        ] as $allowed) {
            if ($allowed !== '_' && str_starts_with($key, $allowed)) {
                return;
            }
        }

        throw new \RuntimeException('第三方插件只能修改自有前缀配置：' . $key);
    }

    /** @return list<string> */
    private static function deniedPrefixes(): array
    {
        return [
            'plugin.security.',
            'plugin.market.',
            'plugin.commercial.',
            'payment.',
            'pivark.',
            'license_',
            'site_',
            'admin_',
            'cache_',
            'member.',
            'upload.',
            'smtp_',
            'mail_',
        ];
    }
}
