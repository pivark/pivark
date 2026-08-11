<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 元舟 PivArk — 定时任务调度
 */
declare(strict_types=1);

namespace app\common\service\cron;

use app\common\support\AppTime;
use app\common\support\QueryLimit;
use app\common\support\ServiceResult;
use app\common\model\StatsHit;
use app\common\model\AuditLog;





use app\common\service\audit\AuditLogService;
use app\common\service\audit\LogArchiveService;
use app\common\service\event\DomainEventLogService;
use app\common\service\event\DomainEventDispatchQueueService;
use app\common\service\media\MediaOrphanQueueService;
use app\common\service\search\SearchIndexQueueService;
use app\common\service\search\SearchQueryLogService;
use app\common\support\LocalFile;
use app\common\service\document\satellite\DocumentScheduleService;
use app\common\service\infra\BackupService;
use app\common\service\payment\PaymentConfigService;
use app\common\service\payment\PaymentOrderService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\market\PluginMarketSecuritySyncService;
use app\common\service\plugin\market\PluginMarketAcquireReliabilityService;
use app\common\service\seo\SitemapService;
use app\common\service\site\SiteFormService;
use app\common\service\plugin\PluginService;
use app\common\service\static\StaticBuildQueueService;
use app\common\service\static\StaticHtmlBatchService;
use app\common\service\static\StaticHtmlService;
use app\common\service\config\ConfigService;
use app\common\service\config\ConfigSecretService;
use app\common\service\infra\DataRetentionConfigService;
use app\common\service\site\SiteUrlModeService;
use app\common\model\CronJob;
use app\common\model\CronLog;
use app\common\model\FormSubmission;
use app\common\support\AdminListParams;

class CronService
{

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /** @var array<string, string> 内核 cron handler => 说明（插件任务见 PluginCronTaskRegistry::legacyHandlerDescriptions） */
    private const CORE_HANDLERS = [
        'static_rebuild'            => '静态 HTML 分批切片（全量/增量，避免超时）',
        'static_rebuild_incremental' => '仅生成近 24h 内有更新的文档静态页',
        'backup_database'           => '备份数据库到 data/backups',
        'backup_uploads'            => '打包 public/uploads 为 uploads_*.zip',
        'backup_prune'              => '按 backup_retention_days 清理过期备份文件',
        'cleanup_audit_logs'        => '删除过期操作日志',
        'cleanup_old_form_submissions' => '删除过期已处理表单提交（contact 等）',
        'plugin_entitlement_expire' => '标记过期插件授权并停用无权限插件',
        'plugin_entitlement_remind' => '插件授权到期提醒（7/3/1 天与刚过期）',
        'plugin_wallet_period_reset' => '重置到期订阅包月/包年插件次数额度',
        'plugin_market_blocklist_sync' => '同步远程 blocklist 并自动停用已装下架插件',
        'plugin_market_auto_update' => '检查远程插件更新并按策略自动升级',
        'plugin_market_compensation_retry' => '市场获取失败补偿重试（退款/撤授权）',
        'plugin_subscription_auto_renew' => '扫描即将到期订阅授权并尝试自动续费',
        'plugin_capability_snapshot_refresh' => '刷新已授权插件 capability 快照',
        'document_schedule'         => '定时发布 / 定时下架到期文档',
        'seo_baidu_push_batch'      => '批量向百度推送最近发布文档 URL',
        'stats_prune_hits'          => '清理 90 天前的访问明细 stats_hits',
        'search_index_queue_drain'  => '消费文档搜索索引异步队列',
        'search_index_queue_prune_dead' => '清理搜索索引队列死信（多次失败且过期）',
        'search_query_log_prune'    => '清理过期搜索词日志',
        'domain_event_dispatch_drain' => '消费领域事件 Hook 异步队列（需 event_bus_async=1）',
        'domain_event_log_prune'    => '清理过期领域事件日志',
        'media_orphan_queue_prune'  => '清理长期未处理的媒体孤儿队列项',
        'static_build_queue_drain'  => '消费静态 HTML 构建队列（可多 worker）',
        'static_build_seed_slice'   => '全量静态播种切片（框架页+文档 ID 游标）',
        'payment_reconcile_pending' => '向微信/支付宝查单补入账（notify 丢失补偿）',
        'payment_close_stale_orders' => '自动关闭超时待支付/失败订单（标记 closed，不删除）',
        'payment_notify_log_prune'  => '清理过期支付回调日志',
        'offer_expire_pending'      => '取消超时未支付的报价订单并回滚库存',
        'plugin_cron_task'          => '插件扩展定时任务（payload: plugin + task）',
    ];

