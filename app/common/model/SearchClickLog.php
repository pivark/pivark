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
 * Class app\common\model\SearchClickLog
 *
 * @property int $id 主键
 * @property int $target_id 目标ID
 * @property string $created_at 创建时间
 * @property string $keyword 搜索关键词
 * @property string $target_type 目标类型
 * @property string $target_url 目标URL
 */
class SearchClickLog extends Model
{
    protected $name = 'search_click_log';

        protected $type = [
        'id' => 'integer',
        'target_id' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
}
