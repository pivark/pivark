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
use app\common\support\QueryLimit;
use app\common\support\DbTable;
use app\common\model\SearchClickLog;

use think\facade\Db;

/** 搜索结果点击日志（P4） */
final class SmartSearchClickService
{

    public function __construct(
        private readonly SearchConfigService $searchConfig,
    ) {
    }

    public function tableExists(): bool
    {
        return DbTable::modelExists(SearchClickLog::class);
    }

    public function log(string $keyword, string $targetType, int $targetId, string $targetUrl = ''): void
    {
        if (!$this->tableExists()) {
            return;
        }
        $blocked = $this->searchConfig->guardKeyword($keyword);
        if ($blocked !== null) {
            return;
        }
        $keyword = mb_substr(trim($keyword), 0, 240);
        if ($keyword === '') {
            return;
        }
        $type = mb_substr(trim($targetType), 0, 32);
        if ($type === '') {
            $type = 'link';
        }
        SearchClickLog::insert([
            'keyword'     => $keyword,
            'target_type' => $type,
            'target_id'   => max(0, $targetId),
            'target_url'  => mb_substr(trim($targetUrl), 0, 512),
            'created_at'  => AppTime::now(),
        ]);
    }

    /**
     * 近 N 天同关键词下品项点击次数（用于排序加权）
     *
     * @return array<int, int> item_id => clicks
     */
    public function productClickScores(string $keyword, int $days = 30): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }
        $since = AppTime::format('Y-m-d H:i:s', time() - max(1, $days) * 86400);
        $rows  = SearchClickLog::where('keyword', $keyword)
            ->where('target_type', 'product')
            ->where('target_id', '>', 0)
            ->where('created_at', '>=', $since)
            ->field('target_id, COUNT(*) AS cnt')
            ->group('target_id')
            ->limit(QueryLimit::CLICK_LOG_TOP)
            ->select()
            ->toArray();
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['target_id'] ?? 0);
            if ($id > 0) {
                $out[$id] = (int) ($row['cnt'] ?? 0);
            }
        }

        return $out;
    }
}
