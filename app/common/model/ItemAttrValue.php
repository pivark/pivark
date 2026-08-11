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
 * Class app\common\model\ItemAttrValue
 *
 * @property int $id 主键
 * @property int $item_id 品项 ID
 * @property string $attr_value 参数值
 * @property string $created_at 创建时间
 * @property string $param_key 参数键
 * @property-read \app\common\model\Item $item
 */
class ItemAttrValue extends Model
{
    protected $name = 'item_attr_values';

        protected $type = [
        'id' => 'integer',
        'item_id' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

}
