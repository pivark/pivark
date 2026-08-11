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

/**
 * 定时任务 pv_cron_jobs
 *
 * @property int $id 主键
 * @property int $interval_minutes 执行间隔（分钟）
 * @property int $status 0禁用 1启用
 * @property mixed $payload 扩展参数
 * @property string $created_at 创建时间
 * @property string $handler 内置处理器标识
 * @property string $last_run_at 上次执行
 * @property string $last_status 上次结果 ok|fail|skip
 * @property string $name 任务名称
 * @property string $next_run_at 下次计划执行
 * @property string $updated_at 更新时间
 */
class CronJob extends Model
{
    protected $name = 'cron_jobs';

        protected $type = [
        'id' => 'integer',
        'interval_minutes' => 'integer',
        'status' => 'integer',
    ];

    public $timestamps = false;
}
