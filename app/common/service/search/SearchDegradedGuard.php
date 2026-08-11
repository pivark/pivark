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

/**
 * 外部搜索引擎（Meili/Elastic）不可用回退 SQL 时的降级保护，避免 LIKE 全表扫拖垮 MySQL。
 */
final class SearchDegradedGuard
{

    public function __construct(
        private readonly SearchConfigService $searchConfig,
        private readonly SearchFulltextSupport $fulltext,
    ) {
    }

    private const CACHE_KEY = 'pv_search_external_degraded_until';
    private const FAIL_STREAK_KEY = 'pv_search_external_degraded_streak';

    public function markExternalUnavailable(): void
    {
        $base = max(30, (int) config('pivark.search_meili_fallback_cooldown', 120));
        $streak = max(1, (int) Cache::get(self::FAIL_STREAK_KEY) + 1);
        Cache::set(self::FAIL_STREAK_KEY, $streak, 86400);
        $multiplier = min(16, 2 ** min($streak - 1, 4));
        $cooldown   = min($base * $multiplier, 3600);
        Cache::set(self::CACHE_KEY, time() + $cooldown, $cooldown + 30);
    }

    public function markExternalRecovered(): void
    {
        Cache::delete(self::CACHE_KEY);
        Cache::delete(self::FAIL_STREAK_KEY);
    }

    public function isDegraded(): bool
    {
        $until = Cache::get(self::CACHE_KEY);
        if ($until === null || $until === false) {
            return false;
        }

        return (int) $until > time();
    }

    /** Meili 回退 SQL 期间：智能模糊且无 FULLTEXT 时拒搜；标题分词/完全匹配仍允许 SQL */
    public function shouldRejectSqlFallback(string $keyword): bool
    {
        if (!$this->isDegraded()) {
            return false;
        }
        $keyword = trim($keyword);
        if ($keyword === '') {
            return false;
        }
        $mode = $this->searchConfig->mode();
        if ($mode === SearchConfigService::MODE_TITLE_SEG || $mode === SearchConfigService::MODE_TITLE_EXACT) {
            return false;
        }
        if (!$this->fulltext->useFulltext()) {
            return true;
        }

        return mb_strlen($keyword) < 2;
    }
}
