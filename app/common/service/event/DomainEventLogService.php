<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\event;
use app\common\support\OpsLog;
use app\common\support\AppTime;
use app\common\support\QueryLimit;
use app\common\model\DomainEventLog;

/** 领域事件日志维护（P3） */
final class DomainEventLogService
{

    /**
     * @return int 删除行数
     */
    public function pruneOlderThanDays(int $days, int $limit = QueryLimit::EVENT_LOG_PRUNE_BATCH): int
    {
        if ($days < 7) {
            return 0;
        }
        $limit  = max(100, min(100000, $limit));
        $since  = AppTime::format('Y-m-d H:i:s', time() - $days * 86400);

        try {
            $ids = DomainEventLog::where('created_at', '<', $since)
                ->order('id', 'asc')
                ->limit($limit)
                ->column('id');
            $ids = array_values(array_map('intval', $ids ?: []));
            if ($ids === []) {
                return 0;
            }

            return (int) DomainEventLog::whereIn('id', $ids)->delete();
        } catch (\Throwable $e) {
            OpsLog::businessWarning('domain_event_log_prune_failed', [
                'days'  => $days,
                'limit' => $limit,
                'msg'   => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
