<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\model;

use think\Model;
use think\model\relation\BelongsTo;

/**
 * 定时任务执行日志 pv_cron_logs
 *
 * @property int $duration_ms 耗时毫秒
 * @property int $id 主键
 * @property int $job_id 任务ID
 * @property string $finished_at 结束时间
 * @property string $message 摘要
 * @property string $started_at 开始时间
 * @property string $status ok|fail|skip
 * @property-read \app\common\model\CronJob $job
 */
class CronLog extends Model
{
    protected $name = 'cron_logs';

        protected $type = [
        'duration_ms' => 'integer',
        'id' => 'integer',
        'job_id' => 'integer',
    ];

    public $timestamps = false;
    public function job(): BelongsTo
    {
        return $this->belongsTo(CronJob::class, 'job_id');
    }

}
