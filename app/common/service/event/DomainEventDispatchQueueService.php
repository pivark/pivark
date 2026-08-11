<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\event;

use app\common\support\AppTime;
use app\common\support\QueryLimit;

use app\common\model\DomainEventDispatchQueue;
use app\common\model\DomainEventDispatchDlq;
use app\common\service\config\ConfigService;
use app\common\service\hook\HookService;
use app\common\support\DbTable;
use app\common\support\OpsLog;

/** 领域事件 Hook 异步分发（DB 队列 + cron drain，与 search/static 队列同模式） */
final class DomainEventDispatchQueueService
{

    public function __construct(
        private readonly ConfigService $config,
        private readonly HookService $hooks,
    ) {
    }

    private const MAX_ATTEMPTS = 5;

    /** @var list<string> */
    private const ASYNC_EVENTS = [
        'document.after_save',
        'document.deleted',
        'order.confirmed',
        'payment.fulfilled',
    ];

    public function tableExists(): bool
    {
        return DbTable::modelExists(DomainEventDispatchQueue::class);
    }

    public function dlqTableExists(): bool
    {
        return DbTable::modelExists(DomainEventDispatchDlq::class);
    }

    public function dlqCount(): int
    {
        if (!$this->dlqTableExists()) {
            return 0;
        }

        return (int) DomainEventDispatchDlq::count();
    }

    public function enabled(): bool
    {
        return $this->tableExists()
            && (string) $this->config->get('event_bus_async', '0') === '1';
    }

    public function shouldQueue(string $event): bool
    {
        $event = strtolower(trim($event));

        return $this->enabled() && in_array($event, self::ASYNC_EVENTS, true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(string $event, array $payload): void
    {
        if (!$this->tableExists()) {
            return;
        }
        $event = strtolower(trim($event));
        if ($event === '') {
            return;
        }
        $now     = AppTime::now();
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            $payloadJson = '{}';
        }
        try {
            DomainEventDispatchQueue::insert([
                'event_name'   => $event,
                'payload_json' => $payloadJson,
                'attempts'     => 0,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        } catch (\Throwable $e) {
            OpsLog::businessWarning('domain_event_dispatch_queue enqueue ' . $event . ' ' . $e->getMessage());
        }
    }

    /**
     * @return array{processed:int,failed:int,remaining:int,dlq:int}
     */
    public function drain(int $limit = QueryLimit::QUEUE_DRAIN_EVENT): array
    {
        if (!$this->tableExists()) {
            return ['processed' => 0, 'failed' => 0, 'remaining' => 0, 'dlq' => $this->dlqCount()];
        }
        $limit = max(1, min(2000, $limit));
        $rows  = DomainEventDispatchQueue::where('attempts', '<', self::MAX_ATTEMPTS)
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $processed = 0;
        $failed    = 0;
        foreach ($rows as $row) {
            $qid   = (int) ($row['id'] ?? 0);
            $event = (string) ($row['event_name'] ?? '');
            if ($qid < 1 || $event === '') {
                continue;
            }
            // 乐观认领：CAS 抬 attempts，避免并行 worker 重复消费
            $prevAttempts = (int) ($row['attempts'] ?? 0);
            $claimed      = (int) DomainEventDispatchQueue::where('id', $qid)
                ->where('attempts', $prevAttempts)
                ->update([
                    'attempts'   => $prevAttempts + 1,
                    'updated_at' => AppTime::now(),
                ]);
            if ($claimed < 1) {
                continue;
            }
            $attempts = $prevAttempts + 1;
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true) ?: [];
            try {
                $this->hooks->fire($event, $payload);
                DomainEventDispatchQueue::where('id', $qid)->delete();
                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $this->moveToDlq($row, $e->getMessage(), $attempts);
                    DomainEventDispatchQueue::where('id', $qid)->delete();
                } else {
                    DomainEventDispatchQueue::where('id', $qid)->update([
                        'last_error' => mb_substr($e->getMessage(), 0, 255),
                        'updated_at' => AppTime::now(),
                    ]);
                }
            }
        }

        return [
            'processed' => $processed,
            'failed'    => $failed,
            'remaining' => (int) DomainEventDispatchQueue::count(),
            'dlq'       => $this->dlqCount(),
        ];
    }

    /**
     * 将 DLQ 行重新入队（attempts 归零），并删除 DLQ 记录。
     *
     * @param list<int> $ids
     */
    public function replayFromDlqByIds(array $ids): int
    {
        if (!$this->tableExists() || !$this->dlqTableExists()) {
            return 0;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }
        $rows = DomainEventDispatchDlq::whereIn('id', $ids)->select()->toArray();
        $now  = AppTime::now();
        $n    = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $dlqId = (int) ($row['id'] ?? 0);
            $event = strtolower(trim((string) ($row['event_name'] ?? '')));
            if ($dlqId < 1 || $event === '') {
                continue;
            }
            $payloadJson = (string) ($row['payload_json'] ?? '{}');
            if ($payloadJson === '') {
                $payloadJson = '{}';
            }
            try {
                DomainEventDispatchQueue::insert([
                    'event_name'   => $event,
                    'payload_json' => $payloadJson,
                    'attempts'     => 0,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
                DomainEventDispatchDlq::where('id', $dlqId)->delete();
                $n++;
            } catch (\Throwable $e) {
                OpsLog::businessWarning('domain_event_dispatch_dlq replay ' . $dlqId . ' ' . $e->getMessage());
            }
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function moveToDlq(array $row, string $error, int $attempts): void
    {
        if (!$this->dlqTableExists()) {
            return;
        }
        $payloadJson = (string) ($row['payload_json'] ?? '{}');
        try {
            DomainEventDispatchDlq::insert([
                'queue_id'     => (int) ($row['id'] ?? 0),
                'event_name'   => (string) ($row['event_name'] ?? ''),
                'payload_json' => $payloadJson,
                'attempts'     => $attempts,
                'last_error'   => mb_substr($error, 0, 255),
                'failed_at'    => AppTime::now(),
            ]);
        } catch (\Throwable $e) {
            OpsLog::businessWarning('domain_event_dispatch_dlq move ' . $e->getMessage());
        }
    }

    public function maxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    /**
     * @return array{pending:int,retryable:int,dlq:int,max_attempts:int,alert:bool}
     */
    public function queueStats(): array
    {
        $dlq = $this->dlqCount();
        if (!$this->tableExists()) {
            return [
                'pending'      => 0,
                'retryable'    => 0,
                'dlq'          => $dlq,
                'max_attempts' => self::MAX_ATTEMPTS,
                'alert'        => $dlq > 0,
            ];
        }

        $pending   = (int) DomainEventDispatchQueue::count();
        $retryable = (int) DomainEventDispatchQueue::where('attempts', '>', 0)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->count();

        return [
            'pending'      => $pending,
            'retryable'    => $retryable,
            'dlq'          => $dlq,
            'max_attempts' => self::MAX_ATTEMPTS,
            'alert'        => $dlq > 0 || $retryable >= 10 || $pending >= 100,
        ];
    }
}
