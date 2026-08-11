<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;
use app\common\support\AdminOpsPanelHints;
use app\common\support\QueryLimit;
use app\common\support\ServiceResult;

use app\common\model\CronJob;
use app\common\service\config\ConfigService;
use app\common\support\DbTable;

/** Meili/Elastic 索引队列后台运维面板 */
final class SearchIndexQueueAdminService
{

    public function __construct(
        private readonly SearchIndexQueueService $indexQueue,
        private readonly SearchDriverFactory $driverFactory,
        private readonly SearchIndexService $searchIndexService,
    ) {
    }

    private const DRAIN_HANDLER = 'search_index_queue_drain';

    /** @return array<string, mixed> */
    public function panel(): array
    {
        $stats = $this->indexQueue->queueStats();
        $drain = $this->drainCronRow();

        return array_merge($stats, [
            'async_enabled'      => $this->indexQueue->shouldQueue(),
            'driver'             => $this->driverFactory->driverName(),
            'drain_cron_enabled' => $drain !== null && (int) ($drain['status'] ?? 0) === 1,
            'drain_cron_id'      => (int) ($drain['id'] ?? 0),
            'tables_ready'       => $this->indexQueue->tableExists(),
            'ops'                => $this->opsForAdmin(),
            'hint'               => 'search_async_index=1 且 Meili/Elastic 驱动时写路径入队；计划任务或 CLI drain。',
        ]);
    }

    /** @return array{drain_cli:string,dry_run_cli:string,doc:string} */
    public function opsForAdmin(): array
    {
        return AdminOpsPanelHints::drainOps('meili_index_drain_cli.php');
    }

    public function drainQueueForAdmin(int $limit = QueryLimit::QUEUE_DRAIN_INDEX): ServiceResult
    {
        return $this->searchIndexService->drainQueueForAdmin($limit);
    }

    /** @return array<string, mixed>|null */
    private function drainCronRow(): ?array
    {
        if (!DbTable::modelExists(CronJob::class)) {
            return null;
        }
        $row = CronJob::where('handler', self::DRAIN_HANDLER)->find();
        if ($row instanceof CronJob) {
            return $row->toArray();
        }

        return is_array($row) ? $row : null;
    }
}
