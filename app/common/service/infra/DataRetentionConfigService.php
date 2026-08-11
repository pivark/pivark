<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\infra;

use app\common\service\config\ConfigService;
use app\common\service\cron\CronService;
use app\common\support\ServiceResult;

/**
 * 运维数据保留天数 SSOT（定时清理/归档 cron 读取；全家桶可配至多年）
 */
final class DataRetentionConfigService
{

    /**
     * @var array<string, array{
     *   key: string,
     *   label: string,
     *   hint: string,
     *   cron: string,
     *   group: string,
     *   default: int,
     *   min: int,
     *   max: int,
     *   action: string
     * }>
     */
    public const ITEMS = [
        'backup_files' => [
            'key'     => 'backup_retention_days',
            'label'   => '备份文件',
            'hint'    => '系统自动生成的数据库/文件备份包，超过保留天数会删除，只留最近几份，省硬盘。',
            'cron'    => 'backup_prune',
            'cron_label' => '清理过期备份',
            'group'   => '备份与文件',
            'default' => 30,
            'min'     => 7,
            'max'     => 3650,
            'action'  => 'delete',
        ],
        'audit_logs' => [
            'key'     => 'ops_retention_audit_logs_days',
            'label'   => '后台操作日志',
            'hint'    => '记录「谁在什么时候改了什么」。到期会先挪到归档表，再从日常列表里去掉；要留 1～3 年选合规预设即可。',
            'cron'    => 'cleanup_audit_logs',
            'cron_label' => '归档并清理操作日志',
            'group'   => '日志与审计',
            'default' => 90,
            'min'     => 7,
            'max'     => 3650,
            'action'  => 'archive',
        ],
        'stats_hits' => [
            'key'     => 'ops_retention_stats_hits_days',
            'label'   => '网站访问明细',
            'hint'    => '每一次打开页面的明细（谁看了哪一页）。删掉后不影响后台「今日访问、统计报表」等汇总数字。',
            'cron'    => 'stats_prune_hits',
            'cron_label' => '清理访问明细',
            'group'   => '访问与搜索',
            'default' => 90,
            'min'     => 30,
            'max'     => 3650,
            'action'  => 'delete',
        ],
        'search_query_log' => [
            'key'     => 'ops_retention_search_query_log_days',
            'label'   => '访客搜索词',
            'hint'    => '访客在搜索框里输入的关键词，便于分析「搜不到内容」的情况；留太久会占存储。',
            'cron'    => 'search_query_log_prune',
            'cron_label' => '清理搜索词记录',
            'group'   => '访问与搜索',
            'default' => 180,
            'min'     => 30,
            'max'     => 3650,
            'action'  => 'delete',
        ],
        'search_index_dead' => [
            'key'     => 'ops_retention_search_index_dead_days',
            'label'   => '搜索索引失败记录',
            'hint'    => '内容进搜索索引时多次失败、长期没成功的任务，可以定期清掉，避免队列越堆越多。',
            'cron'    => 'search_index_queue_prune_dead',
            'cron_label' => '清理索引失败队列',
            'group'   => '访问与搜索',
            'default' => 14,
            'min'     => 7,
            'max'     => 365,
            'action'  => 'delete',
        ],
        'domain_event_log' => [
            'key'     => 'ops_retention_domain_event_log_days',
            'label'   => '系统事件日志',
            'hint'    => '插件、钩子之间异步任务的运行记录，主要给技术排查用，站点稳定后可设短一些。',
            'cron'    => 'domain_event_log_prune',
            'cron_label' => '清理系统事件日志',
            'group'   => '日志与审计',
            'default' => 30,
            'min'     => 7,
            'max'     => 3650,
            'action'  => 'delete',
        ],
        'payment_notify_log' => [
            'key'     => 'ops_retention_payment_notify_log_days',
            'label'   => '支付回调记录',
            'hint'    => '微信/支付宝等平台通知「是否付款成功」的原始报文，体积较大；财务合规站点可设 1～3 年。',
            'cron'    => 'payment_notify_log_prune',
            'cron_label' => '清理支付回调日志',
            'group'   => '支付与订单',
            'default' => 90,
            'min'     => 30,
            'max'     => 3650,
            'action'  => 'delete',
        ],
        'payment_stale_orders' => [
            'key'     => 'ops_retention_payment_stale_order_days',
            'label'   => '长期未付款订单',
            'hint'    => '一直待支付或已失败的订单，超过天数会标记为「已关闭」，不会物理删除，对账仍可查。',
            'cron'    => 'payment_close_stale_orders',
            'cron_label' => '自动关闭无效订单',
            'group'   => '支付与订单',
            'default' => 7,
            'min'     => 1,
            'max'     => 365,
            'action'  => 'close',
        ],
        'media_orphan_queue' => [
            'key'     => 'ops_retention_media_orphan_days',
            'label'   => '疑似无用文件清单',
            'hint'    => '系统扫描「可能没人引用的图片/附件」时产生的待处理列表，太久的条目会清掉。',
            'cron'    => 'media_orphan_queue_prune',
            'cron_label' => '清理媒体扫描队列',
            'group'   => '备份与文件',
            'default' => 90,
            'min'     => 30,
            'max'     => 3650,
            'action'  => 'delete',
        ],
        'form_submission_processed' => [
            'key'     => 'ops_retention_form_submission_days',
            'label'   => '已处理表单提交',
            'hint'    => '只清理「已处理」状态的自定表单提交；未读/未处理不会动。',
            'cron'    => 'cleanup_old_form_submissions',
            'cron_label' => '清理已处理表单提交',
            'group'   => '日志与审计',
            'default' => 180,
            'min'     => 30,
            'max'     => 3650,
            'action'  => 'delete',
        ],
    ];

