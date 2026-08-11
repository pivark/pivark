<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\license;

use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\manifest\PluginManifestPolicyDiscovery;

/**
 * 专业版及以上域名授权：官方内容增强包插件永久 bundled 授权（SSOT）
 */
final class LicenseBundledEnhancementService
{

    public function __construct(
        private readonly EntitlementService $entitlementService,
    ) {
    }

    public function isProPlusTier(string $tier): bool
    {
        return in_array(strtolower(trim($tier)), ['pro', 'professional', 'enterprise'], true);
    }

    /**
     * @return list<string>
     */
    public function enhancementPackIdentifiers(): array
    {
        $rows = config('pivark.plugin_install_enhancement_pack');
        if (!is_array($rows) || $rows === []) {
            $rows = PluginManifestPolicyDiscovery::enhancementPackCatalogRows();
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = strtolower(trim((string) ($row['id'] ?? '')));
            if ($id !== '') {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * 同步/激活 payload：合并增强包 identifier，便于客户站展示
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public function mergePluginsForEntry(array $entry): array
    {
        $tier = strtolower(trim((string) ($entry['core_tier'] ?? '')));
        if (!$this->isProPlusTier($tier)) {
            return $entry;
        }

        $plugins = $entry['plugins'] ?? [];
        if (!is_array($plugins)) {
            $plugins = [];
        }

        $entry['plugins'] = array_values(array_unique(array_merge(
            array_values(array_filter(array_map(static fn ($v): string => strtolower(trim((string) $v)), $plugins))),
            $this->enhancementPackIdentifiers(),
        )));

        return $entry;
    }

    /**
     * 专业版及以上：增强包永久 bundled grant（expire_at=null）
     *
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    public function grantProPlusBundledEnhancements(array $entry, string $licenseCode): array
    {
        $tier = strtolower(trim((string) ($entry['core_tier'] ?? '')));
        if (!$this->isProPlusTier($tier)) {
            return [];
        }

        $code = strtoupper(trim($licenseCode));
        $ref  = $code !== '' ? 'license:' . $code : 'license:DOMAIN';

        $granted = [];
        foreach ($this->enhancementPackIdentifiers() as $identifier) {
            if ($this->entitlementService->grantWithSkuApply($identifier, null, $ref, 'bundled')) {
                $granted[] = $identifier;
            }
        }

        return $granted;
    }
}
