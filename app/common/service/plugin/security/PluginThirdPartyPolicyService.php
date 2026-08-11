<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\security;

use app\common\service\plugin\PluginService;

/** 区分官方 / 第三方插件（开放市场审包与访问策略） */
final class PluginThirdPartyPolicyService
{
    public static function isOfficialIdentifier(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return false;
        }
        $manifest = app(PluginService::class)->readManifest($identifier);
        if (!is_array($manifest)) {
            return false;
        }
        $publisher = strtolower(trim((string) ($manifest['publisher_type'] ?? '')));
        if ($publisher === 'official') {
            return true;
        }
        $package = strtolower(trim((string) ($manifest['package'] ?? '')));
        if ($package !== '' && str_starts_with($package, 'pivark/')) {
            return true;
        }
        $vendors = config('pivark.plugin_official_vendors', ['pivark']);
        if (!is_array($vendors)) {
            return false;
        }
        foreach ($vendors as $vendor) {
            $vendor = strtolower(trim((string) $vendor));
            if ($vendor !== '' && str_starts_with($package, $vendor . '/')) {
                return true;
            }
        }

        return false;
    }
}
