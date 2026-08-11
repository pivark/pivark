<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\support\ServiceResult;

use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\PluginService;
use app\common\model\TagGroup;

/** 保存/启用 site_domains 时校验 tag_group.requires_entitlement */
class SiteDomainEntitlementService
{

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly PluginService $plugins,
    ) {
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    public function normalizeIdentifiers(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $raw)));
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            $id = strtolower(trim((string) $item));
            if ($id !== '' && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function requiredForTagGroup(int $tagGroupId): array
    {
        if ($tagGroupId < 1) {
            return [];
        }
        $row = TagGroup::where('id', $tagGroupId)->find()?->toArray();
        if (!$row) {
            return [];
        }

        return $this->normalizeIdentifiers($row['requires_entitlement'] ?? null);
    }

    /**
     * @return list<string> 未满足的插件 identifier
     */
    public function missingForTagGroup(int $tagGroupId): array
    {
        $missing = [];
        foreach ($this->requiredForTagGroup($tagGroupId) as $identifier) {
            if (!$this->entitlements->can($identifier)) {
                $missing[] = $identifier;
            }
        }

        return $missing;
    }

    /**
     * @return ServiceResult
     */
    public function assertTagGroupEntitled(int $tagGroupId): ServiceResult
    {
        $missing = $this->missingForTagGroup($tagGroupId);
        if ($missing === []) {
            return ServiceResult::ok(null, '');
        }

        $labels = [];
        foreach ($missing as $identifier) {
            $manifest = $this->plugins->readManifest($identifier);
            $labels[] = (string) ($manifest['name'] ?? $identifier);
        }

        return ServiceResult::fail('该标签分组要求已授权插件：' . implode('、', $labels)
                . '。请先在「插件管理」安装并授权后再启用本站托管域。');
    }
}
