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
 * Class app\common\model\StatsHit
 *
 * @property int $id 主键
 * @property int $object_id 对象 ID
 * @property string $created_at 访问时间
 * @property string $ip_hash IP 哈希
 * @property string $object_type document/tag/home
 * @property string $path 访问路径
 * @property string $referer 来源
 * @property string $ua_hash UA 哈希
 */
class StatsHit extends Model
{
    protected $name = 'stats_hits';

        protected $type = [
        'id' => 'integer',
        'object_id' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
}
