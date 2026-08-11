<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use think\facade\Cache;

/** 搜索运维面板：Meili 降级、品项目录驱动、队列告警 SSOT */
final class SearchOpsAdminService
{

    public function __construct(
        private readonly ItemCatalogSearchDriverFactory $itemDriverFactory,
        private readonly SearchDegradedGuard $degradedGuard,
        private readonly MeilisearchItemIndex $meiliItemIndex,
        private readonly SearchConfigService $searchConfig,
    ) {
    }

    /** 后台 meta 探活：短超时 + 请求内 memo，避免 Meili 未启动时阻塞 SPA */
    public function meiliProbeAvailable(): bool
    {
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }
        $cfg = $this->searchConfig->meiliConfig();
        if ($cfg['host'] === '') {
            return $memo = false;
        }

        return $memo = MeilisearchHttpClient::probeHealth($cfg['host'], $cfg['key']);
    }

    /**
     * @param array<string, mixed> $engineBase SearchIndexService::engineStatusForAdmin 基础字段
     * @return array<string, mixed>
     */
    public function enrichEngineStatus(array $engineBase): array
    {
        $configuredItem = $this->itemDriverFactory->configuredDriver();
        $primaryName    = $configuredItem;
        if ($primaryName === ItemCatalogSearchDriverFactory::DRIVER_MEILI) {
            $primaryAvailable = $this->meiliProbeAvailable();
        } else {
            $this->itemDriverFactory->reset();
            $primaryAvailable = $this->itemDriverFactory->make()->isAvailable();
            $this->itemDriverFactory->reset();
        }
        $effectiveItem = ($primaryName === ItemCatalogSearchDriverFactory::DRIVER_SQL || $primaryAvailable)
            ? $primaryName
            : ItemCatalogSearchDriverFactory::DRIVER_SQL;

        $degraded      = $this->degradedGuard->isDegraded();
        $degradedUntil = Cache::get('pv_search_external_degraded_until');
        $itemMeiliOn   = $configuredItem !== ItemCatalogSearchDriverFactory::DRIVER_MEILI
            ? false
            : $this->meiliProbeAvailable();
        $queueAlert    = !empty($engineBase['queue_alert']);

        $hints = [];
        if ($degraded) {
            $until = is_numeric($degradedUntil) ? (int) $degradedUntil : 0;
            $hints[] = $until > time()
                ? '外部搜索引擎降级中（约 ' . max(1, $until - time()) . 's 内拒无 FULLTEXT 的 SQL 回退）'
                : '外部搜索引擎降级中';
        }
        if ($configuredItem === ItemCatalogSearchDriverFactory::DRIVER_MEILI && !$itemMeiliOn) {
            $hints[] = '品项目录 Meili 未连通，关键词检索已回退或受限';
        }
        if ($configuredItem === ItemCatalogSearchDriverFactory::DRIVER_MEILI
            && $effectiveItem === ItemCatalogSearchDriverFactory::DRIVER_SQL) {
            $hints[] = '品项目录实际驱动：SQL（配置为 Meili）';
        }
        if ($queueAlert) {
            $hints[] = '索引异步队列积压或失败项需处理';
        }

        $opsAlert = $queueAlert
            || $degraded
            || ($configuredItem === ItemCatalogSearchDriverFactory::DRIVER_MEILI && !$itemMeiliOn);

        return [
            'degraded'               => $degraded,
            'degraded_until'         => is_numeric($degradedUntil) ? (int) $degradedUntil : 0,
            'item_catalog_driver'    => $configuredItem,
            'item_catalog_effective' => $effectiveItem,
            'item_meili_on'          => $itemMeiliOn,
            'ops_alert'              => $opsAlert,
            'ops_hints'              => $hints,
        ];
    }
}
