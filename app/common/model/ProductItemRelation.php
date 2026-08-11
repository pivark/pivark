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
 * Class app\common\model\ProductItemRelation
 *
 * @property float $qty 展示用量
 * @property int $child_item_id 配件/辅件品项 ID
 * @property int $id 主键
 * @property int $parent_item_id 主产品品项 ID
 * @property int $sort 排序
 * @property string $created_at 创建时间
 * @property string $note 备注
 * @property string $relation_type accessory/spare/component
 * @property-read \app\common\model\Item $child_item
 * @property-read \app\common\model\Item $parent_item
 */
class ProductItemRelation extends Model
{
    protected $name = 'product_item_relations';

        protected $type = [
        'child_item_id' => 'integer',
        'id' => 'integer',
        'parent_item_id' => 'integer',
        'qty' => 'float',
        'sort' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
    public function childItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'child_item_id');
    }

    public function parentItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'parent_item_id');
    }

}