    /** @return array<string, string> */
    public function handlers(): array
    {
        return self::CORE_HANDLERS + app(PluginCronTaskRegistry::class)->legacyHandlerDescriptions();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAdmin(): array
    {
        return $this->listAdminPaged(['limit' => QueryLimit::ADMIN_UNBOUNDED])['list'];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{list:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function listAdminPaged(array $params = []): array
    {
        $p     = AdminListParams::parse($params);
        $query = CronJob::order('id', 'asc');
        AdminListParams::applyKeyword($query, $p['keyword'], 'name|handler|schedule');
        $total = (int) $query->count();
        $rows  = $query->page($p['page'], $p['limit'])->select()->toArray();
        $out   = [];
        foreach ($rows as $row) {
            $out[] = $this->formatAdminRow($row);
        }

        return ['list' => $out, 'total' => $total, 'page' => $p['page'], 'limit' => $p['limit']];
    }

    /**
     * @return list<array<string, mixed>>
     * @param mixed $jobId
     * @param mixed $limit
     */
    public function listLogsAdmin(int $jobId = 0, int $limit = 30): array
    {
        $limit = min(max($limit, 1), 100);
        $query = CronLog::order('id', 'desc');
        if ($jobId > 0) {
            $query->where('job_id', $jobId);
        }
        $rows = $query->limit($limit)->select()->toArray();
        $out  = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'          => (int) ($row['id'] ?? 0),
                'job_id'      => (int) ($row['job_id'] ?? 0),
                'status'      => (string) ($row['status'] ?? ''),
                'message'     => (string) ($row['message'] ?? ''),
                'started_at'  => (string) ($row['started_at'] ?? ''),
                'finished_at' => (string) ($row['finished_at'] ?? ''),
                'duration_ms' => (int) ($row['duration_ms'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $id       = (int) ($data['id'] ?? 0);
        $name     = trim((string) ($data['name'] ?? ''));
        $handler  = trim((string) ($data['handler'] ?? ''));
        $interval = (int) ($data['interval_minutes'] ?? 60);
        $status   = (int) ($data['status'] ?? 1);

        if ($name === '' || $handler === '' || !isset($this->handlers()[$handler])) {
            return ServiceResult::fail('任务参数无效');
        }
        if ($interval < 5) {
            return ServiceResult::fail('间隔不能小于 5 分钟');
        }

        $now     = AppTime::now();
        $payload = [
            'name'             => $name,
            'handler'          => $handler,
            'interval_minutes' => $interval,
            'status'           => $status === 1 ? 1 : 0,
            'updated_at'       => $now,
        ];

        if ($id > 0) {
            $row = CronJob::where('id', $id)->find()?->toArray();
            if (!$row) {
                return ServiceResult::fail('任务不存在');
            }
            CronJob::where('id', $id)->update($payload);
        } else {
            $exists = CronJob::where('handler', $handler)->find()?->toArray();
            if ($exists) {
                return ServiceResult::fail('该处理器任务已存在');
            }
            $payload['next_run_at'] = $now;
            $payload['created_at']  = $now;
            CronJob::insertGetId($payload);
        }

        $this->auditLogService->operate('保存定时任务', 'admin.cron', ['handler' => $handler]);

        return ServiceResult::ok(null, '保存成功');
    }

    /**
     * @return ServiceResult
     * @param mixed $id
     * @param mixed $status
     */
    public function updateStatusAdmin(int $id, int $status): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数无效');
        }
        if (!CronJob::where('id', $id)->find()) {
            return ServiceResult::fail('任务不存在');
        }
        $now = AppTime::now();
        CronJob::where('id', $id)->update([
            'status'       => $status === 1 ? 1 : 0,
            'next_run_at'  => $status === 1 ? $now : null,
            'updated_at'   => $now,
        ]);

        return ServiceResult::ok(null, '状态已更新');
    }

    /**
     * @return ServiceResult
     * @param mixed $id
     */
    public function deleteAdmin(int $id): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数无效');
        }
        CronJob::where('id', $id)->delete();
        CronLog::where('job_id', $id)->delete();

        return ServiceResult::ok(null, '已删除');
    }

    /**
     * @return ServiceResult
     * @param mixed $id
     */
    public function runNowAdmin(int $id): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数无效');
        }
        $row = CronJob::where('id', $id)->find()?->toArray();
        if (!$row) {
            return ServiceResult::fail('任务不存在');
        }

