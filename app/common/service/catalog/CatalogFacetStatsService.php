<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\catalog;

use app\common\support\AppTime;
use app\common\support\DbTable;
use app\common\model\CatalogFacetStat;

use think\facade\Cache;
use think\facade\Db;

/** 跨域 Facet 物化计数（items / erp / plugin_offers …） */
final class CatalogFacetStatsService
{

    public function __construct(
        private readonly CatalogListCacheService $listCache,
    ) {
    }

    public const DOMAIN_ITEMS = 'items';

    public function tableExists(): bool
    {
        return DbTable::modelExists(CatalogFacetStat::class);
    }

    /**
     * @param list<string> $paramKeys
     */
    public function rebuildForParamKeys(string $domain, string $scopeKey, array $paramKeys, callable $aggregate): void
    {
        if (!$this->tableExists()) {
            return;
        }
        $domain   = strtolower(trim($domain));
        $scopeKey = trim($scopeKey);
        $paramKeys = array_values(array_filter(array_map(static function ($k): string {
            $k = strtolower(trim((string) $k));

            return preg_match('/^[a-z0-9_]+$/', $k) ? $k : '';
        }, $paramKeys)));
        if ($domain === '' || $paramKeys === []) {
            return;
        }

        $now = AppTime::now();
        foreach ($paramKeys as $paramKey) {
            /** @var array<string, int> $counts */
            $counts = $aggregate($paramKey);
            CatalogFacetStat::where('domain', $domain)
                ->where('scope_key', $scopeKey)
                ->where('param_key', $paramKey)
                ->delete();
            foreach ($counts as $val => $cnt) {
                $val = trim((string) $val);
                if ($val === '') {
                    continue;
                }
                CatalogFacetStat::insert([
                    'domain'     => $domain,
                    'scope_key'  => mb_substr($scopeKey, 0, 64),
                    'param_key'  => $paramKey,
                    'attr_value' => mb_substr($val, 0, 255),
                    'row_count'  => max(0, (int) $cnt),
                    'updated_at' => $now,
                ]);
            }
        }
        $this->bump();
    }

    /** @return array<string, int> */
    public function counts(string $domain, string $scopeKey, string $paramKey): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        $paramKey = strtolower(trim($paramKey));
        if ($paramKey === '' || !preg_match('/^[a-z0-9_]+$/', $paramKey)) {
            return [];
        }

        $rows = CatalogFacetStat::where('domain', strtolower(trim($domain)))
            ->where('scope_key', trim($scopeKey))
            ->where('param_key', $paramKey)
            ->where('row_count', '>', 0)
            ->order('attr_value', 'asc')
            ->column('row_count', 'attr_value');

        $out = [];
        foreach ($rows ?: [] as $val => $cnt) {
            $val = trim((string) $val);
            if ($val !== '') {
                $out[$val] = max(0, (int) $cnt);
            }
        }

        return $out;
    }

    public function bump(): void
    {
        Cache::inc('pv_catalog_facet_stats_gen');
        $this->listCache->bump(null);
    }

    public function generation(): int
    {
        $g = Cache::get('pv_catalog_facet_stats_gen');

        return is_numeric($g) ? max(1, (int) $g) : 1;
    }
}