    public function __construct(
        private readonly ConfigService $config,
        private readonly CronService $cronService,
    ) {
    }

    /** @return list<string> */
    public function configKeys(): array
    {
        $keys = [];
        foreach (self::ITEMS as $item) {
            $keys[] = $item['key'];
        }

        return $keys;
    }

    /**
     * cron 实际使用的天数（配置优先，payload 仅作 legacy 回退）。
     */
    public function days(string $itemId, mixed $payloadFallback = null): int
    {
        $def = self::ITEMS[$itemId] ?? null;
        if ($def === null) {
            return max(1, (int) $payloadFallback);
        }
        $raw = $this->config->get($def['key'], '');
        $val = (int) ($raw !== '' && $raw !== null ? $raw : $def['default']);
        if ($val < 1 && $payloadFallback !== null && (int) $payloadFallback > 0) {
            $val = (int) $payloadFallback;
        }
        if ($val < 1) {
            $val = $def['default'];
        }

        return max($def['min'], min($def['max'], $val));
    }

    /**
     * @return array{
     *   groups: list<array{key:string,title:string}>,
     *   items: list<array<string,mixed>>,
     *   presets: list<array{key:string,label:string,days:array<string,int>}>
     * }
     */
    public function metaForAdmin(): array
    {
        $groups = [];
        $items  = [];
        $cronJobs = $this->cronJobsByHandler();
        foreach (self::ITEMS as $id => $def) {
            $g = $def['group'];
            if (!isset($groups[$g])) {
                $groups[$g] = ['key' => md5($g), 'title' => $g];
            }
            $handler = (string) $def['cron'];
            $items[] = [
                'id'         => $id,
                'key'        => $def['key'],
                'label'      => $def['label'],
                'hint'       => $def['hint'],
                'cron'       => $handler,
                'cron_label' => $def['cron_label'] ?? $handler,
                'cron_link'  => $this->cronLinkFromMap($handler, $cronJobs),
                'group'      => $g,
                'action'     => $def['action'],
                'days'       => $this->days($id),
                'default'    => $def['default'],
                'min'        => $def['min'],
                'max'        => $def['max'],
            ];
        }

        return [
            'groups'    => array_values($groups),
            'items'     => $items,
            'presets'   => $this->presets(),
            'cron_hub'  => [
                'route' => '/system/cron',
                'title' => '定时任务',
                'hint'  => '保存策略后，对应任务会在下次计划执行时按新天数运行（不会立刻清库）。',
            ],
            'cron_jobs' => $this->cronJobsByHandler(),
        ];
    }