        $result = $this->executeJob($row);
        $this->auditLogService->operate('手动执行定时任务', 'admin.cron', ['job_id' => $id, 'status' => $result['status']]);

        $msg = (string) ($result['message'] ?? '');
        if ($result['status'] === 'fail') {
            return ServiceResult::fail($msg !== '' ? $msg : '执行失败', meta: ['result' => $result]);
        }

        return ServiceResult::ok(['result' => $result], $msg !== '' ? $msg : '执行完成');
    }

    /**
     * CLI：执行到期任务
     *
     * @return array{ran:int,results:list<array<string,mixed>>}
     * @param mixed $forceAll
     */
    public function runDueJobs(bool $forceAll = false): array
    {
        $now  = AppTime::now();
        $rows = CronJob::where('status', 1)
            ->order('id', 'asc')
            ->limit(QueryLimit::CRON_JOBS_ACTIVE)
            ->select()
            ->toArray();

        $results = [];
        foreach ($rows as $row) {
            if (!$forceAll) {
                $next = (string) ($row['next_run_at'] ?? '');
                if ($next !== '' && $next > $now) {
                    continue;
                }
            }
            $results[] = $this->executeJob($row);
        }

        return ['ran' => count($results), 'results' => $results];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{job_id:int,handler:string,status:string,message:string,duration_ms:int}
     */
    private function executeJob(array $row): array
    {
        $jobId   = (int) ($row['id'] ?? 0);
        $handler = (string) ($row['handler'] ?? '');
        $started = microtime(true);
        $startAt = AppTime::now();
        $interval = max(5, (int) ($row['interval_minutes'] ?? 60));
        // 锁须盖住最长作业；payload.lock_ttl_seconds 可覆写（上限 6h），默认 2×周期且 ≥5min
        $payloadTtl = 0;
        if (!empty($row['payload'])) {
            $decoded = json_decode((string) $row['payload'], true);
            if (is_array($decoded)) {
                $payloadTtl = max(0, (int) ($decoded['lock_ttl_seconds'] ?? 0));
            }
        }
        $lockTtl = $payloadTtl > 0
            ? min(21600, max(60, $payloadTtl))
            : min(21600, max(300, $interval * 60 * 2));
        $lockKey  = 'cron:job:' . $jobId;

        if (!app(\app\common\service\infra\DistributedLockService::class)->acquire($lockKey, $lockTtl)) {
            return [
                'job_id'      => $jobId,
                'handler'     => $handler,
                'status'      => 'skip',
                'message'     => '其他节点正在执行',
                'duration_ms' => 0,
            ];
        }

        try {
            $message = $this->dispatchHandler($handler, $row);
            $status  = str_starts_with($message, 'SKIP:') ? 'skip' : 'ok';
            if ($status === 'skip') {
                $message = trim(substr($message, 5));
            }
        } catch (\Throwable $e) {
            $status  = 'fail';
            $message = $e->getMessage();
        } finally {
            app(\app\common\service\infra\DistributedLockService::class)->release($lockKey);
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $finishedAt = AppTime::now();
        $interval   = max(5, (int) ($row['interval_minutes'] ?? 60));

        CronLog::insert([
            'job_id'      => $jobId,
            'status'      => $status,
            'message'     => mb_substr($message, 0, 2000),
            'started_at'  => $startAt,
            'finished_at' => $finishedAt,
            'duration_ms' => $durationMs,
        ]);

        CronJob::where('id', $jobId)->update([
            'last_run_at'  => $finishedAt,
            'last_status'  => $status,
            'next_run_at'  => AppTime::format('Y-m-d H:i:s', time() + $interval * 60),
            'updated_at'   => $finishedAt,
        ]);

        if ($status === 'fail') {
            app(CronAlertService::class)->notifyJobFailed($row, $message);
        }

        return [
            'job_id'      => $jobId,
            'handler'     => $handler,
            'status'      => $status,
            'message'     => $message,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function dispatchHandler(string $handler, array $row): string
    {
        $payload = [];
        if (!empty($row['payload'])) {
            $decoded = json_decode((string) $row['payload'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        return match ($handler) {
            'static_rebuild'            => $this->handleStaticRebuild($payload),
            'static_rebuild_incremental' => $this->handleStaticRebuildIncremental($payload),
            'backup_database'           => $this->handleBackupDatabase(),
            'backup_uploads'            => $this->handleBackupUploads(),
            'backup_prune'              => $this->handleBackupPrune($payload),
            'cleanup_audit_logs'        => $this->handleCleanupAuditLogs($payload),
            'cleanup_old_form_submissions' => $this->handleCleanupOldFormSubmissions($payload),
            'plugin_entitlement_expire' => $this->handlePluginEntitlementExpire(),
            'plugin_entitlement_remind' => $this->handlePluginEntitlementRemind(),
            'plugin_wallet_period_reset' => $this->handlePluginWalletPeriodReset(),
            'plugin_market_blocklist_sync' => $this->handlePluginMarketBlocklistSync(),
            'plugin_market_auto_update' => $this->handlePluginMarketAutoUpdate(),
            'plugin_market_compensation_retry' => $this->handlePluginMarketCompensationRetry(),
            'plugin_subscription_auto_renew' => $this->handlePluginSubscriptionAutoRenew(),
            'plugin_capability_snapshot_refresh' => $this->handlePluginCapabilitySnapshotRefresh(),
            'document_schedule'         => $this->handleDocumentSchedule(),
            'seo_baidu_push_batch'      => $this->handleSeoBaiduPushBatch($payload),
            'stats_prune_hits'          => $this->handleStatsPruneHits($payload),
            'search_index_queue_drain'  => $this->handleSearchIndexQueueDrain($payload),
            'search_index_queue_prune_dead' => $this->handleSearchIndexQueuePruneDead($payload),
            'search_query_log_prune'    => $this->handleSearchQueryLogPrune($payload),
            'domain_event_dispatch_drain' => $this->handleDomainEventDispatchDrain($payload),
            'domain_event_log_prune'    => $this->handleDomainEventLogPrune($payload),
            'media_orphan_queue_prune'  => $this->handleMediaOrphanQueuePrune($payload),
            'static_build_queue_drain'  => $this->handleStaticBuildQueueDrain($payload),
            'static_build_seed_slice'   => $this->handleStaticBuildSeedSlice($payload),
            'payment_reconcile_pending' => $this->handlePaymentReconcilePending($payload),
            'payment_close_stale_orders' => $this->handlePaymentCloseStaleOrders($payload),
            'payment_notify_log_prune'  => $this->handlePaymentNotifyLogPrune($payload),
            'offer_expire_pending'      => $this->handleOfferExpirePending(),
            'plugin_cron_task'          => $this->handlePluginCronTask($payload),
            default                     => $this->dispatchLegacyPluginHandler($handler, $payload)
                ?? throw new \RuntimeException('未知处理器: ' . $handler),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    /** 插件 cron 注册表分发（方法名 Legacy 为冻 ABI；非空壳） */
    private function dispatchLegacyPluginHandler(string $handler, array $payload): ?string
    {
        return app(PluginCronTaskRegistry::class)->dispatchLegacyHandler($handler, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handlePaymentReconcilePending(array $payload): string
    {
        $paymentCfg = app(PaymentConfigService::class);
        if (!$paymentCfg->isOpen() || $paymentCfg->isDemoMode()) {
            return 'SKIP:payment demo or closed';
        }

        $limit = max(1, min(200, (int) ($payload['limit'] ?? 50)));
        $minAge = max(30, (int) ($payload['min_age_seconds'] ?? 30));
        $result = app(PaymentOrderService::class)->reconcilePendingFromGateway($limit, $minAge);

        return sprintf(
            'scanned=%d synced=%d skipped=%d',
            (int) ($result['scanned'] ?? 0),
            (int) ($result['synced'] ?? 0),
            (int) ($result['skipped'] ?? 0)
        );
    }

    /** @param array<string, mixed> $payload */
    private function handlePaymentCloseStaleOrders(array $payload): string
    {
        $days  = app(DataRetentionConfigService::class)->days('payment_stale_orders', $payload['older_than_days'] ?? null);
        $limit = max(50, min(2000, (int) ($payload['limit'] ?? 500)));
        $res   = app(PaymentOrderService::class)->closeStaleInvalidAdmin($days, 0, $limit);
        if (!$res->isOk()) {
            throw new \RuntimeException((string) ($res->message() ?? '关闭无效订单失败'));
        }

        return (string) ($res->message() ?? '完成');
    }

    /** @param array<string, mixed> $payload */
    private function handlePaymentNotifyLogPrune(array $payload): string
    {
        $days  = app(DataRetentionConfigService::class)->days('payment_notify_log', $payload['days'] ?? null);
        $limit = max(100, min(20000, (int) ($payload['limit'] ?? 5000)));
        $count = app(PaymentOrderService::class)->pruneNotifyLogsOlderThanDays($days, $limit);

        return "deleted={$count}";
    }

    private function handleOfferExpirePending(): string
    {
        if (!app(\app\common\service\product\OfferBridgeFacade::class)->enabled()) {
            return '报价桥未注册，跳过';
        }
        $count = app(\app\common\service\product\OfferBridgeFacade::class)->expirePendingOrders();

        return "已取消 {$count} 笔超时待支付订单";
    }

    /** @param array<string, mixed> $payload */
    private function handlePluginCronTask(array $payload): string
    {
        $plugin = trim((string) ($payload['plugin'] ?? ''));
        $task   = trim((string) ($payload['task'] ?? ''));
        if ($plugin === '' || $task === '') {
            return 'SKIP:invalid payload';
        }

        return app(PluginCronTaskRegistry::class)->dispatch($plugin, $task, $payload);
    }

    private function handleDocumentSchedule(): string
    {
        $res = app(DocumentScheduleService::class)->processDue();

        return (string) ($res['msg'] ?? '完成');
    }

    /** @param array<string, mixed> $payload */
    private function handleSeoBaiduPushBatch(array $payload): string
    {
        $limit = max(1, min(100, (int) ($payload['limit'] ?? 30)));
        $res   = app(SitemapService::class)->pushBatchAdmin($limit);
        if (!$res->isOk()) {
            throw new \RuntimeException((string) ($res->message() ?? '推送失败'));
        }

        return (string) ($res->message() ?? '完成');
    }

    /** @param array<string, mixed> $payload */
    private function handleStatsPruneHits(array $payload): string
    {
        $days  = app(DataRetentionConfigService::class)->days('stats_hits', $payload['days'] ?? null);
        $since = AppTime::format('Y-m-d H:i:s', time() - $days * 86400);
        $count = 0;
        try {
            $count = (int) StatsHit::where('created_at', '<', $since)->delete();
        } catch (\Throwable $e) {
            return 'SKIP:stats_hits 不存在或清理失败';
        }

        return "已删除 {$count} 条 {$days} 天前的访问明细";
    }

    /** @param array<string, mixed> $payload */
    private function handleStaticRebuild(array $payload): string
    {
        if (!app(SiteUrlModeService::class)->isStatic()) {
            return 'SKIP:当前非静态模式，已跳过';
        }
        $res = app(StaticHtmlBatchService::class)->cronSlice(array_merge($payload, [
            'mode'  => (string) ($payload['mode'] ?? 'all'),
            'batch' => (int) ($payload['batch'] ?? 200),
        ]));

        return (string) ($res->message() ?? '完成');
    }

    /** @param array<string, mixed> $payload */
    private function handleStaticRebuildIncremental(array $payload): string
    {
        if (!app(SiteUrlModeService::class)->isStatic()) {
            return 'SKIP:当前非静态模式，已跳过';
        }
        $hours = max(1, (int) ($payload['hours'] ?? 24));
        $since = AppTime::format('Y-m-d H:i:s', time() - $hours * 3600);
        $res   = app(StaticHtmlBatchService::class)->cronSlice([
            'mode'   => 'time',
            'since'  => $since,
            'batch'  => (int) ($payload['batch'] ?? 300),
            'purge'  => false,
        ]);

        return (string) ($res->message() ?? '完成');
    }

    private function handleBackupDatabase(): string
    {
        $res = app(BackupService::class)->create(['database']);
        if (!$res->isOk()) {
            throw new \RuntimeException((string) ($res->message() ?? '备份失败'));
        }

        return (string) ($res->message() ?? '备份完成');
    }

    private function handleBackupUploads(): string
    {
        $res = app(BackupService::class)->create(['uploads']);
        if (!$res->isOk()) {
            throw new \RuntimeException((string) ($res->message() ?? '上传目录备份失败'));
        }

        return (string) ($res->message() ?? '上传目录备份完成');
    }

    /** @param array<string, mixed> $payload */
    private function handleBackupPrune(array $payload): string
    {
        $days = app(DataRetentionConfigService::class)->days('backup_files', $payload['days'] ?? null);
        $res  = app(BackupService::class)->pruneOlderThanDays($days);

        return sprintf('保留 %d 天内备份，删除 %d 个过期文件，保留 %d 个', $days, $res['deleted'], $res['kept']);
    }

    /** @param array<string, mixed> $payload */
    private function handleCleanupAuditLogs(array $payload): string
    {
        $days   = app(DataRetentionConfigService::class)->days('audit_logs', $payload['days'] ?? null);
        $limit  = max(500, min(20000, (int) ($payload['batch'] ?? 5000)));
        if (app(LogArchiveService::class)->archiveTableExists()) {
            $res = app(LogArchiveService::class)->archiveOlderThanDays($days, $limit);

            return "已归档 {$res['archived']} 条、删除热表 {$res['deleted']} 条（{$days} 天前）";
        }
        $since = AppTime::format('Y-m-d H:i:s', time() - $days * 86400);
        $ids   = AuditLog::where('created_at', '<', $since)->order('id', 'asc')->limit($limit)->column('id');
        $ids   = array_values(array_map('intval', $ids ?: []));
        $count = $ids !== [] ? (int) AuditLog::whereIn('id', $ids)->delete() : 0;

        return "已删除 {$count} 条 {$days} 天前的操作日志（无归档表，单次上限 {$limit}）";
    }

    /** @param array<string, mixed> $payload */
    private function handleSearchIndexQueueDrain(array $payload): string
    {
        $limit = max(50, min(2000, (int) ($payload['limit'] ?? 300)));
        $res   = app(SearchIndexQueueService::class)->drain($limit);

        return sprintf(
            '索引队列：成功 %d，失败 %d，剩余 %d',
            (int) ($res['processed'] ?? 0),
            (int) ($res['failed'] ?? 0),
            (int) ($res['remaining'] ?? 0)
        );
    }

    /** @param array<string, mixed> $payload */
    private function handleSearchIndexQueuePruneDead(array $payload): string
    {
        $days  = app(DataRetentionConfigService::class)->days('search_index_dead', $payload['days'] ?? null);
        $limit = max(50, min(20000, (int) ($payload['limit'] ?? 2000)));
        $count = app(SearchIndexQueueService::class)->pruneDeadLettersOlderThanDays($days, $limit);

        return "索引队列死信已删 {$count} 条";
    }

    /** @param array<string, mixed> $payload */
    private function handleSearchQueryLogPrune(array $payload): string
    {
        $days  = app(DataRetentionConfigService::class)->days('search_query_log', $payload['days'] ?? null);
        $limit = max(100, min(50000, (int) ($payload['limit'] ?? 10000)));
        $count = app(SearchQueryLogService::class)->pruneOlderThanDays($days, $limit);

        return "搜索词日志已删 {$count} 条";
    }

    /** @param array<string, mixed> $payload */
    private function handleDomainEventDispatchDrain(array $payload): string
    {
        if (!app(DomainEventDispatchQueueService::class)->enabled()) {
            return 'event_bus_async 未启用，跳过';
        }
        $limit = max(50, min(2000, (int) ($payload['limit'] ?? 200)));
        $res   = app(DomainEventDispatchQueueService::class)->drain($limit);

        return sprintf(
            '领域事件队列：成功 %d，失败 %d，剩余 %d',
            (int) ($res['processed'] ?? 0),
            (int) ($res['failed'] ?? 0),
            (int) ($res['remaining'] ?? 0)
        );
    }

    /** @param array<string, mixed> $payload */
    private function handleDomainEventLogPrune(array $payload): string
    {
        $days  = app(DataRetentionConfigService::class)->days('domain_event_log', $payload['days'] ?? null);
        $limit = max(100, min(100000, (int) ($payload['limit'] ?? 20000)));
        $count = app(DomainEventLogService::class)->pruneOlderThanDays($days, $limit);

        return "已删除 {$count} 条 {$days} 天前的领域事件日志";
    }

    /** @param array<string, mixed> $payload */
    private function handleStaticBuildQueueDrain(array $payload): string
    {
        if (!app(SiteUrlModeService::class)->isStatic()) {
            return 'SKIP:非静态模式';
        }
        $batch = max(50, min(2000, (int) ($payload['batch'] ?? app(StaticBuildQueueService::class)->defaultDrainBatch())));
        $res   = app(StaticBuildQueueService::class)->drain($batch);

        return sprintf(
            '静态队列：处理 %d，写入 %d，跳过 %d，失败 %d，剩余 %d',
            (int) ($res['processed'] ?? 0),
            (int) ($res['written'] ?? 0),
            (int) ($res['skipped'] ?? 0),
            (int) ($res['failed'] ?? 0),
            (int) ($res['remaining'] ?? 0)
        );
    }

    /** @param array<string, mixed> $payload */
    private function handleStaticBuildSeedSlice(array $payload): string
    {
        if (!app(SiteUrlModeService::class)->isStatic()) {
            return 'SKIP:非静态模式';
        }
        $limit = max(500, min(20000, (int) ($payload['limit'] ?? 5000)));
        $file  = \app\common\support\ProjectPaths::runtimeDir() . 'static_build_seed_state.json';
        $state = [];
        if (is_file($file)) {
            $size = filesize($file);
            if ($size !== false && $size <= 65536) {
                $decoded = json_decode((string) file_get_contents($file), true);
                if (is_array($decoded)) {
                    $state = $decoded;
                }
            }
        }
        if (empty($state['framework_done'])) {
            app(StaticBuildQueueService::class)->seedFramework();
            $state['framework_done'] = true;
            $state['doc_cursor']     = 0;
        }
        $cursor = (int) ($state['doc_cursor'] ?? 0);
        $res    = app(StaticBuildQueueService::class)->seedDocuments($cursor, $limit);
        $state['doc_cursor'] = (int) ($res['next_cursor'] ?? $cursor);
        if (!empty($res['done'])) {
            $state['finished_at'] = AppTime::now();
            LocalFile::unlinkIfExists($file);
        } else {
            $dir = dirname($file);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE));
        }

        return sprintf(
            '播种：入队 %d 篇，游标 %d，%s，队列待处理 %d',
            (int) ($res['enqueued'] ?? 0),
            (int) ($state['doc_cursor'] ?? 0),
            !empty($res['done']) ? '文档已全部入队' : '未完待续',
            app(StaticBuildQueueService::class)->pendingCount()
        );
    }

    /** @param array<string, mixed> $payload */
    private function handleMediaOrphanQueuePrune(array $payload): string
    {
        $days  = app(DataRetentionConfigService::class)->days('media_orphan_queue', $payload['days'] ?? null);
        $count = app(MediaOrphanQueueService::class)->pruneStale($days);

        return "已清理 {$count} 条 {$days} 天前的媒体孤儿队列项";
    }

    /** @param array<string, mixed> $payload */
    private function handleCleanupOldFormSubmissions(array $payload): string
    {
        $days   = app(DataRetentionConfigService::class)->days('form_submission_processed', $payload['days'] ?? null);
        $status = (int) ($payload['status'] ?? SiteFormService::STATUS_HANDLED);
        $since  = AppTime::format('Y-m-d H:i:s', time() - $days * 86400);
        $formId = app(SiteFormService::class)->findIdBySlug('contact');
        if ($formId < 1) {
            return '已删除 0 条表单提交（contact 表单未配置）';
        }
        $count = (int) FormSubmission::where('form_id', $formId)
            ->where('status', $status)
            ->where('created_at', '<', $since)
            ->delete();

        return "已删除 {$count} 条 {$days} 天前的已处理表单提交";
    }

    private function handlePluginEntitlementExpire(): string
    {
        $stats = app(EntitlementService::class)->enforceExpiredPlugins();

        return sprintf(
            '已标记 %d 条过期授权，停用 %d 个插件',
            (int) ($stats['expired'] ?? 0),
            (int) ($stats['disabled'] ?? 0)
        );
    }

    private function handlePluginEntitlementRemind(): string
    {
        $stats = app(\app\common\service\plugin\entitlement\PluginEntitlementReminderService::class)->runDailyReminders();

        $emails = (int) ($stats['emails'] ?? 0);
        $sent   = (int) ($stats['sent'] ?? 0);
        if ($emails > 0) {
            return sprintf('已发送 %d 条插件授权提醒（邮件 %d 封）', $sent, $emails);
        }

        return sprintf('已发送 %d 条插件授权提醒', $sent);
    }

    private function handlePluginWalletPeriodReset(): string
    {
        $count = app(\app\common\service\plugin\commerce\PluginWalletService::class)->refreshSubscriptionPeriods();

        return "已重置 {$count} 个插件订阅周期额度";
    }

    private function handlePluginMarketBlocklistSync(): string
    {
        if (!(bool) config('plugin.security.cron_sync_blocklist', true)) {
            return 'SKIP:cron_sync_blocklist=0';
        }

        $result   = app(PluginMarketSecuritySyncService::class)->enforceInstalledRevocations(true);
        $data     = $result->dataArray();
        $disabled = is_array($data['disabled'] ?? null) ? $data['disabled'] : [];
        if ($disabled === [] && ($data['uninstalled'] ?? []) === [] && ($data['warned'] ?? []) === []) {
            return (string) ($result->message() ?? '已检查 blocklist');
        }

        return (string) ($result->message() ?? 'blocklist 同步完成');
    }

    private function handlePluginSubscriptionAutoRenew(): string
    {
        if (!(bool) config('plugin.commercial.auto_renew_enabled', false)) {
            return 'SKIP:auto_renew_enabled=0';
        }

        $stats = app(\app\common\service\plugin\commerce\PluginSubscriptionRenewService::class)->runDueRenewals();

        return sprintf(
            'scanned=%d renewed=%d reminded=%d skipped=%d failed=%d',
            (int) ($stats['scanned'] ?? 0),
            (int) ($stats['renewed'] ?? 0),
            (int) ($stats['reminded'] ?? 0),
            (int) ($stats['skipped'] ?? 0),
            (int) ($stats['failed'] ?? 0)
        );
    }

    private function handlePluginMarketAutoUpdate(): string
    {
        $stats = app(PluginMarketAutoUpdateService::class)->run((bool) config('plugin.market.auto_update_apply', false));

        return sprintf(
            '待更新 %d，已应用 %d，跳过 %d，失败 %d',
            (int) ($stats['updates'] ?? 0),
            (int) ($stats['applied'] ?? 0),
            count($stats['skipped'] ?? []),
            count($stats['failed'] ?? [])
        );
    }

    private function handlePluginMarketCompensationRetry(): string
    {
        $stats = app(PluginMarketAcquireReliabilityService::class)->processCron(20, 10);

        return 'acquire_compensation_done=' . $stats['retries'] . ',saga_resumed=' . $stats['sagas'];
    }

    private function handlePluginCapabilitySnapshotRefresh(): string
    {
        if (!(bool) config('plugin.security.cron_refresh_capability_snapshot', true)) {
            return 'SKIP:cron_refresh_capability_snapshot=0';
        }

        $count = app(\app\common\service\plugin\registry\PluginCapabilityService::class)->refreshAllEntitlementSnapshots();
        if ($count < 1) {
            return '无已授权插件需刷新快照';
        }

        $this->auditLogService->operate('定时刷新插件能力快照', 'cron.plugin', [
            'snapshots_refreshed' => $count,
        ]);

        return sprintf('已刷新 %d 个插件 capability 快照', $count);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatAdminRow(array $row): array
    {
        $handler = (string) ($row['handler'] ?? '');

        return [
            'id'               => (int) ($row['id'] ?? 0),
            'name'             => (string) ($row['name'] ?? ''),
            'handler'          => $handler,
            'handler_label'    => $this->handlers()[$handler] ?? $handler,
            'interval_minutes' => (int) ($row['interval_minutes'] ?? 0),
            'status'           => (int) ($row['status'] ?? 0),
            'last_run_at'      => (string) ($row['last_run_at'] ?? ''),
            'next_run_at'      => (string) ($row['next_run_at'] ?? ''),
            'last_status'      => (string) ($row['last_status'] ?? ''),
        ];
    }

    /** HTTP 唤醒密钥（自动生成；凭据进 config_secrets） */
    public function webhookToken(): string
    {
        try {
            app(ConfigSecretService::class)->migrateKeyFromConfigs('cron_webhook_token');
        } catch (\Throwable) {
            // 未安装/无表时跳过
        }
        $token = trim((string) app(ConfigService::class)->get('cron_webhook_token', ''));
        if ($token !== '') {
            return $token;
        }
        $token = bin2hex(random_bytes(16));
        app(ConfigService::class)->set('cron_webhook_token', $token);

        return $token;
    }

    public function webhookUrl(): string
    {
        $base = rtrim((string) app(ConfigService::class)->get('site_url', ''), '/');
        $path = '/api/v1/system/cron-tick?token=' . rawurlencode($this->webhookToken());

        return $base !== '' ? ($base . $path) : $path;
    }

    /** @return array{url:string,token:string,hint:string,crontab_example:string} */
    public function webhookMeta(): array
    {
        $url = $this->webhookUrl();

        return [
            'url'               => $url,
            'token'             => $this->webhookToken(),
            'hint'              => '将下方地址加入服务器计划任务（建议每 1 分钟请求一次），无需额外 CLI 脚本',
            'crontab_example'   => '* * * * * curl -fsS "' . $url . '" >/dev/null',
        ];
    }
}
