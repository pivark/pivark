<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 发行形态迁移跳过规则（按 ReleaseEditionConfig::edition()）。
 *
 * 精简发行（community / 同策略的 dev）跳过未启用能力的建表/种子；
 * platform 等完整形态默认不跳（跑全量迁移）。
 *
 * @see config/release/{edition}.php
 */
declare(strict_types=1);

namespace app\common\support;

final class ReleaseMigrationRules
{
    /**
     * 未预装的官方 weapp 迁移 basename 前缀（装插件时由插件 install.sql 建表）
     *
     * @return list<string>
     */
    public static function nonBundledWeappMigrationBasenamePrefixes(): array
    {
        return ReleaseEditionConfig::list('non_bundled_migration_prefixes');
    }

    /** 是否启用「精简形态」迁移裁剪 */
    public static function usesLeanMigrationSkips(): bool
    {
        return in_array(ReleaseEditionConfig::edition(), ['community', 'dev'], true);
    }

    /**
     * 跳过本形态未启用能力的迁移路径（相对仓根；兼容任意 …/migrations/ 前缀）
     */
    public static function shouldSkipMigrationRel(string $rel): bool
    {
        if (!self::usesLeanMigrationSkips()) {
            return false;
        }

        $rel = self::normalizeRel($rel);
        if (preg_match('#^weapp/[^/]+/database/migrations/migrate_.+\.php$#', $rel) === 1) {
            return self::shouldSkipMigrationBasename(basename($rel));
        }

        $tail = self::migrationsTail($rel);
        if ($tail === null) {
            return false;
        }

        foreach (['www', 'platform', 'tender', 'miniprogram', 'enterprise'] as $domain) {
            if ($tail === $domain || str_starts_with($tail, $domain . '/')) {
                return true;
            }
        }

        $base = basename($tail);
        if (str_starts_with($base, 'migrate_www_') || str_starts_with($base, 'seed_www_')) {
            return true;
        }
        if (str_contains($base, '_www_')) {
            return true;
        }

        return self::shouldSkipMigrationBasename($base);
    }

    /**
     * @return non-empty-string|null
     */
    private static function migrationsTail(string $rel): ?string
    {
        if (preg_match('#(?:^|/)migrations/(.+)$#', $rel, $m) !== 1) {
            return null;
        }
        $tail = trim((string) $m[1], '/');

        return $tail !== '' ? $tail : null;
    }

    /**
     * 按 basename 跳过本形态未启用的可选能力建表迁移
     */
    public static function shouldSkipMigrationBasename(string $basename): bool
    {
        if (!self::usesLeanMigrationSkips()) {
            return false;
        }

        $base = basename($basename, '.php');
        if ($base === '' || !str_starts_with($base, 'migrate_')) {
            return false;
        }

        foreach ([
            'migrate_license_platform',
            'migrate_platform_',
            'migrate_shop_',
            'migrate_tender_',
            'migrate_miniprogram_',
            'migrate_cron_tender_',
            'migrate_weapp_tender_',
            'migrate_mp_wechat_market_',
        ] as $prefix) {
            if (str_starts_with($base, $prefix)) {
                return true;
            }
        }

        foreach (self::nonBundledWeappMigrationBasenamePrefixes() as $prefix) {
            if (str_starts_with($base, $prefix)) {
                return true;
            }
        }

        return in_array($base, [
            'migrate_menu_platform_features',
            'migrate_site_ad_slot_www_topbar',
            'migrate_item_shop_variant_link',
            'migrate_cron_shop_expire',
            'migrate_admin_portal',
            'migrate_enterprise_resource_schema',
        ], true);
    }

    private static function normalizeRel(string $rel): string
    {
        return trim(str_replace('\\', '/', $rel), '/');
    }
}
