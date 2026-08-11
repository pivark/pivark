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
use app\common\model\SearchIndexQueue;

use think\facade\Log;

/** 文档搜索索引异步队列（P3：写路径解耦 Meili/Elastic，可水平扩 cron worker） */
final class SearchIndexQueueService
{

    public function __construct(
        private readonly SearchConfigService $searchConfig,
        private readonly SearchDriverFactory $driverFactory,
    ) {
    }

    private function searchIndex(): SearchIndexService
    {
        return app(SearchIndexService::class);
    }

    private const MAX_ATTEMPTS = 5;

    public function tableExists(): bool
    {
        return DbTable::modelExists(SearchIndexQueue::class);
    }

    public function shouldQueue(): bool
    {
        if (!$this->tableExists()) {
            return false;
        }
        if (!$this->searchConfig->asyncIndexEnabled()) {
            return false;
        }
        $driver = $this->driverFactory->driverName();

        return $driver === SearchConfigService::DRIVER_MEILI || $driver === SearchConfigService::DRIVER_ELASTIC;
    }

    public function enqueue(int $documentId, string $action = 'upsert'): void
    {
        if ($documentId < 1 || !$this->tableExists()) {
            return;
        }
        $action = $action === 'delete' ? 'delete' : 'upsert';
        $now    = AppTime::now();
        try {
            $row = SearchIndexQueue::where('document_id', $documentId)
                ->where('action', $action)
                ->find();
            if ($row instanceof SearchIndexQueue) {
                SearchIndexQueue::where('id', (int) $row->getAttr('id'))->update([
                    'attempts'   => 0,
                    'last_error' => null,
                    'updated_at' => $now,
                ]);
            } else {
                SearchIndexQueue::insert([
                    'document_id' => $documentId,
                    'action'      => $action,
                    'attempts'    => 0,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('search_index_queue enqueue failed id=' . $documentId . ' ' . $e->getMessage());
        }
    }

    /**
     * @return array{processed:int,failed:int,remaining:int}
     */
    public function drain(int $limit = QueryLimit::QUEUE_DRAIN_INDEX): array
    {
        if (!$this->tableExists()) {
            return ['processed' => 0, 'failed' => 0, 'remaining' => 0];
        }
        $limit = max(1, min(2000, $limit));
        $rows  = SearchIndexQueue::where('attempts', '<', self::MAX_ATTEMPTS)
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $processed = 0;
        $failed    = 0;
        foreach ($rows as $row) {
            $qid    = (int) ($row['id'] ?? 0);
            $docId  = (int) ($row['document_id'] ?? 0);
            $action = (string) ($row['action'] ?? 'upsert');
            if ($qid < 1 || $docId < 1) {
                continue;
            }
            try {
                $this->searchIndex()->processQueueEntry($docId, $action);
                SearchIndexQueue::where('id', $qid)->delete();
                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                SearchIndexQueue::where('id', $qid)->update([
                    'attempts'   => (int) ($row['attempts'] ?? 0) + 1,
                    'last_error' => mb_substr($e->getMessage(), 0, 255),
                    'updated_at' => AppTime::now(),
                ]);
            }
        }

        $remaining = (int) SearchIndexQueue::count();

        return ['processed' => $processed, 'failed' => $failed, 'remaining' => $remaining];
    }

    public function maxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    /**
     * @return array{pending:int,fresh:int,retryable:int,failed:int,max_attempts:int,alert:bool}
     */
    public function queueStats(): array
    {
        if (!$this->tableExists()) {
            return [
                'pending'      => 0,
                'fresh'        => 0,
                'retryable'    => 0,
                'failed'       => 0,
                'max_attempts' => self::MAX_ATTEMPTS,
                'alert'        => false,
            ];
        }

        $pending   = (int) SearchIndexQueue::count();
        $fresh     = (int) SearchIndexQueue::where('attempts', 0)->count();
        $failed    = (int) SearchIndexQueue::where('attempts', '>=', self::MAX_ATTEMPTS)->count();
        $retryable = (int) SearchIndexQueue::where('attempts', '>', 0)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->count();

        return [
            'pending'      => $pending,
            'fresh'        => $fresh,
            'retryable'    => $retryable,
            'failed'       => $failed,
            'max_attempts' => self::MAX_ATTEMPTS,
            'alert'        => $failed > 0 || $retryable >= 50 || $pending >= 500,
            'pending_alert_threshold' => 500,
        ];
    }

    public function resetFailed(): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        return (int) SearchIndexQueue::where('attempts', '>=', self::MAX_ATTEMPTS)->update([
            'attempts'   => 0,
            'last_error' => null,
            'updated_at' => AppTime::now(),
        ]);
    }

    /**
     * 删除长期无法消费的死信（attempts 已达上限且超过 N 天未更新）。
     */
    public function pruneDeadLettersOlderThanDays(int $days, int $batchLimit = 2000): int
    {
        if (!$this->tableExists()) {
            return 0;
        }
        $days       = max(7, min(365, $days));
        $batchLimit = max(50, min(20000, $batchLimit));
        $cutoff     = AppTime::format('Y-m-d H:i:s', time() - $days * 86400);
        $ids        = SearchIndexQueue::where('attempts', '>=', self::MAX_ATTEMPTS)
            ->where('updated_at', '<', $cutoff)
            ->order('id', 'asc')
            ->limit($batchLimit)
            ->column('id');
        $ids = array_values(array_map('intval', $ids ?: []));
        if ($ids === []) {
            return 0;
        }

        return (int) SearchIndexQueue::whereIn('id', $ids)->delete();
    }
}
