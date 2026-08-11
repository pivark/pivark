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
 * Class app\common\model\StatsDaily
 *
 * @property int $id 主键
 * @property int $pv 浏览量
 * @property int $uv 访客数
 * @property string $created_at 创建时间
 * @property string $stat_date 日期
 * @property string $updated_at 更新时间
 */
class StatsDaily extends Model
{
    protected $name = 'stats_daily';

        protected $type = [
        'id' => 'integer',
        'pv' => 'integer',
        'uv' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
}
