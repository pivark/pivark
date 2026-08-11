<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\manifest;

use app\common\support\ProjectPaths;

/**
 * 从 weapp/{id}/plugin.json 的 pivark_policy 段发现安装/发行策略（内核禁止写死 identifier）。
 *
 * @see docs/06-插件/插件开发规范.md
 */
final class PluginManifestPolicyDiscovery
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $manifestCache = null;

    public static function resetCache(): void
    {
        self::$manifestCache = null;
    }

    /** @return list<string> */
    public static function installDefaultIdentifiers(): array
    {
        $out = [];
        foreach (self::manifests() as $id => $manifest) {
            $policy = self::policyBlock($manifest);
            if (!empty($policy['install_default']) || !empty($policy['enhancement_pack']['default'])) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    public static function uploadReplaceProtectedIdentifiers(): array
    {
        $out = [];
        foreach (self::manifests() as $id => $manifest) {
            if (!empty(self::policyBlock($manifest)['upload_replace_protected'])) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    public static function entitlementMirrorIdentifiers(): array
    {
        $out = [];
        foreach (self::manifests() as $id => $manifest) {
            if (!empty(self::policyBlock($manifest)['entitlement_mirror'])) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    public static function schemaPilotIdentifiers(): array
    {
        $out = [];
        foreach (self::manifests() as $id => $manifest) {
            if (!empty(self::policyBlock($manifest)['schema_pilot'])) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<array{id:string,name:string,hint:string,default:bool}>
     */
    public static function enhancementPackCatalogRows(): array
    {
        $out = [];
        foreach (self::manifests() as $id => $manifest) {
            $pack = self::policyBlock($manifest)['enhancement_pack'] ?? null;
            if (!is_array($pack) || empty($pack['enabled'])) {
                $community = self::communityBlock($manifest);
                if (empty($community['enhancement_pack'])) {
                    continue;
                }
                $pack = ['default' => true];
            }
            $out[] = [
                'id'      => $id,
                'name'    => trim((string) ($manifest['name'] ?? $id)),
                'hint'    => trim((string) ($pack['hint'] ?? $manifest['description'] ?? '')),
                'default' => !empty($pack['default']),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function taxonomyOverride(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        $manifest   = self::manifests()[$identifier] ?? null;
        if (!is_array($manifest)) {
            return [];
        }
        $policy = self::policyBlock($manifest)['taxonomy'] ?? null;
        if (is_array($policy) && $policy !== []) {
            return $policy;
        }
        $commercial = is_array($manifest['commercial'] ?? null) ? $manifest['commercial'] : [];
        $out        = [];
        foreach (['commercial_tier', 'business_domain', 'editor_audience', 'nav_bucket', 'layout_trigger'] as $field) {
            if (array_key_exists($field, $commercial)) {
                $out[$field] = $commercial[$field];
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function communityBundledPlainWeapp(): array
    {
        $out = [];
        foreach (self::manifests() as $id => $manifest) {
            if (!empty(self::communityBlock($manifest)['bundled_plain'])) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    public static function communityEnhancementPack(): array
    {
        $out = [];
        foreach (self::manifests() as $id => $manifest) {
            $policy = self::policyBlock($manifest);
            if (!empty($policy['enhancement_pack']['enabled']) || !empty(self::communityBlock($manifest)['enhancement_pack'])) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /** distribution.mode=source_open 的标装 identifier（Community 明文 weapp；空则未配置） */
    public static function sourceOpenIdentifier(): string
    {
        foreach (self::manifests() as $id => $manifest) {
            $dist = is_array($manifest['distribution'] ?? null) ? $manifest['distribution'] : [];
            if (strtolower(trim((string) ($dist['mode'] ?? ''))) === 'source_open') {
                return $id;
            }
        }

        return '';
    }

    /** @return list<string> */
    public static function communityMigrationBasenamePrefixes(): array
    {
        $out = [];
        foreach (self::manifests() as $id => $manifest) {
            $prefix = trim((string) (self::communityBlock($manifest)['migration_basename_prefix'] ?? ''));
            if ($prefix !== '') {
                $out[] = $prefix;
                continue;
            }
            $out = array_merge($out, self::scanMigrationPrefixes($id));
        }

        return array_values(array_unique(array_filter($out)));
    }

    /** @return list<string> */
    public static function communityForbiddenOptionalTables(): array
    {
        $out = [];
        foreach (self::manifests() as $id => $manifest) {
            $tables = self::communityBlock($manifest)['forbidden_optional_tables'] ?? null;
            if (is_array($tables)) {
                foreach ($tables as $table) {
                    $table = trim((string) $table);
                    if ($table !== '') {
                        $out[] = $table;
                    }
                }
                continue;
            }
            $out = array_merge($out, self::scanInstallSqlTables($id));
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private static function policyBlock(array $manifest): array
    {
        $policy = $manifest['pivark_policy'] ?? null;

        return is_array($policy) ? $policy : [];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private static function communityBlock(array $manifest): array
    {
        $policy    = self::policyBlock($manifest);
        $community = $policy['community_release'] ?? null;
        if (!is_array($community)) {
            $community = [];
        }

        // 仅认显式 pivark_policy.community_release.bundled_plain；
        // 禁止用 distribution.mode=source_open 自动抬升（否则 shop 等付费包会误入 Community 明文 zip）
        return $community;
    }

    /** @return array<string, array<string, mixed>> identifier => manifest */
    private static function manifests(): array
    {
        if (self::$manifestCache !== null) {
            return self::$manifestCache;
        }
        $out  = [];
        $root = ProjectPaths::root() . 'weapp/';
        if (!is_dir($root)) {
            return self::$manifestCache = [];
        }
        foreach (glob($root . '*/plugin.json') ?: [] as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $raw = file_get_contents($path);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }
            $id = strtolower(trim((string) ($data['identifier'] ?? '')));
            if ($id === '') {
                continue;
            }
            $out[$id] = $data;
        }

        return self::$manifestCache = $out;
    }

    /** @return list<string> */
    private static function scanMigrationPrefixes(string $identifier): array
    {
        $dir = ProjectPaths::root() . 'weapp/' . $identifier . '/database/migrations/';
        if (!is_dir($dir)) {
            return [];
        }
        $prefixes = [];
        foreach (glob($dir . 'migrate_*.php') ?: [] as $file) {
            $base = basename($file, '.php');
            if (preg_match('/^(migrate_[a-z0-9_]+_)/', $base, $m)) {
                $prefixes[] = $m[1];
            }
        }

        return array_values(array_unique($prefixes));
    }

    /** @return list<string> */
    private static function scanInstallSqlTables(string $identifier): array
    {
        $sqlFile = ProjectPaths::root() . 'weapp/' . $identifier . '/database/install.sql';
        if (!is_readable($sqlFile)) {
            return [];
        }
        $body = (string) file_get_contents($sqlFile);
        if (!preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?\{\{prefix\}\}([a-z0-9_]+)/i', $body, $m)) {
            return [];
        }
        $community = self::communityBlock(self::manifests()[$identifier] ?? []);
        if (empty($community['enhancement_pack']) && empty(self::policyBlock(self::manifests()[$identifier] ?? [])['enhancement_pack'])) {
            return [];
        }

        return array_values(array_unique($m[1]));
    }

    /**
     * 合并 .env / config 显式列表与 manifest 发现结果（显式非空时优先）。
     *
     * @param list<string>|mixed $configured
     * @param list<string>       $discovered
     * @return list<string>
     */
    public static function mergeIdentifierLists(mixed $configured, array $discovered): array
    {
        if (is_array($configured) && $configured !== []) {
            return array_values(array_filter(array_map(
                static fn ($id): string => strtolower(trim((string) $id)),
                $configured,
            )));
        }

        return $discovered;
    }
}