    /**
     * @param array<string, mixed> $input id=>days 或 config key=>days
     * @return ServiceResult<null>
     */
    public function saveAdmin(array $input): ServiceResult
    {
        $payload = [];
        foreach (self::ITEMS as $id => $def) {
            $raw = null;
            if (array_key_exists($id, $input)) {
                $raw = $input[$id];
            } elseif (array_key_exists($def['key'], $input)) {
                $raw = $input[$def['key']];
            }
            if ($raw === null) {
                continue;
            }
            $days = (int) $raw;
            if ($days < $def['min'] || $days > $def['max']) {
                return ServiceResult::fail(
                    sprintf('%s 保留天数须在 %d～%d 天之间', $def['label'], $def['min'], $def['max'])
                );
            }
            $payload[$def['key']] = (string) $days;
        }
        if ($payload === []) {
            return ServiceResult::fail('未提交任何保留策略');
        }
        $this->config->save($payload);

        return ServiceResult::ok(null, '数据保留策略已保存');
    }

    /**
     * @return list<array{key:string,label:string,days:array<string,int>}>
     */
    private function presets(): array
    {
        return [
            [
                'key'   => 'standard',
                'label' => '标准（默认）',
                'days'  => $this->presetDays([
                    'backup_files' => 30,
                    'audit_logs' => 90,
                    'stats_hits' => 90,
                    'search_query_log' => 180,
                    'search_index_dead' => 14,
                    'domain_event_log' => 30,
                    'payment_notify_log' => 90,
                    'payment_stale_orders' => 7,
                    'media_orphan_queue' => 90,
                    'form_submission_processed' => 180,
                ]),
            ],
            [
                'key'   => 'compliance_1y',
                'label' => '合规 1 年',
                'days'  => $this->presetDays([
                    'backup_files' => 365,
                    'audit_logs' => 365,
                    'stats_hits' => 180,
                    'search_query_log' => 365,
                    'search_index_dead' => 14,
                    'domain_event_log' => 180,
                    'payment_notify_log' => 365,
                    'payment_stale_orders' => 14,
                    'media_orphan_queue' => 180,
                    'form_submission_processed' => 365,
                ]),
            ],
            [
                'key'   => 'compliance_3y',
                'label' => '合规 3 年',
                'days'  => $this->presetDays([
                    'backup_files' => 1095,
                    'audit_logs' => 1095,
                    'stats_hits' => 365,
                    'search_query_log' => 1095,
                    'search_index_dead' => 30,
                    'domain_event_log' => 365,
                    'payment_notify_log' => 1095,
                    'payment_stale_orders' => 30,
                    'media_orphan_queue' => 365,
                    'form_submission_processed' => 1095,
                ]),
            ],
        ];
    }

    /**
     * @param array<string, int> $map
     * @return array<string, int>
     */
    private function presetDays(array $map): array
    {
        $out = [];
        foreach (self::ITEMS as $id => $def) {
            $out[$id] = $map[$id] ?? $def['default'];
        }

        return $out;
    }

    /**
     * @return array<string, array{
     *   id:int,
     *   name:string,
     *   handler:string,
     *   enabled:bool,
     *   next_run_at:string,
     *   last_run_at:string,
     *   last_status:string
     * }>
     */
    private function cronJobsByHandler(): array
    {
        $out = [];
        foreach ($this->cronService->listAdmin() as $job) {
            $handler = (string) ($job['handler'] ?? '');
            if ($handler === '') {
                continue;
            }
            $out[$handler] = [
                'id'           => (int) ($job['id'] ?? 0),
                'name'         => (string) ($job['name'] ?? ''),
                'handler'      => $handler,
                'enabled'      => ((int) ($job['status'] ?? 0)) === 1,
                'next_run_at'  => (string) ($job['next_run_at'] ?? ''),
                'last_run_at'  => (string) ($job['last_run_at'] ?? ''),
                'last_status'  => (string) ($job['last_status'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array{
     *   job_id:int,
     *   enabled:bool,
     *   next_run_at:string,
     *   last_run_at:string,
     *   last_status:string,
     *   missing:bool
     * }
     */
    private function cronLinkFromMap(string $handler, array $jobs): array
    {
        $job = $jobs[$handler] ?? null;
        if (!is_array($job)) {
            return [
                'job_id'      => 0,
                'enabled'     => false,
                'next_run_at' => '',
                'last_run_at' => '',
                'last_status' => '',
                'missing'     => true,
            ];
        }

        return [
            'job_id'      => (int) ($job['id'] ?? 0),
            'enabled'     => (bool) ($job['enabled'] ?? false),
            'next_run_at' => (string) ($job['next_run_at'] ?? ''),
            'last_run_at' => (string) ($job['last_run_at'] ?? ''),
            'last_status' => (string) ($job['last_status'] ?? ''),
            'missing'     => false,
        ];
    }
}
