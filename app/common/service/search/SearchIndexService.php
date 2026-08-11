<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\support\ServiceResult;

use app\common\support\QueryLimit;

use app\common\model\Document;
use think\facade\Log;

class SearchIndexService
{

    public function __construct(
        private readonly SearchIndexQueueService $indexQueue,
        private readonly SearchDocumentRecordBuilder $recordBuilder,
        private readonly SearchDriverFactory $driverFactory,
    ) {
    }

    /** 破环：SearchIndexQueueAdminService ↔ 本类 */
    private function queueAdmin(): SearchIndexQueueAdminService
    {
        return app(SearchIndexQueueAdminService::class);
    }

    private function opsAdmin(): SearchOpsAdminService
    {
        return app(SearchOpsAdminService::class);
    }

    private const REINDEX_CHUNK = 100;

    public function syncDocumentById(int $id): void
    {
        if ($id < 1) {
            return;
        }
        if ($this->indexQueue->shouldQueue()) {
            $this->indexQueue->enqueue($id, 'upsert');

            return;
        }
        $this->processUpsert($id);
    }

    /**
     * @param list<int> $ids
     */
    public function syncDocumentsByIds(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return;
        }
        if ($this->indexQueue->shouldQueue()) {
            foreach ($ids as $id) {
                $this->indexQueue->enqueue($id, 'upsert');
            }

            return;
        }
        foreach ($ids as $id) {
            $this->processUpsert($id);
        }
    }

    public function removeDocument(int $id): void
    {
        if ($id < 1) {
            return;
        }
        if ($this->indexQueue->shouldQueue()) {
            $this->indexQueue->enqueue($id, 'delete');

            return;
        }
        $this->processDelete($id);
    }

    /**
     * @param list<int> $ids
     */
    public function removeDocumentsByIds(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return;
        }
        if ($this->indexQueue->shouldQueue()) {
            foreach ($ids as $id) {
                $this->indexQueue->enqueue($id, 'delete');
            }

            return;
        }
        foreach ($ids as $id) {
            $this->processDelete($id);
        }
    }

    /** 队列消费：立即 upsert 或 delete，不再入队 */
    public function processQueueEntry(int $documentId, string $action): void
    {
        if ($documentId < 1) {
            return;
        }
        if ($action === 'delete') {
            $this->processDelete($documentId);

            return;
        }
        $this->processUpsert($documentId);
    }

    private function processUpsert(int $id): void
    {
        $row = $this->documentRow(Document::where('id', $id)->find());
        if ($row === null || !empty($row['deleted_at'])) {
            $this->processDelete($id);

            return;
        }
        $record = $this->recordBuilder->fromRow($row);
        if ($record === null) {
            $this->processDelete($id);

            return;
        }
        try {
            $this->driverFactory->make()->upsert($record);
        } catch (\Throwable $e) {
            Log::warning('search index upsert failed id=' . $id . ' ' . $e->getMessage());
            throw $e;
        }
    }

    private function processDelete(int $id): void
    {
        try {
            $this->driverFactory->make()->delete($id);
        } catch (\Throwable $e) {
            Log::warning('search index delete failed id=' . $id . ' ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return ServiceResult
     */
    public function reindexAll(): ServiceResult
    {
        $driver = $this->driverFactory->make();
        if ($driver->name() === 'sql') {
            return ServiceResult::fail('当前为 SQL 模式，无需重建外部索引');
        }
        if (!$driver->isAvailable()) {
            return ServiceResult::fail('搜索引擎不可用，请检查配置与 Meilisearch 服务');
        }

        $totalCount = 0;
        $lastId     = 0;
        $firstBatch = true;

        try {
            while (true) {
                $ids = Document::where('status', 1)
                    ->whereNull('deleted_at')
                    ->where('id', '>', $lastId)
                    ->order('id', 'asc')
                    ->limit(self::REINDEX_CHUNK)
                    ->column('id');
                $ids = array_values(array_map('intval', $ids ?: []));
                if ($ids === []) {
                    break;
                }
                $records = [];
                foreach ($ids as $id) {
                    $rec = $this->recordBuilder->fromId($id);
                    if ($rec !== null) {
                        $records[] = $rec;
                    }
                    $lastId = $id;
                }
                if ($records !== []) {
                    if ($firstBatch) {
                        $totalCount += $driver->reindexBatch($records);
                        $firstBatch = false;
                    } elseif ($driver instanceof MeilisearchSearchDriver) {
                        $totalCount += $driver->appendBatch($records);
                    } else {
                        foreach ($records as $rec) {
                            $driver->upsert($rec);
                            $totalCount++;
                        }
                    }
                    unset($records);
                }
            }
        } catch (\Throwable $e) {
            return ServiceResult::fail('重建失败：' . $e->getMessage());
        }

        if ($totalCount < 1) {
            return ServiceResult::fail('没有可索引的已发布文档');
        }

        return ServiceResult::ok(['count' => $totalCount, 'driver' => $driver->name()], '已重建索引 ' . $totalCount . ' 条');
    }

    /**
     * @return ServiceResult
     */
    public function engineStatusForAdmin(): ServiceResult
    {
        $name   = $this->driverFactory->driverName();
        $on     = $this->driverAvailableForAdmin($name);
        $stats  = $this->indexQueue->queueStats();
        $msg    = $on ? '连接正常' : ($name === 'sql' ? 'SQL 模式' : '无法连接搜索引擎');
        if ($stats['failed'] > 0) {
            $msg .= '；索引队列 ' . $stats['failed'] . ' 条永久失败';
        } elseif ($stats['retryable'] > 0) {
            $msg .= '；索引队列 ' . $stats['retryable'] . ' 条待重试';
        }

        $data = array_merge([
            'driver'            => $name,
            'on'                => $on,
            'msg'               => $msg,
            'queue_pending'     => $stats['pending'],
            'queue_failed'      => $stats['failed'],
            'queue_retryable'   => $stats['retryable'],
            'queue_fresh'       => $stats['fresh'],
            'queue_alert'       => $stats['alert'],
            'async_index'       => $this->indexQueue->shouldQueue(),
            'queue_max_attempts'=> $stats['max_attempts'],
            'queue_ops'         => $this->queueAdmin()->opsForAdmin(),
        ], $this->opsAdmin()->enrichEngineStatus([
            'queue_alert' => $stats['alert'],
            'driver'      => $name,
            'on'          => $on,
        ]));

        return ServiceResult::ok($data, $msg);
    }

    private function driverAvailableForAdmin(string $driverName): bool
    {
        if ($driverName === SearchConfigService::DRIVER_SQL) {
            return true;
        }
        if ($driverName === SearchConfigService::DRIVER_MEILI) {
            return $this->opsAdmin()->meiliProbeAvailable();
        }

        return $this->driverFactory->make()->isAvailable();
    }

    /**
     * @return ServiceResult
     */
    public function drainQueueForAdmin(int $limit = QueryLimit::QUEUE_DRAIN_INDEX): ServiceResult
    {
        if (!$this->indexQueue->shouldQueue()) {
            return ServiceResult::fail('异步索引未启用或非 Meili/Elastic 驱动');
        }
        $res = $this->indexQueue->drain($limit);
        $stats = $this->indexQueue->queueStats();

        return ServiceResult::ok(array_merge($res, ['stats' => $stats]), sprintf(
                '已处理 %d 条，失败 %d 条，剩余 %d 条',
                $res['processed'],
                $res['failed'],
                $res['remaining'],
            ));
    }

    /**
     * @return ServiceResult
     */
    public function retryFailedQueueForAdmin(): ServiceResult
    {
        if (!$this->indexQueue->tableExists()) {
            return ServiceResult::fail('索引队列表不存在');
        }
        $reset = $this->indexQueue->resetFailed();
        if ($reset < 1) {
            return ServiceResult::fail('没有永久失败的队列项');
        }
        $drain = $this->indexQueue->drain(300);

        return ServiceResult::ok(['reset' => $reset, 'drain' => $drain], '已重置 ' . $reset . ' 条并消费 ' . $drain['processed'] . ' 条');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function documentRow(mixed $found): ?array
    {
        if ($found instanceof Document) {
            return $found->toArray();
        }

        return is_array($found) ? $found : null;
    }
}
