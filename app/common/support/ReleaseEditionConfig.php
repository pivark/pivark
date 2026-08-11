<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 当前发行形态配置读取（config/release/{edition}.php + discovery）
 *
 * 内核共用；禁止再以 Community* 命名（专业版/企业版同一套内核）。
 */
declare(strict_types=1);

namespace app\common\support;

use app\common\service\plugin\manifest\PluginManifestPolicyDiscovery;

final class ReleaseEditionConfig
{
    public static function edition(): string
    {
        $locked = InstallGate::lockedEdition();
        if ($locked !== null) {
            return $locked;
        }
        $raw = defined('PIVARK_EDITION')
            ? (string) PIVARK_EDITION
            : (string) (function_exists('env') ? env('PIVARK_EDITION', 'community') : 'community');
        $e = strtolower(trim($raw));

        return in_array($e, ['community', 'platform', 'dev'], true) ? $e : 'community';
    }

    /** @return list<string> */
    public static function list(string $key): array
    {
        $edition = self::edition();
        $cfg = null;
        if (function_exists('config')) {
            try {
                $raw = config('release.' . $edition);
                if (is_array($raw)) {
                    $cfg = $raw;
                }
            } catch (\Throwable) {
                $cfg = null;
            }
        }
        if (!is_array($cfg)) {
            $releaseDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'release';
            $file = $releaseDir . DIRECTORY_SEPARATOR . $edition . '.php';
            $loaded = is_readable($file) ? include $file : null;
            // dev 仓常锁 PIVARK_EDITION=dev 且无 config/release/dev.php：emit/PackGuard 须回落 community，否则 retired 清单空放行脏表
            if (!is_array($loaded) && $edition === 'dev') {
                $communityFile = $releaseDir . DIRECTORY_SEPARATOR . 'community.php';
                $loaded = is_readable($communityFile) ? include $communityFile : [];
            }
            $cfg = is_array($loaded) ? $loaded : [];
        }
        $configured = $cfg[$key] ?? null;

        // discovery 目前按开源发行形态清单；其它形态只认配置文件
        $discovered = [];
        if (in_array($edition, ['community', 'dev'], true)) {
            $discovered = match ($key) {
                'bundled_plain_weapp'            => PluginManifestPolicyDiscovery::communityBundledPlainWeapp(),
                'install_required_weapp'         => [],
                'enhancement_pack'               => PluginManifestPolicyDiscovery::communityEnhancementPack(),
                'non_bundled_migration_prefixes' => PluginManifestPolicyDiscovery::communityMigrationBasenamePrefixes(),
                'forbidden_optional_tables'      => PluginManifestPolicyDiscovery::communityForbiddenOptionalTables(),
                'retired_schema_tables'          => [],
                default                          => [],
            };
        }

        if (is_array($configured) && $configured !== []) {
            return array_values(array_map('strval', $configured));
        }

        return $discovered;
    }
}
