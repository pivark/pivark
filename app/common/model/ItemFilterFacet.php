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
 * Class app\common\model\ItemFilterFacet
 *
 * @property int $id 主键
 * @property int $item_count 在售品项数
 * @property string $attr_value 参数值
 * @property string $param_key 参数键
 * @property string $updated_at 更新时间
 */
class ItemFilterFacet extends Model
{
    protected $name = 'item_filter_facets';

        protected $type = [
        'id' => 'integer',
        'item_count' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
}
