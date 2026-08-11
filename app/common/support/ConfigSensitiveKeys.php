<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use app\common\service\infra\CacheConfigService;

/** 敏感配置键 SSOT 访问器（config/security/sensitive_keys.php） */
final class ConfigSensitiveKeys
{
    /** @return list<string> */
    public static function adminConfirmKeys(): array
    {
        $keys = config('security.sensitive_keys.admin_confirm');

        return is_array($keys) ? array_values($keys) : [];
    }

    /** @return list<string> */
    public static function credentialKeys(): array
    {
        $keys = config('security.sensitive_keys.credentials');

        return is_array($keys) ? array_values($keys) : [];
    }

    /** @return list<string> */
    public static function templateForbiddenKeys(): array
    {
        $extra = config('security.sensitive_keys.template_forbidden_extra');
        $extra = is_array($extra) ? $extra : [];

        return array_values(array_unique(array_merge(self::credentialKeys(), $extra)));
    }

    public static function isAdminConfirmKey(string $key): bool
    {
        if (app(CacheConfigService::class)->isCacheConfigKey($key)) {
            return true;
        }

        return in_array($key, self::adminConfirmKeys(), true);
    }

    public static function isCredentialKey(string $key): bool
    {
        return in_array($key, self::credentialKeys(), true);
    }

    public static function isTemplateForbidden(string $key): bool
    {
        return in_array($key, self::templateForbiddenKeys(), true);
    }
}
