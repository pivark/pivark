<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\support\AppTime;
use app\common\model\SearchQueryLog;

use think\facade\Db;

/** 搜索词日志查询（无结果运营） */
final class SearchQueryLogService
{

    public function __construct(
        private readonly SmartSearchAnalyticsService $analytics,
    ) {
    }

    /**
     * @return array{list:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function listAdmin(int $page = 1, int $limit = 20, bool $zeroOnly = false): array
    {
        if (!$this->analytics->tableExists()) {
            return ['list' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
        }
        $page  = max(1, $page);
        $limit = max(1, min(100, $limit));
        $query = SearchQueryLog::order('id', 'desc');
        if ($zeroOnly) {
            $query->where('zero_result', 1);
        }
        $total = (int) $query->count();
        /** @var list<array<string, mixed>> $rows */
        $rows = $query->page($page, $limit)->select()->toArray();

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    /**
     * @return list<array{keyword:string,cnt:int}>
     */
    public function topZeroKeywords(int $days = 7, int $limit = 30): array
    {
        if (!$this->analytics->tableExists()) {
            return [];
        }
        $since = AppTime::format('Y-m-d H:i:s', time() - max(1, $days) * 86400);
        $rows  = SearchQueryLog::where('zero_result', 1)
            ->where('created_at', '>=', $since)
            ->field('keyword, COUNT(*) AS cnt')
            ->group('keyword')
            ->order('cnt', 'desc')
            ->limit(max(1, min(100, $limit)))
            ->select()
            ->toArray();
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'keyword' => (string) ($row['keyword'] ?? ''),
                'cnt'     => (int) ($row['cnt'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * 分批删除过期搜索词日志（保留近 N 天运营分析）。
     */
    public function pruneOlderThanDays(int $days, int $batchLimit = 10000): int
    {
        if (!$this->analytics->tableExists()) {
            return 0;
        }
        $days       = max(30, min(730, $days));
        $batchLimit = max(100, min(50000, $batchLimit));
        $cutoff     = AppTime::format('Y-m-d H:i:s', time() - $days * 86400);
        $ids        = SearchQueryLog::where('created_at', '<', $cutoff)
            ->order('id', 'asc')
            ->limit($batchLimit)
            ->column('id');
        $ids = array_values(array_map('intval', $ids ?: []));
        if ($ids === []) {
            return 0;
        }

        return (int) SearchQueryLog::whereIn('id', $ids)->delete();
    }
}
