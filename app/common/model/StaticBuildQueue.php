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
 * Class app\common\model\StaticBuildQueue
 *
 * @property int $attempts 重试次数
 * @property int $id 主键
 * @property int $priority 越大越先
 * @property mixed $payload StaticHtmlService runWorkItem 结构
 * @property string $created_at 入队时间
 * @property string $last_error 上次错误
 * @property string $updated_at 更新时间
 * @property string $work_key 幂等键 doc:1 / tag:2:1 / home
 */
class StaticBuildQueue extends Model
{
    protected $name = 'static_build_queue';

        protected $type = [
        'attempts' => 'integer',
        'id' => 'integer',
        'priority' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
}
