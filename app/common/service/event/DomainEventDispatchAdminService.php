<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\event;

use app\common\support\AdminOpsPanelHints;
use app\common\support\ServiceResult;
use app\common\service\event\DomainEventDispatchQueueService;
use app\common\support\QueryLimit;

use app\common\model\CronJob;
use app\common\model\DomainEventDispatchDlq;
use app\common\model\DomainEventDispatchQueue;
use app\common\service\audit\AuditLogService;
use app\common\service\config\ConfigService;
use app\common\support\DbTable;
use app\common\support\ParseIds;

/** 领域事件队列后台运维（面板 / DLQ / 开关） */
final class DomainEventDispatchAdminService
{

    public function __construct(
        private readonly ConfigService $configService,
        private readonly DomainEventDispatchQueueService $domainEventDispatchQueueService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    private const DRAIN_HANDLER = 'domain_event_dispatch_drain';

    /** @return array<string, mixed> */
    public function panel(): array
    {
        $asyncRaw = (string) $this->configService->get('event_bus_async', '0');
        $drain    = $this->drainCronRow();
        $stats    = $this->domainEventDispatchQueueService->queueStats();

        return [
            'async_enabled'      => $this->domainEventDispatchQueueService->enabled(),
            'async_config'       => $asyncRaw,
            'queue_count'        => $stats['pending'],
            'queue_retryable'    => $stats['retryable'],
            'dlq_count'          => $stats['dlq'],
            'queue_alert'        => $stats['alert'],
            'queue_max_attempts' => $stats['max_attempts'],
            'drain_cron_enabled' => $drain !== null && (int) ($drain['status'] ?? 0) === 1,
            'drain_cron_id'      => (int) ($drain['id'] ?? 0),
            'tables_ready'       => $this->domainEventDispatchQueueService->tableExists()
                && $this->domainEventDispatchQueueService->dlqTableExists(),
            'ops'                => $this->opsForAdmin(),
            'hint'               => '开启 event_bus_async 后须启用计划任务「领域事件队列消费」。失败 5 次入 DLQ。',
        ];
    }

    /** @return array{drain_cli:string,dry_run_cli:string,doc:string} */
    public function opsForAdmin(): array
    {
        return AdminOpsPanelHints::drainOps('event_dispatch_drain_cli.php');
    }

    /** @return ServiceResult */
    public function drainQueueForAdmin(int $limit = QueryLimit::QUEUE_DRAIN_EVENT): ServiceResult
    {
        if (!$this->domainEventDispatchQueueService->enabled()) {
            return ServiceResult::fail('异步事件未开启');
        }
        $res   = $this->domainEventDispatchQueueService->drain($limit);
        $stats = $this->domainEventDispatchQueueService->queueStats();

        return ServiceResult::ok(array_merge($res, ['stats' => $stats]), sprintf(
                '已处理 %d 条，失败 %d 条，剩余 %d 条，DLQ %d 条',
                $res['processed'],
                $res['failed'],
                $res['remaining'],
                $res['dlq'],
            ));
    }

    /** @return ServiceResult */
    public function setAsyncEnabled(bool $enabled): ServiceResult
    {
        $this->configService->set('event_bus_async', $enabled ? '1' : '0');
        $this->configService->forgetRequestCache();
        $this->auditLogService->write(
            'admin.cron',
            $enabled ? 'event_bus_async_on' : 'event_bus_async_off',
            'system',
            ['event_bus_async' => $enabled ? '1' : '0'],
            true,
        );

        return ServiceResult::ok(null, $enabled ? '已开启异步事件' : '已关闭异步事件');
    }

    /**
     * @return array{list:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function listDlqAdmin(int $page = 1, int $limit = QueryLimit::ADMIN_PAGE_DEFAULT): array
    {
        if (!$this->domainEventDispatchQueueService->dlqTableExists()) {
            return ['list' => [], 'total' => 0, 'page' => 1, 'limit' => $limit];
        }
        $page  = max(1, $page);
        $limit = min(max($limit, 1), 100);
        $query = DomainEventDispatchDlq::order('id', 'desc');
        $total = (int) $query->count();
        $rows  = $query->page($page, $limit)->select()->toArray();
        $list  = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $list[] = [
                'id'           => (int) ($row['id'] ?? 0),
                'queue_id'     => (int) ($row['queue_id'] ?? 0),
                'event_name'   => (string) ($row['event_name'] ?? ''),
                'attempts'     => (int) ($row['attempts'] ?? 0),
                'last_error'   => (string) ($row['last_error'] ?? ''),
                'failed_at'    => (string) ($row['failed_at'] ?? ''),
                'payload_json' => (string) ($row['payload_json'] ?? ''),
            ];
        }

        return ['list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    /**
     * @param list<int> $ids
     * @return ServiceResult
     */
    public function replayDlqByIds(array $ids): ServiceResult
    {
        $ids = ParseIds::fromMixed($ids);
        if ($ids === []) {
            return ServiceResult::fail('请选择 DLQ 记录');
        }
        $n = $this->domainEventDispatchQueueService->replayFromDlqByIds($ids);
        if ($n > 0) {
            $this->auditLogService->write('admin.cron', 'event_dlq_replay', 'system', ['ids' => $ids, 'count' => $n], true);

            return ServiceResult::ok(['replayed' => $n], '已重放 ' . $n . ' 条');
        }

        return ServiceResult::fail('重放失败或无有效记录', data: ['replayed' => $n]);
    }

    /**
     * @param list<int> $ids
     */
    public function deleteDlqByIds(array $ids): int
    {
        if (!$this->domainEventDispatchQueueService->dlqTableExists()) {
            return 0;
        }
        $ids = ParseIds::fromMixed($ids);
        if ($ids === []) {
            return 0;
        }
        $n = (int) DomainEventDispatchDlq::whereIn('id', $ids)->delete();
        if ($n > 0) {
            $this->auditLogService->write('admin.cron', 'event_dlq_purge', 'system', ['ids' => $ids, 'count' => $n], true);
        }

        return $n;
    }

    public function queueCount(): int
    {
        if (!$this->domainEventDispatchQueueService->tableExists()) {
            return 0;
        }

        return (int) DomainEventDispatchQueue::count();
    }

    /** @return array<string, mixed>|null */
    private function drainCronRow(): ?array
    {
        if (!DbTable::modelExists(CronJob::class)) {
            return null;
        }
        $row = CronJob::where('handler', self::DRAIN_HANDLER)->find();

        return $row ? (is_array($row) ? $row : $row->toArray()) : null;
    }
}
