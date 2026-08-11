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
use app\common\support\DbTable;
use app\common\model\SearchQueryLog;

use think\facade\Db;

/** 搜索词统计 / 无结果词（P5） */
final class SmartSearchAnalyticsService
{

    public function __construct(
        private readonly SearchConfigService $searchConfig,
    ) {
    }

    public function tableExists(): bool
    {
        return DbTable::modelExists(SearchQueryLog::class);
    }

    /**
     * @param array<string, mixed> $stats
     */
    public function log(string $keyword, array $stats): void
    {
        if (!$this->tableExists()) {
            return;
        }
        $keyword = mb_substr(trim($keyword), 0, 240);
        if ($keyword === '') {
            return;
        }
        $productCount = (int) ($stats['product_count'] ?? 0);
        $docCount     = (int) ($stats['doc_count'] ?? 0);
        $hitCount     = $productCount + $docCount + (int) ($stats['source_count'] ?? 0);
        SearchQueryLog::insert([
            'keyword'        => $keyword,
            'hit_count'      => $hitCount,
            'product_count'  => $productCount,
            'doc_count'      => $docCount,
            'mode'           => mb_substr((string) ($stats['mode'] ?? $this->searchConfig->mode()), 0, 32),
            'zero_result'    => $hitCount < 1 ? 1 : 0,
            'created_at'     => AppTime::now(),
        ]);
    }
}
