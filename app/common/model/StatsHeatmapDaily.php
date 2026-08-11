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
 * Class app\common\model\StatsHeatmapDaily
 *
 * @property int $hits 字段：hits
 * @property int $id 主键
 * @property string $cell_key 字段：cell_key
 * @property string $created_at 创建时间
 * @property string $path 字段：path
 * @property string $stat_date 字段：stat_date
 * @property string $updated_at 更新时间
 */
class StatsHeatmapDaily extends Model
{
    protected $name = 'stats_heatmap_daily';

        protected $type = [
        'hits' => 'integer',
        'id' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
}
