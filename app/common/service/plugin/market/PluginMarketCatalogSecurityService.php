<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\market;


use app\common\support\AppTime;
use app\common\support\ServiceResult;
use app\common\service\plugin\market\PluginMarketShelfDirectory;
use app\common\service\plugin\market\PluginMarketBlocklistService;
use app\common\service\plugin\commerce\PluginSkuCatalogStorageService;

final class PluginMarketCatalogSecurityService
{
    public function __construct(
        private readonly PluginSkuCatalogStorageService $pluginSkuCatalogStorageService,
        private readonly PluginMarketShelfDirectory $pluginMarketRemoteCatalog,
    ) {
    }

    /**
     * 将本地 blocklist 写入 catalog.json security 段，并标记 plugins[].market_status
     *
     * @return ServiceResult
     */
    public function syncFromLocalBlocklist(): ServiceResult
    {
        if (!(bool) config('plugin.security.sync_blocklist_to_catalog', true)) {
            return ServiceResult::ok(null, 'skip');
        }

        $catalog = $this->pluginSkuCatalogStorageService->loadCatalog();
        if ($catalog === null) {
            $catalog = [
                'updated_at'      => AppTime::format('c'),
                'edition'         => 'community',
                'catalog_version' => (int) config('plugin.sku_defaults.catalog_version', 2),
                'plugins'         => [],
            ];
        }

        $entries = app(PluginMarketBlocklistService::class)->localEntries();
        $catalog['security'] = [
            'blocklist' => [
                'updated_at'  => AppTime::format('c'),
                'identifiers' => $entries,
            ],
        ];

        $plugins = is_array($catalog['plugins'] ?? null) ? $catalog['plugins'] : [];
        foreach ($plugins as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = strtolower(trim((string) ($row['identifier'] ?? '')));
            if ($id === '') {
                continue;
            }
            if (isset($entries[$id])) {
                $row['market_status'] = 'blocked';
                $row['block_reason']  = (string) ($entries[$id]['reason'] ?? '');
                $row['package_url']   = '';
            } elseif (($row['market_status'] ?? '') === 'blocked') {
                unset($row['market_status'], $row['block_reason']);
            }
            $plugins[$idx] = $row;
        }
        $catalog['plugins']    = array_values($plugins);
        $catalog['updated_at'] = AppTime::format('c');

        $save = $this->pluginSkuCatalogStorageService->saveCatalog($catalog);
        if (!$save->isOk()) {
            return ServiceResult::fail($save->message());
        }

        $this->pluginMarketRemoteCatalog->invalidateCache();

        return ServiceResult::ok(null, 'catalog security 已同步');
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    public function remoteBlocklistEntries(): array
    {
        $load = $this->pluginMarketRemoteCatalog->load();
        if (empty($load['ok'])) {
            return [];
        }

        $security = $load['security'] ?? [];
        $block    = is_array($security['blocklist'] ?? null) ? $security['blocklist'] : [];
        $ids      = is_array($block['identifiers'] ?? null) ? $block['identifiers'] : [];
        $out      = [];
        foreach ($ids as $id => $entry) {
            $key = strtolower(trim((string) $id));
            if ($key === '' || !is_array($entry)) {
                continue;
            }
            $out[$key] = $entry;
        }

        foreach ($load['plugins'] as $row) {
            $id = strtolower(trim((string) ($row['identifier'] ?? '')));
            if ($id === '' || ($row['market_status'] ?? '') !== 'blocked') {
                continue;
            }
            $out[$id] = array_merge($out[$id] ?? [], [
                'reason'     => (string) ($row['block_reason'] ?? ($out[$id]['reason'] ?? '运营下架')),
                'blocked_at' => (string) ($out[$id]['blocked_at'] ?? ''),
                'source'     => 'catalog_plugin_row',
            ]);
        }

        return $out;
    }

    /**
     * @return array{reason:string,blocked_at:string,listing_id?:int,operator?:string,source?:string}|null
     */
    public function remoteBlocklistEntry(string $identifier): ?array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return null;
        }
        $entry = $this->remoteBlocklistEntries()[$identifier] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        return $this->normalizeBlocklistEntry($entry);
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{reason:string,blocked_at:string,listing_id?:int,operator?:string,source?:string}
     */
    private function normalizeBlocklistEntry(array $entry): array
    {
        $sev = strtolower(trim((string) ($entry['severity'] ?? '')));
        if (!in_array($sev, ['warn', 'disable', 'uninstall'], true)) {
            $sev = 'disable';
        }
        $out = [
            'reason'      => (string) ($entry['reason'] ?? ''),
            'blocked_at'  => (string) ($entry['blocked_at'] ?? ''),
            'severity'    => $sev,
            'grace_until' => trim((string) ($entry['grace_until'] ?? '')),
        ];
        if (isset($entry['listing_id'])) {
            $out['listing_id'] = (int) $entry['listing_id'];
        }
        if (isset($entry['operator']) && trim((string) $entry['operator']) !== '') {
            $out['operator'] = (string) $entry['operator'];
        }
        if (isset($entry['source']) && trim((string) $entry['source']) !== '') {
            $out['source'] = (string) $entry['source'];
        }

        return $out;
    }
}
